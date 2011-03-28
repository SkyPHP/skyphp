<?

class media {


	public static $error = NULL;

	function error() {
		return self::$error;
	}//if


	function simpleviewer($vfolder_identifier,$width,$height,$parameters=NULL) {
		$vfolder = media::get_vfolder($vfolder_identifier,$parameters['max_pics']);
		if (is_array($vfolder['items'])) include('modules/media/simpleviewer/simpleviewer_config.php');
		else if ($parameters['empty_message']) echo $parameters['empty_message'];
		else echo 'There are no images available.';
	}//simpleviewer


	function get_original_by_size($vfolder, $width, $height = NULL) {
		if (!$vfolder || !$width) return NULL;
		if ($height) $height_q = "and media_instance.height = {$height}";
		$aql = 	"
					media_instance {
						where media_vfolder.vfolder_path = '{$vfolder}'
						and media_instance.instance IS NULL
						and media_instance.width = {$width}
						{$height_q}
						limit 1
					}
					media_item on media_instance.media_item_id = media_item.id {}
					media_vfolder on media_item.media_vfolder_id = media_vfolder.id {}
				";
		$rs = aql::select($aql);
		return media::get_item($rs[0]['media_item_id']);
	}

/**
 * get a random item from the specified vfolder -- same parameters as get_itemget_item except the first parameter identifies the vfolder to get a random item from
 * @param string $identifier the id, ide, or vfolder_path of the vfolder from which to retrieve a random item
 * @param integer $width desired width
 * @param integer $height desired height
 * @param boolean $crop true means cropping is enabled
 * @return array returns an array containing information about the desired image
 */
	function get_random_item($vfolder_identifier, $desired_w = NULL, $desired_h = NULL, $crop = NULL, $media_overlay_id = NULL, $parameters=NULL) {
		$vfolder = media::get_vfolder($vfolder_identifier,NULL,NULL,NULL,$parameters);
		$num_items = count($vfolder['items']);
		$random_index = rand( 0, $num_items - 1 );
		$media_item_id = $vfolder['items'][$random_index]['media_item_id'];
		return media::get_item( $media_item_id, $desired_w, $desired_h, $crop, $media_overlay_id, $parameters);
	}//get_random_item

	function get_if_not_here($media_item_id,$src_beg,$src_end,$local_path,$dest_dir){

		if(!file_exists($local_path) || filesize($local_path) == 0){
		#echo "TRYING TO GET IMAGE";
			$aql = "media_instance	{
										where media_item_id = $media_item_id
										and instance is null
										limit 1
									}";
			$rs = aql::select($aql);
			if($rs){
				$media_instance_ide = $rs[0]['media_instance_ide'];
				$src = $src_beg.'/'.$media_instance_ide.'.'.$src_end;
				$media_host_id=0;
				$command = 'hostname';
				$hostname = trim(shell_exec($command));
				$aql = "media_host{
									where host_name = '$hostname'
									limit 1
								}";
				$rs = aql::select($aql);
				if($rs){
					$media_host_id = $rs[0]['media_host_id'];
				}
				$aql = "media_distro {
										where media_item_id = $media_item_id
									 }

						media_host	{
										domain_name
										where media_host.id <> $media_host_id
									}";
				$rs = aql::select($aql);
				if($rs){
					foreach($rs as $host) {
						$img = "http://".$host['domain_name'].$src;
						if ($_GET['aql_debug']) echo 'copying ..'.$img.' to '.$local_path.'!!!!<br>';
						@mkdir($dest_dir, 0777, true);
						if(copy($img,$local_path)){
							chmod($local_path,0777);
							$fields = array	(
												'media_host_id'=>$media_host_id,
												'media_item_id'=>$media_item_id
											);
							aql::insert('media_distro',$fields);
							return true;
						}else{
							//keep going
						}
					}
				}
			}
		}
		return false;
	}
