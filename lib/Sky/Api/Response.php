<?

namespace Sky\Api;

class Response {
	
	public $meta;
	public $response;

	function __construct() {
		
	}

	// return the output data in a nice json format
	function json($flag=null) {
		$value = json_beautify(json_encode($this));
		switch ($flag) {
			case 'pre':
				$value = "<pre>{$value}</pre>";
				break;
		}
		return $value;
	}

}