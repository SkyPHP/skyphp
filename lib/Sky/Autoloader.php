<?

/*

This autoloader loads classes that have namespaces.  All namespaces
map to the lib/ folder.

*/

namespace Sky;

class Autoloader {

	public static function namespaceLoader($className) {
		$filename = "lib/" . str_replace('\\', '/', $className) . ".php";
		if (file_exists_incpath($filename)) {
			include $filename;
			if (class_exists($className)) return true;
		}
		return false;
	}

    public static function globalLoader($className) {
		$file_path = 'lib/class/class.'.$className.'.php';
		if (file_exists_incpath($file_path)) {
			include $file_path;
			if (class_exists($className)) return true;
		}
		return false;
	}

	public static function globalAqlModelLoader($model_name) {
		global $sky_aql_model_path;
		$path = $sky_aql_model_path.'/'.$model_name.'/class.'.$model_name.'.php';
		if (file_exists_incpath($path)) {
			include $path;
			if (class_exists($model_name)) return true;
		}
		return false;
	}

}