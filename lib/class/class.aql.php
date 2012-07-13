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

    RETRIEVAL FUNCTIONS

        self::get_aql()
            @return     (string) aql
            @params     (string) model_name

        self::profile()
            @return     (array) db recordset or null
            @params     (mixed) model name or aql or aql_array
                        (varying) identifier - could be IDE or id.

        self::select()
            @return     (array) nested db recordset or null
            @params     (mixed) $aql or model name or aql_array
                        (array) clause_array

        self::sql()
            @return     (array) pre executed sql array with subqueries
            @params     (string) aql
                        (array) clause_array

**/

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

**/

    public function get_clauses_from_model($model_name) {
        $clauses = array('where' => array(), 'order by' => array());
        $arr = aql2array::get($model_name);
        foreach ($arr as $t) {
            $clauses['where'] += $t['where'];
            $clauses['order by'] += $t['order by'];
        }
        return $clauses;
    }

    public function get_min_aql_from_model($model_name) {
        $arr = aql2array::get($model_name);
        $aql = '';
        $i = 0;
        foreach ($arr as $t) {
            $aql .= "{$t['table']} as {$t['as']}";
            if ($t['on']) $aql .= " on {$t['on']}";
            if ($i === 0) $aql .= " { id } \n";
            else $aql .= " { } \n";
            $i++;
        }
        return $aql;
    }

    public function get_aql($model_name) {
        global $codebase_path_arr, $sky_aql_model_path;
        $return = null;
        foreach ($codebase_path_arr as $codebase_path) {
            $model = $codebase_path.$sky_aql_model_path.'/'.$model_name.'/'.$model_name.'.aql';
            if (file_exists($model)) {
                $return = @file_get_contents($model);
                break;
            }
        }
        return $return;
    }

    public function now() {
        global $dbw;
        $sql = "SELECT CURRENT_TIMESTAMP as now";
        $r = $dbw->Execute($sql);
        return $r->Fields('now');
    }

/**

**/
    public function profile($param1, $param2, $param3 = false, $aql_statement = null, $sub_do_set = false, $db_conn = null) {
        if (is_array($param1)) {
            $aql = $param1;  // this is the aql_array

        } else if (!self::is_aql($param1))  {
            $aql_statement = ($aql_statement) ? $aql_statement : self::get_aql($param1);
            $model = $param1;
            $param3 && $param3 = $model;
            $aql = aql2array::get($model, $aql_statement);
        } else {
            $aql_statement = $param1;
            $aql = aql2array($param1);
        }

        if ($aql) {
            $model_name_arr = reset($aql);
            $model = $model_name_arr['as'];
            if (!is_numeric($param2)) $id = decrypt($param2, $model);
            else $id = $param2;

            if (!is_numeric($id)) return false;
            $clause = array(
                $model => array(
                    'where' => array(
                        $model.'.id = '.$id
                    )
                )
            );
            $rs = self::select($aql, $clause, $param3, $aql_statement, $sub_do_set, $db_conn);
            return $rs[0];
        } else {
            return false;
        }
    }
/**

**/
    public function select($aql, $clause_array = null, $object = false, $aql_statement = null, $sub_do_set = false, $db_conn = null) {
        global $db, $is_dev;
        if (!$db_conn) $db_conn = $db;

        $silent = null;
        if (aql::in_transaction()) $silent = true;

        if (!is_array($clause_array) && $clause_array === true) $object = true;

        if (!is_array($aql)) {
            if (!self::is_aql($aql)) {
                $m = $aql;
                $aql_statement = self::get_aql($m);
                if (!$aql_statement && !$silent) {
                    throw new Exception(
                        ' AQL Error: Model '. $m .' is not defined. Could not get AQL statment. '
                        . PHP_EOL
                        . "path/to/models/$m/$m.aql is empty or not found.");
                    return;
                }
                $aql_array = aql2array::get($m, $aql_statement);
            } else {
                $aql_statement = $aql;
                $aql_array = aql2array($aql_statement);
            }
            if (!$aql) return null;
        } else {
            $aql_array = $aql;
        }

        if ($object) { if ($object !== true && $m) $object = $m; }

        if (is_array($clause_array)) $clause_array = self::check_clause_array($aql_array, $clause_array);
        if ($_GET['aql_debug'] && $is_dev) print_a($aql_array);
        $returned = self::make_sql_array($aql_array, $clause_array);
        if ($_GET['aql_debug'] && $is_dev) print_a($returned);
        if ($_GET['refresh'] == 1) $sub_do_set = true;
        $params = array(
            'object' => $object,
            'aql_statement' => $aql_statement,
            'sub_do_set' => $sub_do_set
        );
        return self::sql_result($returned, $params, $db_conn);
    }

    public function count($aql, $clause_array = null) {
        $sql = aql::sql($aql, $clause_array);
        return aql::sql_result($sql, array(
            'select_type' => 'sql_count'
        ));
        $sql = $sql['sql_count'];
        $r = sql($sql);
        return $r->Fields('count');
    }

    public function listing($aql, $clause_array = null) {
        $sql = aql::sql($aql, $clause_array);
        return aql::sql_result($sql, array('select_type' => 'sql_list'));
    }

    public function selectDBW($aql, $clause_array = null, $object = false, $aql_statement = null, $sub_do_set = false) {
        global $dbw;
        return aql::select($aql, $clause_array, $object, $aql_statement, $sub_do_set, $dbw);
    }
