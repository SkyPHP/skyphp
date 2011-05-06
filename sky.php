<?php

// start the stopwatch
$sky_start_time = microtime(true);

if ( $down_for_maintenance ) {
    include( 'pages/503.php' );
    die();
}//if


// backward compatibility paths
if (!$sky_install_path) $sky_install_path = $skyphp_codebase_path;
if ($skyphp_storage_path) {
    if ( substr($skyphp_storage_path,-1)!='/' ) $skyphp_storage_path .= '/';
    $sky_media_local_path = $skyphp_storage_path . 'media';
}
else if ($sky_media_local_path) $skyphp_storage_path = $sky_media_local_path . '/';
else die('You must configure $skyphp_storage_path in index.php.');


// add all codebase paths to the include path
if ( is_array($codebase_path_arr) ) {
    $add_include_path = NULL;
    foreach ( $codebase_path_arr as $codebase_path ) {
        if ($add_include_path) $add_include_path .= PATH_SEPARATOR;
        $add_include_path .= $codebase_path;
    }
    //set_include_path( get_include_path() . $add_include_path );
    set_include_path( $add_include_path );
} else die( 'Missing $codebase_path_arr on index.php' );


// include the config.php of each codebase; global config values will be overwritten by subsequent codebases
foreach ( array_reverse( $codebase_path_arr ) as $codebase_path ) {
    $codebase_config = $codebase_path . 'config.php';
    if ( file_exists( $codebase_config ) ) include( $codebase_config );

    // include user-defined include files
    if ( is_array($includes) ):
        foreach ($includes as $include):
            include_once($include);
        endforeach;
    endif;
    $includes = array();
}


// 301 redirect if canonicalization has been configured and applicable
$sky_canonical_host = $sky_canonical_redirect[$_SERVER['HTTP_HOST']];
if ($sky_canonical_host) {
	header ( 'HTTP/1.1 301 Moved Permanently' );
	header ( 'Location: http://' . $sky_canonical_host . $_SERVER['REQUEST_URI'] );
	exit();
}//if
$sky_canonical_host = $sky_canonical_redirect_no_append[$_SERVER['HTTP_HOST']];
if ($sky_canonical_host) {
	header ( 'HTTP/1.1 301 Moved Permanently' );
	header ( 'Location: http://' . $sky_canonical_host );
	exit();
}//if


// parse the URL to figure out which folders to check for
$path = NULL;
$temp = explode('?',$_SERVER['REQUEST_URI']);
$uri['path'] = $temp[0];
//$uri['path'] = substr($temp[0],1);
$uri['query'] = $temp[1];
$sky_qs = explode('/',$uri['path']);
unset( $sky_qs[0] );
$sky_qs = array_filter($sky_qs);
$sky_qs_original = $sky_qs;


# Serve all types of files except php files in the 'pages' folder
# a. if the first folder in the url corresponds to a special quick-serve script
if ( $quick_serve[ $sky_qs[1] ] ) {
    include( $quick_serve[ $sky_qs[1] ] );
    exit();
# b. if the exact file being requested exists
} else if ( file_exists_incpath( $uri['path'], true ) ) {
    // if the exact file is a php file outside of the /pages folder
    if ( substr($uri['path'],-4)=='.php' && substr($uri['path'], 0, 6 ) != 'pages/' ) {
        $_SERVER['REQUEST_URI'] = '/' . $uri['path'];
        include( $uri['path'] );
        exit();
    // otherwise, serve the file with the correct mime type
    } else {
        include( 'lib/core/quick-serve/file.php' );
    }
# c. if the path being requested is a folder continaing index.htm
} else if ( file_exists_incpath( $uri['path'] . '/index.htm' ) ) {
    add_trailing_slash();
    $_SERVER['REQUEST_URI'] = '/' . $uri['path'] . 'index.htm';
    include( 'lib/core/quick-serve/file.php' );
# d. if the path being requested is a folder continaing index.html
} else if ( file_exists_incpath( $uri['path'] . '/index.html' ) ) {
    add_trailing_slash();
    $_SERVER['REQUEST_URI'] = '/' . $uri['path'] . 'index.html';
    include( 'lib/core/quick-serve/file.php' );

// TODO -- fix this so it works
# e. if the path being requested is a folder continaing index.php
#} else if ( file_exists_incpath( $uri['path'] . '/index.php' ) ) {
#    add_trailing_slash();
#    $_SERVER['REQUEST_URI'] = '/' . $uri['path'] . 'index.php';
#    include( $uri['path'] . '/index.php' );
}


// connect to memcached
if ( class_exists('Memcache') && count($memcache_servers) ) {
    if (!$memcache_port) $memcache_port = 11211;
    $memcache = new Memcache;
    foreach ($memcache_servers as $memcache_host) {
        $memcache->addServer($memcache_host, $memcache_port);
        if ($memcache_save_path) $memcache_save_path .= ',';
        $memcache_save_path .= $memcache_host . ':' . $memcache_port;
    }
}


