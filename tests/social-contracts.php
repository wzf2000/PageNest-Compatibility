<?php
/** In-memory WordPress contract harness; no database, email or external HTTP. */
define('ABSPATH', __DIR__);
$GLOBALS['options'] = [];
$GLOBALS['meta'] = [];
$GLOBALS['users'] = [];
$GLOBALS['current'] = 0;
$GLOBALS['config'] = [
    'enabled' => true,
    'providers' => [],
    'legacy_apps' => ['qq' => 'app', 'github' => 'historic'],
];
function pagenest_companion_service_config($name)
{
    return $GLOBALS['config'];
}
function get_option($key, $default = false)
{
    return $GLOBALS['options'][$key] ?? $default;
}
function set_transient($key, $value, $ttl)
{
    return add_option($key, $value);
}
function get_transient($key)
{
    return get_option($key);
}
function delete_transient($key)
{
    return delete_option($key);
}
function get_site_option($key, $default = false)
{
    return $default;
}
function add_option($key, $value, $deprecated = '', $autoload = false)
{
    if (str_starts_with($key, '_pagenest_social_identity_') && !empty($GLOBALS['fail_index'])) {
        $GLOBALS['fail_index'] = false;
        return false;
    }
    if (array_key_exists($key, $GLOBALS['options'])) {
        return false;
    }
    $GLOBALS['options'][$key] = $value;
    return true;
}
function update_option($key, $value, $autoload = false)
{
    $GLOBALS['options'][$key] = $value;
    return true;
}
function delete_option($key)
{
    if (!array_key_exists($key, $GLOBALS['options'])) {
        return false;
    }
    unset($GLOBALS['options'][$key]);
    return true;
}
function get_user_meta($id, $key = '', $single = false)
{
    if ($key === '') {
        return array_map(static fn($v) => [$v], $GLOBALS['meta'][$id] ?? []);
    }
    return $GLOBALS['meta'][$id][$key] ?? ($single ? '' : []);
}
function metadata_exists($type, $id, $key)
{
    return isset($GLOBALS['meta'][$id][$key]);
}
function delete_user_meta($id, $key)
{
    unset($GLOBALS['meta'][$id][$key]);
    return true;
}
function wp_delete_user($id)
{
    unset($GLOBALS['users'][$id], $GLOBALS['meta'][$id]);
    return true;
}
function update_user_meta($id, $key, $value)
{
    if (!empty($GLOBALS['fail_meta'])) {
        $GLOBALS['fail_meta'] = false;
        return false;
    }
    $GLOBALS['meta'][$id][$key] = $value;
    return true;
}
function get_users($args)
{
    if (isset($args['meta_key'])) {
        return array_values(
            array_filter(
                array_keys($GLOBALS['users']),
                static fn($id) => get_user_meta($id, $args['meta_key'], true) ===
                    $args['meta_value'],
            ),
        );
    }
    return array_keys($GLOBALS['users']);
}
function get_userdata($id)
{
    return $GLOBALS['users'][$id] ?? false;
}
function get_current_user_id()
{
    return $GLOBALS['current'];
}
function home_url($path = '/')
{
    return 'https://example.test' . $path;
}
function wp_unslash($value)
{
    return $value;
}
function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}
function esc_url_raw($url)
{
    return $url;
}
function wp_json_encode($data)
{
    return json_encode($data);
}
function is_email($value)
{
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}
function is_multisite()
{
    return false;
}
function sanitize_text_field($value)
{
    return strip_tags($value);
}
function add_query_arg($args, $url)
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}
function is_wp_error($value)
{
    return $value instanceof Exception;
}
function wp_remote_request($url, $args)
{
    $GLOBALS['requests'][] = [$url, $args];
    return array_shift($GLOBALS['responses']);
}
function wp_remote_retrieve_response_code($response)
{
    return $response['status'];
}
function wp_remote_retrieve_body($response)
{
    return $response['body'];
}
require __DIR__ . '/../modules/social/providers.php';
require __DIR__ . '/../modules/social/identities.php';
require __DIR__ . '/../modules/social/routes.php';
require __DIR__ . '/../modules/social/migration.php';
function add_filter(...$args) {}
require __DIR__ . '/../modules/social/placeholder-mail.php';
$checks = 0;
function check($condition, $message)
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function rejects($callback, $message)
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        check(true, $message);
        return;
    }
    check(false, $message);
}
function response($body, $status = 200)
{
    return ['status' => $status, 'body' => $body];
}
$GLOBALS['users'] = [
    1 => (object) ['user_email' => 'original@fake.com'],
    2 => (object) ['user_email' => 'real@example.test'],
];
$GLOBALS['meta'] = [
    1 => [
        'open_type_qq' => 'original-subject',
        'open_type_github' => 'historical-subject',
        'open_access_token' => 'preserve-only',
        'private' => 'unchanged',
    ],
];
$old = $GLOBALS['meta'][1];
$dry = pagenest_social_migration();
check($dry['identities'] === 2, 'includes historical provider');
check($GLOBALS['meta'][1] === $old, 'dry run does not touch metadata');
pagenest_social_migration(true);
$key = pagenest_social_identity_key('qq', 'app', 'original-subject');
check(get_option($key)['user_id'] === 1, 'original ID mapped');
foreach ($old as $name => $value) {
    check(get_user_meta(1, $name, true) === $value, 'legacy fields retained');
}
$before = $GLOBALS['meta'];
pagenest_social_migration(true);
check($before === $GLOBALS['meta'], 'repeat apply is idempotent');
rejects(
    static fn() => pagenest_social_bind(2, 'qq', 'app', 'original-subject'),
    'cross user conflict',
);
rejects(
    static fn() => pagenest_social_unbind(1, $key),
    'last usable identity protected even with historical Github',
);
$GLOBALS['config']['providers']['qq'] = [
    'enabled' => true,
    'client_id' => 'app',
    'client_secret' => 'mock-secret',
    'redirect_uri' => 'https://example.test/old-callback/',
];
rejects(
    static fn() => pagenest_social_bind(1, 'qq', 'app', 'another-subject'),
    'one identity per app and provider',
);
check(
    pagenest_social_identity_key('qq', 'other-app', 'original-subject') !== $key,
    'application namespaces differ',
);
pagenest_social_bind(2, 'qq', 'app', 'new-subject');
$newkey = pagenest_social_identity_key('qq', 'app', 'new-subject');
pagenest_social_unbind(2, $newkey);
check(get_option($newkey)['user_id'] === 0, 'disconnect tombstone');
$GLOBALS['users'][1]->user_email = 'real@example.test';
pagenest_social_unbind(1, $key);
pagenest_social_migration(true);
check(
    get_option($key)['user_id'] === 0,
    'migration never revives disconnected historical identity',
);
check(
    !pagenest_social_recoverable_email('placeholder@social.invalid'),
    'new placeholder cannot recover',
);
check(
    !pagenest_social_recoverable_email('placeholder@fake.com'),
    'legacy placeholder cannot recover',
);
check(pagenest_social_return('https://evil.test/') === home_url('/'), 'external return rejected');
check(
    pagenest_social_return('https://example.test.evil.test/') === home_url('/'),
    'host suffix rejected',
);
check(
    pagenest_social_return('https://example.test:444/') === home_url('/'),
    'foreign port rejected',
);
check(
    pagenest_social_avatar_url('http://q1.qlogo.cn/a') === 'https://q1.qlogo.cn/a',
    'legacy avatar upgrades TLS',
);
check(
    pagenest_social_avatar_url('https://qlogo.cn.evil.test/a') === '',
    'avatar host suffix rejected',
);
check(
    pagenest_social_avatar_url('https://user:secret@qlogo.cn/a') === '',
    'avatar credentials rejected',
);
$state = 'pn.qq.' . str_repeat('a', 64);
$statekey = 'pagenest_social_state_' . hash('sha256', $state);
$provider_config = $GLOBALS['config']['providers']['qq'];
$record = [
    'expires' => time() + 600,
    'browser' => hash('sha256', 'browser'),
    'provider' => 'qq',
    'app' => 'app',
    'redirect_uri' => $provider_config['redirect_uri'],
    'mode' => 'login',
    'user_id' => 0,
    'return' => home_url('/'),
];
add_option($statekey, $record);
rejects(
    static fn() => pagenest_social_state_consume($state, 'wrong-browser', 'qq', $provider_config),
    'state browser mismatch',
);
$GLOBALS['current'] = 2;
rejects(
    static fn() => pagenest_social_state_consume($state, 'browser', 'qq', $provider_config),
    'state user mismatch',
);
$GLOBALS['current'] = 0;
rejects(
    static fn() => pagenest_social_state_consume(
        $state,
        'browser',
        'qq',
        array_merge($provider_config, ['client_id' => 'another']),
    ),
    'state application mismatch',
);
check(
    pagenest_social_state_consume($state, 'browser', 'qq', $provider_config) === $record,
    'state consumed',
);
rejects(
    static fn() => pagenest_social_state_consume($state, 'browser', 'qq', $provider_config),
    'state cannot replay',
);
$record['expires'] = time() - 1;
add_option($statekey, $record);
rejects(
    static fn() => pagenest_social_state_consume($state, 'browser', 'qq', $provider_config),
    'state expires',
);
$record['expires'] = time() + 600;
$record['mode'] = 'bind';
update_option($statekey, $record);
rejects(
    static fn() => pagenest_social_state_consume($state, 'browser', 'qq', $provider_config),
    'anonymous binding rejected',
);
$GLOBALS['responses'] = [
    response('access_token=mock-token'),
    response('callback( {"client_id":"app","openid":"qq-subject"} );'),
    response('{"ret":0,"nickname":"Name","figureurl_qq_2":"https://q1.qlogo.cn/avatar"}'),
];
$identity = pagenest_social_exchange('qq', $provider_config, 'mock-code');
check($identity['subject'] === 'qq-subject', 'QQ callback parses wrapped OpenID');
foreach ($GLOBALS['requests'] as [$url, $args]) {
    check($args['sslverify'] === true && $args['redirection'] === 0, 'TLS and redirect guard');
}
check(
    str_contains($GLOBALS['requests'][0][0], rawurlencode($provider_config['redirect_uri'])),
    'original redirect URI retained for exchange',
);
$GLOBALS['responses'] = [
    response('access_token=mock-token'),
    response('callback({"client_id":"other-app","openid":"qq-subject"});'),
];
rejects(
    static fn() => pagenest_social_exchange('qq', $provider_config, 'code'),
    'QQ returned application mismatch',
);
$GLOBALS['responses'] = [
    response('{"access_token":"mock-token","uid":"123"}'),
    response('{"idstr":"123","screen_name":"Name","avatar_large":"https://a.sinaimg.cn/avatar"}'),
];
check(
    pagenest_social_exchange('sina', $provider_config, 'mock-code')['subject'] === '123',
    'Sina subject retained',
);
$GLOBALS['responses'] = [
    response('{"access_token":"mock-token"}'),
    response('{"uid":"321","uname":"Name","portrait":"portrait"}'),
];
check(
    pagenest_social_exchange('baidu', $provider_config, 'mock-code')['subject'] === '321',
    'Baidu subject retained',
);
$GLOBALS['responses'] = [response('{"error":"denied"}')];
rejects(
    static fn() => pagenest_social_exchange('baidu', $provider_config, 'mock-code'),
    'provider denial fails closed',
);
$GLOBALS['responses'] = [response('{}', 302)];
rejects(
    static fn() => pagenest_social_exchange('qq', $provider_config, 'mock-code'),
    'provider redirect fails closed',
);
$GLOBALS['options']['active_plugins'] = ['open-social/open-social.php'];
check(!pagenest_social_active(), 'legacy plugin blocks takeover');
$GLOBALS['options']['active_plugins'] = [];
check(pagenest_social_active(), 'explicit configuration enables takeover');
$GLOBALS['config']['enabled'] = false;
check(!pagenest_social_active(), 'disabled service stays disabled');

