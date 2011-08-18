<?

function __autoload($n) {
    aql::include_class_by_name($n);
    if (class_exists($n)) return;
    $file_path = 'lib/class/class.'.$n.'.php';
    if (file_exists_incpath($file_path)) {
	   	include $file_path;
	}
}