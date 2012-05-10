<?

namespace Sky;

/*

	$o = new SkyRouter(array(
		'codebase_paths' => $codebase_paths,
		'db' => $db,
		'default_page' => 'pages/default/default.php',
		'page_404' => 'pages/404.php'
	));
	

*/

class PageRouter {
	
	// following properties are not reset with SkyRouter::cleanSettings()
	public $codebase_paths = array();
	public $db = null;
	public $page_path_default = 'pages/default/default.php';
	public $page_path_404 = 'pages/404.php';

	// following properties are reest with SkyRouter::cleanSettings()
	public $is_default = false;
	public $page = array();
	public $page_path = array();
	public $prefix = null;
	public $qs = array();
	public $scripts = array();
	public $settings = array();
	public $vars = array();
	
	public function __construct($o = array()) {
		if (!$o) throw new \Exception('Constructor arguments required.');
		if (!\is_assoc($o)) throw new \Exception('Contsructor argument needs to be associative.');

		$o = (object) $o;
		$this->codebase_paths = $o->codebase_paths;
		$this->db = $o->db;
		if ($o->page_path_default) $this->page_path_default = $o->page_path_default;
		if ($o->page_path_404) $this->page_path_404 = $o->page_path_404;
		$this->uri = $o->uri;
	}

	public function routeURI() {
		return $this->routePath($this->uri);
	}

	/*
		@param (string)
		shortcut to checkPath using $path as a string
	*/
	public function routePath($path) {
		return $this->checkPath(explode('/', $path), 'pages');
	}

	/*
		resets found settings to a clean state
		keeps codebases/db/default_page/page_404
	*/
	public function cleanSettings() {
		$this->default = false;
		$this->prefix = null;
		$this->settings = $this->scripts 
						= $this->page 
						= $this->page_path 
						= $this->vars
						= $this->qs
						= array();
	}

	/*
		@param (array) $qs an exploded array should look like '/piece1/piece2'
		@param (bool) $prefix usually 'pages'

		sets SkyRouter properties (paths/settings/vars) to what was found 
		traversing each piece of $qs 
	*/
	public function checkPath($qs, $prefix = null) {
		
		# reset the found settings for this object
		$this->cleanSettings();

		$qs = array_filter($qs);
		$this->qs = $qs;
		$this->prefix = $prefix;

		for ($i = $i + 1; $i <= count($qs); $i++) {
			$path_arr = array_slice($qs, 0, $i);
			$slug = $path_arr[$i - 1];
			$path = implode('/', $path_arr);
			if ($path && $prefix) $path = '/' . $path;

			$settings_file = $this->ft($prefix, $path, $slug, 'settings');
			$script_file = $this->ft($prefix, $path, $slug, 'script');

			$this->includePreSettings();
			$this->includeToSettings($settings_file);
			$this->appendScript($script_file);

			$check = array(
				sprintf('%s%s.php',$prefix, $path) => true,
				sprintf('%s%s/%s.php', $prefix, $path, $slug) => true,
				$this->ft($prefix, $path, $slug, 'profile') => 'profile',
				$this->ft($prefix, $path, $slug, 'listing') => true
			);

			foreach ($this->codebase_paths as $codebase_path) {

				$file = '';
				$get_tmp = function($f) use($codebase_path) {
					return $codebase_path . $f;
				};

				foreach ($check as $file => $c) {
					$tmp = $get_tmp($file);
					if (!is_file($tmp)) continue;
					if ($c === 'profile') {
						if ($this->checkProfile($qs[$i + 1], $i, $file, $tmp)) 
							break 2;
					} else {
						$this->addToPageAndPath($file, $tmp, $i);
						break 2;
					}
				}

				$file = $prefix . $path;
				$tmp = $get_tmp($file);
				if ($path && is_dir($tmp)) $this->page[$i] = 'directory';

			}

			if ($this->page[$i]) {
				$this->includePostSettings();
				continue;
			}

			if (!$this->db) continue;

			$path_arr = array_slice($qs, 0, $i - 1);
			$path = implode('/', $path_arr);
			if ($path && $prefix) $path = '/' . $path;

			$matches = array();
			foreach ($this->codebase_paths as $codebase_path) {
				$scandir = $codebase_path . $prefix . $path;
                if (!is_dir($scandir)) continue;
                foreach (scandir($scandir) as $filename) {
                    if (substr($filename, 0, 1) != '_' || strlen($filename) <= 6) continue;
                    if (substr($filename, -1) != '_') continue;
                    if (!is_dir($scandir . '/' . $filename)) continue;
                    $matches[substr($filename, 1, -1)] = $codebase_path;
                }
			}

			if (!$matches) continue;

			foreach ($matches as $field => $codebase_path) {
				$folder = '_' . $field .'_';
                $table = substr($field, 0, strpos($field, '.'));
                $settings_file = $this->dft($prefix, $path, $folder, 'settings');
                $script_file = $this->dft($prefix, $path, $folder, 'script');

                $this->includePreSettings();
                $this->includeToSettings($settings_file);

                $lookup_id = null;
                if (!$this->settings['database_folder']['numeric_slug'] || is_numeric($slug)) {
                	$sql = "SELECT id FROM {$table} WHERE active = 1 and {$field} = '{$slug}'";
                	if ($this->settings['database_folder']['where']) {
                		$sql .= ' and ' . $this->settings['database_folder']['where'];
                	}
                	\elapsed($sql);
                	$r = sql($sql);
                	if (!$r->EOF) $lookup_id = $r->Fields('id');
                	$r = null;
                }

                // clear database folder settings
                $this->settings['database_folder'] = null;
                
                if ($lookup_id === null) {
                	continue;
                }

                $this->includePostSettings();
                $qs[$i] = $folder;
                $lookup_field_id = $table . '_id';
                $$lookup_field_id = $lookup_id;
                $this->vars[$lookup_field_id] = $lookup_id;
                $lookup_slug = str_replace('.', '_', $field);
                $$lookup_slug = $slug;
                $this->vars[$lookup_slug] = $slug;
                $this->appendScript($script_file);

                $get_tmp = function($f) use($codebase_path) {
                	return $codebase_path . $f;
                };

                $check = array(
                	sprintf('%s%s/%s/%s.php', $prefix, $path, $folder, $folder) => true,
                	$this->dft($prefix, $path, $folder, 'profile') => 'profile',
                	$this->dft($prefix, $path, $folder, 'listing') => true
                );

                foreach ($check as $file => $c) {
					$tmp = $get_tmp($file);
					if (!is_file($tmp)) continue;
					if ($c === 'profile') {
						if ($this->checkProfile($qs[$i + 1], $i, $file, $tmp)) 
							break 2;
					} else {
						$this->addToPageAndPath($file, $tmp, $i);
						break 2;
					}
				}

				$file = sprintf('%s%s/%s', $prefix, $path, $folder);
				$tmp = $get_tmp();
				if (is_dir($tmp)) $this->page[$i] = 'directory';
			}
			if ($this->page[$i]) continue;
		}
		
	}

