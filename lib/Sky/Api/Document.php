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
class Document
{

    protected $api;

    protected $resources = array();

    public $parsed = array();

    public function __construct(\Sky\Api $api)
    {
        $this->api = $api;
        $this->resources = $this->api->resources;

        $this->walkResources();
    }

    protected function walkResources()
    {
        array_walk($this->resources, array($this, 'walkResource'));
    }

    protected function walkResource($value, $name)
    {
        $cl = $value['class'];
        $reflection = new \ReflectionClass($cl);

        $actions = $reflection->getStaticProperties();
        $actions = $actions['api_actions'];

        $found = array(
            'general' => array(),
            'aspects' => array()
        );

        $types = array_keys($found);

        foreach ($actions as $m => $a) {

            $method_name = $a['method'] ?: \Sky\Api::toCamelCase($m);
            if ($method_name == $m) {
                $method_name = \Sky\Api::toCamelCase($m, '_');
            }

            $method = $reflection->getMethod($method_name);

            $doc = $method->getDocComment();
            $parsed = \Sky\DocParser::parse($doc);

            $type = $types[!$method->isStatic()];

            $found[$type][$m] = array(
                'params' => $parsed->apiParam,
                'doc' => $parsed->apiDoc
            );
        }

        $this->parsed[$name] = $found;
    }

}
