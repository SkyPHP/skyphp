<?php

header("Expires: " . gmdate("D, d M Y H:i:s",strtotime('+6 months')) . " GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s",strtotime('+30 days')) . " GMT");
header("Content-Type: text/javascript");
$cache_name = IDE;
echo disk('javascript/'.$cache_name);
