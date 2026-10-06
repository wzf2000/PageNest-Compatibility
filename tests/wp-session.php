<?php
/** A revocable synthetic reader session; values are written only to a private file. */
if (
    wp_get_environment_type() !== 'local' ||
    !getenv('COMPONENT_LAB_STATE') ||
    !getenv('COMPONENT_LAB_SESSION')
) {
    throw new RuntimeException('Explicit synthetic lab required');
}
$fixture = json_decode(
    file_get_contents(getenv('COMPONENT_LAB_STATE')),
    true,
    32,
    JSON_THROW_ON_ERROR,
);
$user = (int) $fixture['reader'];
$expires = time() + 1800;
$token = WP_Session_Tokens::get_instance($user)->create($expires);
$cookie = wp_generate_auth_cookie($user, $expires, 'logged_in', $token);
$path = getenv('COMPONENT_LAB_SESSION');
$_COOKIE[LOGGED_IN_COOKIE] = $cookie;
wp_set_current_user($user);
$nonce = wp_create_nonce('wp_rest');
file_put_contents(
    $path,
    wp_json_encode([
        'nonce' => $nonce,
        'token' => $token,
        'user' => $user,
        'cookies' => [
            [
                'name' => LOGGED_IN_COOKIE,
                'value' => $cookie,
                'domain' => '127.0.0.1',
                'path' => '/',
                'httpOnly' => true,
                'secure' => false,
                'sameSite' => 'Lax',
            ],
        ],
    ]),
);
chmod($path, 0600);
echo "Synthetic reader session saved privately\n";
