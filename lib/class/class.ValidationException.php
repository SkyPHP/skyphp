<?php


/**
 *  An that contains an array of ValidationError objects
 */
class ValidationException extends Exception
{

    /**
     *  Error storage
     *  @var array
     */
    private $errors = array();

    /**
     *  @param array        $errors
     *  @param int          $code
     *  @param Exception    $previous
     */
    public function __construct($errors = array(), $code = 0, \Exception $previous = null)
    {
        $message = 'There is one or more validation errors.';
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    /**
     *  @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

}
