<?php

namespace Sky\AQL;

/**
 * @package SkyPHP
 */
class TransactionException extends Exception
{

    /**
     * insert | update | increment
     * @var string
     */
    public $type =  '';

    /**
     * @var string
     */
    public $sql = '';

    /**
     * @var string
     */
    public $db_error = '';

    /**
     * @var string
     */
    public $table = '';

    /**
     * String if increment,
     * @var mixed
     */
    public $fields = null;

    /**
     * @var int
     */
    public $id = null;


    /**
     * @param   string      $table
     * @param   mixed       $fields
     * @param   mixed       $id     can be null
     * @param   ADODB       $db
     */
    public function __construct($table, $fields, $id, $db)
    {
        $this->table = $table;
        $this->fields = $fields;
        $this->id = $id;
        $this->type = $this->getTransactionType();
        $this->db_error = $db->ErrorMsg();

        parent::__construct($this->makeMessage());
    }

    /**
     * Gets the transaction type (insert | update | increment)
     * depending on what the constructor args are
     * @return  string
     */
    private function getTransactionType()
    {
        if (!$this->id) {
            return 'insert';
        }

        return (is_array($this->fields)) ? 'update' : 'increment';
    }

    /**
     * Generates the message string based on the properties
     * @return  string
     */
    private function makeMessage()
    {
        $format = 'Failed %s on table: [%s] with fields: [%s]';
        $pars = array(
            $this->type,
            $this->table,
            print_r($this->fields, true),
        );

        if ($this->type == 'update') {
            $format .= ' on id: [%s]';
            $pars[] = $this->id;
        }

        return vsprintf($format, $pars);
    }

    /**
     * Sends an email with the error description to aql_error_email, if this prop is set
     * @global  $aql_error_email
     */
    public function sendErrorEmail()
    {
        global $aql_error_email;

        if (!$aql_error_email) {
            return;
        }

        $subject = 'AQL ' . $this->type . ' Error:';

        $dump = print_r(array(
            'type' => $this->type,
            'sql' => $this->sql,
            'fields' => $this->fields,
            'error' => $this->db_error,
            'id' => $this->id
        ), true);

        $trace = $this->getTraceAsString();
        $body = "<pre>{$dump}\n---\n{$trace}</pre>";

        @mail(
            $aql_error_email,
            $subject,
            $body,
            "Content-type: text/html"
        );
    }

}
