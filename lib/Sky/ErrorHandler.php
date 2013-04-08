<?php

namespace Sky;

/**
 * A more verbose error handler using set_exception_handler
 * @todo Allow this to be configurable based on different types of exceptions
 * @todo Hide traces from people who shouldn't see them.
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
        $p->inherit('includes/exception-handler', array(
            'e' => $e
        ));
    }


    /**
     * Less intrusive version of displaying exceptions on a page
     */
    public static function errorPopUp(\Exception $e)
    {
        include 'includes/exception/exception.php';
    }

}
