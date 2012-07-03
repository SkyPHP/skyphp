<?php

namespace Sky;

/**
 * SkyPHP adapter for Mustache.php
 *
 * Usage:
 *
 * new Mustache($markup, $data);
 * new Mustache($filename, $data);
 * new Mustache($filename, $data, $path);
 * new Mustache($filename, $data, $partials);
 * new Mustache($filename, $data, $partials, $path);
 *
 * Where:
 *
 * $markup (string) mustache markup containing at least one {{tag}}
 * $filename (string) filename relative to the codebase or relative to $path
 * $data (array|object) array with values/functions OR object with properties/methods
 * $partials (array) associative array('partial' => $filename)
 *                               array('partial' => $markup)
 * $path (string|array) path where markups can be found or non-associative array of paths
 */
class Mustache
{

    /**
     * The primary markup to be rendered
     * @var string
     */
    private $markup;

    /**
     * Array of partials: name => markup
     * @var array
     */
    private $partials;

    /**
     * @var array
     */
    private $data;

    /**
     * Keep track of the partials that we have found markup
     * @var array
     */
    private $confirmed_partials;

    /**
     * Sky Mustache Constructor
     * @param string $mustache mustache filename (relative to calling php file or codebase)
            OR mustache markup string containing at least one {{tag}}
     * @param mixed $data object with properties/methods or array of values/functions
     * @param mixed $partials see usage notes above
     * @param mixed $path see usage notes above
     * @return string
     */
    public function __construct($mustache, $data, $partials = null, $path = null)
    {
        // if $partials is not an associative array, assume it is $path
        if (!\is_assoc($partials)) {
            // $path was provided as the 3rd param
            // ignore the 4th param
            $path = $partials;
            $partials = null;
        }

        $paths = \arrayify($path);

        // get the mustache markup
        $markup = $this->getMarkup($mustache);
        if (!$markup) {
            // the requested mustache file is not in the include path
            // so let's try to find it relative to the path(s) provided
            $markup = $this->getMarkup($mustache, $paths);
        }

        $this->markup = $markup;
        $this->data = $data;
        $this->partials = $this->getPartials($markup, $paths, $partials);
    }

    /**
     * Renders the mustache
     * @return string
     */
    public function render()
    {
        $m = new \Mustache;
        return $m->render($this->markup, $this->data, $this->partials);
    }


    /***************************************
     *           Private methods           *
     ***************************************/

    /**
     * Gets the mustache markup
     * @param string $mustache either the mustache filename or mustache markup string
     * @return string mustache markup
     */
    private function getMarkup($mustache, $paths = array())
    {
        if (strpos($mustache, '{{') !== false) return $mustache;
        if (is_array($paths)) {
            foreach ($paths as $path) {
                $path = rtrim($path, '/') . '/';
                $markup = file_get_contents($path . $mustache, true);
                if ($markup) return $markup;
            }
        }
        return @file_get_contents($mustache, true);
    }

    /**
     * Recursively get the markup for each partial needed. Append any newly found partials
     * to the known $partials array.
     * @param string $markup the mustache doc to check for partials
     * @param array $paths the list of paths to check where each partial may exist
     * @param array $partials the array of partials already known
     * @return array
     */
    private function getPartials($markup, $paths = array(), $partials = array())
    {
        // get the markup for all partials 'included' in our markup
        $matches = $this->identifyPartials($markup);
        foreach ($matches as $name) {
            if (!$this->confirmed_partials[$name]) {
                $partials[$name] = $this->getMarkup($name, $paths);
                if ($partials[$name]) {
                    $markup = $partials[$name];
                    $partials = $this->getPartials($markup, $paths, $partials);
                }
                if (!$partials[$name]) {
                    throw new \Exception("Mustache partial '$name' not found.");
                }
                $this->confirmed_partials[$name] = true;
            }
        }
        return $partials;
    }

    /**
     * Gets the name of each partial in this mustache markup
     * TODO: account for delimiters being set in the mustache markup
     * @param string $markup mustache markup
     * @return array partial names
     */
    private function identifyPartials($markup)
    {
        $pattern = "#\{\{\>[\s]*(.+?)[\s]*\}\}#";
        preg_match_all($pattern, $markup, $matches);
        return $matches[1];
    }

}
