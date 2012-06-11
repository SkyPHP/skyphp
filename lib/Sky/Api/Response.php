<?php

namespace Sky\Api;

/**
 * Errors:
 *
 *  Resource method errors:
 *      400 validation 
 *          ->addError('invalid','invalid_amount', 'You must specify a numeric amount.')
 *          ->addError('required','amount_required', 'You must specify an amount.')
 *          ->addError($Error)
 *      403 access denied
 *
 *  Api errors:
 *      404 api resource not found
 *      500 api internal error
 */
class Response {

    /**
     * Creates a new Response object
     */
    public function __construct() {

    }

    /**
     * Outputs the response http headers
     */
    public function outputHeaders() {
        //header();
    }

    /**
     * Returns "this" api response in json format
     * @return string $flag     matching to the key in $flags
     */
    public function json() {
        if ($this->errors) {
            $output = array(
                'errors' => $this->errors
            );
        } else {
            $output = $this->output;
        }
        return json_beautify(json_encode($output));
    }

    /**
     * Adds an error to the errors stack
     */
    public function addError($params) {
        if (!is_array($params) && !is_object($params)) {

        }
        $this->errors[] = $params;
        return $this;
    }

    /**
     * Throws exception
     */
    public function fail($params) {
        $this->errors[] = array(
            'message' => $params
        );
        throw new ResponseException($this->errors);
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

class ResponseException extends \Exception {

    public function __construct($params) {

    }

}
