<?


    if ( !function_exists('gethostname') ) {
        function gethostname() {
            return exec('hostname');
        }
    }

    function mem( $key, $value='§k¥', $duration=null ) {
        global $memcache, $is_dev;
        if ( !$memcache ) return false;
        if ( $value == '§k¥' ) {
            elapsed("begin mem-read($key)");
            // get the value from memcached
            if ( $_GET['mem_debug'] && $is_dev ) echo "mem-read( $key )<br />";
            $value = $memcache->get($key);
            elapsed("end mem-read($key)");
            return $value;
        } else if ( $value !== NULL ) {
            // save the value to memcached
            if ($duration) {
                $time = time();
                $num_seconds = strtotime('+'.$duration,$time) - $time;
            }
            $success = $memcache->replace( $key, $value, null, $num_seconds );
            if( !$success ) {
                $success = $memcache->set( $key, $value, null, $num_seconds );
            }
            if ( $_GET['mem_debug'] && $is_dev ) {
                if (is_object($value)) $value = '[Object]';
                echo "mem-write( $key, $value, $duration )<br />";
            }
            return $success;
        } else {
            return $memcache->delete( $key );
        }
    }

    function disk( $file, $value='§k¥', $duration='30 days' ) {
        global $skyphp_storage_path;
        $file = implode('/',array_filter(explode('/',$file)));
        $cache_file = $skyphp_storage_path . 'diskcache/' . $file;
        //echo 'cachefile: ' . $cache_file . '<br />';
        if ( $value == '§k¥' ) { // read
            if ( is_file($cache_file) && filesize($cache_file) ) {
                // if the file exists, open the file and get the expiration time
                $fh = fopen($cache_file, 'r');
                $value = fread($fh, filesize($cache_file));
                $needle = "\n";
                $break = strpos($value,$needle);
                $expiration_time = substr($value,0,$break);
                $value = substr($value,$break+strlen($needle));
                fclose($fh);
                if ( $expiration_time > time() ) {
                    // if the file is not expired, return the value
                    return $value;
                } else {
                    // file is expired, delete the file
                    unlink($cache_file);
                }
            }
            return false;
        } else { // write
            // set the value on disk
            $expiration_time = strtotime($duration);
            $value = $expiration_time . "\n" . $value;
			$end = strrpos($cache_file,'/');
			$cache_dir = substr($cache_file,0,$end);
            //echo 'cachedir: ' . $cache_dir . "<br />";
			@mkdir($cache_dir,0777,true);
			touch($cache_file);
			$fh = fopen($cache_file, 'w') or die("can't open cache file");
			fwrite($fh, $value);
			fclose($fh);
            return true;
		}
    }

    /*
     * check if a disk cache is available
     */
    function disk_check($key) {

    }


