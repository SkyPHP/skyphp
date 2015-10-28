<?php 

namespace Sky;

use Aws\S3\S3Client;

/** 
* New class to handle media objects. 
*/ 
class skyMedia {

	/** 
	* Uploading a flyer .
	*
	* @param Array $params
	*/ 
	public static function uploadFlyer($params) {
		global $aws_creds ; 

		#1. make sure we have the credentials that will allow us to upload the file .
		if (!$aws_creds || !$aws_creds['s3']) {
			throw new \Exception('AWS credentials are missing.');
		}

		$client = S3Client::factory(array(
		    'credentials' => array(
		        'key'    => $aws_creds['s3']['key'],
		        'secret' => $aws_creds['s3']['secret']
		    )
		));

		$file = $params['file']; 


		$bucket = "/vfolder/files-v3/events/{$params['ct_event_ide']}/{$params['type']}"; 

		// Upload an object to Amazon S3
		$result = $client->putObject(array(
		    'Bucket' => $bucket,
		    'Key'    => $params['filename'],
		    'SourceFile'   => $file
		));

		return 1; 
	}


	/** 
	* Uploading a flyer .
	*
	* @param Array $params
	*/ 
	public static function uploadVenuePhoto($params) {
		global $aws_creds ; 

		#1. make sure we have the credentials that will allow us to upload the file .
		if (!$aws_creds || !$aws_creds['s3']) {
			throw new \Exception('AWS credentials are missing.');
		}

		$client = S3Client::factory(array(
		    'credentials' => array(
		        'key'    => $aws_creds['s3']['key'],
		        'secret' => $aws_creds['s3']['secret']
		    )
		));

		$file = $params['file']; 


		$bucket = "/vfolder/files-v3/{$params['venue_ide']}"; 

		// Upload an object to Amazon S3
		$result = $client->putObject(array(
		    'Bucket' => $bucket,
		    'Key'    => $params['filename'],
		    'SourceFile'   => $file
		));

		return 1; 
	}

	/**
	* Global function to handle file uploads
	*@param array 
	* [
	*	'folder' => 'foldername' // (Required)
	*	'files' => $_FILES, // (Required)
	*	'filename' => ['filename_cust'] // (Optional) 
	*	'override' => '0/1' // (Default = 0)
	* ]
	*
	*@return json string 
	* {
	*	status : OK / Error
	*	filename : 'filename.filetype'
	*	filepath : 'vfolder/files-v3/foldername/*'
	*	maskedpath : ex . 's3.amazon.com/*'
	*	errorMsg : 'Reason for failure (if there is one)'
	* }
	*/
	public static function fileUpload($params){
		global $aws_creds ; 

		if($params['folder'] !== "" && !is_null($params['folder'])){
			#1. make sure we have the credentials that will allow us to upload the file .
			if (!$aws_creds || !$aws_creds['s3']) {
				throw new \Exception('AWS credentials are missing.');
			}

			$client = S3Client::factory(array(
			    'credentials' => array(
			        'key'    => $aws_creds['s3']['key'],
			        'secret' => $aws_creds['s3']['secret']
			    )
			));

			if(is_array($params['files']) && $params['files']){
				$storeResults = [];
				// Loop through files paramater to handle multiple file uploads
				foreach($params['files'] as $file){ 
					if($file['size'] > 0){
						$folder = $params['folder'];
						$filename = $params['filename'] ? $params['filename'] : $file['name'];
						$override = $params['override'] ? $params['override'] : 0; 
						$bucket = "vfolder/files-v3/$folder"; 

						// Remove special characters and spaces from filename and set it to all lowercase letter
						$filename = strtolower(preg_replace("/[^A-Za-z0-9.]/", "", $filename));

						if($override == 1){
							// Check if file with this name already exists
							$fileExists = $client->doesObjectExist($bucket, $filename);

							if($fileExists){
								// If file exists, rename this file before uploading (append random number between 100 - 100,000)
								$replaceString = "_" . rand(100, 100000);
								$strPosition = strrpos($filename, ".");

								$filename = substr_replace($filename, $replaceString, $strPosition, 0);
							}
						}

						// Upload an object to Amazon S3 using puObject method
						$result = $client->putObject(array(
						    'Bucket' => $bucket,
						    'Key'    => $filename,
						    'Body'   => $file,
						    'SourceFile'   => $file['tmp_name'],
						    'ContentType' => $file['type']
						));

						$storeResults["$filename"] = [
								"bucket" => $bucket,
								"filepath" => urldecode($result['ObjectURL']),
								"maskedpath" => urldecode($result['ObjectURL'])
						];
					}
				}
			} else {
				$response = [
					'status' => 'Error',
					'data' => NULL,
					'errorMsg' => 'Upload failed: No file was available for upload'
				];
			}

			// Add data to response array if no errors are encountered
			$response = [
				'status' => 'OK',
				'data' => $storeResults
			];
		} else {
			// Folder is required
			$response = [
				'status' => 'Error',
				'data' => NULL,
				'errorMsg' => 'You must provide a folder'
			];

		}

		return json_encode($response);
	}

	public function fileRemove(){
		
	}
}