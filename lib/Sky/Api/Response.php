<?php

namespace Sky\Api;

class Response
{

    /**
     * HTTP response code to be output by the Rest API
     * @var int
     */
    public $http_response_code;

    /**
     * Data to be output by this response
     * @var mixed
     */
    public $output;

    /**
     * Array of errors to be output by this response
     * @var array
     */
    public $errors;

    /**
     * Outputs the response http headers
     */
    public function outputHeaders()
    {
        // set the http_response_code
        \http_response_code($this->http_response_code);
    }

    /**
     * Returns this api response in json format
     * @return string $flag     matching to the key in $flags
     */
    public function json()
    {
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
     * Returns this after setting output. Useful for chaining.
     * @param   mixed $val
     * @return  $this
     */
    public function setOutput($val)
    {
        $this->output = $val;
        return $this;
    }

}
