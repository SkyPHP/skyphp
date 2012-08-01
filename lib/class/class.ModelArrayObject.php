<?php

/**
 * @package SkyPHP
 */
class ModelArrayObject extends \ArrayObject
{

    /**
     * Overloading the constructor so that we can set ARRAY_AS_PROPS
     * - ARRAY_AS_PROPS: Entries can be accessed as properties (read and write)
     * This only happens if no flags are set in the constructor arguments
     * @see http://www.php.net/manual/en/class.arrayobject.php#arrayobject.constants
     */
    public function __construct()
    {
        // call the parent constructor with the given arguments
        $args = func_get_args();
        call_user_func_array(array(parent, __FUNCTION__), $args);

        // if there were no flags set, set the flag for this instance
        if (!$this->getFlags()) {
            $this->setFlags(parent::ARRAY_AS_PROPS);
        }
    }

    /**
     * Sets the offset, we are overriding the method so that we can set arrays to
     * ModelArrayObjects on set.
     * @see     http://php.net/manual/en/arrayobject.offsetset.php
     * @param   int     $index
     * @param   mixed   $value
     */
    public function offsetSet($index, $value)
    {
        parent::offsetSet($index, $this->toObject($value));
    }

    /**
     * Changes the arg to an array object if it is an array
     * @param   mixed   $val
     * @return  mixed
     */
    private function toObject($val)
    {
        return is_array($val) ? new self($val) : $val;
    }

}
