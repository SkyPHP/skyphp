<?php

namespace Sky;

/**
 * Create a mock transaction for memcache storage.
 *
 * If concerned about data integrity in the cache (as during Model::save())
 * \mem() is a wrapper for \Sky\Memcache::set() ::delete() ::get()
 *
 * Usage:
 *
 *      \Sky\Memcache::begin();
 *
 *      // ... do some things
 *      \Sky\Memcache::set($k, $value);
 *
 *      // ... do some things
 *
 *      $failed = \Sky\Memcache::failedTransaction();
 *
 *      // will automatically call commit or rollback
 *      // depending on if the transaction failed or not
 *      \Sky\Memcache::end();
 *      if ($failed) {
 *          // do something with an error
 *      } else {
 *          // everything is ok
 *      }
 *
 *
 * @package SkyPHP
 */
class Memcache
{

    /**
     * Array of memcache keys to write
     * Looks like:
     *     stack = [
     *          \Sky\Memcache\Transaction\Set || \Sky\Memcache\Transaction\Delete
     *     ]
     * @var array
     */
    protected static $stack = array();

    /**
     * Current transaction status
     * @var Boolean
     */
    protected static $transaction_ok = true;

    /**
     * If this is 0, we are not in a transaction,
     * This is a number because we can be in nested transactions
     * and each time end() is called the number would decrease
     * until we get to 0 and complete() is called (if status is OK)
     * @var int
     */
    protected static $transaction_count = 0;

    /**
     * Prefix used for memcache key storage (transparent to usage)
     * This is optional, although if this is set set($key) would actually set [$prefix]$key
     * The same for reads and deletes
     * @var string
     */
    public static $app_prefix = '';

    /**
     * @return array
     */
    public static function getStack()
    {
        return static::$stack;
    }

    /**
     * Removes all of the data in the stack, resets it to an empty array
     */
    public static function resetStack()
    {
        static::$stack = array();
    }

    /**
     * If in a transaction, adds this to the stack to be stored when transaction finishes
     * Otherwise caches it now
     * @param   string  $key
     * @param   mixed   $value
     * @return  Boolean
     */
    public static function set($key, $value, $duration = null)
    {
        if (!static::inTransaction()) {
            return static::setMemValue($key, $value, $duration);
        }

        static::$stack[] = new Memcache\Transaction\Set($key, $value, $duration);

        return true;
    }

    /**
     * Deletes key or appends delete action to transaction stack
     * depending on if in transaction
     * @param   string  $key
     * @return  Boolean
     */
    public static function delete($key)
    {
        if (!static::inTransaction()) {
            return static::deleteMemValue($key);
        }

        static::$stack[] = new Memcache\Transaction\Delete($key);

        return true;
    }

    /**
     * Fetches the value for the given key,
     * checks the transaction stack if in transaction
     * @param   string  $key
     * @return  mixed
     */
    public static function get($key)
    {
        return (static::inTransaction())
            ? static::getValueFromStack($key)
            : static::readMemValue($key);
    }

    /**
     * Triggers memcache transaction failure
     * But does not end the transaction
     */
    public static function fail()
    {
        static::$transaction_ok = false;
    }

    /**
     * @return Boolean
     */
    public static function inTransaction()
    {
        return static::$transaction_count > 0;
    }

    /**
     * Initiates a memcahce transaction
     */
    public static function begin()
    {
        static::$transaction_count++;
    }

    /**
     * Decrements the $transaction_count
     * If we are no longer in a transaction when this is over
     * commitTransaction() if there is no failure, otherwise rollbackTransaction()
     *
     * This does support nested transactions
     */
    public static function end()
    {
        if (!static::inTransaction()) {
            static::$transaction_ok = true;
            return;
        }

        static::$transaction_count--;

        if (!static::inTransaction()) {

            $failed = static::failedTransaction();
            static::$transaction_ok = true;

            if (!$failed) {
                static::commitTransaction();
            } else {
                static::rollbackTransaction();
            }
        }
    }

    /**
     * Ends the transaction, if there was failure it will do a rollbac()
     * Otherwise, commit()
     * Same as static::end()
     */
    public static function commit()
    {
        static::end();
    }

    /**
     * Trigger rollback(), this will trigger fail() and attempt a rollbac()
     * If in nested transactions, this will go out of one of them
     * Otherwise it will do rollbackTransaction();
     */
    public static function rollback()
    {
        static::fail();
        static::end();
    }

