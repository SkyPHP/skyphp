<?php

namespace Sky;

/**
 * @package SkyPHP
 */
class DocParser
{

    /**
     * Entier Documentation as a string, raw
     * @var string
     */
    public $docblock = '';

    /**
     * What was found, associative array [@something => value]
     * @var array
     */
    public $found = array();

    /**
     * Things that did not belong in the docBlock (for some reason, no *)
     * @var array
     */
    public $extra = array();

    /**
     * @param   string  $str
     */
    public function __construct($str)
    {
        $this->docblock = $str;
    }

    /**
     * Magic Getter, looks for the key in $this->found
     * @param   string  $name
     */
    public function __get($name)
    {
        return $this->found[$name];
    }

    /**
     * @param   string  $str
     * @return  Sky\DocParser
     */
    public static function parse($str)
    {
        $o = new self($str);
        return $o->parseDoc();
    }

    /**
     * Parses the docblock string
     * @return  $this
     */
    public function parseDoc()
    {
        $last_symbol = 'description';
        $stack = array();

        foreach (preg_split("/(\r?\n)/", $this->docblock) as $line) {

            $l = trim($line);

            if ($l == '*/' || $l == '/**') {
                continue;
            }

            if (strpos($l, '*') !== 0) {
                $this->extra[] = $l;
                continue;
            }

            $l = trim(substr($l, 1));

            if ($l[0] !== '@') {
                $stack[] = $l;
                continue;
            }

            if ($stack) {
                $this->found[$last_symbol][] = $stack;
                $stack = array();
            }

            preg_match('/@(\w+)/', $l, $matches);
            $last_symbol = $matches[1];

            $stack[] = trim(str_replace('@' . $last_symbol, '', $l));
        }

        $this->found[$last_symbol][] = $stack;

        return $this;
    }

}