$GLOBALS['config']['enabled'] = true;
$GLOBALS['config']['allow_registration'] = true;
$GLOBALS['options']['users_can_register'] = true;
function wp_generate_password($length = 12, $special = true, $extra = false)
{
    return str_repeat('x', $length);
}
function wp_insert_user($data)
{
    $id = count($GLOBALS['users']) + 1;
    $GLOBALS['users'][$id] = (object) $data;
    return $id;
}
$provider_config = $GLOBALS['config']['providers']['qq'];
$identity = ['subject' => 'registration-subject', 'name' => 'Synthetic'];
$id = pagenest_social_login_identity('qq', $provider_config, $identity);
check($GLOBALS['users'][$id]->role === 'subscriber', 'registration minimum role');
check(
    str_ends_with($GLOBALS['users'][$id]->user_email, '@social.invalid'),
    'registration placeholder',
);
check(
    pagenest_social_login_identity('qq', $provider_config, $identity) === $id,
    'registration idempotent',
);
check(
    get_option(pagenest_social_identity_key('qq', 'app', $identity['subject']))['user_id'] === $id,
    'registration permanent unique index',
);
$legacykey = pagenest_social_identity_key('qq', 'app', 'original-subject');
delete_option($legacykey);
check(
    pagenest_social_login_identity('qq', $provider_config, [
        'subject' => 'original-subject',
        'name' => 'Ignored',
    ]) === 1,
    'read only exact scoped legacy login',
);
$GLOBALS['config']['legacy_apps']['qq'] = 'other';
$GLOBALS['config']['allow_registration'] = false;
rejects(
    static fn() => pagenest_social_login_identity('qq', $provider_config, [
        'subject' => 'original-subject',
        'name' => 'Ignored',
    ]),
    'legacy application mismatch rejected',
);

