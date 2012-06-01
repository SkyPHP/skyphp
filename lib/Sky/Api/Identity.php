<?php

namespace Sky\Api;

class Identity {

	function __construct($params=null) {
		
		if (!$params) {
			// public access
			$this->appKey = 'public';
			unset($this->person_id);

		} elseif (is_array($params)) {
			// is an identity array
			$this->person_id = $params['person_id'];
			$this->app_key = $params['app_key'];
		
		} else {
			// is a token
			$token = $params;
		
		}
	}

	static function get($token) {
	
	}

	function generateToken() {

	}

}