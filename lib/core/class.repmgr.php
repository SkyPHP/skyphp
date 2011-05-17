<?
class repmgr{
    private $primary_nodes = NULL;
    private $standby_nodes = NULL;
    private $current_node = NULL;

    public $initialized = NULL;

    #returns NULL on failure, $this->initialized = true on success
    public function __construct($read_db = NULL){
       global $repmgr_cluster_name, $db;
       if(!$repmgr_cluster_name){
          return(NULL);
       }

       if(!$read_db){
          global $db_host;
          if(!($read_db = $db_host)){
             return(NULL);
          }
       }

       $conninfo_host_key = 'host=';

       $sql = "select s_nodes.conninfo as standby_conninfo, p_nodes.conninfo as primary_conninfo, substr(s_nodes.conninfo, strpos(s_nodes.conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(s_nodes.conninfo, strpos(s_nodes.conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) as standby_host, substr(p_nodes.conninfo, strpos(p_nodes.conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(p_nodes.conninfo, strpos(p_nodes.conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) as primary_host, standby_node, primary_node, time_lag from repmgr_$repmgr_cluster_name.repl_nodes as s_nodes inner join repmgr_$repmgr_cluster_name.repl_status s_status on s_nodes.id = s_status.standby_node inner join repmgr_$repmgr_cluster_name.repl_nodes as p_nodes on s_status.primary_node = p_nodes.id;";

       if($rs = $db->Execute($sql)){
          $primary_nodes = array();
          $standby_nodes = array();

          while(!$rs->EOF){
             if(!$primary_nodes[$primary_node = $rs->Fields('primary_node')]){
                $primary_nodes[$primary_node] = array(
                   'primary_conninfo' => $rs->Fields('primary_conninfo'),
                   'primary_host' => $rs->Fields('primary_host'),
                   'primary_node' => $primary_node
                );
             }

             if(!$standby_nodes[$standby_node = $rs->Fields('standby_node')]){
                $standby_nodes[$standby_node] = array(
                   'standby_conninfo' => $rs->Fields('standby_conninfo'),
                   'standby_host' => $rs->Fields('standby_host'),
                   'standby_node' => $standby_node,
                   'primary_node' => $primary_node,
                   'time_lag' => $rs->Fields('time_lag')
                );
             }

             if($standby_nodes[$standby_node]['standby_host'] == $read_db){
                $this->set_write_db($primary_nodes[$primary_node]['primary_host']);
                $this->current_node = $standby_nodes[$standby_node];
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

      return($this->initialized = true);
    }

    #last line of output is ssh command exit status
    public function ssh($host = NULL, $cmd = NULL, $user = 'postgres'){ 
       return(explode("\n", trim(rtrim(`ssh -nq $user@$host -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no '$cmd' 2>/dev/null; echo $?`))));
    }

    #this function requires public/private key pairs set up for ssh between two machines for a given user
    public function remote_ps($host = NULL, $user = 'postgres'){
       $output = $this->ssh($host, 'ps -Awwo pid,args|grep repmgr', $user);

       $exit_status = array_pop($output);

       if($exit_status !== '0'){
          return($exit_status);
       }

       $return = array();

       foreach($output as $line){
          if(preg_match('#^\s*\d+\s*bash \-c ps#', $line)){
             continue;
          }

          $matches = array();

          if(preg_match('#^\s*(\d+)\s+(.*)$#', $line, $matches)){
             $return[] = array(
                'pid' => $matches[1],
                'cmd' => $matches[2],
                'raw' => $matches[0]
             );

          }else{
             $return[] = array('raw' => $line);
          }
          
       }

       return($return);
    }

    private function set_write_db($write_db){
       global $dbw_host;
       return($dbw_host = $write_db);
    }

    public function get_primary_nodes(){
       return($this->primary_nodes);
    }

    public function get_standby_nodes(){
       return($this->standby_nodes);
    }

    public function get_nodes(){
       return(array(
          'primary' => $this->get_primary_nodes(),
          'standby' => $this->get_standby_nodes()
       ));
    }

    public function get_current_node(){
       return($this->current_node);
    }

}//class page
