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
     * @return  string
     */
    abstract protected static function getOauthModelName();

    /**
     * @return string
     */
    abstract protected static function getAppIDFieldName();

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

            $app_id = null;

        } else {

            $api_app = static::getApiAppByKey($this->app_key());

            if (!$api_app) {
                static::error('Invalid app_key.');
            }

            $app_id = $api_app->getID();
            $clause['app_id'] = $app_id;
        }

        if (!$clause) {
            static::error('Unknown Identity.');
        }

        $m = static::getOauthModelName();
        $oauth = $m::getOne($clause);

        if (!$oauth || !$oauth->token) {
            $oauth = $m::insert(array(
                'person_id' => $person_id,
                static::getAppIDFieldName() . '_id' => $app_id
            ));
        }

        return array(
            'oauth_token' => $oauth->token,
            'issued' => $oauth->getTimeIssued(),
            'now' => strtotime(\aql::now()),
            'expires' => $oauth->getTimeExpires()
        );
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
        $f = static::getAppIDFieldName();

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

}
