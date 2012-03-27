<?

class SkyRouter {
	
	public $configs = array();
	public $codebase_paths = array();
	public $scripts = array();
	public $page = array();
	public $page_path = array();
	public $db = null;
	public $vars = array();

	public function __construct($codebases, $db) {
		$this->codebase_paths = $codebases;
		$this->db = $db;
	}

	public function routePath($path) {
		return $this->checkPath(explode('/', $path), 'pages');
	}

	public function checkPath($qs, $prefix = null) {
		$qs = array_filter($qs);
		for ($i = $i + 1; $i <= count($qs); $i++) {
			$path_arr = array_slice($qs, 0, $i);
			$slug = $path_arr[$i - 1];
			$path = implode('/', $path_arr);
			if ($path) $path = '/' . $path;

			$settings_file = $this->ft($prefix, $path, $slug, 'settings');
			$script_file = $this->ft($prefix, $path, $slug, 'script');

			$this->_includePreSettings();
			$this->_includeToConfig($settings_file);
			$this->_includeScript($script_file);

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
						if ($this->_checkProfile($qs[$i + 1], $i, $file, $tmp)) 
							break 2;
					} else {
						$this->_addToPageAndPath($file, $tmp, $i);
						break 2;
					}
				}

				$file = $prefix . $path;
				$tmp = $get_tmp($file);
				if ($path && is_dir($tmp)) $this->page[$i] = 'directory';

			}

			if ($this->page[$i]) {
				$this->_includePostSettings();
				continue;
			}

			if (!$this->db) continue;

			$path_arr = array_slice($qs, 0, $i - 1);
			$path = implode('/', $path_arr);
			if ($path) $path = '/' . $path;

			$maches = array();
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

                $this->_includePreSettings();
                $this->_includeToConfig($settings_file);

                $lookup_id = null;
                if (!$this->configs['database_folder']['numeric_slug'] || is_numeric($slug)) {
                	$sql = "SELECT id FROM {$table} WHERE active = 1 and {$field} = '{$slug}'";
                	if ($this->configs['database_folder']['where']) {
                		$sql .= ' and ' . $this->config['database_folder']['where'];
                	}
                	elapsed($sql);
                	$r = sql($sql);
                	if (!$r->EOF) $lookup_id = $r->Fields('id');
                	$r = null;
                }

                $this->configs['database_folder'] = null;
                
                if ($lookup_id === null) {
                	continue;
                }

                $this->_includePostSettings();
                $qs[$i] = $folder;
                $lookup_field_id = $table . '_id';
                $$lookup_field_id = $lookup_id;
                $this->vars[$lookup_field_id] = $lookup_id;
                $lookup_slug = str_replace('.', '_', $field);
                $$lookup_slug = $slug;
                $this->vars[$lookup_slug] = $slug;
                $this->_includeScript($script_file);

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
						if ($this->_checkProfile($qs[$i + 1], $i, $file, $tmp)) 
							break 2;
					} else {
						$this->_addToPageAndPath($file, $tmp, $i);
						break 2;
					}
				}

				$file = sprintf('%s%s/%s', $prefix, $path, $folder);
				$tmp = $get_tmp();
				if (is_dir($tmp)) $this->page[$i] = 'directory';
			}
			if ($this->page[$i]) continue;
		}
		$i--;
	}

	public function ft($prefix, $a, $b, $type) {
		return sprintf('%s%s/%s-%s.php', $prefix, $a, $b, $type);
	}

	public function dft($prefix, $a, $b, $type) {
		return sprintf('%s%s/%s/%s-%s.php', $prefix, $a, $b, $b, $type);
	}

	private function _checkPaths() { 

	}

	private function _includeScript($f) {
		if (!file_exists_incpath($f)) return;
		$this->scripts[$f] = true;
	}

	private function _includePreSettings() {
		$this->_includeToConfig('lib/core/hooks/pre-settings.php');
	}

	private function _includePostSettings() {
		$this->_includeToConfig('lib/core/hooks/settings/post-settings.php');
	}

	private function _includeToConfig($__file__) {
		if (!file_exists_incpath($__file__)) return;
		include $__file__;
		$vars = get_defined_vars();
		unset($vars['__file__']);
		$this->configs = array_merge($this->configs, $vars);
	}

	private function _checkProfile($piece, $i, $file, $path) {
		
		if ($this->configs['model']) {
			$this->configs['primary_table'] = aql::get_primary_table(aql::get_aql($this->configs['model']));
		}

		if ($this->configs['primary_table']) {
			if ($piece == 'add-new' || is_numeric(decrypt($piece, $this->configs['primary_table']))) {
				$this->_addToPageAndPath($file, $path, $i);
				return true;
			}
		} else {
			header("HTTP/1.1 503 Service Temporarily Unavailable");
	        header("Status: 503 Service Temporarily Unavailable");
	        header("Retry-After: 1");
	        die("Profile Page Error:<br /><b>$file</b> exists, but <b>\$primary_table</b> is not specified in <b>$settings_file</b></div>");
		}

	}

	private function _addToPageAndPath($file, $path, $key) {
		$this->page[$key] = $path;
		$this->page_path[$key] = $file;
	}

}