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
    /* // pseudo code:
        if ( $this->cache_is_buffering[$doc_name] ) {
            stop output buffering
            if ( !$this->cache_already_output[$doc_name] ) echo buffer
            save document to cache for $duration
            unset($this->cache_is_buffering[$doc_name]);
            unset($this->cache_already_output[$doc_name]);
            return false;
        } else {
            if document does not exist || isset($_GET['refresh']) {
                start output buffering
                $this->cache_is_buffering[$doc_name] = true;
                return true
            } else if document has not expired {
                echo cache value
                return false
            } else {
                echo cache value
                start output buffering
                cache_is_buffering[doc_name] = true
                cache_already_output[doc_name] = true
                return true
            }
        }
    */
    }

    function template($template_name, $template_area) {
        if ( !$this->templates[$template_name] ) $this->templates[$template_name] = true;
        include( 'templates/' . $template_name . '/' . $template_name . '.php' );
    }

    function javascript() {
        // css manual includes
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
        global $page_js_file;
        // $page_css_file = $this->page_path.'/'.$this->slug.'.css';
        if ( file_exists_incpath($page_js_file) ) {
?>
    <script src="<?=$page_js_file?>"></script>
<?
        }
    }

    function stylesheet() {
        // template auto includes
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
        // page auto include
        global $page_css_file;
        // $page_css_file = $this->page_path.'/'.$this->slug.'.css';
        if ( file_exists_incpath($page_css_file) ) {
?>
    <link rel="stylesheet" href="<?=$page_css_file?>" />
<?
        }
        // css manual includes
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
?>