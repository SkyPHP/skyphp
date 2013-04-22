<?php

debug($uri['path'] . '<br />');
$sky_no_trailing_slash = substr($sky_install_path,0,-1);

$file_path = array();
$file_path[] = $_SERVER['DOCUMENT_ROOT'] . $_SERVER['REQUEST_URI'];

foreach ( $codebase_path_arr as $codebase_path ):
    $codebase_path = substr($codebase_path,0,-1);
    $file_path[] = $codebase_path . $_SERVER['REQUEST_URI'];
endforeach;

$file_path[] = $sky_no_trailing_slash . $_SERVER['REQUEST_URI'];

if ($_GET['debug']) print_a($file_path);

foreach ( $file_path as $file ):
    $file = explode('?',$file);
    $file = $file[0];
    debug($file.'<br />');

    if ( !is_file( $file ) ) continue;

    $file_extension = substr($file, strrpos($file, '.') + 1);
    debug( "ext: $file_extension<br />" );
    $needle = 'pages/';
    if ( strpos($uri['path'],$needle) !== false && strpos($file_extension,'php') !== false ):
        $redirect_path = substr( $file, strpos($file,$needle) + strlen($needle) );
        $redirect_path = substr($redirect_path,0,strrpos($redirect_path,'.'));
        $path_arr = explode('/',$redirect_path);
        $count = count($path_arr);
        if ( $path_arr[$count-1] == $path_arr[$count-2] ) unset($path_arr[$count-1]);
        $redirect_path = implode('/',$path_arr);
        //redirect( $redirect_path );
        $protocol = 'http://';
        if($_SERVER['HTTPS'] == 'on') $protocol = 'https://';

        # TODO: put a 404 header here, so it doesn't get indexed
        $uri = $protocol . $_SERVER['HTTP_HOST'] . '/' . $redirect_path;
?>
        Dude, try this: <a href="/<?=$redirect_path?>"><?=$uri?></a>
        <br /><br />
        (Click here for an <a href="http://switchbreak.com/skyphp/classes/page" target="_blank">explanation</a>)
<?php
        die;
    endif;

    // get the mime type, and if unknown default to octet-stream
    $mime = getMimeType($file);
    if (!$mime) $mime = 'application/octet-stream';

    $ft = filemtime($file);
    date_default_timezone_set('America/New_York'); // PHP 5.3 Throws an error if this line is not here
    header("Expires: " . gmdate("D, d M Y H:i:s",strtotime('+6 months')) . " GMT");
    header('Last-Modified: ' . gmdate("D, d M Y H:i:s", $ft) . " GMT");
    header('Content-type: ' . $mime);
    header('Content-Length: ' . filesize($file));
    readfile($file);

    die;

endforeach;

die("this happens when a file is in the include path but not in a codebase path.");
