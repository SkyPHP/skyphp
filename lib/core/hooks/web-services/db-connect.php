<?php

if ( !$db_host ) $db_host = $db_domain; // for backwards compatibility

if( $db_hosts ) { $db_host = $db_hosts; }

if ( $db_name && $db_host ) {
   $db_hosts = is_array($db_host)?$db_host:explode(',', $db_host);

   shuffle($db_hosts);

   $db_error = '';

   while($db_host = rtrim(trim(array_shift($db_hosts)))){
      # connect to read-only db

      $db = &ADONewConnection( $db_platform );
      @$db->Connect( $db_host, $db_username, $db_password, $db_name );

      if($db->ErrorMsg()) {
          $db_error .= "db error ($db_host): {$db->ErrorMsg()} \n";
      } else {
         if($dbw){
            break;
         }

         if(mem('dbw_down_' . trim(rtrim(`hostname`)))){
            $dbw = NULL;
            break;
         }

         # determine master db -- set $dbw_host
         if($db_replication) include_once("lib/core/db-replication/{$db_replication}/{$db_replication}.php");

         if(!$dbw_host){ // we are not using replication
            $dbw = &$db;
            $dbw_host = $db_host;
         }else { // we are using replication, connect to the the master db
            if($dbw_host == $db_host){
               $dbw = $db;
               $db = NULL;
            }else{
               $dbw = &ADONewConnection( $db_platform );
               @$dbw->Connect( $dbw_host, $db_username, $db_password, $db_name );
               // if we can't connect to master, then aql insert/update will
               // gracefully fail and validation will display error message
               if($dbw->ErrorMsg()) {
                  $master_db_connect_error = "<!-- \$dbw error ($dbw_domain): " . $dbw->ErrorMsg() . " -->";
                  $dbw = NULL;

                  mem('dbw_down_' . trim(rtrim(`hostname`)), 'true', '1 minute');
               }
            }
         }
         if($db){
            #we have our choice of $db now, so we break
            break;
         }
      }
   }

   if($dbw && !$db){
      $db = &$dbw;
      $db_host = $dbw_host;
   }

   #if there is no $db_host, that means all our choices have failed
   if(!$db_host){
      include( 'pages/503.php' );
      die( "<!-- $db_error -->" );
   }
}
