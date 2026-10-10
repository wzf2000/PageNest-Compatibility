<?php
defined('ABSPATH') || exit();
function pagenest_social_config()
{
    return function_exists('pagenest_companion_service_config')
        ? pagenest_companion_service_config('social')
        : [];
}
function pagenest_social_active()
{
    $config = pagenest_social_config();
    $active = array_merge(
        (array) get_option('active_plugins', []),
        array_keys((array) get_site_option('active_sitewide_plugins', [])),
    );
    return ($config['enabled'] ?? false) === true &&
        !in_array('open-social/open-social.php', $active, true) &&
        !is_multisite();
}
function pagenest_social_same_origin($url)
{
    $target = wp_parse_url($url);
    $home = wp_parse_url(home_url('/'));
    return is_array($target) &&
        ($target['scheme'] ?? '') === ($home['scheme'] ?? '') &&
        strtolower($target['host'] ?? '') === strtolower($home['host'] ?? '') &&
        ($target['port'] ?? null) === ($home['port'] ?? null) &&
        !isset($target['user']) &&
        !isset($target['pass']);
}
function pagenest_social_return($url)
{
    return is_string($url) && pagenest_social_same_origin($url) ? esc_url_raw($url) : home_url('/');
}
function pagenest_social_provider_config($provider)
{
    $config = pagenest_social_config()['providers'][$provider] ?? null;
    if (
        !pagenest_social_endpoints($provider) ||
        !is_array($config) ||
        ($config['enabled'] ?? false) !== true ||
        empty($config['client_id']) ||
        empty($config['client_secret']) ||
        empty($config['redirect_uri']) ||
        !pagenest_social_same_origin($config['redirect_uri']) ||
        wp_parse_url($config['redirect_uri'], PHP_URL_SCHEME) !== 'https'
    ) {
        return null;
    }
    $config['client_id'] = (string) $config['client_id'];
    return $config;
}
function pagenest_social_state_create($provider, $config, $mode, $return)
{
    $state = 'pn.' . $provider . '.' . bin2hex(random_bytes(32));
    $browser = bin2hex(random_bytes(32));
    $key = 'pagenest_social_state_' . hash('sha256', $state);
    $record = [
        'expires' => time() + 600,
        'browser' => hash('sha256', $browser),
        'provider' => $provider,
        'app' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'mode' => $mode,
        'user_id' => get_current_user_id(),
        'session' => pagenest_social_session_fingerprint(get_current_user_id()),
        'return' => pagenest_social_return($return),
    ];
    if (!set_transient($key, $record, 600)) {
        throw new RuntimeException('Unable to start authorization.');
    }
    $cookie = 'pn_social_' . substr(hash('sha256', $state), 0, 20);
    if (
        !setcookie($cookie, $browser, [
            'expires' => time() + 600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ])
    ) {
        delete_transient($key);
        throw new RuntimeException('Unable to set browser cookie.');
    }
    return $state;
}
function pagenest_social_state_consume($state, $browser, $provider, $config)
{
    $key = 'pagenest_social_state_' . hash('sha256', $state);
    return pagenest_social_lock($key, static function () use ($key, $browser, $provider, $config) {
        $record = get_transient($key);
        if (
            !is_array($record) ||
            $record['expires'] < time() ||
            !is_string($browser) ||
            !hash_equals($record['browser'], hash('sha256', $browser)) ||
            $record['provider'] !== $provider ||
            $record['app'] !== $config['client_id'] ||
            $record['redirect_uri'] !== $config['redirect_uri'] ||
            $record['user_id'] !== get_current_user_id() ||
            !in_array($record['mode'], ['login', 'bind', 'reauth'], true) ||
            ($record['session'] ?? '') !==
                pagenest_social_session_fingerprint(get_current_user_id()) ||
            (in_array($record['mode'], ['bind', 'reauth'], true) && !$record['user_id']) ||
            ($record['mode'] === 'login' && $record['user_id'])
        ) {
            throw new RuntimeException('Authorization expired or browser changed.');
        }
        if (!delete_transient($key)) {
            throw new RuntimeException('Authorization was already consumed.');
        }
        return $record;
    });
}
function pagenest_social_start()
{
    try {
        if (!pagenest_social_active()) {
            throw new RuntimeException('Social login is unavailable.');
        }
        $provider = sanitize_key(wp_unslash($_REQUEST['provider'] ?? ''));
        $config = pagenest_social_provider_config($provider);
        if (!$config) {
            throw new RuntimeException('Provider is unavailable.');
        }
        $mode = is_user_logged_in()
            ? (($_POST['mode'] ?? '') === 'reauth'
                ? 'reauth'
                : 'bind')
            : 'login';
        if (
            $mode !== 'login' &&
            (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
                !wp_verify_nonce(wp_unslash($_POST['_wpnonce'] ?? ''), 'pagenest-social-bind'))
        ) {
            throw new RuntimeException('Binding requires a valid signed form.');
        }
        if ($mode === 'bind') {
            pagenest_social_require_recent(get_current_user_id());
        }
        $state = pagenest_social_state_create(
            $provider,
            $config,
            $mode,
            wp_unslash($_REQUEST['return'] ?? home_url('/')),
        );
        nocache_headers();
        wp_redirect(pagenest_social_authorize_url($provider, $config, $state));
        exit();
    } catch (Throwable $error) {
        wp_die(
            '社交账号请求无法完成。管理绑定时，请先返回个人资料确认身份，并确保解除连接后仍有可用的登录方式或已验证邮箱；登录失败请重新开始。',
            'Social login',
            ['response' => 400],
        );
    }
}
function pagenest_social_callback()
{
    $state = wp_unslash($_GET['state'] ?? '');
    if (
        !is_string($state) ||
        !preg_match('/^pn\.(qq|sina|baidu)\.[a-f0-9]{64}$/D', $state, $match)
    ) {
        return;
    }
    try {
        if (!pagenest_social_active()) {
            throw new RuntimeException('Social login is unavailable.');
        }
        $provider = $match[1];
        $config = pagenest_social_provider_config($provider);
        if (!$config) {
            throw new RuntimeException('Provider is unavailable.');
        }
        if (!pagenest_social_callback_matches($config['redirect_uri'])) {
            throw new RuntimeException('Unexpected callback location.');
        }
        $cookie = 'pn_social_' . substr(hash('sha256', $state), 0, 20);
        $record = pagenest_social_state_consume(
            $state,
            $_COOKIE[$cookie] ?? '',
            $provider,
            $config,
        );
        setcookie($cookie, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $code = wp_unslash($_GET['code'] ?? '');
        if (
            !is_string($code) ||
            $code === '' ||
            strlen($code) > 2048 ||
            isset($_GET['error']) ||
            isset($_GET['error_code'])
        ) {
            throw new RuntimeException('Authorization cancelled or invalid.');
        }
        $identity = pagenest_social_exchange($provider, $config, $code);
        $key = pagenest_social_identity_key($provider, $config['client_id'], $identity['subject']);
        if ($record['mode'] === 'reauth') {
            pagenest_social_reauthenticate_identity(
                $record['user_id'],
                $provider,
                $config['client_id'],
                $identity['subject'],
            );
            wp_safe_redirect($record['return']);
            exit();
        }
        if ($record['mode'] === 'bind') {
            pagenest_social_require_recent($record['user_id']);
            $user_id = pagenest_social_bind(
                $record['user_id'],
                $provider,
                $config['client_id'],
                $identity['subject'],
            );
        } else {
            $user_id = pagenest_social_login_identity($provider, $config, $identity);
            if (!$user_id) {
                $pending = pagenest_social_pending_create(
                    $provider,
                    $config,
                    $identity,
                    $record['return'],
                );
                wp_safe_redirect(
                    pagenest_social_query_url(
                        ['action' => 'pagenest_social_register', 'pending' => $pending],
                        admin_url('admin-post.php'),
                    ),
                );
                exit();
            }
            pagenest_social_complete_login($user_id);
        }
        if ($identity['avatar']) {
            update_user_meta($user_id, '_pagenest_social_avatar', $identity['avatar']);
        }
        nocache_headers();
        wp_safe_redirect($record['return']);
        exit();
    } catch (Throwable $error) {
        wp_die(
            '社交账号请求无法完成。管理绑定时，请先返回个人资料确认身份，并确保解除连接后仍有可用的登录方式或已验证邮箱；登录失败请重新开始。',
            'Social login',
            ['response' => 400],
        );
    }
}
function pagenest_social_unbind_route()
{
    try {
        if (
            !pagenest_social_active() ||
            !is_user_logged_in() ||
            ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
            !wp_verify_nonce(wp_unslash($_POST['_wpnonce'] ?? ''), 'pagenest-social-unbind')
        ) {
            throw new RuntimeException('Invalid disconnect request.');
        }
        pagenest_social_require_recent(get_current_user_id());
        pagenest_social_unbind(
            get_current_user_id(),
            sanitize_key(wp_unslash($_POST['identity'] ?? '')),
        );
        wp_safe_redirect(get_edit_profile_url(get_current_user_id()));
        exit();
    } catch (Throwable $error) {
        wp_die(
            '社交账号请求无法完成。管理绑定时，请先返回个人资料确认身份，并确保解除连接后仍有可用的登录方式或已验证邮箱；登录失败请重新开始。',
            'Social login',
            ['response' => 400],
        );
    }
}

function pagenest_social_callback_matches($redirect_uri)
{
    $configured = wp_parse_url($redirect_uri);
    $request = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
    if (($configured['path'] ?? '/') !== ($request['path'] ?? '/')) {
        return false;
    }
    parse_str($configured['query'] ?? '', $required);
    foreach ($required as $name => $value) {
        if (!isset($_GET[$name]) || wp_unslash($_GET[$name]) !== $value) {
            return false;
        }
    }
    return true;
}
