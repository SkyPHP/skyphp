<?
class repmgr{
    private $primary_nodes = NULL;
    private $standby_nodes = NULL;
    private $current_node = NULL;

    private $config_path = NULL;
    private $log_path = NULL;
    private $data_path = NULL;

    private $PATH = NULL;
    private $PGDATA = NULL;

    public $initialized = NULL;

    #returns NULL on failure, $this->initialized = true on success
    public function __construct($read_db = NULL){
       global $repmgr_cluster_name;
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
 
       $this->PATH = '/usr/pgsql-9.0/bin:/usr/kerberos/bin:/usr/local/bin:/bin:/usr/bin';
       $this->PGDATA = &$this->data_path;

       return($this->initialized = true);
    }

    #used to initialize $this->standby_nodes and $this->primary_nodes
    private function generate_nodes($read_db = NULL){
       global $repmgr_cluster_name, $db;

       $conninfo_host_key = 'host=';

       $sql = "select s_nodes.conninfo as standby_conninfo, p_nodes.conninfo as primary_conninfo, substr(s_nodes.conninfo, strpos(s_nodes.conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(s_nodes.conninfo, strpos(s_nodes.conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) as standby_host, substr(p_nodes.conninfo, strpos(p_nodes.conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(p_nodes.conninfo, strpos(p_nodes.conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) as primary_host, standby_node, primary_node, time_lag from repmgr_$repmgr_cluster_name.repl_nodes as s_nodes inner join repmgr_$repmgr_cluster_name.repl_status s_status on s_nodes.id = s_status.standby_node inner join repmgr_$repmgr_cluster_name.repl_nodes as p_nodes on s_status.primary_node = p_nodes.id;";

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

             unset($primary_node, $standby_node);
         }

         $this->primary_nodes = $primary_nodes;
         $this->standby_nodes = $standby_nodes;

         unset($rs, $sql, $primary_nodes, $standby_nodes, $conninfo_host_key);
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
       return(explode("\n", trim(rtrim("ssh -nq $user@{$node['host']} -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no '$cmd' 2>/dev/null; echo \$?"))));
    }

    #get a list of running repmgr processes on remote machine
    public function remote_ps($node = NULL, $user = 'postgres'){
       $output = $this->ssh($node, 'ps -Awwo pid,user,args|grep repmgr', $user);

       $exit_status = array_pop($output);

       if($exit_status !== '0'){
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
             $output = $this->ssh($node, "kill -9 $pid 2>&1", $user);
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

    #promotes a standby node to primary, drops old primary from cluster
    #POTENTIALLY VERY DESTRUCTIVE, BE SMART
    public function promote($node){
       $output = array();

       #stop db on master
       $current_primary = $this->get_current_primary_node();
       #pg_ctl stop for some reason takes forever or does not work at all.  /etc/init.d works in seconds every time.
       $output[] = $this->ssh($old_primary_id = $current_primary['id'], $this->export() . 'hostname; /etc/init.d/postgresql-9.0 stop', 'root');

       #promote on new master
       $output[] = $this->ssh($node, $this->export() . "repmgr -f {$this->config_path} --verbose standby promote 2>&1", 'postgres');
 
       #follow on all new standbys
       foreach($this->standby_nodes as $standby_node){
          if($standby_node['id'] == $node){
             continue;
          }

          $output[] = $this->ssh($standby_node['id'], $this->export() . "repmgr -f {$this->config_path} --verbose standby follow 2>&1", 'postgres');
       }

       #reinitialize our object and $dbw
 #      global $db_host, $dbw, $db_platform, $dbw_host, $db_username, $db_password, $db_name;
 #      $this->generate_nodes($db_host);

  #     $dbw = &ADONewConnection($db_platform);
   #    @$dbw->Connect($dbw_host, $db_username, $db_password, $db_name);
  #     if($dbw->ErrorMsg()){
  #        $master_db_connect_error = "<!-- \$dbw error ($dbw_host): " . $dbw->ErrorMsg() . " -->";
  #        die($master_db_connect_error);
  #     }

  #     $output[] = $dbw_host;
       
 #      $dbw->Execute("insert into test(comment) values('abcdefg')");

       #cleanup repmgr tables
#       global $repmgr_cluster_name;
#       $dbw->Execute("delete from repmgr_$repmgr_cluster_name.repl_monitor where primary_node = $old_primary_id");
#       $dbw->Execute("delete from repmgr_$repmgr_cluster_name.repl_nodes where id = $old_primary_id");

       return($output);
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

    #pass true value to alt to return the alternate format
    public function get_nodes($alt = NULL){
       if($alt){
          return(array(
             'primary' => $this->primary_nodes,
             'standby' => $this->standby_nodes
          ));
       }

       $primary_nodes = $this->primary_nodes;
       $standby_nodes = $this->standby_nodes;

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

       return($return_array);
    }

    public function get_node($node = NULL){
       foreach(array($this->standby_nodes, $this->primary_nodes) as $nodes){
          foreach($nodes as $nod){
             if($nod['id'] == $node){
                return($nod);
             }
          }

       }
       
    }

    public function get_current_node(){
       return($this->current_node);
    }

}//class page
