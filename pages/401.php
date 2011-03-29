<?php
header("HTTP/1.0 401 Access Denied");
?>
<h1>401 Error: Access Denied</h1>
<div>
	You are logged in with a username that does not have access to this page.
	<br />
	<a href="?logout=1">Click here to logout</a> and try logging in again.
</div>
<?
$message .= var_export($_SERVER,true);
$message .= var_export($_SESSION,true);
$message .= var_export($_POST,true);
//mail('will@joonbug.com','401',$message);
echo "<!--
$message
-->";
?>