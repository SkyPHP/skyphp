<?php

namespace Sky;

/**
 * A more verbose error handler using set_exception_handler
 * @todo Allow this to be configurable based on different types of exceptions
 * @see sky.php
 * @package SkyPHP
 */
class ErrorHandler
{

    /**
     * Runs pages/error for uncaught exceptions
     * @param   \Exception  $e
     */
    public static function run(\Exception $e)
    {
        $p = new Page;
        $p->inherit('pages/error', array(
            'e' => $e
        ));
    }

}
