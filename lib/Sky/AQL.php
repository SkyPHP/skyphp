<?php

namespace Sky;

/**
 * Abbreviated Query Language / Object Relational Mapper
 *
 * Parse
 *
 * Sample AQL Syntax and Usage:
 *
 *  $aql = "
 *      album {
 *          name,
 *          [artist],
 *          [track]s as tracks,
 *          [person(producer__person_id)] as producer,
 *          year
 *          WHERE name is not null
 *          ORDER BY year
 *          LIMIT 10
 *          track as songs {
 *              title
 *          },
 *          favorite_album { }
 *          user {
 *              username
 *          }
 *      }
 *      artist {
 *          name
 *          where name is not null
 *      }
 *  ";
 *
 *  $rs = AQL::select($aql, [
 *      'where' => "year = 1999"
 *  ]);
 *
 *  echo AQL::count($aql);
 *
 *  $a = new AQL($aql);
 *  d($a);
 *
 */
class AQL {

    /**
     * The AQL statement
     * @var string
     */
    public $statement;

    /**
     * @var string
     */
    public $primaryTable;

    /**
     * Array of AQL\Block objects
     * @var array
     */
    public $blocks;

    /**
     * @var bool
     */
    public $distinct;

    /**
     * @var string DISTINCT ON ( $distinctOn )
     */
    public $distinctOn;

    /**
     * @var object
     */
    public $sql;

    /**
     *
     */
    private static $transactionCounter = 0;

    /**
     * Automatically appended to every table in the SQL query.  Allows records to be
     * virtually "deleted" without ever deleting a row.
     * @var string
     */
    public static $activeWhere = 'active = 1';

    /**
     * The errors for the current transaction
     */
    public static $errors = [];

    /**
     * Reserved SQL keywords
     * Used to make sure we don't auto-prepend table name
     * Searches against this array must be in lower case
     */
    public static $reserved = [
        'date', 
        'case',
        'when',
        'end',
        'length',
        'ilike',
        'like',
        'distinct',
        'select',
        'where',
        'from',
        'then',
        'else',
        'upper',
        'lower',
        'and',
        'or',
        'is',
        'null',
        'in',
        'not',
        'true',
        'false',
        'now()',
        'to_date',
        'asc',
        'desc',
        'interval',
        'trim',
        'to_char',
        'date_format',
        'random()',
        'between',
        'extract',
        'dow'
    ];

    /**
     * Creates an AQL object using the given AQL statement. Optionally, the AQL object
     * can be modified using with the second parameter.
     * @param string $aql_statement
     */
    public function __construct($aql_statement, $params = [])
    {
        // save this object into memory to prevent duplicate memcache retrievals
        static $memoize;

        if (!static::isAQL($aql_statement)) {
            throw new \Exception('Empty or invalid AQL statement.');
        }

        $aql_hash = 'aql:' . md5($aql_statement);

        if ($memoize[$aql_hash]) {
            $aql_cache = $memoize[$aql_hash];
        } else if (!$_GET['aql-refresh']) {
            elapsed("begin mem $aql_hash");
            $aql_cache = mem($aql_hash);
            elapsed("end mem $aql_hash");
        }

        if ($aql_cache) {
            #elapsed('using cached aql object');
            $this->statement = $aql_cache->statement;
            $this->primaryTable = $aql_cache->primaryTable;
            $this->blocks = $aql_cache->blocks;
            $this->sql = $aql_cache->sql;
            $this->distinct = $aql_cache->distinct;
            $this->distinctOn = $aql_cache->distinctOn;
        } else {
            $this->statement = $aql_statement;
            $this->createBlocks();
            $this->fixDuplicateAliases();
            $this->primaryTable = $this->blocks[0]->table;
            $this->fixShorthandJoins();
            $this->autoJoin();
            $this->setForeignKeys();
            // cache these aql object properties
            mem($aql_hash, $this);
        }

        #elapsed('before createSQL');
        $this->createSQL($params);
        #elapsed('after createSQL');

        $memoize[$aql_hash] = $this;

    }


