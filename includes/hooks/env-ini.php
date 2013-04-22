<?php

# canonical redirect
# 301 redirect if canonicalization has been configured and applicable
# example: www.example.com/page --> example.com/page
$sky_canonical_host = $sky_canonical_redirect[$_SERVER['HTTP_HOST']];
if ($sky_canonical_host) {
	header('HTTP/1.1 301 Moved Permanently');
	header ('Location: http://' . $sky_canonical_host . $_SERVER['REQUEST_URI']);
	exit;
}

# example: www.example.com/page --> example.com
$sky_canonical_host = $sky_canonical_redirect_no_append[$_SERVER['HTTP_HOST']];
if ($sky_canonical_host) {
	header('HTTP/1.1 301 Moved Permanently');
	header('Location: http://' . $sky_canonical_host);
	exit;
}

# register globals is off 
# so don't throw the PHP 5.2.3 warning if variables have same name as SESSION keys
ini_set('session.bug_compat_warn', 0);
ini_set('session.bug_compat_42', 0);

# PHP 5.3 Throws Error if this line is not here
date_default_timezone_set($date_default_timezone);

# if magic quotes are not disabled, this workaround will remove the magic quotes
# it is much better to use server directives; 
# http://us2.php.net/manual/en/security.magicquotes.disabling.php
if (get_magic_quotes_gpc()) {
    $stripslashes_deep = function($value) use($stripslashes_deep) {
        return is_array($value) 
        	? array_map($stripslashes_deep, $value) 
        	: stripslashes($value);
    };
    $_POST = array_map($stripslashes_deep, $_POST);
    $_GET = array_map($stripslashes_deep, $_GET);
    $_COOKIE = array_map($stripslashes_deep, $_COOKIE);
    $_REQUEST = array_map($stripslashes_deep, $_REQUEST);
}
