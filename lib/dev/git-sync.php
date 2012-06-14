<?php

/*
    Updates GIT Repositories on a GitHub Hook
*/

if ($_GET['debug_email']) ob_start();

//Load Site Config
$sites = json_decode(file_get_contents('sites.json', true));

$git_path = "/usr/bin/git";

$github = json_decode(stripslashes($_POST['payload']),true);
$ref = explode('/',$github['ref']);

$user = $github['repository']['owner']['name'];
$repository = $github['repository']['name'];
$branch = $ref[2];

$codebase = "$user/$repository/$branch";

$codebase_path = $GLOBALS['codebases_path'];

$branch_path = $codebase_path . $codebase;

//We want to check if we want to update this repository by checking if any site uses it
foreach($sites as $site){
    if(in_array($codebase, $site)) { //if we find a site that uses it, pull it in

        //create folder structure if needed. If NOT we will perform a pull instead of a checkout
        if(is_dir($branch_path)){
            echo exec("cd $branch_path; $git_path pull;");
            break; //we're done
        }
        else {
            mkdir($branch_path, 0777, true);
            echo exec("cd $branch_path; $git_path clone -b $branch git@github.com:$user/$repository.git .;");
            break; //we're done
        }
    }
}

if ($_GET['debug_email']) {
    mail(
        $_GET['debug_email'],
        'git hook ' . time(),
        ob_get_contents()
    );
}
