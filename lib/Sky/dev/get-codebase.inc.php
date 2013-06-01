<?php

/**
 * Clones the repo from github if the branch folder does not exist
 * @todo: don't clone if the remote branch doesn't exist
 *
 * First make sure your nobody user has a private key listed in github, i.e. /.ssh
 *
 *  visudo
 *  # Defaults    requiretty
 *  nobody ALL = NOPASSWD: /usr/bin/git *
 *
 * USAGE
 *
 * See sample_dev_index.php
 *
 * You must have a sites.json file with an array of subdomains and
 * codebases in the following format:
 *
 *   {
 *       "subdomain": [
 *           "GithubUser/repo/master",
 *           "SkyPHP/skyphp/master",
 *       ],
 *       "subdomain2": [
 *           "GithubUser/repo/master",
 *           "SkyPHP/skyphp/master",
 *       ]
 *   }
 *
 * The format is username/repository/branch
 *
 * Or mysubdomain.php containing a $codebases array.
 *
 *
 * @param  string $codebase_path
 * @param  string $codebase
 * @return string
 */
function getCodeBase($codebase_path, $codebase)
{
    $git_path = '/usr/bin/git';

    $branch_path = $codebase_path . $codebase;
    $git_dir = rtrim($branch_path, '/') . '/.git';

    // create folder structure and download branch
    if (!is_dir($git_dir)) {

        $codebase = explode('/', $codebase);
        list($user, $repository, $branch) = $codebase;

        mkdir($branch_path, 0777, true);

        $commands = array(
            'whoami',
            "cd {$branch_path}",
            "sudo $git_path clone --recursive -b {$branch} git@github.com:{$user}/{$repository}.git ."
        );

        $commands = array_map('escapeshellcmd', $commands);
        $command_str = implode(' ; ', $commands);

        exec($command_str, $output);

        echo '<pre><code>';
        print_r($command_str) . "<br />\n";
        if ($output) {
            print_r($output);
        }
        echo '</code></pre>';
    }

    return $branch_path . '/';
}