/**
* retrieve media_overlay_id
*/
	function get_media_overlay_id($params){
		if (is_array($params)) {
			if (!$params["filename"] && $params["media_item_id"]) {
				$overlay_img = media::get_item($params["media_item_id"]);
				$params["filename"] = $overlay_img["local_path"];
			}

			$overlay_media_item_id = $params["media_item_id"];
			$overlay_gravity = $params["gravity"];
			$overlay_width = $params["width"];
			$overlay_height = $params["height"];
			$overlay_crop = $params["crop"];
			$overlay_filename = $params["filename"];

			$overlay_where = "";
			$keys = array("media_item_id","gravity","width","height","crop","filename");
			foreach ($keys as $key) {
				$value = $params[$key];
				$field_where = "where_$key";
				if (is_null($value)) $value = "is null";
				else if (is_numeric($value)) $value = "= $value";
				else $value = "= '$value'";
				$$field_where = " and $key $value";
				$overlay_where .= $$field_where;
			}
			$rs_media_overlay = aql::select	("
												media_overlay {
													id
													where true $overlay_where
													order by id desc limit 1
												}
											");
			if (is_array($rs_media_overlay)) $media_overlay_id = $rs_media_overlay[0]["media_overlay_id"];
			else {
				$media_overlay_fields = array	(
													"media_item_id" => $overlay_media_item_id,
													"gravity" => $overlay_gravity,
													"width" => $overlay_width,
													"height" => $overlay_height,
													"crop" => $overlay_crop,
													"filename" => $overlay_filename
												);
				$media_overlay = aql::insert("media_overlay",$media_overlay_fields);
				if (is_array($media_overlay)) $media_overlay_id = $media_overlay[0]["media_overlay_id"];
			}
			return $media_overlay_id;
		} else {
			return $params;
		}
	}

/**
 * retrieve (and generate if necessary) a zip file given the specified media_vfolder and desired dimensions
 * @param string $identifier either a numeric media_vfolder_id ,an encrypted media_vfolder_ide or media_vfolder path
 * @param integer $width desired width
 * @param integer $height desired height
 * @param boolean $crop true means cropping is enabled
 * @return array returns an array containing information about the desired image
 */
	function zip($identifier, $desired_w = NULL, $desired_h = NULL, $crop = NULL, $media_overlay_id = NULL, $parameters=NULL) {
		global $db, $imagetype, $sky_media_local_path, $sky_media_src_path, $sky_icon_path;
		//media overlay id manipulation
		$media_overlay_array = $media_overlay_id;
		$media_overlay_id = self::get_media_overlay_id($media_overlay_array);

		#echo $media_overlay_id.'!!';
		if(is_numeric($identifier)){
			$media_vfolder_id = $identifier;
		}else{
			$media_vfolder_id = decrypt($identifier,'media_vfolder');
		}
		$where_h = ($desired_h)?"AND media_zip.height = $desired_h":'AND media_zip.height IS NULL';
		$where_w = ($desired_w)?"AND media_zip.width = $desired_w":'AND media_zip.width IS NULL';
		$where_overlay = $media_overlay_id?"AND media_zip.media_overlay_id = $media_overlay_id":'AND media_zip.media_overlay_id IS NULL';
		$where_identifier = (is_numeric($media_vfolder_id))?"media_vfolder.id = $media_vfolder_id":"media_vfolder.vfolder_path = '$identifier'";
		$SQL = "SELECT	media_vfolder.vfolder_path as vfolder_path,
						media_vfolder.id as media_vfolder_id,
						media_zip.id as media_zip_id,
						media_zip.height,
						media_zip.width,
						media_zip.media_overlay_id,
						media_zip.mod_time,
						(
							SELECT COUNT(media_item.id)
							FROM media_item
							WHERE media_item.media_vfolder_id = media_vfolder.id
							and media_item.active = 1
						) as qty
				FROM media_vfolder
				LEFT JOIN media_zip on media_zip.media_vfolder_id = media_vfolder.id
				AND media_zip.active = 1
				$where_h
				$where_w
				$where_overlay
				WHERE
				$where_identifier
				AND media_vfolder.active = 1
				LIMIT 1";

		$r = $db->Execute($SQL) or die("$SQL<br>".$db->ErrorMsg());
		$r = $r->GetArray();
		$r = $r[0];
		$zip = array();
		//check if there are any files in this vfolder
		if($r['qty']>0){
			$media_vfolder_id = $r['media_vfolder_id'];
			$media_zip_id = $r['media_zip_id'];
			$zip = $r;
			$zip['status'] = '';
			//if zip like this is not in database
			if	( !$media_zip_id ){
				$fields = array(
									'width' => $desired_w,
									'height' => $desired_h,
									'mod_time' => 'now()',
									'mod__person_id' => $_SESSION['login']['person_id']?$_SESSION['login']['person_id']:null,
									'media_overlay_id' => $media_overlay_id,
									'media_vfolder_id' => $media_vfolder_id
								);
				$rs = aql::insert('media_zip',$fields);
				$media_zip_id = $rs[0]['media_zip_id'];
				$zip = $rs[0];
				$zip['status'] = 'Inserting new zip into table, one of the properties did not match or zip does not exist. ';
			}


			$zip['media_zip_id'] = $media_zip_id;

			$zip['media_zip_ide'] = encrypt($media_zip_id,'media_zip');
			$zip['url'] = '/media-zip/'.$zip['media_zip_ide'].'.zip';
			$zip['src'] = $zip['url'];
			$zip_folder_path = '/tmp/media-zip';

			//check if folder actually exists, if not make it
			if(!file_exists($zip_folder_path)){
				mkdir($zip_folder_path);
				$zip['status'] = $zip['status'].'Zip directory does not exist. ';
			}
			$zip_file_path = $zip_folder_path.'/'.$media_zip_id.'.zip';
			$zip['local_path'] = $zip_file_path;
			//1 determine what should be in zip and if something was added/changed since zip's creation
			$SQL = "SELECT	media_item.id as media_item_id,
							media_item.mod_time
					FROM media_item
					LEFT JOIN media_vfolder ON media_item.media_vfolder_id = media_vfolder.id and media_vfolder.active = 1
					WHERE media_item.active = 1
					AND media_vfolder.id = $media_vfolder_id";
			$r = $db->Execute($SQL) or die("$SQL<br>".$db->ErrorMsg());
			$media_items = $r->GetArray();
			if($media_items){
				//check if any of the items were modified after zip
				$modified = false;
				foreach($media_items as $media_item){
					if($media_item['mod_time']>$zip['mod_time']){
						$modified = true;
						$zip['status'] = $zip['status'].'One of the files is outdated. ';
						break;
					}
				}
				//recreate zip if it has been deleted or one of its contents modified
				if(!file_exists($zip_file_path) || $modified){
					$zip['status'] = $zip['status'].'Creating zip. ';
					$zip_file = new ZipArchive();
					#print_a($media_items);
					if ($zip_file->open($zip_file_path,ZIPARCHIVE::CREATE) === TRUE) {
						foreach($media_items as $media_item){
							//2 create items that should go into it
							#echo $media_overlay_id.'!';
							$file = media::get_item($media_item['media_item_id'], $desired_w, $desired_h, $crop, $media_overlay_id, $parameters);
							#exit($media_overlay_id);
							$file_path = $file['local_path'];
							if(file_exists($file_path)){
								$file_name = basename($file_path);
								//3 add them to the zip
								$zip_file->addFile($file_path, $file_name) or die ("ERROR: Could not add file: $file_path");
							}else{
								$zip['status'] = $zip['status']."File in $file_path does not exist in this location. ";
							}
						}
						$zip['status'] = $zip['status'].$zip_file->getStatusString();
						$zip_file->close();
						$fields = array('mod_time'=>'now()');
						aql::update('media_zip',$fields,$media_zip_id);
					}else{
						$zip['status'] = $zip['status'].'Failed to create zip file. ';
					}
				}else{
					$zip['status'] = $zip['status'].'The zip already exists and contents were not modified. ';
				}
			}else{
				$zip['status'] = $zip['status'].'This media vfolder got no media items. ';
			}
		}else{
			$zip['status'] = $zip['status'].'This vfolder got no vitems or this vfolder does not exist.';
		}
		return $zip;
	}
/**
 * retrieve (and generate if necessary) a media_instance given the specified media_item and desired dimensions
 * @param string $identifier either a numeric media_item_id or an encrypted media_item_ide
 * @param integer $width desired width
 * @param integer $height desired height
 * @param boolean $crop true means cropping is enabled
 * @return array returns an array containing information about the desired image
 */
	function get_item($identifier, $desired_w = NULL, $desired_h = NULL, $crop = NULL, $media_overlay_id = NULL, $parameters=NULL) {
		global $default_image_quality,$db, $imagetype, $sky_media_local_path, $sky_media_src_path, $media_hosts, $sky_icon_path;
		if (!$default_image_quality) $default_image_quality = 80;
		self::$error = NULL;

        if ($desired_w) $desired_w = floor($desired_w);
        if ($desired_h) $desired_h = floor($desired_h);
		$media_overlay_array = $media_overlay_id;
		$media_overlay_id = self::get_media_overlay_id($media_overlay_array);

		$crop_gravity = $parameters['crop_gravity'];
		if($crop_gravity){
			$possible_gravity = array();
			$possible_gravity['northeast'] = 'NorthEast';
			$possible_gravity['northwest'] = 'NorthWest';
			$possible_gravity['center'] = 'Center';
			$possible_gravity['southeast'] = 'SouthEast';
			$possible_gravity['southwest'] = 'SouthWest';
			$possible_gravity['north'] = 'North';
			$possible_gravity['west'] = 'West';
			$possible_gravity['east'] = 'East';
			$possible_gravity['center'] = 'Center';

			$crop_gravity = $possible_gravity[strtolower($crop_gravity)];
			$crop_gravity_abr = strtolower(preg_replace("/([a-z])/","",$crop_gravity));
		}
		if ( !is_numeric($identifier) ) {
			$media_item_id = decrypt($identifier,'media_item');
		} else {
			$media_item_id = $identifier;
		}//if

		if ($_GET['debug_media']) echo 'media::get_item...';

		if ( !is_numeric($media_item_id) || $media_item_id == 0 ) return false;
		//die("media::get_item error: '$identifier' is not a valid media_item_id or media_item_ide.");

		if ($_GET['debug_media']) echo $media_item_id . '<br />';

		// get the media_item data
		$SQL = "select  media_item.slug,
						media_item.caption,
						media_item.title,
						media_item.credits,
                        media_vfolder.id as media_vfolder_id,
						media_vfolder.vfolder_path,
						media_vfolder.quality,
						media_instance.width,
						media_instance.height,
						media_instance.file_type
				from media_item
				left join media_vfolder on media_item.media_vfolder_id = media_vfolder.id and media_vfolder.active = 1
				left join media_instance on media_instance.media_item_id = media_item.id and media_instance.active = 1 and media_instance.instance is null
				where media_item.active = 1
				and media_item.id = $media_item_id
				limit 1";
		$r = $db->Execute($SQL) or die("$SQL<br>".$db->ErrorMsg());
		$quality = $r->Fields('quality')?$r->Fields('quality'):$default_image_quality;
		$img = array();
		$img['media_item_id'] = $media_item_id;
		$img['media_item_ide'] = encrypt($media_item_id,'media_item');
        $img['media_vfolder_id'] = $r->Fields('media_vfolder_id');
		$img['filename'] = $r->Fields('slug').'.'.$r->Fields('file_type');
		$img['title'] = $r->Fields('title');
		$img['credits'] = $r->Fields('credits');
		$img['caption'] = $r->Fields('caption');
		$slug = $r->Fields('slug');
		$vfolder_path = $r->Fields('vfolder_path');
		$caption = str_replace('"','',$r->Fields('caption'));
        if ( !$caption ) $caption = str_replace('"','',$r->Fields('title'));
		$file_type = strtolower($r->Fields('file_type'));
		if ($instance) $instance_folder = '/' . $instance;
		$local_path = $sky_media_local_path . $vfolder_path . $instance_folder . '/' . $slug . '.' . $file_type;
		$dest_dir = $sky_media_local_path . $vfolder_path . $instance_folder;
		$orig_w = $r->Fields('width');
		$orig_h = $r->Fields('height');

                if(!$desired_h xor !$desired_w){
                   if($desired_w){
                      $unknown = &$desired_h;
                      $known = &$desired_w;
                      $orig_known = $orig_w;
                      $orig_unknown = $orig_h;
                   }else{
                      $known = &$desired_h;
                      $unknown = &$desired_w;
                      $orig_unknown = $orig_w;
                      $orig_known = $orig_h;
                   }

                   $ratio = $known / $orig_known;
                   $unknown = (int)($ratio * $orig_unknown);
                }

		//reset desired dimentions to the original dimentions if the desired dimentions are bigger than original and 'upsizing' was not requested
		if($desired_w>$orig_w && $desired_w  && !$parameters['upsize']){
			$desired_w = $orig_w;
		}
		if($desired_h>$orig_h && $desired_h  && !$parameters['upsize']){
			$desired_h = $orig_h;
		}
		if (!$orig_w && (in_array($file_type,$imagetype))) {
			// we have an invalid image
			return false;
		}//if
		if ($orig_w) $orig_ratio = floor( ($orig_w / $orig_h) * 1000 ) / 1000;
		if ($_GET['debug_media']) echo "desired: $desired_w x $desired_h <br />";
		if ($_GET['debug_media']) echo "orig: $orig_w x $orig_h [$orig_ratio]<br />";

		$target_w = $desired_w;
		$target_h = $desired_h;
		if ($crop) {
                   if(!$parameters['force_crop']){
			if ( $desired_w > $orig_w ) $target_w = $orig_w;
			if ( $desired_h > $orig_h ) $target_h = $orig_h;
                   }
		} else if ( $orig_w && $orig_h ) {
			// if not cropping, determine the dimensions of the new resize which will fit within the desired dimensions with the same aspect ratio of the original
			if ($desired_w && $desired_h) {
				$target_h = floor( $desired_w * $orig_h / $orig_w );
				if ($desired_h && $target_h > $desired_h) {
					$target_h = $desired_h;
					$target_w = floor( $desired_h * $orig_w / $orig_h );
				}//if
			}//if
		}//if

		if ( (!in_array($file_type,$imagetype)) || $file_type == 'swf' || $file_type == 'flv' || ( $target_w <=0 && $target_h <= 0 ) ) {
			$criteria = " and instance is null ";
		} else if ($target_w <= 0) {
			$criteria = " and media_instance.height = $target_h ";
		} else if ($target_h <= 0) {
			$criteria = " and media_instance.width = $target_w ";
		} else {
			$criteria = "	and media_instance.width = $target_w
							and media_instance.height = $target_h ";
		}//if
		if($crop_gravity){
			$instance_name = $target_w.'x'.$target_h.'-'.$media_overlay_id.'-'.$crop_gravity_abr;
			$criteria .= " AND media_instance.instance = '$instance_name' ";
		}
		if ( is_numeric($media_overlay_id) ) {
			$criteria .= " and media_instance.media_overlay_id = $media_overlay_id ";
		} else{
			$criteria .= " and media_instance.media_overlay_id IS NULL ";
		}

		//if

		// find the instance we need
		$SQL = "select media_instance.*
				from media_instance
				where media_instance.active = 1
				and media_instance.media_item_id = $media_item_id
				$criteria";
				#echo $SQL;
		$r = $db->Execute($SQL) or die("$SQL<br>".$db->ErrorMsg());
		if ($_GET['debug_media']) echo "<pre>$SQL</pre><br />";

		// get image from another server if it's not on this server
		media::get_if_not_here($media_item_id,$sky_media_src_path, $file_type,$local_path,$dest_dir);
		if (!$r->EOF) {
			// if instance is there, check if its file stored on this server
			$instance_file_path = $dest_dir.'/'. $r->Fields('instance') . '/'.$slug.'.' . $file_type;
			$instance_file_exists = file_exists($instance_file_path);
		}
		if ($instance_file_exists) {
			if ($file_type=='swf') {
				// the instance is flash
				if ($_GET['debug_media']) echo 'media_instance_id=' . $r->Fields('id') . ' (swf)<br />';
				$img['media_instance_id'] = $r->Fields('id');
				$img['media_instance_ide'] = encrypt($img['media_instance_id'],'media_instance');
				$img['src'] = $sky_media_src_path . '/' . $img['media_instance_ide'] . '.' . $file_type;
				$img['width'] = $r->Fields('width');
				$img['local_path'] = $instance_file_path;
				$img['height'] = $r->Fields('height');
				#if ($_GET['debug_media']) print_a($img);
				if ( $desired_w ) {
					#if ($_GET['debug_media']) echo "desired width: $desired_w <br />";
					$percentage = $desired_w / $img['width'];
					$img['width'] = floor( $percentage * $img['width'] );
					if ($desired_h) $img['height'] = $desired_h;
					else $img['height'] = floor( $percentage * $img['height'] );
				} else if ( $desired_h ) {
					#if ($_GET['debug_media']) echo "desired height: $desired_h <br />";
					$percentage = $desired_h / $img['width'];
					if ($desired_w) $img['width'] = $desired_w;
					else $img['width'] = floor( $percentage * $img['width'] );
					$img['height'] = floor( $percentage * $img['height'] );
				}
				#if ($_GET['debug_media']) print_a($img);
				//$img['img'] = '<img src="'.$img['src'].'" width="'.$img['width'].'" height="'.$img['height'].'" alt="'.$caption.'" border="0" />';
				$img['rand'] = rand(0,9999999);
				$img['img'] = "

					<script type=\"text/javascript\">
						addLoadEvent(function(){
							var flashvars = {};
							var params = {
								allownetworking: \"internal\"
							};
							params.wmode = \"opaque\";
							var attributes = {};
							swfobject.embedSWF('{$img['src']}', 'flash_{$img['rand']}', {$img['width']}, {$img['height']}, '9.0.0', false, flashvars, params, attributes);
						})
					</script>
					<span id=\"flash_{$img['rand']}\"></span>";
				$img['html'] = $img['img'];
				return $img;
			} else if ($file_type=='mp3') {
				// the instance is an mp3 file
				if ($_GET['debug_media']) echo 'media_instance_id=' . $r->Fields('id') . ' (mp3)<br />';
				$img['media_instance_id'] = $r->Fields('id');
				$img['media_instance_ide'] = encrypt($img['media_instance_id'],'media_instance');
				$img['src'] = $sky_media_src_path . '/' . $img['media_instance_ide'] . '/' . $slug . '.' . $file_type;
				$img['file_type'] = $file_type;
				//$img['img'] = '<img src="'.$img['src'].'" width="'.$img['width'].'" height="'.$img['height'].'" alt="'.$caption.'" border="0" />';
				$img['rand'] = rand(0,9999999);
				$img['local_path'] = $instance_file_path;
				$img['img'] = "
					<span id=\"media_item_{$img['rand']}\"></span>
					<script type=\"text/javascript\">
					var flashvars = {
						file: '{$img['src']}'
					};
					var params = {
						allowfullscreen: false
					};
					var attributes = {};
					swfobject.embedSWF('/lib/jw/embed/mediaplayer-3.14.swf', 'media_item_{$img['rand']}', '150', '20', '9.0.0', false, flashvars, params, attributes);
					</script>";
				$img['html'] = $img['img'];
				return $img;
			} else if ($file_type=='flv') {
				// the instance is flash
				if ($_GET['debug_media']) echo 'media_instance_id=' . $r->Fields('id') . ' (flv)<br />';
				$img['media_instance_id'] = $r->Fields('id');
				$img['media_instance_ide'] = encrypt($img['media_instance_id'],'media_instance');
				$img['src'] = $sky_media_src_path . '/' . $img['media_instance_ide'] . '/' . $slug . '.' . $file_type;
				$img['file_type'] = $file_type;
				$img['width'] = $r->Fields('width');
				$img['height'] = $r->Fields('height');
				//$img['img'] = '<img src="'.$img['src'].'" width="'.$img['width'].'" height="'.$img['height'].'" alt="'.$caption.'" border="0" />';
				$img['rand'] = rand(0,9999999);
				$img['flv_image'] = $parameters['flv_image'];
				$img['local_path'] = $instance_file_path;
				$desired_h = $desired_h + 20;
				$img['img'] = "
					<span id=\"flash_{$img['rand']}\"></span>
					<script type=\"text/javascript\">
					var flashvars = false;
					var params = {
						allowescriptaccess: 'always',
						allowfullscreen: 'true',
						wmode: 'opaque',
						flashvars: '&file=http://{$_SERVER['HTTP_HOST']}{$img['src']}&image={$img['flv_image']}'
					};
					var attributes = {};
					swfobject.embedSWF('/lib/jw/embed/player.swf', 'flash_{$img['rand']}', '{$desired_w}', '{$desired_h}', '9.0.0', false, flashvars, params, attributes);
					</script>";
				$img['html'] = $img['img'];
				return $img;
			} else if (!in_array($file_type,$imagetype)) {
				// the instance is a general document of some sort
				if ($_GET['debug_media']) echo 'media_instance_id=' . $r->Fields('id') . ' (' . $file_type . ')<br />';
				$img['open_in_new_win'] = true;
				$img['media_instance_id'] = $r->Fields('id');
				$img['media_instance_ide'] = encrypt($img['media_instance_id'],'media_instance');
				$img['src'] = $sky_media_src_path . '/' . $img['media_instance_ide'] . '/' . $slug . '.' . $file_type;
				$img['file_type'] = $file_type;
				$img['local_path'] = $instance_file_path;
				$img['width'] = $desired_w;
				$img['height'] = $desired_h;
				//$img['img'] = '<img src="'.$img['src'].'" width="'.$img['width'].'" height="'.$img['height'].'" alt="'.$caption.'" border="0" />';
				$img['rand'] = rand(0,9999999);
				$img['img'] = '<img src="'.$sky_icon_path.$file_type.'.jpg" width="'.$img['width'].'" height="'.$img['height'].'" alt="'.$caption.'" title="'.$slug.'.'.$file_type.'" border="0" />';
				$img['html'] = $img['img'];
				return $img;
			} else {
				// the instance already exists
				if ($_GET['debug_media']) echo 'media_instance_id=' . $r->Fields('id') . '<br />';

                                $img['media_item_ide'] = encrypt($media_item_id,'media_item');
                                $img['open_in_new_win'] = true;
				$img['media_instance_id'] = $r->Fields('id');
				$img['media_instance_ide'] = encrypt($img['media_instance_id'],'media_instance');
				$img['src'] = $sky_media_src_path . '/' . $img['media_instance_ide'] . '/' . $slug . '.' . $file_type;
				$img['file_type'] = $file_type;
				$img['width'] = $r->Fields('width');
				$img['height'] = $r->Fields('height');
				$img['img'] = '<img src="'.$img['src'].'" width="'.$img['width'].'" height="'.$img['height'].'" alt="'.$caption.'" border="0" />';
				$img['html'] = $img['img'];
				$img['local_path'] = $instance_file_path;
				return $img;
			}//if
		} else {
			// instance does not yet exist.. we need to resize and/or crop
			if ($crop) {

				// crop the original to the desired dimensions
				//$delta_w = $orig_w - $target_w;
				//$delta_h = $orig_h - $target_h;
				if ( $target_h * $orig_w / $orig_h >= $target_w ) $crop_width = true;
				else $crop_width = false;
				$crop_gravity_command = $crop_gravity?'-gravity '.$crop_gravity:'-gravity Center';
				if ($crop_width) {
					$resize_command = "-resize x{$target_h} -strip -quality $quality"; // $quality is set at the start of this function
					$resize_w = floor( $target_h * $orig_w / $orig_h );
					//offsets not used, default gravity: center is used instead
					#$offset_w = floor( ($resize_w - $target_w) / 2 );
					$offset_w = 0;
					$crop_command = "$crop_gravity_command -crop {$target_w}x{$target_h}+{$offset_w}+0  +repage";
				} else {
					$resize_command = "-resize {$target_w} -strip -quality $quality "; // $quality is set at the start of this function
					$resize_h = floor( $target_w * $orig_h / $orig_w );
					#$offset_h = floor( ($resize_h - $target_h) / 2 );
					$offset_h = 0;
					$crop_command = "$crop_gravity_command -crop {$target_w}x{$target_h}+0+{$offset_h}  +repage";
				}//if
			} else {
				// no crop... resize the original to the target dimensions
				$resize_command = "-resize {$target_w}x{$target_h} -strip -quality $quality"; // $quality is set at the start of this function
			}//if crop

			$instance = $target_w . 'x' . $target_h;
			if ( is_numeric($media_overlay_id) ) $instance .= '-' . $media_overlay_id;
			if ( $crop_gravity && is_numeric($media_overlay_id) ){
				$instance .='-' . $crop_gravity_abr;
			}elseif( $crop_gravity && !is_numeric($media_overlay_id) ){
				$instance .='--' . $crop_gravity_abr;
			}
			$dest_dir = strtolower($sky_media_local_path . $vfolder_path . '/' . $instance);
			@mkdir($dest_dir, 0777, true);
			$new_local_path = strtolower($dest_dir . '/' . $slug . '.' . $file_type);

			$input_path = $local_path;

			// resize
			if ( $resize_command ) {
				$command = "convert $input_path $resize_command $new_local_path";
				$input_path = $new_local_path;
				if ($_GET['debug_media']) echo 'command: ' . $command . '<br>';
				exec($command);
			}//if

			// crop
			if ( $crop_command ) {
				$command = "convert $input_path $crop_command $new_local_path";
				$input_path = $new_local_path;
				if ($_GET['debug_media']) echo 'command: ' . $command . '<br>';
				exec($command);
			}//if
			// overlay
			if (is_array($media_overlay_array)) {
				$overlay_media_item_id = $media_overlay_array["media_item_id"];
				$overlay_gravity = $media_overlay_array["gravity"];
				$overlay_width = $media_overlay_array["width"];
				$overlay_height = $media_overlay_array["height"];
				$overlay_crop = $media_overlay_array["crop"];

				$overlay_img = media::get_item($overlay_media_item_id,$overlay_width,$overlay_height,$overlay_crop);
				$overlay_path = $overlay_img["local_path"];

				$command = "composite -gravity $overlay_gravity $overlay_path $input_path $new_local_path";
				$input_path = $new_local_path;
				if ($_GET['debug_media']) echo 'command: ' . $command . '<br>';
				exec($command);
			}
			else if ( is_numeric($media_overlay_id) ) {
				$SQL = "select filename,
								gravity,
								height,
								crop,
								width,
								media_item_id
						from media_overlay
						where media_overlay.active = 1
						and media_overlay.id = $media_overlay_id";
				$s = $db->Execute($SQL) or die("$SQL<br>".$db->ErrorMsg());
				if (!$s->EOF) {
					$overlay_media_item_id = $s->Fields("media_item_id");
					$overlay_gravity = $s->Fields("gravity");
					$overlay_width = $s->Fields("width");
					$overlay_height = $s->Fields("height");
					$overlay_crop = $s->Fields("crop");
					$overlay_img = media::get_item($overlay_media_item_id,$overlay_width,$overlay_height,$overlay_crop);
					$overlay_path = $overlay_img["local_path"];
					$command = "composite -gravity $overlay_gravity $overlay_path $input_path $new_local_path";
					$input_path = $new_local_path;
					if ($_GET['debug_media']) echo 'command: ' . $command . '<br>';
					exec($command);
				} else {
					die('media_overlay ' . $media_overlay_id . ' does not exist.');
				}//if
			}//if

			// if the new image was successfully created, get the info and insert it into the database
			$image_info = @getimagesize($new_local_path);
			if (is_array($image_info)) {

				// insert the image into the database
				$f = NULL;
				$f['media_item_id'] = $media_item_id;
				$f['file_type'] = strtolower($imagetype[$image_info[2]]);
				$f['instance'] = $instance;
				$f['file_size'] = @filesize($new_local_path);
				$f['width'] = $image_info[0];
				$f['height'] = $image_info[1];
				$f['media_overlay_id'] = ($media_overlay_id)?$media_overlay_id:null;
				#print_a($f);
				// QUICK FIX: imagemagick sometimes does not resize exactly so we want the image recorded in the database with the desired dimensions instead of the actual dimensions
				if ($target_w) $f['width'] = $target_w;
				if ($target_h) $f['height'] = $target_h;

				$f['aspect_ratio'] = floor( ($f['width'] / $f['height']) * 1000 ) / 1000;
				$rs = aql::insert('media_instance',$f);
				if ($_GET['debug_media']) print_a($f);
				if ($_GET['debug_media']) print_a($rs);
/*
				if ( false ) {  // TODO: save the binary to the database
					// we are in a load balanced environment, save the binary file to the database
					// untested:
					$handle = fopen($new_local_path, "rb");
					$binary = fread($handle, filesize($new_local_path));
					fclose($handle);
					$dbw->UpdateBlob('media_instance', 'binary', $binary, 'id = ' . $rs[0]['id']);
				}//if
*/
				$img['media_instance_id'] = $rs[0]['media_instance_id'];
				$img['media_instance_ide'] = $rs[0]['media_instance_ide'];
				#$img['src'] = $sky_media_src_path . '/' . $img['media_instance_ide'] . '.' . $file_type;
				$img['src'] = $sky_media_src_path . '/' . $img['media_instance_ide'] . '/' . $slug . '.' . $file_type;
				$img['file_type'] = $file_type;
				$img['width'] = $rs[0]['width'];
				$img['height'] = $rs[0]['height'];
				if ($crop) {
					$img['width'] = $target_w;
					$img['height'] = $target_h;
				}
				$img['img'] = '<img src="'.$img['src'].'" width="'.$img['width'].'" height="'.$img['height'].'" alt="'.$caption.'" border="0" />';
				$img['html'] = $img['img'];
				$img['aspect_ratio'] = $rs[0]['aspect_ratio'];
				$img['local_path'] = $dest_dir.'/'.$slug.'.' . $file_type;
				return $img;

			} else {
                $src = '/images/overlay.png';
                $img = '<img src="'.$src.'" border="0" alt="'.$new_local_path.'" media_item_id="'.$media_item_id.'" />';
                return array(
                    'src' => $src,
                    'img' => $img,
                    'html' => $img
                );
/*
                echo "new local path: $new_local_path <br />";
				echo "desired: $desired_w x $desired_h <br />";
				echo "target: $target_w x $target_h <br />";
				echo "orig: $orig_w x $orig_h [$orig_ratio]<br />";
				echo $resize_command . '<br />';
				echo $crop_command . '<br />';
				echo 'there was an error creating the new image instance for media item ' . $media_item_id;
*/
			}//if
		}//if
	}//function

/**
 * add a new media_item to the specified virtual folder
 *
 * @param string $source_location the local path OR the url where the file data exists.
 * @param string $vfolder the vfolder_path OR vfolder_id OR vfolder_ide in which to store this item
 * @param array $info_array array containing additional info, ie. name, keywords, caption
 * @return boolean returns true if success or false if there was an error
 */
	function new_item($source_location, $vfolder, $info_array=NULL) {
		global $db, $dbw, $sky_media_local_path, $imagetype;
		self::$error = NULL;

		$identifier = $vfolder;

		if (is_numeric($identifier)) {
			$aql = "media_vfolder {
						*
						where media_vfolder.id = {$identifier}
					}";
			$vf = aql::select($aql);
		} else if (substr($identifier,0,1)=='/') {
			$aql = "media_vfolder {
						*
						where vfolder_path = '{$identifier}'
					}";
			$vf = aql::select($aql);
		} else {
			$identifier = decrypt($identifier,'media_vfolder');
			if (is_numeric($identifier)) {
				$aql = "media_vfolder {
							*
							where media_vfolder.id = {$identifier}
						}";
				$vf = aql::select($aql);
			}//if
		}//if

		if ($vf[0]['id']) {
			$vfolder_path = $vf[0]['vfolder_path'];
			$media_vfolder_id = $vf[0]['id'];
		} else {
			$media_vfolder_id = media::new_vfolder($vfolder);
			if ($media_vfolder_id) $vfolder_path = $vfolder;
			else {
				self::$error = "media::new_item(): Cannot create new vfolder '$vfolder'";
				return false;
			}//if
		}//if

		// parse the slug from the filename
		$last_slash = strrpos($source_location,'/');
		$filename = substr($source_location,$last_slash+1);
		//echo $filename . '<br>';
		$dot_pos = strrpos($filename,'.');
		if ( $dot_pos ) {
            $slug = slugize(strtolower(substr($filename,0,$dot_pos)));
        	$extension = strtolower( substr($filename,$dot_pos + 1) );
        } else {
            $slug = slugize(strtolower($filename));
            $image_info = @getimagesize($source_location);
            $extension = strtolower($imagetype[$image_info[2]]);
        }


		if ( substr($source_location,0,4) == 'http' || substr($source_location,0,5) == 'https' ) {

			/*
			$handle = fopen($source_location, "rb");
			$binary = stream_get_contents($handle);
			fclose($handle);
			*/

			$ch = curl_init();
			curl_setopt ($ch, CURLOPT_URL, $source_location);
			curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, 0);
			$binary = curl_exec($ch);
			curl_close($ch);

		} else {

			$handle = fopen($source_location, "rb");
			$binary = fread($handle, filesize($source_location));
			fclose($handle);

		}//if

		// write the binary to file
		$filename = strtolower($sky_media_local_path . $vfolder_path . '/' . $slug . '.' . $extension);

		//echo $filename;

		// make sure not to overwrite an existing file
		while ( file_exists($filename) ) {
			$slug .= '_';
	        // change filename
			$filename = $sky_media_local_path . $vfolder_path . '/' . $slug . '.' . $extension;
		}//while
		@mkdir($sky_media_local_path . $vfolder_path, 0777, true);
		touch($filename);
		if (is_writable($filename)) {
			if (!$handle = fopen($filename, 'wb')) {
				 self::$error = "media::new_item(): Cannot open file ($filename)";
				 return false;
			}//if
			// Write $binary to our opened file.
			if (fwrite($handle, $binary) === FALSE) {
				 self::$error = "media::new_item(): Cannot write to file ($filename)";
				 return false;
			}//if
			fclose($handle);

		} else {
			self::$error = "media::new_item(): The file $filename is not writable";
			return false;
		}//if

		// insert the media item
		$fields = NULL;
		$fields['media_vfolder_id'] = $media_vfolder_id;
		$fields['slug'] = $slug;
		$fields['name'] = $info_array['name'];
		$fields['keywords'] = $info_array['keywords'];
		$fields['caption'] = $info_array['caption'];
		$fields['credits'] = $info_array['credits'];
		$fields['title'] = $info_array['title'];
		$rs = aql::insert('media_item',$fields);


		// insert the media instance
		$fields = NULL;
		$fields['media_item_id'] = $rs[0]['media_item_id'];
		$fields['file_type'] = strtolower($extension);
		$image_info = getimagesize($filename);
		if (is_array($image_info)) {
			$fields['file_size'] = @filesize($filename);
			$fields['width'] = $image_info[0];
			$fields['height'] = $image_info[1];
            $fields['file_type'] = strtolower($imagetype[$image_info[2]]);
		}//if
		$aql_insert = aql::insert('media_instance',$fields);
		$aql_insert[-1] = $rs;


		//insert the item into distro
		$media_host_id=0;
		$command = 'hostname';
		$hostname = trim(shell_exec($command));
		$aql = "media_host{
							where host_name = '$hostname'
							limit 1
						}";
		$rs_host = aql::select($aql);
		if($rs_host){
			$fields = NULL;
			$media_host_id = $rs_host[0]['media_host_id'];
			$fields['media_host_id'] = $media_host_id;
			$fields['media_item_id'] = $rs[0]['id'];
			$media_item_id = $fields['media_item_id'];
			$media_item_ide = encrypt($rs[0]['id'],'media_item');

			aql::insert('media_distro',$fields);

		}


		//send this file to another server
		//choose to what server to send
		$aql = "media_host{
							domain_name
							where media_host.id<>$media_host_id
							order by random()
						}";
		$rs_domain =  aql::select($aql);
		if($rs_domain){
			foreach($rs_domain as $domain){
				$url = 'http://www.'.$domain['domain_name'].'/scripts/get_media_item?media_item_ide='.$media_item_ide;
				$ch = curl_init();
				curl_setopt ($ch, CURLOPT_URL, $url);
				curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, 0);
				$binary = curl_exec($ch);
				//on error, try different server
				if(!curl_errno($ch)){
					curl_close($ch);
					break;
				}else{
					curl_close($ch);
				}
			}
		}
		$aql_insert[0]['media_item_id'] = $media_item_id;
		return $aql_insert;
	}//function new_item


