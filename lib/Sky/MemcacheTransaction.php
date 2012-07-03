<?php

namespace Sky;

/**
 *  @package SkyPHP
 */
class MemcacheTransaction
{

    /**
     *  Array of memcache keys to write
     *  Looks like:
     *      stack = [
     *          ['tmp' => $tmp_key, 'key' => $actual_key]
     *      ]
     *  @var array
     */
    protected static $stack = array();

    /**
     *  Current transaction status
     *  @var Boolean
     */
    protected static $transaction_ok = true;

    /**
     *  If this is 0, we are not in a transaction,
     *  This is a number because we can be in nested transactions
     *  and each time end() is called the number would decrease
     *  until we get to 0 and complete() is called (if status is OK)
     *  @var int
     */
    protected static $transaction_count = 0;

    /**
     *  Duration to store the tmp key in memcache
     *  @var string
     */
    public static $tmp_duration = '5 minutes';

    /**
     *  Temporary key prefix
     *  @var string
     */
    public static $tmp_key_prefix = '::tmp::';

    /**
     *  @return array
     */
    public static function getStack()
    {
        return static::$stack;
    }

    /**
     *  Removes all of the data in the stack, resets it to an empty array
     */
    public static function resetStack()
    {
        static::$stack = array();
    }

    /**
     *  Makes/returns the temporary key based on the actual key and prefix
     *  @param  string  $key
     *  @return string
     */
    protected static function getTmpKey($key)
    {
        return static::$tmp_key_prefix . $key;
    }

    /**
     *  If in a transaction, adds this to the stack to be stored when transaction finishes
     *  Otherwise caches it now
     *  @param  string  $key
     *  @param  mixed   $value
     */
    public static function append($key, $value)
    {
        if (!static::inTransaction()) {
            \mem($key, $value);
            return;
        }

        $tmp_key = static::getTmpKey($key);
        \mem($tmp_key, $value, static::$tmp_duration);

        static::$stack[] = array(
            'tmp' => $tmp_key,
            'key' => $key
        );
    }

    /**
     *  Triggers memcache transaction failure
     */
    public static function fail()
    {
        static::$transaction_ok = false;
    }

    /**
     *  @return Boolean
     */
    public static function inTransaction()
    {
        return static::$transaction_count > 0;
    }

    /**
     *  Initiates a memcahce transaction
     */
    public static function start()
    {
        static::$transaction_count++;
    }

    /**
     *  Decrements the $transaction_count
     *  If we are no longer in a transaction when this is over
     *  set the values if there was no failure, otherwise clear the stack
     */
    public static function end()
    {
        if (!static::inTransaction()) {
            return;
        }

        static::$transaction_count--;

        if (!static::inTransaction()) {

            $failed = static::failedTransaction();
            static::$transaction_ok = true;

            if (!$failed) {
                static::complete();
            } else {
                static::resetStack();
            }
        }
    }

    /**
     *  @return Boolean
     */
    public static function failedTransaction()
    {
        return !static::$transaction_ok;
    }

    /**
     *  Loops through the stack
     *  and writes the values to their permanent locations in cache
     *  clears the stack when it's done
     */
    protected static function complete()
    {
        foreach (static::$stack as $index => $keys) {
            \mem($keys['key'], \mem($keys['tmp']));
            \mem($keys['tmp'], null);
            unset(static::$stack[$index]);
        }
        static::resetStack();
    }

}
