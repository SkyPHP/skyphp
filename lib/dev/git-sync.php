<?php

/*

*/

$_POST['payload'] = '{"pusher":{"name":"none"},"repository":{"name":"cravetix","created_at":"2012/05/10 10:02:19 -0700","size":148,"has_wiki":true,"watchers":1,"private":true,"fork":true,"url":"https://github.com/tolijoonbug/cravetix","language":"PHP","pushed_at":"2012/05/16 15:44:05 -0700","has_downloads":true,"open_issues":0,"has_issues":false,"homepage":"","forks":0,"organization":"SkyPHP","description":"","owner":{"name":"tolijoonbug","email":"toli@joonbug.com"}},"forced":false,"after":"4d0c67499eb48feff7d46d41da4a6e1d6356c424","head_commit":{"added":["lib/class/class.Mustache.php","pages/purchase/includes/tickets-toli/tickets-toli.js","pages/purchase/includes/tickets-toli/tpl/delivery-method.mustache","pages/purchase/includes/tickets-toli/tpl/event-info.mustache","pages/purchase/includes/tickets-toli/tpl/ticket-as-tableselector.mustache","pages/purchase/includes/tickets-toli/tpl/ticket-as.mustache","pages/purchase/includes/tickets-toli/tpl/ticket-bp.mustache","pages/purchase/includes/tickets-toli/tpl/ticket-ga.mustache"],"modified":["pages/purchase/includes/side-event.php","pages/purchase/includes/tickets-toli/tickets-toli.css","pages/purchase/includes/tickets-toli/tickets-toli.php","templates/cart/cart.css","templates/cart/cart.php"],"timestamp":"2012-05-16T15:48:53-07:00","removed":["lib/mustache.php"],"author":{"name":"Toli","username":"tolijoonbug","email":"toli@joonbug.com"},"url":"https://github.com/tolijoonbug/cravetix/commit/4d0c67499eb48feff7d46d41da4a6e1d6356c424","id":"4d0c67499eb48feff7d46d41da4a6e1d6356c424","distinct":true,"message":"Tickets Update: Converted Everything to Mustache, Added Sidebar","committer":{"name":"Toli","username":"tolijoonbug","email":"toli@joonbug.com"}},"deleted":false,"commits":[{"added":[],"modified":[],"timestamp":"2012-05-15T12:00:12-07:00","removed":["pages/tickets-toli/tickets-toli.css"],"author":{"name":"Toli","username":"tolijoonbug","email":"toli@joonbug.com"},"url":"https://github.com/tolijoonbug/cravetix/commit/f7932be0b70cf02751aeacdeb6103c8d2d318ba1","id":"f7932be0b70cf02751aeacdeb6103c8d2d318ba1","distinct":true,"message":"Moved Tickets Toli","committer":{"name":"Toli","username":"tolijoonbug","email":"toli@joonbug.com"}},{"added":[],"modified":["pages/purchase/includes/tickets-toli/tickets-toli.php"],"timestamp":"2012-05-15T12:07:50-07:00","removed":[],"author":{"name":"Toli","username":"tolijoonbug","email":"toli@joonbug.com"},"url":"https://github.com/tolijoonbug/cravetix/commit/9c238febf4b4b5a3d9f5b3b8626d7f9f51e3d276","id":"9c238febf4b4b5a3d9f5b3b8626d7f9f51e3d276","distinct":true,"message":"Trying New Event API","committer":{"name":"Toli","username":"tolijoonbug","email":"toli@joonbug.com"}},{"added":["lib/class/class.Mustache.php","pages/purchase/includes/tickets-toli/tickets-toli.js","pages/purchase/includes/tickets-toli/tpl/delivery-method.mustache","pages/purchase/includes/tickets-toli/tpl/event-info.mustache","pages/purchase/includes/tickets-toli/tpl/ticket-as-tableselector.mustache","pages/purchase/includes/tickets-toli/tpl/ticket-as.mustache","pages/purchase/includes/tickets-toli/tpl/ticket-bp.mustache","pages/purchase/includes/tickets-toli/tpl/ticket-ga.mustache"],"modified":["pages/purchase/includes/side-event.php","pages/purchase/includes/tickets-toli/tickets-toli.css","pages/purchase/includes/tickets-toli/tickets-toli.php","templates/cart/cart.css","templates/cart/cart.php"],"timestamp":"2012-05-16T15:48:53-07:00","removed":["lib/mustache.php"],"author":{"name":"Toli","username":"tolijoonbug","email":"toli@joonbug.com"},"url":"https://github.com/tolijoonbug/cravetix/commit/4d0c67499eb48feff7d46d41da4a6e1d6356c424","id":"4d0c67499eb48feff7d46d41da4a6e1d6356c424","distinct":true,"message":"Tickets Update: Converted Everything to Mustache, Added Sidebar","committer":{"name":"Toli","username":"tolijoonbug","email":"toli@joonbug.com"}}],"ref":"refs/heads/master","before":"3901f632df6e3ec6b8f8109ecc78b3abca584537","compare":"https://github.com/tolijoonbug/cravetix/compare/3901f63...4d0c674","created":false}';

//exec("cd /share/codebases_test; git clone git@github.com:tolijoonbug/skyphp.git 2>&1;", $out);

//Load Site Config
$sites = json_decode(file_get_contents('sites.json', true));

$git_path = "/usr/bin/git";

$github = json_decode(stripslashes($_POST['payload']),true);
$ref = explode('/',$github['ref']);

$user = $github['repository']['owner']['name'];
$repository = $github['repository']['name'];
$branch = $ref[2];

$codebase = "$user/$repository/$branch";

$codebase_path = "/share/codebases_test/";
$branch_path = $codebase_path . $codebase;

//We want to check if we want to update this repository by checking if any site uses it
foreach($sites as $site){
    if(in_array($codebase, $site)) { //if we find a site that uses it, pull it in
        //$command = "cd $path; git pull origin $branch > /dev/null) 3>&1 1>&2 2>&3";

        //create folder structure if needed. If NOT we will perform a pull instead of a checkout
        if(is_dir($branch_path)){
            echo exec("cd $branch_path; git pull;");
            break; //we're done
        }
        else {
            mkdir($branch_path, 0777, true);
            echo exec("cd $branch_path; git clone -b $branch git@github.com:$user/$repository.git .;");
            break; //we're done
        }
    }
}