/**

**/
    public function sql($aql, $clause_array = null) {
        if (!is_array($aql)) $aql = aql2array($aql);
        if (is_array($clause_array)) $clause_array = self::check_clause_array($aql, $clause_array);
        return self::make_sql_array($aql, $clause_array);
    }


/**

    INPUT FUNCTIONS

        self::insert()
            @param      (string) table name
            @param      (array) fields
            @return     (array) inserted recordset or null

        self::update()
            @param      (string) table name
            @param      (array) fields
            @param      (string) identifier
            @return     (bool)

**/

    public function increment($param1, $param2, $param3, $silent = false) {
        global $dbw;
        if (!$dbw) return false;

        if (aql::in_transaction()) $silent = true;

        list($table, $field) = explode('.',$param1);
        $id = (is_numeric($param3)) ? $param3 : decrypt($param3, $table);
        if (!is_numeric($id)) {
            if (!$silent) {
                throw new Exception('AQL Error: Third parameter of aql::increment is not a valid idenitifer.');
            }
            return false;
        }
        if (!$table && $field) {
            if (!$silent) {
                throw new Exception('AQL Error: First paramter of aql::increment needs to be in the form of table_name.field_name');
            }
            return false;
        }
        if (strpos($param2, '-') !== false) $do = ' - '.abs($param2);
        else $do = ' + '.$param2;
        $sql =  "UPDATE {$table} SET {$field} = {$field} {$do} WHERE id = {$id}";
        $r = $dbw->Execute($sql);
        if ($r === false) {
            if (!$silent) {
                throw new Exception('AQL Error: aql::increment failed. ' . $dbw->ErrorMsg());
            }
            return false;
        } else {
            return true;
        }
    }
/**

**/
    public function insert($table, $fields, $silent = false) {
        global $dbw, $db_platform, $aql_error_email;

        if (aql::in_transaction()) $silent = true;

        if (!$dbw) {
            return false;
        }
        if (!is_array($fields)) {
            if (!$silent) {
                throw new Exception('aql::insert expects a [fields] array.');
            }
            return false;
        }
        foreach ($fields as $k => $v) {
            if ($v === null || $v === '') unset($fields[$k]);
        }

        unset($fields['id']);

        if (!$fields) {
            if (!$silent) {
                throw new Exception('aql::insert was not populated with fields.');
            }
            return false;
        }
        $result = $dbw->AutoExecute($table, $fields, 'INSERT');

        if ($result === false) {
            if ($aql_error_email) {
                $bt = debug_backtrace();
                $trace = array_map(function($i) {
                    return 'File:' .$i['file'].' Line: '.$i['line'];
                }, $bt);
                @mail($aql_error_email, "<pre>Error inserting into table [$table]" , "[insert into $table] " . $dbw->ErrorMsg() . "\n\n<br />" . $bt[1]['file'] . "\n<br />Line: " . $bt[1]['line'] .'<br />'. print_r($fields,1) . '<br />Stack Trace: <br />' . print_r($trace, true) .'</pre>', "From: Crave Tickets <info@cravetickets.com>\r\nContent-type: text/html\r\n");
            }
            if (!$silent) {
                echo "[Insert into {$table}] ".$dbw->ErrorMsg()." ".self::error_on();
                print_a($fields);
                if ( strpos($dbw->ErrorMsg(), 'duplicate key') === false ) {
                    throw new Exception('AQL Error');
                }
            }
            if (aql::in_transaction()) {
                aql::$errors[] = array(
                    'message' => $dbw->ErrorMsg(),
                    'fields' => $fields,
                    'table' => $table
                );
            }
            return false;
        } else {
            if (strpos($db_platform, 'postgres') !== false) {
                $sql = "SELECT currval('{$table}_id_seq') as id";
                $s = $dbw->Execute($sql);
                if ($s === false) {
                    if (aql::in_transaction() || $silent) {
                        aql::$errors[] = array(
                            'message' => 'getID() error',
                            'sql' => $sql
                        );
                    }
                    if (!$silent) {
                        throw new Exception("<p>$sql<br />".$dbw->ErrorMsg()."<br />$table.id must be of type serial.");
                    } else {
                        return false;
                    }
                } else {
                    $id = $s->Fields('id');
                }
            } else {
                $id = $dbw->Insert_ID();
            }
            $aql = " $table { * } ";
            return self::select($aql, array(
                'where' => "{$table}.id = {$id}"
            ), null, null, null, $dbw);
        }
    }

