<?

/**

	@function	aql2array
	@param:  	(string) $aql
	@return:	(array)

	using the aql2array class

**/
	

function aql2array($param1, $param2 = null) {
	if (aql::is_aql($param1)) {
		$r = new aql2array($param1);
		return $r->aql_array;
	} else {
		return aql2array::get($param1, $param2);
	}
}

/**
	
	@class aql2array

**/

class aql2array {
	
	public $pattern = '/(?:(?:^|\s*)(?:\'[\w-.\s]*\s*)*(?<distinct>distinct\s+(?:\bon\b\s+\([\w.]+\)\s+)*)*(?<table_name>\w+)?(?<table_on_as>\s+(?:\bon\b|\bas\b)\s+[\w.=\s\']+)*\s*\{(?<inner>[^\{\}]+|(?R))*\}(?:,)?(?:[\w-.!\s]*\')*)(?=(?:(?:(?:[^"\\\']++|\\.)*+\'){2})*+(?:[^"\\\']++|\\.)*+$)/si';
	public $on_pattern = '/(\bon\b(?<on>.+))(\bas\b)*/mis';
	public $as_pattern = '/(\bas\b(?<as>\s+[\w]+))(\bon\b)*/mis';
	public $object_pattern = '/\[(?<model>[\w]+)(?:\((?<param>[\w.$]+)*\))*\](?<sub>s)?(?:\s+as\s+(?<as>[\w]+))*/';
	public $aggregate_pattern = '/(?<function>[\w]+)\((?<fields>([\w.]+)?(?:[+-\s*]+)*([\w.]+)?)\)(?<post>\s*[-+*])*/mi';
	public $not_in_quotes;
	public $clauses;
	public $comparisons;
	public $comment_patterns = 	array(
									'slashComments' => '/\/\/\/.*$/m',
							      //  'poundComments' => '/#.*$/m',
							        'multiComments' => '/\/\*[\s\S]*?\*\//m',
								);
	public $aql;
	public $aql_array;

	public function __construct($aql, $run = true) {
		$this->clauses = $this->clauses();
		$this->comparisons = $this->comparisons();
		$this->not_in_quotes = self::not_in_quotes();
		$this->aql = $this->strip_comments($aql);
		if ($run)
			$this->aql_array = $this->init($this->aql);	
	}


	public function not_in_quotes() {
		return "(?=(?:(?:(?:[^\"\\']++|\\.)*+\'){2})*+(?:[^\"\\']++|\\.)*+$)";
	}

/**
	
	@function 	add_commas
	@return 	(string) aql
	@param 		(string) aql

		adds commas after field names

**/
	public function add_commas($aql) {
		$aql = preg_replace('/([\w(\')]+\s+as\s+\w+[^\w\{\},])$/mi', '\\1,', $aql);
		return $aql;
	}
/**
	
	@function 	add_table_name
	@return 	(string) field
	@param 		(string) table_name
	@param		(string) field
		
		checks to see if the field name already has a table name (escapes quotes and $comparisons and numbers)

**/
	public function add_table_name($table_name, $field) {
		$field = trim($field);
		//print_pre($field);
		if (strpos($field, '\'') !== false || in_array(trim($field), self::comparisons()) || is_numeric(trim($field)) || $table_name == trim($field)) return $field;
		if (strpos($field, '(') !== false || strpos($field, ')') !== false) {
			$nf =  self::add_table_name($table_name, str_replace(array('(', ')'), '', $field));
			return preg_replace('/[\w.]+/', $nf, $field);
		}
		$rf = explode(' ', $field);
		$f = '';
		foreach ($rf as $r) {
			$r = trim($r);
			if ($r) {
				if (strpos($r,'.') === false && !in_array(trim($r), self::comparisons()) && !is_numeric(trim($r))  && stripos($r, '\'') === false) {
					$f .= trim($table_name).'.'.trim($r).' ';
				} else {
					$f .= $r.' ';
				}
			}
		}
		return trim($f);
	}

/**

	@function 	aggregate_add_table_name
	@return 	(string) aggregate
	@param 		(string) table_name
	@param		(string) aggregate

**/

