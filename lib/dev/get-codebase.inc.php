<?php
/**
 * Clones the repo from github if the branch folder does not exist
 * TODO: don't clone if the remote branch doesn't exist
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
 * The format is username/repository/branch
 *
 * Or mysubdomain.php containing a $codesbases array.
 *
 * More info available at:
 *
 * https://skydev.atlassian.net/wiki/display/SKYPHP/GitHub+PHP+Hook+Setup
 * and
 * https://skydev.atlassian.net/wiki/display/SKYPHP/New+Site+Configuration
 *
 * @param string $codebase_path
 * @param string $codebase
 * @return string
 */
function getCodeBase($codebase_path, $codebase)
{
    $branch_path = $codebase_path . $codebase;

    //create folder structure and download branch
    if(!is_dir($branch_path)){
        $codebase = explode('/', $codebase);
        $user = $codebase[0];
        $repository = $codebase[1];
        $branch = $codebase[2];

        mkdir($branch_path, 0777, true);

        $cmd = "cd $branch_path; git clone -b $branch git@github.com:$user/$repository.git . 2>{$codebase_path}git.log;";

        echo safe_exec($cmd);
    }

    return $branch_path . '/';
}