function collection( $model, $clause, $duration=null ) {
    $key = "aql:get:$model:".substr(md5(serialize($clause)),0,250);
    $collection = mem( $key );
    if ( !$collection ) {
        $aql = aql::get_aql($model);
        // make minimal sql
        $aql_array = aql2array($aql);
        foreach ( $aql_array as $i => $block ) {
            unset($aql_array[$i]['objects']);
            unset($aql_array[$i]['fields']);
            unset($aql_array[$i]['subqueries']);
        }
        $sql_array = aql::make_sql_array($aql_array,aql::check_clause_array($aql_array, $clause));
        $minimal_sql = $sql_array['sql'];
        $r = sql($minimal_sql);
        $collection = array();
        while (!$r->EOF) {
            $collection[] = $r->Fields(0);
            $r->MoveNext();
        }
        #print_a($collection);
        mem( $key, $collection, $duration );
    }
    if (is_array($collection)) {
        //print_a($collection);
        foreach ( $collection as $id ) {
            $obj = model::get($model, $id);
            $ret[] = $obj->dataToArray();
        }
        return $ret;
    } else {
        return false;
    }
}


	function debug($msg=NULL) {
		//if ($_GET['debug'] && auth('developer')) return true;
        if ($_GET['debug']) {
            echo $msg;
            return true;
        } else return false;
	}//function

    function elapsed( $msg ) {
        if ($_GET['elapsed']) {
            global $sky_start_time, $sky_elapsed_count;
            $sky_elapsed_count++;
            echo round(microtime_float()-microtime_float($sky_start_time),3) . ' #' . $sky_elapsed_count . ' - ' . $msg . '<br />';
        }
    }

	function get_codebase_paths() {
		global $codebase_path_arr;
		$codebase_list = array();
		if($codebase_path_arr):
			foreach ( $codebase_path_arr as $path ):
				$version_file = $path . 'version.txt';
				$lines = file($version_file);
				$codebase = array();
                $codebase_name = NULL;
                if (is_array($lines))
				foreach ( $lines as $line ):
					$setting = explode('=',$line);
					$codebase[ trim($setting[0]) ] = trim($setting[1]);
                    if (trim($setting[0])=='codebase') $codebase_name = trim($setting[1]);
				endforeach;
				$codebase['path'] = $path;
				$codebase_list[$codebase['codebase']] = $codebase;
			endforeach;
		endif;
		return $codebase_list;
	}

	function sql($SQL,$dbx=NULL) {
		global $db;
		if (!$dbx) $dbx = $db;
		$r = $dbx->Execute($SQL);
		if ($dbx->ErrorMsg()) die('<div>'.$SQL.'</div><div style="color:red;">' . $dbx->ErrorMsg() . '</div>');
		else return $r;
	}
	
	function sql_array($SQL,$dbx=NULL){
		$r = sql($SQL,$dbx);
		return $r->GetArray();
	}

	
	function inc($relative_file) {
		$bt = debug_backtrace();
		$path = dirname( $bt[0]['file'] );
		include( $path . '/' . $relative_file );
	}
	
	function redirect($href,$type=301) {
		// TODO add support for https
		if ( $href == $_SERVER['REQUEST_URI'] ) return false;
        else header("Debug: $href == {$_SERVER['REQUEST_URI']}");
		
		if (stripos($href,"http://") === false || stripos($href,"http://") != 0)
			if (stripos($href,"https://") === false || stripos($href,"https://") != 0)
				$href = "http://$_SERVER[SERVER_NAME]" . $href;
					
        if ( $type == 302 ) {
            header("HTTP/1.1 302 Moved Temporarily");
            header("Location: $href");
        } else {
            header("HTTP/1.1 301 Moved Permanently");
            header("Location: $href");
        }
		die();
	}//function
	function redir_nosub($href,$type=301) {
		// TODO add support for https
		global $cookie_domain;
		$domain = substr($_SERVER['HTTP_HOST'],strpos($_SERVER['HTTP_HOST'],substr($cookie_domain,1)));
		#exit( 'http://' . $domain . $href);
		if($domain == $_SERVER['HTTP_HOST'])		
			redirect($href,$type);
		else
			redirect('http://' . $domain . $href,$type);
	}
	function redirect_to_subdomain( $subdomain=NULL ) {
		global $cookie_domain, $disable_subdomain_redirect, $primary_subdomain;
		if ($disable_subdomain_redirect) return false;
		if (!$subdomain):
			if ( $primary_subdomain ) $subdomain = $primary_subdomain;
			else $subdomain = 'www';
		endif;
		if ( $_SERVER['HTTP_HOST'] != $subdomain . $cookie_domain ) {
			header ( 'HTTP/1.1 301 Moved Permanently' );
			header ( 'Location: http://' . $subdomain . $cookie_domain . $_SERVER['REQUEST_URI'] );
			die();
		}//if
	}//function
	

	function slugize($name) {
		$name = trim($name);
		$name = str_replace(array(' ','/'),'-',$name);
		$name = ereg_replace("[^A-Za-z0-9\-]", "", $name );
		$name = preg_replace('/\-+/', '-', $name);
		if ( substr($name,0,1) == '-' ) $name = substr($name,1);
        return $name;
	}//function


	function uize($url,$tiny_domain=NULL) {
		global $cookie_domain;
		if ($tiny_domain) $domain = $tiny_domain;
		else if ( substr($cookie_domain,0,1)=='.' ) $domain = substr($cookie_domain,1);
		else $domain = $cookie_domain;
		$aql = "url {
					where url = '$url'
				}";
		$rs = aql::select($aql);
		$url_ide = $rs[0]['url_ide'];
		if (!$rs) {
			$arr = array(
				'url' => $url
			);
			$rs = aql::insert('url',$arr);
			$url_ide = $rs[0]['url_ide'];
		}//if
        return 'http://' . $domain . '/u/' . $url_ide;
	}//function

	function tinyurl($url) {
		global $cookie_domain, $tiny_domain;
		if ($tiny_domain) $domain = $tiny_domain;
		else if ( substr($cookie_domain,0,1)=='.' ) $domain = substr($cookie_domain,1);
		else $domain = $cookie_domain;
		$aql = "url {
                    tinyid
					where url = '$url'
				}";
		$rs = aql::select($aql);
        $tinyid = $rs[0]['tinyid'];
		if (!$rs) {
			$arr = array(
				'url' => $url
			);
			$rs = aql::insert('url',$arr);
            $tinyid = NULL;
        }//if
        $url_id = $rs[0]['url_id'];
        if (!$tinyid) {
            $tinyid = my_base_convert( $url_id, 10, 62);
            aql::update('url',array('tinyid'=>$tinyid),$url_id);
        }
        return 'http://' . $domain . '/' . $tinyid;
	}//function

	
    function get_fb( $setting ) {
        global $facebook_settings, $cookie_domain;
		if ( substr($cookie_domain,0,1)=='.' ) $domain = substr($cookie_domain,1);
		else $domain = $cookie_domain;
        return $facebook_settings[$domain][$setting];
    }

    function geo_distance($lat1,$lng1,$lat2,$lng2)
    { 
        // If 2 coords are the same dist=0 
        if (($lat1 == $lat2) && ($lng1 == $lng2)){ 
            return 0; 
        } 

        // Convert degrees to radians. 
        $lat1=deg2rad($lat1); 
        $lng1=deg2rad($lng1); 
        $lat2=deg2rad($lat2); 
        $lng2=deg2rad($lng2); 

        // Calculate delta longitude and latitude. 
        $delta_lat=($lat2 - $lat1); 
        $delta_lng=($lng2 - $lng1); 

        //Calculate distance based on curvature of the earth. 
        $temp=pow(sin($delta_lat/2.0),2) + cos($lat1) * cos($lat2) * pow(sin($delta_lng/2.0),2); 
        $distance=number_format(3956 * 2 * atan2(sqrt($temp),sqrt(1-$temp)),2,'.',''); 

        return $distance; 
    }

	function print_columns( $rs, $num_columns, $callback ) {

		$count = count($rs);
		if ( !$count ) return false;
		echo '<div class="print_column" style="float:left;">';
		$num_per_column = ceil( $count / $num_columns );
		$counter = 0;
		foreach ( $rs as $row ):
			$counter++;
			if ($counter == $num_per_column):
				$counter = 0;
				echo '</div><div class="print_column" style="float:left;">';
			endif;
			$callback($row);
		endforeach;
		echo '</div><div class="clear"></div>';

	}//print_columns

	function json_headers() {
		header('Cache-Control: no-cache, must-revalidate');
		header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
		header('Content-type: application/json');
	}



