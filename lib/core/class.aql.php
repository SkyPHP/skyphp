<?

class aql {
	
	const AQL_VERSION = 2;

/**

	RETRIEVAL FUNCTIONS
		
		self::get_aql()
			@return		(string) aql
			@params		(string) model_name

		self::profile()
			@return		(array) db recordset or null
			@params 	(mixed) model name or aql or aql_array
						(varying) identifier - could be IDE or id.
		
		self::select() 
			@return		(array) nested db recordset or null
			@params 	(mixed) $aql or model name or aql_array
						(array) clause_array
		
		self::sql()
			@return 	(array) pre executed sql array with subqueries
			@params		(string) aql
						(array) clause_array

**/

	public function form($model_name, $ide = null) {
		global $sky_aql_model_path;
		$r = aql::profile($model_name, $ide);
		if (!include($sky_aql_model_path.'/'.$model_name.'/form.'.$model_name.'.php')) {
			trigger_error('<p>AQL Error: <strong>'.$model_name.'</strong> does not have a form associated with it. <br />'.self::error_on().'</p>', E_USER_ERROR);
		}
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
		foreach (array_reverse($codebase_path_arr) as $codebase_path) {
			$model = $codebase_path.$sky_aql_model_path.'/'.$model_name.'/'.$model_name.'.aql';
			if (file_exists($model)) {
				$return = @file_get_contents($model);
				break;
			}
		}
		return $return;
	}
/**
 
**/
	public function profile($param1, $param2, $param3 = false, $aql_statement = null, $sub_do_set = false) {
		if (is_array($param1)) {
			$aql = $param1;  // this is the aql_array
			$model_name_arr = reset($aql);
			$model = $model_name_arr['as'];
		} else if (!self::is_aql($param1))  {
			$aql_statement = ($aql_statement) ? $aql_statement : self::get_aql($param1);
			$model = $param1;
			$param3 && $param3 = $model;
			$aql = aql2array::get($model, $aql_statement);
		} else {
			$aql_statement = $param1;
			$aql = aql2array($param1);
			$model_name_arr = reset($aql);
			$model = $model_name_arr['as'];
		}

		if ($aql) {
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
			$rs = self::select($aql, $clause, $param3, $aql_statement, $sub_do_set);
			return $rs[0];
		} else {
			return false;
		}
	}
/**
 
**/
	public function select($aql, $clause_array = null, $object = false, $aql_statement = null, $sub_do_set = false) {
		global $db, $is_dev;
		if (!is_array($clause_array) && $clause_array === true) $object = true;
		if (!is_array($aql)) {
			if (!self::is_aql($aql)) {
				$m = $aql;
				$aql_statement = self::get_aql($m);
				if (!$aql_statement) {
					trigger_error('<p><strong>AQL Error:</strong> Model <em>'.$m.'</em> is not defined. Could not get AQL statement.<br />'.self::error_on().'</p>', E_USER_ERROR);
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
		if ($object) {
			if ($object !== true && $m) $object = $m;
		}
		if (is_array($clause_array)) $clause_array = self::check_clause_array($aql_array, $clause_array);
		if ($_GET['aql_debug'] && $is_dev) print_a($aql_array);
		$returned = self::make_sql_array($aql_array, $clause_array);
		if ($_GET['aql_debug'] && $is_dev) print_a($returned);
		if ($_GET['refresh'] == 1) $sub_do_set = true;
		return self::sql_result($returned, $object, $aql_statement, $sub_do_set);
	}
/**
 
**/
	public function sql($aql, $clause_array = null) {
		$aqlarr = aql2array($aql);
		if (is_array($clause_array)) $clause_array = self::check_clause_array($aqlarr, $clause_array);
		return self::make_sql_array($aqlarr, $clause_array);
	}
	

/**

	INPUT FUNCTIONS

		self::insert()
			@param		(string) table name
			@param		(array) fields
			@return		(array) inserted recordset or null

		self::update()
			@param		(string) table name
			@param		(array) fields
			@param		(string) identifier
			@return 	(bool)

**/

	public function increment($param1, $param2, $param3, $silent = false) {
		global $dbw;
		if (!$dbw) return false;
		list($table, $field) = explode('.',$param1);
		$id = (is_numeric($param3)) ? $param3 : decrypt($param3, $table);
		if (!is_numeric($id)) {
			!$silent && trigger_error('<p>AQL Error: Third parameter of aql::increment is not a valid idenitifer. '.self::error_on().'</p>', E_USER_ERROR);
			return false;
		}
		if (!$table && $field) {
			!$silent && trigger_error('<p>AQL Error: First parameter of aql::increment needs to be in the form of "table_name.field_name" '.self::error_on().'</p>', E_USER_ERROR);
			return false;
		}
		if (strpos($param2, '-') !== false) $do = ' - '.abs($param2);
		else $do = ' + '.$param2;
		$sql = 	"UPDATE {$table} SET {$field} = {$field} {$do} WHERE id = {$id}";
		$r = $dbw->Execute($sql);
		if ($r === false) {
			!$silent && trigger_error('<p>AQL Error: aql::increment failed. '.$dbw->ErrorMsg().' '.self::error_on().'</p>', E_USER_ERROR);
			return false;
		} else {
			return true;
		}
	}
/**
 
**/
	public function insert($table, $fields, $silent = false) {
		global $dbw, $db_platform, $aql_error_email;
		if (!$dbw) {
			return false;
		}
		if (!is_array($fields)) {
			!$silent && trigger_error('<p>aql::insert expects a \'fields\' array. '.self::error_on().'</p>', E_USER_ERROR);
			return false;
		}
		foreach ($fields as $k => $v) {
			if (!$v) unset($fields[$k]);
		}
		if (!$fields) {
			!$silent && trigger_error('<p>aql::insert was not populated with fields. '.self::error_on().'</p>', E_USER_ERROR);
			return false;
		}
		$result = $dbw->AutoExecute($table, $fields, 'INSERT');

		if ($result === false) {
			if ($aql_error_email) {
				$bt = debug_backtrace();
				@mail($aql_error_email, "Error inserting into table [$table]" , "[insert into $table] " . $dbw->ErrorMsg() . "\n\n" . $bt[1]['file'] . "\nLine: " . $bt[1]['line'] . print_r($fields,1), "From: Crave Tickets <info@cravetickets.com>\r\nContent-type: text/html\r\n");
			}
			if (!$silent) {
				echo "[Insert into {$table}] ".$dbw->ErrorMsg()." ".self::error_on();
				print_a($fields);
				if ( strpos($dbw->ErrorMsg(), 'duplicate key') === false ) trigger_error('', E_USER_ERROR);
			}
			return false;
		} else {
			if (strpos($db_platform, 'postgres') !== false) {
				$sql = "SELECT currval('{$table}_id_seq') as id";
				$s = $dbw->Execute($sql) or trigger_error("<p>$sql<br />".$dbw->ErrorMsg()."<br />$table.id must be of type serial.".self::error_on().'</p>', E_USER_ERROR);
				$id = $s->Fields('id');
			} else {
				$id = $dbw->Insert_ID();
			}
			$aql = "$table {
						*
						where {$table}.id = {$id}
					}";
			return self::select($aql);
		}
	}

/**
 
**/
	public function update($table, $fields, $identifier, $silent = NULL) {
		global $dbw, $aql_error_email;
		
		if (!$dbw) {
			return false;
		}

		$id = (is_numeric($identifier)) ? $identifier : decrypt($identifier, $table);
		if (!is_numeric($id)) trigger_error('<p>AQL Update Error. "'.$identifier.'" is an invalid recordr identifier for table: "'.$table.'" '.self::error_on()."</p>", E_USER_ERROR);

		if (is_array($fields) && $fields) {
			$result = $dbw->AutoExecute($table, $fields, 'UPDATE', 'id = '.$id);
			if ($result === false) {
				$aql_error_email && @mail($aql_error_email, "[update $table $id] " . $dbw->ErrorMsg(), print_r($fields,1).'<br />'.self::error_on(), "From: Crave Tickets <info@cravetickets.com>\r\nContent-type: text/html\r\n");
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

	HELPER FUNCTIONS	below this line // you probably don't want to use any of them on their own

**/

/**

	@function  -- check_clause_array
	@return 	(array) clause array
	@param		(array)	aql
	@param		(array) clause

**/

	function check_clause_array($aql_array, $clause_array) {
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
			foreach ($v as $clause => $value) {
				if (!is_array($value)) { $value = array($value); }
				if ($clause == 'where') {
					$arr = aql2array::prepare_where($value, $aql_array[$table]['table']);
					$clause_array[$table][$clause] = aql2array::check_where($arr, $aql_array[$table]['as']);
				} else {
					$clause_array[$table][$clause] = aql2array::check_clause($value, $aql_array[$table], $aql_array[$table]['fields']);
				}
			}
		}
		return $clause_array;
	}

/**

	@function -- error_on
		returns the trace for when the first aql2 function was called / line number / filename
	@return 	(string)
	@param		n/a

**/

	public function error_on() {
		$trace = debug_backtrace();
		$called = null;
		foreach (array_reverse($trace) as $t) {
			if ($t['class'] == 'aql') {
				$called = $t;
				break;
			}
		}
		return 'Error on line <strong>'.$called['line'].'</strong> of file <strong>'.$called['file'].'</strong>';
	}


/**
		
	@function -- generate_ides
		generates encrypted ID for fields in the ROW that end with _id, is run after record retrieval
	@return		(array) db recordset (flat)
	@param		(array) db recordset (flat)

**/


	public function generate_ides($r) {
		if (!is_array($r)) return false;
		foreach ($r as $k => $v) {
			if (preg_match('/_id$/', $k)) {
				$table_name = preg_replace('/_id$/', '', $k);
				$double = stripos($table_name,'__');
				if ($double !== false) {
					$table_name = substr($table_name, $double + 2);
				}
				if ($v && $table_name)
					$r[$k.'e'] = encrypt($v, $table_name);
			}
		}
		return $r;
	}

/**

	@function -- get_decrypt_key
	@return 	(string) 	the table name
				(false) 	if this has no _ide
	@param		(string) 	

**/

	public function get_decrypt_key($field_name) {
		$table_name = substr($field_name,-4);
		if ( $table_name == '_ide' ) {
			$temp = substr($field_name,0,-4);
			$start = strpos( $temp, '__' );
			if ( $start ) $start += 2;
		//	echo substr( $temp, $start );
			return substr( $temp, $start );
		} else {
			return false;
		}
	}

/**
	
	@function -- get_primary_table
	@return		(string) table_name
	@param		(string) aql

**/

	public function get_primary_table($aql) {
		if (!self::is_aql($aql)) $aql = self::get_aql($aql);
		$t = new aql2array($aql, false);
		return $t->get_primary_table();
	}

/**

	@function -- include_class_by_name
	@return		(null)
	@param		(string) model name

**/

	public function include_class_by_name($model_name) {
		global $sky_aql_model_path;
		@include_once($sky_aql_model_path.'/'.$model_name.'/class.'.$model_name.'.php');
	}

/**
	
	@function -- is_aql
		checks to see if a string is aql or a model name
	@return 	(bool)
	@param		(string) aql or model

**/

	public function is_aql($aql) {
		if (strpos($aql, '{') !== false) return true;
		else return false;
	}


/**

	@function -- sql_result
		- recursive function to do subqueries if they are necessary
	@return		(array) db recordset
	@params		(array) associative array sql / subs / objects
	@params		(bool) is object, chooses whether objects are returned as objects or subqueries

**/

	public function sql_result($arr, $object = false, $aql_statement = null, $sub_do_set = false) {
		global $db;
		$rs = array();
		$r = $db->Execute($arr['sql']);
		if ($r === false) {
			echo 'AQL:'; print_pre($aql_statement);
			echo 'Genereated SQL:'; print_pre($arr['sql']);
			trigger_error('<p>AQL Error. Select Failed. '.self::error_on().'<br />'.$db->ErrorMsg().'</p>', E_USER_ERROR);
		} 
		while (!$r->EOF) {
			$tmp = self::generate_ides($r->GetRowAssoc(false));
			if ($arr['subs']) foreach ($arr['subs'] as $k => $s) {
				$s['sql'] = preg_replace('/\{\$([\w.]+)\}/e', '$placeholder = $tmp["$1"];', $s['sql']);
				if ($placeholder) {
					$tmp[$k] = self::sql_result($s, $object);
				} 
			}
			$placeholder = null;
			if ($arr['objects']) foreach ($arr['objects'] as $k => $s) {
				$m = $s['model'];
				if ($s['plural'] && $s['sub_where']) {
					$clauses = self::get_clauses_from_model($m);
					$min_aql = self::get_min_aql_from_model($m);
					$sub_where = preg_replace('/\{\$([\w.]+)\}/e', '$placeholder = $tmp["$1"];', $s['sub_where']);
					$clauses['where'][] = $sub_where;
					$query = aql::select($min_aql, $clauses);
					if ($query) foreach ($query as $row) {
						$arg = $row[$s['constructor argument']];
						$o = model::get($m, $arg, $sub_do_set);
						if ($object) {
							$tmp[$k][] = $o;
						} else {
							$tmp[$k][] = $o->dataToArray();
						}
					} else {
						if ($object) {
							$tmp[$k][] = new ArrayObject;
						} else {
							$tmp[$k][] = array();
						}
					}
				} else {
					$arg = (int) $tmp[$s['constructor argument']];
					$o = model::get($m, $arg, $sub_do_set);
					if ($object) {
						$tmp[$k] = $o;
					} else {
						$tmp[$k] = $o->dataToArray();
					}
				}
			}
			if ($object && $aql_statement) {
				if ($object === true) {
					$tmp_model = new model(null, $aql_statement);
				} else {
					$tmp_model = model::get($object);
				}
				$tmp_model->loadArray($tmp);
				$rs[] = $tmp_model;
			} else {
				$rs[] = $tmp;
			}
			$r->moveNext();
		}
		return $rs;
	}

/**
	
	@function -- make_sql_array
		recursive function that generates sql from an aql array
	@return		(array) associative array with keys
						sql =>
						sql_count => 
						subs => optional
						objects => optional
	@params		(array) generated by aql2array
				(array) clause array

**/

	public function make_sql_array($arr, $clause_array = null) {
		if (count($arr) == 0) trigger_error('<p>AQL Error: You have an error in your syntax. '.self::error_on().'</p>', E_USER_ERROR);
		$has_aggregate = false;
		$fields = array();
		$left_joined = array();
		$joins = '';
		$from = '';
		$where = array();
		$objects = array();
		$order_by = array();
		$group_by = array();
		$limit = '';
		$offset = '';
		$distinct = false;
		$fk = array();
		foreach ($arr as $t) {
			$table_name = $t['table'];
			if ($t['as']) $table_name .= ' as '.$t['as'];
			if (!$t['on']) {
				if ($from) trigger_error("<p>AQL Error: <strong>{$t['table']} as {$t['as']}</strong> needs to have a left join. You can not have more than one primary table. ".self::error_on().'</p>', E_USER_ERROR);
				$from = $table_name;
				$primary_table = $t['table'];
				$where[] = $t['as'].'.active = 1';
			} else {
				$left_joined[] = $t['table'];
				$joins .= "LEFT JOIN {$table_name} on {$t['on']} and {$t['as']}.active = 1 \n";
			}
			foreach ($t['where'] as $wh) {
				$where[] = $wh;
			}
			
			if (is_array($t['aggregates'])) {
				$fields = $fields + $t['aggregates'];
				$has_aggregate = true;
			}
			if (is_array($t['objects'])) 
				$objects += $t['objects'];
			
			if (is_array($t['fields'])) $fields = $fields + $t['fields'];
			if (is_array($t['subqueries'])) {
				$subs = array();
				foreach ($t['subqueries'] as $k => $q) {
					$subs[$k] = self::make_sql_array($q, $clause_array);
				}
			}
			if ($t['group by']) foreach ($t['group by'] as $gr) {
				$group_by[] = $gr;
			}
			if ($t['order by']) foreach ($t['order by'] as $or) {
				$order_by[] = $or;
			}
			if ($t['limit']) $limit = $t['limit'];
			if ($t['offset']) $offset = $t['offset'];
			
			if ($t['distinct']) $distinct = $t['distinct'];
			if (is_array($t['fk'])) foreach ($t['fk'] as $f_k) {
				$fk[$f_k][] = $t['table'];
			}
			if ($clause_array[$t['as']]) {
				if (is_array($clause_array[$t['as']]['where'])) foreach($clause_array[$t['as']]['where'] as $wh){
					$where[] = $wh;
				}
				if (is_array($clause_array[$t['as']]['order by'])) {
					foreach ($clause_array[$t['as']]['order by'] as $ob) {
						$order_by[] = $ob;
					}
				}
				if (is_array($clause_array[$t['as']]['group by'])) {
					foreach ($clause_array[$t['as']]['group by'] as $ob) {
						$group_by[] = $ob;
					}
				}
				if ($clause_array[$t['as']]['limit'][0]) $limit = $clause_array[$t['as']]['limit'][0];
				if ($clause_array[$t['as']]['offset'][0]) $offset = $clause_array[$t['as']]['offset'][0];
			}
		}

		if ($distinct) $no_ids = true;

		if (!$has_aggregate && !$no_ids) {
			foreach ($arr as $t) {
				$fields[$t['table'].'_id'] = "{$t['as']}.id";
			}
		} else {
			foreach ($arr as $t) {
				if (is_array($t['fields'])) foreach ($t['fields'] as $k => $v) {
					if (!preg_match('/(case|when)/', $v)) $group_by[] = $v;
				}
				if ($t['order by']) foreach ($t['order by'] as $k => $v) {
					$tmp = str_replace(array(' asc', ' desc', ' ASC', ' DESC'),'', $v);
					if (trim($tmp)) {
						if (!preg_match('/(case|when)/', $tmp)) $group_by[] = $tmp;
					}
				}
			}
		}

		$where_text = '';
		$fields_text = '';
		$group_by_text = '';
		$order_by_text = '';
		foreach ($where as $wh) {
			if (!empty($wh)) $where_text .= ' AND '.$wh;
		}
		if (!empty($where_text)) $where_text = 'WHERE '.substr($where_text, 4);
		foreach ($fields as $alias => $field) {
			if (!empty($field)) {
				if ($field != $alias)
					$fields_text .= "{$field} as \"{$alias}\", \n";
				else
					$fields_text .= "{$field}, \n";
			}
		}
		if (!empty($fields_text)) $fields_text = substr($fields_text, 0, -3)." \n";

		$group_by = array_unique($group_by);
		$order_by = array_unique($order_by);

		foreach($group_by as $g) {
			if (!empty($g)) {
				$group_by_text .= $g.', ';
			}
		}
		if ($group_by_text) $group_by_text = 'GROUP BY '.substr($group_by_text, 0, -2);

		foreach ($order_by as $o) {
			if (!empty($o)) {
				$order_by_text .= $o.', ';
			}
		}
		if ($order_by_text) $order_by_text = 'ORDER BY '.substr($order_by_text, 0, -2);
		if ($limit) $limit = 'LIMIT '.$limit;
		if ($offset) $offset = 'OFFSET '.$offset;
		$sql = "SELECT {$distinct} {$fields_text} FROM {$from} \n{$joins} {$where_text} \n{$group_by_text} \n{$order_by_text} \n{$limit} \n{$offset}";
		$sql_count = "SELECT count(*) as count FROM {$from} {$joins} {$where_text}";
		return array('sql' => $sql, 'sql_count' => $sql_count, 'subs' => $subs, 'objects' => $objects, 'primary_table' => $primary_table, 'left_joined' => $left_joined, 'fk' => $fk);
	}


/**

	@function 	value
	@return		(mixed) array or string
	@param		(string) 
	@param		(mixed) array or string

**/

	public function value($param1, $param2) {
		global $db;
		if ( strpos($param1,'{') ) $is_aql = true;
        if ($is_aql) {
            $aql = $param1;
        } else {
            $temp = explode('.',$param1);
            $primary_table = $temp[0];
            $field = $temp[1];
            $aql = "$primary_table { $field }";
        }
        if (!$primary_table) $primary_table = aql::get_primary_table($aql);
        if ( is_numeric($param2) ) $where = "$primary_table.id = $param2";
        else if (!is_array($param2)) {
            $id = decrypt($param2,$primary_table);
            if ( is_numeric( $id ) ) $where = "$primary_table.id = $id";
            else {
                $sql = "select $primary_table.slug from $primary_table where id = 0";
                $r = $db->Execute($sql);
                if ( $db->ErrorMsg() ) return false;
                $where = "$primary_table.slug = '$param2'";
            }
        } else {
        	$multiple = true;
        	$where = $primary_table.'.id in(';
        	foreach($param2 as $v) {
        		$id = $v;
        		if (!is_numeric($v)) $id = decrypt($v, $primary_table);
        		if (is_numeric($id)) $where .= $id.',';
        	}
        	$where = substr($where,0,-1).')';
        }
        $rs = aql::select($aql,array(
            $primary_table => array(
                'where' => $where,
                'order by' => 'id asc'
            )
        ));
        if ($multiple) $return = $rs;
        else if ($is_aql) $return = $rs[0];
        else $return = $rs[0][$field];
        return $return;
	}
}