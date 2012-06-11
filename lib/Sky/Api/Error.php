<?php

namespace Sky\Api;

/**
 * Standard format for an Error in a \Sky\Api\Response
 */
class Error {

    /**
     * The error code that uniquely identifies the error
     * @var string
     */
    public $code;

    /**
     * Creates a new error object
     * @param string $error_code
     * @param array $params properties of the error to be set
     */
    public function __construct($error_code, $params = array()) {
        $this->code = $error_code;
        if (is_array($params)) {
            foreach ($params as $var => $val) {
                $this->$var = $val;
            }
        }
    }

}

/**
  * Throw this and the REST API will output a 400 response
  */
class ValidationException extends \Exception {
    
    /**
     * @var array
     */
    private $errors;

    /**
     * Creates new exception
     * @param array $errors
     * @param int $code
     * @param Excetion $previous
     */
    public function __construct($errors = array(), $code = 0, \Exception $previous = null) {
        $message = 'There is one or more validation errors.';
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    /**
     * Gets errors
     * @return array
     */
    public function getErrors() {
        return $this->errors;
    }
}

/**
  * Throw this and the REST API will output a 403 response
  */
class AccessDeniedException extends \Exception {}

/**
 * Throw this and the REST API will output a 404 response
 */
class NotFoundException extends \Exception {}