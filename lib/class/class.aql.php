<?php

/**
 * @package SkyPHP
 */
class aql
{

    /**
     * @var int
     */
    const AQL_VERSION = 2;

    /**
     * Stack to hold errors during a transaction
     * @var array
     */
    public static $errors = array();

    /**
     * Generates a form for the given model
     * @param   string  $model_name
     * @param   string  $ide
     * @return  \Sky\Page
     * @global  $p      \Sky\Page
     * @throws  Exception           if no global $p
     * @deprecated                  Use \Sky\Page::form(\Model) instead
     */
    public function form($model_name, $ide = null)
    {
        global $p;
        if (!$p || !is_object($p) || get_class($p) != '\Sky\Page') {
            throw new Exception('No global \Sky\Page object.');
        }

        $o = new $model_name;
        if ($ide) {
            $o->loadDB($ide);
        }

        return $p->form($o);
    }

    /**
     * Checks the model's aql for default clauses
     * and returns a clause array, only keys: [where, order by]
     * @param   string  $model_name
     * @return  array
     */
    public static function get_clauses_from_model($model_name)
    {
        $clauses = array(
            'where' => array(),
            'order by' => array()
        );

        $arr = aql2array::get($model_name);
        foreach ($arr as $t) {
            $clauses['where'] += $t['where'];
            $clauses['order by'] += $t['order by'];
        }

        return $clauses;
    }

    /**
     * Makes a minimal AQL statement form the model's AQL, keeping only the joins,
     * and the primary_table's ID
     * @param   string  $model_name
     * @return  string
     */
    public static function get_min_aql_from_model($model_name)
    {

        $i = 0;
        $aql = '';
        $arr = aql2array::get($model_name);

        foreach ($arr as $t) {

            $aql .= "{$t['table']} as {$t['as']}";

            if ($t['on']) {
                $aql .= " on {$t['on']}";
            }

            if ($i === 0) {
                $aql .= " { id } \n";
                $i++;
            } else {
                $aql .= " { } \n";
            }

        }

        return $aql;
    }

    /**
     * Finds the AQL in the codebases
     * @param   string  $model_name
     * @return  string
     * @global  $codebase_path_arr
     * @global  $sky_aql_model_path
     */
    public function get_aql($model_name)
    {
        global $codebase_path_arr,
            $sky_aql_model_path;


        foreach ($codebase_path_arr as $codebase_path) {
            $path = sprintf(
                '%s%s/%s/%s.aql',
                $codebase_path,
                $sky_aql_model_path,
                $model_name,
                $model_name
            );

            if (file_exists($path)) {
                return @file_get_contents($path);
            }
        }
    }

    /**
     * Gets current master database time
     * If no master DB, returns php time
     * @return  string
     */
    public function now()
    {
        if (!self::hasMasterDB()) {
            return date('c');
        }

        return self::getMasterDB()
            ->Execute("SELECT CURRENT_TIMESTAMP as now")
            ->Fields('now');
    }

    /**
     * Performs a select on the database and returns records,
     * it can possibly return objects as well using third param
     * This is like aql::select, except for it returns only ONE record
     * @param   mixed   $aql        model name | aql | aql array
     * @param   string  $id         id | ide
     * @param   mixed   $obj        string | bool
     * @param   string  $statment   if object, you can pass an aql statement
     * @param   Boolean $force     forces master db
     * @param   mixed   $conn       DB connection to override default
     * @return  array
     */
    public static function profile($aql, $id, $obj = false, $statement = null, $force = false, $conn = null)
    {
        // normalize AQL argument
        if (is_array($aql)) {

            $aql = $aql;  // this is the aql_array

        } else if (!self::is_aql($aql))  {

            $statement = ($statement) ?: self::get_aql($aql);
            $model = $aql;

            if ($obj) {
                $obj = $model;
            }

            $aql = aql2array::get($model, $statement);

        } else {
            $statement = $aql;
            $aql = aql2array($aql);
        }

        if (!$aql) {
            return array();
        }

        $model_name_arr = reset($aql);
        $model = $model_name_arr['as'];

        if (!is_numeric($id)) {
            $id = decrypt($id, $model);
        }

        if (!is_numeric($id)) {
            return array();
        }

        $clause = array(
            $model => array(
                'where' => array(
                    $model.'.id = '.$id
                )
            )
        );

        $rs = self::select($aql, $clause, $obj, $statement, $force, $conn);
        return $rs[0];
    }

