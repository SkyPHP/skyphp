<?php

# start session if enabled

if ($no_cookies) return;

$cookie_domain = $_SERVER['HTTP_HOST'];
if ($enable_subdomain_cookies || $multi_session_domain) {
    # set the cookie domain so that the sessions are shared between subdomains
    # TODO: verify that this works; mixed results
    $cookie_domain = (substr_count($_SERVER['HTTP_HOST'], '.') == 1)
        ? '.' . $_SERVER['HTTP_HOST']
        : preg_replace('/^([^.])*/i', null, $_SERVER['HTTP_HOST']);
}

#set the PHP session id (PHPSESSID) cookie to a custom value
session_set_cookie_params($cookie_timeout, '/', $cookie_domain);

# timeout value for the garbage collector
ini_set('session.gc_maxlifetime', $cookie_timeout);

# set session handler
if ($memcache && $session_storage == 'memcache') {
    ini_set('session.save_handler', 'memcache');
    ini_set('session.save_path', $memcache_save_path);
    if ($_GET['debug']) {
        echo 'session: ' . $memcache_save_path . '<br />';
    }
} else if ($db_name && $db_domain && $session_storage == 'db') {
    include_once 'lib/adodb/session/adodb-session2.php';
    ADOdb_Session::config($db_platform, $dbw_domain, $db_username, $db_password, $db_name, $options = false);
}

# start the session
session_cache_limiter('none');
session_start();

# check if this is a multi domain session
if (!$multi_session_domain) return;

# get the base domain name and subdomain name
foreach ($multi_session_domain as $domain) {
    $start = strpos($_SERVER['HTTP_HOST'], $domain);
    if ($start === false) continue;
    if ($start == 0) {
        $p->subdomain = '';
        $p->base_domain = $_SERVER['HTTP_HOST'];
    } else {
        $p->subdomain = substr($_SERVER['HTTP_HOST'], 0, $start - 1);
        $p->base_domain = substr($_SERVER['HTTP_HOST'], $start);
    }
    break;
}

# load current session values if base_domain found
if (!$p->base_domain) return;

$subdomain = $p->subdomain;
if (is_array($_SESSION['multi-session'])) {
    $first_key = array_shift(array_keys($_SESSION['multi-session']));
}

# if no session on this subdomain, but there is another session,
# copy the existing session to the new subdomain session
if (!is_array($_SESSION['multi-session'][$subdomain])
    && is_array($_SESSION['multi-session'][$first_key])) {
    $_SESSION['multi-session'][$subdomain] = $_SESSION['multi-session'][$first_key];
}
if (is_array($_SESSION['multi-session'][$subdomain])) {
    foreach ($_SESSION['multi-session'][$subdomain] as $var => $val) {
        $_SESSION[$var] = $val;
    }
}

# make sure the current session values are saved to multi-session array
# after connection closes
register_shutdown_function(function() use($p) {
    $session = array('multi-session' => $_SESSION['multi-session']);
    $tmp = $_SESSION;
    unset($tmp['multi-session']);
    $session['multi-session'][$p->subdomain] = $tmp;
    // mail('stan@joonbug.com', 'test', var_export($tmp, true));
    $_SESSION = $session;
});