    /**
     * Determines if a string is an AQL statement
     */
    public static function isAQL($aql_statement)
    {
        if (strpos($aql_statement, '{')) {
            return true;
        }
        return false;
    }


    /**
     * Parses the AQL statement and creates the array of Blocks
     */
    public function createBlocks()
    {
        $pattern = AQL\Block::OUTER_PATTERN;
        preg_match_all($pattern, $this->statement, $matches);
        $this->distinct = $matches[2][0] ? true : false;
        $this->distinctOn = $matches[4][0];
        $blocks = $matches[5];
        foreach ($blocks as $block) {
            $this->blocks[] = new aql\Block($block, [
                'distinct' => $this->distinct
            ]);
        }
        return $this;
    }


    /**
     * Ensures that a joined table doesn't have a field that will overwrite an previously
     * existing field with the same name.
     */
    public function fixDuplicateAliases()
    {

        return $this;
    }


    /**
     * Gets an array of standard objects from the database for the given AQL statement
     * @param mixed $aql AQL string or Sky\AQL object
     * @param array $params
     *      where       string|array
     *      order by    string
     *      limit       int
     *      dbw         bool
     *
     */
    public static function select($aql, $params = [])
    {
        global $db, $dbw;

        if (is_a($aql, '\Sky\AQL')) {
            $a = $aql;
            $a->addParams($params);
        } else {
            $a = new self($aql, $params);
        }
        $dbx = $params['dbw'] ? $dbw : $db;

        #d($a);

        // query, count, list
        $sql_type = $params['sql_type'] ?: 'query';
        $sql = $a->sql->$sql_type;
        return \sql($sql, $dbx);
    }


    /**
     *
     */
    public static function count($aql, $params = [])
    {
        $params['sql_type'] = 'count';
        return static::select($aql, $params)[0]->count;
    }


    /**
     *
     */
    public static function listing($aql, $params = [])
    {
        $params['sql_type'] = 'list';
        return static::select($aql, $params);
    }


    /**
     *
     */
    public static function update($table, $data, $id)
    {
        $dbw = self::getMasterDB();

        #d(1);
        #d($table, $data, $id);
        // returns false on error and sets AQL::$errors
        // $error->getMessage(), getTrace(), db_error, fields

        $set = [];
        foreach ($data as $k => $v) {
            $set[] = $k . ' = :' . $k;
        }
        $set = implode(', ', $set);

        try {
            $sql = "UPDATE $table
                SET $set
                WHERE id = $id";
            $st = $dbw->prepare($sql);
            $st->execute($data);
        } catch (\Exception $e) {
            AQL::$errors[] = [
                'message' => $e->errorInfo[2],
                'sql' => $sql,
                'data' => $data,
                'type' => 'update',
                'exception' => $e
            ];
            return false;
        }

        // return the entire updated record
        $pk = AQL\Block::PRIMARY_KEY_FIELD;
        $sql = "SELECT * FROM $table WHERE $pk = $id";
        $rs = \sql($sql, $dbw);
        return $rs[0];

    }


    /**
     *
     */
    public static function insert($table, $data, $params = [])
    {
        global $db_driver;

        $dbw = self::getMasterDB();

        if (!$dbw) {
            return (object) [];
        }

        $ins = [];
        foreach ($data as $f => $v) {
            $ins[] = ':' . $f;
        }

        $ins = implode(',', $ins);
        $fields = implode(',', array_keys($data));
        $sql = "INSERT INTO $table ($fields) VALUES ($ins)";

        if (isset($_GET['sql_debug']) && $_GET['sql_debug'] == '1')
            d($sql);

        try {
            $st = $dbw->prepare($sql);
            foreach ($data as $f => $v) {
                $st->bindValue(':' . $f, $v);
            }


            $st->execute();
        } catch (\Exception $e) {
            AQL::$errors[] = [
                'message' => $e->errorInfo[2],
                'sql' => $sql,
                'data' => $data,
                'type' => 'insert',
                'exception' => $e
            ];
            return false;
        }

        // now get the id that was just inserted
        switch ($db_driver) {
            case 'pgsql':
                $seq = $table . AQL\Block::SEQUENCE_SUFFIX;
                $id = $dbw->lastInsertId($seq);
                break;

            default:
                $id = $dbw->lastInsertId();
        }
        // return the inserted record
        $pk = AQL\Block::PRIMARY_KEY_FIELD;
        $rs = \sql("SELECT * FROM $table WHERE $pk = $id", $dbw);
        return $rs[0];
    }


