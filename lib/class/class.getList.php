<?php

/**
 * Usage:
 *
 *      $aql = "
 *          table1 { id }
 *          table2 { }
 *          table3 { }
 *      ";
 *
 *      $lst = new getList;
 *      $lst->setAQL($aql)
 *          ->defineFilters([
 *              'name' => [
 *                  'operator' => 'name',
 *                  'callback' => function($value) use($lst) {
 *                      $lst->where[] = "table1.name = '{$value}';
 *                  }
 *              ],
 *              'search' => [
 *                  'callback' => function($value) use($lst) {
 *                      $lst->where[] = $someinterpretedwhere;
 *                  }
 *              ]
 *          ]);
 *
 *      $ids = $lst->select([
 *          'name' => $value
 *      ]);
 *      // or
 *      $ids = $lst->select([
 *          'search' => "name:$val" // this can be done because the operator is defined
 *                                  // for name
 *      ]);
 *
 *      // $ids is an array of ids from the primary_table, in this case: table1
 *      var_dump($ids);
 *
 *      // To get a count of the results
 *      $lst->getCount($params);
 *
 * Each of the keys in params will match a key of the filters defined on the getList.
 * This class is used to generate callback based searches.
 * Used extensively in Models
 * @see Model::getList
 * @see Model::getByClause (powers getMany() and getOne())
 * @package SkyPHP
 */
class getList
{

    /**
     * [filtername => operator]
     * @var array
     */
    public $filters = array();

    /**
     * The params given to select/count
     * @var array
     */
    public $params = array();

    /**
     * The aql for this query
     * @var string
     */
    public $aql;

    /**
     * Generated select query sql
     * @var string
     */
    public $query_sql;

    /**
     * Generated count query sql
     * @var string
     */
    public $count_sql;

    /**
     * Generated where clauses using params
     * @var array
     */
    public $where = array();

    /**
     * Given order by in params
     * @var string
     */
    public $order_by = '';

    /**
     * Given limit in params
     * @var int
     */
    public $limit = 0;

    /**
     * Given offset in params
     * @var int
     */
    public $offset = 0;

    /**
     * Joins to add to the aql (generally set in filters)
     * @var array
     */
    public $joins = array();

    /**
     * Methods that correspond to filters
     * [ set_ filter_name => fn ]
     * @var array
     */
    protected $methods = array();

    /**
     * Constructs the object, does nothing!
     */
    public function __construct($a = array())
    {
    }

    /**
     * @param   string  $aql
     * @return  $this
     */
    public function setAQL($aql)
    {
        $this->aql = $aql;
        return $this;
    }

    /**
     * @param   array   $params
     * @return  $this
     */
    public function setParams(array $params)
    {
        $this->params = $params;
        return $this;
    }

    public function defineFilters(array $arr)
    {
        if (!is_assoc($arr)) {
            throw new Exception('defineFilters expects an associative array.');
        }

        foreach ($arr as $k => $v) {
            $this->defineFilter($k, $v);
        }

        return $this;
    }

    /**
     * @param   string  $str
     * @return  $this
     */
    public function addJoin($str)
    {
        $this->joins[] = $str;
        return $this;
    }

    /**
     * Prepares the currently defined joins by appending '{}'
     * so that they are valid AQL
     * @return  $this
     */
    public function prepareJoins()
    {
        foreach ($this->joins as $j) {
            $this->aql .= $j . ' {}';
        }

        return $this;
    }

    /**
     * Makes the clauses ready for query execution
     * [ where, limit, order_by, offset ]
     * @return  $this
     */
    public function prepareClauses()
    {
        $this->makeWhereArray();

        if ($this->params['limit']) {
            $this->limit = $this->params['limit'];
        }

        if ($this->params['order_by']) {
            $this->order_by = $this->params['order_by'];
        }

        if ($this->params['offset']) {
            $this->offset = $this->params['offset'];
        }

        return $this;
    }

    /**
     * Step by step prep clauses to filters, joins, and make queries
     * This happens right before query execution
     * @return  $this
     */
    public function prepare()
    {
        return $this->prepareClauses()
                    ->mapIDEsToIDs()
                    ->mapSearch()
                    ->checkParams()
                    ->prepareJoins()
                    ->makeQueries();
    }

