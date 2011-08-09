<?

function __autoload($n) {
    aql::include_class_by_name($n);
    if (class_exists($n)) return;
    @include('lib/class/class.'.$n.'.php');
}

?>