    /**
     *
     */
    public static function getMasterDB()
    {
        global $dbw;

        if (!$dbw){
            $dbw_host = \Sky\Db::getPrimary($db);

            if (!$dbw_host) {
                // cannot determine master
                $db_error .= "db error ($db_host): cannot determine master \n";
                $dbw = null;

            }

            // we have determined the master, now we will connect to the master

            $dbw = \Sky\Db::connect([
                'db_host' => $dbw_host
            ]);
            
        }
        return $dbw;
    }


    /**
     *
     */
    public static function begin()
    {
        self::$transactionCounter++;
        if (self::$transactionCounter == 1) {
            // PDO begin transaction
            return self::getMasterDB()->beginTransaction();
        }
    }


    /**
     *
     */
    public static function commit()
    {
        self::$transactionCounter--;
        if (self::$transactionCounter == 0) {
            // PDO commit
            return self::getMasterDB()->commit();
        }
    }


    /**
     *
     */
    public static function rollBack()
    {
        self::$transactionCounter--;
        if (self::$transactionCounter == 0) {
            // PDO rollback
            return self::getMasterDB()->rollBack();
        }
    }


    /**
     *
     */
    public static function getTransactionCounter()
    {
        #d(self::$transactionCounter);
        return self::$transactionCounter;
    }