/**
 * create new vfolder(s) recursively given the specified vfolder path
 *
 * @param string $vfolder_path vfolder_path of the vfolder to create (must begin with "/")
 * @param mixed $info (optional) name string or an array of additional fields to apply to the new vfolder (only applies to the deepest level vfolder created) [name, description, album_date, venue_id]
 * @return boolean returns true on success or false on error
 */
	function new_vfolder($vfolder_path,$info=NULL) {
		global $db, $dbw;
		self::$error = NULL;

		if ($info && !is_array($info)) $info['name'] = $info;

		if (substr($vfolder_path,0,1)!='/') {
			self::$error = "media::new_vfolder error: vfolder_path '{$vfolder_path}' must begin with '/'.";
			return false;
		}//if

		$vfolder_path = strtolower($vfolder_path);

		$path_pieces = explode('/',$vfolder_path);
		unset($path_pieces[0]);

		// check to see if this vfolder already exists
		$aql = "media_vfolder {
					id
					where lower(media_vfolder.vfolder_path) = '{$vfolder_path}'
				}";
		$rs = aql::select($aql);
		if ($rs[0]['id']) {
			self::$error = "media::new_vfolder error: vfolder_path '{$vfolder_path}' already exists.";
			return false;
		} else {
			$path = NULL;
			foreach ( $path_pieces as $piece ) {
				$path .=  '/' . $piece;

				// check to see if each path component of the heirarchy exists
				$aql = "media_vfolder {
							id, parent__media_vfolder_id, tree
							where media_vfolder.vfolder_path = '{$path}'
						}";
				$rs = aql::select($aql);
				if (!$rs[0]['id']) {

					$i['vfolder_path'] = $path;
					$i['parent__media_vfolder_id'] = $parent;
					if (!$tree) $tree = ',';
					$i['tree'] = $tree;

					// don't insert any extended info for parent vfolders that need to be created
					// just insert the extended info for the actual vfolder we are creating
					if ( $path == $vfolder_path ) {
						$i['name'] = substr($info['name'],0,100);
						$i['description'] = $info['description'];
						$i['album_date'] = $info['album_date'];
						$i['venue_id'] = $info['venue_id'];
					}//if

					$rs = aql::insert('media_vfolder',$i);

					// increment the vfolder count for the parent vfolder
					if (is_numeric($parent)) {
						$SQL = "update media_vfolder
								set num_vfolders = num_vfolders + 1
								where id = $parent";
						$dbw->Execute($SQL) or die("$SQL<br>".$dbw->ErrorMsg());
					}//if
				}//if

				// set the parent and tree for the next level
				$parent = $rs[0]['id'];
				$tree = $rs[0]['tree'] . $parent . ',';

			}//foreach
		}//if
		return $parent;
	}//function new_vfolder


