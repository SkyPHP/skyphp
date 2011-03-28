<?
class snippet {
	
/**
 * output tabs
 * @param array $tabs key is the label of the tab, value is the href
 * @param string $selected_substring_match if this substring is present in the tab's link, then this tab is selected
 */
	function tabs( $tabs, $selected_substring_match=NULL, $params=NULL ) {
		if ( !$params['div_class'] ) $params['div_class'] = 'tabs';
		if ( $params['div_id'] ) $params['div_id'] = 'id="'.$params['div_id'].'"';
		if ( $params['onclick'] ) $onclick = 'onClick="'.$params['onclick'].'"';
?>	
	<div <?=$params['div_id']?> class="<?=$params['div_class']?>">
	   <ul><?
		if ( $selected_substring_match === true ) $selected_substring_match = $_SERVER['REQUEST_URI'];
		if (is_array($tabs))
		foreach ($tabs as $label => $href) {
			//if (strpos('/'.$_POST['sky_path'],$href)!==false) $tab_on = true;
			if ( $href == '/' ) {
				if ($selected_substring_match == '/') $tab_on = true;
				else $tab_on = false;
			} else if ( $selected_substring_match && $selected_substring_match != '/' && ( strpos($href,$selected_substring_match) !== false || strpos($selected_substring_match,$href) !== false ) ) $tab_on = true;
			else if ( $_SERVER['REQUEST_URI']==$href || $_SERVER['PATH_INFO']==$href || $_SERVER['PATH_INFO'].'/'==$href ) $tab_on = true;
			else $tab_on = false;
?> 
		<li<? if ($tab_on) { ?> class="tab_on"<? }?>><?
			if ($tab_on) {
				?><a class="active-tab" <?=$onclick?> id="tab-<?=strtolower(str_replace(' ','-',$label))?>" href="<?=$href?>"><?=$label?></a><?
			} else {
				?><a href="<?=$href?>" <?=$onclick?> id="tab-<?=strtolower(str_replace(' ','-',$label))?>"><?=$label?></a><?
			}//if
		?></li><?
		}//foreach
?> 
	   </ul>
	</div>
<?

	}//function

//  this function assumes the tabs are /page/abc NOT /page?tab=abc
	function tab_redirect($tabs) {
		foreach ($tabs as $key => $href) {
			$href = explode('?',$href);
			$tab_folder = $href[0] . '?';
			$uri = explode('?',$_SERVER['REQUEST_URI']);
			$uri = $uri[0] . '?';
			//echo $uri . ' ' . $tab_folder . '<br />';
			if ( strpos( $uri, $tab_folder ) !== false ) return false;
			//if ( ereg( $value . '.*', $_SERVER['REQUEST_URI'] ) ) return false;
			//if ( ereg( str_replace('?','\?',$value) . '.*', $_SERVER['REQUEST_URI'] ) ) return false;
		}//foreach
		redirect($tabs[getArrayFirstIndex($tabs)],302);
	}//function



/**
 * output a select/option html dropdown using the provided array
 * @param array $array of key/value pairs
 * @param array $param array of parameters: i.e. name, selected_value, onchange
 * @return true on success
 */
	function dropdown($array,$param=NULL) {
		$name = $param['name'];
		if (!$param['id']) $param['id'] = $name;
		echo '<select name="' . $name . '" id="' . $param['id'] . '"' . '" class="' . $param['class'] . '"';
		if ($param['onchange']) echo ' onchange="' . $param['onchange'] . '"';
		if ($param['class']) echo ' class="' . $param['class'] . '"';
		echo '>' . "\n";
		if ( $param['null_option'] !== false ) echo "\t" . '<option value="">' . $param['null_option'] . '</option>';
		foreach ($array as $value => $option) {
			echo "\t" . '<option value="' . $value . '"';
			if ($param['selected_value'] && $param['selected_value'] == $value) echo ' selected ';
			echo '>' . $option . '</option>' . "\n";
		}//foreach
		echo "</select>\n";
	
	}//function
/*
<?	// SAMPLE CODE FOR USING DROPDOWN
	if (!$_SESSION['market_id']) $_SESSION['market_id'] = 1;
	$_SESSION['market_ide'] = encrypt($_SESSION['market_id'],'market');
	$aql = "market {
				name,
				state,
				country_code
				where market.active = 1
				order by name asc
			}";
	$rs = aql::select();
	foreach ($rs as $market) {
		$market_name = $market['name'];
		if ($market['country_code'] != 'US') $market_name .= ' (' . $market['country_code'] . ')';
		$dd[$market['market_ide']] = $market_name;
	}//foreach
	$param = array(
		'name' => 'market',
		'selected_value' => $_SESSION['market_ide'],
		'onchange' => 'changeCity();'
	);
	snippet::dropdown($dd,$param);
?>
*/


