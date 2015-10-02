<?php
$namespace = '\\Crave\\Model\\';

$model_name = $this->queryfolders[0];
$model = $namespace . $model_name;
$primary_table = $model::getPrimaryTable();

$o = new $model($_POST);
// s($o);

// if this is an update, make sure the correct _token has been posted
if ($o->isUpdate()) {
	if ($_POST['_token'] != $o->getToken()) {
		exit_json([
			'status' => 'Error',
			'errors' => [
				'Invalid token.'
			]
		]);
	}
}
if ($o->id == 0){
    $o->id = null ;
    $o->ide = null;
}
$o->save();

$response = $o->getResponse();

// if this is an ajax request:

if ($this->is_ajax_request) {
    exit_json($response);
}

// else if this is not an ajax request:

$url = ($_GET['return_uri']) ?: $_SERVER['HTTP_REFERER'];

$qs = '?return='.rawurlencode(serialize($response));

redirect($url . $qs);
