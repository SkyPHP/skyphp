<?php

namespace Sky\AQL;

/**
 * AQL statements are comprised of one or more Blocks.  Each Block corresponds to a table
 * in the database.
 */
class Block {

    /**
     * Regex pattern for parsing outer blocks, i.e.
     *      primary_table as primary on primary.id = fk.primary_id { inner }
     */
    const OUTER_PATTERN = '/((distinct)\s+(on\s*\(\s*([\w\.]+)\s*\)\s*)*)*((\S+)(\s+as\s+(\S+))*(\s+on\s+([^{}]+))*\s*\{(([^{}]*|(?R))*)\})(\,)*/is';

    /**
     * Regex pattern for parsing a field string
     */
    const FIELD_PATTERN = '/((\[(\w*)(\((\w*)\))*\](s)*)|((avg|count|first|last|max|min|sum)\(([\w\.\*]*)\))|([\w\.]*))(\s+as\s+(\S+))*/is';

    /**
     * Regex pattern for parsing a foreign key
     */
    const FOREIGN_KEY_PATTERN = '/^\w*\.((\w*)FOREIGN_KEY_SUFFIX)$/i';

    /**
     * Regex pattern for parsing a foreign key
     */
    const SHORTHAND_JOIN_PATTERN = '/^\s*([\w]+\.[\w]+)\s*$/';

    /**
     *
     */
    const PRIMARY_KEY_FIELD = 'id';
    const FOREIGN_KEY_SUFFIX = '_id';
    const ENCRYPTED_SUFFIX = 'e';
    const SEQUENCE_SUFFIX = '_id_seq'; // i.e. Postgres

    /**
     * @var string
     */
    public $statement;

    /**
     * @var string
     */
    public $table;

    /**
     * @var string
     */
    public $alias;

    /**
     * @var
     */
    public $joinOn;

    /**
     * @var array
     */
    public $fields;

    /**
     * @var bool
     */
    public $distinct;

    /**
     * @var array
     */
    public $aggregates;

    /**
     * A list of the foreign key fields used to determine that those foreign blocks
     * should be saved to the database before this block.
     * @var array
     */
    public $foreignKeys;

    /**
     * @var
     */
    public $where;

    /**
     * @var string
     */
    public $orderBy;

    /**
     * @var
     */
    public $limit;

    /**
     * @var string
     */
    public $groupBy;

    /**
     * @param string $simple_aql AQL statement containing a single block
     * @param array $params
     * TODO: distinct | distinct on
     */
    public function __construct($simple_aql, $params = [])
    {
        $pattern = static::OUTER_PATTERN;
        #d($simple_aql);
        preg_match($pattern, $simple_aql, $pieces);
        #d($pieces);
        $this->statement = $pieces[5];
        $this->table = $pieces[6];
        $this->alias = $pieces[8] ?: $this->table; // alias is never blank
        $this->distinct = $params['distinct'];
        $this->joinOn = $pieces[10];
        $this->parseInner($pieces[11]);
    }

    /**
     * Parses the characters between the curly brackets in the AQL statement.
     * Expected format is:
     *      field1,
     *      field2,
     *      where f2 = 1
     *      and f4 is null
     *      order by a1, b2 desc
     *      limit 1
     *      table2 { t2 }
     *      table3 { t3 },  <-- comma separates multiple nested queries
     *      table4 { t4 }
     *
     * @param string $str
     */
    private function parseInner($str)
    {
        # 1. detect nested blocks -- and get them out of the way and deal with them later
        $pattern = static::OUTER_PATTERN;
        preg_match_all($pattern, $str, $matches);
        $subs = $matches[0];
        foreach ($subs as $sub) {
            $str = str_replace($sub, null, $str);
        }

        # 2. split: fields, where, order by, limit
        $keywords = [
           'where' => 'where',
           'orderBy' => 'order by',
           'limit' => 'limit'
        ];
        $pattern = '/(' . implode('|', $keywords) . ')/i';
        $splits = preg_split($pattern, $str, null, PREG_SPLIT_DELIM_CAPTURE);

        # 3. create fields array and save the keyword values to this object
        $fields = null;
        for ($i = 0; $i < count($splits); $i++) {
            // if this is the first split and it's not a keyword, it must be fields csv
            // TODO: don't explode comma that is inside parens, ie. fn(1,2) as fn
            if ($i == 0 && !in_array($splits[$i], $keywords)) {
                $fields = explode(',', $splits[$i]);
                continue;
            }
            // otherwise, save the keyword values to this object
            $property = array_search(strtolower($splits[$i]), $keywords);
            if ($property) {
                $this->$property = trim($splits[$i + 1]);
                $i++;
                continue;
            }
        }

        # 4. add the primary key to the fields array
        if (!$this->distinct) {
            $fields[] = 'id AS ' . $this->table . static::FOREIGN_KEY_SUFFIX;
        }

        # 5. parse the fields array
        if (is_array($fields)) {
            $fields = array_unique($fields);
            foreach ($fields as $field) {
                $this->parseField($field);
            }
        }
    }

    /**
     * @param string $str string representation of the "field"
     */
    private function parseField($str)
    {
        $str = trim($str);
        $pattern = static::FIELD_PATTERN;
        preg_match($pattern, $str, $matches);

        #d($matches);

        // this is an object, make sure the foreign key is in our list of fields
        if ($matches[3]) {
            $fk = $matches[5];
            // add this inferred field
            if ($fk && !$this->distinct) {
                // add the table alias prefix
                if (!strpos($fk, '.')) {
                    $fk = $this->alias . '.' . $fk;
                }
                $alias = substr($fk, strrpos($fk, '.') + 1);
                $this->fields[] = [
                    'field' => $fk,
                    'alias' => $alias,
                    'fk' => true
                ];
            }
            $this->objects[] = [
                'model' => $matches[3],
                'fk' => $alias, // this is the explicitly set one-to-one foreign key
                'type' => $matches[6] ? 'many' : 'one',
                'alias' => $matches[12]
            ];
            return;
        }

        // aggregate function
        if ($matches[8]) {
            $field = $matches[9];
            // add the table alias prefix
            if (!strpos($field, '.') && $field != '*') {
                $field = $this->alias . '.' . $field;
            }
            $this->aggregates[] = [
                'function' => $matches[8],
                'field' => $field,
                'alias' => $matches[12]
            ];
            return;
        }

        // regular field
        if ($matches[10]) {
            $field = $matches[10];
            // add the table alias prefix
            if (!strpos($field, '.')) {
                $field = $this->alias . '.' . $field;
            }
            $default_alias = substr($field, strrpos($field, '.') + 1);
            $this->fields[] = [
                'field' => $field,
                'alias' => $matches[12] ?: $default_alias
            ];
            return;
        }
    }

}
