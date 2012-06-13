<?php

/**
 *  A standard error object to be used in validation
 */
class ValidationError
{

    /**
     *  The error code that uniquely identifieds this error
     *  @var string
     */
    public $code = '';

    /**
     *  The message that belongs to this error
     *  @var string
     */
    public $message = '';

    /**
     *  @param  string  $error_code
     *  @param  array   $params     additional properties of the error to be set
     */
    public function __construct($error_code, $params = array())
    {
        $this->code = $error_code;
        if (is_array($params)) {
            foreach ($params as $var => $val) {
                $this->{$var} = $val;
            }
        }
    }

}
