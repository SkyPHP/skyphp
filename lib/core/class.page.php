<?
class page {

    public $uri;
    public $page_path;
    public $slug;
    public $title;
    public $meta = array();
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
        $this->page_path = '';
        // database folder detection
        // canonicalization
        // remember uri /
        // authentication, remember me
    }

    function cache($doc_name, $duration) {
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
            cache( $this->page_path, $document, $duration );
            unset($this->cache_is_buffering[$doc_name]);
            unset($this->cache_already_output[$doc_name]);
            return false;
        } else {
            $document = cache($this->page_path);
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

    function consolidated_js() {

        $cache = new cache($cookie_domain, '1 week', 'js');
        $cache->start();

        if ($cache->expired) {
            $m404 = '/<!DOCTYPE/';
            $contents = '';
            foreach ($consolidated_js as $file) {
                $tmp_content = @file_get_contents(($_SERVER['HTTPS']?'https':'http').'://'.$_SERVER['HTTP_HOST'].$file);
                if (!preg_match($m404, $tmp_content))
                    $contents .= "\n\n". $tmp_content;
            }
            echo $contents;

        }
        $cache->stop();
        foreach ( $js as $file ) {
            //$big_file .=
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

}//class page