/**
 * retrieve all the information for a given vfolder and all of the items add/or subvfolders based on
 *	the given vfolder identifier.  valid vfolder identifiers: id, ide, vfolder_path
 *
 * @param string $identifier the id, ide, or vfolder_path of the vfolder to retrieve
 * @param integer $max_items (optional) max number of items to retrieve
 * @param integer $offset (optional) number of items to bypass before retrieving items, useful for pagination
 * @return array returns false on error or an array of information on success
 */
	function get_vfolder($identifier, $max_items=NULL, $offset=0, $order_by=NULL, $parameters=NULL) {
		global $db;
		self::$error = NULL;

		//if ($_GET['d']) echo exec_time();

		if (is_numeric($identifier)) {
			$aql = "media_vfolder {
						*
						where media_vfolder.id = {$identifier}
					}";
			$vf = aql::select($aql);
		} else if (substr($identifier,0,1)=='/') {
			$aql = "media_vfolder {
						*
						where vfolder_path = '{$identifier}'
					}";
			$vf = aql::select($aql);
		} else {
			$identifier = decrypt($identifier,'media_vfolder');
			if (is_numeric($identifier)) {
				$aql = "media_vfolder {
							*
							where media_vfolder.id = {$identifier}
						}";
				$vf = aql::select($aql);
			}//if
		}//if

		//if ($_GET['d']) echo exec_time();

		if (!$vf[0]['id']) {
			self::$error = "media::get_vfolder() error: invalid identifier specified";
			return false;
		}//if

		// count the number of items in this vfolder
		// TODO: this can be removed when the num_items field is up to date
		$aql = "media_item {
					count(*) as count
					where media_vfolder_id = {$vf[0]['id']}
				}";
		$rs = aql::select($aql);
		$num_items = $rs[0]['count'];
		$vf[0]['num_items'] = $num_items;

		// allow extreme offsets to "wrap"
		if ($num_items):
			$offset = $offset % $num_items;
			if ( $offset < 0 ) $offset = $num_items + $offset;
		endif;

		if (is_numeric($max_items)) $limit = "limit $max_items";
		#else if ( !$order_by ) $order_by = 'iorder asc, views desc'; // don't auto-orderby if we're selecting with a limit, postgres is kinda retarded here
		if ( !$order_by ) $order_by = 'iorder asc, views desc';

		if ($order_by) $order_by = 'order by ' . $order_by;

		// if we are only looking for images larger than the specified dimensions
		if ($parameters['min_width'] || $parameters['min_height']) {
			$min_criteria = '';
			if ($parameters['min_width']) $min_criteria .= " and media_instance.width >= {$parameters['min_width']} ";
			if ($parameters['min_height']) $min_criteria .= " and media_instance.height >= {$parameters['min_height']} ";
			$media_instance_aql = "
				media_instance {
					id
					where media_instance.instance is null
					{$min_criteria}
				}
			";
		}//if

		// get all the items in this vfolder
		$aql = "media_item {
					*
					where media_vfolder_id = {$vf[0]['id']}
					$order_by
					offset $offset
					$limit
				}
				{$media_instance_aql}";
