<?php

/**
 * This is the target for GitHub's WebHook.
 * - pull the latest commits from github for the given repo
 * - update submodules
 */

$git_path = "/usr/bin/git";

include '../functions.inc.php';

if ($_GET['debug_email']) {
    ob_start();
}

$github = json_decode(stripslashes($_POST['payload']), true);
$ref = explode('/',$github['ref']);

$user = $github['repository']['owner']['name'];
$repository = $github['repository']['name'];
$branch = $ref[2];

$codebase_path = $GLOBALS['codebases_path'];
$codebase = sprintf('%s/%s/%s', $user, $repository, $branch);
$branch_path = $codebase_path . $codebase;

$commands = array(
    'whoami',
    "cd {$branch_path}"
);

// create folder structure if needed.
// if no folder, we do a clone, otherwise pull
if (!is_dir($branch_path . '/.git')) {
    #mkdir($branch_path, 0777, true);
    $commands[] = "mkdir -m 755 $branch_path";
    $commands[] = sprintf(
        '%s clone --recursive -b %s git@github.com:%s/%s.git .',
        $git_path,
        $branch,
        $user,
        $repository
    );
} else {
    $commands[] = "{$git_path} pull";
}

// update submodules
#$commands[] = "{$git_path} submodule init";
#$commands[] = "{$git_path} submodule update";
# no longer necessary becasue we added --recursive to the clone

// execute commands
safe_exec($commands, $output);

print_r($commands);
print_r($output);

echo "\n\nPOST[payload]\n\n";
echo json_beautify($_POST['payload']);

if ($_GET['debug_email']) {
    mail(
        $_GET['debug_email'],
        'git hook ' . time(),
        ob_get_contents()
    );
}
