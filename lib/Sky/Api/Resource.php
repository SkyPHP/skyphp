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

	protected function dateArray($timestr) {
		return $this->dateTimeArray($timestr, array('U', 'n-d-Y', 'l', 'F', 'n', 'd', 'S', 'Y'));
	}

	protected function timeArray($timestr) {
		$values = $this->dateTimeArray($timestr, array('U', 'g:ia', 'g', 'i', 'a'));
		if (is_array($values)) $values['formatted'] = str_replace(':00', '', $values['g:ia']);
		return $values;
	}

	protected function dateTimeArray($timestr, $formats=null) {
		if (!$timestr) return null;
		$timestr = strtotime($timestr);
		if (!is_array($formats)) $formats = array('U', 'n-d-Y g:ia', 'c', 'l', 'F', 'n', 'd', 'S', 'Y', 'g', 'i', 'a');
		$data = array();
		array_walk($formats, function($format, $key, $timestr) use(&$data){
			$data[$format] = date($format, $timestr);
		}, $timestr);
		return $data;
	}

}