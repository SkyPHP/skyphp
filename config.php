<?php

/*
 default config settings
*/

$jquery_version = '1.5.1';

$cookie_timeout = 60 * 60 * 18; // 18 hours
$default_page = 'pages/default/default.php';
#$global_template = 'templates/global/global.php';
$page_404 = 'pages/404.php';

// media class
# $default_image_quality = 90;

// encrypt key - for use with the encrypt/decrypt functions
// do not change this if you have IDEs in your URLs (SEO issue)
$sky_encryption_key = 'ab';

// content types allowed
$sky_content_type = array(
	'doc' => 'application/msword',
    'txt' => 'text/plain',
    'htm' => 'text/html',
    'html' => 'text/html',
    'css' => 'text/css',
    'xml' => 'text/xml',
    'xsl' => 'text/xml',
    'js' => 'text/javascript',
    'gif' => 'image/gif',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'ico' => 'image/vnd.microsoft.icon',
    'swf' => 'application/x-Shockwave-Flash',
    'flv' => 'video/x-flv',
    'mp3' => 'audio/mpeg',
    'zip' => 'application/zip',
    'class' => 'application/java-vm',
    'jar' => 'application/java-archive',
    'sql' => 'text/plain',
    'ttf' => 'font/ttf',
    'svg' => 'image/svg+xml',
    'eot' => 'application/vnd.ms-fontobject',
    'woff' => 'application/x-font-woff',
    'wsdl' => 'text/xml'
);