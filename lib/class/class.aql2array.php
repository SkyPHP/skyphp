<?

/**
	
	@class aql2array

**/

class aql2array {
	
	// setting for if we're storing the aqlarrays in memcache
	static $use_mem = true;
	static $mem_duration = '1 day';
	static $mem_type = 'mem';

	static $metaColumns = array();
	static $aqlArrays = array();
	static $aqls = array();

	static $pattern = '/(?:(?:^|\s*)(?:\'[\w-.\s]*\s*)*(?<distinct>(?<primary_distinct>primary_)*distinct\s+(?:\bon\b\s+\([\w.]+\)\s+)*)*(?<table_name>\w+)?(?<table_on_as>\s+(?:\bon\b|\bas\b)\s+[\w-\(\).=\s\']+)*\s*\{(?<inner>[^\{\}]+|(?R))*\}(?:,)?(?:[\w-.!\s]*\')*)(?=(?:(?:(?:[^"\\\']++|\\.)*+\'){2})*+(?:[^"\\\']++|\\.)*+$)/si';
	static $on_pattern = '/(\bon\b(?<on>.+))(\bas\b)*/mis';
	static $as_pattern = '/(\bas\b(?<as>\s+[\w]+))(\bon\b)*/mis';
	static $object_pattern = '/\[(?<model>[\w]+)(?:\((?<param>[\w.$]+)*\))*\](?<sub>s)?(?:\s+as\s+(?<as>[\w]+))*/';
	static $aggregate_pattern = '/(?<function>[\w]+)\((?<fields>([^)]+)?(?:[+-\s*]+)*([\w.]+)?)\)(?<post>\s*[-+*])*/mi';
	static $not_in_quotes = "(?=(?:(?:(?:[^\"\\']++|\\.)*+\'){2})*+(?:[^\"\\']++|\\.)*+$)";
	static $clauses = array(
						'where',
						'group by',
						'order by',
						'having',
						'limit',
						'offset'
					);
	static $comparisons = array('_T_AQL_ESCAPED_', 'case', 'CASE', 'when', 'WHEN', 'end', 'END', 'length', 'LENGTH', 'ilike', 'ILIKE', 'DISTINCT', 'distinct', 'SELECT', 'select', 'WHERE', 'where', 'FROM', 'from', 'CASE', 'case', 'WHEN', 'when', 'THEN', 'then', 'ELSE', 'else', 'upper', 'lower', 'UPPER', 'LOWER', '*', 'and','or','like','like','AND','OR','LIKE','ILIKE','IS','is','null','in','IN','not','NOT','NULL','false','FALSE','now()','NOW()','asc','ASC','desc','DESC', 'interval', 'INTERVAL', '-', '+', '=', 'true', 'TRUE', '!', 'trim', 'TRIM', '\\', 'to_char', 'TO_CHAR', 'DATE_FORMAT', 'date_format');
	static $comment_patterns = array(
									'slashComments' => "/\/\/(?=(?:(?:(?:[^\"\\']++|\\.)*+\'){2})*+(?:[^\"\\']++|\\.)*+.*$)$/m",
							      //  'poundComments' => '/#.*$/m',
							        'multiComments' => '/\/\*[\s\S]*?\*\//m',
								);
	public $aql;
	public $aql_array;

	public function __construct($aql, $run = true) {

		$aql = $this->strip_comments($aql);		// strip comments
		$this->aql = $this->prepAQL($aql);		// remove extra whitespace

		if (!$run) return;
		
		$key = $this->getMemKey($this->aql);
		$duration = self::$mem_duration;

		$store_arr = self::_getStoreFn($key, $duration);
		$fetch_fn = self::_getFetchFn($key);

		if (!self::$use_mem || $_GET['refresh']) {
			$this->aql_array = $this->init($this->aql);
			if (self::$use_mem) { $store_arr($this->aql_array); }
		} else if (!$this->aql_array = $fetch_fn()) {
			$this->aql_array = $this->init($this->aql);
			$store_arr($this->aql_array);			
		}

	}

	private function _getStoreFn($key, $duration) {
		
		$fns = array(
			'mem' => function($val) use($key, $duration) {
				return mem($key, $val, $duration);
			},
			'disk' => function($val) use($key, $duration) {
				return disk($key, serialize($val), $duration);
			}
		);

		if (!array_key_exists(self::$mem_type, $fns)) {
			throw new Exception('Invalid mem type.');
		}

		return $fns[self::$mem_type];

	}

