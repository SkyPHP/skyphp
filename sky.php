<?php

/*

WEB SERVER SETUP REQUIREMENTS
------
install image magick rpm (whm)
install postgres rpms (whm)
reconfigure apache (mod security breaks swfuploader)

edit php.ini:
post_max_size = 150M
register_globals = Off
magic_quotes_gpc = Off
upload_max_filesize = 150M
upload_tmp_dir = /tmp
session.gc_maxlifetime = 7500

------

TODO
* protect against sql injection of ' hyphens in URL
* /test returns 404 error when public_html/test/index.php exists
* block direct access to /models and allow it to be a valid page URL.
* block direct access to /validation and allow it to be a valid page URL.

SECURITY
* use a read-only database username for $db connection
* separate username that can write for $dbw connection 


*/

// start the stopwatch
$sky_start_time = microtime(true);

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
    include( 'lib/core/functions.inc.php' );
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
$uri = explode('?',$_SERVER['REQUEST_URI']);
$uri_file_path = $uri[0];
//echo '*' . $uri_file_path . '*<br />';
$uri_querystring = $uri[1];
$sky_qs = explode('/',$uri_file_path);
$uri_file_path = substr($uri_file_path,1); // remove the leading slash
unset( $sky_qs[0] );

// check each 'folder' in the url for different types of pages and files
for ( $i=1; $i<=count($sky_qs); $i++ ) {

    $slug = $sky_qs[$i];

    $last_path = $path;

    if ( $i > 1 && !$slug ) {
        // since this is a 'page', remove trailing slash and 301 redirect for cannonicalization purposes
        $redirect_uri = '/' . substr($uri_file_path,0,-1);
        if ($uri_querystring) $redirect_uri .= '?' . $uri_querystring;
        redirect( $redirect_uri );
    }

    debug("<hr />slug=$slug<br />i=$i<hr />");

    if ( $path ) $path .= '/';
    else if ( $slug == 'media' ) include( 'lib/core/quick-serve/media.php' );
    else if ( $slug == 'media-zip' ) include( 'lib/core/quick-serve/media-zip.php' );
    else if ( $slug == 'index.php' ) redirect('/'); // don't serve this file directly -- only via .htaccess
    else if ( file_exists_incpath( $uri_file_path, true ) ) {
        if ( substr($uri_file_path,-4)=='.php' && substr($uri_file_path, 0, 6 ) != 'pages/' ) {
            include( $uri_file_path );
            exit();
        } else include( 'lib/core/quick-serve/file.php' );
    }

    if (!$path) {
        // now we know we are serving a page
        // do this stuff only once per page

        debug('serve a page<br />');

        // db connection -- if can't connect, show 503 error down for maintenance
        include_once( 'lib/adodb/adodb.inc.php' );

        if ( $down_for_maintenance ) {

            $error = "<!-- manually down for maintenance -->";

        } else if ( $db_name && $db_domain ) {

            debug('connect to database<br />');
            
            if ( class_exists('Memcache') && count($memcache_servers) ) {
                if (!$memcache_port) $memcache_port = 11211;
                $memcache = new Memcache;
                foreach ($memcache_servers as $memcache_host) {
                    $memcache->addServer($memcache_host, $memcache_port);
                    if ($memcache_save_path) $memcache_save_path .= ',';
                    $memcache_save_path .= $memcache_host . ':' . $memcache_port;
                }
            }

            $db = &ADONewConnection( $db_platform );
            @$db->Connect( $db_domain, $db_username, $db_password, $db_name );
            if ($db->ErrorMsg()) {
                $down_for_maintenance = true;
                $error = "<!-- \$db error ($db_domain): " . $db->ErrorMsg() . " -->";
            } else {

                if ( $slony_cluster_name ) {
                     $SQL = "SELECT username, host, password, dbname, port FROM
                            slony_node 
                            INNER JOIN _$slony_cluster_name.sl_set 
                               ON _$slony_cluster_name.sl_set.set_origin=slony_node.id
                            ORDER BY _$slony_cluster_name.sl_set.set_id ASC
                            ";

                    $r = $db->Execute($SQL);
                    if ($db->ErrorMsg()) {
                        $down_for_maintenance = true;
                        $error = "<!-- \$dbw error ($dbw_domain): " . $db->ErrorMsg() . " -->";
                    }elseif(!$r->EOF){
                       $dbw_domain = $r->Fields('host');
                       $db_username = $r->Fields('username');
                       $db_password = $r->Fields('password');
                       $db_name = $r->Fields('dbname');
                    }

                    if(!$dbw_domain){
                       $down_for_maintenance = true;
                       $error = "<!-- Invalid \$slony_cluster_name -->";
                    }

                    //check if we are subscribed, if we are not, then read from $dbw
                    $SQL = "SELECT sub_active FROM
                            slony_node 
                            LEFT JOIN _$slony_cluster_name.sl_subscribe
                               ON _$slony_cluster_name.sl_subscribe.sub_receiver=slony_node.id 
                            WHERE slony_node.host='$db_domain' AND slony_node.active=1
                            ";
                    $r = $db->Execute($SQL);
                    if ($db->ErrorMsg()) {
                       $down_for_maintenance = true;
                       $error = "<!-- \$db error ($db_domain): " . $db->ErrorMsg() . " -->";
                    }elseif(!$r->Fields('sub_active') || $r->Fields('sub_active')=='f'){
                       $db->disconnect();
                       $db =& $dbw;
                       $db_domain = $dbw_domain;
                    }

                }

                if ( !$dbw_domain ) {
                    $dbw =& $db;
                    $dbw_domain = $db_domain;
                } else {
                    // TODO: this does not allow a custom port
                    $dbw = &ADONewConnection( $db_platform );
                    @$dbw->Connect( $dbw_domain, $db_username, $db_password, $db_name );
                    if ($dbw->ErrorMsg()) {
                        $down_for_maintenance = true;
                        $error = "<!-- \$dbw error ($dbw_domain): " . $db->ErrorMsg() . " -->";
                    }
                }//if
            }//if
        }//if

        if ( $down_for_maintenance ) {
            include( 'pages/503.php' );
            echo $error;
            die();
        }//if

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

        // include functions & classes
        include( 'lib/class/class.aql.php' );
        include( 'lib/core/class.cache.php' );
        include( 'lib/core/class.media.php' );
        include( 'lib/core/class.page.php' );
        include( 'lib/core/class.snippet.php' );

        $p = new page();

/*
        // include user-defined include files
        if ( is_array($includes) ):
            foreach ($includes as $include):
                include_once($include);
            endforeach;
        endif;
*/


        /*
         * SESSION & AUTHENTICATION
         */
        if ( $disable_subdomain_cookies ) {
            $cookie_domain = $_SERVER['HTTP_HOST'];
        } else {
            // set the cookie domain so that sessions are shared between subdomains
            if (substr_count ($_SERVER['HTTP_HOST'], ".") == 1) {
                $cookie_domain = "." . $_SERVER['HTTP_HOST'];
            } else {
                $cookie_domain = preg_replace ('/^([^.])*/i', NULL, $_SERVER['HTTP_HOST']);
            }
        }

        // set the PHP session id (PHPSESSID) cookie to a custom value
        session_set_cookie_params( $cookie_timeout, '/', $cookie_domain );

        // timeout value for the garbage collector
        //   we add 300 seconds, just in case the user's computer clock
        //   was synchronized meanwhile; 600 secs (10 minutes) should be
        //   enough - just to ensure there is session data until the
        //   cookie expires
        $garbage_timeout = $cookie_timeout + 600; // in seconds
        ini_set('session.gc_maxlifetime', $garbage_timeout);

        // this is needed for swfupload
        if (isset($_POST["PHPSESSID"])) {
            session_id($_POST["PHPSESSID"]);
        }

        // start session
        if ( $memcache ) {
            ini_set('session.save_handler', 'memcache');
            ini_set('session.save_path', $memcache_save_path);
        } else if ( $db_name && $dbw_domain ) {
            include_once("lib/adodb/session/adodb-session2.php");
            ADOdb_Session::config($db_platform, $dbw_domain, $db_username, $db_password, $db_name, $options=false);
        }//if
        session_cache_limiter('none');
        session_start();

        // logout the current user if applicable
        if ($_GET['logout']) {
            unset($_SESSION['login']);unset($_SESSION['login_array']);
            if ($_COOKIE["saveuser"] && $_COOKIE["savepassword"])
            {
                @setcookie('savepassword', '', time()-5184000, '/', $cookie_domain);
                @setcookie('saveuser', '', time()-5184000, '/', $cookie_domain);
                unset($_COOKIE["saveuser"]);
                unset($_COOKIE["savepassword"]);
            }
        }

        // user authentication
        /* check if there is a saved cookie that contains credentials */
        if (!( $_POST['login_username'] && $_POST['login_password']) )
        {
            if ($_COOKIE["saveuser"] && $_COOKIE["savepassword"])
            {
                $_POST['login_username']=decrypt($_COOKIE["saveuser"]);
                $_POST['login_password']=decrypt($_COOKIE["savepassword"]);
            }
        }

        if ( $_POST['login_username'] && $_POST['login_password'] ) {

            $_POST['login_username'] = trim($_POST['login_username']);
            $_POST['login_password'] = trim($_POST['login_password']);

            $access_granted = false;
			
			$aql = 	"
						person {
							fname,
							lname,
							email_address,
							access_group,
							activation_required
							where ((
								UPPER(person.email_address) = UPPER('".addslashes($_POST['login_username'])."')
								and UPPER(person.password) = UPPER('".addslashes($_POST['login_password'])."')
							) or (
								UPPER(person.username) = UPPER('".addslashes($_POST['login_username'])."')
								and UPPER(person.password) = UPPER('".addslashes($_POST['login_password'])."')
							))
						}
					";
			$rs = aql::select($aql);
			if (is_array($rs)) {
				$access_granted = true;
				$settings_file = 'pages/' . $path . '/' . $slug . '-settings.php';
				@include_once( $settings_file );
				$return_k = 0;
				foreach ($rs as $k=>$r) {
					$_SESSION['login']['person_id'] = $r['person_id'];
					if ($access_groups) {
						if (auth($access_groups)) $access_denied = false;
						else $access_denied = true;
						if (!$access_denied) $return_k = $k;
					}
					
				}
				$r = $rs[$return_k];
				$_SESSION['login']['person_id'] = $r['person_id'];
                $_SESSION['login']['person_ide'] = encrypt($r['person_id'],'person');
                $_SESSION['login']['activation_required'] = $r['activation_required'];
                $_SESSION['login']['fname'] = $r['fname'];
                $_SESSION['login']['lname'] = $r['lname'];
                $_SESSION['login']['email'] = $r['email_address'];
                $_SESSION['login']['access_group'] = $r['access_group'];
            
                if($_POST['remember_me'])
                {
                    @setcookie('saveuser', encrypt($_POST['login_username']), time()+5184000, '/', $cookie_domain);
                    @setcookie('savepassword', encrypt($_POST['login_password']), time()+5184000, '/', $cookie_domain);
                }
                @setcookie('username', $r['email_address'], time()+5184000, '/', $cookie_domain);

                // log this login
                aql::update( 'person', array('last_login_time'=>'now()'), $r['person_id'] );
            }//if
        }//if


        debug('<hr />');

    }//if

    // now we start looking in various locations for the 'page' to serve up
	
	// but first let's get the IDE from the URL now in case we need it in the settings.php file
	$tmp = $_SERVER['REQUEST_URI'];
	$tmp = explode('?',$tmp);
	$tmp = $tmp[0];
	$tmp = explode('/',$tmp);
	$tmp = $tmp[ count($tmp)-1 ];
	$_POST['sky_ide'] = $tmp;
	define( 'IDE', $_POST['sky_ide'] );
	

    $path .= $slug;

    debug('slug: ' . $slug . '<br />');
    debug('path: ' . $path . '<br />');

   // if ($_GET['debug']) print_a( $sky_qs );

    // the uri is blank, serve up the default homepage
    if ( count($sky_qs) == 1 && !$slug ) {
		$found[$i] = $default_page;
        break;
	} 
	
    // include the page settings file
    // if this folder has restricted access, is the user authorized?
    $access_groups = NULL;
    $settings_file = 'pages/' . $path . '/' . $slug . '-settings.php';
    @include_once( $settings_file );
    debug("340 primary_table=$primary_table, model=$model<br />");
    if ($access_groups) {
        if (auth($access_groups)) $access_denied = false;
        else $access_denied = true;
    }

    // determine the primary_table if possible
    if ( $primary_table || $model ) {
        if ( !$primary_table && $model ) {
            $primary_table = aql::get_primary_table( aql::get_aql($model) );
        }
    }

    // start checking all the possible implied file paths for something to serve
    if ( $path ) {

        // check for a 'foreign' html file
        $check_files = array(
            $uri_file_path . '/index.htm',
            $uri_file_path . '/index.html'
        );
        foreach ( $check_files as $check_file ) {
            if ( file_exists_incpath( $check_file ) ) {
                // canonicalize with the trailing slash (non-page)
                if ( substr($uri_file_path,-1)!='/' ) {
                    $qs = NULL;
                    if ( $uri_querystring ) $qs = '?' . $uri_querystring;
                    redirect('/'.$uri_file_path.'/'.$qs);
                }
                $_SERVER['REQUEST_URI'] = '/' . $check_file;
                include( 'lib/quick-serve/file.php' );
            }
        }

        // check the standard page locations
        $check_files = array(
            'pages/' . $path . '/' . $slug . '.php',
            'pages/' . $path . '.php'
        );
        foreach ( $check_files as $check_file ) {
            debug('checking: ' .  $check_file . '<br />');
            if ( file_exists_incpath( $check_file ) ) {
                $continue = true;
                $found[$i] = $check_file;
                //break; // don't break here or deeper pages will not be accesible
            }
        }
        if ( $continue ) {
            $continue = false;
            continue;
        }

        // no page exists in the standard locations.  can we display a profile page?
        $check_file = 'pages/' . $path . '/' . $slug . '-profile.php';
        $settings_file = 'pages/' . $path . '/' . $slug . '-settings.php';
        if ( file_exists_incpath( $check_file ) ) {
            @include( $settings_file );
            debug("397 primary_table=$primary_table, model=$model<br />");
            if ( $model ) {
                $primary_table = aql::get_primary_table( aql::get_aql($model) );
            }
            if ( $primary_table ) {
                //if ( $sky_qs[$i+1] == 'add-new' || is_numeric( decrypt($sky_qs[$i+1],$primary_table) ) ) {
                if ( $sky_qs[count($sky_qs)] == 'add-new' || is_numeric( decrypt($sky_qs[count($sky_qs)],$primary_table) ) ) {
                    $found[$i] = $check_file;
                    continue;
                }
            } else {
                header("HTTP/1.1 503 Service Temporarily Unavailable");
                header("Status: 503 Service Temporarily Unavailable");
                header("Retry-After: $seconds");
                die("Profile Page Error:<br /><b>$check_file</b> exists, but <b>\$primary_table</b> is not specified in <b>$settings_file</b></div>");
            }
        }

        // profile page was a no go.  can we display a listing page?
        $check_file = 'pages/' . $path . '/' . $slug . '-listing.php';
        if ( file_exists_incpath( $check_file ) ) {
            $found[$i] = $check_file;
            $found_listing_page[$i] = true;
            continue;
        }

        // if there is a folder, let's keep decending without checking database folders
        $check_dir = 'pages/' . $path;
        if ( file_exists_incpath( $check_dir ) ) {
            continue;
        }

        $no_db_folder_path = $path;
        // listing page was a no go.  let's check to see if we have any database folders specified
        if ( $db ) {
            $lookup_file_name = NULL;
            $lookup_folder_default_page = NULL;
            // detect lookup fields
            $lookup_fields = array();
            debug('reset $lookup_fields<br />');
            foreach ( $codebase_path_arr as $codebase_path ) {
                debug("codebase_path=$codebase_path<br />");
                $scandir = $codebase_path . 'pages/' . $last_path;
                if ( is_dir( $scandir ) ) {
                    debug("scandir=$scandir<br />");
                    foreach ( scandir( $scandir ) as $filename ) {
                        if ( substr($filename,0,1)=='_' && strlen($filename) > 6 ) {
                            debug("filename=$filename<br />");
                            if ( is_dir( $scandir . '/' . $filename ) ) {
                                if ( substr($filename,-1)=='_' ) {
                                    $lookup_fields[ substr($filename,1,-1) ] = 'd';
                                } else if ( substr($filename,-2)=='_i' ) {
                                    $lookup_fields[ substr($filename,1,-2) ] = 'di';
                                }
                            }
                        }
                    }
                }
            }
            if ($_GET['debug']) print_a($lookup_fields);
            if ( $lookup_fields ) {
                $found_lookup_match = false;
                foreach ( $lookup_fields as $lookup_field => $lookup_type ) {
                    if ( $found_lookup_match ) break; // if we already found a lookup folder match, don't look for a second match
                    debug($lookup_field . ' is a lookup folder.<br />');
                    $lookup_field_dot = strpos( $lookup_field, '.' );
                    $lookup_table = substr( $lookup_field, 0, $lookup_field_dot );
                    if ( strpos($lookup_type,'i') === false ) $lookup_operator = '=';
                    else $lookup_operator = 'ilike';

                    if ( !$has_run_first && file_exists_incpath('pages/run-first.php') ) {
                        $has_run_first = true;
                        include('pages/run-first.php');
                    }//if

                    $db_value = $slug;
                    $lookup_file_name = "_" . $lookup_field . "_";
                    if ( $lookup_operator == 'ilike' ) $lookup_file_name .= 'i';
                    $slug2 = $lookup_file_name;
                    debug("path=$path<br />");
                    if (strrpos($path,'/')) $path = substr( $path, 0, strrpos($path,'/') + 1 ) . $lookup_file_name;
                    else $path = $lookup_file_name;
                    $settings_file = 'pages/' . $path . '/' . $slug2 . '-settings.php';
                    debug($settings_file."<br />");
                    $primary_table = NULL;
                    @include( $settings_file );
                    debug("483 primary_table=$primary_table, model=$model<br />");

                    $SQL = "select id, $lookup_field as slug
                            from $lookup_table
                            where active = 1 and $lookup_field $lookup_operator '$db_value'";
                    if ( $database_folder['where'] ) {
                        $SQL .= ' and ' . $database_folder['where'];
                    }
                    debug($SQL . '<br />');
                    $r = sql($SQL);
                    $database_folder = NULL;
                    if ( !$r->EOF ) {
                        debug('found lookup match!<br />');
                        $found_lookup_match = true;
                        $lookup_field_id = $lookup_table . '_id';
                        $$lookup_field_id = $r->Fields('id');
                        $lookup_slug = str_replace('.','_',$lookup_field);
                        $$lookup_slug = $r->Fields('slug');

                        debug('path: '.$path.'<br />');
                        @include( 'pages/' . $path . '/' . $lookup_file_name . '-script.php' );
                        $lookup_folder_default_page = 'pages/' . $path . '/' . $lookup_file_name . '.php';
                        debug( "checking: " . $lookup_folder_default_page . '<br />' );
                        if ( file_exists_incpath( $lookup_folder_default_page ) ) $found[$i] = $lookup_folder_default_page;
                        else {
                            
                            // no page exists in the standard locations.  can we display a profile page?
                            $check_file = 'pages/' . $path . '/' . $slug2 . '-profile.php';
                            
                            if ( file_exists_incpath( $check_file ) ) {

                                debug("primary_table=$primary_table<br />");

                                // determine the primary_table if possible
                                if ( $primary_table || $model ) {
                                    if ( !$primary_table && $model ) {
                                        $primary_table = aql::get_primary_table( aql::get_aql($model) );
                                    }
                                }
                                if ( $primary_table ) {
                                    debug("primary_table=$primary_table<br />");
                                    //if ( $sky_qs[$i+1] == 'add-new' || is_numeric( decrypt($sky_qs[$i+1],$primary_table) ) ) {
                                    if ( $sky_qs[count($sky_qs)] == 'add-new' || is_numeric( decrypt($sky_qs[count($sky_qs)],$primary_table) ) ) {
                                        $found[$i] = $check_file;
                                        continue;
                                    }
                                } else {
                                    header("HTTP/1.1 503 Service Temporarily Unavailable");
                                    header("Status: 503 Service Temporarily Unavailable");
                                    header("Retry-After: $seconds");
                                    die("Profile Page Error:<br /><b>$check_file</b> exists, but <b>\$primary_table</b> is not specified in <b>$settings_file</b></div>");
                                }
                            }

                            // profile page was a no go.  can we display a listing page?
                            $check_file = 'pages/' . $path . '/' . $slug2 . '-listing.php';
                            if ( file_exists_incpath( $check_file ) ) {
                                $found[$i] = $check_file;
                                $found_listing_page[$i] = true;
                                continue;
                            }
                        }
                        break;
                    }//if
                    $r = NULL;
                }//foreach
                if ( !$found_lookup_match ) break;
            }// if lookup fields

        }//if db

        $check_file = $uri_file_path . '/index.php';
        if ( file_exists_incpath( $check_file ) ) {
            // canonicalize with the trailing slash (non-page)
            if ( substr($uri_file_path,-1)!='/' ) {
                $qs = NULL;
                if ( $uri_querystring ) $qs = '?' . $uri_querystring;
                redirect('/'.$uri_file_path.'/'.$qs);
            }
            $_SERVER['REQUEST_URI'] = '/' . $check_file;
            include( $check_file );
            exit();
        }

        $check_dir = 'pages/' . $path;
        debug("checkdir=$check_dir<br />");
        if ( !$lookup_slug && !file_exists_incpath($check_dir) ) break;

    }//if path

    $last_path = $path;
    $last_slug = $slug;
    $last_lookup_folder_default_page = $lookup_folder_default_page;

    if ($_GET['debug']) print_a($found);

}//for each slug

