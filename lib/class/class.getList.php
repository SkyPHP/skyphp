<?php

class getList {
	
	public $filters = array();
	public $params = array();
	public $aql;
	public $query_sql;
	public $count_sql;
	public $where = array();
	public $order_by = '';
	public $limit = '';
	public $offset = 0;
	public $joins = array();

	private $_methods = array();

	public function __construct($a = array()) {
		
	}

	public function setAQL($aql) {
		$this->aql = $aql;
		return $this;
	}

	public function setParams($params) {
		$this->params = $params;
		return $this;
	}

	public function defineFilters($arr) {
		foreach ($arr as $k => $v) {
			$this->defineFilter($k, $v);
		}
		return $this;
	}

	public function addJoin($str) {
		$this->joins[] = $str;
	}

	public function prepareJoins() {
		foreach ($this->joins as $j) {
			$this->aql .= $j . ' {}';
		}
	}

	public function prepareClauses() {
		$this->makeWhereArray();
		if ($this->params['limit']) $this->limit = $this->params['limit'];
		if ($this->params['order_by']) $this->order_by = $this->params['order_by'];
		if ($this->params['offset']) $this->offset = $this->params['offset'];
	}

	public function prepare() {
		$this->prepareClauses();
		$this->mapIDEsToIDs();
		$this->mapSearch();
		$this->checkParams();
		$this->prepareJoins();
		$this->makeQueries();
		return $this;
	}

	public function makeQueries() {
		$sql = aql::sql($this->aql, array(
			'limit' => $this->limit,
			'where' => $this->where,
			'order by' => $this->order_by,
			'offset' => $this->offset
		));
		$this->count_sql = preg_replace('/\bcount\(\*\)/', "count(distinct {$sql['primary_table']}.id)", $sql['sql_count']);
		$this->query_sql = $sql['sql_list'];
	}

	public function getCount($arr = array()) {
		$this->setParams($arr)->prepare();
		$r = sql($this->count_sql);
		return $r->Fields('count');
	}

	public function select($arr = array()) {
		$this->setParams($arr)->prepare();
		if ($_GET['getList_debug']) krumo($this);
		$r = sql($this->query_sql);
		$ids = array();
		while (!$r->EOF) {
			$ids[] = $r->Fields('id');
			$r->moveNext();
		}
		return $ids;
	}

	public function getFilterByOperator($operator) {
		$re = array();
		foreach ($this->filters as $k => $v) {
			if (is_bool($v)) continue;
			if ($v == $operator)  $re[] = $k;
		}
		return $re;
	}

	public function makeWhereArray() {
		if (!$this->params['where']) return;
		else if (is_array($this->params['where'])) $this->where = $this->params['where'];
		else $this->where = array($this->params['where']);
	}

	public function mapIDEsToIDs() {
		foreach ($this->params as $k => $v) {
			if (substr($k, -4) != '_ide') continue;
			$key = aql::get_decrypt_key($k);
			$prop = substr($k, 0, -1);
			if (!array_key_exists($prop, $this->filters)) continue;
			$this->params[substr($k, 0, -1)] = decrypt($v, $key);
		}
	}

	public function mapSearch() {
		
		if (!$this->params['search']) return;
		$q = $this->params['search'];
		$qs = array_map('trim', explode(',', $q));

		$operators = array_values($this->filters);

		$search = '';
		foreach ($qs as $q) {

			$matches = $this->_matchSearchOperators($q);
			if (!$matches['operator'] || !in_array($matches['operator'], $operators) || !$matches['search']) {
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

	}

	private function _matchSearchOperators($search) {
		preg_match('/^(?<operator>[\w]+):(?<search>.+)$/', $search, $matches);
		return $matches;
	}

	public function defineFilter($n, $arr) {
		$this->filters[$n] = if_not($arr['operator'], true);
		if (is_callable($arr['callback'])) $this->addMethod('set_'.$n, $arr['callback']);
	}

	public function checkParams() {
		foreach ($this->params as $k => $v) {
			if (!$v) continue;
			if ($this->applyMethodIfExists('set_'.$k, array($v)));
		}
	}

	public function applyMethodIfExists($method, $arg = array()) {
		if ($this->methodExists($method)) {
			return call_user_func_array($this->_methods[$method], $arg);
		}
	}

	public function addMethods($arr) {
		if (!is_assoc($arr)) {
			throw new Exception('addMethods expects the argument to be an associative array');
			return;
		}
		foreach ($arr as $k => $v) {
			$this->addMethod($k, $v);
		}
		return $this;
	}

	public function addMethod($name, $fn) {
		if (!is_callable($fn)) {
			throw new Exception('method: '. $name .' is not callable. Cannot add an uncallable method.');
			return $this;
		}
		if ($this->methodExists($name)) {
			throw new Exception('method: '. $name .' already exists in this object.');
			return $this;
		}
		$this->_methods[$name] = $fn;
		return $this;
	}

	public function methodExists($name) {
		return array_key_exists($name, $this->_methods);
	}

	public function __call($method, $args) {
		if (!$this->methodExists($method)) {
			throw new Exception('Method: '. $method .' does not exist in this object.');
			return;
		}
		return call_user_func_array($this->_methods[$name], $args);
	}

}