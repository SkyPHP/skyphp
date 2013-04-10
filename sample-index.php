<?php
// index.php
// Powered by SkyPHP (github.com/SkyPHP)

#$down_for_maintenance = true;

$skyphp_codebase_path = '/absolute/path/to/skyphp/';

$codebase_path_arr = array(
    '/absolute/path/to/mycodebase/',
    $skyphp_codebase_path
);

// make sure this folder is writable
$skyphp_storage_path = "/absolute/path/to/storage/";

include $skyphp_codebase_path . 'sky.php';
