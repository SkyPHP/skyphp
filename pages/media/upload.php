<?php
	/**
	*	USING UPLOADER
	*	URL/Form Action : /media/upload
	*   DATA : $_FILES Array and $_POST Array
	*		- $_FILES (Can upload Multiple Files, but can cause issues if filename is specified)
	*		- $_POST = [
	*			'folder' => 'full folder path that is needed', // REQUIRED
	*			'redirectURL' => 'Current page that you're on (usually $this->uri)', // OPTIONAL
	*			'filename' => 'desired file name', // OPTIONAL
	*			'override' => '0 or 1', // OPTIONAL
	*			'is_ajax_request' => '0 or 1' // OPTIONAL (Default to 0 [redirect with url variable containing json string] )
	*		]
	*   RETURN : JSON Encoded String via POST Method
	*/
	use \Sky\skyMedia;

	if($_POST && count($_POST) > 0 && $_FILES){

		$data = [
			'folder' => $_POST['folder'],
			'files' => $_FILES,
			'filename' => $_POST['filename'],
			'override' => $_POST['override']
		];

		$upload = skyMedia::fileUpload($data); // Already encoded to json
		
		if ($_POST['is_ajax_request']) {
		    exit_json($response);
		} else {
			$url = $_POST['redirectURL'] ? $_POST['redirectURL'] : $_SERVER['HTTP_REFERER'];

			$qs = (strpos($_SERVER['HTTP_REFERER'], "?") || strpos($_POST['redirectURL'], "?")) !== FALSE ? '&return='.rawurlencode($upload) : '?return='.rawurlencode($upload);

			redirect($url . $qs);
		}
	} 
?>
