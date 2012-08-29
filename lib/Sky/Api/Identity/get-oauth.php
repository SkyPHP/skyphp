<?php



if (!$api ||
    !is_a($api, '\\Sky\\Api', true)
) {
    throw new Exception('Misconfigured page. Provide a Sky\Api as $api.');
}

$identity = $api . '\\Identity';

// output the oauth_token for the currently logged in user (and app_key)

if (!$api::isProtocolOk()) {

    // https is required but is not being used
    $response = \Sky\Api::error(500, 'https_required', 'HTTPS is required.');

} else if (!defined('PERSON_ID') || !is_numeric(PERSON_ID)) {

    // user not logged in
    $response = \Sky\Api::error(500, 'internal_error', 'User is not logged in.');

} else {

    // user is logged in
    try {

        $output = $identity::generateOauthToken(
            PERSON_ID,
            $_GET['app_key']
        );

        $response = new \Sky\Api\Response;

        $response->output = array(
            'oauth_token' => $output
        );

    } catch (\Exception $e) {
        $response = \Sky\Api::error(500, 'internal_error', $e->getMessage());
    }
}

$response->outputHeaders();
echo $response->json();
