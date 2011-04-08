<?

// TODO exit if there is more than 1 skyqs -- issue when a css has a relative file path

header("Content-Type: text/css");
$cache_name = IDE;
echo disk('stylesheet/'.$cache_name);
