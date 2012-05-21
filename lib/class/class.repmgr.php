<?
class repmgr{
    private $primary_nodes = NULL;
    private $standby_nodes = NULL;
    private $unused_nodes = NULL;
    private $current_node = NULL;

    private $config_path = NULL;
    private $log_path = NULL;
    private $data_path = NULL;
    private $scripts_dir = NULL;
    private $postgres_home = NULL;

    private $PATH = NULL;
    private $PGDATA = NULL;

    public $initialized = NULL;

    #returns NULL on failure, $this->initialized = true on success
    public function __construct($read_db = NULL, $generate_nodes = false){
       elapsed("repmgr __construct start");
 
       global $repmgr_cluster_name, $skyphp_codebase_path;
       if(!$repmgr_cluster_name){
          return(NULL);
       }

       if(!$read_db){
          global $db_host;
          if(!($read_db = $db_host)){
             return(NULL);
          }
       }

       elapsed("start find master function");

       #generate_nodes is more thorough and initializes some extra data we need for our repmgr web interface
       #however it is slower, and the extra data is not needed by anything else
       #so we only call generate_nodes when we need it.
       if(!($generate_nodes?$this->generate_nodes($read_db):$this->fast_master_find_db($read_db))){
          return(NULL);
       }

       elapsed("end find master function");

       $this->config_path = '/var/lib/pgsql/repmgr/repmgr.conf';
       $this->log_path = '/var/lib/pgsql/repmgr/repmgr.log';
       $this->data_path = '/var/lib/pgsql/9.0/data';
       $this->scripts_directory = (($last_char = array_pop(str_split($skyphp_codebase_path))) == '/'?$skyphp_codebase_path:$skyphp_codebase_path . $last_char) . 'lib/core/db-replication/repmgr';
       $this->postgres_home = '/var/lib/pgsql';

       $this->PATH = '/usr/pgsql-9.0/bin:/usr/kerberos/bin:/usr/local/bin:/bin:/usr/bin';
       $this->PGDATA = &$this->data_path;

       elapsed("repmgr __construct end");

       return($this->initialized = true);
    }

    #we assign by refference and use the GLOBALS array for speed purposes
    #same reason we avoid regexp and unnecesary variable assignments
    private function fast_master_find_db($read_db){
       $repmgr_cluster_name = &$GLOBALS['repmgr_cluster_name'];

       if($mem = mem($mem_key = "repmgr:$repmgr_cluster_name master")){
          return($GLOBALS['dbw_host'] = $mem);
       }

       if(!$read_db){
          $read_db = $GLOBALS['db_host'];  #not by reference because we may meed to reassign
       }

       if($read_db == 'localhost'){
          $read_db = trim(rtrim(`hostname`));
       }

       $repmgr_cluster_name = &$GLOBALS['repmgr_cluster_name'];
       $db = &$GLOBALS['db'];

       if($rs = $db->Execute("select id, conninfo from repmgr_$repmgr_cluster_name.repl_nodes")){
          $nodes = array();

          $read_id = NULL;

          while(!$rs->EOF){ 
             $nodes[($fields = &$rs->fields)?$id = &$fields['id']:NULL] = ($conninfo = &$fields['conninfo']); #we only want to call fields once, for speed

             if(!$read_id && strpos($conninfo, $read_db) !== false){
                $read_id = $id;
             }

             $rs->MoveNext();
          }

          if($rs = $db->Execute("select distinct primary_node from repmgr_$repmgr_cluster_name.repl_monitor where standby_node = $read_id")){
             $write_conninfo = $nodes[$rs->Fields('primary_node')];
             return(mem($mem_key, $GLOBALS['dbw_host'] = substr($write_conninfo, ($offset = strpos($write_conninfo, 'host=')) + 5, strpos($write_conninfo, ' ', $offset) - $offset - 5)));
          }
       }

       $GLOBALS['dbw_host'] = NULL;       
    }

    #this function will not work right now, we may not need to impliment it
    private function fast_master_find_recovery_conf($read_db = NULL){
       global $repmgr_cluster_name, $db;

       if(!$read_db){
          global $db_host;

          $read_db = $db_host;
       }

       $recovery_conf = file_get_contents('/var/lib/pgsql/9.0/data/recovery.conf');

       var_dump($recovery_conf);

       if(!$recovery_conf){
          return(NULL);
       }

       $recovery_conf = explode(' ', substr($recovery_conf = array_pop(explode("\n", $recovery_conf)), strpos($recovery_conf, "'")));

       var_dump($recovery_conf);

       while($str = array_shift($recovery_conf)){
          var_dump($str);

          if($str = explode('=', $str) && $str[0] == 'host'){
             global $dbw_host;
             $dbw_host = $str[1];
          }
       }
 
    }