    /**
     * Executes a select query on the DB
     * @param   mixed   $aql        aql | model name | aql array
     * @param   array   $clause     clause array | true (for $obj)
     * @param   mixed   $obj        Boolean or object name
     * @param   string  $statement  If passing in an aql array, use this to also pass in
     *                              the aql statement
     * @param   Boolean $force      Force master DB read
     * @param   mixed   $conn       Specific DB connection
     * @return  array
     * @global  $is_dev
     * @throws  \Sky\AQL\Exception  if model not found
     */
    public function select($aql, $clause = array(), $obj = false, $statement = null, $force = false, $conn = null)
    {
        global $is_dev;

        $conn = $conn ?: self::getDB();
        $silent = aql::in_transaction();

        if (!is_array($clause) && $clause === true) {
            $obj = true;
        }

        if (!$aql) {
            return array();
        }

        if (is_array($aql)) {
            $aql_array = $aql;
        } else {
            if (self::is_aql($aql)) {

                $statement = $aql;
                $aql_array = aql2array($statement);

            } else {

                $m = $aql;
                $statement = self::get_aql($m);
                if (!$statement) {
                    $e = new \Sky\AQL\Exception(
                        ' AQL Error: Model '. $m .' is not defined. ' . PHP_EOL
                        . "path/to/models/$m/$m.aql is empty or not found."
                    );

                    if (!$silent) {
                        self::$errors[] = $e;
                        return array();
                    }

                    throw $e;
                }

                $aql_array = aql2array::get($m, $statement);
            }
        }

        if ($obj && !is_bool($obj) && $m) {
            $obj = $m;
        }

        if (is_array($clause)) {
            $clause = self::check_clause_array($aql_array, $clause);
        }

        if ($_GET['aql_debug'] && $is_dev) {
            print_a($aql_array);
        }

        $returned = self::make_sql_array($aql_array, $clause);

        if ($_GET['aql_debug'] && $is_dev) {
            print_a($returned);
        }

        if ($_GET['refresh']) {
            $force = true;
        }

        $params = array(
            'object' => $obj,
            'aql_statement' => $statement,
            'sub_do_set' => $force
        );

        return self::sql_result($returned, $params, $conn);
    }

    /**
     * Returns result of a count(*) query on the given AQL
     * using sql_count select type
     * @param   mixed   $aql    string | aql array
     * @param   array   $clause
     * @return  int
     */
    public static function count($aql, $clause = array())
    {
        $rs = aql::sql_result(
            self::sql($aql, $clause),
            array(
                'select_type' => 'sql_count'
            )
        );

        return $rs[0]['count'];
    }

    /**
     * Returns an sql_select on the aql
     * Results look like:
     *  [
     *      [ id, {$primary_table}_id ]
     *  ]
     * @param   mixed   $aql    string | aql array
     * @param   array   $clause
     * @return  array
     */
    public static function listing($aql, $clause = array())
    {
        return self::sql_result(
            self::sql($aql, $clause),
            array(
                'select_type' => 'sql_list'
            )
        );
    }

    /**
     * Performs db select
     * This is a shortcut function to use the master db because of arguments list length
     * @see     self::select() on how these arguments get mapped
     * @param   mixed   $aql
     * @param   mxied   $clause
     * @param   mixed   $obj
     * @param   mixed   $statement
     * @param   Boolean $force_db
     */
    public static function selectDBW($aql, $clause = null, $obj = false, $statement = null, $force_db = false)
    {
        $dbw = self::getMasterDB();
        return aql::select($aql, $clause, $obj, $statement, $force_db, $dbw);
    }