// connect to db
if ( !$db_host ) $db_host = $db_domain; // for backwards compatibility
if ( $db_name && $db_host ) {
    # connect to read-only db
    $db = &ADONewConnection( $db_platform );
    @$db->Connect( $db_host, $db_username, $db_password, $db_name );
    if ($db->ErrorMsg()) {
        include( 'pages/503.php' );
        die( "<!-- \$db error ($db_host): " . $db->ErrorMsg() . " -->" );
    } else {
        # determine master db -- set $dbw_host
        if ($db_replication) include("lib/core/db-replication/{$db_replication}.php");
        if ( !$dbw_host ) { // we are not using replication
            $dbw =& $db;
            $dbw_host = $db_host;
        } else { // we are using replication, connect to the the master db
            $dbw = &ADONewConnection( $db_platform );
            @$dbw->Connect( $dbw_host, $db_username, $db_password, $db_name );
            // if we can't connect to master, then aql insert/update will
            // gracefully fail and validation will display error message
            if ($dbw->ErrorMsg()) {
                $master_db_connect_error = "<!-- \$dbw error ($dbw_domain): " . $dbw->ErrorMsg() . " -->";
                $dbw = NULL;
            }
        }
    }
}


// if magic quotes are not disabled, this workaround will remove the magic quotes
// it is much better to use server directives; http://us2.php.net/manual/en/security.magicquotes.disabling.php
if (get_magic_quotes_gpc()) {
    function stripslashes_deep($value) {
        $value = is_array($value) ? array_map('stripslashes_deep', $value) : stripslashes($value);
        return $value;
    }
    $_POST = array_map('stripslashes_deep', $_POST);
    $_GET = array_map('stripslashes_deep', $_GET);
    $_COOKIE = array_map('stripslashes_deep', $_COOKIE);
    $_REQUEST = array_map('stripslashes_deep', $_REQUEST);
}//if


// register globals is off, so don't throw the PHP 4.2.3 warning if variables have same name as SESSION keys
ini_set('session.bug_compat_warn', 0);
ini_set('session.bug_compat_42', 0);


// auto-loader
function __autoload($n) {
    aql::include_class_by_name($n);
}


// start session
if ( $enable_subdomain_cookies ) {
    // set the cookie domain so that sessions are shared between subdomains
    // TODO: verify that this works; have encountered mixed results
    if (substr_count ($_SERVER['HTTP_HOST'], ".") == 1) {
        $cookie_domain = "." . $_SERVER['HTTP_HOST'];
    } else {
        $cookie_domain = preg_replace ('/^([^.])*/i', NULL, $_SERVER['HTTP_HOST']);
    }
} else {
    $cookie_domain = $_SERVER['HTTP_HOST'];
}
// set the PHP session id (PHPSESSID) cookie to a custom value
session_set_cookie_params( $cookie_timeout, '/', $cookie_domain );
// timeout value for the garbage collector
ini_set('session.gc_maxlifetime', $cookie_timeout);
// start session
if ( $memcache && $session_storage=='memcache' ) {
    ini_set('session.save_handler', 'memcache');
    ini_set('session.save_path', $memcache_save_path);
} else if ( $db_name && $dbw_domain && $session_storage=='db' ) {
    include_once("lib/adodb/session/adodb-session2.php");
    ADOdb_Session::config($db_platform, $dbw_domain, $db_username, $db_password, $db_name, $options=false);
}//if
session_cache_limiter('none');
session_start();


// user authentication
@include('lib/core/hooks/login/authenticate.php');


// instantiate this page
$p = new page();


// run this before the page is executed
if ( file_exists_incpath('pages/run-first.php') ) include('pages/run-first.php');


