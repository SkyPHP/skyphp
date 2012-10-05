<?php

namespace Sky;

class Page
{

    /**
     * @var string
     */
    public $uri = '';

    /**
     * URI of the current page without queryfolders or querystring
     * @var string
     */
    public $urlpath = '';

    /**
     * page's folder path relative to the root of its codebase
     * @var string
     */
    public $incpath = '';

    /**
     * page's filename relative to the root of its codebase
     * @var string
     */
    public $page_path = '';

    /**
     * array of directories appnended to $this->urlpath
     * @var array
     */
    public $queryfolders = array();

    /**
     * last value of $this->queryfolders
     * @var string
     */
    public $ide = '';

    /**
     * @var string
     */
    public $slug = '';

    /**
     * what will be output in <title />
     * @var string
     */
    public $title = '';

    /**
     * @var string
     */
    public $favicon = '/favicon.ico';

    /**
     * @var string
     */
    public $apple_touch_icon = '/apple-touch-icon.png';

    /**
     * @var array
     */
    public $seo = array();

    /**
     * @var array
     * @deprecated
     */
    public $breadcrumb = array();

    /**
     * @var array
     */
    public $vars = array();

    /**
     * @var array
     */
    public $css = array();

    /**
     * @var array
     */
    public $js = array();

    /**
     * @var array
     */
    public $script = array();

    /**
     * @var array
     */
    public $script_files = array();

    /**
     * @var array
     */
    public $template_css = array();

    /**
     * @var array
     */
    public $template_js = array();

    /**
     * associative array to be added to the <html> tag
     * @var array
     */
    public $html_attrs = array();

    /**
     * associative array of data imported from:
     * database folders, settings files, and otherwise set
     * to be used in the page/template
     * @var array
     */
    public $head = array();

    /**
     * @var array
     */
    public $templates = array();

    /**
     * @var Boolean
     */
    public $is_ajax_request = false;

    /**
     * @var array
     */
    protected $cache_is_buffering = array();

    /**
     * css files that were ouput in the <head>, css added to $this->css after the header
     * will get output after the body of the page
     * @var array
     */
    protected $css_added = array();

    /**
     * Create the page object and set if this is an ajax request
     * Optionally set other properties
     * @param  array   $config
     */
    public function __construct(array $config = array())
    {
        $this->is_ajax_request = \is_ajax_request();
        $this->uri = $_SERVER['REQUEST_URI'];
        foreach ($config as $k => $v) {
            $this->{$k} = $v;
        }
    }

    /**
     * Sets some commonly used constants
     * @deprecated
     */
    public function setConstants()
    {
        $ref = $_SERVER['HTTP_REFERER'];
        $consts = array(
            'URLPATH' => $this->urlpath,
            'INCPATH' => $this->incpath,
            'IDE' => $this->ide,
            'XIDE' => substr($ref, strpos($ref, '/') + 1)
        );
        foreach ($consts as $n => $v) {
            define($n, $v);
        }
    }

    /**
     * Checks to see if there was an imput stream set that is valid JSON
     * If so, json_decode => $_POST
     */
    private function checkInputStream()
    {
        // only works with AJAX request , no $_POST, and valid JSON content type
        if (!$this->is_ajax_request ||
            $_POST ||
            !preg_match('/application\/json/', $_SERVER['CONTENT_TYPE'])
        ) {
            return;
        }

        $stream = file_get_contents('php://input');
        if ($stream) {
            $_POST = json_decode($stream, true) ?: $_POST;
        }
    }

