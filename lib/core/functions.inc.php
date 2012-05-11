<?php


    if ( !function_exists('gethostname') ) {
        function gethostname() {
            return exec('hostname');
        }
    }

    /*
    sample usage:
    mem('myKey',$myValue); // write my key/value pair to memcache
    echo mem('myKey'); // read the value stored in 'mykey' and echo it
    */
    function mem( $key, $value='§k¥', $duration=null ) {
        global $memcache, $is_dev;
        if ( !$memcache ) return false;
        if ( !$key) return false;
        if ( $value == '§k¥' ) {
            elapsed("begin mem-read($key)");
            // get the value from memcached
            if ( $_GET['mem_debug'] && $is_dev ) echo "mem-read( $key )<br />";
            $value = $memcache->get($key);

            // if this is a multi_get
            if (is_array($key)) {
            	$c = $value; unset($value);
            	foreach ($key as $k) {
            		$value[$k] = $c[$k];
            	}
            }

            elapsed("end mem-read($key)");
            return $value;
        } else if ( $value !== NULL ) {
            elapsed("begin mem-write($key)");
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
            elapsed("end mem-write($key)");
            return $success;
        } else {
        	// null timeout to work around 3.0.3 bug
            return $memcache->delete( $key, null ); 
        }
    }

    function disk( $file, $value='§k¥', $duration='30 days' ) {
        global $skyphp_storage_path;
        $file = implode('/',array_filter(explode('/',$file)));
        $cache_file = $skyphp_storage_path . 'diskcache/' . $file;
        //echo 'cachefile: ' . $cache_file . '<br />';
        if ( $value == '§k¥' ) { // read
        	elapsed("begin disk-read($file)");
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
                    elapsed("end disk-read($file)");
                    return $value;
                } else {
                    // file is expired, delete the file
                    elapsed("end disk-read($file)");
                    unlink($cache_file);
                }
            }
            return false;
        } else { // write
        	elapsed("begin disk-write($file)");
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
			elapsed("end disk-write($file)");
            return true;
		}
    }

    /*
     * check if a disk cache is available
     */
    function disk_check($key) {

    }

    /**
	 * If a string is too long, shorten it in the middle
	 * @param string $text
	 * @param int $limit limit number of characteres
	 * @return string
	 */
	function shorten($text, $limit = 25) {
		if (strlen($text) > $limit) {
         	$pos = array_keys(str_word_count($text, 2));
         	$length = call_user_func(function() use($pos, $limit) {
         		foreach ($pos as $k => $l) {
         			if ($l < $limit) continue;
         			return $l;
         		}
         		return $pos[$k - 1];
         	});
          	$text = trim(substr($text, 0, $length) ) . '...';
      	}

		return $text;
	}

	/**
	 * like explode, but ignores things inside of quotes and parenthesis
	 * makes a mini state machine that splits on the given $delimiter
	 * @param string -> a delimiter (exampes: ',' or ' ')
	 * @param string -> string to split
	 * @param array -> key value config array, 
	 * 	'ignore' => array($key_in_closings)
	 * 	'closings' => $additional_closings (same structure $open => $close)
	 * @return array
	 */
	function explodeOn($delimiter, $str, $conf = array()) {
		
		// open => close (what matches what)
		$closings = array(
			'(' => ')',
			"'" => "'",
			'"' => '"'
		);

		if ($conf) {
			if (is_array($conf['ignore'])) foreach ($conf['ignore'] as $piece) {
				unset($closings[$piece]);
			}
			if (is_array($conf['closings'])) {
				array_merge($closings, $conf['closings']);
			}
		}

		$inner = array(); 		// stack of state
		$escape_next = false;	// whether or not we're escaping the next character
		$re = array();			// response

		// init pieces
		$split_str = str_split($str);
		$length = count($split_str);

		// current chunk
		$current = '';
		for ($i = 0; $i < $length; $i++) {
			
			$piece = $split_str[$i];

			// escaping current if $escape_next
			if ($escape_next) {
				$current .= $piece;
				$escape_next = false;
				continue;
			}

			// if current $piece is delimiter and we're not in inner state, add to the split
			if ($split_str[$i] == $delimiter && !$inner) {
				$re[] = $current;
				$current = '';
				continue;
			}

			$current .= $piece;
			
			// match closings to this character push/pop state
			foreach ($closings as $open => $close) {
				if (end($inner) == $open && $piece == $close) {
					array_pop($inner);
				} else if ($piece == $open) {
					array_push($inner, $piece);
				}
			}

			$escape_next = ($piece == '\\');

		} // end foreach character

		// append the last piece
		$re[] = $current;
		return array_filter(array_map('trim', $re));

	}

	function explodeOnComma($str) {
		return explodeOn(',', $str);
	}

	function explodeOnWhitespace($str) {
		return explodeOn(' ', $str);
	}

    // if_not( $a, $b )
    // shortcut for:
    // if (!$a) $a = $b;
    // examples:
    // $x = if_not($a, true);
    // $x = if_not($a, function(){ } );
    // $x = if_not($a, function($o){ }, $this);
	function if_not() {
		$args = func_get_args();
		$val = array_shift($args);
		$callback = array_shift($args);
		if ($val) return $val;
		if (!is_callable($callback)) return $callback;
		return call_user_func_array($callback, $args);
	}

    // return the output of an include file
    // optionally provide second parameter to pass a variable to the include file
	function return_include($inc, $data = null) {
		ob_start();
		include $inc;
		$r = ob_get_contents();
		ob_end_clean();
		return $r;
	}

	function return_content() {
		$args = func_get_args();
		$fn = array_shift($args);
		
		if (!$fn) {
			throw new Exception('First argument of return_content() needs to be a callback.');
			return;
		}

		ob_start();
		call_user_func_array($fn, $args);
		$content = ob_get_contents();
		ob_end_clean();

		return $content;

	}

