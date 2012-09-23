<?php
$seconds = 60 * 60; // 1 hour
header("HTTP/1.1 503 Service Temporarily Unavailable");
header("Status: 503 Service Temporarily Unavailable");
header("Retry-After: $seconds");
?>

<div style="text-align:center; font-size:24px;">
	Sorry, this website is currently down for scheduled maintenance.
    <br />
    Please check back in about an hour.
</div>