$failurekey = pagenest_social_identity_key('qq', 'app', 'failed-bind');
$GLOBALS['fail_meta'] = true;
rejects(
    static fn() => pagenest_social_bind(2, 'qq', 'app', 'failed-bind'),
    'bind metadata failure',
);
check(get_option($failurekey, null) === null, 'failed bind has no active index');
$GLOBALS['fail_index'] = true;
rejects(static fn() => pagenest_social_bind(2, 'qq', 'app', 'failed-bind'), 'bind index failure');
check(
    !isset(get_user_meta(2, '_pagenest_social_bindings', true)[$failurekey]),
    'failed index restores user bindings',
);
$GLOBALS['config']['allow_registration'] = true;
$count = count($GLOBALS['users']);
$GLOBALS['fail_meta'] = true;
rejects(
    static fn() => pagenest_social_login_identity('qq', $provider_config, [
        'subject' => 'failed-registration',
        'name' => 'Synthetic',
    ]),
    'registration metadata failure',
);
check(count($GLOBALS['users']) === $count, 'failed new registration removes only new account');
$GLOBALS['fail_index'] = true;
rejects(
    static fn() => pagenest_social_login_identity('qq', $provider_config, [
        'subject' => 'failed-registration',
        'name' => 'Synthetic',
    ]),
    'registration index failure',
);
check(count($GLOBALS['users']) === $count, 'index failure rolls back new registration');
$_SERVER['REQUEST_URI'] = '/original-callback/?code=x&state=y';
$_GET = [];
check(
    pagenest_social_callback_matches('https://example.test/original-callback/'),
    'callback configured path accepted',
);
check(
    !pagenest_social_callback_matches('https://example.test/other-callback/'),
    'callback wrong path refused',
);
check(
    !pagenest_social_callback_matches('https://example.test/original-callback/?required=yes'),
    'callback required query refused',
);
$_GET['required'] = 'yes';
check(
    pagenest_social_callback_matches('https://example.test/original-callback/?required=yes'),
    'callback original query accepted',
);

