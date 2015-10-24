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
	*			'override' => '0 or 1' // OPTIONAL
	*		]
	*   RETURN : JSON Encoded String via POST Method
	*/
	use \Crave\skyMedia;

	if($_POST && count($_POST) > 0 && $_FILES){

		$data = [
			'folder' => $_POST['folder'],
			'files' => $_FILES,
			'filename' => $_POST['filename'],
			'override' => $_POST['override']
		];

		$upload = skyMedia::fileUpload($data); // Already encoded to json
	} 

	// Redirect after upload.  If url hasn't been specified, use http referer
/*	
	$qs = "?return" . rawurlencode(serialize($upload));

	redirect($url . $qs);*/
	$url = $_POST['redirectURL'] ? $_POST['redirectURL'] : $_SERVER['HTTP_REFERER'];

	// Use form to post back to original url since using an ajax request for file uploads is impossible.
	// Find out how to return data easier later
?>

<form action="<?= $url ?>" method="post" name="uploadResponse">
	<input type="hidden" name="responseData" value='<?= $upload ?>'>
</form>
<script>
	document.uploadResponse.submit();
</script>