    /**
     * Generates the sql_array with different query types based on the given aql
     * and clause array
     * @param   mixed   $aql    string | array
     * @param   array   $clause_array
     * @return  array
     */
    public static function sql($aql, $clause = array())
    {
        if (!is_array($aql)) {
            $aql = aql2array($aql);
        }

        if (is_array($clause)) {
            $clause = self::check_clause_array($aql, $clause);
        }

        return self::make_sql_array($aql, $clause);
    }

    /**
     * Increments a value in a table by the given amount
     * If silent or in transaction, errors are added to self::$errors
     *
     * Usage:
     *      aql::increment('table.field', 1, $id);
     *      // can decrement
     *      aql::incremnet('table.field', -2, $id);
     *
     * @param   string  $table_field
     * @param   string  $value
     * @param   string  $id     id | ide
     * @param   Boolean $silent
     * @return  Boolean
     * @throws  \Sky\AQL\Exception  if invalid args
     * @throws  \Sky\AQL\TransactionException if udpate failed
     */
    public static function increment($table_field, $value, $id, $silent = false)
    {
        if (!self::hasMasterDB()) {
            return false;
        }

        if (self::in_transaction()) {
            $silent = true;
        }

        list($table, $field) = explode('.', $table_field);
        if (!$table || !$field) {

            $e = new \Sky\AQL\Exception(
                'increment expects table.field as a first argument.'
            );

            if ($silent) {
                aql::$errors[] = $e;
                return false;
            }

            throw $e;
        }

        $id = is_numeric($id) ? $id : decrypt($id, $table);
        if (!$id || !is_numeric($id)) {

            $e = new \Sky\AQL\Exception(
                'third param of increment is not a valid identifier.'
            );

            if ($silent) {
                aql::$errors[] = $e;
                return false;
            }

            throw $e;
        }

        $do = (strpos($value, '-') !== false)
            ? ' - ' . abs($value)
            : ' + ' . $value;

        $dbw = self::getMasterDB();
        $sql = "UPDATE {$table} SET {$field} = {$field} {$do} WHERE id = {$id}";
        $r = $dbw->Execute($sql);

        if ($r !== false) {
            return true;
        }

        $e = new \Sky\AQL\TransactionException(
            $table,
            $field,
            $id,
            $dbw
        );

        $e->sql = $sql;

        if ($silent) {
            aql::$errors[] = $e;
            return false;
        }

        throw $e;
    }

    /**
     * Inserts a record into the database
     * If in a transaction or silent, exceptions will be added to self::$errors stack
     * @param   string      $table
     * @param   array       $fields
     * @param   Boolean     $silent
     * @return  array                           [ recordset ]
     * @throws  \Sky\AQL\Exception              if fields are invalid
     * @throws  \Sky\AQL\TransactionException  if insert failure
     */
    public static function insert($table, $fields, $silent = false)
    {
        if (!self::hasMasterDB()) {
            return array();
        }

        if (self::in_transaction()) {
            $silent = true;
        }

        if (!is_assoc($fields)) {
            if (!$silent) {
                throw new \Sky\AQL\Exception('Insert expects an array of fields');
            }

            return array();
        }

        // clean up fields
        unset($fields['id']);
        foreach ($fields as $k => $v) {
            if ($v === null || $v === '') {
                unset($fields[$k]);
            }
        }

        if (!$fields) {
            if (!$silent) {
                throw new \Sky\AQL\Exception('Insert fields is empty.');
            }

            return array();
        }

        $dbw = self::getMasterDB();
        $result = $dbw->AutoExecute($table, $fields, 'INSERT');
        if ($result === false) {

            $e = new \Sky\AQL\TransactionException(
                $table,
                $fields,
                null,
                $dbw
            );

            if ($silent) {
                $e->sendErrorEmail();
                aql::$errors[] = $e;
                return array();
            }

            throw $e;
        }

        if (!self::db_is_postgres()) {

            $id = $dbw->Insert_ID();

        } else {

            $sql = "SELECT currval('{$table}_id_seq') as id";
            $s = $dbw->Execute($sql);

            if ($s !== false) {
                $id = $s->Fields('id');
            } else {

                $e = new \Sky\AQL\Exception($table .' getID() error after insert.');

                if ($silent) {
                    aql::$errors[] = $e;
                    return array();
                }

                throw $e;
            }
        }

        $aql = "{$table} { * }";
        $clause = array(
            'where' => "{$table}.id = {$id}"
        );

        return self::select($aql, $clause, null, null, null, $dbw);
    }

