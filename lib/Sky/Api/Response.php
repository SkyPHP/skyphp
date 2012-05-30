<?php

namespace Sky\Api;

class Response {

    /**
     * will contain the status of the api call and possibly an error message
     * @var array
     */
    public $meta = array();

    /**
     * will contain the response data from the api call
     * @var array
     */
    public $response = array();

    /**
     * return "this" api response in json format
     * @return string $flag     matching to the key in $flags
     */
    function json($flag = 'identity') {

        $flags = array(
            'identity' => function($val) {
                return $val;
            },
            'pre' => function($val) {
                return "<pre>{$val}</pre>";
            }
        );

        if (!$flags[$flag]) {
            throw new ResponseException('Invalid $flag');
        }

        $value = json_beautify(json_encode($this));
        return $flags[$flag]($value);
    }

}

class ResponseException extends \Exception {}