// this should go in the model class if we determine this to be useful
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
            $obj = Model::get($model, $id);
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


	// takes an arbitrary amount of arguments.
    function elapsed() {
    	
    	if (!$_GET['elapsed']) return;

    	global $sky_start_time, $sky_elapsed_count;

    	$do_elapsed = function($msg = null) use(&$sky_start_time, &$sky_elapsed_count) {
    		$sky_elapsed_count++;
    		echo round(microtime_float()-microtime_float($sky_start_time),3) . ' #' . $sky_elapsed_count;
            if ($msg) echo ' - ' . $msg;
            echo '<br />';
    	};

    	$args = func_get_args();
    	$num_args = func_num_args();

    	if ($num_args == 0) {
	    	$do_elapsed();
	    } else {
	    	foreach ($args as $msg) {
	    		$do_elapsed($msg);
	    	}
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

	function sql($SQL = null ,$dbx=NULL) {
		global $db;
		if (!$SQL) {
			throw new Exception('missing SQL argument for function sql()');
			return;
		}
		if (!$dbx) $dbx = $db;
		$r = $dbx->Execute($SQL);
		if ($e = $dbx->ErrorMsg()) {
			$error = '<div>'.$SQL.'</div>';
			if (auth('admin:developer')) $error .= '<div>' . $dbx->host . '</div>';
			$error .= '<div style="color:red;">' . $e . '</div>';
			die($error);
		} else return $r;
	}
	
	function sql_array($SQL,$dbx=NULL){
		$r = sql($SQL,$dbx);
		$rs = array();
		while(!$r->EOF) {
			$rs[] = $r->GetRowAssoc(false);
			$r->moveNext();
		}
		return $rs;
	}

	
	function inc($relative_file) {
		$bt = debug_backtrace();
		$path = dirname( $bt[0]['file'] );
		include( $path . '/' . $relative_file );
	}
	
	function redirect($href,$type=302, $continue = false) {
		// TODO add support for https
		if ( $href == $_SERVER['REQUEST_URI'] ) return false;
        else header("Debug: $href == {$_SERVER['REQUEST_URI']}");
		
		if (stripos($href,"http://") === false || stripos($href,"http://") != 0)
			if (stripos($href,"https://") === false || stripos($href,"https://") != 0)
				$href = "http://$_SERVER[SERVER_NAME]" . $href;
					
        if ( $type == 301 ) {
            header("HTTP/1.1 301 Moved Permanently");
            header("Location: $href");
        } else {
            header("HTTP/1.1 302 Moved Temporarily");
            header("Location: $href");
        }
		if (!$continue) die();
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


    function add_trailing_slash($uri=null) {
        if (!$uri) {
            $uri = $_SERVER['REQUEST_URI'];
        }
        $temp = explode('?',$uri);
        $u['path'] = $temp[0];
        $u['query'] = $temp[1];
        // canonicalize with the trailing slash
        if ( substr($u['path'],-1)!='/' ) {
            $qs = NULL;
            if ( $u['query'] ) $qs = '?' . $u['query'];
            redirect($u['path'].'/'.$qs);
        }
    }

	function slugize($name) {
		$name = trim($name);
		$name = str_replace(array(' ','/'),'-',$name);
		$name = preg_replace("/[^A-Za-z0-9\-]/", "", $name );
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

	function exit_json($arr = array()) {
		json_headers();
		exit(json_encode($arr));
	}

	function exit_json_ok($extra = array()) {
		$arr = array(
			'status' => 'OK'
		);
		$arr = array_merge($arr, $extra);
		exit_json($arr);
	}

	function exit_json_errors($errors, $extra = array()) {
		$arr = array(
			'status' => 'Error',
			'errors' => $errors
		);
		$arr = array_merge($arr, $extra);
		exit_json($arr);
	}

	// bind to an array $errors and returns a function
	function exit_errors_function(&$errors) {
		return function() use(&$errors) {
			if (!$errors) return;
			exit_json_errors($errors);
		};	
	}

	function is_ajax_request() {
		if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
			return true;
		} 
		return false;
	}

	// tests whether the input is an associative array
	function is_assoc($arr) {
		if (!is_array($arr)) return false;
		return (count(array_filter(array_keys($arr), 'is_string')) == count($arr));
	}


	function array_chunk_map($rs = null, $fn = null, $step = 10) {
		if (!is_callable($fn)) $fn = function($val) { return $val; };
		$rs = array_chunk($rs, $step, true); // preserve keys
		return array_map($fn, $rs);
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

	function encryptFn($key = null) {
		return function($message) use($key) {
			return encrypt($message, $key);	
		};
	}


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
		if (ctype_alnum($temp)) return $temp;
		else return false;
	}//function

	function decryptFn($key = null) {
		return function($message) use($key) {
			return decrypt($message, $key);	
		};
	}


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
	   $length = ($numstring) ? strlen($numstring) : 0;
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

			$person_id = Login::get('person_id');

			if ( !$person_id ) return false;
			if ( !$arg ) return true;

			// new method -- check the appropriate keytable on demand
			if ( strpos($arg,':') ):

				return auth_person($arg, $person_id);
				
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

	/**
		
		will return an auth function that uses those contraints

		value arguments for now only accept ['constant']

		KEYS MUST BE SET

		constraints = array(
			'arg1' => $vars, // check contants
			'arg2' => $vars // dont check for constant
		);
	*/

	function makeAuthFn($constraints) {

		static $results = array();

		if (!is_assoc($constraints)) {
			throw new Exception('constraints needs to be an associative array');
		}

		$constraint_hash = md5(serialize($constraints));

		$trim_to_lower = function($str) {
			return trim(strtolower($str));
		};

		return function($access_level_str, $params = null) use($constraints, $constraint_hash, $trim_to_lower, &$results) {

			// set key for this auth_function if it doesnt exist
			if (!array_key_exists($constraint_hash, $results)) {
				$results[$constraint_hash] = array();
			}

			// make sure parms is an array regardless of what's given.
			if (count($constraints) == 1) {
				if (!is_array($params)) {
					$params = array( reset(array_keys($constraints)) => $params);
				}
			}

			// generate where, look for constants if check_for_constant is true and the params are not defined
			// return false if it isn't set, exit early
			$where = array();
			foreach ($constraints as $constraint => $vars) {
				if (!$params[$constraint]) {
					if (!$vars['constant']) return false;
					if (defined($vars['constant'])) {
						$params[$constraint] = constant($vars['constant']);
					}
					if (!$params[$constraint]) return false;
				}
				$where[] = "{$constraint} = {$params[$constraint]}";
			}

			// make hash
			$param_hash = md5(sprintf('%s:::%s', $access_level_str, serialize($params)));

			// if this has been computed for this page return the result
			if (array_key_exists($param_hash, $results[$constraint_hash])) {
				return $results[$constraint_hash][$param_hash];
			} 

			$allowed = false;
			$access_level_arr = explode(';', $access_level_str);

			foreach ($access_level_arr as $access_level) {

				$access = array_map($trim_to_lower, explode(':', $access_level, 2));
				$key_table = $access[0];
				
				if (!$key_table) continue;

				$access_needed_arr = my_array_unique(
					array_map($trim_to_lower, 
						explode(',', $access[1])
					)
				);

				if ($access_needed_arr[0] == '*') {
					$access_needed_arr = array();	
				} 

				$aql = 	" $key_table { id, access_group } ";
				$rs = aql::select($aql, array(
					'where' => $where,
					'limit' => 1
				));

				if (!$rs) continue;

				if (!$access_needed_arr) {
					$allowed = true;
					break;
				}

				$granted = array_map($trim_to_lower, explode(',', $rs[0]['access_group']));
				foreach ($access_needed_arr as $needed) {
					if (in_array($needed, $granted)) {
						$allowed = true;
						break 2; // break out of both loops if a match is found
					}
				}

			}

			// return and store value
			return $results[$constraint_hash][$param_hash] = $allowed;

		};

	}

	function auth_person( $access_level_str, $person_id=NULL ) {
		
		if (!$person_id) $person_id = $_SESSION['login']['person_id'];

		$auth_fn = makeAuthFn(array(
			'person_id' => array(
				'constant' => 'PERSON_ID'
			)
		));

		return $auth_fn($access_level_str, $person_id);
		
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
 * @param array $arr the array to output visually in HTML table format
 * @param bool $silent, default to false, if true, returns the html of the print_a
 */
function print_a($arr, $silent = false) {

	static $ran = false;

	if ($silent) {
		ob_start();
	}

	if (!is_array($arr)) {
		echo 'print_a(): Not an array.';
		return;		
	}

	if (!$ran) {
?>		
		<style type="text/css">
			a.ide {
				color:blue;
				text-decoration:none;
			}
			a.ide:hover {
				font-weight:bold;
			}
		</style>		
<?
		$ran = true;	
	}

	$ide_link = function($ide) {
		$format = '<a href="/dev/ide/%s" class="ide">%s</a>';
		return sprintf($format, $ide, $ide);
	};

	$id_link = function($table, $id) {
		$format = '<a href="/dev/ide/%s/%s" class="ide">%s</a>';
		return sprintf($format, $table, $id, $id);
	};

	$table_name = function($k) {
		$n = substr($k, 0, -3);
		$pos = stripos($n, '__');
		return ($pos !== false)
			? substr($n, $pos + 2)
			: $n;
	};

?>
	<table border="0" style="border-collapse:separate;border-spacing:2px;font-family:monospace">
<?
	foreach ($arr as $key => $val) {
?>
		<tr>
			<td bgcolor="#D1E9D3" valign="top">
				<strong><?=$key?></strong>
			</td>
			<td bgcolor="#EEEECA" valign="top">
<?
			if (is_array($val)) {
				
				print_a($val);

			} else if ($k == 'login_password') {
				
				echo '***********';

			} else {
				
				if (substr($key, -4) == '_ide') {
				
					$val = ($val) ? $ide_link($val) : '';

				} else if (substr($key, -3) == '_id' && is_numeric($id)) {

					$table = $table_name($key);
					$val = ($val) ? $id_link($table, $val) : '';

				} 

				echo utf8_encode($val);

			}
?>				
			</td>
		</tr>
<?		
	}
?>
	</table>
<?
	
	if ($silent) {
		$contents = ob_get_contents();
		ob_end_clean();
		return $contents;
	}

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
         print PHP_EOL;
    }//for
    print "</pre>\n";
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

// http://snipplr.com/view/3680/format-phone/
function formatPhone($phone = '', $convert = false, $trim = true) {
	// If we have not entered a phone number just return empty
	if (empty($phone)) {
		return '';
	}
 
	// Strip out any extra characters that we do not need only keep letters and numbers
	$phone = preg_replace("/[^0-9A-Za-z]/", "", $phone);
 
	// Do we want to convert phone numbers with letters to their number equivalent?
	// Samples are: 1-800-TERMINIX, 1-800-FLOWERS, 1-800-Petmeds
	if ($convert == true) {
		$replace = array('2'=>array('a','b','c'),
				 '3'=>array('d','e','f'),
			         '4'=>array('g','h','i'),
				 '5'=>array('j','k','l'),
                                 '6'=>array('m','n','o'),
				 '7'=>array('p','q','r','s'),
				 '8'=>array('t','u','v'),								 '9'=>array('w','x','y','z'));
 
		// Replace each letter with a number
		// Notice this is case insensitive with the str_ireplace instead of str_replace 
		foreach($replace as $digit=>$letters) {
			$phone = str_ireplace($letters, $digit, $phone);
		}
	}
 
	// If we have a number longer than 11 digits cut the string down to only 11
	// This is also only ran if we want to limit only to 11 characters
	if ($trim == true && strlen($phone)>11) {
		$phone = substr($phone, 0, 11);
	}						 
 
	// Perform phone number formatting here
	if (strlen($phone) == 7) {
		return preg_replace("/([0-9a-zA-Z]{3})([0-9a-zA-Z]{4})/", "$1-$2", $phone);
	} elseif (strlen($phone) == 10) {
		return preg_replace("/([0-9a-zA-Z]{3})([0-9a-zA-Z]{3})([0-9a-zA-Z]{4})/", "($1) $2-$3", $phone);
	} elseif (strlen($phone) == 11) {
		return preg_replace("/([0-9a-zA-Z]{1})([0-9a-zA-Z]{3})([0-9a-zA-Z]{3})([0-9a-zA-Z]{4})/", "$1($2) $3-$4", $phone);
	}
 
	// Return original phone if not 7, 10 or 11 digits long
	return $phone;
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


function hrs_array() {
	$hrs = range(7, 12);
	for ($i = 1; $i <= 6; $i++) $hrs[] = $i;
	$mins = array_map('prepend_zero', range(0, 59, 15));
	$times = array();
	$a = 'am';
	$i = 2;
	while ($i) {
		foreach ($hrs as $hr) {
			if ($hr == 12) $a = ($a == 'am') ? 'pm' : 'am';
			foreach ($mins as $min) $times[] = $hr.':'.$min.$a;
		}
		$i--;
	}
	return $times;
}

function prepend_zero($n) { 
	return str_pad($n, 2, '0'); 
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

function str_insert($insertstring, $intostring, $offset) {
	return str_inject($intostring, $insertstring, $offset);
}

function krumo_debug_obj($o){
  	$class = get_class($o);
	krumo($o, get_class_methods( $class ), get_class_vars( $class ), get_object_vars($o));
}


function krumo_debug_env(){
    krumo( get_defined_functions(), get_defined_constants(), get_defined_vars(), $GLOBALS, get_declared_interfaces(), get_declared_classes() );   
}

function strip_inline_style($string, $replacement_style = null) {
	if (strpos($string, '<!--nostrip-->') !== false) return $string;
	return preg_replace_callback('/\bstyle\="(?<style>[^"]*)"/', function($matches) use($replacement_style) {
		return 'style="'.$replacement_style.'"';
	}, $string);
}

// needs to be run in the head portion of a script, prior to anything else.

function close_connection_and_continue($callback = null) {
	return close_connection_helper(array(
		'callback' => $callback
	));
}

function redirect_and_continue($href, $type = 302) {
	return close_connection_helper(array(
		'do' => 'redirect',
		'href' => $href,
		'type' => $type
	));
}

function close_connection_helper($args = array()) {
	ignore_user_abort(true);
	ob_start();

	if (is_callable($args['callback'])) {
		$response = $args['callback']();
	}

	if (session_id()) {
		session_write_close();
	}

	header("Content-Encoding: none");//send header to avoid the browser side to take content as gzip format
	header("Content-Length: ".ob_get_length());//send length header

	if ($args['do'] == 'redirect') {
		header('Location: ' . $args['href']);
	} else {
		header("Connection: close");
	}
	
	ob_end_flush();flush();

	return $response;
}

function json_beautify($json) {

    $result      = '';
    $pos         = 0;
    $strLen      = strlen($json);
    $indentStr   = '  ';
    $newLine     = "\n";
    $prevChar    = '';
    $outOfQuotes = true;

    for ($i=0; $i<=$strLen; $i++) {

        // Grab the next character in the string.
        $char = substr($json, $i, 1);

        // Are we inside a quoted string?
        if ($char == '"' && $prevChar != '\\') {
            $outOfQuotes = !$outOfQuotes;

        // If this character is the end of an element,
        // output a new line and indent the next line.
        } else if(($char == '}' || $char == ']') && $outOfQuotes) {
            $result .= $newLine;
            $pos --;
            for ($j=0; $j<$pos; $j++) {
                $result .= $indentStr;
            }
        }

        // Add the character to the result string.
        $result .= $char;

        // If the last character was the beginning of an element,
        // output a new line and indent the next line.
        if (($char == ',' || $char == '{' || $char == '[') && $outOfQuotes) {
            $result .= $newLine;
            if ($char == '{' || $char == '[') {
                $pos ++;
            }

            for ($j = 0; $j < $pos; $j++) {
                $result .= $indentStr;
            }
        }

        $prevChar = $char;
    }

    return $result;
}


/**
 *	shorthand for the aql2array class
 *	@param string $aql
 *	@return array
 */
function aql2array($param1, $param2 = null) {
	if (aql::is_aql($param1)) {
		$r = new aql2array($param1);
		return $r->aql_array;
	} else {
		return aql2array::get($param1, $param2);
	}
}