    /**
     * Generates the sql queries
     * This is the last step before execution
     * @return  $this
     */
    public function makeQueries()
    {
        $sql = aql::sql($this->aql, array(
            'limit' => $this->limit,
            'where' => $this->where,
            'order by' => $this->order_by,
            'offset' => $this->offset
        ));

        $this->count_sql = preg_replace(
            '/\bcount\(\*\)/',
            "count(distinct {$sql['primary_table']}.id)",
            $sql['sql_count']
        );

        $this->query_sql = $sql['sql_list'];

        return $this;
    }

    /**
     * Executes a count query based on the params
     * @param   array   $arr
     * @return  int
     */
    public function getCount($arr = array())
    {
        $this->setParams($arr)->prepare();

        return sql($this->count_sql)->Fields('count');
    }

    /**
     * Excecutes select query
     * Returns an array of [int]
     * @param   array   $arr
     * @return  array
     */
    public function select($arr = array())
    {
        $this->setParams($arr)->prepare();

        if ($_GET['getList_debug']) {
            krumo($this);
        }

        $r = sql($this->query_sql);

        $ids = array();
        while (!$r->EOF) {
            $ids[] = $r->Fields('id');
            $r->moveNext();
        }

        return $ids;
    }

    /**
     * Loops through currently defined filters to see which filter
     * this operator applies to
     *  - filters are stored as [filter-name => operator_name]
     * @param   string  $operator
     * @return  array
     */
    public function getFilterByOperator($operator)
    {
        $re = array();
        foreach ($this->filters as $k => $v) {
            if (!is_bool($v) && $v == $operator) {
                $re[] = $k;
            }
        }

        return $re;
    }

    /**
     * Arrayifys params['where']
     * @return  $this
     */
    public function makeWhereArray()
    {
        if ($this->params['where']) {
            $this->where = \arrayify($this->params['where']);
        }

        return $this;
    }

    /**
     * Iterates through the params and decrypts them if they are ides
     * @return  $this
     */
    public function mapIDEsToIDs()
    {
        foreach ($this->params as $k => $v) {
            if (substr($k, -4) != '_ide') {
                continue;
            }

            $key = aql::get_decrypt_key($k);
            $prop = substr($k, 0, -1);
            if (!array_key_exists($prop, $this->filters)) {
                continue;
            }

            $decrypt = decryptFn($key);
            $decrypted = (is_array($v)) ? array_map($decrypt, $v) : $decrypt($v);
            $this->params[substr($k, 0, -1)] = $decrypted;
        }

        return $this;
    }

    /**
     * Finds operators in the search, otherwise adds 'search' to the where clause
     * @return  $this;
     */
    public function mapSearch()
    {
        if (!$this->params['search']) {
            return $this;
        }

        $q = $this->params['search'];
        $qs = array_map('trim', explode(',', $q));

        $operators = array_values($this->filters);

        $search = '';
        foreach ($qs as $q) {

            $matches = $this->_matchSearchOperators($q);
            if (!$matches['operator'] ||
                !in_array($matches['operator'], $operators) ||
                !$matches['search']
            ) {
                $search .= ' '.$q;
            } else {
                $props = $this->getFilterByOperator($matches['operator']);
                foreach ($props as $p) {
                    if (is_numeric($matches['search'])) {
                        $this->params[$p] = $matches['search'];
                    } else {
                        $key = aql::get_decrypt_key($p.'e');
                        $decrypted = decrypt($matches['search'], $key);
                        if (is_numeric($decrypted)) {
                            $this->params[$p] = $decrypted;
                        } else {
                            $this->params[$matches['operator']] = $matches['search'];
                        }
                    }
                }
            }
        }

        $search = trim($search);
        $this->params['search'] = ($search) ?: null;

        return $this;
    }

    /**
     * Looks for something that looks like an operator in the given string
     * @param   string  $search
     * @return  array
     */
    private function _matchSearchOperators($search)
    {
        preg_match('/^(?<operator>[\w]+):(?<search>.+)$/', $search, $matches);
        return $matches;
    }