    /**
     * Includes $this->page_path after hooks:
     *     - uri
     *     - run-first
     *     - including script files
     *     - setting constants
     *     - then run-last
     * Uses Page::includePath() to emulate them being executed in the same scope.
     * @return \Sky\Page $this
     */
    public function run()
    {
        try {
            // uri hook
            $vars = $this->includePath('lib/core/hooks/uri/uri.php', $this->vars);

            // set constants
            $this->setConstants();

            // map input stream to $_POST if applicable
            $this->checkInputStream();

            // execute run first
            $vars = $this->includePath('pages/run-first.php', $vars);

            // execute script files
            foreach (array_keys($this->script_files) as $file) {
                $vars = $this->includePath($file, $vars);
            }

            // add page_css/page_js
            $this->setAssetsByPath($this->page_path);

            // see if we're not rendering html but returning JS
            $get_contents = (bool) ($_POST['_json'] || $_GET['_script']);
            if ($get_contents) {
                if ($_GET['_script']) $this->no_template = true;
                ob_start();
            }

            // run-first / settings / script files need to be executed in the same scope
            $vars = $this->includePath($this->page_path, $vars);

            if ($get_contents) {
                // refreshing a secondary div after an ajax state change
                if (is_array($this->div)) $this->div['page'] = ob_get_contents();
                else $this->div->page = ob_get_contents();
                ob_end_clean();
                $this->sky_end_time = microtime(true);

                if ($_POST['_json']) {
                    \json_headers();
                    echo json_encode($this);
                } else {
                    header('Content-type: text/javascript');
                    echo sprintf(
                        "%s(%s,'%s','%s', '%s');",
                        $_GET['_fn']?:'render_page',
                        json_encode($this),
                        $this->uri,
                        $_SERVER['HTTP_HOST'],
                        $_GET['_script_div']?:'page'
                    );
                }
            }

            // run-last hook
            $this->includePath('pages/run-last.php', $vars);

        } catch (\Sky\AQL\Exception\Connection $e) {
            $this->includePath('pages/503.php');
            echo '<!--' . $e->getMessage() . '-->';
        }

        return $this;
    }

    /**
     * Includes the file in $this scope with variables carried through.
     * @param  string  $__p    path
     * @param  array   $__d    associative array of variables to push to this scope
     * @return array           associative array of variables that were used in the scope
     * @throws \PageException      if path is not given
     */
    public function includePath($__p = null, $__d = array())
    {
        $__d = ($__d) ?: $this->vars;

        if (!$__p) throw new PageException('path not specified.');
        if (!\file_exists_incpath($__p)) return $__d;

        // push data array into the file's scope
        foreach ($__d as $__k => $__v) $$__k = $__v;
        unset($__d, $__k, $__v);

        // for backwards compatibility
        $p = $this;
        include $__p;

        // removing $__p, otherwise it will be in defined_vars()
        unset($__p);
        return get_defined_vars();
    }

    /**
     * Includes the form of the Model in the Model's scope after adding css/js
     * @param  \Model       $o
     * @return \Sky\Page   $this
     */
    public function form(\Model $o)
    {
        $css = $o->getFormPath('css');
        if (\file_exists_incpath($css)) {
            $this->css[] = '/' . $css;
        }

        $js = $o->getFormPath('js');
        if (\file_exists_incpath($js)) {
            $this->js[] = '/' . $js;
        }

        $o->includeForm();
        return $this;
    }

    /**
     * Usage:  while ( $this->cache('myCache','1 hour') ) {
     *            // slow stuff goes here
     *         }
     *
     * Note:   doc_name must be alpha-numeric, hyphen, and underscore only or invalid
     *         characters will be substituted for compatibility with windows file names
     *
     * @param  string  $doc_name   name of the cache
     * @param  string  $duration   defaults to '30 days'
     * @return Boolean
     */
    public function cache($doc_name, $duration = '30 days')
    {
        // replace non-windows-friendly characters from the document name
        $doc_name = preg_replace('/[^a-zA-Z0-9\-\_]/i', '-', $doc_name);
        $key = $this->page_path . '/' . $doc_name;

        if ($this->cache_is_buffering[$doc_name]) {
            // we are executing the code inside the while loop
            // and outputing it
            // and writing it to disk cache
            // and exit the loop
            $document .= ob_get_contents();
            ob_flush();
            \disk($key, $document, $duration);
            unset($this->cache_is_buffering[$doc_name]);
            return false;
        }

        // if we have a document and we're not refreshing
        // output the document
        // and exit the loop
        $do_refresh = isset($_GET['refresh']) || isset($_GET['disk-refresh']);
        if (!$do_refresh) {
            $document = \disk($key);
            if ($document) {
                echo $document;
                return false;
            }
        }

        // we are (re)caching the document
        // continue the loop and execute the code inside the while loop
        ob_start();
        $this->cache_is_buffering[$doc_name] = true;
        return true;
    }