    /**
     * @return  string
     * @global  $db_platform
     */
    public static function get_db_platform()
    {
        global $db_platform;
        return $db_platform;
    }

    /**
     * @return  Boolean
     */
    public static function db_is_postgres()
    {
        return strpos(self::get_db_platform(), 'postgres') !== false;
    }

    /**
     * Updates a record in the database
     * If in a transaction or silent, errors/exceptions will be added to self::$errors
     * @param   string  $table
     * @param   array   $fields (associative)
     * @param   string  $id     id | ide
     * @param   Boolean $silent
     * @return  Boolean
     * @throws  \Sky\AQL\Exception              if invalid ID
     * @throws  \Sky\AQL\TransactionException  on update failure
     */
    public function update($table, $fields, $identifier, $silent = false)
    {
        if (!self::hasMasterDB()) {
            return false;
        }

        if (self::in_transaction()) {
            $silent = true;
        }

        $id = (is_numeric($identifier))
            ? $identifier
            : decrypt($identifier, $table);

        if (!is_numeric($id) || !$id) {
            throw new \Sky\AQL\Exception(
                "Update Error: invalid identifier [{$identifier}] for table [{$table}]."
            );
        }

        if (!is_array($fields) || !$fields) {
            return false;
        }

        $dbw = self::getMasterDB();
        $result = $dbw->AutoExecute($table, $fields, 'UPDATE', 'id = ' . $id);
        if ($result === true) {
            return true;
        }

        $e = new \Sky\AQL\TransactionException(
            $table,
            $fields,
            $id,
            $dbw
        );

        if ($silent) {
            $e->sendErrorEmail();
            aql::$errors[] = $e;
        } else {
            throw $e;
        }
    }

    /**
     * @param   string  $param1
     * @param   string  $param2
     * @param   mixed   $options
     * @return  mixed
     */
    public static function value($param1, $param2, $options = array())
    {
        if (!$param2) {
            return null;
        }

        // third param can be a db connection resource
        if (is_object($options) && get_class($options) == 'ADODB_postgres7') {
            $db_conn = $options;
            $options = array();
        }

        // get connection
        $db_conn = $db_conn ?: $options['db'];
        $db_conn = $db_conn ?: self::getDB();

        $is_aql = aql::is_aql($param1);

        // normalize primary table and aql
        if ($is_aql) {
            $aql = $param1;
            $primary_table = aql::get_primary_table($aql);
        } else {
            list($primary_table, $field) = explode('.',$param1);
            $aql = "$primary_table { $field }";
        }

        // get where
        $multiple = false;
        $where = call_user_func(function() use($primary_table, $param2, &$multiple) {

            $spr = '%s.%s = \'%s\'';

            $decrypt = function($r) use($primary_table)  {
                return (is_numeric($r)) ? $r : decrypt($r, $primary_table);
            };

            if (is_numeric($param2)) {
                return sprintf($spr, $primary_table, 'id', $param2);
            }

            if (!is_array($param2)) {

                // check for ide
                $id = $decrypt($param2);
                if (is_numeric($id)) {
                    return sprintf($spr, $primary_table, 'id', $id);
                }

                // otherwise check for slug field on table
                if (!aql2array::table_field_exists($primary_table, 'slug')) {
                    return;
                }

                return sprintf($spr, $primary_table, 'slug', $param2);
            }

            // this is an array
            $multiple = true;

            $param2 = array_filter(array_map($decrypt, $param2));
            $param2[] = -1;

            $ids = implode(',', $param2);
            return "{$primary_table}.id in ({$ids})";
        });

        // return if we dont find a where clause
        if (!$where) {
            return false;
        }

        $clause = array(
            $primary_table => array(
                'where' => array($where),
                'order by' => 'id asc'
            )
        );

        $rs = aql::select($aql, $clause, null, null, null, $db_conn);

        if ($multiple) {
            return $rs;
        }

        if ($is_aql) {
            return $rs[0];
        }

        return $rs[0][$field];
    }

