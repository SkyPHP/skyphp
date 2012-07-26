<?php

namespace Sky\AQL\Exception;

class Transaction extends \Sky\AQL\Exception
{

    /**
     * insert | update
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
     * @var array
     */
    public $fields = array();

    /**
     * @var int
     */
    public $id = null;


    public function __construct($table, $fields, $id, $db)
    {
        $this->table = $table;
        $this->fields = $fields;
        $this->id = $id;
        $this->type = ($this->id) ? 'update' : 'insert';
        $this->db_error = $db->ErrorMsg();
        $this->sql = $this->getSQL($db);

        parent::__construct($this->makeMessage());
    }

    private function getSQL($db)
    {
        $method = sprintf('Get%sSQL', ucwords($this->type));
        $args = array($this->table, $this->fields, $this->id);
        return call_user_func_array(
            array($db, $method),
            $args
        );
    }

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

    public function sendErrorEmail()
    {
        global $aql_error_email;

        if (!$aql_error_email) {
            return;
        }

        $subject = 'AQL ' . $this->type . ' Error:';
        $dump = print_r($this, true);
        $trace = $this->getTraceAsString();
        $body = "<pre>{$dump}\n---\n{$trace}</pre>";

        @mail(
            $aql_error_email,
            $subject,
            $body,
            "Content-type: text/html\r\n"
        );
    }

}
