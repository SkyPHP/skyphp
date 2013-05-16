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
     * Outputs json headers
     */
    public function jsonHeaders()
    {
        \json_headers();
    }

    /**
     * Outputs json headers
     */
    public function xmlHeaders()
    {
        \xml_headers();
    }

    /**
     * Returns the REST API output
     */
    public function getOutput()
    {
        if ($this->errors) {
            return array(
                'errors' => $this->errors
            );
        } else {
            return $this->output;
        }
    }

    /**
     * Returns this api response in json format
     * @return string $flag     matching to the key in $flags
     */
    public function json()
    {
        $output = $this->getOutput();
        return json_beautify(json_encode($output));
    }

    /**
     * Returns this api response in json format
     * @return string $flag     matching to the key in $flags
     */
    public function xml()
    {
        $output = $this->getOutput();
        $arr = \Sky\DataConversion::objectToArray($output);
        $xml = \Sky\DataConversion::arrayToXml($arr, 'response');
        return $xml;
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

    /**
     * Outputs the REST API response in the specified format
     * @param string $format 'json' or 'xml'
     */
    public function outputResponse($format = null)
    {
        $formats = array('json', 'xml');
        $format = !in_array($format, $formats) ? reset($formats) : $format;

        $this->outputHeaders();

        $headersFn = $format . '_headers';
        $headersFn();

        echo $this->$format();
    }

    /**
     * Parses queryfolder array and determines the format specified as file extension
     * @param array Page->$queryfolders
     * @return array [queryfolders, format]
     */
    public static function parseQueryFolders($qf)
    {
        $last_qf = array_pop($qf);
        $pieces = explode('.', $last_qf);
        $values = array_values($pieces);
        $qf[] = array_shift($values);
        $format = end($pieces);
        return array(
            'queryfolders' => $qf,
            'format' => $format
        );
    }

}