    ######################################################################################
    ##                                TRANSACTION FUNCTIONS                             ##
    ######################################################################################

    /**
     * @global  $db
     * @return  ADodb connection | null
     */
    public static function getDB()
    {
        global $db;
        return $db;
    }

    /**
     * @global  $dbw
     * @return ADODB Connection | null
     */
    public static function getMasterDB()
    {
        global $dbw;
        return $dbw;
    }

    /**
     * @return  Boolean
     */
    public static function hasMasterDB()
    {
        return (bool) self::getMasterDB();
    }

    /**
     * Starts a transaction
     */
    public static function start_transaction()
    {
        if (!self::hasMasterDB()) {
            return;
        }

        self::$errors = array();
        self::getMasterDB()->StartTrans();
    }

    /**
     * Finsihes a transaction
     */
    public static function complete_transaction()
    {
        if (!self::hasMasterDB()) {
            return;
        }

        self::getMasterDB()->CompleteTrans();
    }

    /**
     * @return  Boolean
     */
    public static function transaction_failed()
    {
        if (!self::hasMasterDB()) {
            return true;
        }

        return self::getMasterDB()->HasFailedTrans();
    }

    /**
     * Flags transaction as failed
     */
    public static function fail_transaction()
    {
        if (!self::hasMasterDB()) {
            return;
        }

        self::getMasterDB()->FailTrans();
    }

    /**
     * @return  Boolean
     */
    public static function in_transaction()
    {
        if (!self::hasMasterDB()) {
            return false;
        }

        return self::getMasterDB()->transOff;
    }

    ######################################################################################
    ##                                 HELPER FUNCTIONS                                 ##
    ##      below this line // you probably don't want to use any of them on their own  ##
    ######################################################################################

    /**
     * Given the aql array, append table names to strings in the clause aray if they are
     * missing
     * @param   array   $aql_array
     * @param   array   $clause_array
     */
    public static function check_clause_array($aql_array, $clause_array)
    {
        $first = reset($aql_array);
        $clauses = aql2array::$clauses;
        $comparisons = aql2array::$comparisons;

        foreach ($clause_array as $k => $v) {
            if (in_array($k, $clauses)) {
                $clause_array = array(
                    $first['as'] => $clause_array
                );
                break;
            }
        }

        foreach ($clause_array as $table => $v) {

            if (!is_array($v)) {
                continue;
            }

            foreach ($v as $clause => $value) {

                if ($clause == 'where') {

                    $value = (is_array($value)) ? $value : array($value);
                    $arr = aql2array::prepare_where($value, $aql_array[$table]['table']);
                    $clause_array[$table][$clause] = aql2array::check_where(
                        $arr,
                        $aql_array[$table]['as']
                    );

                } else {

                    $value = (is_array($value)) ? $value : explodeOnComma($value);
                    $clause_array[$table][$clause] = aql2array::check_clause(
                        $value,
                        $aql_array[$table],
                        $aql_array[$table]['fields']
                    );

                }

            }
        }

        return $clause_array;
    }

    /**
     * Loops through the associative array looking for keys that end with _id
     * If they look like they are tablename_id, adds tablename_ide
     * @param   array   $r
     * @return  array
     */
    public function generate_ides($r)
    {
        if (!is_array($r)) {
            return array();
        }

        foreach ($r as $k => $v) {
            if (preg_match('/_id$/', $k)) {
                $key = self::get_decrypt_key($k);
                if ($v && $key) {
                    $r[$k . 'e'] = encrypt($v, $key);
                }
            }
        }

        return $r;
    }

