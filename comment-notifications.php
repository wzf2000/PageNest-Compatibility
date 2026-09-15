<?php
/** Reply notifications live in the compatibility plugin, independently of the theme. */
defined('ABSPATH') || exit();
function wzfj_reply_mail_plan($comment, $parent, $post)
{
    if (
        !$comment ||
        !$parent ||
        !$post ||
        (string) $comment->comment_approved !== '1' ||
        (string) $parent->comment_approved !== '1'
    ) {
        return null;
    }
    if (
        !in_array($comment->comment_type, ['', 'comment'], true) ||
        (int) $comment->comment_parent !== (int) $parent->comment_ID ||
        (int) $parent->comment_post_ID !== (int) $post->ID ||
        (int) $comment->comment_post_ID !== (int) $post->ID
    ) {
        return null;
    }
    $to = sanitize_email($parent->comment_author_email);
    if (
        !is_email($to) ||
        strcasecmp($to, trim($comment->comment_author_email)) === 0 ||
        ((int) $parent->user_id > 0 && (int) $parent->user_id === (int) $comment->user_id)
    ) {
        return null;
    }
    if (
        !in_array($post->post_status, ['publish', 'private'], true) ||
        $post->post_password !== ''
    ) {
        return null;
    }
    if ($post->post_status !== 'publish') {
        if (
            !(int) $parent->user_id ||
            !user_can((int) $parent->user_id, 'read_post', $post->ID) ||
            $post->post_password !== ''
        ) {
            return null;
        }
    }
    // Do not embed private comment text in mail; link back to the permission-checked page.
    $site = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
    return [
        'to' => $to,
        'subject' => '您在「' . $site . '」的留言有了回复',
        'message' =>
            sanitize_text_field($parent->comment_author) .
            '，您好！\n\n' .
            sanitize_text_field($comment->comment_author) .
            ' 回复了您的留言。\n\n查看回复：' .
            get_permalink($post) .
            '#comment-' .
            (int) $comment->comment_ID .
            '\n\n此邮件由网站自动发送，请勿直接回复。',
    ];
}
function wzfj_author_mail_plan($comment, $post, $author, $core_enabled)
{
    if (
        $core_enabled ||
        !$comment ||
        !$post ||
        !$author ||
        (int) $comment->comment_parent ||
        (string) $comment->comment_approved !== '1' ||
        !in_array($comment->comment_type, ['', 'comment'], true)
    ) {
        return null;
    }
    if (
        !in_array($post->post_status, ['publish', 'private'], true) ||
        $post->post_password !== '' ||
        !user_can($author, 'read_post', $post->ID)
    ) {
        return null;
    }
    $to = sanitize_email($author->user_email);
    if (
        !is_email($to) ||
        (int) $comment->user_id === (int) $author->ID ||
        strcasecmp($to, trim($comment->comment_author_email)) === 0
    ) {
        return null;
    }
    return [
        'to' => $to,
        'subject' => '您的文章有了新留言',
        'message' =>
            sanitize_text_field($author->display_name) .
            "，您好！\n\n" .
            sanitize_text_field($comment->comment_author) .
            " 在您的文章下发表了新留言。\n\n查看留言：" .
            get_permalink($post) .
            '#comment-' .
            (int) $comment->comment_ID .
            "\n\n此邮件由网站自动发送，请勿直接回复。",
    ];
}
function wzfj_notify_comment_mail($comment_id)
{
    $comment = get_comment($comment_id);
    if (!$comment) {
        return;
    }
    $parent = $comment->comment_parent ? get_comment($comment->comment_parent) : null;
    $post = get_post($comment->comment_post_ID);
    if (!$post) {
        return;
    }
    $author = get_userdata($post->post_author);
    $mail = $parent
        ? wzfj_reply_mail_plan($comment, $parent, $post)
        : wzfj_author_mail_plan($comment, $post, $author, (bool) get_option('comments_notify'));
    if (!$mail) {
        return;
    }
    // Core already notifies the post author. Avoid a second message to that same recipient.
    $author = get_userdata($post->post_author);
    if (
        $parent &&
        $author &&
        strcasecmp($mail['to'], $author->user_email) === 0 &&
        (int) $comment->user_id !== (int) $post->post_author &&
        apply_filters('notify_post_author', (bool) get_option('comments_notify'), $comment_id)
    ) {
        return;
    }
    $sent_key = '_wzfj_comment_mail_sent';
    $recipient_key = hash('sha256', strtolower($mail['to']));
    $sent = (array) get_comment_meta($comment_id, $sent_key, true);
    if (isset($sent[$recipient_key])) {
        return;
    }
    $lock = 'wzfj_comment_mail_lock_' . (int) $comment_id;
    if (!add_option($lock, time(), '', false)) {
        return;
    }
    try {
        $sent = (array) get_comment_meta($comment_id, $sent_key, true);
        if (isset($sent[$recipient_key])) {
            return;
        }
        if (wp_mail($mail['to'], $mail['subject'], str_replace('\\n', "\n", $mail['message']))) {
            $sent[$recipient_key] = gmdate('c');
            update_comment_meta($comment_id, $sent_key, $sent);
            delete_comment_meta($comment_id, '_wzfj_comment_mail_failed');
        } else {
            update_comment_meta($comment_id, '_wzfj_comment_mail_failed', gmdate('c'));
        }
    } finally {
        delete_option($lock);
    }
}
add_action('comment_post', 'wzfj_notify_comment_mail', 30);
add_action(
    'transition_comment_status',
    static function ($new, $old, $comment) {
        if ($new === 'approved' && $old !== 'approved') {
            wzfj_notify_comment_mail($comment->comment_ID);
        }
    },
    30,
    3,
);

// If the legacy theme is previewed or restored with this bridge still active, avoid double mail.
add_action(
    'after_setup_theme',
    static function () {
        remove_action('comment_post', 'comment_mail_notify');
    },
    100,
);