/**
 * encrypt a message or value.  useful for hiding the actual ID number of any given record in the database.  numeric ID numbers should never be visible in HTML or XML source code!
 * do not confuse with {@link encode()}
 * <br><br>Example:
 * <code>
 * <?
 * $person_id = 12345;
 * $person_ide = encrypt($person_id,'person'); 
 * // $person_ide: iYq8okuGw9a
 * $temp = decrypt($person_ide,'person'); 
 * // $temp: 12345
 * ?>
 * </code>
 * @version 2.1
 * @param string $message the message or value to encrypt; must only contain visible characters and spaces (i.e. POSIX [:print:])
 * @param string $key the private key, typically the name of the variable is used as the private key
 * @return string returns an encrypted message
 * @see decrypt()
 */
	function encrypt($message, $key=31337) {
		global $sky_encryption_key;
		if (!$key) $key = 31337;
		$temp = $key . $sky_encryption_key;
		if (strlen($temp) > 16) $key = substr(strrev($key),0, 16 - strlen($sky_encryption_key)) . $sky_encryption_key;
		else $key = $temp;
		$iv_size = mcrypt_get_iv_size(MCRYPT_XTEA, MCRYPT_MODE_ECB);
		$iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
		$enc = bin2hex(mcrypt_encrypt(MCRYPT_XTEA, $key, $message, MCRYPT_MODE_ECB, $iv));
		return my_base_convert('1'.$enc,16,62);
	}//function


/**
 * decrypt an encrypted message or value
 * <br><br>Example:
 * <code>
 * <?
 * $person_id = 12345;
 * $person_ide = encrypt($person_id,'person'); 
 * // $person_ide: iYq8okuGw9a
 * $temp = decrypt($person_ide,'person'); 
 * // $temp: 12345
 * ?>
 * </code>
 * @version 2.1
 * @param string $encrypted_message the encrypted message or value
 * @param string $key the private key, typically the name of the variable is used as the private key
 * @return string returns a decrypted message or false when unsuccessful
 * @see encrypt()
 */
	function decrypt($encrypted_message, $key=31337) {
		global $sky_encryption_key;
		if (!$key) $key = 31337;
		$temp = $key . $sky_encryption_key;
		if (strlen($temp) > 16) $key = substr(strrev($key),0, 16 - strlen($sky_encryption_key)) . $sky_encryption_key;
		else $key = $temp;
		$encrypted_message = my_base_convert($encrypted_message,62,16);
		$encrypted_message = substr($encrypted_message,1);
		$iv_size = mcrypt_get_iv_size(MCRYPT_XTEA, MCRYPT_MODE_ECB);
		$iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
		$temp = trim(mcrypt_decrypt(MCRYPT_XTEA, $key, pack("H*", $encrypted_message), MCRYPT_MODE_ECB, $iv));
		$invalids = eregi_replace ("[[:print:]]", "", $temp);
		if (!strlen($invalids)) return $temp;
		else return false;
	}//function


/**
 * convert a number from any base to any other base (max base 62)
 * currently there is no sanity check on input values
 * @version 1.0
 * @param string $numstring case-sensitive alphanumeric input representing a number of any base (base2-base62). lowercase must be used for base11-base36.
 * @param integer $frombase the base of the input given
 * @param integer $tobase the base to convert to
 * @return string returns alphanumeric output representing a number of base n, where n = $tobase
 */
	function my_base_convert($numstring, $frombase, $tobase) {
	   $chars = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
	   $tostring = substr($chars, 0, $tobase);
	   $length = strlen($numstring);
	   $result = '';
	   for ($i = 0; $i < $length; $i++) {
		   $number[$i] = strpos($chars, $numstring{$i});
	   }
	   do {
		   $divide = 0;
		   $newlen = 0;
		   for ($i = 0; $i < $length; $i++) {
			   $divide = $divide * $frombase + $number[$i];
			   if ($divide >= $tobase) {
				   $number[$newlen++] = (int)($divide / $tobase);
				   $divide = $divide % $tobase;
			   } elseif ($newlen > 0) {
				   $number[$newlen++] = 0;
			   }
		   }
		   $length = $newlen;
		   $result = $tostring{$divide} . $result;
	   }
	   while ($newlen != 0);
	   return $result;
	}// function my_base_convert