    /**
     * @param array $params
     *      where
     *      order by | order_by | orderBy
     *      limit
     *      offset
     * @return $this
     */
    public function createSQL($params = [])
    {

        $fields = [];
        $has_aggregate = false;
        $group_by = [];
        $joins = [];
        $wheres = [];

        if ($params['where']) {
            if (!is_array($params['where'])) {
                $params['where'] = [$params['where']];
            }
            $wheres = $params['where'];
        }

        // remove empty expressions
        $wheres = array_filter($wheres);

        // prepend table name to user-defined where clauses
        foreach ($wheres as $i => $where) {
            $wheres[$i] = static::prependTableName($where, $this->primaryTable);
        }

/*        if (!$this->blocks ||  !is_array($this->blocks))
            return ;*/
        // aggregate the fields, joins, etc for each block into a single string
        #d($this->blocks);
        foreach ($this->blocks as $i => $block) {
            // table and alias
            $table = $block->alias ?: $block->table;
            if ($i == 0) {
                $primaryAlias = $table; // save this for easy access later
            }

            #d($block);

            // aggregates
            if ($block->aggregates) {
                $has_aggregate = true;
                foreach ($block->aggregates as $agg) {
                    $field = $agg['function'] . '(' . $agg['field'] . ')';
                    if ($agg['alias']) {
                        $field .= " AS " . $agg['alias'];
                    }
                    $fields[] = $field;
                }
            }

            // fields
            if ($block->fields) {
                foreach ($block->fields as $f) {
                    $field = $f['field'];

                    // un-aliased field name needed for group by (if there is an aggregate)
                    $group_by[] = $field;

                    // only add the AS alias if necessary
                    if ($table . '.' . $f['alias'] != $f['field']) {
                        $alias = $f['alias'];
                        $field .= ' AS ' . $f['alias'];
                    } else {
                        $alias = substr($field, strrpos($field, '.') + 1);
                    }

                    // if this alias already exists, let's not overwrite the first one
                    if (!$fields[$alias]) {
                        $fields[$alias] = $field;
                    }
                }
            }

            // add id field
            if (!$this->distinct) {
                $fields['id'] = $this->primaryTable . '.id';
            }

            // joins
            if ($i > 0) { // don't join the primary table
                $join = $block->table;
                if ($block->alias && $block->alias != $block->table) {
                    $join .= " AS {$block->alias}";
                }
                $join .= " ON {$block->joinOn}";
                if (static::$activeWhere) {
                    $join .= ' AND ' . $table . '.' . static::$activeWhere;
                }
                $joins[] = $join;
            }

            // where
            if ($block->where) {
                // prepend the table name to the fields in where clause
                $wheres[] = static::prependTableName($block->where, $table);
            }

            // order by
            if ($block->orderBy) {
                // add a comma if we are adding to an existing order by clause
                if ($order_by) {
                    $order_by .= ', ';
                }
                // prepend the table name to the fields in order by clause
                $order_by .= static::prependTableName($block->orderBy, $table);
            }

            // limit
            if ($block->limit && !$limit) {
                // only set the limit if it has not already been set
                $limit = $block->limit;
            }

            // offset
            if ($block->offset && !$offset) {
                // only set the offset if it has not already been set
                $offset = $block->offset;
            }

        }

        // select
        $distinct = '';
        if ($this->distinct) {
            $distinct = 'DISTINCT ';
            if ($this->distinctOn) {
                $distinct .= 'ON (' . $this->distinctOn . ') ';
            }

        }
        $select = "SELECT $distinct\n\t" . implode(",\n\t", array_unique($fields));
        $count = "SELECT count(*) as count";

        // from
        $from = "\nFROM {$this->primaryTable}";

        // joins
        if (count($joins)) {
            $left_join = "\nLEFT JOIN " . implode("\nLEFT JOIN ", $joins);
        }

        // where
        $where = "\nWHERE \n\t";
        // add active = 1 (or custom activeWhere) if applicable
        if (static::$activeWhere) {
            $where .= $primaryAlias . '.' . static::$activeWhere;
        } else {
            $where .= 'true';
        }
        if (count($wheres)) {
            $where .= "\n\tAND " . implode("\n\tAND ", $wheres);
        }

        // aggregates
        if ($has_aggregate) {
            $group_by = "\nGROUP BY \n\t" . implode(",\n\t", array_unique($group_by));
        } else {
            $group_by = null;
        }

        // order by
        $strs = [
            'order by',
            'order_by',
            'orderBy'
        ];
        foreach ($strs as $str) {
            if ($params[$str]) {
                if ($order_by) {
                    $order_by .= ', ';
                }
                $order_by .= $params[$str];
            }
        }
        // we have all order by statements in a string so append ORDER BY
        if ($order_by) {
            // if there is an ambiguous field, assume it belongs to the primary table
            $order_by = static::prependTableName($order_by, $this->primaryTable);
            $order_by = "\nORDER BY $order_by";
        }

        // limit -- user specified limit param overrides aql block limit
        if ($params['limit']) {
            $limit = $params['limit'];
        }
        if ($limit) {
            $limit = "\nLIMIT $limit";
        }

        // limit -- user specified limit param overrides aql block limit
        if ($params['offset']) {
            $offset = $params['offset'];
        }
        if ($offset) {
            $offset = "\nOFFSET $offset";
        }

        $id = AQL\Block::PRIMARY_KEY_FIELD;
        $_id = AQL\Block::FOREIGN_KEY_SUFFIX;

        $this->sql = (object) [
            'query' =>  "{$select}{$from}{$left_join}{$where}{$group_by}{$order_by}{$limit}{$offset}\n",
            'count' =>  "{$count}{$from}{$left_join}{$where}\n",
            'list' =>   "SELECT {$id}, {$id} AS {$this->primaryTable}{$_id}\n" .
                        "FROM (\n" .
                        "\tSELECT DISTINCT ON (q.{$id}) {$id}, row\n" .
                        "\tFROM (\n" .
                        "SELECT\n" .
                        "\t{$this->primaryTable}.{$id},\n" .
                        "\trow_number() OVER({$order_by}) as row{$from}{$left_join}{$where}{$order_by}\n" .
                        "\t) as q\n" .
                        ") as fin\n" .
                        "ORDER BY row\n" .
                        $limit .
                        $offset
        ];

        #d($this);

        return $this;
    }