	private function _getFetchFn($key) {

		$fns = array(
			'mem' => function() use($key) {
				return mem($key);
			},
			'disk' => function() use($key) {
				return unserialize(disk($key));
			}
		);

		if (!array_key_exists(self::$mem_type, $fns)) {
			throw new Exception('Invalid mem type.');
		}

		return $fns[self::$mem_type];

	}

	public function getMemKey($aql) {
		return sprintf('aql:v%s:%s', aql::AQL_VERSION, sha1($aql));
	}

	public function prepAQL($aql) {
		return trim(preg_replace('/\s+/', ' ', $aql));
	}

/**
	
	@function 	add_commas
	@return 	(string) aql
	@param 		(string) aql

		adds commas after field names

**/
	public function add_commas($aql) {
		return preg_replace('/([\w(\')]+\s+as\s+\w+[^\w\{\},])$/mi', '\\1,', $aql);
	}

	public function replace_escaped_quotes($aql) {
		return preg_replace("/\\\'/mi", '_T_AQL_ESCAPED_', $aql);
	}

	public function put_back_escaped_quotes($aql) {
		return preg_replace('/_T_AQL_ESCAPED_/', "\\'", $aql);
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
		// print_pre($field);
		if (strpos($field, '\'') !== false || in_array(trim($field), self::$comparisons) || is_numeric(trim($field)) || $table_name == trim($field)) return $field;
		if (strpos($field, '(') !== false || strpos($field, ')') !== false) {
			if (preg_match(self::$aggregate_pattern, $field)) return self::aggregate_add_table_name($table_name, $field);
			$nf = self::add_table_name($table_name, str_replace(array('(', ')'), ' ', $field));
			return preg_replace('/[\w.%]+/', $nf, $field);
		}
		$rf = explode(' ', $field);
		$f = '';
		foreach ($rf as $r) {
			$r = trim($r);
			if ($r) {
				if (	strpos($r,'.') === false 
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

	@function 	aggregate_add_table_name
	@return 	(string) aggregate
	@param 		(string) table_name
	@param		(string) aggregate

**/

	public function aggregate_add_table_name($table_name, $field) {
		preg_match_all(self::$aggregate_pattern, $field, $matches);
		$r = '';
		foreach ($matches[0] as $k => $v) {
		//	if (!in_array(trim($v), $this->comparisons)) continue;
			$r .= $matches['function'][$k].'(';
			$r .= preg_replace('/([\w-.%\'\/]+)/e', "aql2array::add_table_name($table_name, '\\1')", $matches['fields'][$k]);
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
		if (is_numeric($join)) return $join;
		if (!(stripos($join, '.') !== false && stripos($join, '=') !== false)) {
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
	public function check_where($array, $table = null) {
		if (!$table) return $array;
		if (is_array($array)) foreach ($array as $k => $where) {
			if (preg_match('/(?:case\s+when)'.self::$not_in_quotes.'/mi', $where)) {
				$array[$k] = aql2array::parse_case_when($where, $table);
			} else {
				$n = preg_replace('/([()]*[\'%\w\/.#!@$%^&*\\\{\}]+[()]*)'.self::$not_in_quotes.'/mie', "aql2array::add_table_name($table, '\\1')", $where);
				$array[$k] = self::put_back_escaped_quotes($n);
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
				if (is_string($clause) && preg_match('/(case|when)/mi', $clause)) {
					$array[$k] = self::parse_case_when($clause, $table);
				} else {
					$cl = explodeOnWhitespace($clause);
					foreach ($cl as $i => $c) {
						if (!in_array($c, self::$comparisons) 
							&& !empty($c) 
							&& !is_numeric($c) 
							&& strpos($c, '.') === false
							&& strpos($c, '\'') === false
							&& !in_array($c, $aliases)) {
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
		}
		return $array;
	}

	public static function get($model, $aql = null) {
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

	@function 	get_table_fields
	@return 	(array) fields
	@param 		(string) table name

**/
	public function get_table_fields($table) {
		
		if (array_key_exists($table, self::$metaColumns)) {
			return self::$metaColumns[$table];
		}

		global $db;
		
		$cols = $db->MetaColumns($table);
		$cols = (is_array($cols))
			? array_map('strtolower', array_keys($cols))
			: array();

		return self::$metaColumns[$table] = $cols;

	}

	public function table_field_exists($table, $field) {
		$fields = self::get_table_fields($table);
		if (!$fields) return false;
		return (bool) in_array($field, $fields);
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
		$aql = $this->replace_escaped_quotes($this->add_commas($aql));
		$m = $this->split_tables($aql);
		$prev = null;
		foreach ($m['table_name'] as $k => $v) {
			$tmp = array();
			if ($m['primary_distinct'][$k]) $tmp['primary_distinct'] = true;
			else if ($m['distinct'][$k]) $tmp['distinct'] = $m['distinct'][$k];
			$on_as = $this->table_on_as($m['table_on_as'][$k]);
			$table_alias = ($on_as['as']) ? $on_as['as'] : $v;
			$tmp['table'] = $v;
			$tmp['as'] = $table_alias;
			$tables[$v] = $tmp;
			$split_info = $this->inner($m[0][$k], $tmp);
			if ($on_as['on']) {
				$check_join = $this->check_join($on_as['on'], $v, $tmp['as'], $prev['table'], $prev['as']);
				$tmp['on'] = $check_join;
			} 
			$split_info['where'] = $this->prepare_where($split_info['where'], $tmp['table']);
			$split_info['where'] = $this->check_where($split_info['where'], $table_alias);
			if (!$prev && $parent) {
				$split_info['where'][] = $this->subquery_where($v, $tmp['as'], $parent['table'], $parent['as']);
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

	@function 	inner
	@return 	(array) fields / subqueries / objects / clauses
	@param 		(string) aql
	@param		(array)	if subquery, the parent query array

**/
	public function inner($aql, $parent = null) {
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
			$constructor_arg = ($matches['param'][$k])?$matches['param'][$k]:$primary_table.'_id';
			$object_tmp = array(
				'model' => $v,
				'primary_table' => $primary_table,
				'constructor argument' => $constructor_arg
			);
			$tmp_as = ($matches['as'][$k]) ? $matches['as'][$k] : $v;
			if ($matches['sub'][$k]) {
				$object_tmp['plural'] = true;
				$object_tmp['sub_where'] = $this->subquery_where($primary_table, $primary_table, $parent['table'], $parent['as']);
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
			
			if (empty($field)) return;

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

			if (!$as[0] || !$alias) return;

			if (preg_match("/(case|when){$o->not_in_quotes}/im", $as[0])) {
				// htis is a case when
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
					$add_field($alias, $o->aggregate_add_table_name($parent['as'], $as[0]), 'aggregates');
				} else {
					$add_field($alias, $as[0]);
				}
			} else {
				// regular field
				$add_field($alias, trim($o->add_table_name($parent['as'], $as[0])));
			}

			$i++;
		}, $this);

		if (!$tmp['fields']) $tmp['fields'] = array();
		if (!$tmp['aggregates']) $tmp['aggregates'] = array();

		foreach (array('order by', 'group by') as $cl) {
			$tmp[$cl] = $this->check_clause(
				explodeOnComma($tmp[$cl]),
				$parent,
				array_merge($tmp['fields'], $tmp['aggregates'])
			);
		}

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
		preg_match_all('/(?:case\s+when)\s+(?<condition>.+)?(?:\bthen\b)\s+(?<result>.+)(\belse\b)(?<default>.+)\s+end\b\s*(?<other>.+)*/msi', trim($str), $matches);
		$cond = self::check_where($matches['condition'][0], $table);
		$str = 'CASE WHEN '.$cond.' THEN '.$matches['result'][0].' ELSE '.$matches['default'][0].' END '.$matches['other'][0];
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
		if ($sub) {
			$aql = substr($aql, strpos($aql, '{') + 1);
		}
		preg_match_all(self::$pattern, trim($aql), $matches);
		return $matches;
	}
/**
	
	@function	strip_comments
	@return		(string) aql
	@param		(string) aql

**/
 	function strip_comments($str) {
        foreach (self::$comment_patterns as $pattern) {
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
		preg_match(self::$on_pattern, $str, $matches);
		preg_match(self::$as_pattern, $str, $matches2);
		return array(
			'on' => trim($matches['on']),
			'as' => trim($matches2['as'])
		);
	}

}