    /**
     * @param   string  $field_name
     * @return  string
     */
    public function get_decrypt_key($field_name)
    {
        $count = -4;
        $table_name = substr($field_name, $count);
        if ($table_name != '_ide') {

            $count = -3;
            $table_name = substr($field_name, $count);
            if ($table_name != '_id') {
                return null;
            }
        }

        $temp = substr($field_name, 0, $count);
        $start = strpos($temp, '__');

        if ($start) {
            $start += 2;
        }

        return substr($temp, $start);
    }

    /**
     * @param   string  $aql
     * @return  string
     */
    public function get_primary_table($aql)
    {
        $aql = (self::is_aql($aql)) ? $aql : self::get_aql($aql);
        $t = new aql2array($aql, false);
        return $t->get_primary_table();
    }

    /**
     * Includes the class looking for it in $sky_aql_model_path
     * @param   string  $model_name
     * @global  $sky_aql_model_path
     */
    public static function include_class_by_name($model_name)
    {
        if (class_exists($model_name)) {
            return;
        }
        global $sky_aql_model_path;
        $path = $sky_aql_model_path.'/'.$model_name.'/class.'.$model_name.'.php';
        if (file_exists_incpath($path)) {
            include $path;
        }
    }

    /**
     * Checks if a string is AQL
     * @param   string  $aql
     * @return  Boolean
     */
    public static function is_aql($aql)
    {
        return strpos($aql, '{') !== false;
    }

    /**
     * Executes the actual select query based on sql_array and settings,
     * It will execute recursively based on the given array
     * depending on if there are "subs" or objects and their respective sql_arrays and aql
     *
     * @see self::sql()
     * @param   array   $arr    generated sql array
     *                  - sql
     *                  - sql_list
     *                  - sql_count
     *                  - subs
     *                  - objects
     * @param   array   $settings
     *                  - object (bool)
     *                  - aql_statement (string)
     *                  - select_type (string) default is 'sql'
     * @param   db      $db_conn
     * @return  array
     * @throws  \Sky\AQL\ConnectionException    if no db
     * @throws  \Sky\AQL\SelectException        if db select fails
     */
    private static function sql_result($arr, $settings, $db_conn = null)
    {
        if (!$db_conn) {
            $db_conn = self::getDB();
        }

        if (self::in_transaction()) {
            $db_conn = self::getMasterDB();
            $silent = true;
        }

        if (!$db_conn) {
            throw new \Sky\AQL\ConnectionException(
                'Cannot Execute AQL without a db connection'
            );
        }

        $object = $settings['object'];
        $aql_statement = $settings['aql_statement'];
        $sub_do_set = $settings['sub_do_set'];
        $select_type = ($settings['select_type']) ?: 'sql';

        $rs = array();
        $r = $db_conn->Execute($arr[$select_type]);

        if ($r === false) {

            $e = new \Sky\AQL\SelectException(
                $aql_statement,
                $arr[$select_type],
                $db_conn->ErrorMsg()
            );

            if (!$silent) {
                throw $e;
            } else {
                aql::$errors[] = $e;
            }

            return $rs;
        }

        while (!$r->EOF) {

            $tmp = self::generate_ides($r->GetRowAssoc(false));

            $placeholder = null;
            $get_placeholder = function($m) use($tmp, &$placeholder) {
                return $placeholder = $tmp[$m[1]];
            };

            $replace_placeholder = function($clause) use($get_placeholder) {
                return preg_replace_callback(
                    '/\{\$([\w.]+)\}/',
                    $get_placeholder,
                    $clause
                );
            };

            if ($arr['subs']) {

                foreach ($arr['subs'] as $k => $s) {

                    $s['sql'] = $replace_placeholder($s['sql']);

                    if ($placeholder) {
                        $params = array(
                            'object' => $object,
                        );

                        $tmp[$k] = self::sql_result($s, $params, $db_conn);
                    }

                }
            }

            $placeholder = '';
            if ($arr['objects']) {
                foreach ($arr['objects'] as $k => $s) {

                    $m = $s['model'];
                    if ($s['plural'] && $s['sub_where']) {

                        $clauses = self::get_clauses_from_model($m);
                        $min_aql = self::get_min_aql_from_model($m);
                        $clauses['where'][] = $replace_placeholder($s['sub_where']);

                        $query = aql::select(
                            $min_aql,
                            $clauses,
                            null,
                            null,
                            $sub_do_set,
                            $db_conn
                        );

                        if ($query) {
                            foreach ($query as $row) {
                                $arg = $row[$s['constructor argument']];
                                $o = Model::get($m, $arg, $sub_do_set);
                                $tmp[$k][] = ($object) ? $o : $o->dataToArray();
                            }
                        }
                    } else if (!$s['plural']) {
                        $arg = (int) $tmp[$s['constructor argument']];
                        if ($arg) {
                        	$o = Model::get($m, $arg, $sub_do_set);
	                        $tmp[$k] = ($object) ? $o : $o->dataToArray();
                        }
                    }
                }
            }

            if ($object && $aql_statement) {

                $tmp_model = ($object === true)
                    ? new Model(null, $aql_statement)
                    : Model::get($object);

                $tmp_model->loadArray($tmp);
                $tmp_model->_token = $tmp_model->getToken();

                $tmp = $tmp_model;
            }

            $rs[] = $tmp;

            $r->moveNext();
        }

        return $rs;
    }

