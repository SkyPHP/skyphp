<?
class page {

    public $uri;
    public $urlpath;
    public $incpath;
    public $page_path;
    public $queryfolders;
    public $ide;
    public $slug;
    public $title;
    public $seo = array();
    public $breadcrumb = array();
    public $vars = array();
    public $css = array();
    public $js = array();
    public $script = array();
    public $html_attrs = array();
    public $head = array();
    public $templates = array();
    public $is_ajax_request = false;
    protected $cache_is_buffering = array();
    protected $cache_already_output = array();
    protected $css_added = array();

    public function __construct($template=null) {
        $this->uri = $_SERVER['REQUEST_URI'];
        $this->is_ajax_request = is_ajax_request(); // in functions.inc
		if ($seo_enabled) {
			$rs=aql::select("website { where domain = '{$_SERVER['SERVER_NAME']}'");
			$this->seo($page_path,$rs[0]['website_id']);
		}
        // database folder detection
        // canonicalization
        // remember uri /
        // authentication, remember me
    }

    /*  Usage:
     *
     *  while ( $p->cache('myCache','1 hour') ) {
     *      // slow stuff goes here
     *  }
     *
     *  note:
     *  doc_name must be alpha-numeric, hyphen, and underscore only or invalid
     *  characters will be substituted for compatibility with windows file names
     */
    function cache($doc_name, $duration='30 days') {
        $doc_name = preg_replace("#[^a-zA-Z0-9\-\_]#i", "-", $doc_name);
        $pattern = '/^[a-zA-Z0-9][a-zA-Z0-9\-\_]+$/';
        $key = $this->page_path . '/' . $doc_name;
        if ( $this->cache_is_buffering[$doc_name] ) {
			/*
            if ($this->file_type == 'js') {
				$document .= "\n // cached ".date('m/d/Y H:ia') . " \n";
			} else {
				$document .= "\n<!-- cached " . date('m/d/Y H:ia') . " -->\n";
            }
            */
			$document .= ob_get_contents();
			ob_flush();
            if ( !$this->cache_already_output[$doc_name] ) echo $doc;
            disk( $key, $document, $duration );
            unset($this->cache_is_buffering[$doc_name]);
            unset($this->cache_already_output[$doc_name]);
            return false;
        } else {
            $document = disk( $key );
            if ( !$document || isset($_GET['refresh']) || isset($_GET['disk-refresh']) ) {
                ob_start();
                $this->cache_is_buffering[$doc_name] = true;
                return true;
            } else if ( $document ) {
                echo $document;
                return false;
            } else {
               echo $document;
               ob_start();
               $this->cache_is_buffering[$doc_name] = true;
               $this->cache_already_output[$doc_name] = true;
               return true;
            }
        }
        return false;
    }

    function get_template_auto_includes($type = null) {
        $r = array();
        if (!$type) $type = array('css', 'js');
        else if (!is_array($type)) $type = array($type);
        foreach ($type as $t) { $r[$t] = array(); }
        foreach (array_keys(array_reverse($this->templates)) as $name) {
            $file = '/templates/'.$name.'/'.$name;
            foreach ($type as $t) {
                $r[$t] = self::get_template_auto_includes_type($file, $t, $r[$t]);
            }
        }
        if (count($type) == 1) return reset($r);
        return $r;
    }

    function get_template_auto_includes_type($file, $type, $arr = array()) {
        if (file_exists_incpath($file.'.'.$type)) {
            $arr[] = $file.'.'.$type;
        }
        return $arr;
    }

    public function setConfig($config = array()) {
        
        if (!$config) return;
        if ($config && !is_assoc($config)) {
            throw new Exception('Attempting to set page class variables with page::setConfig(), argument must be an associative array.');
            return;
        }

        $p = $this;
        $set = function($var, $key) use($p) { $p->$key = $var; };
        $append = function($var, $key) use($p) { $p->$key = array_merge($p->$key, $var); };

        $map = array(
            'css' => $append,
            'js' => $append,
            'breadcrumb' => $set,
            'title' => $set,
            'vars' => $append,
            'script' => $append
        );

        foreach ($config as $k => $v) {
            if (!array_key_exists($k, $map)) continue;
            $map[$k]($v, $k);
        }

    }

    function template($template_name, $template_area, $config = array()) {
        
        global $dev, $template_alias;    
        
        // set page vars based on 
        $this->setConfig($config);

        // replace by alias if it is set.
        $template_name = ($template_alias[$template_name]) ? $template_alias[$template_name] : $template_name;

        // add to templates array
        if ( !$this->templates[$template_name] ) $this->templates[$template_name] = true;
        if ( $_POST['_no_template'] ) return $this;
        if ( $this->no_template ) return $this;

        if ($this->page_path == 'pages/default/default.php' && $template_area == 'top') {
            $hometop = $this->get_template_contents($template_name, 'hometop');
            if ($hometop) {
                echo $hometop;
                return $this;
            }
        }
        echo $this->get_template_contents($template_name, $template_area);
        return $this;
    }

