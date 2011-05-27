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
    public function __construct($read_db = NULL){
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

       if(!$this->generate_nodes($read_db)){
          return(NULL);
       }

       $this->config_path = '/var/lib/pgsql/repmgr/repmgr.conf';
       $this->log_path = '/var/lib/pgsql/repmgr/repmgr.log';
       $this->data_path = '/var/lib/pgsql/9.0/data';
       $this->scripts_directory = (($last_char = array_pop(str_split($skyphp_codebase_path))) == '/'?$skyphp_codebase_path:$skyphp_codebase_path . $last_char) . 'lib/core/db-replication/repmgr';
       $this->postgres_home = '/var/lib/pgsql';

       $this->PATH = '/usr/pgsql-9.0/bin:/usr/kerberos/bin:/usr/local/bin:/bin:/usr/bin';
       $this->PGDATA = &$this->data_path;

       return($this->initialized = true);
    }

    #used to initialize $this->standby_nodes and $this->primary_nodes amd $this->unused_nodes
    private function generate_nodes($read_db = NULL){
       global $repmgr_cluster_name, $db;

       $conninfo_host_key = 'host=';

       $sql = "select id, cluster, conninfo, substr(conninfo, strpos(conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(conninfo, strpos(conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) as host from repmgr_$repmgr_cluster_name.repl_nodes where cluster = '$repmgr_cluster_name'";

       $unused_nodes = array();

       if($rs = $db->Execute($sql)){
          while(!$rs->EOF){
             $unused_nodes[$id = $rs->Fields('id')] = array(
                'id' => $id,
                'type' => 'unused',
                'host' => $rs->Fields('host'),
                'conninfo' => $rs->Fields('conninfo')                
             );

             $rs->MoveNext();
          }       
    
          unset($rs, $sql, $id);
       }

       $sql = "select p_nodes.cluster, s_nodes.conninfo as standby_conninfo, p_nodes.conninfo as primary_conninfo, substr(s_nodes.conninfo, strpos(s_nodes.conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(s_nodes.conninfo, strpos(s_nodes.conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) as standby_host, substr(p_nodes.conninfo, strpos(p_nodes.conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(p_nodes.conninfo, strpos(p_nodes.conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) as primary_host, standby_node, primary_node, time_lag from repmgr_$repmgr_cluster_name.repl_nodes as s_nodes inner join repmgr_$repmgr_cluster_name.repl_status s_status on s_nodes.id = s_status.standby_node inner join repmgr_$repmgr_cluster_name.repl_nodes as p_nodes on s_status.primary_node = p_nodes.id where p_nodes.cluster = '$repmgr_cluster_name';";

       if($rs = $db->Execute($sql)){
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

         $this->primary_nodes = $primary_nodes;
         $this->standby_nodes = $standby_nodes;
         $this->unused_nodes = $unused_nodes;

         unset($rs, $sql, $primary_nodes, $standby_nodes, $conninfo_host_key, $unused_nodes);
      }else{
         return(NULL);
      }

      return(true);
    }

    #we need certain environmental variables set in our ssh sessions, this function returns the boilerplate
    private function export(){
       return("export PATH={$this->PATH} ; export PGDATA={$this->PGDATA} ;");
    }

    #last line of output is ssh command exit status
    private function ssh($node = NULL, $cmd = NULL, $user = 'postgres'){
       $node = $this->get_node($node);
       return(explode("\n", trim(rtrim(`ssh -T $user@{$node['host']} -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no <<\"EOF\"\n$cmd\nEOF\necho $?`))));
/*       return(explode("\n", trim(rtrim(`ssh -nq $user@{$node['host']} -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no <<EOF\n$cmd\nEOF\necho $?`))));
       return(explode("\n", trim(rtrim(`ssh -nq $user@{$node['host']} -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no '$cmd' 2>/dev/null; echo \$?`))));*/
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

    #private while not complete
    public function add($node = NULL){
       $output = array();
 
       $current_primary = $this->get_current_primary_node();

       global $db_name;

       $output[] = $this->ssh($node, "/etc/init.d/postgresql-9.0 stop", 'root');
       $output[] = $this->ssh($node, "repmgr -D \$PGDATA -d $db_name -U repmgr -R postgres --verbose --force standby clone {$current_primary['host']} 2>&1");
       $output[] = $this->ssh($node, "/etc/init.d/postgresql-9.0 start", 'root');
       $output[] = $this->ssh($node, "repmgrd -f {$this->config_path} --verbose  >{$this->log_path} 2>&1 &", 'postgres');

       return($output);
        
    }

    #promotes a standby node to primary, drops old primary from cluster
    #POTENTIALLY VERY DESTRUCTIVE, BE SMART
    public function promote($node){
       $output = array();

       foreach($this->get_nodes() as $host => $obj){
          $this->upload_scripts($obj['id']);
       }

       #stop db on master
       $current_primary = $this->get_current_primary_node();
       #pg_ctl stop for some reason takes forever or does not work at all.  /etc/init.d works in seconds every time.
       $output[] = $this->ssh($old_primary_id = $current_primary['id'], '/etc/init.d/postgresql-9.0 stop', 'root');

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
       global $dbw, $dbw_host, $db_host, $db_username, $db_password, $db_name;

       $this->set_write_db($this->get_node_host($node));

       $dbw = &ADONewConnection($db_platform);
       @$dbw->Connect($dbw_host, $db_username, $db_password, $db_name);

       $sleep_time = 5;
       $max_tries = 5;
       $try = 0;

       while($output[] = $dbw->ErrorMsg()){
          if($try++ > $max_tries){
             $output[] = "Unable to connect to database";
             return($output);
          }

          sleep($sleep_time);
   
          $dbw = &ADONewConnection($db_platform);
          @$dbw->Connect($dbw_host, $db_username, $db_password, $db_name);
       }

    /*   @$dbw->Connect($dbw_host, $db_username, $db_password, $db_name);
       if($dbw->ErrorMsg()){
          $output[] = $master_db_connect_error = "<!-- \$dbw error ($dbw_host): " . $dbw->ErrorMsg() . " -->";
          die(var_dump($output));
       }

       $output[] = $dbw_host; */
       
       #just a test
       $dbw->Execute("insert into test(comment) values('abcdefghijkl')");

       #cleanup repmgr tables
       global $repmgr_cluster_name;
       $dbw->Execute("delete from repmgr_$repmgr_cluster_name.repl_monitor where primary_node = $old_primary_id");

       foreach($needs_start as $id){
          $this->remote_start($id);
       }

       #update our object to contain correct information
       $this->generate_nodes($db_host);
      
       return($output);
    }

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

    public function get_standby_nodes(){
       return($this->standby_nodes);
    }

    public function get_unused_nodes(){
       return($this->unused_nodes);
    }

    #pass true value to alt to return the alternate format
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











