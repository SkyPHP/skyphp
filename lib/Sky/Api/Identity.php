<?php

namespace Sky\Api;

abstract class Identity {

    /**
     * Creates an Identity object based on params
     * @param array $params key/value pairs, usually:
     *      + person_id
     *      + app_key
     */
    abstract public function __construct($params = null);

    /**
     * Gets the Identity associated with the specified oauth_token
     * @param string $oauth_token
     * @return Identity
     * @abstract
     */
    abstract public static function get($oauth_token = null);

    /**
     * Issues or retrieves an oauth_token
     * @return string
     * @abstract
     */
    abstract public function getOAuthToken();

    /**
     * Shorthand for throwing an exception
     * @param string $message error message
     * @throws \Exception
     */
    protected function error($message) {
        throw new \Exception($message);
    }

}
