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

# add codebases to include path
$add_include_path = implode(PATH_SEPARATOR, $codebase_path_arr);
set_include_path($add_include_path);

# if down for maintenance
if ($down_for_maintenance) {
    include 'pages/503.php';
    die;
}

# TODO: clean out functions.inc.php
include_once 'lib/core/functions.inc.php';

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

    # serve file with correct mime-type
    include 'lib/core/quick-serve/file.php';
    die;
}

$path = null;

##########################################################################################
#                    if we got this far we are serving a page                            #
##########################################################################################

# auto-loader
include 'lib/core/hooks/__autoload/__autoload.php';

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

# canonical redirect hook / session compat / timezone set
include 'lib/core/hooks/env-ini/env-ini.php';

# web services hooks
include 'lib/core/hooks/web-services/mem-connect.php';
include 'lib/core/hooks/web-services/db-connect.php';
include 'lib/core/hooks/web-services/media-connect.php';

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
$p->protocol = $_SERVER['HTTPS'] ? 'https' : 'http';

# create session if necessary
include 'lib/core/hooks/web-services/session.php';

# $access_groups to global (for authenticate hook)
# authentication hook
$access_groups = $router->settings['access_groups'];
include 'lib/core/hooks/login/authenticate.php';

# run the page
$p->run();

# close the read and write database connections
if ($db_host) {
    if ($db) $db->Close();
    if ($dbw) $dbw->Close();
    else if (!$p->is_ajax_request) echo $master_db_connect_error;
}
