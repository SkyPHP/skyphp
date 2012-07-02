<?php

namespace Sky;

class Mustache {

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
     * Sky Mustache Constructor
     * @param string $mustache mustache filename (relative to calling php file or codebase)
            OR mustache markup string containing at least one {{tag}}
     * @param mixed $data object with properties/methods or array of values/functions
     * @param mixed $partials path to partials or array of name => filename/markup
     * @param string $path path to check for the main markup file (if applicable)
     * @return string
     */
    public function __construct($mustache, $data, $partials=null, $path=null)
    {
        // get the mustache markup
        $markup = $this->getMarkup($mustache);
        if (!$markup) {
            // the requested mustache file is not in the include path
            // so let's try to find it relative to the php file
            $markup = $this->getMarkup($mustache, $path);
        }

        $paths = array($path);
        // get the markup for the partials we need
        if ($partials && !is_array($partials)) {
            $paths = array(
                $path,
                $partials,
                $path . $partials
            );
            $partials = null;
        }

        $this->markup = $markup;
        $this->data = $data;
        $this->partials = $this->getPartials($markup, $paths, $partials);

    }

    /**
     * Renders the mustache
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
    private function getMarkup($mustache, $path=null)
    {
        if ($path) {
            // a path was provided
            $path = rtrim($path, '/') . '/';
            return @file_get_contents($path . $mustache, true);
        }
        if (strpos($mustache, '{{') !== false) return $mustache;
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
    private function getPartials($markup, $paths=null, $partials=null)
    {
        // get the markup for all partials 'included' in our markup
        $matches = $this->identifyPartials($markup);
        foreach ($matches as $name) {
            if (!$partials[$name]) {
                foreach ($paths as $path) {
                    $partials[$name] = $this->getMarkup($name, $path);
                    if ($partials[$name]) {
                        $markup = $partials[$name];
                        $partials = $this->getPartials($markup, $paths, $partials);
                        break;
                    }
                }
                if (!$partials[$name]) throw new \Exception(
                    "Mustache partial '$name' not found."
                );
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
