<?php

/*
 default config settings
*/

$jquery_version = '1.7.0';

$cookie_timeout = 60 * 60 * 18; // 18 hours
$default_page = 'pages/default/default.php';
$global_template = 'templates/html5/html5.php';
$page_404 = 'pages/404.php';

// db replication
# $db_replication = 'repmgr';

// where to store session data?  if memcache or db is not setup,
// it will fallback gracefully to default session handler (/tmp files)
$session_storage = 'memcache';
# $session_storage = 'db';

// aql model path
$sky_aql_model_path = 'models/';

// include files
$includes[] = 'lib/core/functions.inc.php';
$includes[] = 'lib/core/class.aql2array.php';
$includes[] = 'lib/core/class.aql.php';
$includes[] = 'lib/core/class.ModelArrayObject.php';
$includes[] = 'lib/core/class.Model.php';
$includes[] = 'lib/core/class.Page.php';
$includes[] = 'lib/core/class.PageRouter.php';
$includes[] = 'lib/adodb/adodb.inc.php';

// needed by php 5.3
$date_default_timezone = 'America/New_York';

// access denied output
$access_denied_output_file = 'lib/core/hooks/login/access-denied-output.php';

// encrypt key - for use with the encrypt/decrypt functions
$sky_encryption_key = '0123456789abcdef';

// if the first folder in your REQUEST_URI is a key in the $quick_serve array,
// the corresponding script is executed without the overhead of sky.php
$quick_serve['index.php'] = 'lib/core/quick-serve/index.php';

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
    'bmp' => 'image/bmp',
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