    /**
     * Gets an array of auto includes for the templates of this page
     * @param  mixed   $type
     * @return array
     */
    public function get_template_auto_includes($type = null)
    {
        // $type must be an array
        if (!$type) $type = array('css', 'js');
        else if (!is_array($type)) $type = array($type);

        // initialize an array for each type (so foreaches work)
        $r = array();
        foreach ($type as $t) $r[$t] = array();

        // traverse the tempaltes from bottom up and fine their auto included files
        foreach (array_keys(array_reverse($this->templates)) as $name) {
            $file = vsprintf('/templates/%s/%s', array_fill(0, 2, $name));
            foreach ($type as $t) {
                $r[$t] = self::get_template_auto_includes_type($file, $t, $r[$t]);
            }
        }

        // return a nested array if multiple types, otherwise just the first piece
        return (count($type === 1)) ? reset($r) : $r;
    }

    /**
     * Appends to the $arr the file if it exists and returns the array
     * @param  string  $file
     * @param  string  $type   type
     * @param  array   $arr    array to append to if the auto include exists
     * @return array           of auto includes
     */
    private function get_template_auto_includes_type($file, $type, $arr = array())
    {
        $path = sprintf('%s.%s', $file, $type);
        if (\file_exists_incpath($path)) $arr[] = $path;
        return $arr;
    }

    /**
     * maps config array to $map. appends or sets the value on the object as specified.
     * @param  array       $config     associative, must coincide to internal $map
     * @return \Sky\Page   $this
     * @throws PageException               if config is non associative
     */
    public function setConfig($config = array())
    {
        if (!$config) {
            return $this;
        }

        if ($config && !\is_assoc($config)) {
            throw new PageException('$config must be an associative.');
        }

        $p = $this;
        $set = function($var, $key) use($p) {
            $p->$key = $var;
        };
        $append = function($var, $key) use($p) {
            $p->$key = array_merge($p->$key, $var);
        };

        $map = array(
            'css' => $append,
            'js' => $append,
            'breadcrumb' => $set,
            'title' => $set,
            'vars' => $append,
            'script' => $append
        );

        foreach ($config as $k => $v) {
            if (array_key_exists($k, $map)) {
                $map[$k]($v, $k);
            }
        }

        return $this;
    }

    /**
     * this outputs the template area contents
     *
     * @param  string      $template_name
     * @param  string      $template_area
     * @param  array       $config (optional)
     * @return /Sky/Page   $this
     *
     * @global $dev
     * @global $template_alias             can be set in the config
     */
    function template($template_name, $template_area, $config = array())
    {
        global $dev, $template_alias;

        // set page vars based on $config and properties
        $this->setConfig($config);

        // replace by alias if it is set.
        $template_name = ($template_alias[$template_name]) ?: $template_name;

        // add to templates array
        if ( !$this->templates[$template_name] ) $this->templates[$template_name] = true;
        if ( $_POST['_no_template'] || $this->no_template) return $this;

        // check for hometop
        if ($this->page_path == 'pages/default/default.php' && $template_area == 'top') {
            $hometop = $this->get_template_contents($template_name, 'hometop');
            if ($hometop) {
                echo $hometop;
                return $this;
            }
        }

        // else return $template_area
        echo $this->get_template_contents($template_name, $template_area);
        return $this;
    }

