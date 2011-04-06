<?

// legacy class for backwards compatibility
class template {

    function inc($the_template_file,$area,$param=NULL) {

            extract($GLOBALS);
            //eval('global $' . join(',$', array_keys($GLOBALS)) . ';');

            if ( $template_alias[$the_template_file] ) $the_template_file = $template_alias[$the_template_file];

            // pass a parameter if nested templates aren't inheriting variables properly
            if (is_array($param))
            foreach ($param as $var => $val) {
                    $$var = $val;
            }//if

            $_POST['template_auto_include'][$the_template_file] = true;

            $template_area = $area;
            include("templates/{$the_template_file}/{$the_template_file}.php");

    }

    function breadcrumb( $arr=NULL ) {
        global $title;
        $crumbs = my_array_unique(explode('/',URLPATH));
?>
        <div class="breadcrumb">
<?
        if ( $arr['home'] !== false ) {
?>
            <a href="/">Home</a> &rsaquo;
<?
        }//if

            $first_crumb = true;
			if (is_array($crumbs))
            foreach ( $crumbs as $crumb ) {
                $path .= '/' . $crumb;
                $breadcrumb = NULL;
                @include('pages'.$path.'/'.$crumb.'-settings.php');
                if (!$breadcrumb) continue;
                echo "\n\t\t\t";
                if ( !$first_crumb ) echo ' &rsaquo; ';
                ?><a href="<?=$path?>"><?=$breadcrumb?></a><?
                $first_crumb = false;
            }
    /*
?>
            &rsaquo; <span class="bold"><?=$title?></span>
<?
    */
?>
        </div>
<?
    }//breadcrumb

}//class
