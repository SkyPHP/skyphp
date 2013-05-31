<?php

/**
 * This is a sample index file for a dev site.
 */


# $down_for_maintenance = true;

// config
$dev_domain = 'gadgetlabs.us';
$codebases_path = '/home/gadgetus/codebases/';
$skyphp_storage_path = '/home/gadgetus/storage/';
$master_skyphp = $codebases_path . 'SkyPHP/skyphp/3.0-beta/';

include $master_skyphp . 'lib/Sky/dev/get-codebase.inc.php';

$site_url = strtolower($_SERVER['HTTP_HOST']);
$site = str_replace('.' . $dev_domain, '', $site_url);

if ($site != $dev_domain) {
    $subdomain_index = $site . '.php';
    if (file_exists($subdomain_index)) {
        include $subdomain_index;
    } else {
        die("'$site' is not a valid dev site.");
    }
}

$codebase_path_arr = array();
if (is_array($codebases)) {
    foreach($codebases as $codebase) {
        $codebase_path_arr[] = getCodeBase($codebases_path, $codebase);
    }
} else {
    $codebase_path_arr = array( $master_skyphp );
}

include  end($codebase_path_arr) . 'sky.php';