	function radio ($params) {
	/* ARRAY OF PARAMS
	 * name
	 * value
	 * label		(optional) NOTE: use either label or multi_label... not both
	 * multi_label	(optional but must include radio_id) ---- This is for radio buttons with the same name
	 * onclick
	 * radio_id		(must be used with multi_label)
	 * radio_class
	 * label_class
	 * checked  	(false)  "selected" is an alias for checked
	 * onclick  	(NULL)
	 * disabled 	(NULL) 
	 *
	 */	
	 if ( !$params['checked'] && $params['selected'] ) $params['checked'] = $params['selected'];
?>
	<input	type="radio"
			name="<?=$params['name']?>"
			value="<?=$params['value']?>" 
			id="<?=$params['radio_id']?>"
			class="<?=$params['radio_class']?>" 
			onclick="<?=$params['onclick']?>"
			<? if ($params['disabled']) echo 'disabled="disabled"'; ?>
			<? if ($params['checked']) echo 'checked="checked"';?>
	>
<?
		if ($params['label']) {
?>
		<a	href="javascript:void(null);"
			onclick="setCheckedValue(getElementsByName('<?=$params['name']?>'),'<?=$params['value']?>'); <?=$params['onclick']?>"
			class="<?=$params['label_class']?>"
		><?=$params['label']?></a>
<?
		}//if
		else if ($params['multi_label'] && $params['radio_id']) {
?>
			<a	href="javascript:void(null);"
			onclick="setCheckedValue(getElementById('<?=$params['radio_id']?>'),'<?=$params['value']?>'); <?=$params['onclick']?>"
			class="<?=$params['label_class']?>"
		><?=$params['multi_label']?></a>
<?		
		} // else if
	}//function


	/* ARRAY OF PARAMS
	 * label
	 * id
	 * name
	 * checked
	 * onclick
	 * y_value
	 * n_value
	 * label_class
	 * disabled
	 * rand
	 */
	function checkbox ($params) {
		if (!$params['id']) $params['id'] = $params['name'];
                if (!$params['y_value']) $params['y_value'] = 1;
                if (!$params['n_value']) $params['n_value'] = 0;
?>
		<input type="hidden" name="<?=$params['name']?>" value="<?=$params['n_value']?>" />
		<input
			type="checkbox" 
			name="<?=$params['name']?>" 
			value="<?=$params['y_value']?>"
			id="<?=$params['id']?>" 
			onclick="<?=$params['onclick']?>"
			<? if ($params['checked']) echo 'checked="checked"'; ?> 
			<? if ($params['disabled']) echo 'disabled="disabled"'; ?> 
			style="display:inline;"
		/>
		<label for="<?=$params['id']?>" class="checkbox-label"><?=$params['label']?></label>
		
<?
	}//function