/**

**/
    /**
     * @param   string  $table
     * @param   array   $fields (associative)
     * @param   string  $id     id | ide
     * @param   Boolean $silent
     * @global  $aql_error_email
     * @return  Boolean
     */
    public function update($table, $fields, $identifier, $silent = false)
    {
        global $aql_error_email;

        if (!self::hasMasterDB()) {
            return false;
        }

        if (self::in_transaction()) {
            $silent = true;
        }

        $id = (is_numeric($identifier)) ? $identifier : decrypt($identifier, $table);

        if (!is_numeric($id)) {
            throw new \Sky\AQL\Exception(
                "Update Error: invalid identifier [{$identifier}] for table [{$table}]."
            );
        }

        if (is_array($fields) && $fields) {

            $dbw = self::getMasterDB();
            $result = $dbw->AutoExecute($table, $fields, 'UPDATE', 'id = ' . $id);

            if ($result === false) {

                $error = $dbw->ErrorMsg();

                $e = new \Sky\AQL\UpdateException()

                if ($aql_error_email) {
                    @mail(
                        $aql_error_email,
                        'AQL Update Error',
                        "[ update table:[$table] id:[$id] ] $error " . print_r($fields, true)
                        . "<br />"
                    );
                }

                $aql_error_email && @mail($aql_error_email, 'AQL Update Error', "" . $dbw->ErrorMsg() . print_r($fields,1).'<br />'.self::error_on(). '<br />Stack Trace: <br />' . print_r($bt, true) .'</pre>', "From: Crave Tickets <info@cravetickets.com>\r\nContent-type: text/html\r\n");

                if (self::in_transaction() || $silent) {
                    self::$errors[] = array(
                        'message' => $dbw->ErrorMsg(),
                        'table' => $table,
                        'fields' => $fields,
                        'id' => $id
                    );
                }

                if (!$silent) {
                    echo "[update $table $id] " . $dbw->ErrorMsg() . "<br>".self::error_on();
                    print_a( $fields );
                    trigger_error('', E_USER_ERROR);
                } else {
                    return false;
                }
            } else {
                return true;
            }
        } else return false;
    }

    /**
     * @param   string  $param1
     * @param   string  $param2
     * @param   mixed   $options
     * @return  mixed
     * @global  $db
     */
    public static function value($param1, $param2, $options = array())
    {
        if (!$param2) {
            return null;
        }

        global $db;

        // third param can be a db connection resource
        if (is_object($options) && get_class($options) == 'ADODB_postgres7') {
            $db_conn = $options;
            $options = array();
        }

        // get connection
        $db_conn = $db_conn ?: $options['db'];
        $db_conn = $db_conn ?: $db;

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
                    $r[$k.'e'] = encrypt($v, $table_name);
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
     * @param   array   $arr    generated sql array
     * @param   array   $settings
     * @param   db      $db_conn
     * @return  array
     * @global  $db
     * @global  $dbw
     * @throws  AQLException        if no db
     * @throws  AQLSelectException  if db select fails
     */
    public static function sql_result($arr, $settings, $db_conn = null)
    {
        global $db, $dbw;
        if (!$db_conn) {
            $db_conn = $db;
        }

        if (self::in_transaction()) {
            $db_conn = $dbw;
            $silent = true;
        }

        if (!$db_conn) {
            throw new AQLException('Cannot Execute AQL without a db connection');
        }

        $object = $settings['object'];
        $aql_statement = $settings['aql_statement'];
        $sub_do_set = $settings['sub_do_set'];
        $select_type = ($settings['select_type']) ?: 'sql';

        $rs = array();
        $r = $db_conn->Execute($arr[$select_type]);

        if ($r === false) {

            if (!$silent) {

                throw new AQLSelectException(
                    $aql_statement,
                    $arr[$select_type],
                    $db_conn->ErrorMsg()
                );

            } else {

                if (aql::in_transaction()) {

                    aql::$errors[] = array(
                        'message' => $db_conn->ErrorMsg(),
                        'sql' => $arr[$select_type]
                    );
                }

            }

            return $rs;
        }

        while (!$r->EOF) {

            $tmp = self::generate_ides($r->GetRowAssoc(false));

            $placeholder = null;
            $get_placeholder = function($m) use($tmp, &$placeholder) {
                $placeholder = $tmp[$m[1]];
            };

            $replace_placeholder = function($clause) use($get_placeholder) {
                return preg_replace('/\{\$([\w.]+)\}/', $get_placeholder, $clause);
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

                        $query = aql::select($min_aql, $clauses, null, null, $sub_do_set, $db_conn);
                        if ($query) {
                            foreach ($query as $row) {
                                $arg = $row[$s['constructor argument']];
                                $o = Model::get($m, $arg, $sub_do_set);
                                $tmp[$k][] = ($object) ? $o : $o->dataToArray();
                            }
                        }
                    } else {
                        $arg = (int) $tmp[$s['constructor argument']];
                        $o = Model::get($m, $arg, $sub_do_set);
                        $tmp[$k] = ($object) ? $o : $o->dataToArray();
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
     * @throws  Exception   If there are aql errors
     */
    public function make_sql_array($arr, $clause_array = array()) {

        if (count($arr) == 0) {
            throw new Exception('AQL Error: You have an error in your syntax.');
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

                    throw new Exception($error);
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
        }// end foreach table block

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
