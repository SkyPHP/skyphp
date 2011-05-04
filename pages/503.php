<?php
$seconds = 60 * 60; // 1 hour
header("HTTP/1.1 503 Service Temporarily Unavailable");
header("Status: 503 Service Temporarily Unavailable");
header("Retry-After: $seconds");
?>

<div style="text-align:center;">
	Sorry, this website is currently down for maintenance.
    <br />
    Please try again in a few minutes.
</div>