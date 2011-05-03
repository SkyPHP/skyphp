<?

// logout the current user if applicable
if ($_GET['logout']) {
    unset($_SESSION['login']);
}

// auto-login the user if not logged in and there is a 'remember me' cookie
if ( !$_SESSION['login'] && $_COOKIE['password'] && !$_POST['login_username'] ) {
    $_POST['login_username'] = $_COOKIE['username'];
    $_POST['login_password'] = decrypt($_COOKIE['password']);
}

// user authentication
if ( $_POST['login_username'] && $_POST['login_password'] ) {

    $_POST['login_username'] = trim($_POST['login_username']);
    $_POST['login_password'] = trim($_POST['login_password']);

    $aql = 	"
        person {
            fname,
            lname,
            email_address,
            password
            where ((
                person.email_address ilike '".addslashes($_POST['login_username'])."'
                and person.password like '".addslashes($_POST['login_password'])."'
            ) or (
                person.username ilike '".addslashes($_POST['login_username'])."'
                and person.password like '".addslashes($_POST['login_password'])."'
            ))
        }";
    $rs_logins = aql::select($aql);
    $person = $rs_logins[0];
    if ($person) {
        unset($_SESSION['login']);
        $person['username'] = $_POST['login_username'];
        login_person($person,$_POST['remember_me']);
    }//if
}//if