    private function get_template_contents($template_name, $template_area) {
        $p = $this;
        ob_start();
        include ( 'templates/' . $template_name . '/' . $template_name . '.php');
        $contents = ob_get_contents();
        ob_end_clean();
        return trim($contents);
    }

    function unique_css() {
        return $this->unique_include('css');
    }

    function unique_js() {
        return $this->unique_include('js');
    }

    function unique_include($types = array('css', 'js')) {
        $types = (is_array($types)) ? $types : array($types);
        $flip = array_flip($types);
        $p = $this;

        $clean_input = function($arrs, $all = array()) {
            $arrs = array_map(function($arr) use (&$all) {
                if (!is_array($arr)) $arr = array($arr);
                return array_filter(array_map(function($val) use(&$all) {
                    if (!$val || in_array($val, $all)) return null;
                    $all[] = $val;
                    return $val;
                }, array_unique($arr)));
            }, $arrs);
            return array('all' => $all, 'arrs' => $arrs);
        };

        $types = array_map(function($type) use($p, $clean_input) {
            return $clean_input(array(
                'template' => $p->{'template_'.$type},
                'template_auto' => $p->get_template_auto_includes($type),
                'inc' => $p->{$type},
                'page' => array($p->{'page_'.$type})
            ));
        }, $types);

        $flip = array_map(function($f) use($types) { return $types[$f]; }, $flip);
        if (count($flip) == 1) return reset($flip);
        return $flip;

    }

    function javascript() {
        $js = $this->unique_js();
        foreach ($js['all'] as $file) {
            if (!file_exists_incpath($file) && strpos($file, 'http') !==0 ) continue;
            $this->output_js($file);  
        }
        // scripts
        if (is_array($this->script))
        foreach ( $this->script as $script ) {
            ?><script><?=$script?></script><?
        }
    }

    function stylesheet() {
        $css = $this->unique_css();
        foreach ($css['all'] as $file) {
            if (!file_exists_incpath($file)) continue;
            $this->css_added[] = $file;
            $this->output_css($file);
        }
    }

    function output_css($file) {
        ?><link rel="stylesheet" type="text/css" href="<?=$file?>" /><?
        echo "\n";
    }

    function output_js($file) {
        ?><script type="text/javascript" src="<?=$file?>"></script><?
        echo "\n";
    }

    function do_consolidated($type) {
        if (!in_array($type, array('css', 'js'))) throw new Exception('Cannot consolidate non js or css');
        $uniques = $this->{'unique_'.$type}();
        $files = null;

        $is_remote_key = function($file) { 
            return ( ( strpos($file,'http:') === 0 || strpos($file,'https:') === 0 ) )  ? 'remote' : 'local'; 
        };

        if (is_array($uniques['arrs']['inc'])) foreach ($uniques['arrs']['inc'] as $file) {
            if ($type == 'css') $this->css_added[] = $file;
            $files[ $is_remote_key($file) ][ $file ] = true;
        }
        if ($uniques['arrs']['page'][0]) $files['local'][$uniques['arrs']['page'][0]] = true;
        $page_inc = page::cache_files($files['local'], $type);


        $files['local'] = null;
        if (is_array($uniques['arrs']['template'])) foreach ($uniques['arrs']['template'] as $file) $files[ $is_remote_key($file) ][ $file ] = true;
        if (is_array($uniques['arrs']['template_auto'])) foreach ($uniques['arrs']['template_auto'] as $file) $files['local'][$file] = true;
        $template_inc = page::cache_files($files['local'], $type);

        # output consolidated css files
        if (is_array($files['remote'])) 
            foreach(array_keys($files['remote']) as $file) 
                $this->{'output_'.$type}($file);
        
        if ($template_inc) $this->{'output_'.$type}($template_inc);
        if ($page_inc) $this->{'output_'.$type}($page_inc);
        return $uniques;

        if (is_array($this->style)) foreach ($this->style as $s) {
            ?><style><?=$s?></style><?
        }

    }

    function consolidated_javascript() {
        $r = $this->do_consolidated('js');
        $this->script[] = 'var page_js_includes = '.json_encode($r['all']).';';
        if (is_array($this->script))
        foreach ( $this->script as $script ) {
            ?><script><?=$script?></script><?
            echo "\n";
        }
    }

    function consolidated_stylesheet() {
        $r = $this->do_consolidated('css');
        if (is_array($this->style)) foreach ($this->style as $s) {
            ?><style><?=$s?></style><?
        }
    }