    /**
     * Adds the filter and method
     * @param   string  $n      filter name
     * @param   array   $arr    filter options
     */
    public function defineFilter($n, $arr)
    {
        $this->filters[$n] = $arr['operator'] ?: true;
        if (is_callable($arr['callback'])) {
            $this->addMethod('set_' . $n, $arr['callback']);
        }

        return $this;
    }

    /**
     * Loops through the params and applies the callabacks
     * @return  $this
     */
    public function checkParams()
    {
        foreach ($this->params as $k => $v) {
            if ($v) {
                $this->applyMethodIfExists('set_'.$k, array($v));
            }
        }

        return $this;
    }

    /**
     * Checks to see if hte method is in 'methods' before calling it with the given args
     * @param   string  $method
     * @param   array   $arg
     * @return  mixed
     */
    public function applyMethodIfExists($method, array $arg = array())
    {
        if ($this->methodExists($method)) {
            return call_user_func_array($this->methods[$method], $arg);
        }
    }

    /**
     * Adds methods
     * @param   array   $arr
     * @return  $this
     * @throws  Exception if param is non associative
     */
    public function addMethods($arr)
    {
        if (!is_assoc($arr)) {
            throw new Exception('addMethods expects the arg to be an associative array');
        }

        foreach ($arr as $k => $v) {
            $this->addMethod($k, $v);
        }

        return $this;
    }

    /**
     * Adds a method to the methods array
     * @param   string      $name
     * @param   callable    $fn
     * @return  $this
     * @throws  Exception   if function is not callable or method is already defined
     */
    public function addMethod($name, $fn)
    {
        if (!is_callable($fn)) {
            throw new Exception('method: '. $name .' is not callable.');
        }

        if ($this->methodExists($name)) {
            throw new Exception('method: '. $name .' already exists in this object.');
        }

        $this->methods[$name] = $fn;

        return $this;
    }

    /**
     * Checks if the method already exists
     * @param   string  $name
     * @return  Boolean
     */
    public function methodExists($name)
    {
        return array_key_exists($name, $this->methods);
    }

    /**
     * Magic call method
     * @param   string  $method
     * @param   array   $args
     * @return  mixed
     * @throws  Exception   if method is not defined
     */
    public function __call($method, $args)
    {
        if (!$this->methodExists($method)) {
            throw new Exception('Method: '. $method .' does not exist in this object.');
        }

        return call_user_func_array($this->methods[$name], $args);
    }

    /**
     * Returns a getList object with filters defined based on the given AQL
     * @param   string      $aql
     * @param   Boolean     $search_operators
     * @return  \getList
     * @throws  \Exception  if invalid AQL
     */
    public static function autoGenerate($aql, $search_operators = false)
    {
        if (!aql::is_aql($aql)) {
            throw new \Exception('autoGenerate requires AQL.');
        }

        $aql_array = aql2array($aql);
        $min_aql = aql::minAQLFromArr($aql_array);

        $fields = array();
        foreach ($aql_array as $k => $f) {
            $fields = array_merge($fields, $f['fields']);
        }

        $lst = new self;
        $lst->setAQL($min_aql)
            ->defineFilters(array_map(
                function($field) use($lst, $search_operators) {
                    return array(
                        'operator' => ($search_operators) ? $field : null,
                        'callback' => function($val) use($lst, $field) {
                            $where = \getList::prepVal($val);
                            $lst->where[] = "{$field} in {$where}";
                        }
                    );
                },
                $fields
            ));

        return $lst;
    }

    /**
     * Prepares the callback value to be a list as necessary
     * to be used in: field in (values)
     * @param   mixed   $val (array or string)
     * @return  string
     */
    public static function prepVal($val)
    {
        $quote = function($val) {
            return "'{$val}'";
        };

        return sprintf(
            '(%s)',
            implode(',', array_map($quote, \arrayify($val)))
        );
    }

    /**
     * Returns a getList function based on the given AQL
     * @param   string      $aql
     * @param   Boolean     $search_operators
     * @return  callable
     */
    public static function getFn($aql, $search_operators = false)
    {
        $list = static::autoGenerate($aql, $search_operators);

        return function($clause = array(), $count = false) use($list) {
            return $count ? $list->getCount($clause) : $list->select($clause);
        };
    }

}
