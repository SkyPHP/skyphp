<?php

/**
Postgresql 9.0 is required for SkyPHP
Postgresql 9.1 is required to utilize replication (multiple values in $db_hosts)
**/

// TODO don't connect to master unless you need to write

elapsed('db-connect begin');

if (!$db_hosts) {
    // not using the latest settings format
    // check $db_host and $db_domain for backwards compatibility
    if ($db_host) $db_hosts = array($db_host);
    else if ($db_domain) $db_hosts = array($db_domain);
}

// skip database connection if we don't have a db_name and db_hosts
if ($db_name && is_array($db_hosts)) {

    // in case db_hosts was specified as csv
    if (!is_array($db_hosts)) {
        $db_hosts = explode(',', $db_hosts);
    }

    // the memcache key to use so we remember for a minute that the master is down
    $dbw_status_key = 'dbw-status:' . $db_name . ':' . implode(',', $db_hosts);
    $dbw_status_check_interval = '1 minute';

    shuffle($db_hosts);

    $db_error = '';

    foreach ($db_hosts as $db_host) {

        if ($db && $dbw) break;

        // connect to the next database in our (randomized) list of hosts
        $db_host = trim($db_host);
        $d = &ADONewConnection($db_platform);
        @$d->Connect($db_host, $db_username, $db_password, $db_name);

        if ($d->ErrorMsg()) {
            #this connection failed, try the next one
            $db_error .= "db error ($db_host): {$d->ErrorMsg()}, trying next one... \n";
            continue;
        }

        // determine if this database is the master or a standby
        $r = sql("select pg_is_in_recovery() as stat;", $d); #9.0 required

        $is_standby = $r->Fields('stat');

        if ($is_standby == 't') {
            // we just connected to a standby
            $db = &$d;
 
            if ($dbw) {
                #we already found our master in a previous iteration
                break;
            } else {
                #get the master and connect to it                

                if (mem($dbw_status_key)) {
                    #our master is down, do not attempt to connect
                    $db_error .= 'memcached indicates master is down...';
                    $dbw = NULL;
                    break;
                }

                #get the comment for the database
                #this query adapted from the query for `psql -E -c \\l+`
                $r = sql("
                    SELECT pg_catalog.shobj_description(d.oid, 'pg_database') as comment
                    FROM pg_catalog.pg_database d
                    JOIN pg_catalog.pg_tablespace t on d.dattablespace = t.oid
                    WHERE d.datname = '$db_name';
                ", $db); #9.1 required

                $comment = json_decode($r->Fields('comment'), true);

                if (!$comment) {
                    #our comment either does not exist, is invalid json, or the json evaluates to false
                    #go into readonly
                    $db_error .= "db error ($db_host): db comment is not valid \n";
                    $dbw = NULL;
                    break;
                }

                if (!(is_array($comment) && is_array($comment['replication']) && $comment['replication']['master'])) {
                    #our comment is missing the master information, go into read-only
                    $db_error .= "db error ($db_host): db comment is missing proper replication information\n";
                    $dbw = NULL;
                    break;
                }

                $dbw_host = $comment['replication']['master'];

                $dbw = &ADONewConnection($db_platform);
                @$dbw->Connect($dbw_host, $db_username, $db_password, $db_name);

                if ($dbw->ErrorMsg()) {
                    #connection to the master failed, go into read-only
                    $db_error .= "db error ($dbw_host): {$dbw->ErrorMsg()}, can not connect to master \n";
                    #mark the failure in memcahced
                    mem($dbw_status_key, 'true', $dbw_status_check_interval);
                    $dbw = NULL;
                    break;
                }

                // determine if this database is actually the master
                $r = sql("select pg_is_in_recovery() as stat;", $dbw);

                $is_standby = $r->Fields('stat');

                if ($is_standby == 't') {
                    #our db comment is out of date and there is a new master which we cant determine, go into read-only
                    #do not mark this in memcached, this should be manually resolved shortly
                    #(usually this will only happen when a webpage is served during a promotion)
                    $dbw = NULL;
                    break;
                }
            }
        } else {
            // we just connected to master
            $dbw = &$d;

            #do not attempt to seek a slave if only one host is in the config
            if (count($db_hosts == 1)) break;

            $r = sql("
                    select
                        client_addr
                    from
                        pg_stat_replication
                    order by
                       -- pg_xlog_location_diff(
                       --     write_location,
                       --     pg_current_xlog_location()  -- this depends on 9.2
                       -- ) asc,
                        random()
                    ", $dbw
            ); #this depends on 9.1

            if ($r->EOF) {
                #if multiple hosts are specified in the config but no actual standbys are configured, break
                break;
            }

            while(!$r->EOF){
                $db_host = $r->Fields('client_addr');

                $db = &ADONewConnection($db_platform);
                @$db->Connect($db_host, $db_username, $db_password, $db_name);
 
                if ($db->ErrorMsg()) {
                    #connection to the slave failed, try the next one
                    $db_error .= "db error ($db_host): {$db->ErrorMsg()}, trying next one... \n";
                } else {
                    break;
                }

                $r->MoveNext();
            }

        }

    }

    if($dbw && !$db){
        $db = &$dbw;
        $db_host = $dbw_host;
    }

    #if there is no $db_host, that means all our choices have failed
    if (!($db || $dbw)) {
        include( 'pages/503.php' );
        die( "<!-- $db_error -->" );
    }
}

if ($_GET['db_debug'] == 1) {
    echo "<hr />\n";
    echo '$db host  : ', $db?$db->host:NULL, "\n";
    echo '$dbw_host : ', $dbw?$dbw->host:NULL, "\n"; 
    echo "errors    : \n$db_error\n";
    echo '<hr />';
}

elapsed('db-connect end');