    function cache_files( $files, $type ) {
        switch ($type) {
        case 'js':
            $type_folder = 'javascript';
            include_once('lib/minify-2.1.3/JSMin.php');
            break;
        case 'css':
            $type_folder = 'stylesheet';
            include_once('lib/minify-2.1.3/Minify_CSS_Compressor.php');
            break;
        }
        if (is_array($files)) {
            foreach ( $files as $file => $null ) {
                $filename = array_pop(explode('/',$file)); // strip path
                $filename = str_replace('.'.$type,'',$filename);
                if ($cache_name) $cache_name .= '-';
                $cache_name .= $filename;
            }
            if ( count($files) ) {
                $cache_name = '/' . $type_folder . '/' . $cache_name . '.' . $type;
                // check if we have a cache value otherwise read the files and save to cache
                if ( !disk($cache_name) || $_GET['refresh'] ) {
                    ob_start();
                    foreach ( $files as $file => $null ) {
                        $file = substr($file,1);
                        @include($file);
                        echo "\n\n\n\n\n";
                    }
                    $file_contents = ob_get_contents();
                    ob_end_clean();
                    if ( $file_contents ) {
                        switch ($type) {
                            case 'css':
                                $file_contents = Minify_CSS_Compressor::process($file_contents);
                                break;
                            case 'js':
                                $file_contents = JSMin::minify($file_contents);
                                break;
                        }
                        disk($cache_name,$file_contents);
                    }
                }
            }
        }
        return $cache_name;
    }

    function minify() {
        include_once('lib/minify-2.1.3/Minify_HTML.php');
        if ( $this->minifying ) {
			$html = ob_get_contents();
			ob_end_clean();
            echo Minify_HTML::minify($html);
            unset($this->minifying);
            return false;
        } else {
            ob_start();
            $this->minifying = true;
            return true;
        }
    }

    function redirect($href, $type=302) {
		// TODO add support for https
		if ( $href == $_SERVER['REQUEST_URI'] ) return false;
        else header("Debug: $href == {$_SERVER['REQUEST_URI']}");

		if (stripos($href,"http://") === false || stripos($href,"http://") != 0)
			if (stripos($href,"https://") === false || stripos($href,"https://") != 0)
				$href = "http://$_SERVER[SERVER_NAME]" . $href;

        if ( $type == 302 ) {
            header("HTTP/1.1 302 Moved Temporarily");
            header("Location: $href");
        } else {
            header("HTTP/1.1 301 Moved Permanently");
            header("Location: $href");
        }
		die();
    }

    function getSubdomainName() {
        $server = explode('.', $_SERVER['SERVER_NAME']);
        return (count($server) <= 2) ? null : $server[0];
    }

    /*
        @param (string) $path
        @param (associative array) $data
        
        creates the effect of a symlink and allows the passing of data (keys of $data)
        to the included page to mimic the directory contents of $path

        $p->inherit('includes/somepath');

    */
    function inherit($path, $data = array()) {
        
        # add first slash if it isn't there so exploding is accurate.
        $path = (strpos($path, '/') !== 0)
            ? '/' . $path
            : $path;

        global $codebase_path_arr, $db;
        $router = new SkyRouter(array(
            'codebase_paths' => $codebase_path_arr,
            'db' => $db
        ));

        $qs = array_merge(explode('/', $path), $this->queryfolders);
        $router->checkPath($qs);
        
        $inherited_path = end($router->page_path);
        if (!$inherited_path) {
            throw new Exception('page::inherit could not find this path. ' . $path);
        }
        
        # set variables
        $this->inherited_path = $inherited_path;
        $this->vars = array_merge($this->vars, $router->vars);
        $this->setAssetsByPath($this->inherited_path);
        $this->incpath = call_user_func(function($path) {
            $path = array_filter(explode('/', $path));
            array_pop($path);
            return implode('/', $path);
        }, $this->inherited_path);

        # call this in a closure so that 
        # the inherited page does not have any previously declared vars
        call_user_func(function($p, $__data__) {
            foreach ($__data__ as $k => $v) $$k = $v;
            unset($k, $v, $__data__);
            include $p->inherited_path;
        }, $this, $data);

    }

    /*
        for a given path, sets the page_js, and page_css
        if they are set before hand, moves them to the css and js arrays
    */
    function setAssetsByPath($path) {
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
            if (file_exists_incpath($file)) {
                $this->{$page_asset} = '/' . $file;
            }
        }
    }

    /*
        use SkyRouter object
        to set incpath/urlpath/page_path/queryfolders/querystring/ide
    */
    function setPropertiesByRouter(SkyRouter $router) {

        $router->checkPagePath();
        
        if ($router->is_default) {
            $this->incpath = substr($router->default_page, 0, strrpos($router->default_page, '/'));
        }

        $lastkey = array_pop(array_keys($router->page_path));
        $sliced = array_slice($router->qs, 0, $lastkey);
        $this->urlpath = '/' . implode('/', $sliced);
        $this->incpath = ($this->incpath) ?: $router->prefix . $this->urlpath;
        $this->page_path = end($router->page_path);
        $this->queryfolders = array_slice($router->qs, $lastkey);
        $this->querystring = $_SERVER['QUERY_STRING'];
        $this->ide = $this->queryfolders[count($this->queryfolders) - 1];

    }

}//class page