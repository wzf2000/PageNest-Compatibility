<?php
/** Optional public discovery and metadata; no migration or content writes. */
defined('ABSPATH') || exit();
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
function pagenest_discovery_text(string $value, int $limit = 160): string
{
    // Never render shortcodes or apply the_content: private/tool rendering is outside this module.
    $value = wp_strip_all_tags(strip_shortcodes($value), true);
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
    echo '<div class="wrap"><h1>内容发现与 SEO</h1><p>新模块默认关闭；启用不会改写文章、媒体、链接或私人记录。外部 SEO 插件接管时自动停止基础元信息输出。</p><form method="post" action="options.php">';
    settings_fields('pagenest_discovery');
    foreach (
        [
            'search' => '启用公开内容搜索建议与工具筛选',
            'seo' => '启用基础描述、分享卡片和结构化数据',
        ]
        as $key => $label
    ) {
        echo '<p><label><input type="checkbox" name="pagenest_companion_discovery[' .
            esc_attr($key) .
            ']" value="1" ' .
            checked(!empty($options[$key]), true, false) .
            '> ' .
            esc_html($label) .
            '</label></p>';
    }
    echo '<p><label for="pagenest-description">站点描述（留空使用站点副标题）</label><br><textarea id="pagenest-description" name="pagenest_companion_discovery[description]" rows="3" class="large-text" maxlength="160">' .
        esc_textarea($options['description']) .
        '</textarea></p><p><label for="pagenest-share-image">默认分享图片（未选择时使用站点 Logo）</label><br><input id="pagenest-share-image" type="hidden" name="pagenest_companion_discovery[image]" value="' .
        esc_attr($options['image']) .
        '"><button type="button" class="button" id="pagenest-select-share-image">从媒体库选择</button> <button type="button" class="button" id="pagenest-clear-share-image">使用站点 Logo</button><span id="pagenest-share-image-status" role="status">' .
        ($options['image']
            ? '已选择：' . esc_html(get_the_title($options['image']))
            : '使用站点 Logo') .
        '</span></p><p>文章描述优先使用手写摘要；没有摘要时使用站点描述，不从正文提取可能含有私人信息或短代码参数的文本。</p>';
    submit_button();
    echo '</form></div>';
}
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/seo.php';

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
