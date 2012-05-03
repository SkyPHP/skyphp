<?


$api = new RestApi(array(
	
	'accounts' => array(

	),

	'customers' => array(

	),
	
	'orders' => array(
		'class' => 'Order',
		'decrypt_key' => 'ec_order',
		'static_methods' => array(
			'list' => 'getList'
		),
		'non_static_methods' => array(),
		'properties' => array()
	)

));




// TODO: access level (active user)
// TODO: don't allow download of entire table

echo $api->call($this->queryfolders, $_GET)->json();





/*

/resource/static-method
/resource/id
/resource/id/property
/resource/id/non-static-method

*/
class RestApi {

	private $config;
	private $output;

	function __construct($config) {
		$this->config = $config;
		$this->output = new stdClass;
		$this->output->meta = new stdClass;
		$this->output->response = new stdClass;
	}

	/*
		path -  uri relative to the base api 
				OR queryfolder array
		params - usually the POST array
	*/
	function call($path, $params=null) {

		if (is_array($path)) {
			$qf = $path;
			$uri = implode('/', $qf);
		} else {
			$uri = $path;
			$qf = explode('/', $uri);	
		}

		// determine the resource, and make sure it's valid for this api
		$resource = $qf[0];
		if (!is_array($this->config[$resource])) return $this->error("'$resource' is an invalid resource.");
		$class = $this->config[$resource]['class'];

		if (is_numeric($qf[1])) {
			$id = $qf[1];
		} else if ($this->config[$resource]['decrypt_key']) {
			$id = decrypt($qf[1], $this->config[$resource]['decrypt_key']);
		}

		// TODO: detect if it's a static method first, so non-numeric keys would work i.e. mongo
		if (is_numeric($id)) {
			$o = new $class($id);
			if ($qf[2]) {
				// execute the non-static method OR get the property
				$aspect = $this->config[$resource]['non_static_methods'][$qf[2]] ?: $qf[2];
				if ( method_exists($o, $aspect) ) {
					// TODO: make sure it's a public method
					try {
						$this->output->response = $o->$aspect();
					} catch(Exception $e) {
						return $this->error($e->getMessage());
					}
				} else if ( property_exists($o, $aspect)) {
					// TODO: make sure it's a public property
					$this->output->response = $o->$aspect;
				}
			} else {
				// get the entire object
				$this->output->response = $o;
			}
		} else {
			$static_method = $this->config[$resource]['static_methods'][$qf[1]] ?: $qf[1];
			if ( method_exists($class, $static_method) ) {
				// TODO: make sure it's a public method
				try {
					$this->output->response = call_user_func(array($class,$static_method), $params);
				} catch(Exception $e) {
					return $this->error($e->getMessage());
				}
			} else {
				return $this->error("'$static_method' is an invalid static method.");
			}
		}
		$this->output->meta->status = 'ok';
		return $this;
	}

	function json() {
		return json_beautify(json_encode($this->output));
	}

	function error($message) {
		$this->output->meta->status = 'error';
		$this->output->meta->errorMsg = $message;
		unset($this->output->response);
		return $this;
	}
}

