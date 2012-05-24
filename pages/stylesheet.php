<?php

// TODO exit if there is more than 1 skyqs -- issue when a css has a relative file path

header("Expires: " . gmdate("D, d M Y H:i:s",strtotime('+6 months')) . " GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s",strtotime('+30 days')) . " GMT");
header("Content-Type: text/css");
$cache_name = IDE;
echo disk('stylesheet/'.$cache_name);
