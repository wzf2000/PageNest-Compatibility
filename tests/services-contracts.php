<?php
/** Offline tests run WordPress' actual wp_mail construction with mocked HTTP, never SMTP. */
$root = $argv[1] ?? getenv('PAGENEST_TEST_WP_ROOT');
if (!$root || !is_file($root . '/wp-includes/pluggable.php')) {
    fwrite(STDERR, "Usage: php tests/services-contracts.php /path/to/wordpress\n");
    exit(1);
}
define('ABSPATH', rtrim($root, '/') . '/');
define('WPINC', 'wp-includes');
$hooks = [];
$events = [];
$requests = [];
$profiles = [
    'mail' => [
        'enabled' => true,
        'api_key' => 'test-not-a-key',
        'from_email' => 'sender@example.test',
        'from_name' => 'Test Sender',
        'reply_to_email' => 'configured@example.test',
        'reply_to_name' => 'Configured',
    ],
    'turnstile' => [
        'enabled' => true,
        'site_key' => 'test-public-key',
        'secret_key' => 'test-not-a-secret',
        'hostname' => 'example.test',
        'scopes' => ['login' => true, 'register' => true, 'reset' => true, 'comments' => true],
    ],
    'output' => ['enabled' => false, 'html_whitespace' => false],
];
$response = ['response' => ['code' => 201], 'body' => '{"messageId":"accepted-test"}'];
class WP_Error
{
    public function __construct(public $code = '', public $message = '', public $data = null) {}
    public function get_error_message()
    {
        return $this->message;
    }
    public function add($code, $message)
    {
        $this->code = $code;
        $this->message = $message;
    }
}
function add_filter($name, $callback, $priority = 10, $accepted = 1)
{
    $GLOBALS['hooks'][$name][$priority][] = [$callback, $accepted];
}
function add_action(...$args)
{
    add_filter(...$args);
}
function apply_filters($name, $value, ...$args)
{
    $hooks = $GLOBALS['hooks'][$name] ?? [];
    ksort($hooks);
    foreach ($hooks as $items) {
        foreach ($items as [$callback, $accepted]) {
            $value = $callback(...array_slice([$value, ...$args], 0, $accepted));
        }
    }
    return $value;
}
function do_action_ref_array($name, $args)
{
    $GLOBALS['events'][$name][] = $args;
    $hooks = $GLOBALS['hooks'][$name] ?? [];
    ksort($hooks);
    foreach ($hooks as $items) {
        foreach ($items as [$callback, $accepted]) {
            $callback(...array_slice($args, 0, $accepted));
        }
    }
}
function do_action($name, ...$args)
{
    do_action_ref_array($name, $args);
}
function pagenest_companion_service_config($key)
{
    return $GLOBALS['profiles'][$key];
}
function is_email($value)
{
    return filter_var($value, FILTER_VALIDATE_EMAIL);
}
function is_wp_error($value)
{
    return $value instanceof WP_Error;
}
function wp_json_encode($value)
{
    return json_encode($value);
}
function wp_parse_url(...$args)
{
    return parse_url(...$args);
}
function network_home_url()
{
    return 'https://example.test/';
}
function get_bloginfo($key)
{
    return 'UTF-8';
}
function wp_remote_post($url, $options)
{
    $GLOBALS['requests'][] = [$url, $options];
    return $GLOBALS['response'];
}
function wp_remote_retrieve_body($response)
{
    return $response['body'];
}
function wp_remote_retrieve_response_code($response)
{
    return $response['response']['code'];
}
function wp_unslash($value)
{
    return stripslashes($value);
}
function __($value, ...$args)
{
    return $value;
}
function check($result, $label)
{
    if (!$result) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
}
require ABSPATH . WPINC . '/pluggable.php';
require dirname(__DIR__) . '/modules/services/bootstrap.php';
pagenest_companion_services_bootstrap();
$filterCount = 0;
add_filter('wp_mail', function ($atts) use (&$filterCount) {
    $filterCount++;
    $atts['subject'] .= ' filtered';
    return $atts;
});
add_filter('wp_mail_from', function ($value) {
    return 'filtered@example.test';
});
add_filter('wp_mail_from_name', function ($value) {
    return 'Filtered';
});
add_action('phpmailer_init', function ($mailer) {
    $mailer->addStringAttachment('test attachment', 'contract.txt');
    $mailer->AltBody = 'Alternative body';
});
check(
    wp_mail(['Reader <reader@example.test>'], 'Subject', '<b>Body</b>', [
        'Content-Type: text/html; charset=UTF-8',
        'Reply-To: Original <original@example.test>',
        'Cc: cc@example.test',
        'Bcc: bcc@example.test',
        'X-Test: custom',
    ]),
    'HTML request accepted',
);
check($filterCount === 1, 'wp_mail filter runs once');
$payload = json_decode($requests[0][1]['body'], true);
check(
    $payload['sender'] === ['email' => 'sender@example.test', 'name' => 'Test Sender'],
    'forced sender wins after hooks',
);
check($payload['replyTo']['email'] === 'original@example.test', 'configured Reply-To appends');
check(
    $payload['to'][0]['name'] === 'Reader' &&
        $payload['cc'][0]['email'] === 'cc@example.test' &&
        $payload['bcc'][0]['email'] === 'bcc@example.test',
    'core recipient parsing',
);
check(
    $payload['htmlContent'] === '<b>Body</b>' &&
        $payload['textContent'] === 'Alternative body' &&
        $payload['subject'] === 'Subject filtered',
    'core body and init hook',
);
check(
    base64_decode($payload['attachment'][0]['content']) === 'test attachment' &&
        $payload['headers']['X-Test'] === 'custom',
    'string attachments/custom headers',
);
check(
    count($events['wp_mail_succeeded']) === 1 && empty($events['wp_mail_failed']),
    'core success event',
);
$GLOBALS['hooks']['phpmailer_init'] = [];
check(wp_mail('next@example.test', 'Plain', 'Plain body'), 'second message');
$payload = json_decode(end($requests)[1]['body'], true);
check(
    !isset($payload['htmlContent'], $payload['attachment'], $payload['cc'], $payload['bcc']) &&
        $payload['textContent'] === 'Plain body',
    'no previous message state',
);
check(
    $payload['replyTo']['email'] === 'configured@example.test',
    'configured Reply-To without original',
);
$before = count($requests);
check(
    !wp_mail('account@fake.com', 'Placeholder', 'Body') && count($requests) === $before,
    'fake recipient rejected before network',
);
$response = new WP_Error('timeout', 'request contains secret test-not-a-key');
check(!wp_mail('next@example.test', 'Timeout', 'Body'), 'timeout fails');
$failure = end($events['wp_mail_failed'])[0];
check(
    !str_contains($failure->get_error_message(), 'test-not-a-key'),
    'failure message does not leak HTTP detail',
);
$response = ['response' => ['code' => 400], 'body' => '{"message":"contains test-not-a-key"}'];
check(!wp_mail('next@example.test', 'Rejected', 'Body'), 'provider rejection fails');
$response = ['response' => ['code' => 201], 'body' => '{}'];
check(!wp_mail('next@example.test', 'Bad response', 'Body'), 'missing acceptance marker fails');
add_action('phpmailer_init', function ($mailer) {
    $mailer->addStringEmbeddedImage('image bytes', 'cid-one', 'one.png');
});
$before = count($requests);
check(
    !wp_mail('next@example.test', 'Embed', '<img src="cid:cid-one">', 'Content-Type: text/html') &&
        count($requests) === $before,
    'unsupported CID fails explicitly',
);
$GLOBALS['hooks']['phpmailer_init'] = [];
$response = [
    'response' => ['code' => 200],
    'body' => json_encode([
        'success' => true,
        'hostname' => 'example.test',
        'action' => 'login',
        'challenge_ts' => gmdate('c'),
    ]),
];
check(!pagenest_companion_turnstile_verify('login', ''), 'missing token');
check(pagenest_companion_turnstile_verify('login', 'unique-token'), 'verified native login token');
check(
    !pagenest_companion_turnstile_verify('login', 'unique-token'),
    'same token not reused in request',
);
check(!pagenest_companion_turnstile_verify('comments', 'wrong-action'), 'action mismatch');
$response['body'] = json_encode([
    'success' => true,
    'hostname' => 'other.test',
    'action' => 'login',
    'challenge_ts' => gmdate('c'),
]);
check(!pagenest_companion_turnstile_verify('login', 'wrong-host'), 'hostname mismatch');
$response['body'] = json_encode([
    'success' => true,
    'hostname' => 'example.test',
    'action' => 'login',
    'challenge_ts' => gmdate('c', time() - 301),
]);
check(!pagenest_companion_turnstile_verify('login', 'expired'), '300 second expiry');
$response = new WP_Error('timeout', 'contains secret');
check(!pagenest_companion_turnstile_verify('login', 'timeout'), 'siteverify network fails closed');
$pagenow = 'index.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$user = (object) ['ID' => 1];
check(
    pagenest_companion_turnstile_login($user, 'name', 'password') === $user,
    'non-native login not intercepted',
);
$pagenow = 'wp-login.php';
$_REQUEST['action'] = 'login';
check(
    is_wp_error(pagenest_companion_turnstile_login($user, 'name', 'password')),
    'native login requires token',
);
$html =
    '<!doctype html><html>\n<head><script>"</body>  <body>"</script></head><body><pre>x  y\n</pre><code>a  b</code><math>x  y</math><textarea> a  b </textarea> inline <span>A</span> <span>B</span></body></html>';
