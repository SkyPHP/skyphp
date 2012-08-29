<?php

namespace Sky;

/**
 * By extending the \Sky\Api class, you can easily create a RESTful interface for your
 * application using OAuth 2.0 principles.
 *
 * Your API is comprised of various "resources".
 *
 * Each resource of your API maps a URI to a class.  API resources are created by
 * extending \Sky\Api\Resource.  Each method of your resource must accept a single array
 * parameter and return an array or object.
 *
 * Methods and properties of your resource class may be accessed like this:
 *
 *  /my-resource/my-general (public static method)
 *  /my-resource/id
 *  /my-resource/id/my-aspect (public property)
 *  /my-resource/id/my-action (public non-static method)
 *
 * You may have multiple Api's each with multiple resources.  Each resource has multiple
 * endpoints, or URLs, which are defined in the properties of your Api class:
 *
 *  class \My\Api extends \Sky\Api {
 *      public $resources = array(
 *
 *          'my-resource' => array(
 *
 *              // this resource maps to the following class (required)
 *              'class' => '\My\Class',
 *
 *          )
 *      )
 *  }
 *
 * This is the typical usage to create a REST API on a given page:
 *
 *  echo \My\Api::call(
 *      $this->queryfolders, // uri array or string
 *      $_GET['oauth_token'],
 *      $_POST
 *  )->json();
 *
 */
abstract class Api
{

    /**
     * If this is true, error exceptions caught will be returned with a trace
     * @var Boolean
     */
    public static $is_dev = false;

    /**
     * If this is true, REST API requests must be over SSL.
     * @var Boolean
     */
    public static $https_required = false;

    /**
     * the data to be output
     * @var \Sky\Api\Response
     */
    protected $response;

    /**
     * the identity of the user making the request (represented by a token)
     * @var \Sky\Api\Identity
     */
    protected $identity;

    /**
     * the character used to delimit multiple aspects in the url of a rest api call
     * @var string
     */
    const ASPECT_DELIMITER = ',';

    /**
     * @var string
     */
    const E_INVALID_RESOURCE = "'%s' is an invalid resource.";

    /**
     * @var string
     */
    const E_INVALID_MISSING_ENDPOINT = 'Invalid API endpoint: missing id or general.';

    /**
     * @var string
     */
    const E_INVALID_ENDPOINT = 'Invalid API endpoint: %s';

    /**
     * @var string
     */
    const E_INVALID_API_ASPECT = 'Invalid API aspect endpoint: %s.';

    /**
     * @var string
     */
    const E_INVALID_GENERAL_ENDPOINT = 'Invalid API general endpoint: %s.';

    /**
     * @var string
     */
    const E_INVALID_METHOD_ASPECT = '%s cannot be delimited with other aspects.';

    /**
     * @var string
     */
    const E_INVALID_API_ACTION = 'Invalid API action endpoint: %s.';

    /**
     *  an array of api resources allowed to be accessed
     *  Example:
     *  array(
     *      'albums' => array(
     *          'class' => '\My\Api\Album',
     *          'decrypt_key' => 'album',
     *          'general' => array(
     *              'list' => 'getList'
     *          ),
     *          'actions' => array(),
     *          'aspects' => array()
     *      )
     *  )
     *  @var array
     */
    protected $resources = array();

    /**
     * Initializes the Api object with the specified Identity
     * and initialize blank output response object
     * @param  string  $token
     */
    public function __construct($oauth_token = null)
    {
        // set the identity -- this implies your Identity class is in the
        // Api namespace.
        $identityClass = '\\' . get_called_class() . '\\Identity';
        $this->identity = $identityClass::get($oauth_token);
        // initialize output response
        $this->response = new Api\Response();
    }

    /**
     *  Makes an api call statically
     *  @param  mixed   $path   rest api endpoint (uri path string or queryfolder array)
     *  @param  string  $oauth_token  token that identifies the app/user making the call
     *  @param  array   $params key/value pairs to be passed to the rest api endpoint
     *  @return \Sky\Api\Response
     */
    public static function call($path, $oauth_token, array $params = array())
    {
        // first check to make sure protocol is ok
        if (!static::isProtocolOk()) {
            return static::error(500, 'https_required', 'HTTPS is required.');
        }
        try {
            $apiClass = get_called_class();
            $o = $apiClass::init($oauth_token);
            return $o->apiCall($path, $params);
        } catch (\Exception $e) {
            return static::error(500, 'internal_error', $e->getMessage());
        }
    }

