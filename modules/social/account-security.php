<?php
defined('ABSPATH') || exit();
function pagenest_social_session_fingerprint($user_id)
{
    $user = get_userdata($user_id);
    $token = wp_get_session_token();
    if (!$user || !$token || (int) $user_id !== get_current_user_id()) {
        return '';
    }
    return hash('sha256', $user_id . '|' . $token . '|' . $user->user_pass);
}
function pagenest_social_mark_recent($user_id)
{
    $session = pagenest_social_session_fingerprint($user_id);
    if (!$session) {
        throw new RuntimeException('Unable to confirm session.');
    }
    $key = 'pn_social_recent_' . $session;
    $at = time();
    set_transient($key, $at, 600);
    // WordPress may return false when the same second is already recorded.
    if ((int) get_transient($key) !== $at) {
        throw new RuntimeException('Unable to confirm session.');
    }
}
function pagenest_social_reauthenticate_identity($user_id, $provider, $app, $subject)
{
    $key = pagenest_social_identity_key($provider, $app, $subject);
    $owner = pagenest_social_owner($key);
    $bindings = get_user_meta($user_id, '_pagenest_social_bindings', true) ?: [];
    $config = pagenest_social_provider_config($provider);
    if (
        !$config ||
        $config['client_id'] !== $app ||
        !is_array($owner) ||
        (int) $owner['user_id'] !== (int) $user_id ||
        !isset($bindings[$key])
    ) {
        throw new RuntimeException('Use an already connected identity.');
    }
    pagenest_social_mark_recent($user_id);
}
function pagenest_social_require_recent($user_id)
{
    $session = pagenest_social_session_fingerprint($user_id);
    $at = $session ? get_transient('pn_social_recent_' . $session) : false;
    if (!$at || (int) $at < time() - 600) {
        throw new RuntimeException('Please confirm your identity in your profile.');
    }
}
function pagenest_social_rate_limit($purpose, $user_id, $limit, $window)
{
    $key = 'pn_social_rate_' . hash('sha256', $purpose . ':' . $user_id);
    pagenest_social_lock($key, static function () use ($key, $limit, $window) {
        $record = get_transient($key);
        if (!is_array($record) || $record['until'] <= time()) {
            $record = ['count' => 0, 'until' => time() + $window];
        }
        if ($record['count'] >= $limit) {
            throw new RuntimeException('Please retry later.');
        }
        $record['count']++;
        if (!set_transient($key, $record, max(1, $record['until'] - time()))) {
            throw new RuntimeException('Unable to record attempt.');
        }
    });
}
function pagenest_social_verify_password($user_id, $password)
{
    pagenest_social_rate_limit('password', $user_id, 5, 600);
    $user = get_userdata($user_id);
    if (
        !$user ||
        !is_string($password) ||
        !wp_check_password($password, $user->user_pass, $user_id)
    ) {
        throw new RuntimeException('Unable to confirm identity.');
    }
    pagenest_social_mark_recent($user_id);
}
function pagenest_social_email_verified($user_id)
{
    $user = get_userdata($user_id);
    $record = get_user_meta($user_id, '_pagenest_social_verified_email', true);
    return $user &&
        pagenest_social_recoverable_email($user->user_email) &&
        is_array($record) &&
        isset($record['email']) &&
        hash_equals($record['email'], hash('sha256', $user->user_email));
}
function pagenest_social_email_changed($user_id, $old)
{
    $user = get_userdata($user_id);
    if ($user && $old->user_email !== $user->user_email) {
        delete_user_meta($user_id, '_pagenest_social_verified_email');
        delete_user_meta($user_id, '_pagenest_social_email_challenge');
    }
}
function pagenest_social_email_request($user_id)
{
    pagenest_social_require_recent($user_id);
    pagenest_social_rate_limit('email', $user_id, 3, 3600);
    return pagenest_social_lock('email:' . $user_id, static function () use ($user_id) {
        $user = get_userdata($user_id);
        if (!$user || !pagenest_social_recoverable_email($user->user_email)) {
            throw new RuntimeException('Please save a usable email address first.');
        }
        $token = bin2hex(random_bytes(32));
        $record = [
            'token' => hash('sha256', $token),
            'email' => hash('sha256', $user->user_email),
            'expires' => time() + 1800,
        ];
        update_user_meta($user_id, '_pagenest_social_email_challenge', $record);
        if (get_user_meta($user_id, '_pagenest_social_email_challenge', true) !== $record) {
            throw new RuntimeException('Unable to request verification.');
        }
        $url = pagenest_social_query_url(
            ['action' => 'pagenest_social_email_confirm', 'token' => $token],
            admin_url('admin-post.php'),
        );
        if (
            !wp_mail(
                $user->user_email,
                '确认你的账号恢复邮箱',
                "请登录发起验证的账号，打开以下链接并确认邮箱。链接30分钟内有效。\n\n" . $url,
            )
        ) {
            delete_user_meta($user_id, '_pagenest_social_email_challenge');
            throw new RuntimeException('Unable to request verification.');
        }
    });
}
function pagenest_social_email_confirm($user_id, $token)
{
    if ((int) $user_id !== get_current_user_id() || !is_user_logged_in()) {
        throw new RuntimeException('Sign in to confirm your email.');
    }
    return pagenest_social_lock('email:' . $user_id, static function () use ($user_id, $token) {
        $user = get_userdata($user_id);
        $record = get_user_meta($user_id, '_pagenest_social_email_challenge', true);
        if (
            !$user ||
            !is_string($token) ||
            !preg_match('/^[a-f0-9]{64}$/D', $token) ||
            !is_array($record) ||
            $record['expires'] <= time() ||
            !hash_equals($record['token'], hash('sha256', $token)) ||
            !hash_equals($record['email'], hash('sha256', $user->user_email))
        ) {
            throw new RuntimeException('Verification expired or account changed.');
        }
        if (!delete_user_meta($user_id, '_pagenest_social_email_challenge', $record)) {
            throw new RuntimeException('Verification already consumed.');
        }
        $verified = ['email' => $record['email'], 'at' => time()];
        update_user_meta($user_id, '_pagenest_social_verified_email', $verified);
        if (get_user_meta($user_id, '_pagenest_social_verified_email', true) !== $verified) {
            throw new RuntimeException('Unable to save verification.');
        }
    });
}
function pagenest_social_security_route()
{
    try {
        if (!pagenest_social_active() || !is_user_logged_in()) {
            throw new RuntimeException('Sign in to continue.');
        }
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        $action = sanitize_key(wp_unslash($_REQUEST['action'] ?? ''));
        if (
            $action === 'pagenest_social_email_confirm' &&
            ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
        ) {
            $token = wp_unslash($_GET['token'] ?? '');
            if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
                throw new RuntimeException('Invalid link.');
            }
            $html =
                '<p>确认将当前账号的邮箱用于账号恢复。</p><form method="post" action="' .
                esc_url(admin_url('admin-post.php')) .
                '"><input type="hidden" name="action" value="pagenest_social_email_confirm"><input type="hidden" name="token" value="' .
                esc_attr($token) .
                '">' .
                wp_nonce_field('pagenest-social-security', '_wpnonce', false, false) .
                '<button type="submit">确认邮箱</button></form>';
            wp_die($html, '确认邮箱', ['response' => 200]);
        }
        if (
            ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
            !wp_verify_nonce(wp_unslash($_POST['_wpnonce'] ?? ''), 'pagenest-social-security')
        ) {
            throw new RuntimeException('Invalid form.');
        }
        $id = get_current_user_id();
        if ($action === 'pagenest_social_password') {
            pagenest_social_verify_password($id, wp_unslash($_POST['password'] ?? ''));
        } elseif ($action === 'pagenest_social_email_request') {
            pagenest_social_email_request($id);
        } elseif ($action === 'pagenest_social_email_confirm') {
            pagenest_social_email_confirm($id, wp_unslash($_POST['token'] ?? ''));
        } else {
            throw new RuntimeException('Invalid action.');
        }
        wp_safe_redirect(get_edit_profile_url($id));
        exit();
    } catch (Throwable $error) {
        wp_die('操作无法完成。请返回个人资料，确认身份、邮箱或稍后重试。', '账号安全', [
            'response' => 400,
        ]);
    }
}

function pagenest_social_email_signin()
{
    nocache_headers();
    header('Referrer-Policy: no-referrer');
    $token = wp_unslash($_GET['token'] ?? '');
    if (
        ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' ||
        !is_string($token) ||
        !preg_match('/^[a-f0-9]{64}$/D', $token)
    ) {
        wp_die('请重新打开邮箱验证链接。', '确认邮箱', ['response' => 400]);
    }
    $return = pagenest_social_query_url(
        ['action' => 'pagenest_social_email_confirm', 'token' => $token],
        admin_url('admin-post.php'),
    );
    wp_safe_redirect(wp_login_url($return));
    exit();
}
