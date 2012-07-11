<?php

namespace Sky\Memcache\Transaction;

/**
 * @package SkyPHP
 */
class Set extends Type
{

    /**
     * Sets the temporary key and stores the tmp value in cache
     * @param   string  $key
     * @param   mixed   $value
     * @param   string  $duration
     */
    public function __construct($key, $value, $duration = null)
    {
        $this->key = $key;
        $this->duration = $duration;
        \Sky\Memcache::setMemValue($this->generateTmpKey(), $value);
    }

}