// check each folder slug in the url to find the deepest page match
for ( $i=$i+1; $i<=count($sky_qs); $i++ ) {
    $path_arr = array_slice( $sky_qs, 0, $i );
    #print_a($path_arr);
    $slug = $path_arr[$i-1];
    $path = implode('/',$path_arr);
    if ( $path ) $path = '/' . $path;
    $settings_file = 'pages' . $path . '/' . $slug . '-settings.php';
    //echo 'fsettings: '.$settings_file . '<br />';
    include('lib/core/hooks/settings/pre-settings.php');
    @include_once( $settings_file );

    foreach ( $codebase_path_arr as $codebase_path ) {

        $file = 'pages' . $path . '.php';
        if ( is_file( $codebase_path . $file ) ) {
            $page[$i] = $codebase_path . $file;
            $page_path[$i] = $file;
            break;
        }

        $file = 'pages' . $path . '/' . $slug . '.php';
        if ( is_file( $codebase_path . $file ) ) {
            $page[$i] = $codebase_path . $file;
            $page_path[$i] = $file;
            break;
        }

        $file = 'pages' . $path . '/' . $slug . '-profile.php';
        if ( is_file( $codebase_path . $file ) ) {
            if ( $model ) {
                $primary_table = aql::get_primary_table( aql::get_aql($model) );
            }
            if ( $primary_table ) {
                //echo "slug: $slug<br />";
                //print_a($sky_qs);
                if ( $sky_qs[$i+1] == 'add-new' || is_numeric( decrypt($sky_qs[$i+1],$primary_table) ) ) {
                    $page[$i] = $codebase_path . $file;
                    $page_path[$i] = $file;
                    break;
                }
            } else {
                header("HTTP/1.1 503 Service Temporarily Unavailable");
                header("Status: 503 Service Temporarily Unavailable");
                header("Retry-After: 1");
                die("Profile Page Error:<br /><b>$file</b> exists, but <b>\$primary_table</b> is not specified in <b>$settings_file</b></div>");
            }
        }

        $file = 'pages' . $path . '/' . $slug . '-listing.php';
        if ( is_file( $codebase_path . $file ) ) {
            $page[$i] = $codebase_path . $file;
            $page_path[$i] = $file;
            break;
        }

        $file = 'pages' . $path;
        if ( $path && is_dir( $codebase_path . $file ) ) {
            $page[$i] = 'directory';
        }
    }
    if ( $page[$i] ) {
        include('lib/core/hooks/settings/post-settings.php');
        continue;
    }

    // look for database folders
    if ( $db ) {
        // set the path back to the last path, so we can scan for a db folder match
        $path_arr = array_slice( $sky_qs, 0, $i-1 );
        //print_a($path_arr);
        $path = implode('/',$path_arr);
        if ( $path ) $path = '/' . $path;
        $matches = array();
        foreach ( $codebase_path_arr as $codebase_path ) {
            $scandir = $codebase_path . 'pages' . $path;
            //debug("scandir=$scandir<br />");
            if ( is_dir( $scandir ) ) {
                foreach ( scandir( $scandir ) as $filename ) {
                    if ( substr($filename,0,1)=='_' && strlen($filename) > 6 ) {
                        if ( is_dir( $scandir . '/' . $filename ) ) {
                            if ( substr($filename,-1)=='_' ) {
                                //debug("folder=$filename<br />");
                                $matches[ substr($filename,1,-1) ] = $codebase_path;
                            }
                        }
                    }
                }
            }
        }
        #print_a($matches);
        if ( $matches ) {
            foreach ( $matches as $field => $codebase_path ) {
                $folder = '_' . $field . '_';
                //debug($folder . ' is a database folder.<br />');
                $table = substr( $field, 0, strpos( $field, '.' ) );
                //debug("path=$path<br />");
                //echo 'path: ' . $path . '<br />';
                $settings_file = 'pages' . $path . '/' . $folder .'/' . $folder . '-settings.php';
                //echo 'dbsettings: '.$settings_file."<br />";
                include('lib/core/hooks/settings/pre-settings.php');
                @include( $settings_file );
                // don't include post-settings unless this is a match
                $SQL = "select id
                        from $table
                        where active = 1 and $field = '$slug'";
                if ( $database_folder['where'] ) {
                    $SQL .= ' and ' . $database_folder['where'];
                }
                //debug($SQL . '<br />');
                $r = sql($SQL);
                $database_folder = NULL;
                if ( !$r->EOF ) {
                    include('lib/core/hooks/settings/post-settings.php');
                    //debug('DATABASE MATCH!<br />');
                    $sky_qs[$i] = $folder;
                    $lookup_field_id = $table . '_id';
                    $$lookup_field_id = $r->Fields('id');
                    $p->var[$lookup_field_id] = $r->Fields('id');
                    $lookup_slug = str_replace('.','_',$field);
                    $$lookup_slug = $slug;
                    $p->var[$lookup_slug] = $slug;

                    $file = 'pages' . $path . '/' . $folder . '/' . $folder . '.php';
                    if ( is_file( $codebase_path . $file ) ) {
                        $page[$i] = $codebase_path . $file;
                        $page_path[$i] = $file;
                        break;
                    }

                    $file = 'pages' . $path . '/' . $folder . '/' . $folder . '-profile.php';
                    if ( is_file( $codebase_path . $file ) ) {
                        if ( $model ) {
                            $primary_table = aql::get_primary_table( aql::get_aql($model) );
                        }
                        if ( $primary_table ) {
                            //echo "slug: $slug<br />";
                            //print_a($sky_qs);
                            if ( $sky_qs[$i+1] == 'add-new' || is_numeric( decrypt($sky_qs[$i+1],$primary_table) ) ) {
                                $page[$i] = $codebase_path . $file;
                                $page_path[$i] = $file;
                                break;
                            }
                        } else {
                            header("HTTP/1.1 503 Service Temporarily Unavailable");
                            header("Status: 503 Service Temporarily Unavailable");
                            header("Retry-After: 1");
                            die("Profile Page Error:<br /><b>$file</b> exists, but <b>\$primary_table</b> is not specified in <b>$settings_file</b></div>");
                        }
                    }

                    $file = 'pages' . $path . '/' . $folder . '/' . $folder . '-listing.php';
                    if ( is_file( $codebase_path . $file ) ) {
                        $page[$i] = $codebase_path . $file;
                        $page_path[$i] = $file;
                        break;
                    }

                    $file = 'pages' . $path . '/' . $folder;
                    if ( is_dir( $codebase_path . $file ) ) {
                        $page[$i] = 'directory';
                    }
                }//if
                $r = NULL;
            }//foreach
            if ( !$page[$i] ) continue;
        }// if lookup fields
    }//if $db
}//check forward
$i--;


