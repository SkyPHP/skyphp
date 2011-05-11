<?
ob_start();
?>
<pre>
<?

/*
1. put the following url into github's post-receive url:
    http://example.com/lib/dev/git.php#myrepo
    where myrepo is the name of your codebase/repository
2. make sure you have version.txt in your codebase
3. visudo
    # Defaults    requiretty
    nobody ALL = NOPASSWD: /usr/bin/git pull *

 */

if (!$git_path) $git_path = "/usr/bin/git";

if($allow_auto_updates){

    echo '$_POST';
    print_r($_POST);

    $github = json_decode(stripslashes($_POST['payload']),true);
    echo '$github';
    print_r($github);
    
    // get branch
    $temp = explode('/',$github['ref']);
    $branch = $temp[2];
    
    $codebase = $github['repository']['name'];
    $codebase_array = get_codebase_paths();
    echo '$codebase_array';
    print_r($codebase_array);

    $path = $codebase_array[ $codebase ]['path'];
    echo "path: $path \n";

	if ( $path ) {

        if ($branch && $branch != 'master') {
            // check if we have a folder for this branch
            $path = substr($path,0,-1) . '.' . $branch . '/';
            if ( !is_dir($path) ) $skip = true;
            // TODO: auto-deploy new branch dev site.. just do a git clone or whatever.
        }
        if (!$branch) $branch = 'master';

        if (!$skip) $command = "cd $path && (sudo $git_path pull origin $branch > /dev/null) 3>&1 1>&2 2>&3";

        $message .= "\n $command ";

		echo "$command\n";
		$t = exec($command);

		echo $t;
	}else{
		$message .= "\n '$codebase' is an invalid codebase.";
	}
}else{
	$message .= 'Auto updates are disabled, please set "$allow_auto_updates = true;" in your index.php file.';
}

echo $message;

?>
</pre>
<?
mail('will123195@gmail.com','git hook',ob_get_contents());
?>