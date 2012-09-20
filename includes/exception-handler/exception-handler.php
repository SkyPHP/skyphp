<?php

/**
 * This page must have an $e (\Exception) in the scope
 */
if (!$e || !is_a($e, 'Exception')) {
    return;
}

$export = function($a) { return trim(var_export($a, true)); };

header("HTTP/1.1 503 Service Temporarily Unavailable");
header("Status: 503 Service Temporarily Unavailable");
header("Retry-After: 120");

// get public properties of the exception
$ps = array();
$props = (array) $e;
array_walk($props, function($v, $k) use(&$ps, $export) {
    if (!preg_match('/(Exception|\*)/', $k)) {
        $ps[$k] = $export($v);
    }
});

if ($ps) {
    // reformat to use as a partial
    $extra = array(
        'list' => array('each' => mustachify($ps))
    );
}


// collect validation errors if they are set
if (is_a($e, 'ValidationException')) {

    $list = array_map(function($e) use($export) {
        return array('each' =>
            array_map(function($v) use($export) {
                return array_map($export, $v);
            }, mustachify((array) $e))
        );
    }, $e->getErrors());

    $errors = array(
        'list' => $list
    );
}

$info = array(
    'type' => get_class($e),
    'message' => $e->getMessage() ?: '--no-message--',
    'validation_errors' => $errors,
    'extra' => $ps ? $extra : null,
    'trace' => array_map(function($t) use($export) {

        $t['args'] = array_map(function($arg) use($export) {

            if (is_object($arg)) {
                $name = get_class($arg);
            } else if (is_array($arg)) {
                $name = shorten($export($arg));
            } else {
                $name = shorten($arg);
            }

            return array(
                'display' => $name,
                'content' => htmlentities(trim(var_export($arg, true)))
            );
        }, $t['args'] ?: array());

        return $t;
    }, $e->getTrace())
);

$this->template('html5', 'top');

echo $this->mustache('error.m', $info, $this->incpath . '/mustache/');

$this->template('html5', 'bottom');
