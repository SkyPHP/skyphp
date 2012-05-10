<?

namespace Sky;

class Page {

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
	public $script_files = array();
	public $template_css = array();
	public $template_js = array();
	public $html_attrs = array();
	public $head = array();
	public $templates = array();
	public $is_ajax_request = false;
	protected $cache_is_buffering = array();
	protected $cache_already_output = array();
	protected $css_added = array();


	public function __construct($config = array()) {
		foreach ($config as $k => $v) $this->{$k} = $v;
		$this->uri = $_SERVER['REQUEST_URI'];
		$this->is_ajax_request = is_ajax_request(); // in functions.inc
	}

	public function setConstants() {
		define('URLPATH', $this->urlpath);
		define('INCPATH', $this->incpath);
		define('IDE', $this->ide);
		define( 'XIDE', substr( $_SERVER['HTTP_REFERER'], strrpos($_SERVER['HTTP_REFERER'],'/') + 1 ) );
	}

	/*
		any time $_POST is not set
		check to see if the input stream is valid JSON
		and populate $_POST array
	*/
	private function checkInputStream() {
		
		if (!$this->is_ajax_request) return;
		if ($_POST) return;
		if ($_SERVER['CONTENT_TYPE'] != 'application/json') return;
	
		$stream = file_get_contents('php://input');
		if (!$stream) return;

		$decoded = json_decode($stream, true);
		if (!$decoded) return;

		$_POST = $decoded;

	}