    /**
     * Gets the template contents as a string based on template/and area
     * @param  string  $template_name
     * @param  string  $template_area
     * @return string
     */
    private function get_template_contents($template_name, $template_area)
    {
        $p = $this;
        ob_start();
        include vsprintf('templates/%s/%s.php', array_fill(0, 2, $template_name));
        $contents = ob_get_contents();
        ob_end_clean();
        return trim($contents);
    }

    /**
     * Renders a mustache template using the specified data
     *
     * See lib\Sky\Mustache.php for usage notes.
     *
     * @param string $mustache the mustache filename (relative to php file or codebase)
            OR mustache template markup string containing at least one {{tag}}
     * @param mixed $data object or array of properties and/or functions
     * @param mixed $partials array of partial_name => filename/markup OR $path
     * @param mixed $path path to markups or array of paths
     * @return string
     */
    public function mustache($mustache, $data, $partials = null, $path = null)
    {
        $m = new Mustache($mustache, $data, $partials, $path);
        return $m->render();
    }

    /**
     * Gets unique css files (strips duplicates from all levels)
     * @return array
     */
    function unique_css()
    {
        return $this->unique_include('css');
    }

     /**
     * Gets unique js files (strips duplicates from all levels)
     * @return array
     */
    function unique_js()
    {
        return $this->unique_include('js');
    }

    /**
     * Gets the array of unique types (css and/or js) depending on what is specified
     * @param  mixed   $types
     * @return array
     */
    public function unique_include($types = array('css', 'js'))
    {
        $types = (is_array($types)) ? $types : array($types);
        $flip = array_flip($types);
        $p = $this;

        $clean_input = function($arrs, $all = array()) {

            // cleans the arrays so that all values are unique and in the proper orders
            $arrs = array_map(function($arr) use (&$all) {
                if (!is_array($arr)) $arr = array($arr);
                return array_filter(array_map(function($val) use(&$all) {
                    if (!$val || in_array($val, $all)) return null;
                    $all[] = $val;
                    return $val;
                }, array_unique($arr)));
            }, $arrs);

            // return an array of everyhting, and one partitioned
            return array(
                'all' => $all,
                'arrs' => $arrs
            );
        };

        // clean types
        $types = array_map(function($type) use($p, $clean_input) {
            return $clean_input(array(
                'template' => $p->{'template_'.$type},
                'template_auto' => $p->get_template_auto_includes($type),
                'inc' => $p->{$type},
                'page' => array($p->{'page_'.$type})
            ));
        }, $types);

        // set types as keys
        $flip = array_map(function($f) use($types) {
            return $types[$f];
        }, $flip);

        // return array or nested array depending on how many types were given
        return (count($flip) === 1)
            ? reset($flip)
            : $flip;
    }

    /**
     * Appends file mod time as querystring only if this is a locally hosted file
     * @todo account for an already existing querystring on the file
     * @param   string  $file   the file relative to the include path (or remote asset)
     * @return  mixed   the file with appended mod time or false if file doesn't exist
     */
    public function appendFileModTime($file)
    {
        // this is not a remotely hosted file
        if (strpos($file, 'http') !== 0) {

            // if it doesn't exist locally skip it
            if (!\file_exists_incpath($file)) {
                return false;
            }

            // append the filetime to force a reload if the file contents changes
            $file .= '?' . \filemtime(\getFilename($file));
        }

        return $file;
    }

    /**
     * Outputs the JS for this page
     */
    public function javascript()
    {
        $js = $this->unique_js();
        foreach ($js['all'] as $file) {
            // append file mod time querystring to force browser reload when file changes
            $file = $this->appendFileModTime($file);
            if (!$file) {
                continue;
            }

            $this->output_js($file);
        }

        // scripts
        if (is_array($this->script)) {
            foreach ($this->script as $script) {
?>
                <script><?=$script?></script>
<?php
            }
        }

    }

