<?php

namespace Sky\Memcache\Transaction;

/**
 * @package SkyPHP
 */
class Delete extends Type
{

    /**
     * Sets the key that will be deleted
     * @param   string  $key
     */
    public function __construct($key, $value, $duration = null)
    {
        $this->key = $key;
    }

}
