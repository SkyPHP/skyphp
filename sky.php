<?php

# start the stopwatch
$sky_start_time = microtime(true);

# check requirements
if (!$skyphp_storage_path && !$sky_media_local_path) {
    die('You must configure $skyphp_storage_path in index.php.');
}
if (!is_array($codebase_path_arr) || !$codebase_path_arr) {
    die('Missing $codebase_path_arr on index.php');
}

# backward compatibility paths
$sky_install_path = ($sky_install_path) ?: $skyphp_codebase_path;
if ($skyphp_storage_path) {
    if (substr($skyphp_storage_path,-1) !='/') $skyphp_storage_path .= '/';
    $sky_media_local_path = $skyphp_storage_path . 'media';
} else if ($sky_media_local_path) {
    $skyphp_storage_path = $sky_media_local_path . '/';
}

# make sure codebase paths end with a slash
$codebase_path_arr = array_map(function($path){
    return rtrim($path, '/') . '/';
}, $codebase_path_arr);

# add codebases to include path
$add_include_path = implode(PATH_SEPARATOR, $codebase_path_arr);
set_include_path($add_include_path);

# if down for maintenance
if ($down_for_maintenance) {
    include 'pages/503.php';
    die;
}

# include common functions
include_once 'lib/Sky/functions.inc.php';

# parse the url for the folders
$uri =  call_user_func(function($t) {
            return array('path' => $t[0], 'query' => $t[1]);
        }, explode('?', $_SERVER['REQUEST_URI']));

# check if quick serve
$check_paths = array(
    array('path' => null, 'is_file' => true),   # checks for exact file
    array('path' => '/index.html'),             # if folder contains index.html
    array('path' => '/index.htm')               # if folder contains index.htm
);

foreach ($check_paths as $p) {

    $path = $uri['path'] . $p['path'];
    if (!file_exists_incpath($path, $p['is_file'])) continue;

    if (!$p['is_file']) {
        add_trailing_slash();
        $_SERVER['REQUEST_URI'] = '/' . $path;
    }

    if (substr($path,-4) == '.php' && substr($path, 0, 6) != 'pages/') {
        # if serving a php file not in pages/
        $_SERVER['REQUEST_URI'] = $path;
        include substr($path, 1);
    } else {
        # serve file with correct mime-type
        include 'includes/hooks/quick-serve-file.php';
    }

    exit;

}

$path = null;

##########################################################################################
#                    if we got this far we are serving a page                            #
##########################################################################################

# auto-loader
include 'includes/hooks/autoloader.php';

# include the config.php of each codebase;
# default skyphp config values will be overwritten by higher level codebases
foreach ( array_reverse( $codebase_path_arr ) as $codebase_path ) {
    $codebase_config = $codebase_path . 'config.php';
    if (!file_exists($codebase_config)) continue;
    $includes = array();
    include $codebase_config;
    if (!is_array($includes)) continue;
    foreach ($includes as $include) include_once $include;
}

# exception handler
set_exception_handler(array('\\Sky\\ErrorHandler', 'errorPopUp'));

# canonical redirect hook / session compat / timezone set
include 'includes/hooks/env-ini.php';

# web services hooks
include 'includes/hooks/mem-connect.php';
include 'includes/hooks/db-connect.php';
include 'includes/hooks/media-connect.php';

# create page router
$router = new \Sky\PageRouter(array(
    'codebase_paths' => $codebase_path_arr,
    'db' => $db,
    'page_path_404' => $page_404,
    'page_path_default' => $default_page,
    'uri' => $uri['path']
));

# instantiate page using PageRouter
$p = new \Sky\Page($router->getPageProperties());
$p->sky_start_time = $sky_start_time;

$protocol = 'http';
if ($server_ssl_header && $_SERVER[$server_ssl_header]) $protocol = 'https';
if ($_SERVER['HTTPS']) $protocol = 'https';

$p->protocol = $_SERVER['HTTPS'] ? 'https' : 'http';

# create session if necessary
include 'includes/hooks/session-start.php';

# $access_groups to global (for authenticate hook)
# authentication hook
$access_groups = $router->settings['access_groups'];
include 'includes/hooks/login-authenticate.php';

# run the page
$p->run();

# output the error if the master is down
if ($db_host && !$dbw && !$p->is_ajax_request) {
    echo $master_db_connect_error;
} else {
    // no problems with the master db
    // make sure our php-cron-daemon is running only on one host
    if ($sky_cron_enabled) {
        // if this host is the host with the pid, check to make sure
        // it's running.
    }
}