/**
 * convert a string into HTML character entity references.
 * useful for preventing against email address harvesting spiders.<br> 
 * do not confuse with {@link encrypt()}
 * <br><br>Example usage:
 * <code>
 * <a href="mailto:<?=encode('info@dom.com')?>"><?=encode('info@dom.com')?></a>
 * --
 * HTML source: &#105;&#110;&#102;&#111;&#64;&#100;&#111;&#109;&#46;&#99;&#111;&#109;
 * HTML output: info@dom.com
 * </code>
 * @param string $input 
 * @return string returns a sequence of HTML character entity references
 */
	function encode($input) {
		for ($i=0; $i<strlen($input); $i++) {
			$output .= '&#'.ord(substr($input,$i,1)).';';
		}//for
		return $output;
	}//function
	
	
/**
 * determine if the user currently logged in has access to at least one of the specified access_group(s)
 * <br><br>Example:
 * <code>
 * <?
 * // new style
 * if ( auth( 'staff:intern; photographer:' ) ) echo 'You are either an intern or a photographer.';
 *
 * // old style
 * if (auth("Developer")) {
 *     echo $debug_information;
 * }//if
 * ?>
 * </code>
 * @param string $access_group
 * @param string $access_group...
 * @return boolean returns true if the user is granted access to one or more of the specified access_groups, otherwise returns false
 */
	function auth() {
		for ($i=0; $i < func_num_args(); $i++) {

			$arg = func_get_arg($i);

			if ( !$_SESSION['login']['person_id'] ) return false;
			if ( !$arg ) return true;

			// new method -- check the appropriate keytable on demand
			if ( strpos($arg,':') ):

				return auth_person($arg);
				
			// old method -- for backwards compatibility -- check the session for the desired access group (person.access_group)
			else:
                $arr = explode(',',$arg);
                foreach ($arr as $arg):
                    if ( strpos(strtolower($_SESSION['login']['access_group']),strtolower($arg)) !== false ):
                        return true;
                    endif;
                endforeach;
			endif;

		}//for
		return false;
	}//function 

	function auth_person( $access_level_str, $person_id=NULL ) {
		if (!$person_id) $person_id = $_SESSION['login']['person_id'];
		$access_level_arr = explode(';',$access_level_str);
		//echo $access_level_str . ', ' . $person_id;
		foreach ( $access_level_arr as $access_level ):
			// split the access level into it's 2 components, keytable:access_needed
			$access = explode(':',$access_level,2);
			$keytable = trim($access[0]);
            if (!$keytable) continue;
			$access_needed_arr = my_array_unique( explode(',',$access[1]) );
			if ( $access_needed_arr[0] == '*' ) $access_needed_arr = NULL;
			$aql = "$keytable {
						id, access_group
						where {$keytable}.person_id = {$person_id}
					}";
			$rs = aql::select($aql);
            if (is_array($rs)):
                if ( $access_needed_arr ):
                    foreach ( $rs as $row ): // person could be in the keytable multiple times w/ different access_group values
                        $access_granted_arr = explode(',',$row['access_group']);
                        //echo '<br />access_needed_arr:';
                        //print_a( $access_needed_arr );
                        // return true if the person's keytable.access_group field contains at least one of the values from access_needed_arr
                        foreach ( $access_needed_arr as $access_needed ):
                            $access_needed = trim( $access_needed );
                            foreach( $access_granted_arr as $access_granted ):
                                $access_granted = trim( $access_granted );
                                if ( strtolower($access_granted) == strtolower($access_needed) ) return true;
                            endforeach;
                        endforeach;
                    endforeach;
                else:
                    // return true if the person is in the keytable -- any access group is cool
                    return true;
                endif;
            endif;
		endforeach;
	}//auth_person


    function login_person($person,$remember_me) {
        global $cookie_domain;
        $_SESSION['login']['person_id'] = $person['person_id'];
        $_SESSION['login']['person_ide'] = encrypt($person['person_id'],'person');
        $_SESSION['login']['fname'] = $person['fname'];
        $_SESSION['login']['lname'] = $person['lname'];
        $_SESSION['login']['email'] = $person['email_address'];
        @setcookie('username', $person['username'], time()+5184000, '/', $cookie_domain);
        if ( $remember_me ) @setcookie('password', encrypt($person['password']), time()+5184000, '/', $cookie_domain);
        // log this login
        aql::update( 'person', array('last_login_time'=>'now()'), $person['person_id'] );
    }

