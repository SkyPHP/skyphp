<?php

namespace Sky\Api;

class Error {
	
    public $code;

	public function __construct($error_code, $params = array()) {
        $this->code = $error_code;
        foreach ($params as $var => $val) {
            $this->$var = $val;
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