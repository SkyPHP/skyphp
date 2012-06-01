<?php

namespace Sky\Api;

class Response {

    /**
     * will contain the status of the api call and possibly an error message
     * @var array
     */
    public $meta; // object

    /**
     * will contain the response data from the api call
     * @var array
     */
    public $response; // object

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

    /**
     *  return the error message in a standardized format
     *  @param  string  $message
     *  @return \Sky\Api\Response
     */
    static function error($message) {
        $response = new Response();
        $response->meta->status = 'error';
        $response->meta->errorMessage = $message;
        unset($response->response);
        return $response;
    }

}

class ResponseException extends \Exception {}
