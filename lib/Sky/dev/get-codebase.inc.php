<?php

/**
 * Clones the repo from github if the branch folder does not exist
 * @todo: don't clone if the remote branch doesn't exist
 *
 * 1. make sure your user has a private key listed in github, i.e. /.ssh
 * 2. make sure your user has sudo access to clone and mkdir:
 *
 *  visudo
 *  # Defaults    requiretty
 *  nobody ALL = NOPASSWD: /usr/bin/git clone *
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

        #mkdir($branch_path, 0777, true);

        $commands = array(
            'whoami',
            "sudo mkdir -p -m 755 $branch_path",
            "cd {$branch_path}",
            "sudo $git_path clone --recursive -b {$branch} git@github.com:{$user}/{$repository}.git ."
        );

        $commands = array_map('escapeshellcmd', $commands);
        $command_str = implode(' ; ', $commands);

        exec($command_str . ' 2>&1', $output);

        echo '<pre><code>';
        echo $command_str;
        echo "\n";
        if ($output) {
            print_r($output);
        }
        echo '</code></pre>';
    }

    return $branch_path . '/';
}