$r = NULL;

// if we still haven't found the page or file, display 404 error or developer tools
if ( !$found && !$access_denied ) {

    debug('no page found <br />');

    // TODO
    // if developer and is_folder show the directory listing of the codebase that the file is from

    // we've checked everywhere.  display 404 error page.

		$found[0] = $page_404;
}

// of all the pages in our path that exist, identify the correct page's file path to include
if (is_array($found)) {
    end($found);
    $slice_key = key($found);
    $page_path = array_pop( $found );
}//if

// make paths and other helpful data available as constants and in $_POST
$_POST['sky_qs'] = my_array_unique(array_slice($sky_qs,$slice_key));
// this is needed in the profile module
if ( count($sky_qs) ) $_POST['sky_path'] = implode('/',$sky_qs);
// this is quite helpful on most pages
$_POST['sky_ide'] = $_POST['sky_qs'][count($_POST['sky_qs'])-1];
// sky_page
if ( $_POST['sky_qs'] ) {
    $end = strrpos( $_POST['sky_path'], $_POST['sky_qs'][0] ) - 1;
    if ( $end < 0 ) $end = strlen($_POST['sky_path']);
    $_POST['sky_page'] = '/' . substr($_POST['sky_path'],0,$end);
    $end = strrpos( $path, $_POST['sky_qs'][0] ) - 1;
    if ( $end < 0 ) $end = strlen($path);
    $path = substr($path,0,$end);
} else {
    $_POST['sky_page'] = '/' . $_POST['sky_path'];
    if ( substr($_POST['sky_page'],-1) == '/' ) $_POST['sky_page'] = substr($_POST['sky_page'],0,-1);
}

