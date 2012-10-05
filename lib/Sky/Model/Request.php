<?php

namespace Sky\Model;

/**
 *  Handles CRUD requrests to models
 *  See pages/aql/save.php && pages/aql/delete.php for usage
 */
class Request
{

    /**
     *  @var string
     */
    public $model_name;

    /**
     *  @var array
     */
    public $response;

    /**
     *  @var string
     */
    public $action;

    /**
     *  @var Model
     */
    public $object;

    /**
     *  @var \Sky\Page
     */
    public $page;

    /**
     *  Allowed actions
     *  @var string
     */
    public static $actions = 'save|delete';

    /**
     *  @param  \Sky\Page   $p
     */
    public function __construct(\Sky\Page $p = null)
    {
        $this->page = $p;
        $this->model_name = $p->queryfolders[0];
    }

    /**
     *  Excecutes the request -- and sets $this->response
     *  @param  string      $action
     *  @return $this
     *  @throws \Exception  if invalid action
     */
    public function run($action = null)
    {
        if (!$action || !in_array($action, explode('|', self::$actions))) {
            throw new Exception('Invalid action.');
        }
        $this->action = $action;

        if (!preg_match('/^[\w0-9]+$/', $this->model_name)) {
            $this->response = array(
                'status' => 'Error',
                'errors' => array('Invalid Model Name.')
            );
        } else if (!$_POST) {
            $this->response = array(
                'status' => 'Error',
                'errors' => array('No Data Submitted In Request.')
            );
        } else {
            $m = $this->model_name;
            $this->object = new $m($_POST, array(
                'use_token_validation' => true
            ));
            $this->response = $this->object->{$this->action}();
        }
        return $this;
    }

    /**
     *  If this is an ajax request, we exit json
     *  Otherwise redirect to the page we either came from, or $_GET[return_uri]
     */
    public function finish()
    {
        $p = $this->page;
        if ($p->is_ajax_request) {
            exit_json($this->response);
        }

        $to = ($_GET['return_uri']) ?: $_SERVER['HTTP_REFERER'];
        $get = ($this->response['status'] == 'OK')
            ? array('status' => 'OK')
            : $this->response;

        $get = '?return='.rawurlencode(serialize($get));

        $p->redirect($to.$get, 302);
    }

    /**
     *  @param  string      $action
     *  @param  \Sky\Page   $page
     */
    public static function runRequest($action, \Sky\Page $page)
    {
        $request = new self($page);
        try {

            $request->run($action)->finish();

        } catch (\ValidationException $e) {

            $request->response = array(
                'status' => 'Error',
                'type' => 'ValidationException',
                'errors' => array_map(function($o) {
                    return $o->message;
                }, $e->getErrors())
            );
            $request->finish();

        } catch (\Exception $e) {

            $request->response = array(
                'status' => 'Error',
                'type' => 'Exception',
                'errors' => array(
                    $e->getMessage()
                )
            );
            $request->finish();

        }
    }
}
