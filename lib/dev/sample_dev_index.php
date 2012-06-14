<?php

# $down_for_maintenance = true;
$dev_domain = 'example.com';
$codebases_path = '/path/to/codebases/';
$sites_json_file = 'sites.json';


$site_url = strtolower($_SERVER['HTTP_HOST']);
$site = str_replace('.' . $dev_domain, '', $site_url);
$master_skyphp = $codebases_path . 'SkyPHP/skyphp/master/';
$sites = json_decode(file_get_contents($sites_json_file, true));
include $master_skyphp . 'lib/dev/get-codebase.inc.php';
$codebase_path_arr = array();
if (is_array($sites->$site)) {
    foreach($sites->$site as $codebase) {
        $codebase_path_arr[] = getCodeBase($codebases_path, $codebase);
    }
} else {
    $codebase_path_arr = array( $master_skyphp );
}
$skyphp_storage_path = "/home/skydevus/storage";
include  end($codebase_path_arr) . 'sky.php';