    #used to initialize $this->standby_nodes and $this->primary_nodes amd $this->unused_nodes
    private function generate_nodes($read_db = NULL){
       global $repmgr_cluster_name, $db;

       elapsed("repmgr generate_nodes start");

       $conninfo_host_key = 'host=';

       $sql = "select id, cluster, conninfo, case when strpos(conninfo, '$conninfo_host_key') > 0 then substr(conninfo, strpos(conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(conninfo, strpos(conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) else NULL end as host from repmgr_$repmgr_cluster_name.repl_nodes where cluster = '$repmgr_cluster_name'";

       $unused_nodes = array();

       elapsed('repmgr generate_nodes query 1 start');
       if($rs = $db->Execute($sql)){
          elapsed('repmgr generate_nodes query 1 end');
          elapsed('repmgr generate_nodes query 1 start process data');
          while(!$rs->EOF){
             $unused_nodes[$id = $rs->Fields('id')] = array(
                'id' => $id,
                'type' => 'unused',
                'host' => $rs->Fields('host'),
                'conninfo' => $rs->Fields('conninfo')                
             );

             $rs->MoveNext();
          }       
    
          elapsed('repmgr generate_nodes query 1 end process data');

          unset($rs, $sql, $id);
       }

       if($read_db == 'localhost'){
          $read_db = trim(rtrim(`hostname`));
       }

       $sql = "select p_nodes.cluster, s_nodes.conninfo as standby_conninfo, p_nodes.conninfo as primary_conninfo, substr(s_nodes.conninfo, strpos(s_nodes.conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(s_nodes.conninfo, strpos(s_nodes.conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) as standby_host, substr(p_nodes.conninfo, strpos(p_nodes.conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(p_nodes.conninfo, strpos(p_nodes.conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) as primary_host, standby_node, primary_node, time_lag from repmgr_$repmgr_cluster_name.repl_nodes as s_nodes inner join repmgr_$repmgr_cluster_name.repl_status s_status on s_nodes.id = s_status.standby_node inner join repmgr_$repmgr_cluster_name.repl_nodes as p_nodes on s_status.primary_node = p_nodes.id where p_nodes.cluster = '$repmgr_cluster_name';";
#       $sql = "select p_nodes.cluster, s_nodes.conninfo as standby_conninfo, p_nodes.conninfo as primary_conninfo, substr(s_nodes.conninfo, strpos(s_nodes.conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(s_nodes.conninfo, strpos(s_nodes.conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) as standby_host, substr(p_nodes.conninfo, strpos(p_nodes.conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(p_nodes.conninfo, strpos(p_nodes.conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) as primary_host, standby_node, primary_node from repmgr_$repmgr_cluster_name.repl_nodes as s_nodes inner join (select distinct primary_node, standby_node from repmgr_$repmgr_cluster_name.repl_monitor) as s_status on s_nodes.id = s_status.standby_node inner join repmgr_$repmgr_cluster_name.repl_nodes as p_nodes on s_status.primary_node = p_nodes.id where p_nodes.cluster = '$repmgr_cluster_name';";

       elapsed('repmgr generate_nodes query 2 start');
       if($rs = $db->Execute($sql)){
          elapsed('repmgr generate_nodes query 2 end');
          elapsed('repmgr generate_nodes query 2 start process data');


          $primary_nodes = array();
          $standby_nodes = array();

          while(!$rs->EOF){
             if(!$primary_nodes[$primary_node = $rs->Fields('primary_node')]){
                $primary_nodes[$primary_node] = array(
                   'id' => $primary_node,
                   'type' => 'primary',
                   'host' => $rs->Fields('primary_host'),
                   'conninfo' => $rs->Fields('primary_conninfo')
                );
             }

             if(!$standby_nodes[$standby_node = $rs->Fields('standby_node')]){
                $standby_nodes[$standby_node] = array(
                   'id' => $standby_node,
                   'type' => 'standby',
                   'host' => $rs->Fields('standby_host'),
                   'conninfo' => $rs->Fields('standby_conninfo'),
                   'roles' => array(array(
                      'primary_node_id' => $primary_node,
                      'time_lag' => $rs->Fields('time_lag')
                   ))
                );
             }else{
                $standby_nodes[$standby_node]['roles'][] = array(
                   'primary_node_id' => $primary_node,
                   'time_lag' => $rs->Fields('time_lag')
                );
             }

             if($standby_nodes[$standby_node]['host'] == $read_db){
                $this->set_write_db($primary_nodes[$primary_node]['host']);
                $this->current_node = &$standby_nodes[$standby_node];
             }

             $rs->MoveNext();

             unset($unused_nodes[$primary_node], $unused_nodes[$standby_node], $primary_node, $standby_node);
         }

         elapsed('repmgr generate_nodes query 2 end process data');

         $this->primary_nodes = $primary_nodes;
         $this->standby_nodes = $standby_nodes;
         $this->unused_nodes = $unused_nodes;

         unset($rs, $sql, $primary_nodes, $standby_nodes, $conninfo_host_key, $unused_nodes);
      }else{
         return(NULL);
      }

      elapsed("repmgr generate_nodes end");

      return(true);
    }

