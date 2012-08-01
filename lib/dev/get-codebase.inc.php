<?php

/**
 * Clones the repo from github if the branch folder does not exist
 * @todo: don't clone if the remote branch doesn't exist
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
 * More info available at:
 *
 * https://skydev.atlassian.net/wiki/display/SKYPHP/GitHub+PHP+Hook+Setup
 * and
 * https://skydev.atlassian.net/wiki/display/SKYPHP/New+Site+Configuration
 *
 * @param  string $codebase_path
 * @param  string $codebase
 * @return string
 */
function getCodeBase($codebase_path, $codebase)
{
    $branch_path = $codebase_path . $codebase;
    $git_dir = rtrim($branch_path, '/') . '/.git';

    // create folder structure and download branch
    if (!is_dir($git_dir)) {

        $codebase = explode('/', $codebase);
        list($user, $repository, $branch) = $codebase;

        @mkdir($branch_path, 0777, true);

        $commands = array(
            "cd {$branch_path}",
            "git clone -b {$branch} git@github.com:{$user}/{$repository}.git ."
        );

        $commands = array_map('escapeshellcmd', $commands);
        $command_str = implode(' ; ', $commands);

        exec($command_str, $output);
        if ($output) {
            print_r($output);
        }
    }

    return $branch_path . '/';
}