	public function aggregate_add_table_name($table_name, $field) {
		preg_match_all($this->aggregate_pattern, $field, $matches);
		$r = '';
		foreach ($matches[0] as $k => $v) {
		//	if (!in_array(trim($v), $this->comparisons)) continue;
			$r .= $matches['function'][$k].'(';
			$r .= preg_replace('/([\w.]+)/e', "aql2array::add_table_name($table_name, '\\1')", $matches['fields'][$k]);
			$r .= ') '.$matches['post'][$k].' ';
		}
		return $r;
	}
/**

	@function 	check_join
	@return 	(string) join
	@param		(string) original join
	@param		(string) table name
	@param		(string) table alias
	@param		(string) previous table name
	@param		(string) prvious table alias

		if only the joining table was given, the current table is added

**/
	public function check_join($join, $table, $table_alias, $prev_table, $prev_table_alias) {
		if (!(stripos($join, '.') !==false && stripos($join, '=') !== false)) {
			$join .= " = {$table_alias}.id";
		}
		return $join;
	}	

/**

	@function 	check_where
	@return 	(array) where
	@param 		(array) where
	@param		(string) table name

		parses the where clause and adds the table name to fields that don't have it

**/
	public function check_where($array, $table) {
		if (is_array($array)) foreach ($array as $k => $where) {
			//print_pre($where);
			if (preg_match('/(case|when)/mi', $where)) {
				$array[$k] = aql2array::parse_case_when($where, $table);
			} else {
				$array[$k] = preg_replace('/([()]*[\'%\w\/.#]+[()]*)/mie', "aql2array::add_table_name($table, '\\1')", $where);
			}
		}
		return $array;
	}

	public function check_clause($array, $table, $fields) {
		$aliases = array();
		if (is_array($fields)) {
			$aliases = array_keys($fields);
		}
		if (is_array($array)) {
			foreach ($array as $k => $clause) {
				$cl = explode(' ', trim($clause));
				array_map('trim', $cl);
				foreach ($cl as $i => $c) {
					if (!in_array($c, self::comparisons()) && !in_array($c, $aliases) && !empty($c) && !is_numeric($c) && strpos($c, '.') === false) {
						if (strpos($c, '(') !== false) $c = $this->aggregate_add_table_name($table['as'], $c);
						else $c = $table['as'].'.'.$c;
					} 
					$cl[$i] = $c;
				}
				$array[$k] = implode(' ', $cl);
			}
		}
		return $array;
	}

	public static function clauses() {
		return array(
						'where',
						'group by',
						'order by',
						'having',
						'limit',
						'offset'
					);
	}

	public static function comparisons() {
		return array('length', 'LENGTH', 'ilike', 'ILIKE', 'DISTINCT', 'distinct', 'SELECT', 'select', 'WHERE', 'where', 'FROM', 'from', 'CASE', 'case', 'WHEN', 'when', 'THEN', 'then', 'ELSE', 'else', 'upper', 'lower', 'UPPER', 'LOWER', '*', 'and','or','like','like','AND','OR','LIKE','ILIKE','IS','is','null','in','IN','not','NOT','NULL','false','FALSE','now()','NOW()','asc','ASC','desc','DESC', 'interval', 'INTERVAL', '-', '+', '=', 'true', 'TRUE');
	}

	public static function get($model, $aql = null) {
		if (!$model || $model == 'model') return array();
		if ($GLOBALS['aqlarrays'][$model]) {
			$r = $GLOBALS['aqlarrays'][$model];
		} else {
			if (!$aql) $aql = aql::get_aql($model);
			$r = $GLOBALS['aqlarrays'][$model] = aql2array($aql);
		}
		return $r;
	}

/**

	@function 	get_table_fields
	@return 	(array) fields
	@param 		(string) table name

**/
	public function get_table_fields($table) {
		global $db;
		$cols = $db->MetaColumns($table);
		if (!is_array($cols)) return false;
		$cols = array_keys($cols);
		return array_map('strtolower', $cols);
	}