/**
 * determine if a file exists relative to the current include_path
 * @param string $file_name
 * @return boolean returns true if the specified file is accessible, false otherwise
 */
	function file_exists_incpath ($file_name, $file_only = false ) {
        if ( !$file_name ) return false;
		$paths = explode(PATH_SEPARATOR, get_include_path());
		foreach ($paths as $path) {
			// Formulate the absolute path
			$fullpath = $path . $file_name;
			// Check it
			if ( $file_only ) {
                if ( is_file($fullpath) ) {
                    debug('file_exists_incpath(file_only): ' . $fullpath . '<br />');
                    return true;
                }
            } else if (file_exists($fullpath)) {
                debug('file_exists_incpath: ' . $fullpath . '<br />');
				return true;
			}//if
		}//foreach
		return false;
	}//function


/**
 * retrieve the HTML source code of a remote web page.
 * <br><br>Example:
 * <code>
 * <?
 * $html_code = GetCurlPage('http://www.google.com/search?q=php+curl');
 * ?>
 * </code>
 * @param string $url the URL of the remote web page of which to retreive HTML source code
 * @param array $post an array containing HTTP POST data
 * @return string returns the HTML source code of the requested URL
 */
function GetCurlPage ($url,$post=NULL) {
 if(function_exists('curl_init')){
  $agent = "Mozilla/5.0 (X11; U; Linux x86_64; en-US; rv:1.9.2.3) Gecko/20100427 Firefox/3.6.3";
  $ref = "http://".$_SERVER['HTTP_HOST'];
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_USERAGENT, $agent);
  curl_setopt($ch, CURLOPT_REFERER, $ref);
  //curl_setopt($ch, CURLOPT_NOPROGRESS, false);
  if ($post!=NULL) curl_setopt($ch, CURLOPT_POSTFIELDS, $post); // add POST fields
  //echo time();
  $tmp = curl_exec ($ch);
  //echo time();
  curl_close ($ch);
  $ret = $tmp;
 }else{
  $ret = @implode('', @file ($url));
 }//if
return $ret;
}//getcurlpage


/**
 * convert an array into a querystring of name-value pairs.  multi-dimensional arrays are supported.
 * <br><br>Example:
 * <code>
 * <?
 * $qs = array_to_querystring($_GET);
 * // $qs: fname=Jane&lname=Doe
 * ?>
 * </code>
 * @param array $array any array
 * @return string returns a sequence of name-value pairs
 * @see parse_querystring()
 */
function array_to_querystring($array,$is_array=false) {
// convert an array into a querystring recursively  i.e. $_POST into $_GET (used to pass $_POST to curl)
	$post = NULL;
	while (is_array($array) && list($var,$val)=each($array)) {
		if (is_array($val)) {
			$post.= $var.array_to_querystring($val,true);
		} else {
			if ($is_array) $post.= "[$var]=$val&";
			else $post.= "$var=$val&";
		}//if
	}//while
	return $post;
}//function

/**
 * convert a querystring into an array.  multi-dimensional arrays are supported.
 * @param string $qs name-value pairs in querystring format
 * @return array returns an array corresponding to the specified name-value pairs
 * @see array_to_querystring()
 */
function parse_querystring($qs) {
	$a = explode('&',$qs);
	while (list($var,$val)=each($a)) {
		$temp = explode('=',$val,2);
		$arr[$temp[0]] = $temp[1]; 
	}//while
	return $arr;
}//if



function getArrayFirstIndex($arr) {
	foreach ($arr as $key => $value) return $key;
}//function



function print_value($field_name,$s=NULL) {
	global $r, $action, $global_print_value_override;
	if ($_POST[$field_name]) return $_POST[$field_name];
		if ($s) {
			return $s->Fields($field_name);
		} else if ($global_print_value_override) {
			return $global_print_value_override->Fields($field_name);
		} else if ($r) {
			return $r->Fields($field_name);
		} else {
			return NULL;
		}//if
}//function


function microtime_float($microtime=NULL) { 
   if (!$microtime) $microtime = microtime();
   list($usec, $sec) = explode(" ", $microtime); 
   return ((float)$usec + (float)$sec); 
} 

function exec_time() {
	global $sky_start_time;
	$time_start = microtime_float($sky_start_time);
	$time_end = microtime_float();
	$time = $time_end - $time_start;
	return $time . "<br />\n";
} 

/**
 * remove one or more variables from a querystring
 * <br><br>Example:
 * <code>
 * <a href="?page=1&<?=qs_remove('page',$_SERVER['QUERY_STRING'])?>">Page 1</a>
 * // discard the current $_GET['page'], but retain any additional $_GET values
 * <a href="?<?=qs_remove(array('a','b'),'a=1&b=2&c=3')?>">Link</a>
 * // result: href="index.php?c=3"
 * </code>
 * @param string/array $name name(s) of variables to be removed from specified querystring
 * @param string $qs querystring possibly containing specific variables to be removed
 * @return string returns a querystring less the variables specified to be removed
 */
function qs_remove($name, $qs=null) {
//return a querystring $qs with the get-value $x removed
// $x may be an array
// i.e. qs_remove("fname",$_SERVER['QUERY_STRING'])
    if ( !$qs ) $qs = $_SERVER['QUERY_STRING'];
	$x = $name;
	if (is_array($x)) {
		while (list($var,$val)=each($x)) {
			$qs = qs_remove($val,$qs);
		}//while
	return $qs;
	}//if
    parse_str($qs,$parts);
    unset($parts[$name]);
	return http_build_query($parts);
}//qs_remove


