<?php
// index.php
// Powered by SkyPHP (skyphp.org)
// Note: Each path must have a trailing slash

$codebase_path = '/path/to/codebases/';

include $codebase_path . 'skyphp/sky.php';

sky([

	#'down_for_maintenance' => true,

	'codebases' => [
    	$codebase_path . 'mycodebase/',
    	$codebase_path . 'skyphp/'
	],

	// make sure this folder is writable
	'storage_path' => '/path/to/storage/'

]);




##### config.php #####

$this->includes[] = '';

hook::add('env', 'hooks/canonicalization.php');
hook::add('env', 'hooks/file_serve.php');
hook::add('env', 'hooks/ini_set.php');

hook('connect', 'hooks/mem.php');

$sky->hook('connect', 'hooks/db.php');

hook::add('connect', function(){
	mail();
});

$sky->hook('connect', 'hooks/media.php');





##### sky.php #####

function sky($a) {

	$sky = new sky($a);

}

class sky {

	public function init($config_array) {

		// set include path based on codebases array

		// include minimal functions needed for quickserving
		// quick serve

		// if no quickserve...

		// instantiate (& initialize) the page $p
		$router = new pageRouter(array(
			'sky' => $this,
			// the uri of the page being requested
			'uri' => $_SERVER['REQUEST_URI'],
			// ignore this prefix of the uri when determining the page to serve
			'rooturi' => $a['rooturi'] ?: '/'
		));
		// scan filesystem and *identify files and paths* needed by this URI.
		$pageSettingsArray = $router->getPageSettings();
		/*
			
		*/
		$p = new page( $pageSettingsArray );	// returns array of settings required to instantiate a new page

		$p->run();

	}

}\

class pageRouter {
	use Configurable;

	public $sky,
			$uri;

	public function __construct($config) {
		//$this->sky = $config['sky'];
		$this->configure($config);
	}

}

class page {


	public function init() {

		include('hooks/autoload.php');
		
		hook::run('config');

		hook::run('env');
		
		$this->hook('connect');
		
		$this->hook('hooks/session_start.php');
		
		$router = new pageRouter(array(
		    'codebases' => $codebase_path_arr,
		    'db' => $db,
		    'page_path_404' => $page_404,
		    'page_path_default' => $default_page
		));


		$router->checkPath($sky_qs, 'pages');

		$this->setProperties( pageRouter $router);

			#$sky->hook('pages/run-first.php');
			#$sky->hook('hooks/authenticate.php');
			#$sky->hook('hooks/redirect.php'); // remember uri
			#$sky->hook('pages/run-last.php');

		$sky->hook('hooks/close.php');
	}

}