    /**
     * Prefixes field names with the table: i.e. table_name.field_name
     * TODO: account for string literals containing an escaped apostrophe, i.e. '\'test\''
     */
    public static function prependTableName($expression, $table)
    {
        #$pattern = "#([\w.\"']+)(\(\))*#i";
        $pattern = "#('.*')*|[\w.\"']+(\(\))*#i";
        preg_match_all($pattern, $expression, $matches, PREG_OFFSET_CAPTURE);
        $matches = $matches[0];
        // reverse the array so our insertions don't affect the character position
        // of subsequent insertions
        $matches = array_reverse($matches);
        foreach ($matches as $match) {
            if (array_search(strtolower($match[0]), static::$reserved) === false) {
                // if the match is blank skip
                if (!trim($match[0])) {
                    continue;
                }
                // if the first character is single quote, skip
                if (strpos($match[0], "'") === 0) {
                    continue;
                }
                // not a reserved word, prepend table name unless it already has
                // a dot '.' in the word
                if (strpos($match[0], '.') === false
                    && strpos($match[0], "'") === false
                    && !is_numeric($match[0])) {
                    // there is no dot, apostrophe, and not numeric.
                    // now let's embed the table name before the field name
                    $length = strlen($match[0]);
                    $begin = substr($expression, 0, $match[1]);
                    $embed = $table . '.' . $match[0];
                    $end = substr($expression, $match[1] + $length);
                    $expression = $begin . $embed . $end;
                    #d($begin, $embed, $end);
                }
            }
        }
        return $expression;
    }

    /**
     * Sets the JOIN ON clause for each block that needs to be automatically joined
     * @return $this
     */
    private function autoJoin()
    {
        // find blocks that are not joined yet
        foreach ($this->blocks as $i => $unjoined_block) {
            if ($i == 0 || $unjoined_block->joinOn) {
                // this block is already joined
                continue;
            }
            // find an already joined block that this unjoined block can join on
            foreach ($this->blocks as $j => $joined_block) {
                #elapsed($unjoined_block->table . ' --> ');
                #elapsed($joined_block->table);
                if ($j > 0 && !$joined_block->joinOn) {
                    #elapsed('not!');
                    // this block is not joined, we only want to try joining on a block
                    // that is already joined
                    continue;
                }
                $joinOn = $this->getJoin($unjoined_block, $joined_block);
                if ($joinOn) {
                    $joined_something = true;
                    $this->blocks[$i]->joinOn = $joinOn;
                    break;
                }
                #elapsed('nope');
            }
            // we could not find a table to join on.. send this table to the back
            if (!$joinOn) {
                #d($this->blocks);
                $this->blocks[] = $this->blocks[$i];
                unset($this->blocks[$i]);
                #d($this->blocks);
            }
        }
        // run autoJoin() again in case we still have unjoined tables
        // only if we joined something this time to prevent infinite loop
        if ($joined_something) {
            $this->autoJoin();
        }
        return $this;
    }