/**
 * remove duplicate values and null values from an array. same as array_unique(), except null values are removed
 * <br><br>Example:
 * <code>
 * <?
 * $array_no_dups = my_array_unique($array_with_dups_and_nulls);
 * ?>
 * </code>
 * @link http://www.php.net/array_unique
 * @param array $array the array of which to remove duplicates
 * @return array returns an array free from null values or duplicate values
 */
function my_array_unique($array) {
	if (is_array($array)) {
		$tmparr = array_unique($array);
		$i=0;
		foreach ($tmparr as $v) { 
			if ($v!=NULL) $newarr[$i] = trim($v);
			$i++;
		}//foreach
		return $newarr;
	} else {
		return NULL;
	}//if
}//function

function array_search_recursive( $needle, $haystack, $strict=false, $path=array() )
{
    if( !is_array($haystack) ) {
        return false;
    }
 
    foreach( $haystack as $key => $val ) {
        if( is_array($val) && $subPath = array_search_recursive($needle, $val, $strict, $path) ) {
            $path = array_merge($path, array($key), $subPath);
            return $path;
        } elseif( (!$strict && $val == $needle) || ($strict && $val === $needle) ) {
            $path[] = $key;
            return $path;
        }
    }
    return false;
}

function array_change_key_name( $orig, $new, &$array )
{
    foreach ( $array as $k => $v )
        $return[ $k == $orig ? $new : $k ] = ( is_array( $v ) ? array_change_key_name( $orig, $new, $v ) : $v );
    return $return;
}



/**
 * visually outputs an array as an HTML table.  useful for displaying complex multi-dimensional arrays.
 * <br><br>Example:
 * <code>
 * <?
 * print_a($_SERVER);
 * ?>
 * </code>
 * @param array $TheArray the array to output visually in HTML table format
 */
function print_a( $TheArray ) { 
  if (is_array($TheArray)) {
	?>
	<style>
	a.ide {
		color:blue;
		text-decoration:none;
	}
	a.ide:hover {
		font-weight:bold;
	}
	</style>
	<?
	echo "<table border=\"0\">\n"; 
    $Keys = array_keys( $TheArray ); 
    foreach( $Keys as $OneKey ) 
    { 
      echo "<tr>\n"; 
      echo "<td bgcolor=\"#D1E9D3\" valign=\"top\">"; 
      echo "<b>$OneKey</b>"; 
      echo "</td>\n"; 
      echo "<td bgcolor=\"#EEEECA\" valign=\"top\">"; 
      if ( is_array($TheArray[$OneKey]) )  {
         print_a ($TheArray[$OneKey]); 
//      } else if ($OneKey=='login_password') {
//	  		echo '**********';
	  } else {
		if (substr($OneKey,-4) == '_ide') $TheArray[$OneKey] = ($TheArray[$OneKey]) ? ('<a class="ide" href="/dev/ide/' . $TheArray[$OneKey] . '">' . $TheArray[$OneKey] . '</a>') : "";
		else if (substr($OneKey,-3) == '_id') {
			$tablename = substr($OneKey,0,-3);
			$pos = stripos($tablename,"__");
			if ($pos !== false) $tablename = substr($tablename,$pos + 2);
			$TheArray[$OneKey] = ($TheArray[$OneKey]) ? ('<a class="ide" href="/dev/ide/' . $tablename . '/' . $TheArray[$OneKey] . '">' . $TheArray[$OneKey] . '</a>') : "";
		}
		echo utf8_decode ($TheArray[$OneKey]);
      }//if
	  echo "</td></tr>"; 
    } 
    echo "</table>\n"; 
  } else echo "print_a(): Not an array.";
}//function

/**
 * takes the arguments passed on the command line with the format
 *    --key=value
 * and puts them in the $_GET variable as key/value pairs
 * <br><br>Example:
 * <code>
 * <?
 * set_arg_vars();
 * ?>
 * </code>
 * @return boolean returns false if there is a parsing error
 */
function set_arg_vars() {
    $ARGV = $_SERVER['argv'];
    for ($i=1; $i < count($ARGV); $i++) {
        if (preg_match('/^\-\-([^=]+)=(.*)$/', $ARGV[$i], $matches)) {
            $_GET[$matches[1]] = $matches[2];
        } else {
            return false;
        }//if
    }//for

	return true;
}//function

/**
 * gets the number of arguments passed on the command line after the php filename
 * (will be zero if there are none)
 * <br><br>Example:
 * <code>
 * <?
 * $num_args = count_args();
 * ?>
 * </code>
 * @return integer the number of arguments on the command line
 */
function count_args() {
    $arg_count = count($_SERVER['argv']) - 1;
    if ($arg_count < 0) {
        return 0;
    }//if

    return $arg_count;
}//function

/**
 * runs a PHP script locally and returns the results as a SimpleXMLElement
 * <br><br>Example:
 * <code>
 * <?
 * $xml = get_script_xml('/path/to/script.php', $_GET);
 * ?>
 * </code>
 * @param string $php_file the PHP script to execute locally
 * @param array $args arguments to be passed to the PHP script in key/value pairs
 * @return SimpleXMLElement a link to the root node of the xml tree
 */
