<?php
/** Optional indentation removal. Never removes asset versions or creates a cache. */
defined('ABSPATH') || exit();
function pagenest_companion_output_html($html)
{
    // Touch only the outer document envelope. Never parse/rewrite the head or body.
    // This deliberately tiny optimization cannot change pre/code/math/JSON or inline spacing.
    $html = preg_replace(
        '~\A(\s*<!doctype html[^>]*>)[\r\n\t ]+(<html\b[^>]*>)[\r\n\t ]+(<head\b[^>]*>)~i',
        '$1$2$3',
        $html,
        1,
    );
    return preg_replace('~(</body>)[\r\n\t ]+(</html>\s*)\z~i', '$1$2', $html, 1);
}
function pagenest_companion_output_start()
{
    $config = pagenest_companion_service_config('output');
    if (
        empty($config['enabled']) ||
        empty($config['html_whitespace']) ||
        is_admin() ||
        is_user_logged_in() ||
        wp_doing_ajax() ||
        is_feed() ||
        is_preview() ||
        is_search() ||
        is_404() ||
        (defined('REST_REQUEST') && REST_REQUEST) ||
        (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) ||
        (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) ||
        !is_singular(['post', 'page'])
    ) {
        return;
    }
    $post = get_queried_object();
    if (
        !$post ||
        $post->post_status !== 'publish' ||
        $post->post_password !== '' ||
        !empty($_GET) ||
        ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' ||
        !empty($_SERVER['HTTP_AUTHORIZATION'])
    ) {
        return;
    }
    ob_start(static function ($html) {
        foreach (headers_list() as $header) {
            if (
                stripos($header, 'Set-Cookie:') === 0 ||
                (stripos($header, 'Content-Type:') === 0 &&
                    stripos($header, 'text/html') === false) ||
                (stripos($header, 'Cache-Control:') === 0 &&
                    preg_match('/private|no-store/i', $header))
            ) {
                return $html;
            }
        }
        return stripos($html, '<!doctype html') !== false
            ? pagenest_companion_output_html($html)
            : $html;
    });
}
