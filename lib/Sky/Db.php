<?php

namespace Sky;

class Db {

    /**
     * determines if the given db connection is a standby server (read-only)
     * @param adodb_conn $db
     * @return boolean
     */
    public static function isStandby($db)
    {
        // TODO: just grab the db_platform from the $db object instead of this global
        global $db_platform;

        $is_standby = false;

        // determine if this database is the master or a standby
        switch ($db_platform) {

            case 'postgres':
            case 'postgres8':
                // PostgreSQL 9.0 required
                $r = \sql("select pg_is_in_recovery() as stat;", $db);
                if ($r->Fields('stat') == 't') {
                    $is_standby = true;
                }
                break;

            case 'mysql':
            /*
                // MySQL -- not tested
                $r = sql("select variable_value
                          from information_schema.global_status
                          where variable_name = 'Slave_running';", $d);
                if ($r->Fields('variable_value') == 'ON') {
                    $is_standby = true;
                }
                break;
            */
        }

        return $is_standby;
    }

    /**
     * gets an array of standby server hostnames
     * This is too slow! We are currently not using this.
     * @param adodb_conn $dbw
     * @return array
     */
    public static function getStandbys($dbw)
    {
        global $db_platform;

        $standbys = array();

        switch ($db_platform) {

            // PostgreSQL
            case 'postgres':
            case 'postgres8':

                // find a standby (9.1 required)
                $r = sql("
                        select client_addr
                        from pg_stat_replication
                        order by random()
                        ", $dbw
                );
                /*
                            -- order by the least lag (9.2 required)
                            --pg_xlog_location_diff(
                            --    write_location,
                            --    pg_current_xlog_location()
                            --) asc,
                */

                if ($r->EOF) {
                    // if multiple hosts are specified in the config but no standbys
                    // are actually up and running
                    break;
                }

                while (!$r->EOF) {
                    $standbys[] = $r->Fields('client_addr');
                    $r->MoveNext();
                }
                break;

            // MySQL
            case 'mysql':
                // TODO
                break;

        }

        return $standbys;
    }

    /**
     * gets the master hostname
     * TODO: add multi-master support
     * @param adodb_conn $db
     * @return string
     */
    public static function getPrimary($db)
    {
        global $db_platform, $db_name;

        $dbw_host = null;

        switch ($db_platform) {

            case 'postgres':
            case 'postgres8':

                // PostgreSQL does not have a mechanism to determine the master, so our
                // procedure is to store the hostname of the master in the database
                // "description" JSON.

                // this query adapted from the query for `psql -E -c \\l+`
                $r = sql("
                    SELECT pg_catalog.shobj_description(d.oid, 'pg_database') as comment
                    FROM pg_catalog.pg_database d
                    JOIN pg_catalog.pg_tablespace t on d.dattablespace = t.oid
                    WHERE d.datname = '$db_name';
                ", $db);

                $comment = json_decode($r->Fields('comment'), true);

                if (!(is_array($comment) && is_array($comment['replication']) && $comment['replication']['master'])) {
                    // db comment is missing the master information, go into read-only
                    break;
                }

                $dbw_host = $comment['replication']['master'];

            case 'mysql':
                break;

        }

        return $dbw_host;
    }
}