    #we need certain environmental variables set in our ssh sessions, this function returns the boilerplate
    private function export(){
       return("export PATH={$this->PATH} ; export PGDATA={$this->PGDATA} ;");
    }

    #last line of output is ssh command exit status unless $no_exit_status is true
    #if $return_cmd is true, the command is never actually executed, only returned
    private function ssh($node = NULL, $cmd = NULL, $user = 'postgres', $no_exit_status = NULL, $return_cmd = NULL){
       $node = $this->get_node($node);
       $command = "ssh -T $user@{$node['host']} -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no <<\"EOF\"\n$cmd\nEOF\n".($no_exit_status?'':'echo $?');
       return(explode("\n", trim(rtrim($return_cmd?$command:`$command`))));
    }

    #get a list of running repmgr processes on remote machine
    public function remote_ps($node = NULL, $user = 'postgres'){
       $output = $this->ssh($node, 'ps -Awwo pid,user,args|grep repmgr', $user);

       $exit_status = array_pop($output);

       if($exit_status == '255' || $exit_status == '130'){
          return($exit_status);
       }

       $return = array();

       foreach($output as $line){
          if(preg_match('#^\s*\d+\s+\S+\s*bash \-c ps#', $line)){
             continue;
          }

          $matches = array();

          if(preg_match('#^\s*(\d+)\s+(\S+)\s+(.*)$#', $line, $matches)){
             $return[] = array(
                'pid' => $matches[1],
                'user' => $matches[2],
                'cmd' => $matches[3],
                'raw' => $matches[0]
             );

          }else{
             $return[] = array('raw' => $line);
          }
          
       }

       return($return);
    }

    #kill a daemon on a remote machine
    public function remote_kill($node = NULL, $pid = NULL, $user = 'root'){
       $procs = $this->remote_ps($node, $user);

       if(!is_numeric($pid)){
          #this would mean somebody gave bad input
          #which could be an attempt to execute arbitrary commands
          return(NULL);
       }

       $output = NULL;
       #we need to make sure the pid given actually corresponds to a repmgr process
       #we don't want to accidentally kill something important
       foreach($procs as $proc){
          if($proc['pid'] == $pid){
             if(preg_match('#^\s*repmgrd #', $proc['cmd'])){
                $output = $this->ssh($node, "kill -9 $pid 2>&1", $user);
             }

             break;
          }
       }

       return($output);
    }

    #start a daemon on a remote machine
    public function remote_start($node = NULL, $user = 'postgres'){
       #for some reason .bash_profile isn't run when we do ssh (nor ssh bash -c)
       return($this->ssh($node, $this->export() . "repmgrd -f {$this->config_path} --verbose >{$this->log_path} 2>&1 &", $user));
    } 

    #checks if replication appears to be occurring for a node
    public function check_replication($node = NULL){
       return(array_pop($this->ssh($node, "if [ -e \"{$this->postgres_home}/9.0/data/recovery.conf\" ]\nthen\necho '1'\nelse\necho '0'\nfi", 'postgres', true)));
    }
 
