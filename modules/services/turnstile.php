<?php
/** Native form protection only; no REST, XML-RPC or OAuth interception. */
defined('ABSPATH') || exit();
function pagenest_companion_turnstile_scope($scope)
{
    $config = pagenest_companion_service_config('turnstile');
    return !empty($config['enabled']) && !empty($config['scopes'][$scope]);
}
function pagenest_companion_turnstile_error()
{
    return new WP_Error(
        'pagenest_turnstile',
        __('Verification failed. Please retry the security check.', 'pagenest-companion'),
    );
}
function pagenest_companion_turnstile_verify($scope, $token)
{
    static $attempted = [];
    if (!pagenest_companion_turnstile_scope($scope)) {
        return true;
    }
    $config = pagenest_companion_service_config('turnstile');
    if (
        !is_string($token) ||
        $token === '' ||
        strlen($token) > 2048 ||
        empty($config['secret_key'])
    ) {
        return false;
    }
    $hash = hash('sha256', $token);
    if (isset($attempted[$hash])) {
        return false;
    }
    $attempted[$hash] = true;
    try {
        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 10,
            'redirection' => 0,
            'sslverify' => true,
            'body' => ['secret' => $config['secret_key'], 'response' => $token],
        ]);
    } catch (Throwable $error) {
        return false;
    }
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $hosts = $config['allow_hosts'] ?? [$config['hostname'] ?? ''];
    $issued =
        is_array($data) && is_string($data['challenge_ts'] ?? null)
            ? strtotime($data['challenge_ts'])
            : false;
    return is_array($data) &&
        ($data['success'] ?? false) === true &&
        is_string($data['hostname'] ?? null) &&
        in_array(strtolower($data['hostname']), array_map('strtolower', $hosts), true) &&
        ($data['action'] ?? '') === $scope &&
        $issued !== false &&
        $issued <= time() + 30 &&
        $issued >= time() - 300;
}
function pagenest_companion_turnstile_token()
{
    $raw = $_POST['cf-turnstile-response'] ?? '';
    return is_string($raw) ? wp_unslash($raw) : '';
}
function pagenest_companion_turnstile_native_post($file)
{
    global $pagenow;
    return $pagenow === $file &&
        ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&
        !(defined('REST_REQUEST') && REST_REQUEST) &&
        !(defined('XMLRPC_REQUEST') && XMLRPC_REQUEST);
}
function pagenest_companion_turnstile_login($user, $username, $password)
{
    if (
        !pagenest_companion_turnstile_native_post('wp-login.php') ||
        ($_REQUEST['action'] ?? 'login') !== 'login'
    ) {
        return $user;
    }
    return pagenest_companion_turnstile_verify('login', pagenest_companion_turnstile_token())
        ? $user
        : pagenest_companion_turnstile_error();
}
function pagenest_companion_turnstile_registration($errors)
{
    if (
        pagenest_companion_turnstile_native_post('wp-login.php') &&
        !pagenest_companion_turnstile_verify('register', pagenest_companion_turnstile_token())
    ) {
        $errors->add(
            'pagenest_turnstile',
            pagenest_companion_turnstile_error()->get_error_message(),
        );
    }
    return $errors;
}
function pagenest_companion_turnstile_reset($errors)
{
    if (
        pagenest_companion_turnstile_native_post('wp-login.php') &&
        !pagenest_companion_turnstile_verify('reset', pagenest_companion_turnstile_token())
    ) {
        $errors->add(
            'pagenest_turnstile',
            pagenest_companion_turnstile_error()->get_error_message(),
        );
    }
}
function pagenest_companion_turnstile_comment($comment)
{
    if (
        pagenest_companion_turnstile_native_post('wp-comments-post.php') &&
        !pagenest_companion_turnstile_verify('comments', pagenest_companion_turnstile_token())
    ) {
        wp_die(pagenest_companion_turnstile_error(), '', ['response' => 403, 'back_link' => true]);
    }
    return $comment;
}
function pagenest_companion_turnstile_widget($scope)
{
    if (!pagenest_companion_turnstile_scope($scope)) {
        return;
    }
    $config = pagenest_companion_service_config('turnstile');
    $base = plugin_dir_url(dirname(__DIR__, 2) . '/pagenest-compatibility.php');
    $manifest = require dirname(__DIR__, 2) . '/assets/manifest.php';
    $asset = $manifest['services_turnstile_js'] ?? 'services-turnstile.js';
    wp_enqueue_script('pagenest-services-turnstile', $base . 'assets/' . $asset, [], null, true);
    wp_enqueue_script(
        'pagenest-services-turnstile-api',
        'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=pagenestTurnstileReady&render=explicit',
        ['pagenest-services-turnstile'],
        null,
        true,
    );
    wp_script_add_data('pagenest-services-turnstile-api', 'strategy', 'defer');
    echo '<div class="pagenest-turnstile ' .
        ($scope === 'comments' ? 'cf-turnstile-comments' : '') .
        '" data-sitekey="' .
        esc_attr($config['site_key']) .
        '" data-action="' .
        esc_attr($scope) .
        '" data-theme="' .
        esc_attr($config['theme'] ?? 'auto') .
        '" data-language="' .
        esc_attr($config['language'] ?? 'zh-cn') .
        '" data-size="' .
        esc_attr($config['size'] ?? 'normal') .
        '" data-appearance="' .
        esc_attr($config['appearance'] ?? 'always') .
        '"><div class="pagenest-turnstile-widget"></div><p class="pagenest-turnstile-status" role="status" aria-live="polite">' .
        esc_html__('Complete the security check before submitting.', 'pagenest-companion') .
        '</p><button type="button" class="pagenest-turnstile-retry">' .
        esc_html__('Retry security check', 'pagenest-companion') .
        '</button></div>';
}
function pagenest_companion_turnstile_register()
{
    foreach (
        [
            'login_form' => 'login',
            'register_form' => 'register',
            'lostpassword_form' => 'reset',
            'comment_form_after_fields' => 'comments',
            'comment_form_logged_in_after' => 'comments',
        ]
        as $hook => $scope
    ) {
        add_action($hook, static function () use ($scope) {
            pagenest_companion_turnstile_widget($scope);
        });
    }
    add_filter('login_form_middle', static function ($content) {
        ob_start();
        pagenest_companion_turnstile_widget('login');
        return $content . ob_get_clean();
    });
    add_filter('authenticate', 'pagenest_companion_turnstile_login', 99, 3);
    add_filter('registration_errors', 'pagenest_companion_turnstile_registration', 99);
    add_action('lostpassword_post', 'pagenest_companion_turnstile_reset', 99);
    add_filter('preprocess_comment', 'pagenest_companion_turnstile_comment', 99);
}
