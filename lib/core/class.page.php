<?
class page {

    public $uri;
    public $page_path;
    public $slug;
    public $title;
    public $seo = array();
    public $breadcrumb = array();
    public $var = array();
    public $css = array();
    public $js = array();
    public $head = array();
    public $templates = array();
    protected $cache_is_buffering = array();
    protected $cache_already_output = array();

    public function __construct($template=null) {
        $this->uri = $_SERVER['REQUEST_URI'];
		if ($seo_enabled) {
			$rs=aql::select("website { where domain = '{$_SERVER['SERVER_NAME']}'");
			$this->seo($page_path,$rs[0]['website_id']);
		}
        // database folder detection
        // canonicalization
        // remember uri /
        // authentication, remember me
    }

    function cache($doc_name, $duration) {
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
            if ( !$document || isset($_GET['refresh']) ) {
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

    function template($template_name, $template_area) {
        if ( !$this->templates[$template_name] ) $this->templates[$template_name] = true;
        $p = $this;
         if ( !$_POST['_ajax'] || $_POST['skybox'] ) include( 'templates/' . $template_name . '/' . $template_name . '.php' );
    }

    function javascript() {
        // js manual includes
        if (is_array($this->js))
        foreach ( $this->js as $file ) {
            if ( file_exists_incpath($file) || strpos($file,'http')===0 ) {
?>
    <script src="<?=$file?>"></script>
<?
            }
        }
        // template auto includes
        foreach ( $this->templates as $name => $null ) {
            $template_js_file = "/templates/{$name}/{$name}.js";
            if ( file_exists_incpath($template_js_file) ) {
?>
    <script src="<?=$template_js_file?>"></script>
<?
            }
        }
        // page auto include
        if ( $this->page_js ) {
?>
    <script src="<?=$this->page_js?>"></script>
<?
        }
        // scripts
        if (is_array($this->script))
        foreach ( $this->script as $script ) {
?>
    <script>
    <?=$script?>

    </script>
<?
        }
    }

    function stylesheet() {
        // template auto includes
        if (is_array($this->templates)) {
            $this->templates = array_reverse( $this->templates );
            foreach ( $this->templates as $name => $null ) {
                $template_css_file = "/templates/{$name}/{$name}.css";
                if ( file_exists_incpath($template_css_file) ) {
?>
    <link rel="stylesheet" href="<?=$template_css_file?>" />
<?
                }
            }
            $this->templates = array_reverse( $this->templates );
        }
        // page auto include
        if ( $this->page_css ) {
?>
    <link rel="stylesheet" href="<?=$this->page_css?>" />
<?
        }
        // css manual includes
        if (is_array($this->css))
        foreach ( $this->css as $file ) {
            if ( file_exists_incpath($file) ) {
?>
    <link rel="stylesheet" href="<?=$file?>" />
<?
            }
        }
    }

    function consolidated_javascript() {
        // get p->js[] and auto page js
        $js = null;
        if (is_array($this->js))
            foreach ( $this->js as $js_file )
                $js[ ( strpos($js_file,'http:')===0 || strpos($js_file,'https:')===0 )?'remote':'local' ][$js_file] = true;
        if ( $this->page_js ) $js[$this->page_js] = true;
        $page_js = page::cache_js($js['local']);

        // get p->template_js[] and auto template js
        $js['local'] = null;
        if (is_array($this->template_js))
            foreach ( $this->template_js as $js_file )
                $js[ ( strpos($js_file,'http:')===0 || strpos($js_file,'https:')===0 )?'remote':'local' ][$js_file] = true;

        if ( is_array($this->templates) )
            foreach ( $this->templates as $name => $null )
                $js['local'][ "/templates/{$name}/{$name}.js" ] = true;

        $template_js = page::cache_js($js['local']);
        
        // output consolidated js files
        if (is_array($js['remote']))
        foreach ( $js['remote'] as $js_file => $null ) {
            ?><script src="<?=$js_file?>"></script><?
            echo "\n";
        }
        if ($page_js) {
            ?><script src="<?=$page_js?>"></script><?
            echo "\n";
        }
        if ($template_js) {
            ?><script src="<?=$template_js?>"></script><?
            echo "\n";
        }
        if (is_array($this->script))
        foreach ( $this->script as $script ) {
            ?><script><?=$script?></script><?
            echo "\n";
        }
    }

    function cache_js( $js ) {
        if (is_array($js)) {
            foreach ( $js as $js_file => $null ) {
                $filename = array_pop(explode('/',$js_file)); // strip path
                $filename = str_replace('.js','',$filename);
                if ($cache_name) $cache_name .= '-';
                $cache_name .= $filename;
            }
            if ( count($js) ) {
                $js_cache_name = '/javascript/' . $cache_name . '.js';
                // check if we have a cache value otherwise read the files and save to cache
                if ( !disk($js_cache_name) || $_GET['refresh'] ) {
                    ob_start();
                    foreach ( $js as $js_file => $null ) {
                        $js_file = substr($js_file,1);
                        @include($js_file);
                        echo "\n\n\n\n\n";
                    }
                    $file_contents = ob_get_contents();
                    ob_end_clean();
                    if ( $file_contents ) disk($js_cache_name,$file_contents);
                }
            }
        }
        return $js_cache_name;
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
	
	function seo ($page_path,$website_id) {
		$rs = aql::select("website_page_data { field, value } website_page { where website_id = {$website_id} and $page_path='{$page_path}'}");
		if (is_array($rs)) {
			foreach ($rs as $r) $seo[$r['field']]=$r['value'];
		}
	}

}//class page