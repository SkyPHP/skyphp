<?
	include_once( $sky_install_path . 'lib/functions/common.inc.php' );
	include_once( $sky_install_path . 'lib/adodb/adodb.inc.php' );
	@include_once( $sky_install_path . 'config.php' );
	
	$needle = '/media-zip/';
	$start = strlen($needle);
	$end = strpos($_SERVER['REQUEST_URI'],'/', $start);
	$length = $end - $start;
	if ($end) $media_instance_ide = substr( $_SERVER['REQUEST_URI'], $start, $length );
	else {
		$file = substr( $_SERVER['REQUEST_URI'], $start );
		$temp = explode('.',$file);
		$media_instance_ide = $temp[0];
	}//if
	if(is_numeric($media_instance_ide)){
		$media_zip_id = $media_instance_ide;
	}else{
		$media_zip_ide = $media_instance_ide;
		$media_zip_id = decrypt($media_zip_ide,'media_zip');
	}
	$zip_file_name = $media_zip_id.'.zip';
	$zip_file_path = '/tmp/media-zip/'.$zip_file_name;
	if(file_exists($zip_file_path)){
		header('Content-type: application/zip');
		header('Content-Disposition: attachment; filename="' . $media_instance_ide . '.zip"'); 
		header("Content-length: " . filesize($zip_file_path)); 
		readfile($zip_file_path);
		exit(0);
	}else{
		header("HTTP/1.0 404 Not Found");
		exit ('404 Error: Zip file Not Found.');
	}
	
?>