<?

// connect to memcached
if ( class_exists('Memcache') && count($memcache_servers) ) {
    if (!$memcache_port) $memcache_port = 11211;
    $memcache = new Memcache;
    foreach ($memcache_servers as $memcache_host) {
        $memcache->addServer($memcache_host, $memcache_port);
        if ($memcache_save_path) $memcache_save_path .= ',';
        $memcache_save_path .= $memcache_host . ':' . $memcache_port;
    }
    if ( @$memcache->getVersion() == false ) $memcache = null;
}