    #attempts to stop replication for a given node
    public function stop_replication($node = NULL){
       if($this->check_replication($node)){
          #stop the database
          $this->stop_db($node);

          #move the recovery.conf
          $this->ssh($node, "mv {$this->postgres_home}/9.0/data/recovery.conf {$this->postgres_home}/9.0/data/recovery.conf.backup." . date('Ymd'), 'postgres');

          #start the database
          $this->start_db($node);

          if($ret = $this->check_replication($node) == "0"){
             #if this was a success, we need to clean up repl_monitor tables
             $this->cleanup_repl_monitor($node, true);
          }
       }else{
          $ret = NULL;
       }

       return($ret);
    }

    private function stop_db($node = NULL){
       if(!$node){
          return(NULL);
       }

       $output = $this->ssh($node, '/etc/init.d/postgresql-9.0 stop', 'root', true);

       return(preg_match('#OK#', $output[0]));
    }

    private function start_db($node = NULL){
       if(!$node){
          return(NULL);
       }

       $output = $this->ssh($node, '/etc/init.d/postgresql-9.0 start', 'root', true);

       return(preg_match('#OK#', $output[0]));
    }

    #performs a 'hard add' to the cluster.  $node will be configured as a standby node after this
    public function add($node = NULL){
       $output = array();
 
       $current_primary = $this->get_current_primary_node();

       global $db_name;

       $output[] = $this->stop_db($node);
       $output[] = $this->ssh($node, "repmgr -D \$PGDATA -d $db_name -U repmgr -R postgres --verbose --force standby clone {$current_primary['host']} 2>&1");
       $output[] = $this->start_db($node);
       $output[] = $this->ssh($node, "repmgrd -f {$this->config_path} --verbose  >{$this->log_path} 2>&1 &", 'postgres');

       return($output);       
    }

    #performs a 'soft add' to the cluster.  Adds the node to the repl_nodes table but does nothing else
    #returns NULL on bad input, false on query failure, true on success
    #does not perform any conninfo sanity checking or node configuration validation
    #assumes node to be added is properly configured with repmgr
    public function add_soft($cluster = NULL, $conninfo = NULL, $id = NULL){
       if(is_array($cluster)){
          $params = &$cluster;

          $conninfo = $params['conninfo'];
          $id = $params['id'];
          $cluster = $params['cluster'];

          unset($params);
       }

       if(!is_numeric($id) || !($cluster && $conninfo && $id)){
          return(NULL);
       }

       global $repmgr_cluster_name, $db, $dbw;

       $rs = $db->Execute("select count(*) as count from repmgr_$repmgr_cluster_name.repl_nodes where id = $id");

       if($rs->Fields('count') > 0){
          return(NULL);
       }

       if($rs = $dbw->Execute("insert into repmgr_$repmgr_cluster_name.repl_nodes(cluster, conninfo, id) values('$cluster', '$conninfo', $id) returning *")){
          if(!$rs->EOF){
             return($rs->Fields('cluster') == $cluster);
          }

       }
       
       return(false);
    }
  
    #performs the opposite of add_soft, removes the node from the repl_nodes table but nothing more
    #potentially destructive
    #returns NULL on bad input, false on query failure, true on success
    public function drop_soft($node = NULL){
       if(!$node){
          return(NULL);
       }

       global $repmgr_cluster_name, $dbw;

       if($rs = $dbw->Execute("delete from repmgr_$repmgr_cluster_name.repl_nodes where id = $node and cluster = '$repmgr_cluster_name' returning *")){
          if(!$rs->EOF){
             return($rs->Fields('id') == $node);
          }
       }

       return(false);
    }

