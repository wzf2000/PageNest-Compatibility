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
    $html = '<aside class="pagenest-social-share" aria-label="分享文章"><span>分享：</span>';
    foreach ($config['providers'] ?? [] as $provider) {
        if (isset($links[$provider])) {
            $html .=
                '<a href="' .
                esc_url($links[$provider][1]) .
                '" target="_blank" rel="noopener noreferrer">' .
                esc_html($links[$provider][0]) .
                '</a> ';
        }
        if ($provider === 'wechat') {
            $html .=
                '<details class="pagenest-social-qr"><summary>微信</summary><div data-pagenest-social-qr="' .
                esc_attr($url) .
                '"></div><p>使用微信扫描二维码打开文章。</p><a href="' .
                esc_url($url) .
                '">文章链接</a></details>';
        }
    }
    return $content . $html . '</aside>';
}
function pagenest_social_share_assets()
{
    $config = pagenest_social_config()['share'] ?? [];
    if (
        !pagenest_social_active() ||
        ($config['enabled'] ?? false) !== true ||
        !is_singular(['post', 'page'])
    ) {
        return;
    }
    if (
        (is_page() && ($config['pages'] ?? false) !== true) ||
        (is_single() && ($config['posts'] ?? true) !== true)
    ) {
        return;
    }
    $base = plugin_dir_url(dirname(__DIR__, 2) . '/pagenest-compatibility.php');
    $manifest = require dirname(__DIR__, 2) . '/assets/manifest.php';
    if (in_array('wechat', $config['providers'] ?? [], true)) {
        wp_enqueue_script(
            'pagenest-social-qrcode',
            $base . 'assets/' . $manifest['social_qrcode_js'],
            [],
            null,
            true,
        );
        wp_enqueue_script(
            'pagenest-social-share',
            $base . 'assets/' . $manifest['social_share_js'],
            ['pagenest-social-qrcode'],
            null,
            true,
        );
    }
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