	public function get_primary_table() {
		$m = $this->split_tables($this->aql);
		return $m['table_name'][0];
	}

/**

	@function 	init
	@return 	(array) aql_array
	@param 		(string) aql
	@parram		(array) parent (if this is a subquery)

**/
	public function init($aql, $parent = null) {
		$aql_array = array();
		$tables = array();
		$fk = array();
		$aql = $this->add_commas($aql);
		// print_pre($aql);
		$m = $this->split_tables($aql);
		// print_pre($m);
		$prev = null;
		foreach ($m['table_name'] as $k => $v) {
			$tmp = array();
			if ($m['distinct'][$k]) $tmp['distinct'] = $m['distinct'][$k];
		//	print_pre($m['distinct'][$k]);
			$on_as = $this->table_on_as($m['table_on_as'][$k]);
		//	print_pre($on_as);
			$table_alias = ($on_as['as']) ? $on_as['as'] : $v;
			$tmp['table'] = $v;
			$tmp['as'] = $table_alias;
			$tables[$v] = $tmp;
			$split_info = $this->inner($m[0][$k], $tmp);
			if ($on_as['on']) {
				$check_join = $this->check_join($on_as['on'], $v, $tmp['as'], $prev['table'], $prev['as']);
				$tmp['on'] = $check_join;
				//$tmp['fk'][] = $check_join['fk'];
			} 
			$split_info['where'] = $this->prepare_where($split_info['where'], $tmp['table']);
			$split_info['where'] = $this->check_where($split_info['where'], $table_alias);
			if (!$prev && $parent) {
				$split_info['where'][] = $this->subquery_where($v, $tmp['as'], $parent['table'], $parent['as']);
			}
		//	print_pre($tmp);
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
			foreach ($tables as $i => $t) {
				if (in_array($i.'_id', $fields)) $aql_array[$table['as']]['fk'][] = $i;
			}
		}
		return $aql_array;
	}
/**

	@function 	inner
	@return 	(array) fields / subqueries / objects / clauses
	@param 		(string) aql
	@param		(array)	if subquery, the parent query array

**/
	public function inner($aql, $parent = null) {
		$tmp = array();
		$subqueries = $this->split_tables($aql, true);
		$subqueries = $subqueries[0];
		// print_a($subqueries);
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
		foreach (array_reverse($this->clauses) as $cl) {
			$split = preg_split("/(\b{$cl}\b){$this->not_in_quotes}/i", $aql, 2);
			if ($split[1]) {
				$tmp[$cl] = $split[1];
				$aql = $split[0];
			}
		}
		preg_match_all($this->object_pattern, $aql, $matches);
		$aql = str_replace($matches[0], '', $aql);
		// print_a($matches);
		foreach ($matches['model'] as $k => $v) {
			$primary_table = aql::get_primary_table($v);
			$constructor_arg = ($matches['param'][$k])?$matches['param'][$k]:$primary_table.'_id';
			$object_tmp = array(
				'model' => $v,
				'primary_table' => $primary_table,
				'constructor argument' => $constructor_arg
			);
			$tmp_as = ($matches['as'][$k]) ? $matches['as'][$k] : $v;
			if ($matches['sub'][$k]) {
				$object_tmp['plural'] = true;
				$object_tmp['sub_where'] = $this->subquery_where($primary_table, $tmp_as, $parent['table'], $parent['as']);
			}
			$tmp['objects'][$tmp_as] = $object_tmp;
		}
		$i = 1;
		foreach(explode(',', $aql) as $field) {
			$field = trim($field);
			if (!empty($field)) {
				if ($field == '*') {
					$fields = $this->get_table_fields($parent['table']);
					if (is_array($fields)) foreach ($fields as $f) {
						$tmp['fields'][$f] = $parent['as'].'.'.$f;
					}
				} else {
					$as = preg_split('/\bas\b'.$this->not_in_quotes.'/', $field);
					$alias = ($as[1]) ? trim($as[1]) : trim($as[0]);
					if (strpos($alias, "'") !== false) {
						$alias = 'field_'.$i;
					}
					if (strpos($alias, ' ') !== FALSE) {
						print_pre($this->aql);
						die('AQL Error: Error converting AQL to Array, expeciting a <strong>COMMA</strong> between fields. <br />Alias: '.$alias.' is invalid.');
					}
					if (trim($as[0]) && $alias) {
						if (preg_match('/(case|when)'.self::not_in_quotes().'/im', $as[0])) {
							$tmp['fields'][$alias] = trim($this->parse_case_when($as[0], $parent['as']));
						} else if (strpos($as[0], ')') !== false) {
							$a = explode('(', trim($as[0]));
							if (!empty($a[0])) {
								if ($alias == $as[0]) {
									$alias = trim($a[0]);
								} 
								$f = trim($this->aggregate_add_table_name($parent['as'], $as[0]));
								$tmp['aggregates'][$alias] = $f;
							} else {
								$tmp['fields'][$alias] = $as[0];
							}
						} else {
							$tmp['fields'][$alias] = trim($this->add_table_name($parent['as'],$as[0]));
						}
					}
				}
				$i++;
			}
		}
		$tmp['order by'] = $this->check_clause(explode(',', $tmp['order by']), $parent, $tmp['fields']);
		$tmp['group by'] = $this->check_clause(explode(',', $tmp['group by']), $parent, $tmp['fields']);
		$tmp['where'] = preg_split('/\band\b(?=(?:(?:(?:[^()]++|\\.)*+[()]){2})*+(?:[^())]++|\\.)*+$)/i', $tmp['where']);
		return $tmp;
	}
/**

	@function 	make_join_via_tables
	@return 	(string) join
	@return		(null) if no join
	@param 		(array) table info (name and alias)
	@param 		(array)	tables in query

		figures out what table to join on.

**/
	public function make_join_via_tables($table, $tables) {
		$join = null;
		foreach ($tables as $t) {
			if ($t['as'] != $table['as']) {
				$join = $this->make_join($table['table'], $table['as'], $t['table'], $t['as']);
				//print_pre($join);
				if ($join['join']) break;
			}
		}
		return $join;
	}
/**

	@function 	make_join
	@return 	(string) join
	@return		(null) if no join
	@param		(string) original join
	@param		(string) table name
	@param		(string) table alias
	@param		(string) previous table name
	@param		(string) prvious table alias

**/
	public function make_join($table, $table_as, $prev_table, $prev_table_as) {
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
		return array('join' => $join, 'fk' => $fk);
	}

/**

	@funciton	parse_case_when
	@return 	(string)
	@param		(string)

**/

