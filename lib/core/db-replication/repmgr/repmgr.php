<?

$repmgr = new repmgr($db_host);

/*
The old code, I don't expect we'll need it but you never know

// we already have a connection to the slave machine ($db)
// now, determine the writable master db host

#if this variable is defined (in the config) it should be assumed that repmgr is in use for this website
if($repmgr_cluster_name){
   $conninfo_host_key = 'host=';

   $sql = "select s_nodes.conninfo as standby_conninfo, p_nodes.conninfo as primary_conninfo, substr(s_nodes.conninfo, strpos(s_nodes.conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(s_nodes.conninfo, strpos(s_nodes.conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) as standby_host, substr(p_nodes.conninfo, strpos(p_nodes.conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(p_nodes.conninfo, strpos(p_nodes.conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) as primary_host, standby_node, primary_node, time_lag from repmgr_$repmgr_cluster_name.repl_nodes as s_nodes inner join repmgr_$repmgr_cluster_name.repl_status s_status on s_nodes.id = s_status.standby_node inner join repmgr_$repmgr_cluster_name.repl_nodes as p_nodes on s_status.primary_node = p_nodes.id  where substr(s_nodes.conninfo, strpos(s_nodes.conninfo, '$conninfo_host_key') + length('$conninfo_host_key'), strpos(substr(s_nodes.conninfo, strpos(s_nodes.conninfo, '$conninfo_host_key')), ' ') - 1 - length('$conninfo_host_key')) = '$db_host';";

   if($rs = $db->Execute($sql)){
      while(!$rs->EOF){
         $dbw_host = $rs->Fields('primary_host');
         $time_lag = $rs->Fields('time_lag');
         $rs->MoveNext();
      }
   }
}
*/
