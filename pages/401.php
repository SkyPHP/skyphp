<?php

header("HTTP/1.0 401 Access Denied");

$message = var_export($_SERVER, true)
         . var_export($_SESSION, true)
         . var_export($_POST, true);
?>

<h1>401 Error: Access Denied</h1>
<div>
	You are logged in with a username that does not have access to this page.
	<br />
	<a href="?logout=1">Click here to logout</a> and try logging in again.
</div>

<!--
<?=$message?>
-->
