<?php

namespace Sky\Api;

abstract class Identity
{

    /**
     * Ouath Model
     * @var \Sky\Api\Identity\Model\Oauth
     */
    private $oauth_model = null;

    /**
     * Creates an Identity object based on params
     * @param Identity\Model\Oauth
     */
    public function __construct(Identity\Model\Oauth $oauth = null)
    {
        $this->oauth_model = $oauth;
    }

    /**
     * @param
     * @return  Sky\Api\Identity
     */
    public static function get($token = null)
    {
        if (!$token) {
            return new self(new $o);
        }

        $oauth = static::getOauthByToken($token);
        if (!$oauth) {
            static::error('Invalid Token.');
        }

        return new self($oauth);
    }

    /**
     * Example: sky_api_oauth
     * @return  string
     */
    abstract protected static function getOauthModelName();

    /**
     * Example: sky_api_app
     * @return string
     */
    abstract protected static function getApiAppModelName();

    /**
     * Gets an oauth model by token
     * @return  Model | null
     */
    public static function getOauthByToken($token)
    {
        $model = static::getOauthModelName();
        return $model::getByToken($token);
    }

    /**
     * Get or create a token for this person_id for this app
     * @return  array
     */
    public function getOAuthToken()
    {
        $person_id = $this->person_id();

        if (!$person_id) {
            static::error('User not specified.');
        }

        if (!\person::exists($person_id)) {
            static::error('Invalid user.');
        }

        $clause = array(
            'person_id' => $person_id
        );

        if (!$this->app_key()) {

            krumo($this->getModel()->dataToArray());

            $app_id = null;

        } else {

            $api_app = static::getApiAppByKey($this->app_key());

            if (!$api_app) {
                static::error('Invalid app_key.');
            }

            $app_id = $api_app->getID();
        }

        $clause[static::getApiAppModelName() . '_id'] = $app_id;

        if (!$clause) {
            static::error('Unknown Identity.');
        }

        $m = static::getOauthModelName();
        $oauth = $m::getOne($clause);

        if (!$oauth || !$oauth->token) {
            $oauth = $m::insert($clause);
        }

        return array(
            'oauth_token' => $oauth->token,
            'issued' => $oauth->getTimeIssued(),
            'now' => strtotime(\aql::now()),
            'expires' => $oauth->getTimeExpires()
        );
    }

    /**
     * @return  Identity\Model\App | null
     */
    public static function getApiAppByKey($key)
    {
        $m = static::getApiAppModelName();

        return $m::getByKey($key);
    }

    /**
     * @return  Identity\Model\Oauth | null
     */
    public function getModel()
    {
        return $this->oauth_model;
    }

    /**
     * @return  int
     */
    public function person_id()
    {
        return $this->getModel()->person_id;
    }

    /**
     * @return  string
     */
    public function app_key()
    {
        $f = static::getApiAppModelName();

        return $this->getModel()->$f->app_key;
    }

    /**
     * @return  Boolean
     */
    public function isPublic()
    {
        return !$this->getModel();
    }

    /**
     * Shorthand for throwing an exception
     * @param string $message error message
     * @throws \Exception
     */
    protected static function error($message)
    {
        throw new \Exception($message);
    }

    /**
     * Gets an app model for it's key, if there is no key, it returns an emtpy object
     * @param   string  $key
     * @return  \Sky\Identity\Model\App
     */
    public static function getOAuthApp($key)
    {
        $m = static::getApiAppModelName();

        if (!$key) {
            // public access
            return  new $m;
        }

        return static::getApiAppByKey($key);
    }

    /**
     * Instantiates a oauth model given the params and returns it.
     * @param   array   $params
     * @return  Identity\Model\Oauth;
     */
    public static function getOAuthModel($person_id, $app_id)
    {
        $m = static::getOauthModelName();
        $app = static::getApiAppModelName();
        $params = array(
            'person_id' => $person_id,
            $app .'_id' => $app_id,
            $app => $app_id ? new $app($app_id) : null
        );

        return new $m($params);
    }

    /**
     * Generates oauth token output based on the person and app_key
     * @return  array
     */
    public static function generateOAuthToken($person, $app_key)
    {
        $app = static::getOAuthApp($app_key);
        $auth = static::getOAuthModel($person, $app->getID());

        $cl = get_called_class();

        $identity = new $cl($auth);

        return $identity->getOAuthToken();
    }

}