    #promotes a standby node to primary, drops old primary from cluster
    #POTENTIALLY VERY DESTRUCTIVE, BE SMART
    public function promote($node){
       if(!$node){
          return(NULL);
       }

       $output = array();

       foreach($this->get_nodes() as $host => $obj){
          $this->upload_scripts($obj['id']);
       }

       #stop db on master
       $current_primary = $this->get_current_primary_node();
       $output[] = $this->stop_db($old_primary_id = $current_primary['id']);

       #promote on new master
       $output[] = $this->ssh($node, "cd {$this->postgres_home}/scripts ; /usr/bin/perl watch.pl './promote.sh' 'STANDBY PROMOTE successful'", 'postgres');

       $needs_start = array();
       #follow on all new standbys
       foreach($this->standby_nodes as $standby_node){
          if($standby_node['id'] == $node || $standby_node['id'] == $old_primary_id){
             continue;
          }

          #repmgrd is supposed to be able to detect a new master, but i have rarely seen this
          #so we kill them all and start them again

          foreach($this->remote_ps($standby_node['id']) as $ps){
             $this->remote_kill($standby_node['id'], $ps['pid']);
          }
 
          $output[] = $this->ssh($standby_node['id'], "cd {$this->postgres_home}/scripts ; /usr/bin/perl watch.pl './follow.sh' 'server starting'", 'postgres');
          
          $needs_start[] = $standby_node['id'];
       }

       sleep(5);  
       
       #reinitialize $dbw
       global $dbw_host, $db_host;

       $this->set_write_db($this->get_node_host($node));

       $dbw = $this->get_db_connection($dbw_host);

       #cleanup repmgr tables
       $this->cleanup_repl_monitor($old_primary_id);

       foreach($needs_start as $id){
          $this->remote_start($id);
       }

       #update our object to contain correct information
       $this->generate_nodes($db_host);
      
       return($output);
    }

    #returns an ado db connection, like we are used to
    private function get_db_connection($host = NULL, $sleep_time = 5, $max_tries = 5){
       #$dbw is NOT global!
       global $db_username, $db_password, $db_name;

       $dbw = &ADONewConnection($db_platform);
       @$dbw->Connect($host, $db_username, $db_password, $db_name);

       $sleep_time = 5;
       $max_tries = 5;
       $try = 1;

       while($output[] = $dbw->ErrorMsg()){
          if($try++ > $max_tries){
             return(false);
          }

          sleep($sleep_time);

          $dbw = &ADONewConnection($db_platform);
          @$dbw->Connect($host, $db_username, $db_password, $db_name);
       }

       return($dbw);
    }

    #uploads a file to a remote node
    #checks m5sum of remote and local files and aborts upload if equivalent
    private function upload($node = NULL, $user = NULL, $file = NULL, $remote_path = NULL, $chmod = 755){
       if(!$node || !$user || !$file || !$remote_path){
          return(NULL);
       }
 
       $local_md5 = trim(rtrim(`md5sum $file`));

       $remote_md5 = trim(rtrim($this->ssh("md5sum $file", 'postgres')));

       if($local_md5 != $remote_md5){
          `scp $file $user@{$this->get_node_host($node)}:$remote_path`;
       }

       $remote_md5 = trim(rtrim($this->ssh("md5sum $file", 'postgres')));
 
       $this->ssh("chmod $chmod $remote_path", 'postgres');

       return($local_md5 == $remote_md5);
    }

    #potentially a dangerous function
    #if standby is set, standby_node is used in the delete query where clause instead of primary_node.  useful when dropping a node from replication
    #if $strong is set, cleanup is performed on all primaries we could find.  Not recomended
    public function cleanup_repl_monitor($node = NULL, $standby = NULL, $strong = NULL){
       global $repmgr_cluster_name, $dbw;

       #if this function is being called, it is likely that right now $dbw is either undefined or not connected to the correct primary node
       #because this is the case, and this function seeks to resolve the problem
       #we need to make sure that we target the correct primary node

       $returns = array();
       $primaries = array();

       foreach($this->get_nodes() as $_node){
          if(!$primaries[$primary = $this->get_primary_node_for_node($_node['id'], true)]){
             $primaries[$primary] = 1;
          }else{
             $primaries[$primary]++;
          }
        
          unset($primary);
       }

       $most_likely_primary = array('host' => NULL, 'count' => 0);

       global $repmgr_cluster_name;

       foreach($primaries as $host => $count){
          if(!$strong){
             if($host){
                if($most_likely_primary['count'] < $count){
                   $most_likely_primary = array('host' => $host, 'count' => $count);
                }else{
                   #if two nodes appear equally as likely to be the primary, we target both of them
                   if($most_likely_primary['count'] == $count){
                      $most_likely_primary['host'] .= ",$host";
                   }
                } 
             }
          }else{
             if($db = $this->get_db_connection($host)){
                $returns[] = $db->Execute("delete from repmgr_$repmgr_cluster_name.repl_monitor where ". ($standby?'standby_node':'primary_node'). " = $node");
             }
 
             unset($db);
          }
       }

       if(!$strong){
          foreach(explode(',', $most_likely_primary['host']) as $host){
             if($db = $this->get_db_connection($host)){
                $returns[] = $db->Execute("delete from repmgr_$repmgr_cluster_name.repl_monitor where ". ($standby?'standby_node':'primary_node'). " = $node");
             }
          }
       }

       return($returns);
    }

