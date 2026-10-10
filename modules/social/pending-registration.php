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
function pagenest_social_registration_open()
{
    if (
        (pagenest_social_config()['allow_registration'] ?? false) !== true ||
        !get_option('users_can_register')
    ) {
        throw new RuntimeException('Registration is closed.');
    }
}
function pagenest_social_pending_register($token, $browser, $details)
{
    $key = 'pn_social_pending_' . hash('sha256', $token);
    return pagenest_social_lock($key, static function () use ($token, $browser, $details, $key) {
        $record = pagenest_social_pending_read($token, $browser);
        pagenest_social_registration_open();
        $config = pagenest_social_provider_config($record['provider']);
        if (!$config || $config['client_id'] !== $record['app']) {
            throw new RuntimeException('Provider changed.');
        }
        $id = pagenest_social_login_identity(
            $record['provider'],
            $config,
            $record['identity'],
            true,
            $details,
        );
        // Invalid details never consume authorization. After successful creation,
        // the unique identity owner also prevents replay if deletion fails.
        if (!delete_transient($key)) {
            throw new RuntimeException('Registration choice consumed.');
        }
        $avatar = pagenest_social_avatar_url($record['identity']['avatar'] ?? '');
        if ($avatar) {
            update_user_meta($id, '_pagenest_social_avatar', $avatar);
        }
        return ['user_id' => $id, 'record' => $record];
    });
}
function pagenest_social_registration_form($token, $values, $errors = [])
{
    $html =
        '<h1>创建本站账号</h1><p>请填写用户名和电子邮箱。昵称用于公开显示，可自行修改；留空时使用用户名。</p>';
    if ($errors) {
        $html .= '<div role="alert"><p><strong>请检查以下信息：</strong></p><ul>';
        foreach ($errors as $message) {
            $html .= '<li>' . esc_html($message) . '</li>';
        }
        $html .= '</ul></div>';
    }
    $html .=
        '<form method="post" action="' .
        esc_url(admin_url('admin-post.php')) .
        '"><input type="hidden" name="action" value="pagenest_social_register"><input type="hidden" name="pending" value="' .
        esc_attr($token) .
        '">' .
        wp_nonce_field('pagenest-social-register', '_wpnonce', false, false);
    foreach (
        [
            'user_login' => ['用户名（必填）', 'text', 'username', 60, true],
            'user_email' => ['电子邮箱（必填）', 'email', 'email', 100, true],
            'nickname' => ['昵称（选填）', 'text', 'nickname', 250, false],
        ]
        as $field => [$label, $type, $autocomplete, $length, $required]
    ) {
        $value = is_string($values[$field] ?? null) ? $values[$field] : '';
        $html .=
            '<p><label for="pn-register-' .
            $field .
            '">' .
            esc_html($label) .
            '</label><br><input style="box-sizing:border-box;width:100%;max-width:100%;padding:9px 10px;margin-top:6px;font-size:16px" id="pn-register-' .
            $field .
            '" name="' .
            $field .
            '" type="' .
            $type .
            '" autocomplete="' .
            $autocomplete .
            '" maxlength="' .
            $length .
            '" value="' .
            esc_attr($value) .
            '"' .
            ($required ? ' required' : '') .
            (isset($errors[$field])
                ? ' aria-invalid="true" aria-describedby="pn-error-' . $field . '"'
                : '') .
            '>';
        if (isset($errors[$field])) {
            $html .=
                '<small id="pn-error-' . $field . '">' . esc_html($errors[$field]) . '</small>';
        }
        $html .= '</p>';
    }
    $html .=
        '<p>邮箱不会自动视为已验证。注册后可在个人资料中验证邮箱，用于账号恢复。</p><p><button type="submit" name="choice" value="register">完成注册并登录</button> <button type="submit" name="choice" value="existing" formnovalidate>登录已有账号</button></p></form>';
    return $html;
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
        if ($choice === 'existing') {
            pagenest_social_pending_consume($token, $browser);
            pagenest_social_pending_clear_browser();
            wp_safe_redirect(wp_login_url(admin_url('profile.php')));
            exit();
        }
        pagenest_social_registration_open();
        if ($choice === 'create') {
            wp_die(
                pagenest_social_registration_form($token, [
                    'nickname' => sanitize_text_field($record['identity']['name']),
                ]),
                '填写注册资料',
                ['response' => 200],
            );
        }
        if ($choice !== 'register') {
            throw new RuntimeException('Choose an account action.');
        }
        $details = [];
        foreach (['user_login', 'user_email', 'nickname'] as $field) {
            $details[$field] = is_string($_POST[$field] ?? null) ? wp_unslash($_POST[$field]) : '';
        }
        try {
            $result = pagenest_social_pending_register($token, $browser, $details);
        } catch (PageNest_Social_Registration_Error $error) {
            wp_die(
                pagenest_social_registration_form($token, $details, $error->errors),
                '请检查注册资料',
                ['response' => 200],
            );
        }
        pagenest_social_pending_clear_browser();
        pagenest_social_complete_login($result['user_id']);
        wp_safe_redirect($result['record']['return']);
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