    /**
     * Outputs all CSS for this page
     */
    public function stylesheet()
    {
        $css = $this->unique_css();
        foreach ($css['all'] as $file) {

            $file_without_time = $file;

            // append file mod time querystring to force browser reload when file changes
            $file = $this->appendFileModTime($file);
            if (!$file) {
                continue;
            }

            // add the file without timestamp so it doesn't also go into the footer
            $this->css_added[] = $file_without_time;
            $this->output_css($file);
        }
    }

    /**
     * Outputs css link
     * @todo add title="page" for non-tempate css files
     * @param  string  $file   css filename
     */
    public function output_css($file)
    {
?>
        <link rel="stylesheet" type="text/css" href="<?=$file?>" />
<?php
    }

    /**
     * Outputs js remote file
     * @param  string  $file   js filename
     */
    public function output_js($file)
    {
?>
        <script type="text/javascript" src="<?=$file?>"></script>
<?php
    }

    /**
     * @param  mixed   $type   array/string; css/js
     * @return array           array of unique files for that type
     * @throws PageException       if invalid type
     */
    public function do_consolidated($type)
    {
        if (!in_array($type, array('css', 'js'))) {
            throw new PageException('Cannot consolidate non js or css');
        }

        // get unique files of this type
        $files = array('local' => array(), 'remote' => array());
        $uniques = $this->{'unique_'.$type}();

        // checks if file is local or remote
        $is_remote_key = function($file) {
            return ( ( strpos($file,'http:') === 0 || strpos($file,'https:') === 0 ) )
                ? 'remote'
                : 'local';
        };

        $add_to_files = function($file) use(&$files, $is_remote_key) {
            $files[ $is_remote_key($file) ][ $file ] = true;
        };

        if (is_array($uniques['arrs']['inc'])) {
            foreach ($uniques['arrs']['inc'] as $file) {
                // adding to css_added because
                // css can be output both in the header and footer
                if ($type == 'css') $this->css_added[] = $file;
                $add_to_files($file);
            }
        }

        // does this have a page specific include
        $path = $uniques['arrs']['page'][0];
        if ($path) $files['local'][$path] = true;

        // cache local files (page specific)
        $page_inc = self::cache_files($files['local'], $type);

        // clear array (do template specific includes)
        $files['local'] = array();
        if (is_array($uniques['arrs']['template'])) {
            foreach ($uniques['arrs']['template'] as $file) $add_to_files($file);
        }

        if (is_array($uniques['arrs']['template_auto'])) {
            foreach ($uniques['arrs']['template_auto'] as $file) $add_to_files($file);
        }

        // cahce template specific files
        $template_inc = self::cache_files($files['local'], $type);

        // output consolidated css files
        foreach(array_keys($files['remote']) as $file) $this->{'output_'.$type}($file);
        if ($template_inc) $this->{'output_'.$type}($template_inc);
        if ($page_inc) $this->{'output_'.$type}($page_inc);

        return $uniques;
    }

    /**
     * output consolidated javascript
     * and a json encoded list of js_files included
     */
    public function consolidated_javascript()
    {
        $r = $this->do_consolidated('js');
        $incs = json_encode($r['all']);

        // a record of js files included
        $this->script[] = "var page_js_includes = {$incs};";

        if (is_array($this->script)) {
            foreach ($this->script as $script) {
?>
                <script type="text/javascript">
                    <?=$script?>
                </script>
<?php
            }
        }
    }

    /**
     * output consolidated css
     */
    public function consolidated_stylesheet()
    {
        $r = $this->do_consolidated('css');
        if (!is_array($this->style) || !$this->style) {
            return;
        }

        foreach ($this->style as $s) {
?>
            <style type="text/css">
                <?=$s?>
            </style>
<?php
        }
    }