function get_script_xml($php_file, $args=array()) {
	$arg_string = "";
	foreach ($args as $key => $val) {
		$arg_string .= " --{$key}={$val}";
	}

	$xmldoc = "";

	exec("php $php_file $arg_string", $lines);
	for ($i=3; $i < count($lines); $i++) { // start on line 3 to skip the PHP headers
		$xmldoc .= $lines[$i];
	}

	if ($_GET['debugxml']) {
		print "<blockquote>$xmldoc</blockquote>";
	}

	return new SimpleXMLElement($xmldoc);
}//function

/**
 * gets the value from an array corresponding with key
 * <br><br>Example:
 * <code>
 * <?
 * $string = "a|b|c|d|e|f";
 * $value = array_get(explode($string, "|"), 3);
 * ?>
 * </code>
 * @param array $array Array to get the value from
 * @param string $key The key to use to obtain the value
 * @return object the
 */
function array_get($array, $key){
	return $array[$key];
}

/**
 * gets the URL of the current page
 * <br><br>Example:
 * <code>
 * <?
 * print self_url();
 * ?>
 * </code>
 * @return string the URL of the current page
 */
function self_url() {
    $s = empty($_SERVER["HTTPS"]) ? '' : ($_SERVER["HTTPS"] == "on") ? "s" : "";
    $protocol = str_left(strtolower($_SERVER["SERVER_PROTOCOL"]), "/") . $s;
    $port = ($_SERVER["SERVER_PORT"] == "80") ? "" : (":" . $_SERVER["SERVER_PORT"]);
    return $protocol . "://" . $_SERVER['SERVER_NAME'] . $port . $_SERVER['REQUEST_URI'];
}

/**
 * gets the IP number corresponding to an ip address
 * for use with ip2location.com's ip address database
 * @return int ip number
 */
function Dot2LongIP($IPaddr) {
    if ($IPaddr == "") {
        return 0;
    } else {
        $ips = split ("\.", "$IPaddr");
        return ($ips[3] + $ips[2] * 256 + $ips[1] * 256 * 256 + $ips[0] * 256 * 256 * 256);
    }
}


function str_left($s1, $s2) {
    return substr($s1, 0, strpos($s1, $s2));
}

/**
 * Parses a string as a line from a CSV file 
 * <br><br>Example:
 * <code>
 * <?
 * $string = 'a,b,c,"d,e",f';
 * $values = parse_csv($string);
 * ?>
 * </code>
 * @param string $str String to parse
 * @param string $delimiter One character long string that determines the CSV delimiter
 * @param string $enclosure One character long string that determines what to ues for enclosing strings
 * @param integer length The maximum length of the string for parsing
 * @return array the CSV fields seperated into an array
 */
function parse_csv($str, $delimiter = ',', $enclosure = '"', $len = 0) {
    $fh = fopen('php://memory', 'rw');
    fwrite($fh, $str);
    rewind($fh);

    $result = fgetcsv($fh, $len, $delimiter, $enclosure );
    fclose($fh);
    return $result;
}
    
/**
 * Creates a line for a CSV file from an array of strings 
 * <br><br>Example:
 * <code>
 * <?
 * $string = 'a,b,c,"d,e",f';
 * $values = parse_csv($string);
 * ?>
 * </code>
 * @param array $arr An array of the CSV fields to be concatenated into a line of text
 * @param string $delimiter One character long string that determines the CSV delimiter
 * @param string $enclosure One character long string that determines what to ues for enclosing strings
 * @param integer length The maximum length of the output string
 * @return string The CSV output string 
 */
function create_csv($arr, $delimiter = ',', $enclosure = '"', $len = 0) {
    $fh = fopen('php://memory', 'rw');
    fputcsv($fh, $arr, $delimiter, $enclosure);
    rewind($fh);
       
    if ($len) {
        $result = fgets($fh, $len);
    } else {
        $result = fgets($fh);
    }
       
    fclose($fh);
    return $result;
}

/**
 * Merges 2 arrays recursively checking for nested arrays and merging them as well
 * Can either preserve numeric indexes or reorder them depending on the value
 * of $keep_numeric_index, which defaults to true
 * @param array $array1 The first array to merge
 * @param array $array2 The second array to merge
 * @param boolean $keep_numeric_index $enclosure One character long string that determines what to ues for enclosing strings
 * @return array The recursively merged arrays
  */
function array_merge_deep($array1, $array2, $keep_numeric_index=true) {
    // the first array is in the output set in every case
    $ret = $array1;
   
    // merege $ret with the remaining arrays
    foreach ($array2 as $key => $value) {
        if (! $keep_numeric_index && ((string) $key) === ((string) intval($key))) {
         	// integer or string as integer key - append
            $ret[] = $value;
        } else { // string key - megre
            if (is_array($value) && isset($ret[$key])) {
                // if $ret[$key] is not an array you try to merge an scalar value with an array - the result is not defined (incompatible arrays)
                // in this case the call will trigger an E_USER_WARNING and the $ret[$key] will be null.
                $ret[$key] = array_merge_deep($ret[$key], $value);
            } else {
                $ret[$key] = $value;
            }
        }
    }   
   
    return $ret;
}

