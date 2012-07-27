<?php

namespace Sky\AQL;

/**
 * Exception class to store AQL errors
 * Accepts the AQL statement, SQL generated, and DB error
 */
class SelectException extends Exception
{

    /**
     * @var string
     */
    public $aql = '';

    /**
     * @var string
     */
    public $sql = '';

    /**
     * @var string
     */
    public $db_error = '';

    /**
     * @param   string  $aql
     * @param   string  $sql
     * @param   string  $error
     */
    public function __construct($aql = '', $sql = '', $error = '')
    {
        parent::__construct('AQL Select Error.');

        $this->aql = $aql;
        $this->sql = $sql;
        $this->db_error = $error;
    }

}
