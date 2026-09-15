<?php
define('ABSPATH', '/test/');
$options = ['comments_notify' => false];
$meta = [];
$mails = [];
$send_ok = true;
$comments = [];
function add_action(...$x) {}
function sanitize_email($x)
{
    return trim($x);
}
function is_email($x)
{
    return filter_var($x, FILTER_VALIDATE_EMAIL);
}
function sanitize_text_field($x)
{
    return strip_tags($x);
}
function wp_specialchars_decode($x, $flags)
{
    return html_entity_decode($x, $flags);
}
function get_option($k, $default = false)
{
    global $options;
    return $options[$k] ?? ($k === 'blogname' ? 'Test site' : $default);
}
function user_can(...$x)
{
    return true;
}
function get_permalink($p)
{
    return 'https://example.invalid/?p=' . $p->ID;
}
function get_comment($id)
{
    global $comments;
    return $comments[$id] ?? null;
}
function get_post($id)
{
    return (object) [
        'ID' => $id,
        'post_author' => 1,
        'post_status' => 'publish',
        'post_password' => '',
    ];
}
function get_userdata($id)
{
    return (object) [
        'ID' => 1,
        'user_email' => 'author@example.invalid',
        'display_name' => 'Author',
    ];
}
function apply_filters($tag, $v, ...$rest)
{
    return $v;
}
function get_comment_meta($id, $k, $single)
{
    global $meta;
    return $meta[$id][$k] ?? [];
}
function update_comment_meta($id, $k, $v)
{
    global $meta;
    $meta[$id][$k] = $v;
}
function delete_comment_meta($id, $k)
{
    global $meta;
    unset($meta[$id][$k]);
}
function add_option($k, $v, ...$x)
{
    global $options;
    if (isset($options[$k])) {
        return false;
    }
    $options[$k] = $v;
    return true;
}
function delete_option($k)
{
    global $options;
    unset($options[$k]);
}
function wp_mail($to, $subject, $message)
{
    global $mails, $send_ok;
    $mails[] = [$to, $subject, $message];
    return $send_ok;
}
require dirname(__DIR__) . '/comment-notifications.php';
$c = (object) [
    'comment_ID' => 10,
    'comment_parent' => 0,
    'comment_post_ID' => 1,
    'comment_approved' => '1',
    'comment_type' => 'comment',
    'comment_author_email' => 'reader@example.invalid',
    'comment_author' => 'Reader',
    'user_id' => 2,
];
$comments[10] = $c;
$out = [];
$check = function ($name, $ok) use (&$out) {
    if (!$ok) {
        throw new RuntimeException($name);
    }
    $out[] = ['name' => $name, 'passed' => true];
};
pagenest_notify_comment_mail(10);
$check('new_comment_author_mail', count($mails) === 1 && $mails[0][0] === 'author@example.invalid');
pagenest_notify_comment_mail(10);
$check('duplicate_hook_no_resend', count($mails) === 1);
$child = clone $c;
$child->comment_ID = 11;
$child->comment_parent = 10;
$child->comment_author_email = 'other@example.invalid';
$child->user_id = 3;
$comments[11] = $child;
pagenest_notify_comment_mail(11);
$check('reply_mail_to_parent', count($mails) === 2 && $mails[1][0] === 'reader@example.invalid');
pagenest_notify_comment_mail(11);
$check('reply_no_resend', count($mails) === 2);
$c2 = clone $c;
$c2->comment_ID = 12;
$c2->comment_approved = '0';
$comments[12] = $c2;
pagenest_notify_comment_mail(12);
$check('pending_no_send', count($mails) === 2);
$c2->comment_approved = '1';
pagenest_notify_comment_mail(12);
$check('approval_sends_once', count($mails) === 3);
$c3 = clone $c;
$c3->comment_ID = 13;
$comments[13] = $c3;
$send_ok = false;
pagenest_notify_comment_mail(13);
$check('failure_not_marked_sent', empty($meta[13]['_pagenest_comment_mail_sent']));
$send_ok = true;
pagenest_notify_comment_mail(13);
$check('explicit_retry_after_failure', isset($meta[13]['_pagenest_comment_mail_sent']));
$options['comments_notify'] = true;
$c4 = clone $c;
$c4->comment_ID = 14;
$comments[14] = $c4;
$n = count($mails);
pagenest_notify_comment_mail(14);
$check('core_author_notification_not_duplicated', count($mails) === $n);
$parent = clone $c;
$parent->comment_ID = 15;
$parent->comment_author_email = 'author@example.invalid';
$comments[15] = $parent;
$reply = clone $child;
$reply->comment_ID = 16;
$reply->comment_parent = 15;
$comments[16] = $reply;
pagenest_notify_comment_mail(16);
$check('reply_author_core_dedup', count($mails) === $n);
$options['comments_notify'] = false;
$legacy = clone $c;
$legacy->comment_ID = 17;
$comments[17] = $legacy;
$meta[17]['_wzfj_comment_mail_sent'] = [hash('sha256', 'author@example.invalid') => '2026-01-01'];
$n = count($mails);
pagenest_notify_comment_mail(17);
$check('legacy_sent_marker_prevents_resend', count($mails) === $n);
$locked = clone $c;
$locked->comment_ID = 18;
$comments[18] = $locked;
$options['wzfj_comment_mail_lock_18'] = time();
pagenest_notify_comment_mail(18);
$check('legacy_lock_prevents_concurrent_send', count($mails) === $n);
echo json_encode($out, JSON_PRETTY_PRINT);