function print_pre() {
    print "\n<pre>\n";
    for ($i=0; $i < func_num_args(); $i++) {
         $arg = func_get_arg($i);
         print_r ($arg);
    }//for
    print "\n</pre>\n";
} //foreach

//returns html/css/js necesary to create a googlemap, does not print
function googlemaps($settings, $mapoptions = NULL){
               $address=$settings['address']?$settings['address']:"New York, New York";
               $divid=$settings['id']?$settings['id']:("googlemaps_".rand(0,10000));

               $sanitized_divid = preg_replace('/\-/','_',$divid);

               $class=$settings['class']?$settings['class']:$divid;
               $height=$settings['height']?$settings['height']:NULL;
               $width=$settings['width']?$settings['width']:NULL;
          //     $onload=isset($settings['onload'])?$settings['onload']:true;
          //     $creatediv=isset($settings['create_div'])?$settings['create_div']:true;  
          //     $funcname=$settings['function_name']?$settings['function_name']:$sanitized_divid."_init";
               $mapoptions=$mapoptions?$mapoptions:($settings['mapoptions']?$settings['mapoptions']:NULL);

               $googlemaps = "";

               /*  //dont delete this code!

               if(!$divid){
                  $divid =  ("googlemaps_".rand(0,10000));
               }
               if(!$class){
                  $class = $divid;
               }
               */
               if(is_array($mapoptions)){
                  $dump = "{";
                  $length = count($mapoptions);
                  $i=0;
                  foreach($mapoptions as $key=>$val){
                     if(is_array($val)){
                        $dump .= "'$key':{";
                        $lengthj = count($val);
                        $j=0;
                        foreach($val as $keyj=>$valj){
                           $dump.="'$keyj':$valj".(++$j==$lengthj?'':',');
                        }
                        $dump .= "}".(++$i==$length?'':',');
                     }else{
                        $dump.="'$key':$val".(++$i==$length?'':',');
                     }
                  }
                  $dump .= "}";
                  $mapoptions = $dump;
               }

               $precede_mapoptions = "<script type='text/javascript'>var {$sanitized_divid}_mapoptions =";
               $procede_mapoptions = ";</script>";

               $mapoptions = $precede_mapoptions.$mapoptions.$procede_mapoptions;               

               /*

               if($creatediv){
                  $googlemaps .= "<div id='$divid' class='$class' ";
                  $googlemaps .= (($height || $width)?"style='".($height?"height:$height"."px;":"").($width?"width:$width"."px;":"")."'":"")."></div>";
               }
               $googlemaps .= "<script type='text/javascript'>  add_js('/lib/js/googlemaps.js'); var $sanitized_divid"."_address = '$address';";
               $googlemaps .= "var {$sanitized_divid}_obj = null;";
               $googlemaps .= "function $funcname(){{$sanitized_divid}_obj = new googlemaps_init($sanitized_divid"."_address,'$divid'";
               $googlemaps .= $mapoptions?",$mapoptions":'';
               $googlemaps .= ");}";
               if($onload){
                  $googlemaps .= "$(document).ready($funcname);";
               }
               $googlemaps .= "</script>";

               */

               $address_for_css = preg_replace('#\s+#',' ',$address);
               $address_for_css = preg_replace('#\s#','-',$address_for_css);
               $address_for_css = preg_replace('#,#','--',$address_for_css);

               $googlemaps.="<div id='$divid' class='googlemaps $class googlemaps-address_$address_for_css' ";
               $googlemaps.=(($height || $width)?"style='".($height?"height:$height"."px;":"").($width?"width:$width"."px;":"")."'":"")."></div>";
               $googlemaps.=$mapoptions;
               return($googlemaps);
            } //function googlemaps




function format_phone($phone) {
	if (strlen($phone)==10) $phone = '(' . substr($phone,0,3) . ') ' . substr($phone,3,3) . '-' . substr($phone,6,4);
	return $phone;
}

// inject a needle into a haystack at the specified offset
// $offset - the offset
function str_inject($haystack, $new_needle, $offset) {
	$part1 = substr($haystack, 0, $offset);
	$part2 = substr($haystack, $offset);
	$part1 = $part1 . $new_needle;
	$whole = $part1 . $part2;
	return $whole;
}

function postToCurl($url,$post_fields=NULL,$referer=NULL) {
	global $cookie_file_path;
	//$referer = '';
	if (!$referer) $referer = $url;
	$ch = curl_init(); 
	curl_setopt($ch, CURLOPT_URL, $url); 
	//curl_setopt($ch, CURLOPT_HEADER, 1); 
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); 
	curl_setopt($ch, CURLOPT_HTTP_VERSION, 1);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); 
	curl_setopt($ch, CURLOPT_REFERER, $referer); 
	if ($post_fields) curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields); 
	curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file_path); 
	curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file_path); 
	$result = curl_exec ($ch); 
	$headers = curl_getinfo($ch);
	curl_close ($ch); 
	$response['html'] = $result;
	$response['headers'] = $headers;
	return $response;
}//function

?>
