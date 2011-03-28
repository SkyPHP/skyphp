<?php

/*
	SkyPHP Website Setup Instructions:
	1. Put the "skyphp" folder in your website's public_html folder.
	2. Access this file in your web browser.
		Example: http://example.com/skyphp/index.php
    3. Follow the setup instructions.
*/

// get this version number
ob_start();
include('version.txt');
$txt = ob_get_contents();
ob_end_clean();
$txt_arr = explode("\n", $txt);
foreach ($txt_arr as $line) {
    $tmp = explode('=',$line);
    if ($tmp[0]=='version') {
        $version = $tmp[1];
        break;
    }
}

//print_r($_SERVER);

// get the path
$web_path = substr( $_SERVER['SCRIPT_FILENAME'], 0, strpos( $_SERVER['SCRIPT_FILENAME'], $_SERVER['SCRIPT_NAME']) );
$web_parent_path = substr($web_path, 0, strrpos($web_path,'/'));
?>

<style>
pre {
    border: dotted 1px #666;
    background: #f6f6f6;
    padding: 10px;
}
</style>

<h1>SkyPHP <?=$version?> Website Setup</h1>

<ol>
    <li>
        Check System Requirements:
        <ul>
            <li>php.ini
<pre>
post_max_size = 150M
register_globals = Off
magic_quotes_gpc = Off
upload_max_filesize = 150M
upload_tmp_dir = /tmp
session.gc_maxlifetime = 7500
</pre>
            </li>
            <li>imagemagick</li>
            <li>apache conf</li>
            <li>postgres</li>
        </ul>
        <br />
    </li>
    <li>
        Codebase Setup:
<pre>
ln -s <?=$web_path?>/skyphp/.htaccess <?=$web_path?>/.htaccess
vi <?=$web_path?>/index.php
</pre>
<?
$skyphp_codebase_path = $web_path . substr($_SERVER['SCRIPT_NAME'],0,strrpos($_SERVER['SCRIPT_NAME'],'index.php'));
$skyphp_storage_path = $web_parent_path . '/sky-data/';

$index_php = <<<SKY
<?php
# /index.php
# Powered by SkyPHP (skyphp.org)

\$down_for_maintenance = false;
\$skyphp_codebase_path = '$skyphp_codebase_path';
\$skyphp_storage_path = '$skyphp_storage_path';

# Database Connection Settings
\$db_domain = '';
\$db_platform = 'postgres8';
\$db_name = '';
\$db_username = '';
\$db_password = '';

\$codebase_path_arr = array(
    \$skyphp_codebase_path
);
include( \$skyphp_codebase_path . 'sky.php' );
?>
SKY;

?>
<blockquote>
press "i" to enter insert mode, paste the following into the file:
<pre>
<?=htmlentities($index_php)?>
</pre>
press "ESC" to exit insert mode, then press ":wq!" to save and exit.
</blockquote>
    </li>
    <li>
        Storage Folder Setup:
        <ul>
            <li>Prompt for $skyphp_storage_path</li>
        </ul>
<pre>
mkdir <?=$skyphp_storage_path."\n"?>
chown nobody:nobody <?=$skyphp_storage_path."\n"?>
chmod 644 <?=$skyphp_storage_path?>
</pre>
    </li>
    <li>
        Database Setup:
        <ul>
            <li>Prompt for db host/name/username/password</li>
            <li>Create schema</li>
            <li>Save db info to index.php</li>
            <li>Prompt for admin &amp; dev: fname, lname, email, username/password</li>
            <li>Insert admin and dev users</li>
        </ul>
    </li>
    <li>
        Installation Successful!
        Click here to login.
    </li>
</ol>