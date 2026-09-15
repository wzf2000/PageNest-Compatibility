<?php
/** Preserve sent markers and unfinished locks from pre-PageNest identifiers. */
defined('ABSPATH') || exit();
function pagenest_comment_sent_records($comment_id)
{
    $current = (array) get_comment_meta($comment_id, '_pagenest_comment_mail_sent', true);
    $legacy = (array) get_comment_meta($comment_id, '_wzfj_comment_mail_sent', true);
    return array_replace($legacy, $current);
}
function pagenest_legacy_mail_locked($comment_id)
{
    return (bool) get_option('wzfj_comment_mail_lock_' . (int) $comment_id, false);
}
