<?

if ($access_groups) {
    if (auth($access_groups)) $access_denied = false;
    else {
        $access_denied = true;
        // access denied hook
        @include('lib/core/hooks/login/access-denied.php');
    }
}