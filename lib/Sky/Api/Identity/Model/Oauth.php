<?php

namespace Sky\Api\Identity\Model;

use \Sky\AQL;

/**
 * Example AQL
 *      sky_api_oauth {
 *          sky_api_app_id,
 *          mod_time,
 *          person_id,
 *          token,
 *          [sky_api_app]
 *      }
 *      sky_api_app {
 *
 *      }
 * @package SkyPHP
 */
abstract class Oauth extends \Sky\Model
{

    /**
     * Setting for when
     * @var string
     */
    public static $expiration_interval = '60 days';

    // /**
    //  *  @var array
    //  */
    // public $_required_fields = array(
    //     'person_id' => 'Person',
    //     'token' => 'Token'
    // );

    /**
     * Gets the field name of this model that this token will belong to
     * IE: sky_api_app_id
     * @return  string
     */
    protected static function getAppIDFieldName()
    {
        return static::getAppIDFieldName();
    }

    /**
     * @param  string  $token
     * @return Model | null
     */
    public static function getByToken($token)
    {
        if (!is_string($token) || !$token) {
            return null;
        }
        $oauth = static::getOne([
            'where' => [
                "token = '$token'"
            ]
        ]);
        return $oauth;
    }

    /**
     * Pre validate hook
     * if insert and not provided with a token, generate one
     */
    public function beforeCheckRequiredFields()
    {
        // make sure we have a token only if we are inserting
        if (AQL::getTransactionCounter() > 0) {
            if ($this->isInsert() && !$this->token) {
                $this->token = $this->generateNewToken();
            }
        }
    }

    /**
     * Generates oauth_token
     * @return string
     * @throws Exception
     */
    public function generateNewToken()
    {
        if (!$this->person_id) {
            throw new \Exception('Unknown user.');
        }

        return encrypt($this->person_id . $this->ct_api_app_id . time());
    }

    /**
     * @param  string  $format
     * @return string | int
     */
    public function getTimeExpires($format = '')
    {
        $interval = '+' . static::$expiration_interval;
        $time = strtotime($interval, $this->getTimeIssued());
        return ($format) ? date($format, $time) : $time;
    }

    /**
     *  @param  string  $format
     *  @return string | int
     */
    public function getTimeIssued($format = '')
    {
        $time = strtotime($this->mod_time);
        return ($format) ? date($format, $time) : $time;
    }

}
