<?php

namespace Sky\Api;

abstract class Identity
{

    private $oauth_model = null;

    /**
     * Creates an Identity object based on params
     * @param array $params key/value pairs, usually:
     *      + person_id
     *      + app_key
     */
    public function __construct($oauth = null)
    {
        $this->oauth_model = $oauth;
    }

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

    abstract protected static function getOauthModel();

    abstract protected static function getAppFieldName();

    public static function getOauthByToken($token)
    {
        $model = static::getOauthModel();
        return $model::getByToken($token);
    }

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

        $m = static::getOauthModel();
        $oauth = $m::getOne($clause);

        if (!$oauth || !$oauth->token) {
            $oauth = $m::insert(array(
                'person_id' => $person_id,
                static::getAppFieldName() . '_id' => $app_id
            ));
        }

        return array(
            'oauth_token' => $oauth->token,
            'issued' => $oauth->getTimeIssued(),
            'now' => strtotime(\aql::now()),
            'expires' => $oauth->getTimeExpires()
        );
    }

    public function getModel()
    {
        return $this->oauth_model;
    }

    public function person_id()
    {
        return $this->getModel()->person_id;
    }

    public function app_key()
    {
        $f = static::getAppFieldName();

        return $this->getModel()->$f->app_key;
    }

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
