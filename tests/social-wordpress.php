<?php
/** wp eval-file in a synthetic local WordPress only; mock provider HTTP and mail. */
if (
    wp_get_environment_type() !== 'local' ||
    !in_array(
        wp_parse_url(home_url('/'), PHP_URL_HOST),
        ['localhost', '127.0.0.1', 'example.test', 'social.example.test'],
        true,
    )
) {
    throw new RuntimeException('Synthetic local WordPress required.');
}
require_once dirname(__DIR__) . '/modules/social/bootstrap.php';
$checks = 0;
$assert = static function ($condition) use (&$checks) {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Social WordPress assertion failed: ' . $checks);
    }
};
$rejects = static function ($callback) use ($assert) {
    try {
        $callback();
    } catch (RuntimeException $error) {
        $assert(true);
        return;
    }
    $assert(false);
};
$before = $GLOBALS['pagenest_companion_services_profile'];
$prefix = 'social_fixture_' . bin2hex(random_bytes(6));
$created = [];
$keys = [];
$http = [];
$queue = [];
$mock = static function ($pre, $args, $url) use (&$http, &$queue) {
    $http[] = [$url, $args];
    if (!$queue) {
        return new WP_Error(
            'fixture_http_blocked',
            'All external HTTP is blocked in this fixture.',
        );
    }
    return [
        'headers' => [],
        'body' => array_shift($queue),
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies' => [],
        'filename' => null,
    ];
};
$mail = static fn() => false;
add_filter('pre_http_request', $mock, PHP_INT_MAX, 3);
add_filter('pre_wp_mail', $mail, PHP_INT_MAX);
try {
    $GLOBALS['pagenest_companion_services_profile']['social'] = [
        'enabled' => true,
        'allow_registration' => false,
        'avatar_enabled' => true,
        'legacy_apps' => ['qq' => $prefix, 'github' => 'legacy-unconfigured'],
        'providers' => [
            'qq' => [
                'enabled' => true,
                'client_id' => $prefix,
                'client_secret' => 'synthetic-secret',
                'redirect_uri' => home_url('/original-callback/'),
            ],
        ],
    ];
    // HTTP callbacks require HTTPS even in local WP. Provider exchange itself is fully mocked.
    $provider_config = [
        'client_id' => $prefix,
        'client_secret' => 'synthetic-secret',
        'redirect_uri' => 'https://example.test/original-callback/',
    ];
    $encoded_config = $provider_config;
    $encoded_config['redirect_uri'] =
        'https://example.test/index.php?from=login&next=%2Freading%2F';
    parse_str(
        wp_parse_url(
            pagenest_social_authorize_url('sina', $encoded_config, 'synthetic-state'),
            PHP_URL_QUERY,
        ),
        $authorization_query,
    );
    $assert($authorization_query['redirect_uri'] === $encoded_config['redirect_uri']);
    $assert(!isset($authorization_query['next']));
    $one = wp_insert_user([
        'user_login' => $prefix . '_one',
        'user_pass' => wp_generate_password(),
        'user_email' => $prefix . '@fake.com',
        'role' => 'subscriber',
    ]);
    $two = wp_insert_user([
        'user_login' => $prefix . '_two',
        'user_pass' => wp_generate_password(),
        'user_email' => $prefix . '@example.test',
        'role' => 'subscriber',
    ]);
    if (is_wp_error($one) || is_wp_error($two)) {
        throw new RuntimeException('Fixture user creation failed.');
    }
    $created = [$one, $two];
    update_user_meta($one, 'open_type_qq', $prefix . '_subject');
    update_user_meta($one, 'open_img', 'https://q1.qlogo.cn/synthetic-avatar');
    update_user_meta($one, 'open_access_token', 'retained-fixture-token');
    $original = get_userdata($one);
    $key = pagenest_social_identity_key('qq', $prefix, $prefix . '_subject');
    $keys[] = $key;
    pagenest_social_bind($one, 'qq', $prefix, $prefix . '_subject');
    $assert(get_option($key)['user_id'] === $one);
    $assert(get_userdata($one)->user_pass === $original->user_pass);
    $assert(get_userdata($one)->roles === $original->roles);
    $assert(get_userdata($one)->user_email === $original->user_email);
    $assert(get_user_meta($one, 'open_access_token', true) === 'retained-fixture-token');
    $rejects(static fn() => pagenest_social_bind($two, 'qq', $prefix, $prefix . '_subject'));
    $rejects(static fn() => pagenest_social_unbind($one, $key));
    $second_key = pagenest_social_identity_key('qq', $prefix, $prefix . '_second');
    $keys[] = $second_key;
    pagenest_social_bind($two, 'qq', $prefix, $prefix . '_second');
    update_user_meta($two, '_pagenest_social_verified_email', [
        'email' => hash('sha256', get_userdata($two)->user_email),
        'at' => time(),
    ]);
    pagenest_social_unbind($two, $second_key);
    $assert(get_option($second_key)['user_id'] === 0);
    wp_set_current_user(0);
    $state = 'pn.qq.' . bin2hex(random_bytes(32));
    $state_key = 'pagenest_social_state_' . hash('sha256', $state);
    $state_record = [
        'expires' => time() + 600,
        'browser' => hash('sha256', 'browser'),
        'provider' => 'qq',
        'app' => $prefix,
        'redirect_uri' => $provider_config['redirect_uri'],
        'mode' => 'login',
        'user_id' => 0,
        'return' => home_url('/'),
    ];
    set_transient($state_key, $state_record, 600);
    $assert(
        pagenest_social_state_consume($state, 'browser', 'qq', $provider_config) === $state_record,
    );
    $rejects(
        static fn() => pagenest_social_state_consume($state, 'browser', 'qq', $provider_config),
    );
    $queue = [
        'access_token=synthetic-access',
        'callback(' .
        wp_json_encode(['client_id' => $prefix, 'openid' => 'synthetic-openid']) .
        ');',
        '{"ret":0,"nickname":"Synthetic","figureurl_qq_2":"https://q1.qlogo.cn/avatar"}',
    ];
    $assert(
        pagenest_social_exchange('qq', $provider_config, 'synthetic-code')['subject'] ===
            'synthetic-openid',
    );
    foreach ($http as [$url, $args]) {
        $assert($args['sslverify'] === true && $args['redirection'] === 0);
    }
    $assert(
        pagenest_social_login_identity('qq', $provider_config, [
            'subject' => $prefix . '_subject',
            'name' => 'Ignored',
        ]) === $one,
    );
    $queue = ['{"error":"provider-denial"}'];
    $rejects(static fn() => pagenest_social_exchange('baidu', $provider_config, 'synthetic-code'));
    echo wp_json_encode([
        'checks' => $checks,
        'provider_http' => 'mock only',
        'mail' => 'blocked',
    ]) . "\n";
} finally {
    wp_set_current_user(0);
    foreach ($keys as $key) {
        delete_option($key);
    }
    if (isset($state_key)) {
        delete_transient($state_key);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($created as $id) {
        wp_delete_user($id);
    }
    $GLOBALS['pagenest_companion_services_profile'] = $before;
    remove_filter('pre_http_request', $mock, PHP_INT_MAX);
    remove_filter('pre_wp_mail', $mail, PHP_INT_MAX);
}
