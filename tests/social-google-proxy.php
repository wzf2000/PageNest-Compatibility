<?php
/** Real WP hook dispatcher and cURL; owned Unix socket only, no external HTTP. */
$root = $argv[1] ?? '';
if (!is_file($root . '/wp-includes/plugin.php')) {
    throw new RuntimeException('WordPress core path required.');
}
define('ABSPATH', $root . '/');
require $root . '/wp-includes/plugin.php';
require $root . '/wp-includes/Requests/src/Autoload.php';
WpOrg\Requests\Autoload::register();
require dirname(__DIR__) . '/modules/social/google-proxy.php';
$checks = 0;
function check($condition, $label)
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($label);
    }
}
function rejects($call, $label)
{
    try {
        $call();
    } catch (RuntimeException $error) {
        check(true, $label);
        return;
    }
    check(false, $label);
}
function pagenest_social_config()
{
    return $GLOBALS['fixture_config'];
}
function wp_remote_request($url, $args)
{
    $GLOBALS['fixture_calls']++;
    $headers = ['Proxy-Authorization' => 'synthetic-global-proxy-auth'];
    $data = null;
    $type = 'GET';
    $options = [
        'transport' => 'synthetic-non-curl',
        'verify' => false,
        'verifyname' => false,
        'proxy' => 'synthetic-global-proxy',
    ];
    do_action_ref_array('requests-requests.before_request', [
        &$url,
        &$headers,
        &$data,
        &$type,
        &$options,
    ]);
    $GLOBALS['fixture_options'] = $options;
    $GLOBALS['fixture_headers'] = $headers;
    if (!empty($GLOBALS['fixture_throw'])) {
        throw new RuntimeException('Synthetic request failure.');
    }
    if (!empty($GLOBALS['fixture_execute'])) {
        // The actual handle only names loopback. Even a broken proxy selection
        // can never reach Google; the local socket accepts no upstream traffic.
        $handle = curl_init('https://127.0.0.1:1/');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 120,
            CURLOPT_CONNECTTIMEOUT_MS => 120,
        ]);
        do_action('http_api_curl', $handle, $args, $url);
        do_action('requests-curl.before_send', $handle);
        curl_exec($handle);
        $client = @stream_socket_accept($GLOBALS['fixture_listener'], 0.2);
        $GLOBALS['fixture_greeting'] = $client ? fread($client, 3) : '';
        if ($client) {
            fclose($client);
        }
    }
    return ['synthetic' => true];
}
$GLOBALS['fixture_calls'] = 0;
$GLOBALS['fixture_config'] = ['google_proxy' => ['enabled' => false, 'unix_socket' => '']];
$targets = ['https://oauth2.googleapis.com/token', 'https://www.googleapis.com/oauth2/v1/certs'];
check(
    pagenest_social_google_proxy_request($targets[0], []) === ['synthetic' => true],
    'default disabled proxy delegates unchanged',
);
$GLOBALS['fixture_config']['google_proxy'] = [
    'enabled' => true,
    'unix_socket' => '/tmp/pagenest-no-such-proxy-' . bin2hex(random_bytes(6)),
];
$count = $GLOBALS['fixture_calls'];
rejects(
    static fn() => pagenest_social_google_proxy_request($targets[0], []),
    'missing socket fails before HTTP',
);
check($GLOBALS['fixture_calls'] === $count, 'missing socket never falls back to direct network');
foreach (
    [
        'https://api.github.com/user',
        'https://www.googleapis.com/oauth2/v1/certs?x=1',
        'https://oauth2.googleapis.com/token/other',
        'http://oauth2.googleapis.com/token',
    ]
    as $other
) {
    check(
        pagenest_social_google_proxy_request($other, []) === ['synthetic' => true],
        'nonexact endpoint never uses Google proxy',
    );
}
$path = sys_get_temp_dir() . '/pagenest-google-proxy-test-' . bin2hex(random_bytes(6)) . '.sock';
$listener = stream_socket_server('unix://' . $path, $errno, $error);
if (!$listener) {
    throw new RuntimeException('Unable to create synthetic Unix listener.');
}
chmod($path, 0600);
$GLOBALS['fixture_listener'] = $listener;
$GLOBALS['fixture_config']['google_proxy']['unix_socket'] = $path;
try {
    $GLOBALS['fixture_execute'] = true;
    foreach ($targets as $target) {
        check(
            pagenest_social_google_proxy_request($target, []) === ['synthetic' => true],
            'eligible request completes through scoped hooks',
        );
        check(
            $GLOBALS['fixture_greeting'] === "\x05\x01\x00",
            'Unix socket receives SOCKS5 no-auth greeting instead of raw TLS',
        );
        check(
            $GLOBALS['fixture_options']['transport'] === 'WpOrg\\Requests\\Transport\\Curl',
            'Requests transport forced to cURL',
        );
        check(
            $GLOBALS['fixture_options']['verify'] === true &&
                $GLOBALS['fixture_options']['verifyname'] === true &&
                $GLOBALS['fixture_options']['follow_redirects'] === false,
            'TLS and no-redirect requirements preserved',
        );
        check(
            !isset($GLOBALS['fixture_headers']['Proxy-Authorization']),
            'global proxy authorization header removed',
        );
        check(
            !has_action('http_api_curl') &&
                !has_action('requests-requests.before_request') &&
                !has_action('requests-curl.before_send'),
            'successful request removes temporary hooks',
        );
    }
    $GLOBALS['fixture_execute'] = false;
    $GLOBALS['fixture_throw'] = true;
    rejects(
        static fn() => pagenest_social_google_proxy_request($targets[0], []),
        'transport exception propagates',
    );
    check(
        !has_action('http_api_curl') &&
            !has_action('requests-requests.before_request') &&
            !has_action('requests-curl.before_send'),
        'exception also removes temporary hooks',
    );
    $GLOBALS['fixture_throw'] = false;
    rejects(
        static fn() => pagenest_social_google_proxy_request($targets[0], []),
        'preempted request without tunnel fails closed',
    );
    check(
        !has_action('http_api_curl') && !has_action('requests-requests.before_request'),
        'preempted request leaves no global hooks',
    );
    echo "Google proxy contracts: $checks checks passed; Unix socket only.\n";
} finally {
    fclose($listener);
    unlink($path);
}
