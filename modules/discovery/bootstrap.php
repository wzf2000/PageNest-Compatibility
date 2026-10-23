<?php
/** Optional public discovery and metadata; no migration or content writes. */
defined('ABSPATH') || exit();
require_once dirname(__DIR__, 2) . '/admin-design.php';
function pagenest_discovery_options(): array
{
    $value = get_option('pagenest_companion_discovery', []);
    $value = is_array($value) ? $value : [];
    return [
        'search' => ($value['search'] ?? false) === true,
        'seo' => ($value['seo'] ?? false) === true,
        'description' => is_string($value['description'] ?? null)
            ? pagenest_discovery_text($value['description'])
            : '',
        'image' => is_scalar($value['image'] ?? null) ? absint($value['image']) : 0,
    ];
}
function pagenest_discovery_public_post($post): bool
{
    if (
        !$post ||
        $post->post_status !== 'publish' ||
        $post->post_password !== '' ||
        !in_array($post->post_type, ['post', 'page'], true)
    ) {
        return false;
    }
    if (class_exists('PageNest\\Tools\\Registry')) {
        // When a configured catalog fails validation, page classification is unknown.
        // Fail closed for pages; ordinary posts remain discoverable.
        if (!\PageNest\Tools\Registry::healthy()) {
            return $post->post_type === 'post';
        }
        foreach (\PageNest\Tools\Registry::all() as $tool) {
            if ($tool['kind'] !== 'internal' || $tool['binding']['post_id'] !== (int) $post->ID) {
                continue;
            }
            // Match identity before checking the slug: a renamed private tool is not a public page.
            foreach (pagenest_discovery_tools() as $public) {
                if ($public['binding']['post_id'] === (int) $post->ID) {
                    return true;
                }
            }
            return false;
        }
    }
    return true;
}
function pagenest_discovery_has_shortcodes(string $value): bool
{
    // Comma-separated one-letter dimensions are plain notation, e.g. [B, T, D] or [a,b].
    // Keep unknown shortcode names and their attributes out of all public summaries.
    $value = preg_replace('/\[(?:[a-zA-Z][0-9]*\s*,\s*)+[a-zA-Z][0-9]*\s*\]/', '', $value);
    return (bool) preg_match('/\[\/?[a-zA-Z][^\]]*\]/', $value);
}
function pagenest_discovery_text(string $value, int $limit = 160): string
{
    // Never render shortcodes or apply the_content: private/tool rendering is outside this module.
    // Unknown or disabled shortcode syntax is not safe excerpt material either.
    if (pagenest_discovery_has_shortcodes($value)) {
        return '';
    }
    $value = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $value);
    $value = wp_strip_all_tags($value, true);
    $value = preg_replace(
        '/\s+/u',
        ' ',
        html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    );
    return mb_substr(trim($value), 0, $limit);
}
add_action('admin_menu', static function (): void {
    add_options_page(
        '内容发现与 SEO',
        '内容发现与 SEO',
        'manage_options',
        'pagenest-discovery',
        'pagenest_discovery_settings_page',
    );
});
add_action('admin_init', static function (): void {
    register_setting('pagenest_discovery', 'pagenest_companion_discovery', [
        'type' => 'array',
        'sanitize_callback' => static function ($input): array {
            if (!current_user_can('manage_options')) {
                return pagenest_discovery_options();
            }
            $input = is_array($input) ? $input : [];
            $image =
                isset($input['image']) && is_scalar($input['image']) ? absint($input['image']) : 0;
            return [
                'search' => in_array($input['search'] ?? false, ['1', true], true),
                'seo' => in_array($input['seo'] ?? false, ['1', true], true),
                'description' => pagenest_discovery_text(
                    is_string($input['description'] ?? null) ? $input['description'] : '',
                ),
                'image' => $image && wp_attachment_is_image($image) ? $image : 0,
            ];
        },
    ]);
});
function pagenest_discovery_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $options = pagenest_discovery_options();
    pagenest_companion_admin_header(
        '搜索与分享',
        '帮助读者找到内容，也让页面在搜索和分享时呈现清晰的信息。',
        'pagenest-discovery',
    );
    echo '<div class="pn-admin-layout"><form class="pn-admin-main" method="post" action="options.php">';
    settings_fields('pagenest_discovery');
    echo '<section class="pn-admin-section"><h2>内容发现</h2>';
    foreach (
        [
            'search' => ['公开搜索建议', '输入关键词时提供公开文章、页面与有效工具的建议。'],
            'seo' => ['基础 SEO 与分享卡片', '输出页面描述、分享卡片和结构化数据。'],
        ]
        as $key => [$label, $help]
    ) {
        echo '<div class="pn-admin-setting"><label><input type="checkbox" name="pagenest_companion_discovery[' .
            esc_attr($key) .
            ']" value="1" ' .
            checked(!empty($options[$key]), true, false) .
            '><span>' .
            esc_html($label) .
            '</span></label><p class="pn-admin-description">' .
            esc_html($help) .
            '</p></div>';
    }
    echo '</section><section class="pn-admin-section"><h2>默认分享信息</h2><p>当文章没有单独提供摘要或封面时，使用这里的站点信息。</p><div class="pn-admin-field"><label for="pagenest-description">站点描述</label><textarea id="pagenest-description" name="pagenest_companion_discovery[description]" rows="4" class="large-text" maxlength="160" aria-describedby="pn-description-help">' .
        esc_textarea($options['description']) .
        '</textarea><div class="pn-admin-meta"><span id="pn-description-help">留空使用站点副标题</span><span data-pn-description-count></span></div></div><div class="pn-admin-field"><label for="pagenest-select-share-image">默认分享图片</label><input id="pagenest-share-image" type="hidden" name="pagenest_companion_discovery[image]" value="' .
        esc_attr($options['image']) .
        '"><div class="pn-admin-image-actions"><button type="button" class="button" id="pagenest-select-share-image">从媒体库选择</button><button type="button" class="button" id="pagenest-clear-share-image">使用站点 Logo</button><span id="pagenest-share-image-status" role="status">' .
        ($options['image']
            ? '已选择：' . esc_html(get_the_title($options['image']))
            : '使用站点 Logo') .
        '</span></div></div></section>';
    submit_button('保存搜索与分享设置');
    echo '</form><details class="pn-admin-help"><summary>' .
        esc_html__('How this works', 'pagenest-companion') .
        '</summary><h2>内容优先</h2><p>文章描述优先使用手写摘要，再使用站点描述。不会从正文提取短代码或私人信息。</p><h2>保留已有设置</h2><p>启用不会改写文章、媒体或链接。关闭功能保留这里的配置。</p><h2>与 WordPress 协作</h2><p>核心继续处理标题、标准地址和站点地图。其他 SEO 插件接管时，基础元信息会自动停止输出。</p></details></div></div>';
}
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/editor.php';

add_action('admin_enqueue_scripts', static function ($hook): void {
    if ($hook !== 'settings_page_pagenest-discovery' || !current_user_can('manage_options')) {
        return;
    }
    wp_enqueue_media();
    $manifest = require dirname(__DIR__, 2) . '/assets/manifest.php';
    wp_enqueue_script(
        'pagenest-discovery-admin',
        plugins_url(
            'assets/' . $manifest['discovery_admin_js'],
            dirname(__DIR__, 2) . '/pagenest-compatibility.php',
        ),
        ['media-views'],
        null,
        true,
    );
});
