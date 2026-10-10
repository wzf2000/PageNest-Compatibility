<?php
defined('ABSPATH') || exit();
function pagenest_social_pending_create($provider, $config, $identity, $return)
{
    if (is_user_logged_in()) {
        throw new RuntimeException('Sign out before registering.');
    }
    $token = bin2hex(random_bytes(32));
    $browser = bin2hex(random_bytes(32));
    $record = [
        'expires' => time() + 600,
        'browser' => hash('sha256', $browser),
        'provider' => $provider,
        'app' => $config['client_id'],
        'identity' => [
            'subject' => $identity['subject'],
            'name' => $identity['name'],
            'avatar' => pagenest_social_avatar_url($identity['avatar'] ?? ''),
        ],
        'return' => pagenest_social_return($return),
    ];
    $key = 'pn_social_pending_' . hash('sha256', $token);
    if (
        !set_transient($key, $record, 600) ||
        !setcookie('pn_social_pending', $browser, [
            'expires' => time() + 600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ])
    ) {
        delete_transient($key);
        throw new RuntimeException('Unable to save registration choice.');
    }
    return $token;
}
function pagenest_social_pending_read($token, $browser)
{
    if (
        is_user_logged_in() ||
        !is_string($token) ||
        !preg_match('/^[a-f0-9]{64}$/D', $token) ||
        !is_string($browser)
    ) {
        throw new RuntimeException('Registration choice unavailable.');
    }
    $record = get_transient('pn_social_pending_' . hash('sha256', $token));
    if (
        !is_array($record) ||
        $record['expires'] <= time() ||
        !hash_equals($record['browser'], hash('sha256', $browser))
    ) {
        throw new RuntimeException('Registration choice expired.');
    }
    return $record;
}
function pagenest_social_pending_consume($token, $browser)
{
    $key = 'pn_social_pending_' . hash('sha256', $token);
    return pagenest_social_lock($key, static function () use ($token, $browser, $key) {
        $record = pagenest_social_pending_read($token, $browser);
        if (!delete_transient($key)) {
            throw new RuntimeException('Registration choice consumed.');
        }
        return $record;
    });
}
function pagenest_social_complete_login($user_id)
{
    $user = get_userdata($user_id);
    if (!$user || (int) $user->user_status !== 0) {
        throw new RuntimeException('Account unavailable.');
    }
    $authenticated = apply_filters('wp_authenticate_user', $user, '');
    if (
        is_wp_error($authenticated) ||
        !($authenticated instanceof WP_User) ||
        (int) $authenticated->ID !== (int) $user_id
    ) {
        throw new RuntimeException('Account unavailable.');
    }
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, false, is_ssl());
    do_action('wp_login', $user->user_login, $user);
}
function pagenest_social_registration_route()
{
    try {
        if (!pagenest_social_active()) {
            throw new RuntimeException('Social login unavailable.');
        }
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        $token = wp_unslash($_REQUEST['pending'] ?? '');
        $browser = $_COOKIE['pn_social_pending'] ?? '';
        $record = pagenest_social_pending_read($token, $browser);
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            $html =
                '<p>这个社交账号尚未连接本站账号。已有账号请先登录，再到个人资料重新授权连接。</p><form method="post" action="' .
                esc_url(admin_url('admin-post.php')) .
                '"><input type="hidden" name="action" value="pagenest_social_register"><input type="hidden" name="pending" value="' .
                esc_attr($token) .
                '">' .
                wp_nonce_field('pagenest-social-register', '_wpnonce', false, false);
            if (
                (pagenest_social_config()['allow_registration'] ?? false) === true &&
                get_option('users_can_register')
            ) {
                $html .=
                    '<button type="submit" name="choice" value="create">创建新的本站账号</button> ';
            }
            $html .=
                '<button type="submit" name="choice" value="existing">登录已有账号</button></form>';
            wp_die($html, '选择账号', ['response' => 200]);
        }
        if (
            ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
            !wp_verify_nonce(wp_unslash($_POST['_wpnonce'] ?? ''), 'pagenest-social-register')
        ) {
            throw new RuntimeException('Invalid form.');
        }
        $choice = wp_unslash($_POST['choice'] ?? '');
        if (!in_array($choice, ['create', 'existing'], true)) {
            throw new RuntimeException('Choose an account action.');
        }
        $record = pagenest_social_pending_consume($token, $browser);
        setcookie('pn_social_pending', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if ($choice === 'existing') {
            wp_safe_redirect(wp_login_url(admin_url('profile.php')));
            exit();
        }
        $config = pagenest_social_provider_config($record['provider']);
        if (!$config || $config['client_id'] !== $record['app']) {
            throw new RuntimeException('Provider changed.');
        }
        $id = pagenest_social_login_identity(
            $record['provider'],
            $config,
            $record['identity'],
            true,
        );
        $avatar = pagenest_social_avatar_url($record['identity']['avatar'] ?? '');
        if ($avatar) {
            update_user_meta($id, '_pagenest_social_avatar', $avatar);
        }
        pagenest_social_complete_login($id);
        wp_safe_redirect($record['return']);
        exit();
    } catch (Throwable $error) {
        wp_die('账号选择已失效或无法完成，请重新开始登录。', '社交登录', ['response' => 400]);
    }
}

function pagenest_social_pending_clear_browser()
{
    if (isset($_COOKIE['pn_social_pending'])) {
        unset($_COOKIE['pn_social_pending']);
        setcookie('pn_social_pending', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