// 					order by media_item.iorder asc, media_item.views desc
		$vf[0]['items'] = aql::select($aql);
		$vf[0]['sql'] = aql::sql($aql);

		//if ($_GET['d']) echo exec_time();

		// get all the subvfolders in this vfolder
		$aql = "media_vfolder {
					*
					where parent__media_vfolder_id = {$vf[0]['id']}
				}";
		$vf[0]['vfolders'] = aql::select($aql);

		return $vf[0];
	}//function get_vfolder


/**
 * display an uploader using SWF Uploader
 *	There is a single array parameter:
 *	REQUIRED: vfolder_path
 *	OPTIONAL:	thumb_width, thumb_height, thumb_crop,
 				max_num_files, max_file_size (in kilobytes),
				empty_message,
				file_types, file_types_description,
 				db_field, db_row_id,
				on_success_js
 *
 * @param array $offset (optional) number of items to bypass before retrieving items, useful for pagination
 */
	function uploader($settings) {
		$media_upload = $settings;

                $context = $settings['context'];

		$media_upload['delete'] = true;
		if ( !isset($media_upload['gallery']) ) $media_upload['gallery'] = true;
		$media_upload['unique_uploader_id'] = rand(0,999999999);
		if (!$media_upload['no_js']) {
			echo "<script type=\"text/javascript\">add_javascript('/pages/media/easyupload/easyupload.js');</script>";
			echo "<script type=\"text/javascript\">add_css('/pages/media/easyupload/easyupload.css');</script>";
			echo "\n\n";
		}
		echo "<div style=\"".$media_upload['gallery_style']."\" id=\"upload-items-".$media_upload['unique_uploader_id']."\">";
		if ( $media_upload['gallery'] ) include('pages/media/easyupload/items.php');
		echo "</div>";
		echo "<div class=\"clear\"></div>";
		if (!$media_upload['on_success_js']) $media_upload['on_success_js'] = "get_vfolder_items('{$media_upload['vfolder_path']}','{$media_upload['unique_uploader_id']}','{$media_upload['thumb_width']}','{$media_upload['thumb_height']}','{$media_upload['thumb_crop']}');";
		if (!$media_upload['vfolder_js']) $media_upload['vfolder_js'] = "'".$media_upload['vfolder_path']."'";
        if ($_GET['media_debug']) $media_upload['debug'] = true;
		include('modules/media/upload/upload.php');
	}//function uploader