$racekey = pagenest_social_identity_key('qq', 'app', 'race');
add_option($racekey, ['user_id' => 0, 'unbound_from' => 1]);
check(
    pagenest_social_bind(1, 'qq', 'app', 'race', true) === null,
    'migration rechecks tombstone under identity lock',
);
check(
    get_option($racekey)['user_id'] === 0,
    'migration racing with disconnect never revives identity',
);
check(
    pagenest_social_placeholder_mail(null, [
        'to' => ['Name <placeholder@fake.com>', 'real@example.test'],
    ]) === false,
    'legacy placeholder mail refused independently',
);
check(
    pagenest_social_placeholder_mail(null, [
        'to' => 'real@example.test',
        'headers' => 'Bcc: placeholder@social.invalid',
    ]) === false,
    'placeholder BCC refused',
);
check(
    pagenest_social_placeholder_mail(null, ['to' => 'real@example.test']) === null,
    'real recipient mail unchanged',
);
check(
    pagenest_social_password_message('Reset: <https://example.test/reset/>') ===
        'Reset: https://example.test/reset/',
    'legacy password email link normalization',
);

$GLOBALS['responses'] = [
    response('{"access_token":"mock-token","uid":123456789012345678901}'),
    response('{"id":123456789012345678901,"screen_name":"Name"}'),
];
check(
    pagenest_social_exchange('sina', $provider_config, 'code')['subject'] ===
        '123456789012345678901',
    'large provider subject retains exact decimal identity',
);
function is_user_logged_in()
{
    return (bool) get_current_user_id();
}
function sanitize_key($value)
{
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($value));
}
function wp_verify_nonce($value, $action)
{
    return false;
}
function wp_die($message, $title = '', $args = [])
{
    throw new RuntimeException('fixture safe error');
}
$GLOBALS['current'] = 2;
$_REQUEST = ['provider' => 'qq'];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
check(pagenest_social_provider_config('qq') !== null, 'binding route has available provider');
rejects(static fn() => pagenest_social_start(), 'binding GET refused before authorization state');
$_SERVER['REQUEST_METHOD'] = 'POST';
rejects(static fn() => pagenest_social_start(), 'binding missing or invalid nonce refused');
rejects(static fn() => pagenest_social_unbind_route(), 'disconnect missing nonce refused');
$GLOBALS['current'] = 0;
rejects(static fn() => pagenest_social_unbind_route(), 'anonymous disconnect refused');
echo "Social contracts: $checks checks passed; mock HTTP only.\n";
