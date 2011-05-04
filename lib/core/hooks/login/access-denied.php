<?

// check duplicate logins to see if another person (with same user/pass) has access then undeny access
if ( is_array($rs_logins) )
foreach ( $rs_logins as $person ) {
    if ( auth_person( $access_groups, $person['person_id'] ) ) {
        $access_denied = false;
        login_person($person,$_POST['remember_me']);
        break;
    }
}