<?php

namespace Sky\Api;

class Response {
    
    /**
     * will contain the status of the api call and possibly an error message
     * @var array
     */
    public $meta;
    
    /**
     * will contain the response data from the api call
     * @var array
     */
    public $response;

    /**
     * return "this" api response in json format
     * @return string json
     */ 
    function json($flag=null) {
        $value = json_beautify(json_encode($this));
        switch ($flag) {
            case 'pre':
                $value = "<pre>{$value}</pre>";
                break;
        }
        return $value;
    }

}