//	function local_path() {
//		return $sky_media_path . $vfolder_path . $instance . $slug . '.' . $file_type;
//	}//function local_path


	function gallery($settings){

           $grid_margin=$settings['grid_margin']?$settings['grid_margin']:0;
           $edge_padding=$settings['edge_padding']?$settings['edge_padding']:0;

           $gallery_grid_x = $settings['grid_x']?$settings['grid_x']:4;
           $gallery_grid_y = $settings['grid_y']?$settings['grid_y']:3;

           $id = $settings['id']?$settings['id']:'gallery'.rand(0,10000);
           $class = $settings['class']?$settings['class']:'gallery';
           $file = $settings['file']?$settings['file']:'pages/media/gallery/gallery.php';
           $duration = $settings['duration']?$settings['duration']:4000;
           $transition = $settings['transition']?$settings['transition']:"fade";

           $height_pane = $settings['height_pane']?$settings['height_pane']:300;
           $width_pane = $settings['width_pane']?$settings['width_pane']:475;

           $height_grid = $settings['height_grid']!=NULL?$settings['height_grid']:(56+$grid_margin)*$gallery_grid_y;
           $width_grid = $settings['width_grid']!=NULL?$settings['width_grid']:$width_pane;

           $height = $settings['height']?$settings['height']:$height_pane+$height_grid+(2*$edge_padding);
           $width = $settings['width']<($greatest=($width_grid>$width_pane?$width_grid:$width_pane)) || !$settings['width']?$greatest+(2*$edge_padding):$settings['width'];

           $height_thumb = ($height_grid-($gallery_grid_y*$grid_margin))/$gallery_grid_y;
           $width_thumb = ($width_grid-($gallery_grid_x-1)*$grid_margin)/$gallery_grid_x;

           $height_enlarged = $settings['height_enlarged']?$settings['height_enlarged']:800;
           $width_enlarged = $settings['width_enlarged']?$settings['width_enlarged']:800;

           $speed = $settings['speed']?$settings['speed']:'null';
           $easing = $settings['easing']?$settings['easing']:'null';

           $vfolder_id = $settings['vfolder']?$settings['vfolder']:null;

           $arrows_wrap = $settings['arrows_wrap']===false?true:($settings['arrows_wrap']?$settings['arrows_wrap']:false);

           $css = $settings['css']?$settings['css']:null;

           $disable_enlarge = $settings['disable_enlarge']?$settings['disable_enlarge']:null;

           $crop_gravity = $settings['crop_gravity']?$settings['crop_gravity']:'center';

           $vfolder = media::get_vfolder($vfolder_id,$gallery_grid_x*$gallery_grid_y);

           include_once('pages/media/gallery/init.php');

           ?><script type='text/javascript'>
           var <?=$id ?>_galleryVars = new Array();<?
           $parameters = array('crop_gravity'=>$crop_gravity, 'force_crop'=>true, 'upsize'=>true);
           $index=0;
           if(is_array($vfolder['items'])){

           $num_items = count($vfolder['items']);
           $num_actual_rows = ceil($num_items/$gallery_grid_x);

           if($num_actual_rows != $gallery_grid_y){
              $gallery_grid_y = $num_actual_rows;
              $height_grid = (56+$grid_margin)*$gallery_grid_y;
              $height = $height_pane+$height_grid+(2*$edge_padding);
           }

           if(count($vfolder['items'])==1){$arrows_wrap=true;}
           foreach($vfolder['items'] as &$item){
              $item['image']=media::get_item($item['id'],$width_thumb,$height_thumb,true,NULL,$parameters);
              $item['bigimage']=media::get_item($item['id'],$width_pane,$height_pane,true,NULL,$parameters);
              $item['enlargedimage']=media::get_item($item['id'],$width_enlarged,$height_enlarged);
              ?>
              <?=$id ?>_galleryVars[<?=$index++ ?>]={
                 "bigimage" : "<?=$item['bigimage']['src'] ?>" ,
                 "enlargedimage" : "<?=$item['enlargedimage']['src'] ?>",
                 "enlargedimage_width" : <?=$item['enlargedimage']['width'] ?>
              };
              <?

              if($_GET['debug']){
                 var_dump($item);
              }
           }

           $gallery_images = $vfolder['items'];

           ?>
                 var arrows_wrap = <?=$arrows_wrap?"true":"false"?>;
                 <?=$id ?>_galleryVars['grid_x']=<?=$gallery_grid_x ?>;
                 <?=$id ?>_galleryVars['transition']="<?=$transition ?>";
                 <?=$id ?>_galleryVars['duration']=<?=$duration ?>;
                 <?=$id ?>_galleryVars['speed']=<?=$speed ?>;

                 <? if($easing!='null'){ ?>
                    <?=$id ?>_galleryVars['easing']=<?$easing ?>;
                 <? } ?>

                 add_js("/lib/js/jquery/jquery.easing.1.1.1.js");
                 add_js("/lib/js/jquery/jquery.cycle.all.js");
		 add_js("/pages/media/gallery/gallery.js");//.php?id=<?=$id ?>");
                 <? if (!$settings['style_sheet']): ?> add_css("/pages/media/gallery/gallery.css");//.php?id=<?=$id ?>");
				 <? else: ?> add_css("<?=$settings['style_sheet']?>");//.php?id=<?=$id ?>");
				 <? endif; ?>
              </script>
              <style type='text/css'>
                 /*dimensions*/
                 <?=$arrows_wrap?"#{$id}_gallery_pane_wrap.onlast .arrow_wrap_right{display:none;} #{$id}_gallery_pane_wrap.onfirst .arrow_wrap_left{display:none;}":""?>

                 #<?=$id ?>_gallery{height: <?=$height ?>px ; width: <?=$width ?>px;}
                 #<?=$id ?>_gallery_pane,#<?=$id ?>_gallery_pane_wrap{height: <?=$height_pane ?>px; width: <?=$width_pane ?>px;}
                 .<?=$id ?>_grid_cell{height: <?=$height_thumb ?>px; width: <?=$width_thumb ?>px;}
                 #<?=$id ?>_gallery_grid{width: <?=$width_grid ?>px;}

                  /*margins and paddings*/
                 .<?=$id ?>_grid_cell{margin-right: <?=$grid_margin ?>px;}
                 .<?=$id ?>_grid_row{padding-bottom: <?=$grid_margin ?>px;}
                  #<?=$id ?>_gallery_pane,#<?=$id ?>_gallery_pane_wrap{padding-top: <?=$edge_padding ?>px; padding-bottom: <?=$grid_margin ?>px;}
                  #<?=$id ?>_gallery_grid{padding-bottom: <?=$edge_padding ?>px;}

              </style>

           <?

           if(is_array($css)){
           ?> <style type='text/css'> <?
           foreach($css as $key => $style){
              ?>
                    <?=$key ?> { <?=$style ?>; }
              <?
           }//foreach
           ?> </style> <?
           }//if

	   include($file);

           }//if(is_array($vfolder['items']))
	}// function gallery

        function slideshow($settings){
           $debug = $settings['debug'];

           $file = $settings['file']?$settings['file']:'pages/media/slideshow/slideshow.php';

           $number_thumbs = $settings['number_thumbs']?$settings['number_thumbs']:NULL;
           $thumb_width = $settings['thumb_width']?$settings['thumb_width']:NULL;
           $thumb_height = $settings['thumb_height']?$settings['thumb_height']:NULL;
           $selected_width = $settings['selected_width']?$settings['selected_width']:600;
           $selected_height = $settings['selected_height']?$settings['selected_height']:800;
           $strip_width = $settings['strip_width']?$settings['strip_width']:NULL;
           $strip_height = $settings['strip_height']?$settings['strip_height']:NULL;
           $control_width = $settings['control_width']?$settings['control_width']:30;

           $thumb_margin = defined($settings['thumb_margin'])?$settings['thumb_margin']:0;

           $media_vfolder_id = $settings['media_vfolder']?$settings['media_vfolder']:NULL;
           $max_items = $settings['vfolder_max_items']?$settings['max_items']:25;
           $offset = $settings['vfolder_offset']?$settings['vfolder_offset']:0;
           $order_by = $settings['vfolder_order_by']?$settings['vfolder_order_by']:NULL;

           $show_captions = defined($settings['show_captions'])?$settings['show_captions']:true;

           $hide_name = defined($settings['hide_name'])?$settings['hide_name']:true;
           $album = $settings['album']?$settings['album']:NULL;  //used in conjunction with hide_name=false

           $thumb_crop = defined($settings['thumb_crop'])?$settings['thumb_crop']:true;
           $thumb_crop_gravity = array('crop_gravity'=> $settings['thumb_crop_gravity']?$settings['thumb_crop_gravity']:'north' );
           $selected_crop = defined($settings['selected_crop'])?$settings['selected_crop']:false;
           $selected_crop_gravity = array('crop_gravity'=> $settings['selected_crop_gravity']?$settings['selected_crop_gravity']:'north' );

           $wrap = defined($settings['wrap'])?$settings['wrap']:true;

           $transition = $settings['transition']?$settings['transition']:'fade';
           $speed = $settings['speed']?$settings['speed']:'null';
           $easing = $settings['easing']?$settings['easing']:'null';

           $class = $settings['class']?$settings['class']:'blog_slideshow';
           $id = $settings['id']?$settings['id']:'media_slideshow_'.rand(0,1000000);
           $css = $settings['css']?$settings['css']:null;

           if(!$strip_height){
              $strip_height = $thumb_height?$thumb_height:$thumb_height=82;
           }

           if(!$strip_width){
              $strip_width = $thumb_width && $number_thumbs ? ($thumb_width*$number_thumbs)+(($number_thumbs-1)*$thumb_margin)+(2*$control_width) : 640;

              if(!$thumb_width && !$number_thumbs){
                 $number_thumbs = 7;
              }

              if(!$thumb_width && $number_thumbs){
                 $thumb_width = floor(($strip_width-(($number_thumbs-1)*$thumb_margin))/$number_thumbs);
              }else{
                 $number_thumbs = (($strip_width-$thumb_width)/($thumb_width+$thumb_margin))+1;
              }
           }

           $vfolder = media::get_vfolder($media_vfolder_id);

           if($debug){
              ?><!-- <? var_dump($vfolder); ?> --><?
           }

           $media_images = array();

           foreach($vfolder['items'] as $item){
              $media_images[]=media::get_item($item['media_item_id'],$selected_width,$selected_height,$selected_crop,NULL,$selected_crop_gravity);
           }

           include_once('pages/media/slideshow/init.php');

           ?><script type='text/javascript'>
                if(media_slideshows['<?=$id?>']){
                   if(<?=$debug?'true':'false'?>){
                      alert("Duplicate blog_slideshow id: <?=$id?>\n\n"+debug_footer_msg);
                   }
                }
                media_slideshows['<?=$id?>'] = {
                   'images': new Array(),
                   'speed': '<?=$speed?>',
                   'easing': '<?=$easing?>',
                   'transition': '<?=$transition?>',
                   'wrap': <?=$wrap?'true':'false'?>,
                   'show_captions' : <?=$show_captions?'true':'false'?>,
                   'debug': <?=$debug?'true':'false'?>
                };

               <?
               foreach($media_images as $count=>&$media_image){
                  $media_image['thumb']=media::get_item($media_vfolder['items'][$count]['media_item_id'],$thumb_width,$thumb_height,$thumb_crop,NULL,$thumb_crop_gravity);

                  ?>
                  media_slideshows['<?=$id?>']['images'][<?=$count?>]={
                     'src': '<?=$media_image['src']?>',
                     'thumb': '<?=$media_image['thumb']['src']?>',
                     'caption': '<?=htmlspecialchars($media_image['caption'],ENT_QUOTES)?>'
                  };
                  <?
               }

           ?></script><?

           if(is_array($css)){
              ?> <style type='text/css'> <?
              foreach($css as $key => $style){
                 ?>
                    <?=$key ?> { <?=$style ?>; }
                 <?
              }//foreach
              ?> </style> <?
           }//if

           include($file);
        }

}//class media


// constant array used by the class
$imagetype[1] = 'gif';
$imagetype[2] = 'jpg';
$imagetype[3] = 'png';
$imagetype[4] = 'swf';
//$imagetype[5] = 'psd';
$imagetype[6] = 'bmp';
$imagetype[7] = 'tiff(intel byte order)';
$imagetype[8] = 'tiff(motorola byte order)';
$imagetype[9] = 'jpc';
$imagetype[10] = 'jp2';
$imagetype[11] = 'jpx';
$imagetype[12] = 'jb2';
$imagetype[13] = 'swc';
$imagetype[14] = 'iff';
$imagetype[15] = 'wbmp';
$imagetype[16] = 'xbm';
$imagetype[17] = 'jpeg';


?>