    /**
     * cahces a minified array of files to `/$type_folder/$cache_name.$type`
     * @param array $files     array with keys as paths to file
     * @param string $type     text/css
     * @return string | null   null if no files or invalid type,
     *                         cache_name otherwise
     */
    public function cache_files($files, $type)
    {
        // set up so we can have other caching in the future
        // an array of acceptable types and their configurations
        $types = array(
            'js' => array(
                'folder'    => 'javascript',
                'class'     => 'JSMin',
                'method'    => 'minify'
            ),
            'css' => array(
                'folder'    => 'stylesheet',
                'class'     => 'Minify_CSS_Compressor',
                'method'    => 'process'
            )
        );

        // if invalid params return null
        if (!array_key_exists($type, $types)) return null;
        if (!is_array($files) || !$files) return null;

        // set vars used when caching the files
        // conditionals only happen once (to not repeat logic)
        $current = (object) $types[$type];
        $type_folder = $current->folder;
        $callback = array($current->class, $current->method);
        include_once sprintf('lib/minify-2.1.3/%s.php', $current->class);

        // get cache name by imploding filenames
        $cache_name = implode('-',
            array_map(function($file) use($type) {
                return str_replace('.' . $type, '', array_pop(explode('/', $file)));
            }, array_keys($files))
        );

        $cache_name = sprintf('/%s/%s.%s', $type_folder, $cache_name, $type);

        // return early if cache exists and we're not refreshing it
        if (!$_GET['refresh'] && disk($cache_name)) return $cache_name;

        // get files' contents
        ob_start();
        foreach (array_keys($files) as $file) {
            $file = substr($file, 1);
            @include $file;
            echo str_repeat("\n", 4);
        }
        $file_contents = ob_get_contents();
        ob_end_clean();

        if (!$file_contents) return $cache_name;

        // store file contents
        $file_contents = call_user_func($callback, $file_contents);
        \disk($cache_name, $file_contents);

        return $cache_name;
    }

    /**
     * minifies HTML in $this->minify
     * @return Boolean
     */
    public function minify()
    {
        include_once 'lib/minify-2.1.3/Minify_HTML.php';
        if ( $this->minifying ) {
            $html = ob_get_contents();
            ob_end_clean();
            echo \Minify_HTML::minify($html);
            unset($this->minifying);
            return false;
        } else {
            ob_start();
            $this->minifying = true;
            return true;
        }
    }

    /**
     * sets headers to redirect to give $href, exits PHP sript
     * @param string $href
     * @param int  $type   defaults to 302
     */
    public function redirect($href, $type = 302)
    {
        $href = trim($href);

        // dont redirect if redirecting to this page
        if ($href == $_SERVER['REQUEST_URI']) {
            return;
        }

        // set up
        $types = array( 301 => 'Permanently', 302 => 'Temporarily' );
        $header = 'HTTP/1.1 %d Moved %s';
        $location = 'Location: %s';

        // set message and type
        $type = ($type == 302) ? 302 : 301;
        $message = $types[$type];

        // if href doesn't have http(s):// set it up
        $protocol = ($this->protocol) ?: 'http';
        $href = (!preg_match('/^http(?:s){0,1}:\/\//', $href))
            ? sprintf('%s://%s%s', $protocol, $_SERVER['SERVER_NAME'], $href)
            : $href;

        // set headers
        header("Debug: {$href} == {$_SERVER['REQUEST_URI']}");
        header(sprintf($header, $type, $message));
        header(sprintf($location, $href));

        die;
    }

    /**
     * gets subdomain name
     * @return null | string
     */
    public  function getSubdomainName()
    {
        $server = explode('.', $_SERVER['SERVER_NAME']);
        return (count($server) <= 2) ? null : $server[0];
    }