	function time_picker($arr) {
		$rand = rand(0,999999);
		if (!$arr['x']) $arr['x'] = -180;
		if (!$arr['y']) $arr['y'] = -90;
		//if (!$arr['selected_time']) $arr['selected_time'] = '1:00 am';
		$temp = explode(':',$arr['selected_time']);
		if (!$arr['hour']) $arr['hour'] = $temp[0];
		if (!$arr['minute']) $arr['minute'] = substr($temp[1],0,2);
		if (!$arr['ampm']) $arr['ampm'] = trim(substr($temp[1],2));
		if ($arr['ampm']) $arr['hour'] += 12;
?>
		<script type="text/javascript">
		window.addEvent("domready", function (){
			var tp1 = new TimePicker('time_picker_<?=$rand?>', '<?=$arr['name']?>', 'time_toggler_<?=$rand?>',{
				imagesPath:"/lib/time_picker/time_picker_files/images", 
				offset:{x:<?=$arr['x']?>, y:<?=$arr['y']?>}
<?
		if ($arr['selected_time']) {
?>
				, selectedTime:{hour:<?=$arr['hour']?>, minute:<?=$arr['minute']?>}
				, startTime:{hour:<?=$arr['hour']?>, minute:<?=$arr['minute']?>}
<?
		}//if
?>
			});
		});
		</script>
		<input type="text" name="<?=$arr['name']?>" id="<?=$arr['name']?>" style="width:65px;" value="<?=$arr['selected_time']?>" />
		<a href="javascript:void(null);" id="time_toggler_<?=$rand?>"><img src="/images/clock_icon.gif" border="0" align="absmiddle"></a> 
		<span style="font-size:10px; color:#999999;">hh:mm am</span>
		<span id="time_picker_<?=$rand?>" class="time_picker_div"></span>
<?
	}//function


	function time_picker2($arr) {
		$rand = rand(0,999999);
		if (!$arr['x']) $arr['x'] = -180;
		if (!$arr['y']) $arr['y'] = -90;
		//if (!$arr['selected_time']) $arr['selected_time'] = '1:00 am';
		$temp = explode(':',$arr['selected_time']);
		if (!$arr['hour']) $arr['hour'] = $temp[0];
		if (!$arr['minute']) $arr['minute'] = substr($temp[1],0,2);
		if (!$arr['ampm']) $arr['ampm'] = trim(substr($temp[1],2));
		if ($arr['ampm']) $arr['hour'] += 12;
?>
		<script type="text/javascript">
			add_javascript('/lib/js/jquery/jquery.timePicker.js');
		</script>
		<script type="text/javascript">
		jQuery(function() {
			$("#<?=$arr['name']?>").timePicker({
				//startTime: "9:30 PM", // Using string. Can take string or Date object.
				//endTime: "11:30 PM",
				show24Hours: false,
				separator: ':',
				step: 15
			});
		});
		</script>
		<input name="<?=$arr['name']?>" id="<?=$arr['name']?>" style="width: 65px;" value="<?=$arr['selected_time']?>" type="text">
		<span style="font-size:10px; color:#999999;">hh:mm am</span>

<?
	}//function


	function youtube_embed($url, $width = NULL, $height = NULL, $silent = NULL) {
		// get ID from youtube URL
		// http://www.youtube.com/watch?v=cEVCjUG1Mww
		$ar = explode('?', $url);
		$params = parse_querystring($ar[1]);
		$id = $params['v'];

        $ytqs = 'fs=1&rel=0&showinfo=0&color1=0xffffff&color2=0xffffff&hd=0&hl=en_US';

		if (!$width) $width = 560;
		if (!$height) $height = 340;
		
		if ($id) {
			$embed = 	'<object width="'.$width.'" height="'.$height.'">'.
						'<param name="movie" value="http://www.youtube.com/v/'.$id.'?'.$ytqs.'"></param>'.
						'<param name="allowFullScreen" value="true"></param>'.
						'<param name="allowscriptaccess" value="always"></param>'.
						'<embed src="http://www.youtube.com/v/'.$id.'?'.$ytqs.'" type="application/x-shockwave-flash" allowscriptaccess="always" allowfullscreen="true" width="'.$width.'" height="'.$height.'"></embed></object>';
			if ($silent) return $embed;
			else echo $embed;
		} else {
			return false;
		}
	}


}//class
?>