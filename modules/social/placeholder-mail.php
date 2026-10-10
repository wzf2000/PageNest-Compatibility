<?php
/** Placeholder identities must not accidentally email an unrelated real domain. */
defined('ABSPATH') || exit();
function pagenest_social_placeholder_mail($pre, $atts)
{
    if (!pagenest_social_active()) {
        return $pre;
    }
    $recipients = (array) ($atts['to'] ?? []);
    $headers = $atts['headers'] ?? [];
    if (is_string($headers)) {
        $headers = preg_split('/\r?\n/', $headers);
    }
    foreach ((array) $headers as $header) {
        if (is_string($header) && preg_match('/^\s*(?:cc|bcc)\s*:/i', $header)) {
            $recipients[] = $header;
        }
    }
    foreach ($recipients as $recipient) {
        if (
            is_string($recipient) &&
            preg_match('/@(?:fake\.com|(?:[a-z0-9-]+\.)*invalid)(?=[>\s,;]|$)/i', $recipient)
        ) {
            return false;
        }
    }
    return $pre;
}
add_filter('pre_wp_mail', 'pagenest_social_placeholder_mail', -10000, 2);
function pagenest_social_password_message($message)
{
    // Preserve the historical plain-text password-link wrapper normalization.
    return pagenest_social_active() ? str_replace(['<', '>'], '', $message) : $message;
}
add_filter('retrieve_password_message', 'pagenest_social_password_message', 99);
