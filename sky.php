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


// auto-loader
include('lib/core/hooks/__autoload/__autoload.php');


// include the config.php of each codebase; default skyphp config values will be overwritten by higher level codebases
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
// example: www.example.com/page --> example.com/page
$sky_canonical_host = $sky_canonical_redirect[$_SERVER['HTTP_HOST']];
if ($sky_canonical_host) {
	header ( 'HTTP/1.1 301 Moved Permanently' );
	header ( 'Location: http://' . $sky_canonical_host . $_SERVER['REQUEST_URI'] );
	exit();
}//if
// example: www.example.com/page --> example.com
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
        $_SERVER['REQUEST_URI'] = $uri['path'];
        include( substr($uri['path'], 1) );
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


// web services hooks
include('lib/core/hooks/web-services/mem-connect.php');
include('lib/core/hooks/web-services/db-connect.php');
include('lib/core/hooks/web-services/media-connect.php');


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


// register globals is off, so don't throw the PHP 5.2.3 warning if variables have same name as SESSION keys
ini_set('session.bug_compat_warn', 0);
ini_set('session.bug_compat_42', 0);

// PHP 5.3 Throws Error if this line is not here
date_default_timezone_set($date_default_timezone);


// instantiate this page
$p = new page();

// start session
if(!$no_cookies){
    if ( $enable_subdomain_cookies || $multi_session_domain ) {
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

    if ( $multi_session_domain ) {
        // get the base domain name and subdomain name
        foreach ( $multi_session_domain as $domain ) {
            $start = strpos($_SERVER['HTTP_HOST'], $domain);
            if ( $start !== false ) {
                if ( $start == 0 ) {
                    $p->subdomain = '';
                    $p->base_domain = $_SERVER['HTTP_HOST'];
                } else {
                    $p->subdomain = substr($_SERVER['HTTP_HOST'], 0, $start - 1);
                    $p->base_domain = substr($_SERVER['HTTP_HOST'], $start);
                }
                break;
            }
        }

        // load the current session values
        if ( $p->base_domain ) {
            $subdomain = $p->subdomain;
            if (is_array($_SESSION['multi-session'])) $first_key = array_shift(array_keys($_SESSION['multi-session']));
            // if no session on this subdomain, but there is another session,
            // copy the existing session to the new subdomain session
            if ( !is_array( $_SESSION['multi-session'][$subdomain] )
                && is_array( $_SESSION['multi-session'][$first_key] ) ) {
                $_SESSION['multi-session'][$subdomain] = $_SESSION['multi-session'][$first_key];
            }
            if ( is_array( $_SESSION['multi-session'][$subdomain] ) ) {
                foreach ( $_SESSION['multi-session'][$subdomain] as $var => $val ) {
                    $_SESSION[$var] = $val;
                }
            }

            // make sure the current session values are saved to multi-session array after connection closes
            register_shutdown_function(function(){
                global $p;
                $subdomain = $p->subdomain;
                $session = array(
                    'multi-session' => $_SESSION['multi-session']
                );
                $temp = $_SESSION;
                unset($temp['multi-session']);
                $session['multi-session'][$subdomain] = $temp;
                $_SESSION = $session;
            });
        }
    }


}

$router = new SkyRouter(array(
    'codebase_paths' => $codebase_path_arr,
    'db' => $db,
    'page_path_404' => $page_404,
    'page_path_default' => $default_page
));

$router->checkPath($sky_qs, 'pages');

// settings to global :/
foreach ($router->settings as $k => $v) $$k = $v;

// user authentication (uses $access_groups from $router->configs)
include 'lib/core/hooks/login/authenticate.php';

$p->setPropertiesByRouter($router);
$p->sky_start_time = $sky_start_time;
$p->protocol = $_SERVER['HTTPS'] ? 'https' : 'http';

$script_files = $router->scripts;
$page = $router->page;
$page_path = $router->page_path;
$p->vars = $router->vars;

// vars into global scope for backwards compatibility
foreach ($p->vars as $k => $v) $$k = $v;

// krumo($router, $p);

// set constants
define( 'URLPATH', $p->urlpath );
define( 'INCPATH', $p->incpath );
define( 'IDE', $p->ide );
// ide of previous page
define( 'XIDE', substr( $_SERVER['HTTP_REFERER'], strrpos($_SERVER['HTTP_REFERER'],'/') + 1 ) );


// remember uri
// if the page has a trailing '/' or '?', redirect to the remembered uri
/*if ( strlen($p->uri) == strlen($p->urlpath) + 1 ) {
    if ( $_SESSION['remember_uri'][$p->page_path] ) redirect($_SESSION['remember_uri'][$p->page_path]);
    else redirect($p->urlpath);
// if the page has query folders and/or querystring, remember it
} else if ( strlen($p->uri) > strlen($p->urlpath) + 1 ) {
    $_SESSION['remember_uri'][$p->page_path] = $p->uri;
}
*/
include('lib/core/hooks/uri/uri.php');

// if access denied, show login page
if ( $access_denied ) {
    if ( file_exists_incpath($access_denied_output_file) ) include($access_denied_output_file);

// otherwise, include the 'page'
} else {

    // run this before the page is executed
    if ( file_exists_incpath('pages/run-first.php') ) include('pages/run-first.php');

    // run the script files
    if ( is_array($script_files) )
    foreach ( $script_files as $script_file => $null ) {
        include( $script_file );
    }
    
    //print_r($_POST);
    $p->setAssetsByPath(end($page_path));

    // if ajax refreshing a secondary div after an ajax state change
    if ( $_POST['_p'] ) {
        // need this because we need the variables etc from the 'parent' page we are on
        $p = json_decode($_POST['_p']);
    }
    if ( $_POST['_json'] ) ob_start();
    else if ( $_GET['_script'] ) {
        ob_start();
        $p->no_template = true;
    }
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
                $page_rev, $jpath, $lastkey, $i_backup, $script_file, $script_files
            );
            include( $p->script_filename );
            break;
        }
    }
    if ( $_POST['_json'] ) {
        if (is_array($p->div) ) $p->div['page'] = ob_get_contents();
        else $p->div->page = ob_get_contents(); // refreshing a secondary div after an ajax state change
        ob_end_clean();
        $p->sky_end_time = microtime(true);
        json_headers();
        echo json_encode($p);
    }
    else if ( $_GET['_script'] ) {
        if (is_array($p->div) ) $p->div['page'] = ob_get_contents();
        else $p->div->page = ob_get_contents();
        ob_end_clean();
        $p->sky_end_time = microtime(true);
        header("Content-type: text/javascript");
        echo "$(function(){ render_page( " . json_encode($p) . ", '{$p->uri}', '{$_SERVER['HTTP_HOST']}', '".($_GET['_script_div']?:'page')."' ); });";
    }

}


//print_pre($p);
//print_a($_SESSION);


// run this after the page is executed
if ( file_exists_incpath('pages/run-last.php') ) include('pages/run-last.php');

/*
// user authentication
$update_session = 'lib/core/hooks/login/update-session.php';
if ( file_exists_incpath($update_session) ) include($update_session);
*/

#echo 'last';
#print_a($_SESSION);

// memcache automatically closes


// close the read and write database connections
if ( $db_host ) {
    if ( $db ) $db->Close();
    if ( $dbw ) $dbw->Close();
    else if (!$p->is_ajax_request) echo $master_db_connect_error;
}
