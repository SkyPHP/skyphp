<?php

$qf = implode('/', $this->queryfolders);
$dot = strrpos($qf, '.');
$asset = substr($qf, 0, $dot);
$cacheKey = 'cached-assets/' . $asset;
$ext = substr($qf, $dot + 1);
$mime_type = getMimeTypes()[$ext];

#d($cacheKey, $ext, $mime_type);

$payload = disk($cacheKey);

if (!$payload) {
	include 'pages/404.php';
	exit;
}

header("Expires: " . gmdate("D, d M Y H:i:s",strtotime('+6 months')) . " GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s",strtotime('+30 days')) . " GMT");
header("Content-Type: $mime_type");

echo $payload;