    #sets $dbw_host, called from generate_nodes
    private function set_write_db($write_db){
       global $dbw_host;
       return($dbw_host = $write_db);
    }

    public function get_primary_nodes(){
       return($this->primary_nodes);
    }

    public function get_current_primary_node(){
       global $dbw_host;
       
       foreach($this->primary_nodes as $node){
          if($node['host'] == $dbw_host){
             return($node);
          }
       }

       return(NULL);
    }

    #determines a node's primary node by parsing its recovery.conf
    public function get_primary_node_for_node($node, $return_host = NULL){
       if(!$this->check_replication($node)){
          return(NULL);
       }

       $output = $this->ssh($node, "cat {$this->postgres_home}/9.0/data/recovery.conf | grep 'primary_conninfo'", 'postgres', true);
    
       $matches = array();
       if(!preg_match("#primary_conninfo\s*=\s*'*.*host=(\S+)#", $output[0], $matches)){
          return(NULL);
       }

       return($return_host?$matches[1]:$this->get_node_from_host($matches[1]));
    }

    public function get_standby_nodes(){
       return($this->standby_nodes);
    }

    public function get_unused_nodes(){
       return($this->unused_nodes);
    }

    #pass true value to $alt to return the alternate format
    public function get_nodes($alt = NULL){
       if($alt){
          return(array(
             'primary' => $this->primary_nodes,
             'standby' => $this->standby_nodes,
             'unused' => $this->unused_nodes
          ));
       }

       $primary_nodes = $this->primary_nodes;
       $standby_nodes = $this->standby_nodes;
       $unused_nodes = $this->unused_nodes;

       $return_array = array();
       
       foreach($primary_nodes as $node){
          $return_array[$node['host']] = $node;
       }
 
       foreach($standby_nodes as $node){
          if($return_array[$node['host']]){
             $return_array[$node['host']]['type'] = 'both';
             $return_array[$node['host']]['roles'] = $node['roles'];
          }else{
             $return_array[$node['host']] = $node;
          }
       }

       foreach($unused_nodes as $node){
          $return_array[$node['host']] = $node;
       }

       return($return_array);
    }

    public function get_node($node = NULL){
       foreach(array($this->standby_nodes, $this->primary_nodes, $this->unused_nodes) as $nodes){
          foreach($nodes as $nod){
             if($nod['id'] == $node){
                return($nod);
             }
          }

       }
       
    }

    public function get_node_from_host($host = NULL){
       foreach(array($this->standby_nodes, $this->primary_nodes, $this->unused_nodes) as $nodes){
          foreach($nodes as $node){
             if($node['host'] == $host){
                return($node);
             }
          }

       }

    }

    public function get_node_host($node = NULL){    
       if($node = $this->get_node($node)){
          return($node['host']);
       } 

    }

    public function get_current_node(){
       return($this->current_node);
    }

    ###################
    #this section of code will be obsolete once repmgr issue #33 is resolved
    ###################

    #this function isn't very useful
    function check_scripts_on_node($node=NULL){
      $required_files = array(
         'scripts/promote.sh',
         'scripts/follow.sh',
         'scripts/watch.sh',
      );

      $required_updates = array();

      foreach($required_files as $file){
         $output = $this->ssh($node, "cd {$this->PGDATA} ; md5sum $file");

         $remote_md5 = trim(rtrim($output[0]));
 
         if($remote_md5 != trim(rtrim(`cd {$this->scripts_directory} ; md5sum $file`))){
            $required_updates[] = $file;
         }

      }

      return(count($required_updates)==0?true:$required_updates);
   }

   function upload_scripts($node){
      $required_files = array(
         'scripts/promote.sh',
         'scripts/follow.sh',
         'scripts/watch.pl',
      );

      $required_directories = array();

      foreach($required_files as $file){
         $path = explode('/', $file);
         array_pop($path);
         $path = implode('/', $path);

         if(!in_array($path, $required_directories)){
            $this->ssh($node, "mkdir -p {$this->postgres_home}/$path", 'postgres');
            $required_directories[] = $path;
         }
         
         unset($path);

         $this->upload($node, 'postgres', $this->scripts_directory . "/$file", $this->postgres_home . "/$file", '755');
      }

   }
   
   ####
   #END
   ####    

}//class page











