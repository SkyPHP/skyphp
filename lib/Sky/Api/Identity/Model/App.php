<?php

namespace Sky\Api\Identity\Model;

/**
 * For models given this AQL:
 *      sky_api_app {
 *          app_key,
 *          name,
 *          sky_account_id, // getAccountFieldName() should return this string
 *          status
 *      }
 * Implimenting Class:
 *      class sky_api_app extends \Sky\Api\Identity\Model\App
 *      {
 *
 *          public static function getAccountFieldName()
 *          {
 *              return 'sky_account_id';
 *          }
 *      }
 *
 * @package SkyPHP
 */
abstract class App extends \Sky\Model
{

    // /**
    //  * Possible Errors
    //  * @var array
    //  */
    // protected static $possible_errors = array(
    //     'duplicate_app_name' => array(
    //         'message' => 'An app with this name is already registered to this account.',
    //         'fields' => array('name')
    //     ),
    //     'invalid_status' => array(
    //         'message' => 'Invalid Status',
    //         'fields' => array('status')
    //     )
    // );

    /**
     * Valid API App Statuses
     * @var array
     */
    public static $statuses = array(
        'A' => 'Active',
        'D' => 'Disabled',
        'S' => 'Suspended'
    );

    // /**
    //  * @var array
    //  */
    // public $_required_fields = array(
    //     'app_key' => 'App Key',
    //     'name' => 'App Name',
    //     'status' => 'Status'
    // );

    /**
     * The AQL for this model should have
     * @return  string
     */
    protected static function getAccountFieldName()
    {
        return static::getAccountFieldName();
    }

    /**
     * Makes sure there is an appkey
     */
    public function beforeCheckRequiredFields()
    {
        if ($this->isInsert() && !$this->app_key) {
            $this->app_key = $this->generateAppKey();
        }
    }

    /**
     * @return  string
     */
    public function generateAppKey()
    {
        return sha1(mt_rand() . time());
    }

    /**
     * Makes sure this account only has one app by this name
     * @param   string  $val
     */
    public function validate_name($val)
    {
        if ($this->isInsert()) {
            $count = self::getCount([
                'where' => "name = '$val'"
                ]); 


            if ($count > 0) {
                $this->addError('duplicate_app_name');
            }

        }
    }

    /**
     * Makes sure we're setting a valid status
     * @param   string  $val
     */
    public function validate_status($val)
    {
        if (!array_key_exists($val, static::$statuses)) {
            $this->addError('invalid_status');
        }
    }

    /**
     * Gets an api_app object by key
     * @param  string  $key
     * @return ct_api_app | null
     */
    public static function getByKey($key)
    {
        if (!is_string($key) || !trim($key)) {
            throw new InvalidArgumentException('getByKey() expects a string');
        }

        return static::getOne(array(
            'app_key' => trim($key)
        ));
    }

    /**
     * @return  Boolean
     */
    public function isSuspended()
    {
        return $this->status == 'S';
    }

    /**
     * @return  Boolean
     */
    public function isActive()
    {
        return $this->status == 'A';
    }

    /**
     * @return  Boolean
     */
    public function isInactive()
    {
        return !$this->isActive();
    }

    /**
     * @return  Boolean
     */
    public function isDisabled()
    {
        return $this->status == 'D';
    }

}
