<?php

/**
 * Memcache Hook
 *****************************************************************************************
 * This hook supports memcache version 2 & 3
 *    as well as an older version of the skyphp memcache config format.
 * If version 3 is installed, MemcachePool will be used, otherwise Memcache
 * if $memcache_settings is a proper skyphp memcache config, it will be used,
 * otherwise the old format of $memcache_* variables will be used instead
 */

\Sky\Memcache\Connection::debug('Entering memcache hook...');

if (!$memcache_settings) {

   if (!$memcache_servers) {
      \Sky\Memcache\Connection::debug('No settings defined. Exiting hook.');
      return;
   }

   $memcache_settings = \Sky\Memcache\Connection::reformatBCSettings(
      $memcache_servers,
      $memcache_port,
      $memcache_redundancy
   );

}

\Sky\Memcache\Connection::connect($memcache_settings);

// Used for other hooks:
$memcache = \Sky\Memcache\Connection::getInstance();
$memcache_save_path = \Sky\Memcache\Connection::getSavePath();

\Sky\Memcache\Connection::debug('Exiting Memcache hook.');