	/*
		includes $this->page_path
		after running hooks:
			- uri
			- run-first
		including script files
		setting constants
		then run-last
	
		includes are done using Page::includePath() 
		to emulate them being executed in the same scope.

	*/
	public function run() {

		# uri hook
		$vars = $this->includePath('lib/core/hooks/uri/uri.php', $this->vars);

		# set constants
		$this->setConstants();

		# map input stream to $_POST if applicable
		$this->checkInputStream();

		# execute run first
		$vars = $this->includePath('pages/run-first.php', $vars);
	
		# execute script files
		foreach (array_keys($this->script_files) as $file) {
			$vars = $this->includePath($file, $vars);
		}

		# add page_css/page_js
		$this->setAssetsByPath($this->page_path);

		# see if we're not rendering html but returning JS
		$get_contents = (bool) ($_POST['_json'] || $_GET['_script']);
		if ($get_contents) {
			if ($_GET['_script']) $this->no_template = true;
			ob_start();
		}

		# run-first / settings / script files need to be executed in the same scope
		$vars = $this->includePath($this->page_path, $vars);

		if ($get_contents) {
			# refreshing a secondary div after an ajax state change
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
					"\$(function() { render_page(%s,'%s','%s', '%s'); });", 
					json_encode($this),
					$this->uri,
					$_SERVER['HTTP_HOST'],
					$_GET['_script_div']?:'page'
				);
			}
		}

		# run-last hook
		$this->includePath('pages/run-last.php', $vars);	

		return $this;

	}


	/*
		@return associative array of variables that were defined in this scope.
		@param 	string path
		@param  associative array of variables to push to this scope
	
		includes the file in a scope with variables carried through.

	*/
	public function includePath($__p = null, $__d = array()) {
		
		if (!$__p) throw new \Exception('path not specified.');
		if (!\file_exists_incpath($__p)) return $__d;

		# push data array into the file's scope
		foreach ($__d as $__k => $__v) $$__k = $__v;
		unset($__d, $__k, $__v);

		# for backwards compatibility
		$p = $this;
		include $__p;

		# removing $__p, otherwise it will be in defined_vars()
		unset($__p);
		return get_defined_vars();
	}

	public function form(Model $o) {
	
		$css = $o->getFormPath('css');
		$js = $o->getFormPath('js');

		if (\file_exists_incpath($css)) $this->css[] = '/' . $css;
		if (\file_exists_incpath($js)) $this->js[] = '/' . $js;

		$o->includeForm();

		return $this;

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
	public function cache($doc_name, $duration='30 days') {
		$doc_name = preg_replace('/[^a-zA-Z0-9\-\_]/i', '-', $doc_name);
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
			\disk( $key, $document, $duration );
			unset($this->cache_is_buffering[$doc_name]);
			unset($this->cache_already_output[$doc_name]);
			return false;
		} else {
			$document = \disk( $key );
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

	/*
		returns an array of auto includes for the templates of this page
	*/
	public function get_template_auto_includes($type = null) {
		
		# $type must be an array
		if (!$type) $type = array('css', 'js');
		else if (!is_array($type)) $type = array($type);

		# initialize an array for each type (so foreaches work)
		$r = array();
		foreach ($type as $t) $r[$t] = array(); 

		# traverse the tempaltes from bottom up and fine their auto included files
		foreach (array_keys(array_reverse($this->templates)) as $name) {
			$file = vsprintf('/templates/%s/%s', array_fill(0, 2, $name));
			foreach ($type as $t) {
				$r[$t] = self::get_template_auto_includes_type($file, $t, $r[$t]);
			}
		}

		# return a nested array if multiple types, otherwise just the first piece
		return (count($type === 1)) ? reset($r) : $r;

	}

	/*
		@return array
		@param string file
		@param string file type
		@param array
		appends to the $arr the file if it exists and returns the array
	*/
	private function get_template_auto_includes_type($file, $type, $arr = array()) {
		$path = sprintf('%s.%s', $file, $type);
		if (\file_exists_incpath($path)) $arr[] = $path;
		return $arr;
	}
	
	/*
		@return $this;
		@param array of key value pairs, keys must coincide to internal $map
	
		maps config array to $map. appends or sets the value on the object as specified.

	*/
	public function setConfig($config = array()) {

		if (!$config) return $this;
		if ($config && !\is_assoc($config)) {
			throw new \Exception('Attempting to set page class variables with page::setConfig(), argument must be an associative array.');
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

		return $this;

	}
	
	/*
		@return $this
		@param string $template_name
		@param string $template_area
		@param Array $config variables
		this outputs the template area contents
	*/
	function template($template_name, $template_area, $config = array()) {

		global $dev, $template_alias;    

		# set page vars based on $config and properties
		$this->setConfig($config);

		# replace by alias if it is set.
		$template_name = ($template_alias[$template_name]) ?: $template_name;

		# add to templates array
		if ( !$this->templates[$template_name] ) $this->templates[$template_name] = true;
		if ( $_POST['_no_template'] || $this->no_template) return $this;

		# check for hometop
		if ($this->page_path == 'pages/default/default.php' && $template_area == 'top') {
			$hometop = $this->get_template_contents($template_name, 'hometop');
			if ($hometop) {
				echo $hometop;
				return $this;
			}
		}

		# else return $template_area
		echo $this->get_template_contents($template_name, $template_area);
		return $this;
	}

	# gets template contents based on template/and area
	private function get_template_contents($template_name, $template_area) {
		$p = $this;
		ob_start();
		include vsprintf('templates/%s/%s.php', array_fill(0, 2, $template_name));
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
	
	/*
		returns the array of unique types (css and/or js)
		depending on what is specified
	*/
	public function unique_include($types = array('css', 'js')) {
		
		$types = (is_array($types)) ? $types : array($types);
		$flip = array_flip($types);
		$p = $this;

		$clean_input = function($arrs, $all = array()) {

			# cleans the arrays so that all values are unique and in the proper orders 	
			$arrs = array_map(function($arr) use (&$all) {
				if (!is_array($arr)) $arr = array($arr);
				return array_filter(array_map(function($val) use(&$all) {
					if (!$val || in_array($val, $all)) return null;
					$all[] = $val;
					return $val;
				}, array_unique($arr)));
			}, $arrs);

			# return an array of everyhting, and one partitioned
			return array(
				'all' => $all, 
				'arrs' => $arrs
			);
		};

		# clean types
		$types = array_map(function($type) use($p, $clean_input) {
			return $clean_input(array(
				'template' => $p->{'template_'.$type},
				'template_auto' => $p->get_template_auto_includes($type),
				'inc' => $p->{$type},
				'page' => array($p->{'page_'.$type})
			));
		}, $types);

		# set types as keys
		$flip = array_map(function($f) use($types) { 
			return $types[$f]; 
		}, $flip);

		# return array or nested array depending on how many types were given
		return (count($flip) === 1)
			? reset($flip)
			: $flip;

	}

	public function javascript() {
		$js = $this->unique_js();
		foreach ($js['all'] as $file) {
			if (!\file_exists_incpath($file) && strpos($file, 'http') !==0 ) continue;
			$this->output_js($file);  
		}
		// scripts
		if (is_array($this->script))
		foreach ( $this->script as $script ) {
			?><script><?=$script?></script><?
		}
	}

	public function stylesheet() {
		$css = $this->unique_css();
		foreach ($css['all'] as $file) {
			if (!\file_exists_incpath($file)) continue;
			$this->css_added[] = $file;
			$this->output_css($file);
		}
	}

	public function output_css($file) {
		?><link rel="stylesheet" type="text/css" href="<?=$file?>" /><?
		echo "\n";
	}

	public function output_js($file) {
		?><script type="text/javascript" src="<?=$file?>"></script><?
		echo "\n";
	}

	public function do_consolidated($type) {
		
		if (!in_array($type, array('css', 'js'))) {
			throw new \Exception('Cannot consolidate non js or css');
		}

		# get unique files of this type
		$files = array('local' => array(), 'remote' => array());
		$uniques = $this->{'unique_'.$type}();
		
		# checks if file is local or remote	
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
				# adding to css_added because 
				# css can be output both in the header and footer
				if ($type == 'css') $this->css_added[] = $file;
				$add_to_files($file);
			}
		}
		
		# does this have a page specific include
		$path = $uniques['arrs']['page'][0];
		if ($path) $files['local'][$path] = true;

		# cache local files (page specific)
		$page_inc = self::cache_files($files['local'], $type);

		# clear array (do template specific includes)
		$files['local'] = array();
		if (is_array($uniques['arrs']['template'])) {
			foreach ($uniques['arrs']['template'] as $file) $add_to_files($file);
		}
		
		if (is_array($uniques['arrs']['template_auto'])) {
			foreach ($uniques['arrs']['template_auto'] as $file) $add_to_files($file);
		}

		# cahce template specific files
		$template_inc = self::cache_files($files['local'], $type);

		# output consolidated css files
		foreach(array_keys($files['remote']) as $file) $this->{'output_'.$type}($file);	
		if ($template_inc) $this->{'output_'.$type}($template_inc);
		if ($page_inc) $this->{'output_'.$type}($page_inc);	
		 
		return $uniques;

	}

	/*
		output consolidated javascript
		and a json encoded list of js_files included
	*/
	public function consolidated_javascript() {
		$r = $this->do_consolidated('js');
		$incs = json_encode($r['all']);
		$this->script[] = "var page_js_includes = {$incs};";
		if (is_array($this->script)) {
			foreach ( $this->script as $script ) {
				?><script type="text/javascript"><?=$script?></script><?
				echo "\n";
			}
		}
	}

	/*
		output consolidated css and individual style tags
	*/
	public function consolidated_stylesheet() {
		$r = $this->do_consolidated('css');
		if (!is_array($this->style) || !$this->style) return;
		foreach ($this->style as $s) {
			?><style type="text/css"><?=$s?></style><?
		}
	}

	/*
		@return null if no files or invalid type passed
				$cache_name (string) otherwise
		@param $files array with keys as paths to file
		@param $type string text/css

		caches a minified array of files to /$type_folder/$cache_name.$type
	*/
	public function cache_files($files, $type) {

		# set up so we can have other caching in the future
		# an array of acceptable types and their configurations
		$types = array(
			'js' => array(
				'folder' 	=> 'javascript',
				'class' 	=> 'JSMin',
				'method' 	=> 'minify'
			),
			'css' => array(
				'folder' 	=> 'stylesheet',
				'class' 	=> 'Minify_CSS_Compressor',
				'method' 	=> 'process'
			)
		);

		# if invalid params return null
		if (!array_key_exists($type, $types)) return null;
		if (!is_array($files) || !$files) return null;

		# set vars used when caching the files
		# conditionals only happen once (to not repeat logic)
		$current = (object) $types[$type];
		$type_folder = $current->folder;
		$callback = array($current->class, $current->method);
		include_once sprintf('lib/minify-2.1.3/%s.php', $current->class);

		# get cache name by imploding filenames
		$cache_name = implode('-', 
			array_map(function($file) use($type) {
				return str_replace('.' . $type, '', array_pop(explode('/', $file)));
			}, array_keys($files))
		);

		$cache_name = sprintf('/%s/%s.%s', $type_folder, $cache_name, $type);

		# return early if cache exists and we're not refreshing it
		if (!$_GET['refresh'] && disk($cache_name)) return $cache_name;

		# get files' contents
		ob_start();
		foreach (array_keys($files) as $file) {
			$file = substr($file, 1);
			@include $file;
			echo str_repeat("\n", 4);
		}
		$file_contents = ob_get_contents();
		ob_end_clean();

		if (!$file_contents) return $cache_name;

		# store file contents
		$file_contents = call_user_func($callback, $file_contents);
		\disk($cache_name, $file_contents);

		return $cache_name;

	}

	public function minify() {
		include_once 'lib/minify-2.1.3/Minify_HTML.php';
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

	public function redirect($href, $type = 302) {
		$href = trim($href);

		# dont redirect if redirecting to this page
		if ( $href == $_SERVER['REQUEST_URI'] ) return; 
		
		# set up
		$types = array( 301 => 'Permanently', 302 => 'Temporarily' );
		$header = 'HTTP/1.1 %d Moved %s';
		$location = 'Location: %s';

		# set message and type
		$type = ($type == 302) ? 302 : 301;
		$message = $types[$type];

		# if href doesn't have http(s):// set it up
		$protocol = ($this->protocol) ?: 'http';
		$href = (!preg_match('/^http(?:s){0,1}:\/\//', $href)) 
			? sprintf('%s://%s%s', $protocol, $_SERVER['SERVER_NAME'], $href)
			: $href;

		# set headers
		header("Debug: {$href} == {$_SERVER['REQUEST_URI']}");
		header(sprintf($header, $type, $message));
		header(sprintf($location, $href));
		die;

	}
	
	/*
		@return mixed null or string
		gets subdomain name
	*/
	public  function getSubdomainName() {
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
	public function inherit($path, $data = array()) {

		# add first slash if it isn't there so exploding is accurate.
		$path = (strpos($path, '/') !== 0)
			? '/' . $path
			: $path;

		global $codebase_path_arr, $db;
		$router = new \PageRouter(array(
			'codebase_paths' => $codebase_path_arr,
			'db' => $db
		));

		$qs = array_merge(explode('/', $path), $this->queryfolders);
		$router->checkPath($qs);

		$inherited_path = end($router->page_path);
		if (!$inherited_path) {
			throw new \Exception('Page::inherit could not find this path. ' . $path);
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
		$this->includePath($this->inherited_path, $data);

	}

	

	/*
		for a given path, sets the page_js, and page_css
		if they are set before hand, moves them to the css and js arrays
	*/
	public function setAssetsByPath($path) {
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

} // end class