	public function parse_case_when($str, $table) {
		preg_match_all('/(?:case\s+when)\s+(?<condition>.+)?(?:\bthen\b)\s+(?<result>.+)(\belse\b)(?<default>.+)\s+end/mi', trim($str), $matches);
		$cond = self::check_where($matches['condition'], $table);
		$cond = $cond[0];
		if (preg_match())
		$str = 'CASE WHEN '.$cond.' THEN '.$matches['result'][0].' ELSE '.$matches['default'][0].' END';
		return $str;
	}

/**
	
	@function	prepare_where
	@return		(array) where
	@param		(array)	where
	@param		(table) table name

	checks for ide fields and and changes them to id and decrypts

**/

	public function prepare_where($where, $table) {
		if (is_array($where)) foreach ($where as $k => $v) {
			preg_match_all('/\b(?<field>[\w.]*ide)\s*=\s*(?<ide>[\w\']+)?/i', trim($v), $matches);
			if (!empty($matches['field'][0])) {
				$field = $matches['field'][0];
				$ide = str_replace("'", '', $matches['ide'][0]);
				$table_field = explode('.', $field);
				$field_name = ($table_field[1]) ? $table_field[1] : $table_field[0];
				$tmp = self::table_name_from_id_field($field_name);
				if ($table_field[1]) {
					$table_name = ($tmp) ? $tmp : $table_field[0];
				} else {
					$table_name = ($tmp) ? $tmp : $table;
				}
				$id = decrypt($ide, $table_name);
				$where[$k] = preg_replace('/ide$/', 'id', $field). ' = '.$id;
			}
		}
		return $where;
	}
/**
	
	@function	table_name_from_id_field
	@return		(string) table name
	@param		(string) field

**/
	public function table_name_from_id_field($field) {
		$field = explode('__', $field);
		$field = ($field[1]) ? $field[1] : $field[0];
		if ($field == 'id' || $field == 'ide') return null;
		$field = preg_replace(array('/_id$/', '/_ide$/'), '', $field);
		return $field;
	}
/**
	
	@function	split_tables
	@return		(array) matches
	@param		(string) aql
	@param		(bool) if looking for subqueries / default false

		finds things that look like tables

**/
	public function split_tables($aql, $sub = false) {
		if ($sub) $aql = substr($aql, strpos($aql, '{') + 1);
		preg_match_all($this->pattern, trim($aql), $matches);
		return $matches;
	}
/**
	
	@function	strip_comments
	@return		(string) aql
	@param		(string) aql

**/
 	function strip_comments($str) {
        foreach ($this->comment_patterns as $pattern) {
            $str = preg_replace($pattern, '', $str);
        }
        return $str;
    }
/**
	
	@function	subquery_where
	@return		(string) where
	@param		(string) table name
	@param		(string) table alias
	@param		(strign) parent query table name
	@param		(string) parent table alias

	the where clause for connecting a subquery

**/
	public function subquery_where($table, $table_as, $prev_table, $prev_table_as) {
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
	
	@function	table_on_as
	@return		(array) on / as
	@param		(string) table header, stuff after table name

**/
	public function table_on_as($str = null) {
		if (!$str) return null;
		preg_match($this->on_pattern, $str, $matches);
		preg_match($this->as_pattern, $str, $matches2);
		return array(
			'on' => trim($matches['on']),
			'as' => trim($matches2['as'])
		);
	}

}