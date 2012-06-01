<?php

namespace Sky\Api;

class Response {

    /**
     * Will contain the status of the api call and possibly an error message
     * @var array
     */
    public $meta; // object

    /**
     * Will contain the response data from the api call
     * @var array
     */
    public $response; // object

    /**
     * Returns "this" api response in json format
     * @return string $flag     matching to the key in $flags
     */
    public function json($flag = 'identity') {

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
     *  Returns an "error" response in a standardized format
     *  @param  string  $message
     *  @return \Sky\Api\Response
     */
    public static function error($message) {
        $response = new Response();
        $response->meta->status = 'error';
        $response->meta->errorMessage = $message;
        unset($response->response);
        return $response;
    }

    /**
     *  Returns an "ok" repsonse in a standardized format
     *  @param  array $data
     *  @return \Sky\Api\Response
     */
    public static function ok($data) {
        $response = new Response();
        $response->meta->status = 'ok';
        $response->response = $data;
        return $response;
    }

}

class ResponseException extends \Exception {}
