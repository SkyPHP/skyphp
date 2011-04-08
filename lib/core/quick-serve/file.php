<?

debug($uri_file_path . '<br />');
//@include_once( $sky_install_path . 'config.php' );
//@include_once( $sky_install_path . 'lib/functions/common.inc.php' );

$sky_no_trailing_slash = substr($sky_install_path,0,-1);

$file_path = array();
$file_path[] = $_SERVER['DOCUMENT_ROOT'] . $_SERVER['REQUEST_URI'];

//if ( is_array($codebase_path_arr) )
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
	if ( is_file( $file ) ):
		$file_extension = substr($file, strrpos($file, '.') + 1);

        $needle = 'pages/';
		if ( strpos($uri_file_path,$needle) !== false && strpos($file_extension,'php') !== false ):
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
?>
			Dude, try this: <a href="/<?=$redirect_path?>"><? echo $protocol . $_SERVER['HTTP_HOST'] . '/' . $redirect_path; ?></a>
			<br /><br />
			(Click here for an <a href="http://www.skyphp.org/doc/page" target="_blank">explanation</a>)
<?
			die();
		endif;
		
		if ($sky_content_type[$file_extension]) {
            //if ( $file_extension == 'css' ) $t = strtotime("+1 day");
            //else $t = strtotime("+35 days");
            //$expires = strftime ("%a, %d %b %Y %T GMT", $t);
            header("Expires: " . gmdate("D, d M Y H:i:s",strtotime('+6 months')) . " GMT");
            $ft = filemtime ($file);
            header( 'Last-Modified: ' . gmdate("D, d M Y H:i:s", $ft) . " GMT" );
            header( 'Content-type: ' . $sky_content_type[$file_extension] );
            header( 'Content-Length: ' . filesize($file) );
			readfile( $file );
        } else {
            echo 'You may not download this type of file. (SkyPHP)';
        }//if
		die();

	endif;
endforeach;

die("this happens when a file is in the include path but not in a codebase path.");

?>