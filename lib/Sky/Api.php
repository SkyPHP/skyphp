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
 *          'my-resource' => array(
 *              // this resource maps to the following class (required)
 *              'class' => '\My\Class',
 *
 *              // aliases of methods and properties (optional)
 *              'alias' => array(
 *                  'list' => 'getList'
 *              )
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
                throw new Api\NotFoundException("'$resource_name' is an invalid resource.");
            }

            // determine the class associated with the resource being called
            $class = $this->resources[$resource_name]['class'];

            // if no aspect or method specified
            if (!$qf[1]) {
                throw new Api\NotFoundException(
                "Invalid API endpoint: missing id or general."
                );
            }

            // detect if we are calling a public static method
            $static_method = $this->resources[$resource_name]['alias'][$qf[1]] ?: $qf[1];
            if (method_exists($class, $static_method)) {

                // run the method if it's public static
                $rm = new \ReflectionMethod($class, $static_method);
                if ($rm->isPublic() && $rm->isStatic()) {

                    // methods must return a response object
                    // or it will be an internal error
                    return $this->verifyResponse(
                        $class::$static_method($params, $this->identity)
                    );

                } else throw new Api\NotFoundException(
                    "Invalid API general endpoint: $static_method"
                );

            } else {
                // not a public static method
                // so instantiate the Resource being requested
                // TODO: don't instantiate if aspect is not valid
                $id = $qf[1];
                $params['id'] = $id;
                $o = new $class($params, $this->identity);

                // now that we have our instance, either return it or return the aspects
                // being requested
                if (!$qf[2]) {
                    // no aspect is being requested
                    // so get the entire object
                    return $this->response->setOutput(array(
                        $this->singular($resource_name) => $o
                    ));
                } else {
                    // one or more aspects is being requested in the url
                    // these aspects could be public properties or public non-static methods

                    // get the name of the non-static method OR property
                    $aspect = $this->resources[$resource_name]['alias'][$qf[2]] ?: $qf[2];

                    // check to see if our aspect is actually a csv of aspects
                    $aspects = explode(static::ASPECT_DELIMITER, $aspect);

                    foreach ($aspects as $aspect) {
                        if (method_exists($o, $aspect)) {
                            // run the method if it's public non-static
                            // but do not allow multiple method calls
                            if (count($aspects) > 1) {
                                throw new Api\ValidationException(
                                    "$aspect cannot be delimited with other aspects"
                                );
                            }
                            $method = $aspect;
                            $rm = new \ReflectionMethod($o, $method);

                            if (!$rm->isPublic() || $rm->isStatic())

                                throw new Api\NotFoundException(
                                    "Invalid API action endpoint: $method"
                                );

                            // methods must return a response object or it will be an
                            // internal error
                            return $this->verifyResponse($o->$method($params));

                        } else if ( property_exists($o, $aspect)) {
                            // get the property if it's public
                            $rp = new \ReflectionProperty($o, $aspect);
                            if ($rp->isPublic()) {
                                // put the property requested into this response object
                                $this->response->output->$aspect = $o->$aspect;
                            } else {
                                throw new Api\NotFoundException(
                                    "Invalid API aspect endpoint: $aspect"
                                );
                            }
                        } else {
                            throw new Api\NotFoundException(
                                "Invalid API endpoint: $aspect"
                            );
                        }
                    }
                    return $this->verifyResponse($this->response);
                }
            }

        // TODO: getTrace if a developer
        } catch(Api\ValidationException $e) {
            $this->response->http_response_code = 400;
            $this->response->errors = $e->getErrors();
            return $this->response;
        } catch(Api\AccessDeniedException $e) {
            $msg = static::errorMsg($e, 'Access denied');
            return static::error(403, 'access_denied', $msg);
        } catch(Api\NotFoundException $e) {
            $msg = static::errorMsg($e, 'Resource not found');
            return static::error(404, 'not_found', $msg);
        } catch(\Exception $e) {
            // TODO output backtrace so the error message can reveal the rogue method
            return static::error(500, 'internal_error', $e->getMessage());
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
     *  make sure what we are returning is a valid Response
     *  @param  \Sky\Api\Response $response
     *  @return \Sky\Api\Response
     */
    public function verifyResponse($response)
    {
        return (is_a($response, '\Sky\Api\Response'))
            ? $response
            : static::error(
                500,
                'internal_error',
                'Invalid response from method.'
            );
    }

    /**
     *  Gets a response object containing an error
     *  @param  string  $http_response_code
     *  @param  string  $error_code
     *  @param  string  $error_message
     *  @return \Sky\Api\Response
     */
    public static function error($http_response_code, $error_code, $error_message)
    {
        $response = new Api\Response();
        $response->http_response_code = $http_response_code;
        $error = new Api\Error($error_code, array('message' => $error_message));
        $response->errors = array($error);
        return $response;
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

}
