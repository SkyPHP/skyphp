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
     * Array of actions accessible via the REST API.
     * array(
     *   'my-action' => array(
     *
     *       // the name of the method (defaults to camelCase action)
     *       'method' => 'myMethod',
     *
     *       // the sucessful response code (default 200)
     *       'http_response_code' => 201,
     *
     *       // the response key wrapper (defaults to my-action if not set)
     *       'response_key' => '' // blank means no wrapper
     *   )
     * )
     * @var array
     */
    protected static $api_actions = array();

    /**
     * The array of possible errors in the following format:
     * protected $possible_errors = array(
     *     'my_error_code' => array(
     *         'message' => 'The value for my_input_field is not valid.',
     * #       'fields' => array('my_input_field'),
     * #       'type' => 'invalid'
     *         // you may specify any other arbitrary key/value pairs
     *         // that are helpful for your application
     *     )
     * );
     * @var array
     */
    protected static $possible_errors = array();

    /**
     * Errors this has
     * @var array
     */
    protected $errors = array();

    /**
     * When you override __construct, make sure the record requested is allowed
     * to be accessed by the Identity making the api call, and set all the
     * public properties that are to be returned from a 'general' api call
     * @param array $params POST key/value pairs
     * @param Identity $identity the identity of the app/user making the api call.
     *       It cannot be null for REST API call, only a direct call from a developer
     */
    abstract function __construct($params, $identity = null);

    /**
     * Convenience method for setting a value for many properties
     * @param array $arr array of key value pairs
     *     each key is a property of the resource object to set its value
     * @return $this
     */
    public function set($arr)
    {
        if (is_array($arr)) {
            foreach ($arr as $var => $val) {
                $this->$var = $val;
            }
        }
        return $this;
    }

    /**
     * Convenience method to return useful date formats for a given date string
     * @param  string   $timestr    date
     * @return array                various date formats
     */
    public function dateArray($timestr)
    {
        return $this->dateTimeArray(
            $timestr,
            array(
                'U',
                'n/j/Y',
                'l',
                'F',
                'n',
                'j',
                'S',
                'd',
                'Y'
            )
        );
    }

    /**
     * Convenience method to return useful time formats for a given time string
     * @param   string  $timestr    time
     * @return  array               various time formats
     */
    public function timeArray($timestr)
    {
        $values = $this->dateTimeArray(
            $timestr,
            array(
                'U',
                'g:ia',
                'g',
                'i',
                'a'
            )
        );

        if ($values['g:ia']) {
            $values['formatted'] = str_replace(':00', '', $values['g:ia']);
        }

        return $values;
    }

    /**
     * Convenience method to return useful date/time formats for a given date/time string
     * @param   string  $timestr    date and time
     * @param   array   $formats    see php manual for date() formats
     * @return  array               various date/time formats
     */
    public function dateTimeArray($timestr, array $formats = array())
    {
        if (!$timestr) {
            return array();
        }

        $timestr = strtotime($timestr);
        if (!$timestr) {
            return array();
        }

        if (!$formats) {
            $formats = array(
                'U',
                'n/j/Y g:ia',
                'c',
                'l',
                'F',
                'n',
                'j',
                'S',
                'd',
                'Y',
                'g',
                'i',
                'a'
            );
        }

        $data = array();
        array_walk($formats, function($format, $key, $timestr) use(&$data){
            $data[$format] = date($format, $timestr);
        }, $timestr);

        return $data;
    }


    /**
     *
     */
    protected static function getFeed(array $params = array(), Identity $identity = null)
    {
        // the key for the api response data
        $key = $params['key'];

        global $p;
        $request = \Sky\Api\Response::parseQueryFolders($p->queryfolders);
        $format = $request['format'];

        if (is_numeric($_GET['limit'])) $params['limit'] = $_GET['limit'];
        if (is_numeric($_GET['offset'])) $params['offset'] = $_GET['offset'];
        $items = static::getList($params, $identity);

        $wrap = array(
            'total_count' => 0,
            'count' => count($items),
            'offset' => $params['offset'] ?: 0,
            'limit' => $params['limit'],
            $key => array('|')
        );

        unset($params['limit']);
        unset($params['offset']);
        $wrap['total_count'] = static::getCount($params, $identity);

        if ($format == 'xml') {
            // xml

            \xml_headers();
            $item_delimiter = "\n";
            $xml = \Sky\DataConversion::arrayToXml($wrap, 'response');
            $wrap = explode('<item>|</item>', $xml);
            $wrap[0] .= "\n\n";
            $wrap[1] = "\n\n" . $wrap[1];
            echo $wrap[0];
            $count = 0;
            foreach ($items as $itemide) {
                if ($count) echo $item_delimiter;
                $item = (object) new static(['id'=>$itemide], $identity);
                $arr = \Sky\DataConversion::objectToArray($item);
                $xml = \Sky\DataConversion::arrayToXml($arr, 'event');
                $xml = str_replace('<?xml version="1.0" encoding="utf-8"?>', '', $xml);
                echo $xml;
                flush();
                $count++;
            }
            echo $wrap[1];

        } else {
            // json
            \json_headers();
            $item_delimiter = ",\n";
            $wrap = json_beautify(json_encode($wrap));
            $wrap = explode('"|"', $wrap);
            $wrap[0] .= "\n\n";
            $wrap[1] = "\n\n" . $wrap[1];
            echo $wrap[0];
            $count = 0;
            foreach ($items as $itemide) {
                if ($count) echo $item_delimiter;
                $item = (object) new static(['id'=>$itemide], $identity);
                echo json_beautify(json_encode($item));
                flush();
                $count++;
            }
            echo $wrap[1];
        }

        exit;
    }


    /**
     * Returns an associative array of this objects publicly accesible properties
     * Casting to array returns private/protected properties with * prefixes
     * @return array
     */
    public function dataToArray()
    {
        $d = (array) $this;
        foreach ($d as $k => $v) {
            if ($k[1] == '*') {
                unset($d[$k]);
            }
        }
        return $d;
    }

    /**
     * Adds an error to the error stack ($this->errors)
     * @param string $message error message
     */
    public function addError($error_code, $params = array())
    {
        $this->errors[] = static::getError($error_code, $params);
    }

    /**
     * Stops execution of the method and throws ValidationException with all errors
     * that have been added to the error stack.
     * @param   mixed   $a      Either a string $error_code,
     *                         Error object, or an array of error objects
     * @param   array   $params Optional array for customizing the error output
     * @throws  Sky\Api\ValidationException
     */
    public static function error($a, $params = array())
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
     * Gets the action array if it exists
     * @param string $action_name the name of the action (method alias)
     */
    public static function getAction($action_name)
    {
        return static::$api_actions[$action_name];
    }

    /**
     * Gets the Error object for the given $error_code
     * @param string $error_code
     * @param array $params properties to set for the Error object
     * @return Error
     */
    protected static function getError($error_code, $params = array())
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
    public static function accessDenied($message = null)
    {
        throw new AccessDeniedException($message);
    }

    /**
     * Throws NotFoundException
     * @param string $message optional message
     * @throws Sky\Api\NotFoundException
     */
    public static function notFound($message = null)
    {
        throw new NotFoundException($message);
    }

    /**
     * @param  mixed   $var
     * @return \Sky\Api\Response
     */
    public function output($var)
    {
        $this->response = ($this->response) ?: new \Sky\Api\Response;
        return $this->response->setOutput($var);
    }

    /**
     * Tests whether the given argument is an instance of this Resource
     * - uses late static binding
     * @param   mixed   $var
     * @return  Boolean
     */
    public static function isResource($var)
    {
        return is_object($var) && get_class($var) == get_called_class();
    }

    /**
     * Returns a \Model of the given class based on the $value given (ID, IDE, or Model)
     * @param  string  $class
     * @param  mixed   $value
     * @param  string  $error_code
     * @return \Model
     */
    public static function convertToObject($class, $value, $error_code)
    {
        return static::modelConvertTo('Object', $class, $value, $error_code);
    }

    /**
     * Returns an ID of the given class based on the $value given (ID, IDE, or Model)
     * @param  string  $class
     * @param  mixed   $value
     * @param  string  $error_code
     * @return int
     */
    public static function convertToID($class, $value, $error_code)
    {
        return static::modelConvertTo('ID', $class, $value, $error_code);
    }

    /**
     * Returns an IDE of the given class based on the $value given (ID, IDE, or Model)
     * @param  string  $class
     * @param  mixed   $value
     * @param  string  $error_code
     * @return string
     */
    public static function convertToIDE($class, $value, $error_code)
    {
        return static::modelConvertTo('IDE', $class, $value, $error_code);
    }

    /**
     * Return is dependent on $ext
     * and is based off of the $value given (ID, IDE, or Model object)
     *
     * This is a generic helper method for:
     *     static::convertToID(), static::convertToIDE(), static::convertToObject()
     * that uses \Model methods of the same name
     *
     * @param  string  $ext
     * @param  string  $class
     * @param  mixed   $value
     * @param  string  $error_code
     * @return mixed   depending on what $ext is
     * @throws \BadMethodCallException if $class || $ext is invalid
     * @throws ValidationException if could not get return value
     */
    public static function modelConvertTo($ext, $class, $value, $error_code)
    {
        if (static::$modelNamespace) {
            $class = static::$modelNamespace . '\\' . $class;
        }

        if (!\Sky\Model::isModelClass($class)) {
            $e = sprintf('[%s] is not a valid Model', $class);
            throw new \BadMethodCallException($e);
        }

        $exts = array(
            'ID',
            'IDE',
            'Object'
        );

        if (!in_array($ext, $exts)) {
            $e = sprintf('[convertTo%s] is not a valid method', $ext);
            throw new \BadMethodCallException($e);
        }

        $method = 'convertTo' . $ext;

        try {
            return $class::$method($value);
        } catch (\Exception $e) {
            self::error($error_code);
        }
    }

}
