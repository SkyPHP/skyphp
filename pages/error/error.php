<?php

/**
 * This page must have an $e (\Exception) in the scope
 */
if (!$e || !is_a($e, 'Exception')) {
    return;
}

header("HTTP/1.1 503 Service Temporarily Unavailable");
header("Status: 503 Service Temporarily Unavailable");
header("Retry-After: 120");

$props = (array) $e;
$ps = array();
array_walk($props, function($v, $k) use(&$ps) {
    // print_pre($v, $k);
    if (!preg_match('/(Exception|\*)/', $k)) {
        $ps[$k] = $v;
    }
});

if (is_a($e, 'ValidationException')) {

    $list = reset(array_map(function($e) {
        return array_map(function($v) {
            return array_map(function($a) {
                return var_export($a, true);
            }, $v);
        }, mustachify((array) $e));
    }, $e->getErrors()));

    $errors = array(
        'list' =>$list
    );
}




$info = array(
    'message' => $e->getMessage(),
    'validation_errors' => $errors,
    'extra' => $ps ? array('list' => mustachify($ps)) : null,
    'trace' => array_map(function($t) {

        $t['args'] = array_map(function($arg) {
            if (is_object($arg)) {
                $name = get_class($arg);
            } else if (is_array($arg)) {
                $name = shorten(var_export($arg, true));
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

echo $this->mustache('mustache/error.m', $info, $this->incpath);

$this->template('html5', 'bottom');
