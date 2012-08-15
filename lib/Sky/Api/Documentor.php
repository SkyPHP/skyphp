<?php

namespace Sky\Api;

/**
 * This class allows for automatic generation of documentation
 * for \Sky\Api\Resource objects by parsing the Api configuration and using reflection
 * on the DocBlocks.
 *
 * Use new `@apiParam` and `@apiDoc`
 *
 * @package SkyPHP
 */
class Documentor
{

    /**
     * @var \Sky\Api
     */
    protected $api;

    /**
     * Resources in the api
     * @var array
     */
    protected $resources = array();

    /**
     * Parsed Documentation for the API
     * @var array
     */
    public $parsed = array();

    /**
     * Initializes the parser through the resources
     * @param   \Sky\Api    $api
     */
    public function __construct(\Sky\Api $api)
    {
        $this->api = $api;
        $this->resources = $this->api->resources;

        $this->walkResources();
    }

    /**
     * Parses each resource
     */
    protected function walkResources()
    {
        array_walk($this->resources, array($this, 'parseResource'));
    }

    /**
     * Parses a resource's api_actions and separates them into general/aspects
     * depending on if they are static or not.
     * Uses `apiDoc` and `apiParam` to get documentation
     * This is an argument format for array_walk callback.
     * @param   array   $value
     * @param   string  $name
     */
    protected function parseResource($value, $name)
    {
        $reflection = new \ReflectionClass($value['class']);

        // container for parsed docs
        $found = array('general' => array(), 'aspects' => array());

        // keys to index by truthiness of if the method is static or not
        $types = array_keys($found);

        $actions = static::getApiActions($reflection);
        foreach ($actions as $m => $a) {
            $method = $reflection->getMethod(static::getMethodName($m, $a));
            $type = $types[!$method->isStatic()];
            $found[$type][$m] = static::getParsedArray($method);
        }

        $construct = $reflection->getMethod('__construct');
        $found['construct'] = static::getParsedArray($construct);

        // set to property
        $this->parsed[$name] = $found;
    }

    /**
     * Gets api_actions array from the reflectiosn class static props
     * @param   \ReflectionClass    $re
     * @return  array
     */
    protected static function getApiActions(\ReflectionClass $re)
    {
        $props = $re->getStaticProperties();

        return $props['api_actions'];
    }

    /**
     * Gets api docblock info from the Method
     * @param   \ReflectionMethod   $re
     * @return  array
     */
    protected static function getParsedArray(\ReflectionMethod $re)
    {
        $docs = \Sky\DocParser::parse($re->getDocComment());

        return array(
            'params' => $docs->apiParam,
            'doc' => $docs->apiDoc
        );
    }

    /**
     * Get the Method Name
     * @param   string  $key
     * @param   array   $conf
     * @return  string
     */
    protected static function getMethodName($key, $conf)
    {
        if ($conf['method']) {
            return $conf['method'];
        }

        $n = \Sky\Api::toCamelCase($key);
        if ($n == $key) {
            $n = \Sky\Api::toCamelCase($key, '_');
        }

        return $n;
    }

}
