<?php
	/**
	*	USING Delete
	*	URL/Form Action : /media/delete/table_name(ex. ctn_order)
	*   DATA : $_POST = [
	*			'folder' => 'full folder path that is needed', // REQUIRED
	*			'redirectURL' => 'Current page that you're on (usually $this->uri)', // OPTIONAL
	*			'filename' => 'desired file name', // REQUIRED (Use array of file name with the same name attribute as desired file name appended with '_cust')
	*			'key' => 'key in media_items json string', REQUIRED
	* 			'ide' => 'grewniuj' // REQUIRED if using save method
	*		]
	*   RETURN : JSON Encoded String 
	*/
	use \Sky\skyMedia;

	if($_POST && count($_POST) > 0){
		$data = [
			'folder' => $_POST['folder'],
			'filename' => $_POST['filename'],
			'key' => $_POST['key']
		];

		$url = $_POST['redirectURL'] ? $_POST['redirectURL'] : $_SERVER['HTTP_REFERER'];
		

		// Function to archive selected image on S3 Server
		// $archive = skyMedia::fileArchive($data);
		
		if ($this->is_ajax_request || $_POST['is_ajax_request']){
			// Find IDE in $_POST array and save object
			$namespace = '\\Crave\\Model\\';
			$model_name = $namespace .  IDE;
			$model = new $model_name($_POST['ide']);

			// Name function that handles media items in model saveMediaItems()
			if(method_exists($model, "deleteMediaItems")){
				$model->deleteMediaItems($data);
			}

			// redirect($url);
		    
		} elseif($_POST['save']) {

		} else {

		}
	} else {
		// Something went wrong
		throw new \Exception('Could not delete image.');
	}
?>
