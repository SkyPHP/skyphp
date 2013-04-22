<?php

namespace Sky\Memcache\Transaction;

/**
 * @abstract
 * @package SkyPHP
 */
abstract class Type
{

    /**
     * Key in memcahce of the temporary value
     * @var string
     */
    protected $tmp_key = '';

    /**
     * Actual key to change
     * @var string
     */
    protected $key = '';

    /**
     * Storage duration
     * @var string
     */
    protected $duration = null;

    /**
     * Prefix to use for temporary key
     * @var string
     */
    protected static $tmp_key_prefix = ':tmp:';

    /**
     * Duration to store the tmp key in memcache
     * @var string
     */
    public static $tmp_duration = '5 minutes';

    /**
     * Abstract constructor, child class should
     */
    abstract public function __construct($key, $value, $duration = null);

    /**
     * Type should be either set | delete
     * @return string
     */
    public function getType()
    {
        $t = explode('\\', strtolower(get_called_class()));
        return array_pop(array_filter($t));
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @return string | null
     */
    public function getTmpKey()
    {
        return $this->tmp_key;
    }

    /**
     * Returns the value that this key will be set to
     * @return mixed
     */
    public function getValue()
    {
        return ($this->isSetType())
            ? \Sky\Memcache::readMemValue($this->getTmpKey())
            : null;
    }

    /**
     * @return Boolean
     */
    public function isDeleteType()
    {
        return $this->getType() == 'delete';
    }

    /**
     * @return Boolean
     */
    public function isSetType()
    {
        return $this->getType() == 'set';
    }

    /**
     * Generates and sets the temporary key
     * @return string
     */
    protected function generateTmpKey()
    {
        return $this->tmp_key = static::$tmp_key_prefix
                              . $this->key
                              . md5(mt_rand() . time());
    }

    /**
     * @return string
     */
    public function getDuration()
    {
        return $this->duration;
    }

    /**
     * Rolls back this TransactionValue (deletes the temp key from cache)
     */
    public function rollback()
    {
        if ($this->isSetType()) {
            \Sky\Memcache::deleteMemValue($this->getTmpKey());
        }
    }

    /**
     * Commits this TransactionValue,
     * deletes key if this is a delete, sets it and deletes tmp key if it s a set
     */
    public function commit()
    {
        if ($this->isSetType()) {
            \Sky\Memcache::setMemValue(
                $this->getKey(),
                $this->getValue(),
                $this->getDuration()
            );
            \Sky\Memcache::deleteMemValue($this->getTmpKey());
        } else {
            \Sky\Memcache::deleteMemValue($this->getKey());
        }
    }

}