    /**
     * @return Boolean
     */
    public static function failedTransaction()
    {
        return !static::$transaction_ok;
    }

    /**
     * Erase temp keys in cache and clear stack
     */
    protected static function rollbackTransaction()
    {
        static::mapStackAndClear('rollback');
    }

    /**
     * Loops through the stack
     * and writes the values to their permanent locations in cache
     * clears the stack when it's done
     */
    protected static function commitTransaction()
    {
        static::mapStackAndClear('commit');
    }

    /**
     * Loops over the stack and performs the given action on the TransactionValue
     * @param   string  $action
     */
    protected static function mapStackAndClear($action)
    {
        foreach (static::$stack as $index => $value) {
            $value->$action();
            unset(static::$stack[$index]);
        }
        static::resetStack();
    }

    /**
     * Gets the memcache resource
     * @return  Memcache resource
     * @global  $memcache
     */
    public static function getMemcache()
    {
        global $memcache;
        return $memcache;
    }

    /**
     * Checks to see if memcache is enabled
     * @return Boolean
     */
    public static function isMemcacheEnabled()
    {
        return (bool) static::getMemcache();
    }

    /**
     * Directly set memcache value
     * Only use this if avoiding transactions on purpose
     * Otherwise use \Sky\Memcache::set()
     * @param   string  $key
     * @param   mixed   $value
     * @param   string  $duration
     * @return  Boolean
     */
    public static function setMemValue($key, $value, $duration = '')
    {

        if (!static::isMemcacheEnabled() || !$key) {
            return;
        }

        $m = static::getMemcache();

        $num_seconds = 0;
        if ($duration) {
            $time = time();
            $num_seconds = strtotime('+' . $duration, $time) - $time;
        }

        $key = static::getAppSpecificKey($key);

        \elapsed("begin mem-write($key)");
        $set = ($m->replace($key, $value, null, $num_seconds))
            ?: $m->set($key, $value, null, $num_seconds);
        \elapsed("end mem-write($key)");

        return $set;
    }

    /**
     * Directly delete memcache value
     * Only use this if avoiding transactions on purpose
     * Otherwise use \Sky\Memcache::delete()
     * @param   string  $key
     * @return  Boolean
     */
    public static function deleteMemValue($key)
    {
        if (!static::isMemcacheEnabled()) {
            return false;
        }

        $keys = \arrayify(static::getAppSpecificKey($key));
        foreach ($keys as $k) {
            static::getMemcache()->delete($k, null);
        }
    }

    /**
     * Directly read from memcache
     * Only use this if avoiding transaction reads
     * Otherwise use \Sky\Memcache::get()
     * @param   mixed   $key    one key or an array of keys
     * @return  mixed           value for one key, or an associative array of key => vals
     */
    public static function readMemValue($key)
    {
        if (!static::isMemcacheEnabled() || !$key) {
            return null;
        }

        $fkey = static::getAppSpecificKey($key);
        $read_key = (is_array($fkey)) ? array_values($fkey) : $fkey;

        \elapsed("begin mem-read({$key})");
        $value = static::getMemcache()->get($read_key);
        \elapsed("end mem-read({$key}");

        if (is_array($key)) {
            $c = $value;
            $value = array();
            foreach ($fkey as $k => $actual) {
                $value[$k] = $c[$actual];
            }
        }

        return $value;
    }

    /**
     * Checks the transaction stack for the key, returns the stored value
     * otherwise returns value from actual cache
     * @param   string  $key
     * @return  mixed
     */
    public static function getValueFromStack($key)
    {
        $stack = array_reverse(static::$stack);
        foreach ($stack as $info) {
            if ($info->getKey() == $key) {
                return $info->getValue();
            }
        }
        return static::readMemValue($key);
    }

    /**
     * Returns application specific keys with a prefix if static::$app_prefix exists
     * @param   array | string       $key   can be an array of keys or one key
     * @return  array | string              depending on type of $key
     */
    protected static function getAppSpecificKey($key)
    {

        if (!is_array($key)) {
            $single = true;
            $key = array($key);
        }

        $prefix = (static::$app_prefix) ? '[' . static::$app_prefix . ']' : '';

        $keys = array();
        foreach ($key as $k) {
            $keys[$k] = $prefix . $k;
        }

        return ($single) ? reset($keys) : $keys;
    }

}
