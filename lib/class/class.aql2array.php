<?php

/**
 * The AQL Parser
 * Usage:
 *
 *      $paraser = new aql2array($aql);
 *      $parsed = $parser->aql_array;
 *      // OR
 *      $parsed = aql2array($aql); // in functions.inc.php
 *
 * @package SkyPHP
 */
class aql2array
{

    /**
     * Flag for if caching aqlarrays
     * @var Boolean
     */
    public static $use_mem = true;

    /**
     * Cache duration
     * @var string
     */
    public static $mem_duration = '1 day';

    /**
     * Cache type: mem | disk
     * @var string
     */
    public static $mem_type = 'mem';

    /**
     * Storage for column info in tables queried
     * @var array
     */
    public static $metaColumns = array();

    /**
     * Storage for already generated aqlarrays
     * @var array
     */
    public static $aqlArrays = array();

    /**
     * Storage for aql statements
     * @var array
     */
    public static $aqls = array();

    /**
     * The main regular expression pattern for an aql block
     * Matches table declaration with (primary) distinct on/as
     * Used to split table declarations
     * @var string
     */
    public static $pattern = '/(?:
        (?:^|\s*)(?:\'[\w-.\s]*\s*)*                        # beginning white space
        (?<distinct>(?<primary_distinct>primary_)*          # (primary) distinct
            distinct\s+                                     # distinct
            (?:\bon\b\s+\([\w.]+\)\s+)*)*                   # on
        (?<table_name>\w+)?                                 # table name
        (?<table_on_as>                                     # alias or join
            \s+(?:\bon\b|\bas\b)\s+[\w-\(\).=\s\']+)*\s*    # alias or join content
        \{(?<inner>[^\{\}]+|(?R))*\}                        # recursive { }
        (?:,)?                                              # multiple queries
        (?:[\w-.!\s]*\')*)(?=(?:(?:(?:[^"\\\']++|\\.)*+\'){2})*+
        (?:[^"\\\']++|\\.)*+                                # not in quotes
    $)/xsi';

    /**
     * Matches if we have a join
     * @var string
     */
    public static $on_pattern = '/(\bon\b(?<on>.+))(\bas\b)*/mis';

    /**
     * Matches alias
     * @var string
     */
    public static $as_pattern = '/(\bas\b(?<as>\s+[\w]+))(\bon\b)*/mis';

    /**
     * Matches [model(id)]s
     * @var string
     */
    public static $object_pattern = '/
        \[                                              # opening bracket
            (?<model>[\w]+)                             # model name
            (?:\((?<param>[\w.$]+)*\))*                 # argument (id)
        \]                                              # closing bracket
        (?<sub>s)?                                      # plural?
        (?:\s+as\s+(?<as>[\w]+))*                       # has alias?
    /x';

    /**
     * Matches sql functions: fn(...)
     * @var string
     */
    public static $aggregate_pattern = '/
        (?<pre>[^\w]+)                                  # anything before the fn name
        (?<function>[\w]+)                              # function name
            \((?<fields>([^)]+)?(?:[+-\s*]+)*([\w.]+)?) # insides
        \)                                              # closing brace
        (?<post>\s*[-+*])*                              # - or + or *
    /xmi';

    /**
     * Append to expression to make sure content not in quotes
     * @link http://stackoverflow.com/questions/1191397/regex-to-match-values-not-surrounded-by-another-char
     * @deprecated for use of state machine
     * @var string
     */
    public static $not_in_quotes = "(?=(?:(?:(?:[^\"\\']++|\\.)*+\'){2})*+(?:[^\"\\']++|\\.)*+$)";

    /**
     * Array of clause strings in the order they should appear in the AQL
     * @var array
     */
    public static $clauses = array(
        'where',
        'group by',
        'order by',
        'having',
        'limit',
        'offset'
    );

    /**
     * Ignore these words when found in the AQL, they are reserved
     * _T_AQL_ESCAPED_ is a replacement for escaped quotes
     * @var array
     */
    public static $comparisons = array(
        '*', '-', '+', '=', '!', '\\',
        '_T_AQL_ESCAPED_',
        'CASE', 'case',
        'WHEN', 'when',
        'END', 'end',
        'LENGTH', 'length',
        'ILIKE', 'ilike',
        'LIKE', 'like',
        'DISTINCT', 'distinct',
        'SELECT', 'select',
        'WHERE', 'where',
        'FROM', 'from',
        'THEN', 'then',
        'ELSE', 'else',
        'UPPER', 'upper',
        'LOWER', 'lower',
        'AND', 'and',
        'OR', 'or',
        'IS', 'is',
        'NULL', 'null',
        'IN', 'in',
        'NOT','not',
        'TRUE', 'true',
        'false','FALSE',
        'now()','NOW()',
        'ASC', 'asc',
        'DESC', 'desc',
        'INTERVAL', 'interval',
        'TRIM', 'trim',
        'TO_CHAR', 'to_char',
        'DATE_FORMAT', 'date_format'
    );

    /**
     * comment patterns to strip out
     * @var array
     */
    public static $comment_patterns = array(
        'slashComments' => "/\/\/(?=(?:(?:(?:[^\"\\']++|\\.)*+\'){2})*+(?:[^\"\\']++|\\.)*+.*$)$/m",
        'multiComments' => '/\/\*[\s\S]*?\*\//m',
        //  'poundComments' => '/#.*$/m',
    );

    /**
     * Input string
     * @var string
     */
    public $aql = '';

    /**
     * Generated array
     * @var array
     */
    public $aql_array;

    /**
     * if ($run) then the aql string is parsed
     * @param   string  $aql
     * @param   Boolean $run    default is true
     */
    public function __construct($aql, $run = true)
    {
        // strip comments
        $aql = $this->strip_comments($aql);

        // remove extra whitespace
        $this->aql = $this->prepAQL($aql);

        if (!$run) {
            return;
        }

        $key = $this->getMemKey($this->aql);
        $duration = self::$mem_duration;
        $store_arr = self::_getStoreFn($key, $duration);
        $fetch_fn = self::_getFetchFn($key);

        if (!self::$use_mem || $_GET['refresh']) {
            $this->aql_array = $this->init($this->aql);
            if (self::$use_mem) {
                $store_arr($this->aql_array);
            }
        } else {
            $this->aql_array = $fetch_fn();
            if (!$this->aql_array) {
                $this->aql_array = $this->init($this->aql);
                $store_arr($this->aql_array);
            } else {
                self::$aqlArrays[$key] = $this->aql_array;
            }
        }
    }

    /**
     * Makes a function to store the aql array dependent on $mem_type
     * @param   string      $key
     * @param   string      $duration
     * @return  Function
     * @throws  Exception   if invalid mem_type
     */
    private function _getStoreFn($key, $duration)
    {
        $memoize = function($val) use($key) {
            aql2array::$aqlArrays[$key] = $val;
        };

        $fns = array(
            'mem' => function($val) use($key, $duration, $memoize) {
                $memoize($val);
                return mem($key, $val, $duration);
            },
            'disk' => function($val) use($key, $duration, $memoize) {
                $memoize($val);
                return disk($key, serialize($val), $duration);
            }
        );

        if (!array_key_exists(self::$mem_type, $fns)) {
            throw new Exception('Invalid mem type.');
        }

        return $fns[self::$mem_type];
    }

    /**
     * Makes a fetcher function for the given key
     * @param   string      $key
     * @return  Function
     */
    private function _getFetchFn($key)
    {
        $check_mem = function($key) {
            return aql2array::$aqlArrays[$key];
        };

        $fns = array(
            'mem' => function() use($key, $check_mem) {
                return $check_mem($key) ?: mem($key);
            },
            'disk' => function() use($key, $check_mem) {
                return $check_mem($key) ?: unserialize(disk($key));
            }
        );

        if (!array_key_exists(self::$mem_type, $fns)) {
            throw new Exception('Invalid mem type.');
        }

        return $fns[self::$mem_type];
    }

    /**
     * @param   string  $aql
     * @return  string
     */
    public function getMemKey($aql)
    {
        return sprintf('aql:v%s:%s', aql::AQL_VERSION, sha1($aql));
    }

    /**
     * Removes multiple white space and line breaks, replaces with ' '
     * This ensures that similar AQL with different whitespace (tabs, newlines)
     * gets parsed the same way (and makes it faster if it's cached already)
     * @param   string  $aql
     * @return  string
     */
    public function prepAQL($aql)
    {
        return trim(preg_replace('/\s+/', ' ', $aql));
    }

    /**
     * Attempts to normalize the statement by adding commas after aliases
     * @param   string  $aql
     * @return  string
     */
    public function add_commas($aql) {
        return preg_replace('/([\w(\')]+\s+as\s+\w+[^\w\{\},])$/mi', '\\1,', $aql);
    }

    /**
     * @param   string  $aql
     * @return  string
     */
    public function replace_escaped_quotes($aql)
    {
        return preg_replace("/\\\'/mi", '_T_AQL_ESCAPED_', $aql);
    }

    /**
     * @param   string  $aql
     * @return  string
     */
    public function put_back_escaped_quotes($aql)
    {
        return preg_replace('/_T_AQL_ESCAPED_/', "\\'", $aql);
    }

    /**
     * Checks to see if the field name already has a table name
     * Escapes quotes, $comparisons and numerics
     * @param   string  $table_name
     * @param   string  $field
     * @return  string
     */
    public function add_table_name($table_name, $field)
    {
        $field = trim($field);
        if (strpos($field, '\'') !== false ||
            in_array($field, self::$comparisons) ||
            is_numeric($field) ||
            $table_name == $field
        ) {
            return $field;
        }

        if (strpos($field, '(') !== false ||
            strpos($field, ')') !== false
        ) {

            if (preg_match(self::$aggregate_pattern, $field)) {
                return self::aggregate_add_table_name($table_name, $field);
            }

            $nf = self::add_table_name($table_name, str_replace(array('(', ')'), ' ', $field));
            return preg_replace('/[\w.%]+/', $nf, $field);
        }

        $rf = explode(' ', $field);
        $f = '';
        foreach ($rf as $r) {
            $r = trim($r);
            if ($r) {
                if (strpos($r,'.') === false
                    && !in_array(trim($r), self::$comparisons)
                    && !is_numeric(trim($r))
                    && stripos($r, '\'') === false
                ) {
                    $f .= trim($table_name).'.'.trim($r).' ';
                } else {
                    $f .= $r.' ';
                }
            }
        }

        return trim($f);
    }

    /**
     * Adds the table name to the aggregate
     * @param   string  $table_name
     * @param   string  $field
     * @return  string
     */
    public function aggregate_add_table_name($table_name, $field)
    {
        preg_match_all(self::$aggregate_pattern, $field, $matches);

        $callback = function($m) use($table_name) {
            return aql2array::add_table_name($table_name, $m[1]);
        };

        $r = '';
        foreach ($matches[0] as $k => $v) {
            $r .= sprintf(
                '%s%s(%s) %s',
                $matches['pre'][$k],
                $matches['function'][$k],
                preg_replace_callback(
                    '/([\w-.%\'\/]+)/',
                    $callback,
                    $matches['fields'][$k]
                ),
                $matches['post'][$k]
            );
        }
        return $r;
    }

    /**
     * If only hte joining table was given, the current table is added to the join
     * @param   string  $join
     * @param   string  $table
     * @param   string  $table_alias
     * @param   string  $prev_table
     * @param   string  $prev_table_alias
     */
    public function check_join($join, $table, $table_alias, $prev_table, $prev_table_alias)
    {
        if (is_numeric($join)) {
            return $join;
        }

        if (!(stripos($join, '.') !== false && stripos($join, '=') !== false)) {
            $join .= " = {$table_alias}.id";
        }

        return $join;
    }

    /**
     * Parses the where clause and adds the table name to fields that do not have it
     * @param   mixed   $array
     * @param   string  $table
     * @return  mixed
     */
    public function check_where($array, $table = '')
    {
        if (!$table || !is_array($array)) {
            return $array;
        }

        foreach ($array as $k => $where) {

            if (preg_match('/(?:case\s+when)'. self::$not_in_quotes . '/mi', $where)) {

                $array[$k] = aql2array::parse_case_when($where, $table);

            } else {

                $pattern = '/([()]*[\'%\w\/.#!@$%^&*\\\{\}]+[()]*)'.self::$not_in_quotes.'/mi';
                $callback = function($m) use($table) {
                    return aql2array::add_table_name($table, $m[1]);
                };

                $n = preg_replace_callback($pattern, $callback, $where);
                $array[$k] = self::put_back_escaped_quotes($n);
            }
        }

        return $array;
    }

    /**
     * Loops through the clause given (statement) and tries to add the table name to
     * strings that look like fields
     * @param   array   $array
     * @param   string  $table
     * @param   array   $fields
     * @return  array
     */
    public function check_clause($array, $table, $fields)
    {
        if (!is_array($array)) {
            return $array;
        }

        $aliases = array();
        if (is_array($fields)) {
            $aliases = array_keys($fields);
        }

        $isAlreadyValid = function($token) use($aliases) {
            return (
                !empty($token) &&
                !in_array($token, aql2array::$comparisons) &&
                strpos($token, '.') === false &&
                strpos($token, '\'') === false &&
                !is_numeric($token) &&
                !in_array($token, $aliases)
            );
        };

        foreach ($array as $k => $clause) {

            if (is_string($clause) && preg_match('/(case|when)/mi', $clause)) {
                $array[$k] = self::parse_case_when($clause, $table);
            } else {
                $cl = explodeOnWhitespace($clause);
                foreach ($cl as $i => $c) {
                    if ($isAlreadyValid($c)) {
                        if ($fields[$c] && !preg_match('/^[.\w]+$/', $fields[$c])) {
                            $c = $c;
                        } else if (strpos($c, '(') !== false && !$fields[$c]) {
                            $c = self::aggregate_add_table_name($table['as'], $c);
                        } else {
                            $c = $table['as'].'.'.$c;
                        }
                    }

                    $cl[$i] = $c;
                }

                $array[$k] = implode(' ', $cl);
            }
        }

        return $array;
    }

    /**
     * Fetches the aql array by name or creates it and stores it
     * @param   string  $model
     * @param   string  $aql
     * @return  array
     */
    public static function get($model, $aql = null)
    {
        if (!$model || $model == 'model') return array();
        if (self::$aqlArrays[$model]) {
            return self::$aqlArrays[$model];
        } else {
            if (!$aql) {
                if (self::$aqls[$model]) {
                    $aql = self::$aqls[$model];
                } else {
                    $aql = self::$aqls[$model] = aql::get_aql($model);
                }
            }
            return self::$aqlArrays[$model] = aql2array($aql);
        }
    }

    /**
     * Memoized table fields
     * @param   string  $table
     * @return  array
     * @global  $db
     */
    public function get_table_fields($table)
    {
        global $db;

        if (!$db) {
            return array();
        }

        if (array_key_exists($table, self::$metaColumns)) {
            return self::$metaColumns[$table];
        }

        $cols = $db->MetaColumns($table);
        $cols = (is_array($cols))
            ? array_map('strtolower', array_keys($cols))
            : array();

        return self::$metaColumns[$table] = $cols;
    }

    /**
     * @param   string  $table
     * @param   string  $field
     * @return  Boolean
     */
    public function table_field_exists($table, $field)
    {
        $fields = self::get_table_fields($table);

        return ($fields && in_array($field, $fields));
    }

    /**
     * @return  string
     */
    public function get_primary_table()
    {
        $m = $this->split_tables($this->aql);
        return $m['table_name'][0];
    }

    /**
     * Parses an aql statement for a table definition
     * and then uses inner for fields/clauses
     * @param   string  $aql
     * @param   array   $parent
     * @return  array
     */
    public function init($aql, $parent = array())
    {
        // the set up
        $aql_array = $tables = $fk = array();

        $aql = $this->replace_escaped_quotes($this->add_commas($aql));
        $m = $this->split_tables($aql);

        $prev = null;
        foreach ($m['table_name'] as $k => $v) {

            $tmp = array();

            // find distincts
            if ($m['primary_distinct'][$k]) {
                $tmp['primary_distinct'] = true;
            } else if ($m['distinct'][$k]) {
                $tmp['distinct'] = $m['distinct'][$k];
            }

            // get the table alias
            $on_as = $this->table_on_as($m['table_on_as'][$k]);
            $table_alias = ($on_as['as']) ?: $v;

            $tmp['table'] = $v;
            $tmp['as'] = $table_alias;

            $tables[$v] = $tmp;
            $split_info = $this->inner($m[0][$k], $tmp);

            if ($on_as['on']) {
                $tmp['on'] = $this->check_join(
                    $on_as['on'],
                    $v,
                    $tmp['as'],
                    $prev['table'],
                    $prev['as']
                );
            }

            $split_info['where'] = $this->prepare_where($split_info['where'], $tmp['table']);
            $split_info['where'] = $this->check_where($split_info['where'], $table_alias);

            if (!$prev && $parent) {
                $split_info['where'][] = $this->subquery_where(
                    $v,
                    $tmp['as'],
                    $parent['table'],
                    $parent['as']
                );
            }

            if ($split_info['aggergates']) {

                $o_arr = array(' asc', ' ASC', ' desc', ' DESC');
                $clean_order = function($t) use($o_arr) {
                    return str_replace($o_arr, '', $t);
                };

                foreach ($split_info['order by'] as $k) {
                    if (!in_array($k, $split_info['group by'])) {
                        $split_info['group by'][] = $clean_order($k);
                    }
                }
            }

            $aql_array[$table_alias] = $prev = $tmp + $split_info;
        }

        $i = 0;

        foreach ($aql_array as $k => $check) {
            if (!$check['on'] && $i > 0) {
                $j = $this->make_join_via_tables($check, $aql_array);
                $aql_array[$k]['on'] = $j['join'];
            }
            $i++;
        }

        foreach ($tables as $k => $table) {
            $fields = $this->get_table_fields($k);
            if (is_array($fields)) foreach ($tables as $i => $t) {
                if (in_array($i.'_id', $fields)) $aql_array[$table['as']]['fk'][] = $i;
            }
        }

        return $aql_array;
    }

    /**
     * Parses the innards of the table definition block for info
     * @param   string  $aql
     * @param   array   $parent     (if in a subquery)
     * @return  array   { fields, subqueries, objects, clauses }
     */
    public function inner($aql, $parent = array())
    {
        $tmp = array();
        $subqueries = $this->split_tables($aql, true);
        $subqueries = $subqueries[0];

        if (is_array($subqueries)) {

            $subs = array();
            $sub = '';

            foreach ($subqueries as $k => $v) {
                if (stripos(trim($v), "'") > 2 || stripos(trim($v), "'") === false) {
                    // FOR MULTIPLE SUBQUERIES
                    $aql = str_replace($v, '', $aql);
                    if (!preg_match('/\},$/', trim($v))) {
                        $sub .= $v;
                    } else {
                        $sub .= $v;
                        $sub = substr($sub, 0, -1);
                        $subs[] = $sub;
                        $sub = '';
                    }
                }

            }

            $subs[] = $sub;
            foreach ($subs as $s) {
                $sub = $this->init($s, $parent);
                if (!empty($sub)) {
                    $keys = array_keys($sub);
                    $tmp['subqueries'][$keys[0]] = $sub;
                }
            }
        }

        // remove opening brace and before and last brace and whitespace
        $aql = trim(substr(substr($aql, 0, -1), strpos($aql, '{') + 1));

        // get clauses
        foreach (array_reverse(self::$clauses) as $cl) {
            $pattern = sprintf('/(?:\b%s\b)%s/i', $cl, "(?=(?:(?:[^']*+'){2})*+[^']*+\z)");
            $split = preg_split($pattern, $aql, 2);
            $aql = $split[0];
            if ($split[1]) $tmp[$cl] = $split[1];
        }

        preg_match_all(self::$object_pattern, $aql, $matches);
        $aql = str_replace($matches[0], '', $aql);

        foreach ($matches['model'] as $k => $v) {

            $primary_table = aql::get_primary_table($v);
            $constructor_arg = ($matches['param'][$k]) ?: $primary_table.'_id';

            $object_tmp = array(
                'model' => $v,
                'primary_table' => $primary_table,
                'constructor argument' => $constructor_arg
            );

            $tmp_as = ($matches['as'][$k]) ?: $v;

            if ($matches['sub'][$k]) {
                $object_tmp['plural'] = true;
                $object_tmp['sub_where'] = $this->subquery_where(
                    $primary_table,
                    $primary_table,
                    $parent['table'],
                    $parent['as']
                );
            }

            $tmp['objects'][$tmp_as] = $object_tmp;
        }

        $i = 1;
        $fields = explodeOnComma($aql);
        array_walk($fields, function($field, $_, $o) use($parent, &$tmp, &$i){

            $add_field = function($alias, $value, $type = 'fields') use(&$tmp) {
                $tmp[$type][$alias] = $value;
            };

            $field = trim($field);

            if (empty($field)) {
                return;
            }

            if ($field == '*') {
                $fields = $o->get_table_fields($parent['table']);
                if (is_array($fields)) foreach ($fields as $f) {
                    $tmp['fields'][$f] = $parent['as'].'.'.$f;
                }
                return;
            }

            $as = array_map('trim', preg_split("/\bas\b{$o->not_in_quotes}/", $field));
            $alias = ($as[1]) ?: $as[0];
            if (strpos($alias, "'") !== false) {
                $alias = 'field_'.$i;
            }

            if (!$as[0] || !$alias) {
                return;
            }

            if (preg_match("/(case|when){$o->not_in_quotes}/im", $as[0])) {

                // this is a case when
                $add_field($alias, trim($o->parse_case_when($as[0], $parent['as'])));

            } else if (strpos($as[0], ')') !== false) {

                // this is a "function" we call it an aggregate for now
                $a = array_map('trim', explode('(', $as[0]));

                if (!empty($a[0])) {

                    $alias = ($alias == $as[0]) ? $a[0] : $alias;

                    if ($tmp['aggregates'][$alias]) {
                        $j = '1';
                        while (true) {
                            if ($tmp['aggregates'][$alias.'_'.$j]) {
                                $j++;
                                continue;
                            }
                            $alias = $alias.'_'.$i;
                            break;
                        }
                    } // end if alias is already taken.

                    $add_field(
                        $alias,
                        $o->aggregate_add_table_name($parent['as'], $as[0]),
                        'aggregates'
                    );

                } else {
                    $add_field($alias, $as[0]);
                }
            } else {

                // regular field
                $add_field($alias, trim($o->add_table_name($parent['as'], $as[0])));
            }

            $i++;

        }, $this);

        $tmp['fields'] = $tmp['fields'] ?: array();
        $tmp['aggregates'] = $tmp['aggregates'] ?: array();

        foreach (array('order by', 'group by') as $cl) {
            $tmp[$cl] = $this->check_clause(
                explodeOnComma($tmp[$cl]),
                $parent,
                array_merge($tmp['fields'], $tmp['aggregates'])
            );
        }

        $tmp['where'] = preg_split(
            '/\band\b(?=(?:(?:(?:[^()]++|\\.)*+[()]){2})*+(?:[^())]++|\\.)*+$)/i',
            $tmp['where']
        );

        return $tmp;
    }

    /**
     * Figures out what table to join on based on the other tables
     * @param   string  $table
     * @param   array   $tables
     * @return  array
     */
    public function make_join_via_tables($table, $tables)
    {
        foreach ($tables as $t) {
            if ($t['as'] != $table['as']) {
                $join = $this->make_join(
                    $table['table'],
                    $table['as'],
                    $t['table'],
                    $t['as']
                );

                if ($join['join']) {
                    return $join;
                }
            }
        }

        return array();
    }

    /**
     * Generates the join statement and foreign key info
     * @param   string  $table
     * @param   string  $table_as alias
     * @param   string  $prev_table
     * @param   string  $prev_table_as
     * @return  array   [join, fk]
     */
    public function make_join($table, $table_as, $prev_table, $prev_table_as)
    {
        $fk = $join = null;

        $fields = $this->get_table_fields($table);
        if (is_array($fields) && in_array($prev_table.'_id', $fields)) {

            $join = "{$table_as}.{$prev_table}_id = {$prev_table_as}.id";

        } else {

            $fields = $this->get_table_fields($prev_table);
            if (is_array($fields) && in_array($table.'_id', $fields)) {
                $join = "{$prev_table_as}.{$table}_id = {$table_as}.id";
                $fk = $prev_table;
            }

        }

        return array(
            'join' => $join,
            'fk' => $fk
        );
    }

    /**
     * Parses case when to add table names
     * @param   string  $str
     * @param   string  $table
     * @return  string
     */
    public function parse_case_when($str, $table)
    {
        $case_when_pattern = '/
            (?:case\s+when)\s+
            (?<condition>.+)?
            (?:\bthen\b)\s+
            (?<result>.+)
            (\belse\b)
            (?<default>.+)\s+
            end\b\s*
            (?<other>.+)*
        /msix';

        preg_match_all($case_when_pattern, trim($str), $matches);
        return sprintf(
            'CASE WHEN %s THEN %s ELSE %s END %s',
            self::check_where($matches['condition'][0], $table),
            $matches['result'][0],
            $matches['default'][0],
            $matches['other'][0]
        );
    }

    /**
     * Checks for 'ide' fields in the where clause and changes them into id fields
     * decrypts the values
     * @param   array   $where
     * @param   string  $table  scope of this where clause
     * @return  array
     */
    public function prepare_where($where, $table)
    {
        if (!is_array($where)) {
            return $where;
        }

        // looking for ides to map to ids with decrypt
        $pattern = '/\b(?<field>[\w.]*ide)\s*=\s*(?<ide>[\w\']+)?/i';
        $find_matches = function($str) use($pattern) {
            preg_match_all($pattern, trim($str), $matches);
            return $matches;
        };

        return array_map(function($where) use($table, $find_matches) {

            $matches = $find_matches($where);
            $field = $matches['field'][0];
            if (empty($field)) {
                return $where;
            }

            $ide = str_replace("'", '', $matches['ide'][0]);
            $table_field = explode('.', $field);
            $field_name = ($table_field[1]) ?: $table_field[0];

            $tmp = aql2array::table_name_from_id_field($field_name);
            $table_name = ($table_field[1])
                ? ($tmp ?: $table_field[0])
                : ($tmp ?: $table);

            $id = decrypt($ide, $table_name);
            return preg_replace('/ide$/', 'id', $field) . ' = ' . $id;

        }, $where);
    }

    /**
     * Gets the table name from the id string
     * @param   string  $field
     * @return  string
     */
    public function table_name_from_id_field($field)
    {
        $field = explode('__', $field);
        $field = ($field[1]) ? $field[1] : $field[0];
        if ($field == 'id' || $field == 'ide') {
            return '';
        }

        return preg_replace(array('/_id$/', '/_ide$/'), '', $field);
    }

    /**
     * Finds the expressions in the AQL that look like table definitions
     * @param   string  $aql
     * @param   Boolean $sub    if true, we're looking for a subquery
     * @return  array
     */
    public function split_tables($aql, $sub = false)
    {
        if ($sub) {
            $aql = substr($aql, strpos($aql, '{') + 1);
        }
        preg_match_all(self::$pattern, trim($aql), $matches);
        return $matches;
    }

    /**
     * Strips out comments from the aql
     * @param   string  $str
     * @return  string
     */
    public function strip_comments($str)
    {
        foreach (self::$comment_patterns as $pattern) {
            $str = preg_replace($pattern, '', $str);
        }
        return $str;
    }

    /**
     * Gets the where clause for connecting a 'subquery'
     * @param   string  $table
     * @param   string  $table_as (alias)
     * @param   string  $prev_table (parent query table name)
     * @param   string  $prev_table_as (parent query table alias)
     * @return  string
     */
    public function subquery_where($table, $table_as, $prev_table, $prev_table_as)
    {
        $fields = $this->get_table_fields($table);
        if (is_array($fields) && in_array($prev_table.'_id', $fields)) {
            $join = "{$table_as}.{$prev_table}_id = ".'{$'.$prev_table_as."_id}";
        } else {
            $fields = $this->get_table_fields($prev_table);
            if (is_array($fields) && in_array($table.'_id', $fields)) {
                $join = "{$prev_table_as}.{$table_as}_id = {$table_as}.id";
            }
        }
        return $join;
    }

    /**
     * Returns an array with on / as keys
     * @param   string  $str should be the table declaration
     * @return  array
     */
    public function table_on_as($str = '')
    {
        if (!$str) {
            return array();
        }

        preg_match(self::$on_pattern, $str, $matches);
        preg_match(self::$as_pattern, $str, $matches2);

        return array(
            'on' => trim($matches['on']),
            'as' => trim($matches2['as'])
        );
    }

}
