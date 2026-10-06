<?php
/** Read legacy markers without rewriting history. */
defined('ABSPATH') || exit();
require_once __DIR__ . '/config.php';
function pagenest_comment_sent_records($comment_id)
{
    $records = [];
    foreach (pagenest_companion_config('mail', 'sent_meta_keys') as $key) {
        $records = array_replace($records, (array) get_comment_meta($comment_id, $key, true));
    }
    return array_replace(
        $records,
        (array) get_comment_meta($comment_id, '_pagenest_comment_mail_sent', true),
    );
}
function pagenest_legacy_mail_locked($comment_id)
{
    foreach (pagenest_companion_config('mail', 'lock_option_prefixes') as $prefix) {
        if (get_option($prefix . (int) $comment_id, false)) {
            return true;
        }
    }
    return false;
}
