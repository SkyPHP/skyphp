<?php

namespace Sky;

/**
 * Utility class for connecting to a replicated database using PDO
 */
class Db {

    /**
     * Connect to database
     * @param array $params
     *
     * @global
     */
    public static function connect($a = [])
    {
        global
            $db_driver,
            $db_name,
            $db_host,
            $db_username,
            $db_password,
            $db_error,
            $db_sslmode;

        $host = $a['db_host'] ?: $db_host;

        if ($db_sslmode) {
            $ssl = ";sslmode={$db_sslmode}";
        }

        try {
            $d = new \PDO(
                "{$db_driver}:dbname={$db_name};host={$host}{$ssl}", // dsn
                $db_username, // username
                $db_password, // password
                [ // options
                    #\PDO::ATTR_PERSISTENT => true
                ]
            );
            $d->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } catch (\PDOException $e) {
            // this connection failed, try the next one
            $db_error .= "db error ($host): {$e->getMessage()}\n";
        }
        return $d;
    }


    /**
     * determines if the given db connection is a standby server (read-only)
     * @param adodb_conn $db
     * @return boolean
     */
    public static function isStandby($db)
    {
        // TODO: just grab the db_platform from the $db object instead of this global
        global $db_driver, $old_postgresql;

        $is_standby = false;

        // determine if this database is the master or a standby
        switch ($db_driver) {

            case 'pgsql':
                // don't check for replication if we don't have postgresql 9.0+
                if (!$old_postgresql) {
                    // PostgreSQL 9.0 required
                    $r = \sql("select pg_is_in_recovery() as stat;", $db);
                    if ($r[0]->stat) {
                        $is_standby = true;
                    }
                }
                break;

            case 'mysql':
                // MySQL -- not tested
                $r = sql("select variable_value
                          from information_schema.global_status
                          where variable_name = 'Slave_running'", $db);
                if ($r[0]->variable_value == 'ON') {
                    $is_standby = true;
                }
                break;
        }

        return $is_standby;
    }


    // /**
    //  * gets an array of standby server hostnames
    //  * This is too slow! We are currently not using this.
    //  * @param adodb_conn $dbw
    //  * @return array
    //  */
    // public static function getStandbys($dbw)
    // {
    //     global $db_platform;

    //     $standbys = array();

    //     switch ($db_platform) {

    //         // PostgreSQL
    //         case 'postgres':
    //         case 'postgres8':

    //             // find a standby (9.1 required)
    //             $r = sql("
    //                     select client_addr
    //                     from pg_stat_replication
    //                     order by random()
    //                     ", $dbw
    //             );

    //                        # -- order by the least lag (9.2 required)
    //                        # --pg_xlog_location_diff(
    //                        # --    write_location,
    //                        # --    pg_current_xlog_location()
    //                        # --) asc,


    //             if ($r->EOF) {
    //                 // if multiple hosts are specified in the config but no standbys
    //                 // are actually up and running
    //                 break;
    //             }

    //             while (!$r->EOF) {
    //                 $standbys[] = $r->Fields('client_addr');
    //                 $r->MoveNext();
    //             }
    //             break;

    //         // MySQL
    //         case 'mysql':
    //             // TODO
    //             break;

    //     }

    //     return $standbys;
    // }


    /**
     * gets the master hostname
     * TODO: add multi-master support
     * @param adodb_conn $db
     * @return string
     */
    public static function getPrimary($db)
    {
        global $db_driver, $db_name, $master_db_host ;

        $dbw_host = null;

        if ($master_db_host ) {
            return $master_db_host ; 
        }

        switch ($db_driver) {

            case 'pgsql':
                elapsed('Looking for master DB');
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

                $comment = json_decode($r[0]->comment, true);

                if (!(is_array($comment) && is_array($comment['replication']) && $comment['replication']['master'])) {
                    // db comment is missing the master information, go into read-only
                    break;
                }

                


                $dbw_host = $comment['replication']['master'];

                elapsed('Master DB found : '.$dbw_host);

            case 'mysql':
                break;

        }

        return $dbw_host;
    }
}
