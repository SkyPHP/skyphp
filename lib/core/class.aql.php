<?

class aql {
	
	const AQL_VERSION = 2;

	public static $errors = array();

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
		global $sky_aql_model_path, $r, $p;
		if ($ide) {
			$o = new $model_name($ide, null, true);
		} else {
			$o = new $model_name;
		}

		if (is_assoc($r) && !$ide) {
			$o->_data = array_merge($o->_data, $r);
		}

		$r = $o;

		$css_path = $sky_aql_model_path.$model_name.'/form.'.$model_name.'.css';
		$js_path = $sky_aql_model_path.$model_name.'/form.'.$model_name.'.js';

		if (file_exists_incpath($css_path)) {
			$p->css[] = '/' . $css_path;
		}

		if (file_exists_incpath($js_path)) {
			$p->js[] = '/' . $js_path;
		}
		
		$path = $sky_aql_model_path . $model_name .'/form.' . $model_name .'.php';

		if (!file_exists_incpath($path)) {
			throw new Exception($sky_aql_model_path.'<p>AQL Error: <strong>'.$model_name.'</strong> does not have a form associated with it.</p>');
			return;
		}
		include($path);
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
		$sql = 	"UPDATE {$table} SET {$field} = {$field} {$do} WHERE id = {$id}";
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
				aql::$errors[] = "[Error insert into $table] " . $dbw->ErrorMsg();
			}
			return false;
		} else {
			if (strpos($db_platform, 'postgres') !== false) {
				$sql = "SELECT currval('{$table}_id_seq') as id";
				$s = $dbw->Execute($sql);
				if ($s === false) {
					if (aql::in_transaction()) {
						aql::$errors[] = 'AQL Insert Error (getID) ['.$table.'] '. $dbw->ErrorMsg() . print_r($fields, true);
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
	public function update($table, $fields, $identifier, $silent = NULL) {
		global $dbw, $aql_error_email;
		
		if (aql::in_transaction()) $silent = true;

		if (!$dbw) {
			return false;
		}

		$id = (is_numeric($identifier)) ? $identifier : decrypt($identifier, $table);
		if (!is_numeric($id)) {
			trigger_error('<p>AQL Update Error. "'.$identifier.'" is an invalid record identifier for table: "'.$table.'" '.self::error_on()."</p>", E_USER_ERROR);
		}

		if (is_array($fields) && $fields) {
			$result = $dbw->AutoExecute($table, $fields, 'UPDATE', 'id = '.$id);
			if ($result === false) {
				$aql_error_email && @mail($aql_error_email, 'AQL Update Error', "[update $table $id] " . $dbw->ErrorMsg() . print_r($fields,1).'<br />'.self::error_on(). '<br />Stack Trace: <br />' . print_r($bt, true) .'</pre>', "From: Crave Tickets <info@cravetickets.com>\r\nContent-type: text/html\r\n");
				if (aql::in_transaction()) {
					aql::$errors[] = "[update $table $id] " . $dbw->ErrorMsg() . print_r($fields,1);
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

	Transaction functions

**/

	public static function start_transaction() {
		global $dbw;
		if (!$dbw) return false;
		aql::$errors = array();
		$dbw->StartTrans();
	}

	public static function complete_transaction() {
		global $dbw;
		if (!$dbw) return false;
		$dbw->CompleteTrans();
	}

	public static function transaction_failed() {
		global $dbw;
		if (!$dbw) return true;
		return $dbw->HasFailedTrans();
	}

	public static function fail_transaction() {
		global $dbw;
		if (!$dbw) return false;
		$dbw->FailTrans();
	}

	public static function in_transaction() {
		global $dbw;
		if (!$dbw) return false;
		if ($dbw->transOff) return true;
		return false;
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

			if (!is_array($v)) continue;

			foreach ($v as $clause => $value) {

				if ($clause == 'where') {
					$value = (is_array($value)) ? $value : array($value);
					$arr = aql2array::prepare_where($value, $aql_array[$table]['table']);
					$clause_array[$table][$clause] = aql2array::check_where($arr, $aql_array[$table]['as']);
				} else {
					$value = (is_array($value)) ? $value : explodeOnComma($value);
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
		if (class_exists($model_name)) return;
		$path = $sky_aql_model_path.'/'.$model_name.'/class.'.$model_name.'.php';
		if (file_exists_incpath($path)) {
			include $path;
		}
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

	public function sql_result($arr, $settings, $db_conn = null) {

		global $db, $fail_select;

		if (!$db_conn) $db_conn = $db;

		$silent = aql::in_transaction();

		$object = $settings['object'];
		$aql_statement = $settings['aql_statement'];
		$sub_do_set = $settings['sub_do_set'];

		$select_type = $settings['select_type'];
		if (!$select_type) $select_type = 'sql';

		$rs = array();
		$r = $db_conn->Execute($arr[$select_type]);
		if ($r === false) {
			if (!$silent) {
				echo 'AQL:'; print_pre($aql_statement);
				echo 'Genereated SQL:'; print_pre($arr['sql']);
				trigger_error('<p>AQL Error. Select Failed. '.self::error_on().'<br />'.$db_conn->ErrorMsg().'</p>', E_USER_ERROR);
			} else {
				if (aql::in_transaction()) aql::$errors[] = $db_conn->ErrorMsg();
				return $rs;
			}
		} 
		while (!$r->EOF) {
			$tmp = self::generate_ides($r->GetRowAssoc(false));
			if ($arr['subs']) foreach ($arr['subs'] as $k => $s) {
				$s['sql'] = preg_replace('/\{\$([\w.]+)\}/e', '$placeholder = $tmp["$1"];', $s['sql']);
				if ($placeholder) {
					$params = array(
						'object' => $object,
					);
					$tmp[$k] = self::sql_result($s, $params, $db_conn);
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
					$query = aql::select($min_aql, $clauses, null, null, $sub_do_set, $db_conn);
					if ($query) foreach ($query as $row) {
						$arg = $row[$s['constructor argument']];
						$o = Model::get($m, $arg, $sub_do_set);
						if ($object) $tmp[$k][] = $o;
						else $tmp[$k][] = $o->dataToArray();
					}
				} else {
					$arg = (int) $tmp[$s['constructor argument']];
					$o = Model::get($m, $arg, $sub_do_set);
					if ($object) {
						$tmp[$k] = $o;
					} else {
						$tmp[$k] = $o->dataToArray();
					}
				}
			}
			if ($object && $aql_statement) {
				if ($object === true) {
					$tmp_model = new Model(null, $aql_statement);
				} else {
					$tmp_model = Model::get($object);
				}
				$tmp_model->loadArray($tmp);
				$tmp_model->_token = $tmp_model->getToken();
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
		
		if (count($arr) == 0) {
			throw new Exceptino('AQL Error: You have an error in your syntax.');
			return;
		}

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
			if ($t['as']) { 
				$table_name .= ' as '.$t['as'];
			}
			if (!$t['on']) {
				if ($from) {
					$error = sprintf('AQL Error: [%s as %s] needs to have a left join. 
						You cannot have more than one primary table.', $t['table'], $t['as']);
					throw new Exception($error);
					return;
				}
				$from = $table_name;
				$primary_table = $t['table'];
				$aliased_from = $t['as'];
				$where[] = $t['as'].'.active = 1';
			} else {
				$left_joined[] = $t['table'];
				$joins .= "LEFT JOIN {$table_name} on {$t['on']} and {$t['as']}.active = 1 \n";
			}
			foreach ($t['where'] as $wh) {
				$where[] = $wh;
			}
			
			if (is_array($t['aggregates']) && count($t['aggregates'])) {
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
			if ($t['primary_distinct']) $primary_distinct = true;
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
		} else if ($has_aggregate) {
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
		$sql_list = "SELECT id, id as {$aliased_from}_id FROM ( SELECT DISTINCT on (q.id) id, row FROM (SELECT $aliased_from.id, row_number() OVER($order_by_text) as row FROM {$from} {$joins} {$where_text} {$order_by_text} ) as q ) as fin ORDER BY row {$limit} {$offset} ";
		if ($primary_distinct) $sql = $sql_list;
		return array('sql' => $sql, 'sql_count' => $sql_count, 'sql_list' => $sql_list, 'subs' => $subs, 'objects' => $objects, 'primary_table' => $primary_table, 'left_joined' => $left_joined, 'fk' => $fk);
	}


/**

	@function 	value
	@return		(mixed) array or string
	@param		(string) 
	@param		(mixed) array or string

**/

	public function value($param1, $param2, $options = array()) {
		
		if (!$param2) {
			return null;
		}

		global $db;		

		if (is_object($options) && get_class($options) == 'ADODB_postgres7') {
			$db_conn = $options;
			$options = array();
		} 

		$db_conn = if_not($db_conn, $options['db']);
		$db_conn = if_not($db_conn, $db);
		$is_aql = aql::is_aql($param1);

        if ($is_aql) {
            $aql = $param1;
            $primary_table = aql::get_primary_table($aql);
        } else {
            list($primary_table, $field) = explode('.',$param1);
            $aql = "$primary_table { $field }";
        }
        
        $decrypt = function($r) use($primary_table) {
    		return (is_numeric($r))
    				? $r
    				: decrypt($r, $primary_table);
    	};

        if ( is_numeric($param2) ) {
	        $where = "{$primary_table}.id = {$param2}";
	    } else if (!is_array($param2)) {
            $id = $decrypt($param2);
            if ( is_numeric( $id ) ) $where = "$primary_table.id = $id";
            else {
                $sql = "SELECT $primary_table.slug from $primary_table where id = 0";
                $r = $db_conn->Execute($sql);
                if ( $db_conn->ErrorMsg() ) return false;
                $where = "$primary_table.slug = '$param2'";
            }
        } else {
        	$multiple = true;
        	$param2 = array_filter(array_map($decrypt, $param2));
        	$where = "{$primary_table}.id in(" . implode(',', $param2) . ")";
        }
        
        $clause = array(
        	$primary_table => array(
        		'where' => array($where),
        		'order by' => 'id asc'
	        )
	    );

        $rs = aql::select($aql, $clause, null, null, null, $db_conn);
        if ($multiple) return $rs;
        if ($is_aql) return $rs[0];
        return $rs[0][$field];
	}
	
}