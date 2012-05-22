<?
function getCodeBase($codebase_path, $codebase){
	$branch_path = $codebase_path . $codebase;

	//create folder structure and download branch
    if(!is_dir($branch_path)){
    	$codebase = explode('/', $codebase);
    	$user = $codebase[0];
    	$repository = $codebase[1];
    	$branch = $codebase[2];

		mkdir($branch_path, 0777, true);
        echo exec("cd $branch_path; git clone -b $branch git@github.com:$user/$repository.git .;");
    }

	return $branch_path . '/';
}
?>