#print_a($sky_qs);
#print_a($sky_qs_original);
#print_a($page_path);
#print_a($page);


// default page or page not found 404
if ( !is_array($page_path) && !$access_denied ) {
    if ($sky_qs_original[1]) $page[1] = $page_path[1] = $page_404;
    else {
        $page[1] = $page_path[1] = $default_page;
        $p->incpath = substr($default_page,0,strrpos($default_page,'/'));
    }
}


// set $p properties
$lastkey = array_pop(array_keys($page_path));
$p->urlpath = '/' . implode('/',array_slice($sky_qs_original,0,$lastkey));
if (!$p->incpath) $p->incpath = 'pages/' . implode('/',array_slice($sky_qs,0,$lastkey));
$p->page_path = end($page_path);
$p->queryfolders = array_slice($sky_qs_original,$lastkey);
//$p->uri_array = $sky_qs_original;
//$p->inc_array = $sky_qs;
$p->ide = $p->queryfolders[count($p->queryfolders)-1];


// set constants
define( 'URLPATH', $p->urlpath );
define( 'INCPATH', $p->incpath );
define( 'IDE', $p->ide );
// ide of previous page
define( 'XIDE', substr( $_SERVER['HTTP_REFERER'], strrpos($_SERVER['HTTP_REFERER'],'/') + 1 ) );


// remember uri
// if the page has a trailing '/' or '?', redirect to the remembered uri
if ( strlen($p->uri) == strlen($p->urlpath) + 1 ) {
    if ( $_SESSION['remember_uri'][$p->page_path] ) redirect($_SESSION['remember_uri'][$p->page_path]);
    else redirect($p->urlpath);
// if the page has query folders and/or querystring, remember it
} else if ( strlen($p->uri) > strlen($p->urlpath) + 1 ) {
    $_SESSION['remember_uri'][$p->page_path] = $p->uri;
}


// if access denied, show login page
if ( $access_denied ) {
    @include('lib/core/hooks/login/access-denied-output.php');

// otherwise, include the 'page'
} else {

    $page_css_file = substr(str_replace(array('-profile','-listing'),null,end($page_path)),0,-4) . '.css';
    $page_js_file = substr(str_replace(array('-profile','-listing'),null,end($page_path)),0,-4) . '.js';
    if ( file_exists_incpath($page_css_file) ) $p->page_css = '/' . $page_css_file;
    if ( file_exists_incpath($page_js_file) ) $p->page_js = '/' . $page_js_file;

    if ( $_POST['_p'] ) {
        $p = json_decode($_POST['_p']);
    }
    if ( $_POST['_ajax'] ) ob_start();
    $page_rev = array_reverse($page);
    foreach ( $page_rev as $j => $jpath ) {
        if ( $jpath != 'directory' ) {
            $p->script_filename = $jpath;
            // ideally all this logic will go in the page class constructor,
            // but since it's not...
            // unset the variables we used temporarily in this file
            unset( 
                $i, $j, $page_path, $page, $add_include_path, $codebase_path,
                $codebase_config, $include, $path, $temp, $uri, $sky_qs, $SQL,
                $sky_qs_original, $file, $folder, $value, $num_slugs, $j, $r,
                $matches, $scandir, $filename, $field, $table, $settings_file,
                $path_arr, $database_folder, $lookup_field_id, $lookup_slug,
                $page_rev, $jpath, $lastkey, $i_backup
            );
            include( $p->script_filename );
            break;
        }
    }
    if ( $_POST['_ajax'] ) {
        $p->div['page'] = ob_get_contents();
        ob_end_clean();
        echo json_encode($p);
    }
}


//print_pre($p);
//print_a($_SESSION);


// memcache automatically closes


// close the read and write database connections
if ( $db ) $db->Close();
if ( $dbw ) $dbw->Close();
else echo $master_db_connect_error;