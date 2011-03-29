<?php
$seconds = 60 * 60; // 1 hour
header("HTTP/1.1 503 Service Temporarily Unavailable");
header("Status: 503 Service Temporarily Unavailable");
header("Retry-After: $seconds");
?>

<div style="text-align:center;">
	<img src="/images/503.gif" />
</div>