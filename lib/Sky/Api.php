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
 *              // try decrypting if the resource id is non-numeric (optional)
 *              'decrypt_key' => 'my_primary_table',
 *
 *              // general aliases of static methods (optional)
 *              'generals' => array(
 *                  'list' => 'getList'
 *              ),
 *
 *              // action aliases of non-static methods (optional)
 *              'actions' => array(),
 *
 *              // aspect aliases of public properties (optional)
 *              'aspects' => array()
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
     *  issue a token to the requestor
     *  @param array $params
     *      api_key
     *      username or email_address
     *      password
     *  @abstract
     */
    abstract public static function getOAuthToken(array $params=null);

    /**
     * initialize the Api object with the specified Identity
     * and initialize blank output response object
     * @param  string  $token
     */
    public function __construct($token=null) {
        // set the identity
        $this->identity = Api\Identity::get($token);
        $this->output = new Api\Response();
    }

    /**
     *  make an api call statically
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

        // assume the call is for a specific record, we will find out
        // later if it is in fact a valid record...and if it's not we
        // will check if it's a general, aspect, or action.
        if (is_numeric($qf[1])) {
            $id = $qf[1];
        } else if ($this->resources[$resource]['decrypt_key']) {
            $id = decrypt($qf[1], $this->resources[$resource]['decrypt_key']);
        }

        // TODO: detect if it's a static method first,
        // so non-numeric keys would work i.e. mongo
        if (is_numeric($id)) {
            try {
                $params['id'] = $id;
                $o = new $class($params, $this->identity);
            } catch (\Exception $e) {
                return $this->error($e->getMessage());
            }
            if ($qf[2]) {
                // execute the non-static method OR get the property
                $aspect = $qf[2];
                $aspect = $this->resources[$resource]['actions'][$aspect] ?: $aspect;
                $aspect = $this->resources[$resource]['aspects'][$aspect] ?: $aspect;
                if ( method_exists($o, $aspect) ) {
                    // TODO: make sure it's a public method
                    try {
                        $this->output->response = $o->$aspect($params, $this->identity);
                    } catch(\Exception $e) {
                        return $this->error($e->getMessage());
                    }
                } else if ( property_exists($o, $aspect)) {
                    // TODO: make sure it's a public property
                    // TODO: parse aspect csv
                    $this->output->response = (object) array($aspect => $o->$aspect);
                } else {
                    return $this->error("'$aspect' is an invalid aspect or action.");
                }
            } else {
                // get the entire object
                $this->output->response = $o;
            }
        } else {

            $static_method = $this->resources[$resource]['general'][$qf[1]] ?: $qf[1];
            if ( method_exists($class, $static_method) ) {
                // TODO: make sure it's a public method
                try {
                    $this->output->response = call_user_func(array($class,$static_method), $params);
                } catch(\Exception $e) {
                    return $this->error($e->getMessage());
                }
            } else {
                return $this->error("Invalid API URL.");
            }
        }

        $this->output->meta->status = 'ok';
        return $this->output;
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
