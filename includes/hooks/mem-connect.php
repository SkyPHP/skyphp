<?php

if($_GET['debug']){echo "\nEnterring memcache hook...\n";}

#this hook supports memcache version 2 & 3 as well as an older version of the skyphp memcache config format
#if version 3 is installed, MemcachePool will be used, otherwise Memcache
#if $memcache_settings is a proper skyphp memcache config, it will be used, otherwise the old format of $memcache_* variables will be used instead

$using_MemcachePool = NULL;

if(($using_MemcachePool = class_exists('MemcachePool')) || class_exists('Memcache')){
   if(is_array($memcache_settings) && is_array($memcache_settings['servers']) && count($memcache_settings['servers'])){

      if($_GET['debug']){echo 'using $memcache_settings...' . "\n";}

      #set up baseline default settings which can be overridden with $memcache_default_settings
      $_memcache_default_settings = array(
         'allow_failover' => 1,
 #        'redundancy' => 1,
         'hash_strategy' => 'consistent',
         'servers' => array(
            'port' => 11211, #this is synonimous with tcp_port
            'udp_port' => 0, #this is only used by memcachePool
            'persistent' => true,
            'weight' => 1,
            'timeout' => 1,
            'retry_interval' => 15,
            'status' => true,
            'callback_failure' => NULL,
            'timeoutms' => NULL
         )
      );

      if(!is_array($memcache_default_setings)){
         $memcache_default_settings = $_memcache_default_settings;
      }

      #merge $memcache_default_settings and $_memcache_default_settings to $memcache_default_settings
      foreach($_memcache_default_settings as $key => $val){
         array_key_exists($key, $memcache_default_settings) || ($memcache_default_settings[$key] = $val);

         if($key == 'servers'){
            foreach($memcache_default_settings['servers'] as $server_key => $server_val){
               array_key_exists($server_key, $memcache_default_settings['servers']) || ($memcache_default_settings['servers'][$server_key] = $server_val);
            }
            unset($server_key, $server_val);
         }
      }
      unset($key, $val);
      unset($_memcache_default_settings);

      #search for synonimous configs
      foreach($memcache_settings['servers'] as &$server){
         if(array_key_exists('tcp_port', $server)){
            $server['port'] = $server['tcp_port'];
            unset($server['tcp_port']);
         }
      }
      unset($server);

      #merge $memcache_settings and $memcache_default_settings to $memcache_settings
      foreach($memcache_default_settings as $key => $val){
         array_key_exists($key, $memcache_settings) || ($memcache_settings[$key] = $val);

         if($key == 'servers'){
            foreach($memcache_settings['servers'] as &$server){
               foreach($memcache_default_settings['servers'] as $server_key => $server_val){
                  array_key_exists($server_key, $server) || ($server[$server_key] = $server_val);
               }
               unset($server_key, $server_val);
            }
            unset($server);
         }
      }
      unset($key, $val);

      if($_GET['debug']){echo "\n", '$memcache_settings : ' , var_export($memcache_settings, true), "\n";}

      #use $memecache_settings to set up Memcache
      array_key_exists('allow_failover', $memcache_settings) && ini_set('memcache.allow_failover', $memcache_settings['allow_failover']);
      array_key_exists('redundancy', $memcache_settings) && array(ini_set('memcache.redundancy', $memcache_settings['redundancy']), ini_set('memcache.session_redundancy', $memcache_settings['redundancy']));
      array_key_exists('hash_strategy', $memcache_settings) && ini_set('memcache.hash_strategy', $memcache_settings['hash_strategy']);

      $memcache = NULL;
      $using_memcachePool = false;

      if($using_MemcachePool){
         if($_GET['debug']){echo "\nUsing MemacachePool\n";}

         $memcache = new MemcachePool;
      }else{
         if($_GET['debug']){echo "\nUsing Memcache\n";}

         $memcache = new Memcache;
      }

      $memcache_save_path = '';

      #addServer foreach $memcache_settings['servers']
      foreach($memcache_settings['servers'] as $server){
         #bool Memcache::addServer ( string $host [, int $port = 11211 [, bool $persistent [, int $weight [, int $timeout [, int $retry_interval [, bool $status [, callback $failure_callback [, int $timeoutms ]]]]]]]] ) ### from php.net

         if($_GET['debug']){echo "\n", '$memcache->addServer : ' , var_export($server, true) , "\n";}

         $addServer_status = NULL;

         $memcache_host = $server['host'];
         $memcache_port = $server['port'];

         if($using_MemcachePool){
            $addServer_status = $memcache->addServer($server['host'], $server['port'], $server['udp_port'], $server['persistent'], $server['timeout'], $server['retry_interval']);
            if(array_key_exists('status', $server) && !$server['status']){
               $server['retry_interval'] = -1; #it does not make sense to have any other value for retry_interval if status is false

               if(!$memcache->setServerParams($server['host'], $server['port'], $server['timeout'], $server['retry_interval'], $server['status']) && $_GET['debug']){
                  echo "\nFailed to set $memcache_host status to offline\n";
               }else{
                  if($_GET['debug']){
                     echo "\nSet $memcache_host status to offline\n";
                  }
               }
            }
         }else{
            $addServer_status = $memcache->addServer($server['host'], $server['port'], $server['persistent'], $server['weight'], $server['timeout'], $server['retry_interval'], $server['status'], $server['callback_failure'] /*, $server['timeoutms']*/);
         }

         if($_GET['debug']){
            if($addServer_status){
               echo "\nSuccessfully added $memcache_host\n";
            }else{
               echo "\nFailed to add $memcache_host\n";
            }
         }

         if($server['status']){
            $memcache_save_path .= ($memcache_save_path?',':'') . "tcp://$memcache_host:$memcache_port";
         }

         unset($memcache_host, $memcache_port, $addServer_status);
      }
      unset($server);
   }else{
       if($_GET['debug']){echo "\n", 'Using old $memcache_* configs...', "\n";}

      // connect to memcached using old config variables
      if (is_array($memcache_servers) && count($memcache_servers) ) {
          if($memcache_redundancy ) {
              ini_set('memcache.allow_failover',1);
              ini_set('memcache.redundancy',$memcache_redundancy);
              ini_set('memcache.session_redundancy',$memcache_redundancy);
          }
          ini_set('memcache.hash_strategy','consistent');
          if (!$memcache_port) $memcache_port = 11211;
          $memcache = new Memcache;
          foreach ($memcache_servers as $memcache_host) {
               if($_GET['debug']){echo "\n", '$memcache->addServer : ' , "$memcache_host, $memcache_port\n";}

              $memcache->addServer($memcache_host, $memcache_port);
              if ($memcache_save_path) $memcache_save_path .= ',';
                  $memcache_save_path .= 'tcp://' . $memcache_host . ':' . $memcache_port;
          }
      }
   }

   if($memcache && !$using_MemcachePool){
      if(!@$memcache->getVersion()){
         if($_GET['debug']){echo "\n", '$memcache->getVersion returned false, will not use memcache' , "\n";}

         $memcache = NULL;
      }
   }
}else{
   if($_GET['debug']){echo "\nClass 'Memcache' does not exist, can not use memcache\n";}
}

if($_GET['debug']){echo "\nExitting memcache hook.\n";}
