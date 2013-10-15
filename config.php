<?php

// default config settings

$sky_php_version = '3.0';

$jquery_version = '1.9.1';

$cookie_timeout = 60 * 60 * 18; // 18 hours
$default_page = 'pages/default/default.php';
$global_template = 'templates/html5/html5.php';
$page_404 = 'pages/404.php';

// if using a load balancer with ssl termination, it may use a custom ssl indicator header
#$server_ssl_header = 'HTTP_SSLCLIENTCIPHER';

// where to store session data?  if memcache or db is not setup,
// it will fallback gracefully to default session handler (/tmp files)
$session_storage = 'memcache';
# $session_storage = 'db';

// include files
$includes[] = 'lib/kint/Kint.class.php'; // pretty debug output
$includes[] = 'lib/lessphp/lessc.inc.php'; // less to css auto-compile support

// needed by php 5.3
$date_default_timezone = 'America/New_York';

// access denied output
$access_denied_output_file = 'includes/hooks/login-access-denied-output.php';

// encrypt key - for use with the encrypt/decrypt functions
$sky_encryption_key = '0123456789abcdef';