	/*
		formatting for file path (non db folder)
	*/
	public function ft($prefix, $a, $b, $type) {
		return sprintf('%s%s/%s-%s.php', $prefix, $a, $b, $type);
	}

	/*
		formatting for file path (db folder)
	*/
	public function dft($prefix, $a, $b, $type) {
		return sprintf('%s%s/%s/%s-%s.php', $prefix, $a, $b, $b, $type);
	}

	/*
		add script to scripts array if file exists
	*/
	private function appendScript($f) {
		if (!\file_exists_incpath($f)) return;
		$this->scripts[$f] = true;
	}

	private function includePreSettings() {
		$this->includeToSettings('lib/core/hooks/settings/pre-settings.php');
	}

	private function includePostSettings() {
		$this->includeToSettings('lib/core/hooks/settings/post-settings.php');
	}

	/*
		if the file exists, merges declared variables to $this->settings
		using $__file__ because it is unlikely to appear in the settings file
	*/
	private function includeToSettings($__file__) {
		if (!\file_exists_incpath($__file__)) return;
		include $__file__;
		$vars = get_defined_vars();
		unset($vars['__file__']);
		$this->settings = array_merge($this->settings, $vars);
	}

	/*
		@return bool if true this was added to the page/path arrays
		if the -profile page exists, 
		this method checks to see if there is a $primary_table 
		and if this is an IDE/add-new for this $primary_table
	*/
	private function checkProfile($piece, $i, $file, $path) {
		
		// find primary_table via model if it is specified
		if ($this->settings['model']) {
			$aql = aql::get_aql($this->settings['model']);
			$this->settings['primary_table'] = aql::get_primary_table($aql);
		}

		// throw error if no primary_table
		if (!$this->settings['primary_table']) {
			header("HTTP/1.1 503 Service Temporarily Unavailable");
	        header("Status: 503 Service Temporarily Unavailable");
	        header("Retry-After: 1");
	        throw new \Exception('Profile Page Error: $primary_table not specified on file. <br />' . $file);
	        return false;
		}

		// set to profile
		$decrypted = \decrypt($piece, $this->settings['primary_table']);
		if ($piece == 'add-new' || is_numeric($decrypted)) {
			$this->addToPageAndPath($file, $path, $i);
			return true;
		}

		return false;

	}


	/*
		returns an array of properties used to configure a new Page object
	*/
	public function getPageProperties() {
		
		$this->routeURI();
		$this->checkPagePath();

		$incpath = ($this->is_default) 
			? substr($this->default_page, 0, strrpos($this->default_page, '/'))
			: null;
		
		$lastkey = array_pop(array_keys($this->page_path));
		$sliced = array_slice($this->qs, 0, $lastkey);
		$imploded = implode('/', $sliced);
		$qf = array_slice($this->qs, $lastkey);

		return array(
			'urlpath' => '/' . $imploded,
			'incpath' => ($incpath) ?: $this->prefix . '/' . $imploded,
			'page_path' => end($this->page_path),
			'queryfolders' => $qf,
			'querystring' => $_SERVER['QUERY_STRING'],
			'ide' => $qf[count($qf) - 1],
			'script_files' => $this->scripts,
			'vars' => array_merge($this->vars, $this->settings)
		);

	}

	/*
		adds $file to page
		 and $path to page_path
		at the specified key
		this is used if these files are found
	*/
	private function addToPageAndPath($path, $file, $key) {
		$this->page[$key] = $file;
		$this->page_path[$key] = $path;
	}

	/*
		if no page path, sets to 404 or default depending on if qs array was set
	*/
	public function checkPagePath() {
		if ($this->page_path) return false;
		$add = (!$this->qs[1]) ? $this->page_path_default : $this->page_path_404;
		$this->addToPageAndPath($add, $add, 1);
		return $this->is_default = (bool) (!$this->qs[1]);
	}

}