<?php

/*
    If using PostgreSQL:
    - Version 9.0 is required
    - Version 9.1 required for replication (multiple values in $db_hosts)

    If using MySQL:
    - Replication is not yet supported
*/

// TODO don't force a connection to the master unless you need to write

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

        // if we have read and write db connections, we are done
        if ($db && $dbw) break;

        // connect to the next database in our (randomized) list of hosts
        $db_host = trim($db_host);
        $d = &ADONewConnection($db_platform);
        @$d->Connect($db_host, $db_username, $db_password, $db_name);

        if ($d->ErrorMsg()) {
            // this connection failed, try the next one
            $db_error .= "db error ($db_host): {$d->ErrorMsg()}! \n";
            continue;
        }

        $is_standby = \Sky\Db::isStandby($d);

        if ($is_standby) {

            // PostgreSQL
            // we just connected to a standby
            $db = $d;

            if ($dbw) {
                // we already found our master in a previous iteration
                break;

            } else {
                // get the master and connect to it

                if (mem($dbw_status_key)) {
                    // master was down less than a minute ago, do not attempt to connect
                    $db_error .= 'memcached indicates master is down.';
                    $dbw = NULL;
                    break;
                }

                // determine the master
                $dbw_host = \Sky\Db::getPrimary($db);
                if (!$dbw_host) {
                    // cannot determine master
                    $db_error .= "db error ($db_host): cannot determine master \n";
                    $dbw = NULL;
                    break;
                }

                // we have determined the master, now we will connect to the master

                $dbw = &ADONewConnection($db_platform);
                @$dbw->Connect($dbw_host, $db_username, $db_password, $db_name);

                if ($dbw->ErrorMsg()) {
                    // connection to the master failed, go into read-only
                    $db_error .= "db error ($dbw_host): {$dbw->ErrorMsg()}, cannot connect to master \n";
                    // the host we believe is the master is down
                    // cache this so we don't try connecting to it again for a minute
                    mem($dbw_status_key, 'true', $dbw_status_check_interval);
                    $dbw = NULL;
                    break;
                }

                // we connected successfully to the host we believe is the master
                // now we must verify this database actually is in fact the master
                // STONITH: it is guaranteed that only one host thinks it is the master
                $is_standby = \Sky\Db::isStandby($dbw);

                if ($is_standby) {
                    // there is no master, or at least this standby doesn't know the
                    // correct master.  this should only happen during a promotion.
                    // go into read-only mode
                    $dbw = NULL;
                    break;
                }
            }

        } else {
            // we just connected to a writable database, i.e. the master

            $dbw = $d;
            $dbw_host = $db_host;

            // do not attempt to seek a standby if only one host is in the config
            if (count($db_hosts) === 1) break;

            // getting verified standbys is too slow, so just get the next standby from
            // our list of hosts
            continue;

            /*
            $standbys = \Sky\Db::getStandbys($dbw);

            if (!$standbys) {
                // if multiple hosts are specified in the config but no standbys
                // are actually up and running
                break;
            }

            foreach ($standbys as $db_host) {

                $db = &ADONewConnection($db_platform);
                @$db->Connect($db_host, $db_username, $db_password, $db_name);

                if ($db->ErrorMsg()) {
                    // connection to the standby failed, try the next one
                    $db_error .= "db error ($db_host): {$db->ErrorMsg()}. \n";
                    continue;
                }

                // we connected
                break;

            }
            */

        }

    }

    // unset temp connection resource
    unset($d);

    // if the master is our only connection, use it for reads also
    if ($dbw && !$db) {
        $db = &$dbw;
        $db_host = $dbw_host;
    }

    // if we are missing both master and slave, display 503 down for maintenance message
    if (!$db && !$dbw) {
        include 'pages/503.php';
        die("<!-- $db_error -->");
    }
}

elapsed('db-connect end');