debug("PATH=$path");
if ($_GET['debug']) print_a($_POST);

// get the ide of the previous page
$start = strrpos($_SERVER['HTTP_REFERER'],'/') + 1;
$last_ide = substr($_SERVER['HTTP_REFERER'],$start);
define( 'XIDE', $last_ide );
define( 'URLPATH', $_POST['sky_page'] );
define( 'INCPATH', 'pages/' . $path );
define( 'IDE', $_POST['sky_ide'] );
define( 'PERSON_ID', $_SESSION['login']['person_id'] );
define( 'PERSON_IDE', $_SESSION['login']['person_ide'] );
if ($primary_table) define( 'ID', decrypt($_POST['sky_ide'],$primary_table) );

// "remember" the criteria you have selected on a listing page
if ( $found_listing_page[$slice_key] && $remember_uri !== false ) {
    // save the criteria for each of the listing pages you have visited in a session, i.e. remember what tab you were on
    $path = $_SERVER['REQUEST_URI'];
    if ( !strpos($path,'?') && !$_POST['sky_qs'][0] ) {
        if (!$_SESSION['login']['remember_uri'][$path]) redirect($path.'?',302);
        else redirect($_SESSION['login']['remember_uri'][$path],302);
    } else {
        $needle = $_POST['sky_qs'][0];
        if (!$needle) $needle = '?';
        else $needle = '/' . $needle;
        $end = strpos($path,$needle);
        $short_path = substr($path,0,$end);
        $_SESSION['login']['remember_uri'][$short_path] = $path;
        if ($_GET['debug']) print_a($_SESSION['login']);
    }
}

// figure out the css and js files to be auto-linked into the head of the global template (if they exist)

debug("page_path=$page_path<br/>");

$include_file_end = strrpos($page_path,'.');
$include_file_noext = substr($page_path,0,$include_file_end);
$include_file_noext = str_replace(array('-listing','-profile'),'',$include_file_noext);
$page_css_file = '/' . $include_file_noext . '.css';
$page_js_file = '/' . $include_file_noext . '.js';

// run this before the page is executed
if ( file_exists_incpath('pages/run-first.php') ) include('pages/run-first.php');

// run this before the page is executed
if ($pre_page_include) include($pre_page_include);

// include or output the page or file
if ($access_denied) {
    if ($_SESSION['login']['person_id']) {
		include( 'pages/401.php' );
	} else {
        template::inc('global','top');
        include('pages/login/login.php');
        template::inc('global','bottom');
    }//if
} else {
    include( $page_path );
}

// close the read and write database connections
if ( $db ) {
	$db->Close();
	$dbw->Close();
}//if

?>