    /**
     * creates the effect of a symlink and allows the passing of data (keys of $data)
     * to the included page to mimic the directory contents of $path
     *
     *     $p->inherit('includes/somepath');
     *
     * @param  string  $path
     * @param  array   $data     associative
     * @throws PageException
     */
    public function inherit($path, $data = array())
    {
        // add first slash if it isn't there so exploding is accurate.
        $path = (strpos($path, '/') !== 0)
            ? '/' . $path
            : $path;

        global $codebase_path_arr, $db;
        $router = new \Sky\PageRouter(array(
            'codebase_paths' => $codebase_path_arr,
            'db' => $db
        ));

        $qs = array_merge(explode('/', $path), $this->queryfolders);
        $router->checkPath($qs);

        $inherited_path = end($router->page_path);
        if (!$inherited_path) {
            throw new PageException('Page::inherit could not find this path. ' . $path);
        }

        // set variables
        $this->inherited_path = $inherited_path;
        $this->vars = array_merge($this->vars, $router->vars);
        $this->setAssetsByPath($this->inherited_path);
        $this->incpath = static::getIncPath($this->inherited_path);

        // need to set path for queryfolders to work properly
        $slug = static::getPathSlug($this->inherited_path);

        $qf = $this->queryfolders;
        $key = array_search($filename, $qf);
        $key = is_bool($key) ? 0 : $key + 1;
        $this->queryfolders = array_slice($qf, $key);

        // call this in a closure so that
        // the inherited page does not have any previously declared vars
        $this->includePath($this->inherited_path, $data);
    }

    /**
     * Gets the slug from the given path. This will only work for .php files
     * Examples:
     *      [param]                         => [return]
     *      path/to/asdf/asdf.php           => asdf
     *      path/to/asdf.php                => asdf
     *      path/to/asdf/asdf-listing.php   => asdf
     *      path/to/asdf/asdf-profile.php   => asdf
     * @param   string  $path
     * @return  string
     */
    protected static function getPathSlug($path)
    {
        return str_replace(
            array('-listing', '-profile', '.php'),
            '',
            end(array_filter(explode('/', $path)))
        );
    }

    /**
     * Returns the enclosing path for the filepath (assuming that $path ends with a file)
     * Example:
     *      path/to/something/something.php => path/to/something
     * @param   string  $path
     * @return  string
     */
    protected static function getIncPath($path)
    {
        $path = array_filter(explode('/', $path));
        array_pop($path);
        return implode('/', $path);
    }

    /**
     * Sets page_js and page_css
     * If they are set before hand, moves them to the css and js arrays
     * @param  string  $path
     */
    public function setAssetsByPath($path)
    {
        $assets = array('css', 'js');
        $replace = array('-profile', '-listing');
        $prefix = substr(str_replace($replace, null, $path), 0, -4);
        foreach ($assets as $asset) {
            $page_asset = 'page_' . $asset;
            if ($this->{$page_asset}) {
                $this->{$asset}[] = $this->{$page_asset};
                $this->{$page_asset} = null;
            }
            $file = sprintf('%s.%s', $prefix, $asset);
            if (!\file_exists_incpath($file)) continue;
            $this->{$page_asset} = '/' . $file;
        }
    }

    /**
     * @return array   associative or empty array of key value pairs of meta tags
     *                 meta_name => meta_content
     */
    public function seoMetaContent()
    {
        // <meta name="$key" /> => $this->seo[$value]
        $meta = array(
            'title'                     => 'meta_title',
            'description'               => 'meta_description',
            'subject'                   => 'meta_subject',
            'keywords'                  => 'meta_keywords',
            'copyright'                 => 'domain',
            'ICBM'                      => 'ICBM',
            'geo.position'              => 'ICBM',
            'geo.placename'             => 'placename',
            'geo.region'                => 'geo-region',
            'zipcode'                   => 'zipcode',
            'city'                      => 'city',
            'state'                     => 'state',
            'country'                   => 'country',
            'google-site-verification'  => 'google_site_verification'
        );

        $seo = $this->seo;
        $map = function($r) use($seo) {
            return ($seo[$r]) ?: null;
        };

        return ($seo)
            ? array_filter(array_map($map, $meta))
            : array();
    }

    /**
     * @return string  html attributes based on $this->html_addrs
     */
    public function getHTMLAttrString()
    {
        $attrs  = '';
        if ($this->html_attrs) {
            foreach ($this->html_attrs as $k => $v) {
                $attrs .= " {$k}=\"{$v}\"";
            }
        }
        return $attrs;
    }

}

class PageException extends \Exception
{
}
