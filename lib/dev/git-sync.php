<?php

/*
    This is the target for GitHub's WebHook.
    This script will pull the latest commits from github for the given repo
*/

$git_path = "/usr/bin/git";

include '../core/functions.inc.php';

if ($_GET['debug_email']) ob_start();

//Load Site Config
$sites = json_decode(file_get_contents('sites.json', true));

$github = json_decode(stripslashes($_POST['payload']),true);
$ref = explode('/',$github['ref']);

$user = $github['repository']['owner']['name'];
$repository = $github['repository']['name'];
$branch = $ref[2];

$codebase = "$user/$repository/$branch";

$codebase_path = $GLOBALS['codebases_path'];

$branch_path = $codebase_path . $codebase;

if (!$sites) echo 'No sites in sites.json.';

//create folder structure if needed. If NOT we will perform a pull instead of a checkout
if(is_dir($branch_path)) {
    $commands = array(
        "cd $branch_path",
        "$git_path pull"
    );
    safe_exec($commands, $output);
} else {
    mkdir($branch_path, 0777, true);
    $commands = array(
        "cd $branch_path";
        "$git_path clone -b $branch git@github.com:$user/$repository.git .;"
    );
    safe_exec($commands, $output);
}

print_r($commands);

echo "\n\n";
print_r($output);

echo "\n\n\nPOST[payload]\n\n";
echo json_beautify($_POST['payload']);

if ($_GET['debug_email']) {
    mail(
        $_GET['debug_email'],
        'git hook ' . time(),
        ob_get_contents()
    );
}
