<?php

#\Sky\Model\Request::runRequest('save', $this);

//$_GET['elapsed'] = 1;

$model = $this->queryfolders[0];

//print_r($_POST);

$o = new $model($_POST);
$o->save();

$response = $o->getResponse();


// for debugging purposes
exit_json($response);



// if this is an ajax request:

if ($this->is_ajax_request) {
    exit_json($response);
}


// else if this is not an ajax request:

$url = ($_GET['return_uri']) ?: $_SERVER['HTTP_REFERER'];

$qs = '?return='.rawurlencode(serialize($response));

redirect($url . $qs);
