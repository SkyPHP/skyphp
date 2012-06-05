<?php

namespace Sky;

/**
 * By extending the \Sky\Api class, you can easily create a RESTful
 * interface for your application using OAuth 2.0 principles.
 *
 * Your API is comprised of various "resources".
 *
 * Each resource of your API maps a URI to a class.  API resources are
 * created by extending \Sky\Api\Resource.  Each method of your
 * resource must accept a single array parameter and return an array
 * or object.
 *
 * Methods and properties of your resource class may be accessed like this:
 *
 *  /my-resource/my-general (public static method)
 *  /my-resource/id
 *  /my-resource/id/my-aspect (public property)
 *  /my-resource/id/my-action (public non-static method)
 *
 * You may have multiple Api's each with multiple resources.  Each
 * resource has multiple endpoints, or URLs, which are defined in the
 * properties of your Api class:
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
abstract class Api {

    /**
     * the data to be output
     * @var \Sky\Api\Response
     */
    protected $output;

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
     *      'orders' => array(
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
    public function __construct($oauth_token=null) {
        // set the identity -- this implies your Identity class is in the
        // Api namespace.
        $class = '\\' . get_called_class() . '\\Identity';
        $this->identity = $class::get($oauth_token);
        // initialize output response
        $this->output = new Api\Response();
    }

    /**
     *  Makes an api call statically
     *  @param  mixed   $path   rest api endpoint (uri path string or queryfolder array)
     *  @param  string  $token  token that identifies the app/user making the call
     *  @param  array   $params key/value pairs to be passed to the rest api endpoint
     *  @return \Sky\Api\Response
     */
    public static function call($path, $token, array $params=array()) {
        $class = get_called_class();
        try {
            $o = new $class($token);
        } catch (\Exception $e) {
            return Api\Response::error($e->getMessage());
        }
        return $o->apiCall($path, $params);
    }

    /**
     *  construct a new Api and return the instance
     *  since you can't chain off of the constructor, you can chain off init()
     *  @return \My\Api
     */
    public static function init($token) {
        $class = get_called_class();
        return new $class($token);
    }

    /**
     *  make an api call
     *  @param  mixed   $path       rest api endpoint (uri path string/queryfolder array)
     *  @param  array   $params     key/value pairs to be passed to the rest api endpoint
     *  @return \Sky\Api\Response
     */
    function apiCall($path, array $params=array()) {

        if (is_array($path)) {
            $qf = $path;
            $uri = implode('/', $qf);
        } else {
            $uri = $path;
            $qf = array_values(array_filter(explode('/', $uri)));
        }

        // determine the resource, and make sure it's valid for this api
        $resource = $qf[0];
        if (!is_array($this->resources[$resource])) {
            return $this->error("'$resource' is an invalid resource.");
        }

        // determine the class associated with the resource being called
        $class = $this->resources[$resource]['class'];

        // if no aspect or method specified
        if (!$qf[1]) return static::error("Invalid API endpoint: missing aspect or action");

        // detect if we are calling a public static method
        $static_method = $this->resources[$resource]['alias'][$qf[1]] ?: $qf[1];
        if (method_exists($class, $static_method)) {
            // run the method if it's public static
            $rm = new \ReflectionMethod($class, $static_method);
            if ($rm->isPublic() && $rm->isStatic()) {
                try {
                    return static::ok($class::$static_method($params, $this->identity));
                } catch(\Exception $e) {
                    return static::error($e->getMessage());
                }
            } else {
                return static::error("Invalid API general endpoint: $static_method");
            }
        } else {
            // not a public static method
            // so instantiate the Resource being requested
            try {
                $id = $qf[1];
                $params['id'] = $id;
                $o = new $class($params, $this->identity);
            } catch (\Exception $e) {
                return $this->error($e->getMessage());
            }

            // now that we have our instance, either return it or return the aspects being requested
            if (!$qf[2]) {
                // no aspect is being requested
                // so get the entire object
                return static::ok($o);
            } else {
                // one or more aspects is being requested in the url
                // these aspects could be public properties or public non-static methods

                // get the name of the non-static method OR property
                $aspect = $this->resources[$resource]['alias'][$qf[2]] ?: $qf[2];

                // check to see if our aspect is actually a csv of aspects
                $aspects = explode(static::ASPECT_DELIMITER, $aspect);
                
                foreach ($aspects as $aspect) {
                    if (method_exists($o, $aspect)) {
                        // run the method if it's public non-static
                        // but do not allow multiple method calls
                        if (count($aspects) > 1) return static::error("$aspect cannot be delimited with other aspects");
                        $rm = new \ReflectionMethod($o, $aspect);
                        if ($rm->isPublic() && !$rm->isStatic()) {
                            try {
                                return static::ok($o->$aspect($params, $this->identity));
                            } catch(\Exception $e) {
                                return static::error($e->getMessage());
                            }
                        } else {
                            return static::error("Invalid API action endpoint: $aspect");
                        }
                    } else if ( property_exists($o, $aspect)) {
                        // get the property if it's public
                        $rp = new \ReflectionProperty($o, $aspect);
                        if ($rp->isPublic()) {
                            $response[$aspect] = $o->$aspect;
                        } else {
                            return static::error("Invalid API aspect endpoint: $aspect");
                        }
                    } else {
                        return static::error("Invalid API endpoint: $aspect");
                    }
                }
                return static::ok($response);
            }
        }
    }

    /**
     *  return the ok response in a standardized format
     *  @param  string  $response
     *  @return \Sky\Api\Response
     */
    function ok($data) {
        $response = $this->output;
        $response = $response::ok($data);
        return $response;
    }

    /**
     *  return the error message in a standardized format
     *  @param  string  $message
     *  @return \Sky\Api\Response
     */
    function error($message) {
        $response = $this->output;
        $response = $response::error($message);
        return $response;
    }

}