    /**
     * Recursively generates sql statements from the aqlarray
     * @param   array   $arr
     * @param   array   $clause_array
     * @return  array
     * @throws  \Sky\AQL\Exception   If there are aql errors
     */
    public function make_sql_array($arr, $clause_array = array()) {

        if (count($arr) == 0) {
            throw new \Sky\AQL\Exception('AQL Error: You have an error in your syntax.');
        }

        // set up vars
        $has_aggregate = $distinct = false;
        $joins = $from = $limit = $offset = '';
        $fields = $left_joined
                = $where
                = $objects
                = $order_by
                = $group_by
                = $fk
                = array();


        foreach ($arr as $t) {

            $table_name = $t['table'];

            if ($t['as'] && $table_name != $t['as']) {
                $table_name .= ' as '. $t['as'];
            }

            if (!$t['on']) {

                if ($from) {

                    $error = sprintf(
                        'AQL Error: [%s as %s] needs to have a left join.
                        You cannot have more than one primary table.',
                        $t['table'],
                        $t['as']
                    );

                    throw new \Sky\AQL\Exception($error);
                }

                $from = $table_name;
                $primary_table = $t['table'];
                $aliased_from = $t['as'];
                $where[] = $t['as'] . '.active = 1';

            } else {

                $left_joined[] = $t['table'];
                $joins .= sprintf(
                    'LEFT JOIN %s on %s and %s.active = 1 ',
                    $table_name,
                    $t['on'],
                    $t['as']
                ) . PHP_EOL;

            }

            // add where
            $where = array_merge($where, $t['where']);

            if (is_array($t['aggregates']) && count($t['aggregates'])) {
                $has_aggregate = true;
                $fields = array_merge($fields, $t['aggregates']);
            }

            if (is_array($t['objects'])) {
                $objects = array_merge($objects, $t['objects']);
            }

            if (is_array($t['fields'])) {
                $fields = array_merge($fields, $t['fields']);
            }

            if (is_array($t['subqueries'])) {
                $subs = array();
                foreach ($t['subqueries'] as $k => $q) {
                    $subs[$k] = self::make_sql_array($q, $clause_array);
                }
            }

            if (is_array($t['group by'])) {
                $group_by = array_merge($group_by, $t['group by']);
            }

            if (is_array($t['order by'])) {
                $order_by = array_merge($order_by, $t['order by']);
            }

            if ($t['limit']) {
                $limit = $t['limit'];
            }

            if ($t['offset']) {
                $offset = $t['offset'];
            }

            if ($t['distinct']) {
                $distinct = $t['distinct'];
            }

            if ($t['primary_distinct']) {
                $primary_distinct = true;
            }

            if (is_array($t['fk'])) {
                foreach ($t['fk'] as $f_k) {
                    $fk[$f_k][] = $t['table'];
                }
            }

            if ($clause_array[$t['as']]) {
                if (is_array($clause_array[$t['as']]['where'])) {
                    $where = array_merge($where, $clause_array[$t['as']]['where']);
                }

                if (is_array($clause_array[$t['as']]['order by'])) {
                    $order_by = array_merge($order_by, $clause_array[$t['as']]['order by']);
                }

                if (is_array($clause_array[$t['as']]['group by'])) {
                    $group_by = array_merge($group_by, $clause_array[$t['as']]['group by']);
                }

                if ($clause_array[$t['as']]['limit'][0]) {
                    $limit = $clause_array[$t['as']]['limit'][0];
                }

                if ($clause_array[$t['as']]['offset'][0]) {
                    $offset = $clause_array[$t['as']]['offset'][0];
                }
            }

        } // end foreach table block

        if ($distinct) {
            $no_ids = true;
        }

        if (!$has_aggregate && !$no_ids) {

            foreach ($arr as $t) {
                $fields[$t['table'].'_id'] = "{$t['as']}.id";
            }

        } else if ($has_aggregate) {

            foreach ($arr as $t) {

                if (is_array($t['fields'])) {
                    foreach ($t['fields'] as $k => $v) {
                        if (!preg_match('/(case|when)/', $v)) $group_by[] = $v;
                    }
                }

                if ($t['order by']) {
                    foreach ($t['order by'] as $k => $v) {
                        $tmp = str_replace(array(' asc', ' desc', ' ASC', ' DESC'),'', $v);
                        if (trim($tmp)) {
                            if (!preg_match('/(case|when)/', $tmp)) {
                                $group_by[] = $tmp;
                            }
                        }
                    }
                }
            }
        }

        $ff = $fields;
        $fields = array();
        foreach ($ff as $alias => $field) {
            if ($field) {
                $fields[] = ($field != $alias)
                    ? sprintf('%s as "%s"', $field, $alias)
                    : $field;
            }
        }
        $fields_text = implode(", \n ", $fields);

        $where = array_filter($where);
        $where_text = ($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $group_by = array_filter(array_unique($group_by));
        $group_by_text = ($group_by) ? 'GROUP BY ' . implode(', ', $group_by) : '';

        $order_by = array_filter(array_unique($order_by));
        $order_by_text = ($order_by) ? 'ORDER BY ' . implode(', ', $order_by) : '';

        $limit = ($limit) ? 'LIMIT ' . $limit : '';
        $offset = ($offset) ? 'OFFSET ' . $offset : '';

        $sql = "SELECT {$distinct}
                    {$fields_text}
                FROM {$from}
                {$joins}
                {$where_text}
                {$group_by_text}
                {$order_by_text}
                {$limit}
                {$offset}";

        $sql_count = "SELECT
                        count(*) as count
                      FROM {$from}
                      {$joins}
                      {$where_text}";

        $sqllist = "SELECT id, id as {$aliased_from}_id
                    FROM (  SELECT DISTINCT on (q.id) id, row
                            FROM
                            (   SELECT
                                    $aliased_from.id,
                                    row_number() OVER($order_by_text) as row
                                FROM {$from}
                                {$joins}
                                {$where_text}
                                {$order_by_text}
                            ) as q
                        ) as fin
                    ORDER BY row
                    {$limit}
                    {$offset}";

        if ($primary_distinct) {
            $sql = $sql_list;
        }

        return array(
            'sql' => $sql,
            'sql_count' => $sql_count,
            'sql_list' => $sqllist,
            'subs' => $subs,
            'objects' => $objects,
            'primary_table' => $primary_table,
            'left_joined' => $left_joined,
            'fk' => $fk
        );
    }

}
