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
     * HTTP response code to be output by the Rest API
     */
    public $http_response_code;

    /**
     * Creates a new Response object
     */
    public function __construct() {

    }

    /**
     * Outputs the response http headers
     */
    public function outputHeaders() {
        
    }

    /**
     * Returns "this" api response in json format
     * @return string $flag     matching to the key in $flags
     */
    public function json() {
        // set the http_response_code
        \http_response_code($this->http_response_code);
        if ($this->errors) {
            $output = array(
                'errors' => $this->errors
            );
        } else {
            $output = $this->output;
        }
        return json_beautify(json_encode($output));
    }

}
