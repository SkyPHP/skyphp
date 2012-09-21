<?php

namespace Sky;

/**
 * @package SkyPHP
 */
class DataConversion
{

    /**
     * The node name to use for items in a list
     * @var
     */
    public static $item_string = 'item';

    /**
     * Converts an array to an XML string.
     * Pass in a multi dimensional array and this recrusively loops through and builds up
     * an XML document.
     * @param array $data
     * @param string $root_name - what you want the root node to be - defaultsto data.
     * @param SimpleXMLElement $xml - should only be used recursively
     * @return string XML
     */
    public static function arrayToXml($data, $root_name = 'data', \SimpleXMLElement $xml = null)
    {
        // turn off compatibility mode as simple xml throws a wobbly if you don't.
        if (ini_get('zend.ze1_compatibility_mode') == 1) {
            ini_set ('zend.ze1_compatibility_mode', 0);
        }

        if ($xml == null) {
            $xml = simplexml_load_string("<?xml version='1.0' encoding='utf-8'?><$root_name />");
        }

        // loop through the data passed in.
        foreach ($data as $key => $value) {
            // no numeric keys in our xml please!
            if (is_numeric($key)) {
                // make string key...
                $key = static::$item_string;
            }

            // replace anything not alpha numeric
            $key = preg_replace('/[^a-z_]/i', '', $key);

            // if there is another array found recursively call this function
            if (is_array($value)) {
                $node = $xml->addChild($key);
                // recrusive call.
                static::arrayToXml($value, $root_name, $node);
            } else {
                // add single node.
                $value = htmlentities(utf8_encode($value));
                $xml->addChild($key,$value);
            }

        }
        // pass back as string
        return $xml->asXML();
    }

    /**
     * Converts an object to an array recursively
     * @param object $obj
     * @return array
     */
    public static function objectToArray($obj)
    {
        $_arr = is_object($obj) ? get_object_vars($obj) : $obj;
        foreach ($_arr as $key => $val) {
                $val = (is_array($val) || is_object($val))
                    ? static::objectToArray($val)
                    : $val;
                $arr[$key] = $val;
        }
        return $arr;
    }

}
