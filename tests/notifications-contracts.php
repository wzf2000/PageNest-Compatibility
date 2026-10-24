<?php
/** Behavioral tests; no email delivery, database, or real account data. */
define('ABSPATH', __DIR__);
$comments = $meta = $usermeta = $hooks = [];
$enabled = '1';
$current = 7;
$storage = true;
$wpdb = (object) ['last_error' => ''];
class WP_Error
{
    public function __construct(public $code, public $message, public $data) {}
}
class WP_REST_Response
{
    public function __construct(public $data) {}
    public function header(...$args) {}
}
function add_action(...$args) {}
function add_shortcode(...$args) {}
function add_filter(...$args) {}
function __($text, ...$args)
{
    return $text;
}
function get_option(...$args)
{
    return $GLOBALS['enabled'];
}
function get_current_user_id()
{
    return $GLOBALS['current'];
}
function wp_verify_nonce($nonce, $action)
{
    return $nonce === 'valid';
}
function get_user_by($field, $email)
{
    return $email === 'reader@example.test' ? (object) ['ID' => 7] : false;
}
function esc_html($text)
{
    return htmlspecialchars($text);
}
function get_userdata($id)
{
    return in_array((int) $id, [7, 8], true) ? (object) ['ID' => $id] : false;
}
function get_comment($id)
{
    return $GLOBALS['comments'][$id] ?? null;
}
function get_post($id)
{
    return $GLOBALS['post'];
}
function is_post_publicly_viewable($post)
{
    return true;
}
function get_comment_meta($id, $key, $single)
{
    return $GLOBALS['meta'][$id][$key] ?? '';
}
function add_comment_meta($id, $key, $value, $unique = false)
{
    if (!$GLOBALS['storage'] || isset($GLOBALS['meta'][$id][$key])) {
        return false;
    }
    $GLOBALS['meta'][$id][$key] = $value;
    return 1;
}
function delete_comment_meta($id, $key)
{
    if (($GLOBALS['delete_failure'] ?? '') === $key) {
        return false;
    }
    unset($GLOBALS['meta'][$id][$key]);
    return true;
}
function update_comment_meta($id, $key, $value)
{
    if ($GLOBALS['storage']) {
        $GLOBALS['meta'][$id][$key] = $value;
    }
}
function get_user_meta($id, $key, $single)
{
    return $GLOBALS['usermeta'][$id][$key] ?? '';
}
function update_user_meta($id, $key, $value)
{
    if ($GLOBALS['storage']) {
        $GLOBALS['usermeta'][$id][$key] = $value;
    }
}
function wp_strip_all_tags($text)
{
    return strip_tags($text);
}
function get_the_title($id)
{
    return '<b>Example</b>';
}
function get_comment_link($reply)
{
    return 'https://example.test/article/#comment-' . $reply->comment_ID;
}
function get_comments($args)
{
    if (($args['meta_key'] ?? '') === '_pagenest_notification_owner') {
        check($args['status'] === 'any', 'Privacy includes spam and trash');
    }
    $rows = array_values(
        array_filter(
            $GLOBALS['comments'],
            static fn($comment) => (int) get_comment_meta(
                $comment->comment_ID,
                $args['meta_key'],
                true,
            ) === (int) $args['meta_value'],
        ),
    );
    return array_slice($rows, $args['offset'] ?? 0, $args['number']);
}
class Request
{
    public function __construct(public $params = [], public $nonce = 'valid') {}
    public function get_header($key)
    {
        return $this->nonce;
    }
    public function get_param($key)
    {
        return $this->params[$key] ?? null;
    }
    public function get_json_params()
    {
        return $this->params;
    }
}
function check($value, $message)
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}
require dirname(__DIR__) . '/modules/notifications/bootstrap.php';
$post = (object) [
    'ID' => 3,
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_password' => '',
];
$comments[1] = (object) [
    'comment_ID' => 1,
    'comment_post_ID' => 3,
    'comment_parent' => 0,
    'comment_type' => 'comment',
    'comment_approved' => '1',
    'user_id' => 7,
    'comment_author' => 'Reader',
];
$comments[2] = (object) [
    'comment_ID' => 2,
    'comment_post_ID' => 3,
    'comment_parent' => 1,
    'comment_type' => 'comment',
    'comment_approved' => '1',
    'user_id' => 8,
    'comment_author' => 'Writer',
];
$reply = $comments[2];
pagenest_notifications_submitted(2, 1);
check(count(pagenest_notifications_list(new Request())->data['items']) === 1, 'new reply');
pagenest_notifications_approved('approved', 'hold', $reply);
check(count($meta[2]) === 4, 'approval is idempotent');
$current = 8;
check(
    pagenest_notifications_read(new Request(['id' => 2]))->code === 'notifications_missing',
    'ownership',
);
check(
    count(pagenest_notifications_list(new Request())->data['items']) === 0,
    'recipient isolation',
);
$current = 7;
check(
    pagenest_notifications_permission(new Request([], 'bad'))->code === 'notifications_session',
    'nonce',
);
$current = 0;
check(pagenest_notifications_permission(new Request())->code === 'notifications_session', 'auth');
$current = 7;
$storage = false;
check(
    pagenest_notifications_read(new Request(['id' => 2]))->code === 'notifications_storage',
    'read storage failure',
);
check(
    pagenest_notifications_preference(new Request(['enabled' => false]))->code ===
        'notifications_storage',
    'preference storage failure',
);
$storage = true;
check(pagenest_notifications_read(new Request(['id' => 2]))->data['read'], 'mark read');
$reply->comment_approved = '0';
check(
    pagenest_notifications_read(new Request(['id' => 2]))->code === 'notifications_missing',
    'moderation revoked',
);
$reply->comment_approved = '1';
pagenest_notifications_approved('approved', 'hold', $reply);
check(
    pagenest_notifications_list(new Request())->data['items'][0]['read'],
    'reapproval never resets read',
);
foreach (['post_status' => 'private', 'post_password' => 'secret'] as $field => $value) {
    $previous = $post->$field;
    $post->$field = $value;
    check(
        count(pagenest_notifications_list(new Request())->data['items']) === 0,
        $field . ' access',
    );
    $post->$field = $previous;
}
$comments[1]->user_id = 8;
check(
    count(pagenest_notifications_list(new Request())->data['items']) === 0,
    'parent author reassignment',
);
$comments[1]->user_id = 7;
$reply->user_id = 0;
check(
    count(pagenest_notifications_list(new Request())->data['items']) === 0,
    'reply author reassignment',
);
$reply->user_id = 8;
unset($comments[1]);
check(count(pagenest_notifications_list(new Request())->data['items']) === 0, 'parent deletion');
$comments[1] = (object) [
    'comment_ID' => 1,
    'comment_post_ID' => 3,
    'comment_parent' => 0,
    'comment_type' => 'comment',
    'comment_approved' => '1',
    'user_id' => 7,
];
foreach (
    [3 => 'disabled', 4 => 'optout', 5 => 'self', 6 => 'guest', 7 => 'storage']
    as $id => $case
) {
    $comments[$id] = clone $reply;
    $comments[$id]->comment_ID = $id;
    $enabled = $case === 'disabled' ? '0' : '1';
    $usermeta[7]['_pagenest_notifications_off'] = $case === 'optout' ? '1' : '0';
    $comments[$id]->user_id = $case === 'self' ? 7 : 8;
    $comments[1]->user_id = $case === 'guest' ? 0 : 7;
    $storage = $case !== 'storage';
    pagenest_notifications_submitted($id, 1);
    check(
        get_comment_meta($id, '_pagenest_notification_recipient', true) === '',
        $case . ' suppressed',
    );
}
$storage = true;
$enabled = '1';
$usermeta[7]['_pagenest_notifications_off'] = '0';
$comments[1]->user_id = 7;
$comments[8] = clone $reply;
$comments[8]->comment_ID = 8;
$comments[8]->comment_approved = '0';
pagenest_notifications_submitted(8, 0);
check(
    get_comment_meta(8, '_pagenest_notification_recipient', true) === '',
    'pending does not deliver',
);
$comments[8]->comment_approved = '1';
pagenest_notifications_approved('approved', 'hold', $comments[8]);
check(
    get_comment_meta(8, '_pagenest_notification_recipient', true) === 7,
    'fresh pending approval delivers',
);
$comments[9] = clone $reply;
$comments[9]->comment_ID = 9;
$comments[9]->comment_approved = '0';
pagenest_notifications_submitted(9, 0);
$enabled = '0';
$comments[9]->comment_approved = '1';
pagenest_notifications_approved('approved', 'hold', $comments[9]);
$enabled = '1';
pagenest_notifications_approved('approved', 'hold', $comments[9]);
check(
    get_comment_meta(9, '_pagenest_notification_recipient', true) === '',
    'approval while disabled never rewinds on reapproval',
);
$comments[10] = clone $reply;
$comments[10]->comment_ID = 10;
pagenest_notifications_approved('approved', 'hold', $comments[10]);
check(
    get_comment_meta(10, '_pagenest_notification_event', true) === '',
    'old comments never backfilled',
);
$enabled = '0';
check(
    pagenest_notifications_permission(new Request())->code === 'notifications_disabled',
    'module off',
);
$enabled = '1';
$wpdb->last_error = 'synthetic';
check(
    pagenest_notifications_list(new Request())->code === 'notifications_storage',
    'query failure',
);
$wpdb->last_error = '';
$meta = [];
$comments = [];
for ($i = 10; $i < 62; $i++) {
    $comments[$i] = (object) ['comment_ID' => $i];
    $meta[$i] = [
        '_pagenest_notification_owner' => 7,
        '_pagenest_notification_read' => '1',
        '_pagenest_notification_event' => ['recipient' => 7, 'created' => 'synthetic'],
    ];
}
$export = pagenest_notifications_privacy('reader@example.test');
check(count($export['data']) === 51 && !$export['done'], 'privacy bounded export with preference');
$delete_failure = '_pagenest_notification_read';
check(
    pagenest_notifications_privacy('reader@example.test', 1, true)->code ===
        'notifications_storage',
    'partial erase failure',
);
check(
    get_comment_meta(10, '_pagenest_notification_event', true) === '' &&
        get_comment_meta(10, '_pagenest_notification_owner', true) === 7,
    'partial erase keeps owner retry index',
);
$delete_failure = '';
$erase = pagenest_notifications_privacy('reader@example.test', 1, true);
check(!$erase['done'] && $erase['items_removed'], 'erase retries missing event and first batch');
$erase = pagenest_notifications_privacy('reader@example.test', 2, true);
check(
    $erase['done'] && $erase['items_removed'] && count($comments) === 52,
    'erase second batch without skipping or deleting original comments',
);
$wpdb->last_error = 'synthetic';
check(
    pagenest_notifications_privacy('reader@example.test')->code === 'notifications_storage',
    'export storage failure',
);
check(
    pagenest_notifications_privacy('reader@example.test', 1, true)->code ===
        'notifications_storage',
    'erase storage failure',
);
echo "Notification behavioral and privacy contracts passed.\n";
