<?php
defined('ABSPATH') || exit();
function pagenest_social_share($content)
{
    $config = pagenest_social_config()['share'] ?? [];
    if (
        !pagenest_social_active() ||
        ($config['enabled'] ?? false) !== true ||
        !is_singular(['post', 'page']) ||
        !in_the_loop() ||
        !is_main_query() ||
        is_feed() ||
        is_preview() ||
        post_password_required() ||
        !is_post_publicly_viewable(get_post())
    ) {
        return $content;
    }
    if (
        (is_page() && ($config['pages'] ?? false) !== true) ||
        (is_single() && ($config['posts'] ?? true) !== true)
    ) {
        return $content;
    }
    $url = get_permalink();
    $title = wp_strip_all_tags(get_the_title());
    $links = [
        'qq' => [
            'QQ',
            pagenest_social_query_url(
                ['url' => $url, 'title' => $title],
                'https://connect.qq.com/widget/shareqq/index.html',
            ),
        ],
        'weibo' => [
            '微博',
            pagenest_social_query_url(
                ['url' => $url, 'title' => $title],
                'https://service.weibo.com/share/share.php',
            ),
        ],
        'qqzone' => [
            'QQ 空间',
            pagenest_social_query_url(
                ['url' => $url, 'title' => $title],
                'https://sns.qzone.qq.com/cgi-bin/qzshare/cgi_qzshare_onekey',
            ),
        ],
    ];
    $id = wp_unique_id('pagenest-share-wechat-');
    $html =
        '<aside class="pagenest-social-share" data-pagenest-generated="social-share" aria-label="分享文章"><span>分享：</span>';
    foreach ($config['providers'] ?? [] as $provider) {
        if (isset($links[$provider])) {
            $html .=
                '<a class="pagenest-social-share-action" href="' .
                esc_url($links[$provider][1]) .
                '" target="_blank" rel="noopener noreferrer">' .
                esc_html($links[$provider][0]) .
                '</a> ';
        }
        if ($provider === 'wechat') {
            $html .=
                '<button type="button" class="pagenest-social-share-action" data-pagenest-share-open="' .
                esc_attr($id) .
                '" aria-controls="' .
                esc_attr($id) .
                '" aria-haspopup="dialog" aria-expanded="false">微信</button>' .
                '<dialog id="' .
                esc_attr($id) .
                '" class="pagenest-social-qr" aria-labelledby="' .
                esc_attr($id) .
                '-title">' .
                '<div class="pagenest-social-qr-heading"><p class="pagenest-social-qr-title" id="' .
                esc_attr($id) .
                '-title">分享到微信</p>' .
                '<button type="button" data-pagenest-share-close aria-label="关闭微信分享">×</button></div>' .
                '<div data-pagenest-social-qr="' .
                esc_attr($url) .
                '"></div>' .
                '<p>使用微信扫一扫打开文章，再分享给朋友。</p>' .
                '<label for="' .
                esc_attr($id) .
                '-url">文章链接</label>' .
                '<div class="pagenest-social-copy"><input id="' .
                esc_attr($id) .
                '-url" data-pagenest-share-url readonly value="' .
                esc_attr($url) .
                '">' .
                '<button type="button" data-pagenest-share-copy>复制链接</button></div><p role="status" aria-live="polite"></p></dialog>';
        }
    }
    return $content . $html . '</aside>';
}
function pagenest_social_share_assets()
{
    // Also used by front-end login links to preserve the current fragment.
    $base = plugin_dir_url(dirname(__DIR__, 2) . '/pagenest-compatibility.php');
    $manifest = require dirname(__DIR__, 2) . '/assets/manifest.php';
    $config = pagenest_social_config()['share'] ?? [];
    $dependencies = [];
    if (
        pagenest_social_active() &&
        ($config['enabled'] ?? false) === true &&
        is_singular(['post', 'page']) &&
        in_array('wechat', $config['providers'] ?? [], true)
    ) {
        wp_enqueue_script(
            'pagenest-social-qrcode',
            $base . 'assets/' . $manifest['social_qrcode_js'],
            [],
            null,
            true,
        );
        $dependencies[] = 'pagenest-social-qrcode';
    }
    wp_enqueue_script(
        'pagenest-social-share',
        $base . 'assets/' . $manifest['social_share_js'],
        $dependencies,
        null,
        true,
    );
}

function pagenest_social_ui_assets()
{
    if (!pagenest_social_active()) {
        return;
    }
    $base = plugin_dir_url(dirname(__DIR__, 2) . '/pagenest-compatibility.php');
    $manifest = require dirname(__DIR__, 2) . '/assets/manifest.php';
    wp_enqueue_style('pagenest-social', $base . 'assets/' . $manifest['social_ui_css'], [], null);
}
