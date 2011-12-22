<?

if($_GET['debug']){echo "Enterring memcache hook...\n";}

if(class_exists('Memcache')){
   if(is_array($memcache_settings) && is_array($memcache_settings['servers']) && count($memcache_settings['servers'])){

      if($_GET['debug']){echo 'using $memcache_settings...' . "\n";}

      #set up baseline default settings which can be overridden with $memcache_default_settings
      $_memcache_default_settings = array(
         'allow_failover' => 1,
 #        'redundancy' => 5,
         'hash_strategy' => 'consistent',
         'servers' => array(
            'port' => 11211,
            'persistent' => true,
            'weight' => 1,
            'timeout' => 1,
            'retry_interval' => 15,
            'status' => true,
            'callback_failure' => NULL,
            'timeoutms' => NULL
         )
      );

      $memcache_default_settings || ($memcache_default_settings = $_memcache_default_settings);

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

      if($_GET['debug']){echo '$memcache_settings : ' . var_export($memcache_settings, true);}

      #use $memecache_settings to set up Memcache
      array_key_exists('allow_failover', $memcache_settings) && ini_set('memcache.allow_failover', $memcache_settings['allow_failover']);
      array_key_exists('redundancy', $memcache_settings) && array(ini_set('memcache.redundancy', $memcache_settings['redundancy']), ini_set('memcache.session_redundancy', $memcache_settings['redundancy']));
      array_key_exists('hash_strategy', $memcache_settings) && ini_set('memcache.hash_strategy', $memcache_settings['hash_strategy']);

      $memcache = new Memcache;

      $memcache_save_path = '';

      #addServer foreach $memcache_settings['servers']
      foreach($memcache_settings['servers'] as $server){
         #bool Memcache::addServer ( string $host [, int $port = 11211 [, bool $persistent [, int $weight [, int $timeout [, int $retry_interval [, bool $status [, callback $failure_callback [, int $timeoutms ]]]]]]]] ) ### from php.net

         if($_GET['debug']){echo '$memcache->addServer : ' . var_export($server, true) . "\n";}

         $addServer_status = $memcache->addServer($memcache_host = $server['host'], $memcache_port = $server['port'], $server['persistent'], $server['weight'], $server['timeout'], $server['retry_interval'], $server['status'], $server['callback_failure'] /*, $server['timeoutms']*/);
          
         if($_GET['debug']){
            if($addServer_status){
               echo "\nsuccessfully added $memcache_host\n";
            }else{
               echo "\nfailed to add $memcache_host\n";
            }
         }

         if($server['status']){
            $memcache_save_path .= ($memcache_save_path?',':'') . "tcp://$memcache_host:$memcache_port";
         }

         unset($memcache_host, $memcache_port);
      }
      unset($server);

      if(!@$memcache->getVersion()){
         if($_GET['debug']){echo '$memcache->getVersion returned false, will not use memcache' . "\n";}

         $memcache = NULL;
      }      
   }else{
       if($_GET['debug']){echo 'using old $memcache_* configs...';}

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
               if($_GET['debug']){echo '$memcache->addServer : ' . "$memcache_host, $memcache_port\n";}

              $memcache->addServer($memcache_host, $memcache_port);
              if ($memcache_save_path) $memcache_save_path .= ',';
                  $memcache_save_path .= 'tcp://' . $memcache_host . ':' . $memcache_port;
          }
          if ( @$memcache->getVersion() == false ){
             if($_GET['debug']){echo '$memcache->getVersion returned false, will not use memcache' . "\n";}

             $memcache = null;
          }
      }
   }
}else{
   if($_GET['debug']){echo "Class 'Memcache' does not exist, can not use memcache\n";}
}

if($_GET['debug']){echo "Exitting memcache hook.\n";}
