<?

// connect to memcached
if ( class_exists('Memcache') && count($memcache_servers) ) {
    if ( $memcache_redundancy ) {
        ini_set('memcache.allow_failover',1);
        ini_set('memcache.redundancy',$memcache_redundancy);
        ini_set('memcache.session_redundancy',$memcache_redundancy);
    }
    ini_set('memcache.hash_strategy','consistent');
    if (!$memcache_port) $memcache_port = 11211;
    $memcache = new Memcache;
    foreach ($memcache_servers as $memcache_host) {
        $memcache->addServer($memcache_host, $memcache_port);
        if ($memcache_save_path) $memcache_save_path .= ',';
        $memcache_save_path .= 'tcp://' . $memcache_host . ':' . $memcache_port;
    }
    if ( @$memcache->getVersion() == false ) $memcache = null;
}