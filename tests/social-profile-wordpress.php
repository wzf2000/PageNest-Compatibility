<?php
/** Owned synthetic WordPress only. No provider requests or email. */
if (
    wp_get_environment_type() !== 'local' ||
    !in_array(
        wp_parse_url(home_url('/'), PHP_URL_HOST),
        ['example.test', 'localhost', '127.0.0.1'],
        true,
    )
) {
    throw new RuntimeException('Synthetic local WordPress required.');
}
require_once dirname(__DIR__) . '/modules/social/bootstrap.php';
$checks = 0;
$assert = static function ($condition, $label) use (&$checks) {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Social profile: ' . $label);
    }
};
$original = $GLOBALS['pagenest_companion_services_profile'];
$prefix = 'profile_' . bin2hex(random_bytes(6));
$http = static fn() => new WP_Error('fixture_network_blocked');
$mail = static fn() => false;
add_filter('pre_http_request', $http, PHP_INT_MAX);
add_filter('pre_wp_mail', $mail, PHP_INT_MAX);
$id = wp_insert_user([
    'user_login' => $prefix,
    'user_email' => $prefix . '@example.test',
    'user_pass' => wp_generate_password(),
    'role' => 'subscriber',
]);
$assert(!is_wp_error($id), 'synthetic user created');
$login = static function ($token) use ($id) {
    wp_set_current_user($id);
    $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($id, time() + 3600, 'logged_in', $token);
};
try {
    $providers = [];
    foreach (['qq', 'baidu', 'github', 'google'] as $provider) {
        $providers[$provider] = [
            'enabled' => true,
            'client_id' => 'fixture-app',
            'client_secret' => 'fixture-secret',
            'redirect_uri' => 'https://example.test/callback/',
        ];
    }
    $GLOBALS['pagenest_companion_services_profile']['social'] = [
        'enabled' => true,
        'providers' => $providers,
        'legacy_apps' => [],
    ];
    wp_dequeue_style('pagenest-social');
    do_action('admin_enqueue_scripts', 'profile.php');
    $assert(
        wp_style_is('pagenest-social', 'enqueued'),
        'profile admin hook enqueues social styles',
    );
    $session = WP_Session_Tokens::get_instance($id)->create(time() + 3600);
    $login($session);
    $assert(!pagenest_social_flash_store('<script>bad</script>'), 'unlisted message rejected');
    $_GET['success'] = 'bind_ok';
    $assert(pagenest_social_flash_take() === null, 'query cannot forge flash');
    pagenest_social_flash_store('bind_ok');
    $login(WP_Session_Tokens::get_instance($id)->create(time() + 3600));
    $assert(pagenest_social_flash_take() === null, 'another session cannot read flash');
    $login($session);
    $assert(pagenest_social_flash_take()[0] === 'success', 'original session receives flash');
    $assert(pagenest_social_flash_take() === null, 'flash consumed once');
    set_transient(
        'pn_social_flash_' . pagenest_social_session_fingerprint($id),
        ['code' => 'bind_ok', 'expires' => time() - 1],
        120,
    );
    $assert(pagenest_social_flash_take() === null, 'expired flash rejected');
    pagenest_social_bind($id, 'qq', 'fixture-app', 'fixture-subject');
    pagenest_social_bind($id, 'github', 'fixture-old-app', 'fixture-old-subject');
    pagenest_social_mark_recent($id);
    $render = static function () use ($id) {
        ob_start();
        echo '<form id="your-profile">';
        pagenest_social_profile(get_userdata($id));
        echo '</form>';
        pagenest_social_profile_forms();
        return ob_get_clean();
    };
    pagenest_social_flash_store('bind_ok');
    $html = $render();
    $assert(
        !str_contains($html, 'id="pn-social-bind-qq"'),
        'bound current provider has no connect form',
    );
    $assert(str_contains($html, 'id="pn-social-reauth-qq"'), 'bound provider can confirm identity');
    $assert(
        str_contains($html, 'id="pn-social-bind-github"'),
        'historical provider still permits new app connection',
    );
    $assert(str_contains($html, '历史连接，当前不可用于登录'), 'history scope clearly labelled');
    $assert(str_contains($html, 'role="status"'), 'success status accessible');
    $assert(str_contains($html, 'pagenest-social-icon'), 'provider icons rendered');
    $assert(
        !str_contains($html, 'fixture-secret') && !str_contains($html, 'fixture-subject'),
        'identity and credentials omitted',
    );
    $assert(
        strpos($html, '</form>') < strpos($html, '<form hidden'),
        'action forms outside profile form',
    );
    ob_start();
    pagenest_social_profile_forms();
    $assert(ob_get_clean() === '', 'footer forms rendered once');
    pagenest_social_flash_store('unbind_error');
    $error_html = $render();
    $assert(str_contains($error_html, 'role="alert"'), 'failure alert accessible');
    $output = getenv('PAGENEST_SOCIAL_UI_OUTPUT');
    if ($output) {
        if (!is_dir($output)) {
            mkdir($output, 0700, true);
        }
        $css = file_get_contents(dirname(__DIR__) . '/assets/social-ui.css');
        foreach (['profile-account' => $html, 'profile-error' => $error_html] as $name => $body) {
            file_put_contents(
                $output . '/' . $name . '.html',
                '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>账号管理</title><style>body{margin:20px;font:14px system-ui}button,input{font:inherit} ' .
                    $css .
                    '</style><body>' .
                    $body .
                    '</body></html>',
            );
        }
    }
    update_user_meta($id, '_pagenest_social_verified_email', [
        'email' => hash('sha256', get_userdata($id)->user_email),
        'at' => time(),
    ]);
    $assert(
        !str_contains($render(), 'id="pn-social-email-form"'),
        'verified mailbox avoids repeated email action',
    );
    echo "Social profile: $checks checks passed; no external HTTP or email.\n";
} finally {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($id);
    $GLOBALS['pagenest_companion_services_profile'] = $original;
    wp_set_current_user(0);
    unset($_COOKIE[LOGGED_IN_COOKIE], $_GET['success']);
    remove_filter('pre_http_request', $http, PHP_INT_MAX);
    remove_filter('pre_wp_mail', $mail, PHP_INT_MAX);
}
