<?php

// This is a sample file for backwards compatibility.
// New projects should use the API exclusively.

$namespace = '\\MyApp\\Model\\';

$model = $namespace . $this->queryfolders[0];


$o = new $model($_POST);
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