    /**
     *  Constructs a new Api and return the instance
     *  since you can't chain off of the constructor, you can chain off init()
     *  @return \My\Api
     */
    public static function init($oauth_token)
    {
        $apiClass = get_called_class();
        return new $apiClass($oauth_token);
    }

    /**
     *  Makes an api call
     *  @param  mixed   $path       rest api endpoint (uri path string/queryfolder array)
     *  @param  array   $params     key/value pairs to be passed to the rest api endpoint
     *  @return \Sky\Api\Response
     */
    function apiCall($path, array $params = array())
    {

        try {
            if (is_array($path)) {
                $qf = $path;
                $uri = implode('/', $qf);
            } else {
                $uri = $path;
                $qf = array_values(array_filter(explode('/', $uri)));
            }

            // determine the resource, and make sure it's valid for this api
            $resource_name = $qf[0];
            if (!is_array($this->resources[$resource_name])) {
                throw new Api\NotFoundException(
                    sprintf(static::E_INVALID_RESOURCE, $resource_name)
                );
            }

            // determine the class associated with the resource being called
            $class = $this->resources[$resource_name]['class'];

            // if no aspect or method specified
            if (!$qf[1]) {
                throw new Api\NotFoundException(
                    sprintf(static::E_INVALID_MISSING_ENDPOINT)
                );
            }

            // detect if we are calling a public static method
            $method_alias = $qf[1];
            $static_method = $this->getMethodName($class, $method_alias);

            if (method_exists($class, $static_method)) {

                // run the method if it's public static
                $rm = new \ReflectionMethod($class, $static_method);
                if (!$rm->isPublic() || !$rm->isStatic()) {
                    throw new Api\NotFoundException(
                        sprintf(static::E_INVALID_GENERAL_ENDPOINT, $method_alias)
                    );
                }

                // get the output of the method
                $output = $class::$static_method($params, $this->identity);
                // and wrap it in the specified var key if it has a wrapper
                $output = static::wrap($output, $class, $method_alias);
                return $this->response->setOutput($output);

            }

            $id = $qf[1];

            // now that we have our instance, either return it or return the aspects
            // being requested

            if (!$qf[2]) {

                // no aspect is being requested
                // so get the entire object
                // create the instance and return the data

                $params['id'] = $id;

                $key = $this->singular($resource_name);
                return $this->response->setOutput(array(
                    $key => $this->getResource($class, $params)
                ));

            }

            // one or more aspects is being requested in the url
            // these aspects could be public properties or public non-static methods

            // check to see if our aspect is actually a csv of aspects
            $aspects = explode(static::ASPECT_DELIMITER, $qf[2]);

            foreach ($aspects as $aspect) {

                // first assume this is an action and see if the method exists
                $method = $this->getMethodName($class, $aspect);

                if (method_exists($class, $method)) {
                    // run the method if it's public non-static
                    // but do not allow multiple method calls

                    if (count($aspects) > 1) {
                        throw new Api\ValidationException(
                            sprintf(static::E_INVALID_METHOD_ASPECT, $aspect)
                        );
                    }

                    $rm = new \ReflectionMethod($class, $method);

                    if (!$rm->isPublic() || $rm->isStatic()) {
                        throw new Api\NotFoundException(
                            sprintf(static::E_INVALID_API_ACTION, $method)
                        );
                    }

                    // instantiate the resource and get the output of the method
                    $output = $this->getResource($class, array(
                        'id' => $id
                    ))->$method($params);

                    // wrap the output in a var key if applicable
                    $output = static::wrap($output, $class, $aspect);
                    return $this->response->setOutput($output);

                } else {
                    // presumably we are requesting a valid property of the resource

                    // need to have an instance here in order to check the properties
                    // which are added at instantiation
                    $params['id'] = $id;
                    $o = $this->getResource($class, $params);

                    if (!property_exists($o, $aspect)) {
                        throw new Api\NotFoundException(
                            sprintf(static::E_INVALID_ENDPOINT, $aspect)
                        );
                    }

                    // get the property if it's public
                    $rp = new \ReflectionProperty($o, $aspect);
                    if (!$rp->isPublic()) {
                        throw new Api\NotFoundException(
                            sprintf(static::E_INVALID_API_ASPECT, $aspect)
                        );
                     }
                    // put the property requested into this response object
                    $data = $o->$aspect;
                    if (!$data && $data !== false) throw new Api\NotFoundException(
                        'This aspect has no data.'
                    );
                    $this->response->output->$aspect = $data;

                }
            }

            return $this->response;

        } catch(\ValidationException $e) {
            $this->response->http_response_code = 400;
            $this->response->errors = $e->getErrors();
            return $this->response;
        } catch(Api\AccessDeniedException $e) {
            $msg = static::errorMsg($e, 'Access denied');
            return static::error(403, 'access_denied', $msg, $e);
        } catch(Api\NotFoundException $e) {
            $msg = static::errorMsg($e, 'Resource not found');
            return static::error(404, 'not_found', $msg, $e);
        } catch(\Exception $e) {
            // TODO output backtrace so the error message can reveal the rogue method
            return static::error(500, 'internal_error', $e->getMessage(), $e);
        }
    }