check(pagenest_companion_output_html($html) === $html, 'protected bodies/inline spacing unchanged');
$envelope = "<!doctype html>\n<html>\n<head></head><body><pre>A  B\nC</pre></body>\n</html>";
check(
    pagenest_companion_output_html($envelope) ===
        "<!doctype html><html><head></head><body><pre>A  B\nC</pre></body></html>",
    'only document envelope whitespace changes',
);
$before = count($requests);
check(
    !wp_mail('next@example.test', 'Empty', '') && count($requests) === $before,
    'empty body rejected like PHPMailer',
);
$originalMailer = $phpmailer;
$profiles['mail']['enabled'] = false;
check(
    pagenest_companion_mail_prepare(null, []) === null && $phpmailer === $originalMailer,
    'disabled profile does not replace global mailer',
);
check(
    pagenest_companion_mail_prepare(false, []) === false && $phpmailer === $originalMailer,
    'existing pre_wp_mail result preserved',
);
$profiles['turnstile']['scopes']['comments'] = false;
check(pagenest_companion_turnstile_verify('comments', '') === true, 'disabled scope unchanged');
if (($argv[2] ?? '') === 'owners') {
    function easy_wp_smtp() {}
    function cfturnstile_api_url() {}
    define('WPSOL_ADDON_VERSION', 'test-owner');
    $hooks = [];
    $profiles['mail']['enabled'] = true;
    $profiles['output']['enabled'] = true;
    pagenest_companion_services_bootstrap();
    check($hooks === [], 'active legacy owners prevent registering all services');
}
echo $checks . " offline service checks passed.\n";
