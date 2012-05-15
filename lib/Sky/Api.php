<?php

/*

By extending the \Sky\Api class, you can easily create a RESTful 
interface for your application using OAuth 2.0 principles.

Your API is comprised of various "resources".

Each resource of your API maps a URI to a class.  API resources are 
created by extending \Sky\Api\Resource.  Each method of your 
resource must accept a single array parameter and return an array 
or object. 

Methods and properties of your resource class may be accessed like this:

	/my-resource/my-general (public static method)
	/my-resource/id
	/my-resource/id/my-aspect (public property)
	/my-resource/id/my-action (public non-static method)

You may have multiple Api's each with multiple resources.  Each 
resource has multiple endpoints, or URLs, which are defined in the
properties of your Api class:

	class \My\Api extends \Sky\Api {
		public $resources = array(
			'my-resource' => array(
				// this resource maps to the following class (required)
				'class' => '\My\Class',

				// try decrypting if the resource id is non-numeric (optional)
				'decrypt_key' => 'my_primary_table',

				// general aliases of static methods (optional)
				'generals' => array( 
					'list' => 'getList'
				),

				// action aliases of non-static methods (optional)
				'actions' => array(),

				// aspect aliases of public properties (optional)
				'aspects' => array()
			)
		)
	}

This is the typical usage to create a REST API on a given page:

	echo \My\Api::call(
		$this->queryfolders, // uri array or string
		$_GET['oauth_token'],
		$_POST
	)->json();

*/

namespace Sky;

class Api {

	private $output; // the output of the api call
	private $constraints; // the constraint data corresponding to the current context

	function __construct($token=null) {

		// set constraints based on the current token (token represents app + user)
		// TODO: if the context is an array, get a token
		$this->constraints = $this->getConstraints($token);

		$this->output = new Api\Response();
	}


	static function call($path, $token, $params=null) {
		$class = get_called_class();
		$o = new $class($token);
		if (!$token) return $o->error('Please specify an oauth token.');
		// TODO: get rid of apiCall
		return $o->apiCall($path, $params);
	}


	// construct a new RestApi and return the instance 
	// (so you can chain off of the constructor)
	static function init($token) {
		$class = get_called_class();
		return new $class($token);
	}


	/*
	make an api call
		path -  uri string relative to the base api 
				OR queryfolder array
		token - string
		params - usually the POST array
	*/
	function apiCall($path, $params=null) {

		if (is_array($path)) {
			$qf = $path;
			$uri = implode('/', $qf);
		} else {
			$uri = $path;
			$qf = array_values(array_filter(explode('/', $uri)));
		}

		// determine the resource, and make sure it's valid for this api
		$resource = $qf[0];
		if (!is_array($this->resources[$resource])) return $this->error("'$resource' is an invalid resource.");
		$class = $this->resources[$resource]['class'];

		if (is_numeric($qf[1])) {
			$id = $qf[1];
		} else if ($this->resources[$resource]['decrypt_key']) {
			$id = decrypt($qf[1], $this->resources[$resource]['decrypt_key']);
		}

		// the app accessing this api has constraints... add them as a paramter to the method being called
		$params['constraints'] = $this->constraints;
		
		// TODO: detect if it's a static method first, so non-numeric keys would work i.e. mongo
		if (is_numeric($id)) {
			try {
				$o = new $class($id, $params);
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
						$this->output->response = $o->$aspect();
					} catch(\Exception $e) {
						return $this->error($e->getMessage());
					}
				} else if ( property_exists($o, $aspect)) {
					// TODO: make sure it's a public property
					$this->output->response = $o->$aspect;
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


	// return the error message in a standardized format
	function error($message) {
		$this->output->meta->status = 'error';
		$this->output->meta->errorMsg = $message;
		unset($this->output->response);
		return $this->output;
	}


	// return the output data in a nice json format
	function json() {
		return json_beautify(json_encode($this->output));
	}


}