    /**
     *  Makes an error string using the exception's message and the prefix.
     *  @param  \Exception  $e
     *  @param  string      $prefix
     *  @return string
     */
    public static function errorMsg(\Exception $e, $prefix)
    {
        $m = $e->getMessage();
        $message = ($m) ? ': ' . $m : '.';
        return $prefix.$message;
    }

    /**
     *  Gets a response object containing an error
     *  @param  string      $response_code
     *  @param  string      $error_code
     *  @param  string      $message
     *  @param  \Exception  $e
     *  @return \Sky\Api\Response
     */
    public static function error($response_code, $error_code, $message, \Exception $e = null)
    {
        $response = new Api\Response;
        $response->http_response_code = $response_code;

        $error = new Api\Error($error_code, array('message' => $message));
        if ($e && static::$is_dev) {
            $error->trace = array_filter(preg_split('/\#\d+/', $e->getTraceAsString()));
        }

        $response->errors = array($error);
        return $response;
    }

    /**
     * Gets the specified Resource instance
     * @param string $class
     * @param array $params
     * @param Identity $identity
     * @return Resource
     */
    protected function getResource($class, $params)
    {
        return new $class($params, $this->identity);
    }

    /**
     * Gets the name of the method that corresponds to the given action name
     * @param string $resource_class the name of the resource class
     * @param string $action the alias of the method in the url
     * @return string
     */
    protected function getMethodName($resource_class, $action)
    {
        $action_info = $resource_class::getAction($action);
        return $action_info['method'] ?: static::toCamelCase($action);
    }

    /**
     * gets the singular name of the resource
     * @param string $resource_name
     * @return string
     */
    public function singular($resource_name)
    {
        return $this->resources[$resource_name]['singular'] ?: substr($resource_name,0,-1);
    }

    /**
     * Converts a hyphentated string into camelCase
     * @param string $input hyphenated string
     * @param string $word_delimiter defaults to '-'
     * @return string
     */
    public function toCamelCase($input, $word_delimiter = '-')
    {
        if (!is_string($input)) throw new \InvalidArgumentException('Input must be string');
        $words = explode($word_delimiter, strtolower($input));
        $camel = '';
        foreach ($words as $i => $word) {
            $camel .= $i ? ucfirst($word) : $word;
        }
        return $camel;
    }

    /**
     * Wraps the data in an array with the specified key if Resource::$api_methods
     * specifies a 'response_key' for the specific method.
     * @param   mixed   $data the data to wrap
     * @param   string  $resource_class the name of the resource class
     * @param   string  $action the REST alias of the method
     * @return  string
     */
    public function wrap($data, $resource_class, $action)
    {
        if (!$data && $data !== false) {
            throw new Api\NotFoundException(
                'The requested resource has no data.'
            );
        }

        $action_info = $resource_class::getAction($action);
        if (!isset($action_info['response_key'])) {
            // if response_key is not set, wrapper defaults to method alias
            $wrapper = $action;
        } else {
            // if response_key is blank don't use a wrapper
            $wrapper = $action_info['response_key'];
        }
        return $wrapper ? array($wrapper => $data) : $data;
    }

    /**
     * Determines if this request is over an acceptible protocol
     * @return bool
     */
    public static function isProtocolOk()
    {
        return (!static::$https_required || $_SERVER['HTTPS']);
    }

}
