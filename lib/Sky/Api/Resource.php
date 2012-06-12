<?php

namespace Sky\Api;

abstract class Resource
{

    /**
     * The response object
     * @var Sky\Api\Response
     */
    protected $response;

    /**
     * The identity of the app/user making the api call
     * @var Sky\Api\Identity
     */
    protected $identity;

    /**
     * The array of possible errors in the following format:
     *  protected $possible_errors = array(
     *      'my_error_code' => array(
     *          'message' => 'The value for my_input_field is not valid.',
     *  #       'fields' => array('my_input_field'),
     *  #       'type' => 'invalid'
     *          // you may specify any other arbitrary key/value pairs
     *          // that are helpful for your application
     *      )
     *  );
     * @var array
     */
    protected static $possible_errors = array();

    /**
     * When you override __construct, make sure the record requested is allowed
     * to be accessed by the Identity making the api call, and set all the
     * public properties that are to be returned from a 'general' api call
     * @param array $params POST key/value pairs
     * @param Identity $identity the identity of the app/user making the api call.
     *        It cannot be null for REST API call, only a direct call from a developer
     */
    abstract function __construct($params, $identity = null);

    /**
     *  Convenience method for setting a value for many properties
     *  @param array $arr array of key value pairs
     *      each key is a property of the resource object to set its value
     *  @return $this
     */
    protected function set($arr)
    {
        if (is_array($arr)) {
            foreach ($arr as $var => $val) {
                $this->$var = $val;
            }
        }
        return $this;
    }

    /**
     *  Convenience method to return useful date formats for a given date string
     *  @param string date
     *  @return array various date formats
     */
    protected function dateArray($timestr)
    {
        return $this->dateTimeArray(
            $timestr,
            array('U', 'n-d-Y', 'l', 'F', 'n', 'd', 'S', 'Y')
        );
    }

    /**
     *  Convenience method to return useful time formats for a given time string
     *  @param string time
     *  @return array various time formats
     */
    protected function timeArray($timestr)
    {
        $values = $this->dateTimeArray(
            $timestr,
            array('U', 'g:ia', 'g', 'i', 'a')
        );

        if (is_array($values)) {
            $values['formatted'] = str_replace(':00', '', $values['g:ia']);
        }

        return $values;
    }

    /**
     * Convenience method to return useful date/time formats for a given date/time string
     * @param string date and time
     * @param array formats, see php manual for date() formats
     * @return array various date/time formats
     */
    protected function dateTimeArray($timestr, $formats = null)
    {
        if (!$timestr) return null;
        $timestr = strtotime($timestr);
        if (!is_array($formats)) $formats = array(
            'U', 'n-d-Y g:ia', 'c', 'l', 'F', 'n', 'd', 'S', 'Y', 'g', 'i', 'a'
        );
        $data = array();
        array_walk($formats, function($format, $key, $timestr) use(&$data){
            $data[$format] = date($format, $timestr);
        }, $timestr);
        return $data;
    }

    /**
     * Adds an error to the error stack ($this->errors)
     * @param string $message error message
     */
    protected function addError($error_code, $params = array())
    {
        $this->errors[] = static::getError($error_code, $params);
    }

    /**
     * Stops execution of the method and throws ValidationException with all errors
     * that have been added to the error stack.
     * @param   mixed   $a      Either a string $error_code,
     *                          Error object, or an array of error objects
     * @param   array   $params Optional array for customizing the error output
     * @throws  Sky\Api\ValidationException
     */
    protected static function error($a, $params = array())
    {
        // if the first param is an array of errors
        if (is_array($a)) {
            $errors = $a;
        } elseif (is_string($a)) {
            $error_code = $a;
            $errors = array(static::getError($error_code, $params));
        } elseif (is_a($a, 'Error')) {
            $error = $a;
            $errors = array($error);
        }
        throw new ValidationException($errors);
    }

    /**
     * Gets the Error object for the given $error_code
     * @param string $error_code
     * @param array $params properties to set for the Error object
     * @return Error
     */
    private static function getError($error_code, $params = array())
    {
        $errors = static::$possible_errors;

        if (!is_string($error_code)
            || !array_key_exists($error_code, $errors)
            || !is_array($errors[$error_code])) {
            throw new \Exception('Invalid error_code for Resource->addError()');
        }

        // merge the predefined properties of this error_code with the specified params
        $error_params = array_merge($errors[$error_code], $params);
        $error = new Error($error_code, $error_params);
        return $error;
    }

    /**
     * Throws AccessDeniedException
     * @param string $message optional message
     * @throws Sky\Api\AccessDeniedException
     */
    protected static function accessDenied($message = null)
    {
        throw new AccessDeniedException($message);
    }

    /**
     * Throws NotFoundException
     * @param string $message optional message
     * @throws Sky\Api\NotFoundException
     */
    protected static function notFound($message = null)
    {
        throw new NotFoundException($message);
    }

    /**
     *  @param  mixed   $var
     *  @return \Sky\Api\Response
     */
    protected function output($var)
    {
        $this->response = ($this->response) ?: new \Sky\Api\Response;
        return $this->response->setOutput($var);
    }

}
