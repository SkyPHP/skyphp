<?

namespace Sky\Api;

class Resource {
	
	function __construct( $identifier, $params ) {
		// make sure the record requested falls within the constraints of the app making the api call
	}

	function set($arr) {
		if (!is_array($arr)) return false;
		foreach ($arr as $var => $val) {
			$this->$var = $val;
		}
	}

}