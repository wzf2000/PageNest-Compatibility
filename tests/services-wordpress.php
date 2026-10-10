<?php
/** Run with wp eval-file in an owned, synthetic local WordPress. Every HTTP request is mocked. */
$owner_file = getenv('PAGENEST_SERVICE_TEST_OWNER_FILE');
$expected_owner = getenv('PAGENEST_SERVICE_TEST_OWNER');
$owner =
    is_string($owner_file) && is_readable($owner_file)
        ? json_decode(file_get_contents($owner_file), true)
        : null;
if (
    wp_get_environment_type() !== 'local' ||
    wp_parse_url(home_url('/'), PHP_URL_HOST) !== 'example.test' ||
    !$expected_owner ||
    !is_array($owner) ||
    ($owner['owner'] ?? '') !== $expected_owner ||
    !defined('WP_CLI') ||
    !WP_CLI
) {
    throw new RuntimeException('Owned synthetic local WordPress required.');
}
$checks = 0;
$assert = static function ($condition) use (&$checks) {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Service WordPress assertion failed: ' . $checks);
    }
};
$before_profile = $GLOBALS['pagenest_companion_profile'];
$before_services = $GLOBALS['pagenest_companion_services_profile'];
$before_post = $_POST;
$before_request = $_REQUEST;
$before_server = $_SERVER;
$before_pagenow = $GLOBALS['pagenow'] ?? null;
$before_user = get_current_user_id();
$queue = [];
$http = [];
$success = [];
$failures = [];
$posts = [];
$comments = [];
$option_keys = [];
$fixture = 'service_fixture_' . bin2hex(random_bytes(6));
$mock = static function ($pre, $args, $url) use (&$queue, &$http) {
    $http[] = [$url, $args];
    if (
        !in_array(
            $url,
            [
                'https://api.brevo.com/v3/smtp/email',
                'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            ],
            true,
        ) ||
        !$queue
    ) {
        return new WP_Error('fixture_http_blocked', 'All unqueued external HTTP is blocked.');
    }
    $response = array_shift($queue);
    return is_wp_error($response)
        ? $response
        : [
            'headers' => [],
            'body' => $response['body'],
            'response' => ['code' => $response['code']],
            'cookies' => [],
            'filename' => null,
        ];
};
$success_hook = static function ($data) use (&$success) {
    $success[] = $data;
};
$failure_hook = static function ($error) use (&$failures) {
    $failures[] = $error;
};
$die_handler = static fn() => static function ($message) {
    throw new RuntimeException(
        is_wp_error($message) ? $message->get_error_code() : 'unexpected_wp_die',
    );
};
add_filter('pre_http_request', $mock, PHP_INT_MAX, 3);
add_action('wp_mail_succeeded', $success_hook);
add_action('wp_mail_failed', $failure_hook);
add_filter('wp_die_handler', $die_handler);
try {
    $assert(did_action('plugins_loaded') > 0);
    $assert(pagenest_companion_service_config('mail')['enabled'] === true);
    $assert(pagenest_companion_service_config('turnstile')['enabled'] === true);
    $assert(has_filter('pre_wp_mail', 'pagenest_companion_mail_prepare') === PHP_INT_MAX);
    $assert(has_filter('authenticate', 'pagenest_companion_turnstile_login') === 99);
    $assert(has_filter('registration_errors', 'pagenest_companion_turnstile_registration') === 99);
    $assert(has_action('lostpassword_post', 'pagenest_companion_turnstile_reset') === 99);
    $assert(has_filter('preprocess_comment', 'pagenest_companion_turnstile_comment') === 99);
    $assert(has_action('template_redirect', 'pagenest_companion_output_start') === PHP_INT_MAX);
    $attachment = tempnam(dirname($owner_file), $fixture);
    if (!$attachment || file_put_contents($attachment, 'synthetic attachment bytes') === false) {
        throw new RuntimeException('Writable owned fixture directory required.');
    }
    $queue[] = ['code' => 201, 'body' => '{"messageId":"synthetic-acceptance-one"}'];
    $assert(
        wp_mail(
            'Synthetic Reader <reader@example.test>',
            'Synthetic HTML',
            '<b>Synthetic body</b>',
            [
                'Content-Type: text/html',
                'Cc: copy@example.test',
                'Bcc: hidden@example.test',
                'Reply-To: original@example.test',
            ],
            [$attachment],
        ),
    );
    $payload = json_decode(end($http)[1]['body'], true);
    $assert(
        $payload['sender']['email'] === pagenest_companion_service_config('mail')['from_email'],
    );
    $assert(
        $payload['htmlContent'] === '<b>Synthetic body</b>' && count($payload['attachment']) === 1,
    );
    $assert(base64_decode($payload['attachment'][0]['content']) === 'synthetic attachment bytes');
    $assert($payload['replyTo']['email'] === 'original@example.test');
    $queue[] = ['code' => 201, 'body' => '{"messageId":"synthetic-acceptance-two"}'];
    $assert(wp_mail('second@example.test', 'Synthetic plain', 'Synthetic plain body'));
    $payload = json_decode(end($http)[1]['body'], true);
    $assert(
        $payload['textContent'] === 'Synthetic plain body' &&
            !isset($payload['attachment']) &&
            !isset($payload['cc']) &&
            !isset($payload['bcc']) &&
            !isset($payload['htmlContent']),
    );
    $assert(count($success) === 2 && !$failures);
    $queue[] = new WP_Error('synthetic_timeout', 'unsafe synthetic-secret detail');
    $assert(!wp_mail('second@example.test', 'Synthetic failure', 'Body'));
    $assert(count($failures) === 1 && count($success) === 2);
    $assert(!str_contains(end($failures)->get_error_message(), 'synthetic-secret'));
    $requests_before = count($http);
    $assert(
        !wp_mail('placeholder@fake.com', 'Synthetic placeholder', 'Body') &&
            count($http) === $requests_before,
    );

    // Exercise retained legacy sent markers and locks with only fixture comments.
    $post = wp_insert_post(
        [
            'post_title' => $fixture,
            'post_content' => 'Synthetic body',
            'post_status' => 'publish',
            'post_author' => 0,
        ],
        true,
    );
    if (is_wp_error($post)) {
        throw new RuntimeException('Fixture post creation failed.');
    }
    $posts[] = $post;
    $parent = wp_insert_comment([
        'comment_post_ID' => $post,
        'comment_content' => 'Synthetic parent',
        'comment_author' => 'Synthetic Parent',
        'comment_author_email' => 'parent@example.test',
        'comment_approved' => 1,
    ]);
    $comments[] = $parent;
    $reply = wp_insert_comment([
        'comment_post_ID' => $post,
        'comment_parent' => $parent,
        'comment_content' => 'Synthetic reply',
        'comment_author' => 'Synthetic Reply',
        'comment_author_email' => 'reply@example.test',
        'comment_approved' => 1,
    ]);
    $comments[] = $reply;
    $legacy_key = '_' . $fixture . '_legacy_sent';
    $legacy_lock = $fixture . '_legacy_lock_';
    $GLOBALS['pagenest_companion_profile']['mail'] = [
        'sent_meta_keys' => [$legacy_key],
        'lock_option_prefixes' => [$legacy_lock],
    ];
    $marker = [hash('sha256', 'parent@example.test') => '2000-01-01T00:00:00+00:00'];
    update_comment_meta($reply, $legacy_key, $marker);
    $requests_before = count($http);
    pagenest_notify_comment_mail($reply);
    $assert(
        count($http) === $requests_before &&
            get_comment_meta($reply, $legacy_key, true) === $marker &&
            !metadata_exists('comment', $reply, '_pagenest_comment_mail_sent'),
    );
    delete_comment_meta($reply, $legacy_key);
    add_option($legacy_lock . $reply, 1, '', false);
    $option_keys[] = $legacy_lock . $reply;
    pagenest_notify_comment_mail($reply);
    $assert(
        count($http) === $requests_before &&
            (int) get_option($legacy_lock . $reply) === 1 &&
            !metadata_exists('comment', $reply, '_pagenest_comment_mail_sent'),
    );

    $GLOBALS['pagenow'] = 'wp-login.php';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_REQUEST = ['action' => 'login'];
    $_POST = [];
    $user = new WP_User((object) ['ID' => 999999999, 'user_login' => $fixture, 'user_status' => 0]);
    $assert(is_wp_error(apply_filters('authenticate', $user, $fixture, 'synthetic-password')));
    $errors = apply_filters(
        'registration_errors',
        new WP_Error(),
        $fixture,
        'fixture@example.test',
    );
    $assert(in_array('pagenest_turnstile', $errors->get_error_codes(), true));
    $errors = new WP_Error();
    do_action('lostpassword_post', $errors, $user);
    $assert(in_array('pagenest_turnstile', $errors->get_error_codes(), true));
    $GLOBALS['pagenow'] = 'wp-comments-post.php';
    try {
        apply_filters('preprocess_comment', ['comment_content' => 'Synthetic blocked comment']);
        $blocked = false;
    } catch (RuntimeException $error) {
        $blocked = $error->getMessage() === 'pagenest_turnstile';
    }
    $assert($blocked);
    $verify_response = static fn($scope, $time = null, $host = 'example.test') => [
        'code' => 200,
        'body' => wp_json_encode([
            'success' => true,
            'hostname' => $host,
            'action' => $scope,
            'challenge_ts' => gmdate('c', $time ?? time()),
        ]),
    ];
    $GLOBALS['pagenow'] = 'wp-login.php';
    $_POST['cf-turnstile-response'] = $fixture . '-login';
    $queue[] = $verify_response('login');
    $assert(apply_filters('authenticate', $user, $fixture, 'synthetic-password') === $user);
    $assert(is_wp_error(apply_filters('authenticate', $user, $fixture, 'synthetic-password')));
    $_POST['cf-turnstile-response'] = $fixture . '-register';
    $queue[] = $verify_response('register');
    $errors = apply_filters(
        'registration_errors',
        new WP_Error(),
        $fixture,
        'fixture@example.test',
    );
    $assert(!in_array('pagenest_turnstile', $errors->get_error_codes(), true));
    $_POST['cf-turnstile-response'] = $fixture . '-reset';
    $queue[] = $verify_response('reset');
    $errors = new WP_Error();
    do_action('lostpassword_post', $errors, $user);
    $assert(!$errors->has_errors());
    $GLOBALS['pagenow'] = 'wp-comments-post.php';
    $_POST['cf-turnstile-response'] = $fixture . '-comments';
    $queue[] = $verify_response('comments');
    $comment_input = ['comment_content' => 'Synthetic permitted comment'];
    $assert(apply_filters('preprocess_comment', $comment_input) === $comment_input);
    $queue[] = $verify_response('login', time(), 'unexpected.test');
    $assert(!pagenest_companion_turnstile_verify('login', $fixture . '-host'));
    $queue[] = $verify_response('reset');
    $assert(!pagenest_companion_turnstile_verify('login', $fixture . '-action'));
    $queue[] = $verify_response('login', time() - 301);
    $assert(!pagenest_companion_turnstile_verify('login', $fixture . '-expired'));
    $queue[] = new WP_Error('synthetic_timeout', 'Siteverify blocked');
    $assert(!pagenest_companion_turnstile_verify('login', $fixture . '-timeout'));
    $requests_before = count($http);
    $GLOBALS['pagenow'] = 'index.php';
    $_POST = [];
    $assert(pagenest_companion_turnstile_login($user, $fixture, 'synthetic-password') === $user);
    $assert(pagenest_companion_turnstile_comment($comment_input) === $comment_input);
    $assert(count($http) === $requests_before);
    $private = wp_insert_post(
        [
            'post_title' => $fixture . ' private',
            'post_content' => 'Synthetic private body',
            'post_status' => 'private',
        ],
        true,
    );
    if (is_wp_error($private)) {
        throw new RuntimeException('Fixture private post creation failed.');
    }
    $posts[] = $private;
    wp_set_current_user(0);
    $response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/posts/' . $private));
    $assert($response->get_status() === 401);
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    if (!$admins) {
        throw new RuntimeException('Synthetic administrator required.');
    }
    wp_set_current_user($admins[0]);
    $response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/posts/' . $private));
    $assert($response->get_status() === 200 && count($http) === $requests_before);
    foreach ($http as [$url, $args]) {
        $assert($args['sslverify'] === true && $args['redirection'] === 0);
    }
    $assert(!$queue);
    echo wp_json_encode([
        'checks' => $checks,
        'provider_http' => 'mock only',
        'mail_delivery' => 'mock only',
    ]) . "\n";
} finally {
    foreach ($option_keys as $key) {
        delete_option($key);
    }
    foreach (array_reverse($comments) as $id) {
        if ($id) {
            wp_delete_comment($id, true);
        }
    }
    foreach ($posts as $id) {
        wp_delete_post($id, true);
    }
    if (isset($attachment) && is_file($attachment)) {
        unlink($attachment);
    }
    $GLOBALS['pagenest_companion_profile'] = $before_profile;
    $GLOBALS['pagenest_companion_services_profile'] = $before_services;
    $_POST = $before_post;
    $_REQUEST = $before_request;
    $_SERVER = $before_server;
    $GLOBALS['pagenow'] = $before_pagenow;
    wp_set_current_user($before_user);
    remove_filter('pre_http_request', $mock, PHP_INT_MAX);
    remove_action('wp_mail_succeeded', $success_hook);
    remove_action('wp_mail_failed', $failure_hook);
    remove_filter('wp_die_handler', $die_handler);
}