    /**
     * Gets the JOIN ON expression to join blockA to blockB
     * @param Block $blockA the unjoined block
     * @param Block $blockB presumably a block that has been joined already
     * @return string JOIN ON expression | false if can't be auto-joined
     */
    private function getJoin($blockA, $blockB)
    {
        // if tableB.tableA_id exists
        $fieldA = $blockA->table . AQL\Block::FOREIGN_KEY_SUFFIX;
        $colsB = static::getColumns($blockB->table);
        if (array_search($fieldA, $colsB) !== false) {
            // one-to-one relationship found
            return $blockB->alias . '.' . $fieldA
                    . ' = ' . $blockA->alias . '.' . AQL\Block::PRIMARY_KEY_FIELD;
        }
        // if tableA.tableB_id exists
        $fieldB = $blockB->table . AQL\Block::FOREIGN_KEY_SUFFIX;
        $colsA = static::getColumns($blockA->table);
        if (array_search($fieldB, $colsA) !== false) {
            // one-to-many relationship found
            // add the foreign key to the list of fields
            if (!$this->distinct) {
                $blockA->fields[] = [
                    'field' => $blockA->table . '.' . $fieldB,
                    'alias' => $fieldB,
                    'fk' => true
                ];
            }
            return $blockA->alias . '.' . $fieldB
                    . ' = ' . $blockB->alias . '.' . AQL\Block::PRIMARY_KEY_FIELD;
        }
        return false;
    }


    /**
     * Converts shorthand joins into proper sql expressions
     * For example: a {} b on a.b_id {}
     *      "on a.b_id" --> "on a.b_id = b.id"
     */
    public function fixShorthandJoins()
    {
        foreach ($this->blocks as $block) {
            $pattern = AQL\Block::SHORTHAND_JOIN_PATTERN;
            preg_match($pattern, $block->joinOn, $matches);
            if ($matches[1]) {
                $block->joinOn = $matches[1]
                                . ' = '
                                . $block->table . '.'
                                . AQL\Block::PRIMARY_KEY_FIELD;
            }
        }
        return $this;
    }


    /**
     * Gets the columns
     * @param string $table the table to get its columns
     * @return array
     */
    public static function getColumns($table)
    {
        global $db;

        // memoized columns
        static $columns;

        if (!$db) {
            return false;
        }

        if (is_array($columns)) {
            if (array_key_exists($table, $columns)) {
                return $columns[$table];
            }
        }

        $sql = "SELECT * FROM $table LIMIT 0";
        $rs = $db->query($sql);
        $cols = array();
        if ($rs) {
            for ($i = 0; $i < $rs->columnCount(); $i++) {
                $cols[] = $rs->getColumnMeta($i)['name'];
            }
            // memoize
            $columns[$table] = $cols;
        }
        return $cols;
    }


    /**
     * Identify foreign keys
     */
    public function setForeignKeys()
    {
        // for each block, identify the fields that are foreign keys that
        // link to other blocks so we know what fields to update when inserting
        // multiple tables and the order the tables need to be inserted
        foreach ($this->blocks as $block) {
            if (is_array($block->fields)) {
                foreach ($block->fields as $f) {
                    // if this field ends with the id suffix, then check to see if
                    // this is a foreign key for another block in this aql statement
                    $pattern = str_replace(
                        'FOREIGN_KEY_SUFFIX',
                        AQL\Block::FOREIGN_KEY_SUFFIX,
                        AQL\Block::FOREIGN_KEY_PATTERN
                    );
                    preg_match($pattern, $f['field'], $fk);
                    $foreignKey = $fk[1];
                    $foreignTable = $fk[2];
                    if ($this->getBlock($foreignTable)) {
                        $block->foreignKeys[] = [
                            'key' => $foreignKey,
                            'table' => $foreignTable
                        ];
                    }
                }
            }
        }
    }


    /**
     * Applies params (where, limit, order by) to the current aql object
     * TODO: update the AQL object parameters, not just the SQL statements
     */
    public function addParams($params = [])
    {
        $this->createSQL($params);
        return $this;
    }


    /**
     * Gets the block with the corresponding alias
     * @param string $alias the table name (or alias)
     * @return Sky\AQL\Block
     */
    public function getBlock($alias)
    {
        foreach ($this->blocks as $block) {
            if ($block->alias == $alias) {
                return $block;
            }
        }
        return false;
    }

    /**
     * Gets current master database time
     * If no master DB, returns php time
     * @return  string
     */
    public static function now()
    {
        $dbw = self::getMasterDB();

        if (!$dbw) {
            return date('c');
        }

        return sql("SELECT CURRENT_TIMESTAMP as now", $dbw)->now;
    }

}
