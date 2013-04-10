<?php

/**
 * This is a sample index file for a dev site.
 */

// config
# $down_for_maintenance = true;
$dev_domain = 'example.com';
$codebases_path = '/path/to/codebases/';
$sites_json_file = 'sites.json';
$skyphp_storage_path = '/home/skydevus/storage';

$master_skyphp = $codebases_path . 'SkyPHP/skyphp/master/';
include $master_skyphp . 'lib/Sky/dev/get-codebase.inc.php';

$site_url = strtolower($_SERVER['HTTP_HOST']);
$site = str_replace('.' . $dev_domain, '', $site_url);

$subdomain_index = $site . '.php';
if (file_exists($subdomain_index)) {
    include $subdomain_index;
} else {
    $sites = json_decode(file_get_contents($sites_json_file, true));
}

$codebase_path_arr = array();
if (is_array($sites->$site)) {
    foreach($sites->$site as $codebase) {
        $codebase_path_arr[] = getCodeBase($codebases_path, $codebase);
    }
} elseif (is_array($codebases)) {
    foreach($codebases as $codebase) {
        $codebase_path_arr[] = getCodeBase($codebases_path, $codebase);
    }
} else {
    $codebase_path_arr = array( $master_skyphp );
}

include  end($codebase_path_arr) . 'sky.php';
