<pre>
<?php

if($allow_auto_updates){

	$codebase = $_SERVER['QUERY_STRING'];
	$codebase_array = get_codebase_paths();

	$path = $codebase_array[ $codebase ]['path'];
	
	if ( $path ) {

        if ($svn_bin_path) $command = "(sudo $svn_bin_path update $path > /dev/null) 3>&1 1>&2 2>&3";
        else $command = "(sudo /usr/bin/svn update $path > /dev/null) 3>&1 1>&2 2>&3";

		echo "$command\n";
		$t = exec($command);

/*
		if($t){
			$command = "(sudo /usr/local/bin/svn update $path --non-interactive > /dev/null) 3>&1 1>&2 2>&3";
			$t = exec($command);
		}

		if($t=='Password:'){
			exit('Please add "nobody ALL = NOPASSWD: /usr/local/bin/svn update *"'."\n".
				'"nobody ALL = NOPASSWD: /usr/bin/svn update *"');
		}
*/
		echo $t;
	}else{
		exit('ERROR: invalid codebase.');
	}
}else{
	exit('Auto updates are disabled, please set "$allow_auto_updates = true;" in your index.php file.');
}
?>
</pre>
