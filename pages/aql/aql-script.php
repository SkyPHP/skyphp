<?

// willl handle CRUD requests to models

class AQLhandleRequest {
	
	public $model_name;
	public $response;
	public $action;
	public $object;

	public static $actions = 'save|delete';

	public function __construct($action = null) {
		global $p;
		if (!$action) exit;
		if (!in_array($action, explode('|', self::$actions))) exit;
		$this->action = $action;
		$this->model_name = $p->queryfolders[0];
	}

	public function run() {
		if (!preg_match('/^[\w0-9]+$/', $this->model_name)) {
			$this->response = array(
				'status' => 'Error',
				'errors' => array('Invalid Model Name')
			);
		} else if (!$_POST) {
			$this->response = array(
				'status' => 'Error',
				'errors' => array('No Data Submitted In Request')
			);
		} else {
			$this->object = Model::get($this->model_name);
			$this->object->loadArray($_POST);
			$this->response = $this->object->{$this->action}();
		}
		return $this;
	}

	public function finish() {
		global $p;
		if ($p->is_ajax_request || true) {
			exit_json($this->response);
		} else {
			$to = ($_GET['return_uri']) ? $_GET['return_uri'] : $_SERVER['HTTP_REFERER'];
			if ($this->response['status'] == 'OK') {
				$get = array('status' => 'OK');
			} else {
				$get = $this->response;
			}
			$get = '?return='.rawurlencode(serialize($get));
			$p->redirect($to.$get, 302);
		}	
	}
}