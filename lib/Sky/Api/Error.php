<?php

namespace Sky\Api;

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

class ValidationException extends \Exception {
    private $errors;

    public function __construct($errors = array(), $code = 0, \Exception $previous = null) {
        $message = 'There is one or more validation errors.';
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors() {
        return $this->errors;
    }
}
class AccessDeniedException extends \Exception {}
class NotFoundException extends \Exception {}