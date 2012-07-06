<?php

/**
 * A standard error object to be used in validation
 * @package SkyPHP
 */
class ValidationError
{

    /**
     * The error code that uniquely identifieds this error
     * @var string
     */
    public $code = '';

    /**
     * The message that belongs to this error
     * @var string
     */
    public $message = '';

    /**
     * @param string $error_code
     * @param array  $params     additional properties of the error to be set
     */
    public function __construct($error_code, $params = array())
    {
        $this->code = $error_code;
        if (is_array($params)) {
            foreach ($params as $var => $val) {
                $this->{$var} = $val;
            }
        }

        $this->setTrace();
    }

    /**
     * If we are dev, each Validation Error should have its own trace
     * Sets $this->trace using a ValidationException
     * Only works if \Sky\Api::$is_dev is truthy
     */
    protected function setTrace()
    {
        if (!\Sky\Api::$is_dev) {

            return;
        }

        try {
            throw new ValidationException;
        } catch (ValidationException $e) {
            // get trace -> array
            // take off the first two pieces because they aren't necessary in the trace
            $this->trace = array_slice(
                array_filter(
                    preg_split(
                        '/\#\d+/',
                        $e->getTraceAsString()
                    )
                ),
                2
            );
        }
    }

}
