<?

SkyPHP_Autoloader::Register();

class SkyPHP_Autoloader {
	
	public static function Register() {
		return spl_autoload_register(array('SkyPHP_Autoloader', 'Load'));
	}

	public static function Load($n) {
		aql::include_class_by_name($n);
	    if (class_exists($n)) return;
	    $file_path = 'lib/class/class.'.$n.'.php';
	    if (file_exists_incpath($file_path)) {
		   	include $file_path;
		}
	}

}

