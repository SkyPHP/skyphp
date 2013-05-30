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
        $this->resources = $api::$resources;
    }

    /**
     * Gets the documetation of the class file
     * @return  string
     */
    public function getAPiDoc()
    {
        $re = new \ReflectionClass($this->api);
        $parser = \Sky\DocParser::parse($re->getDocComment());

        return \Sky\DocParser::docAsString($parser->found['apiDoc']);
    }

    /**
     * Parses an individual resource of the current API
     * @param   string  $name
     * @return  array
     * @throws \Exception   if resource not found
     */
    public function parseResource($name)
    {
        foreach ($this->resources as $key => $value) {
            if ($key == $name) {
                return $this->walkResource($value, $name);
            }
        }

        throw new \Exception("Resource [$name] not found for this API.");
    }

    /**
     * Parses each resource
     * @return  array
     */
    public function walkResources()
    {
        array_walk($this->resources, array($this, 'walkResource'));

        return $this->parsed;
    }

    /**
     * Parses a resource's api_actions and separates them into general/aspects
     * depending on if they are static or not.
     * Uses `apiDoc` and `apiParam` to get documentation
     * This is an argument format for array_walk callback.
     * @param   array   $value
     * @param   string  $name
     * @return  array   parsed info
     */
    protected function walkResource($value, $name)
    {
        if (array_key_exists($name, $this->parsed)) {

            return $this->parsed[$name];
        }

        $class_name = $value['class'];

        $reflection = new \ReflectionClass($class_name);

        // container for parsed docs
        $found = array('general' => array(), 'aspects' => array());

        // keys to index by truthiness of if the method is static or not
        $types = array_keys($found);

        $actions = static::getApiActions($reflection);
        foreach ($actions as $m => $a) {
            $method_name = static::getMethodName($m, $a);
            if (method_exists($class_name, $method_name)) {
                $method = $reflection->getMethod($method_name);
                $type = $types[!$method->isStatic()];
                $found[$type][$m] = array_merge(
                    static::getParsedArray($method),
                    array(
                        'method' => $m,
                        $type => true
                    )
                );
            }
        }

        $construct = $reflection->getMethod('__construct');
        $found['construct'] = static::getParsedArray($construct);

        // set to property
        return $this->parsed[$name] = $found;
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
     * Finds and returns the documentation for the given aspect of a resource
     * @param   string  $resource
     * @param   string  $method
     * @return  array
     * @throws  \Exception if apect not found
     */
    public function getResourceDoc($resource, $method = null)
    {
        $parsed = $this->parseResource($resource);
        if (!$method) {

            return $parsed['construct'];
        }

        $all = array_merge($parsed['general'], $parsed['aspects']);
        if (!array_key_exists($method, $all)) {
            throw new \Exception('Method not found');
        }

        return $all[$method];
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
            'params' => static::parseParamDoc($docs->apiParam),
            'doc' => $docs->apiDoc
        );
    }

    /**
     * Parses contextual information from a param block
     * Format: <type> <var> <description> (can be multiline, var $php format)
     * @param   array   $arr
     * @return  array
     */
    protected static function parseParamDoc($arr)
    {
        if (!$arr) {
            return array();
        }

        $docs = array();
        $pattern = '/(?<type>\w+)\s+(?<name>\$\w+)(?<etc>\s+.*)?/';
        $found = array();
        foreach ($arr as $par) {
            foreach ($par as $line) {
                if (preg_match_all($pattern, $line, $matches)) {
                    $docs[] = $found;
                    $found = array(
                        'type' => $matches['type'][0],
                        'name' => substr($matches['name'][0], 1),
                        'description' => array(trim($matches['etc'][0]))
                    );

                } else {
                    $found['description'][] = $line;
                }

            }
        }

        $docs[] = $found;

        return array_values(array_filter($docs));
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
