<?php
/** Administrator toggle preserves the separately imported gallery configuration. */
defined('ABSPATH') || exit();
require_once dirname(__DIR__, 2) . '/admin-design.php';
add_action('admin_menu', static function (): void {
    add_options_page(
        __('Media compatibility', 'pagenest-companion'),
        __('Companion Media', 'pagenest-companion'),
        'manage_options',
        'pagenest-media',
        'pagenest_companion_media_settings_page',
    );
});
add_action('admin_init', static function (): void {
    register_setting('pagenest_companion_media', 'pagenest_companion_media', [
        'type' => 'array',
        'sanitize_callback' => static function ($input) {
            $current = pagenest_companion_media_config();
            if (!current_user_can('manage_options')) {
                return $current;
            }
            $stored = get_option('pagenest_companion_media', $current);
            try {
                $current = pagenest_companion_media_validate($stored);
            } catch (InvalidArgumentException $error) {
                add_settings_error(
                    'pagenest_companion_media',
                    'invalid_media',
                    __(
                        'The stored gallery configuration is invalid. Ask your migration administrator to repair it before changing the toggle.',
                        'pagenest-companion',
                    ),
                );
                return $stored;
            }
            // Provider changes keep all folder identities and gallery mappings intact.
            $current['enabled'] = is_array($input) && ($input['enabled'] ?? '') === '1';
            $current['library_provider'] =
                is_array($input) && ($input['native_library'] ?? '') === '1'
                    ? 'native'
                    : 'external';
            if ($current['library_provider'] === 'native' && empty($current['library_taxonomy'])) {
                $current['library_taxonomy'] = 'pagenest_media_folder';
            }
            return pagenest_companion_media_validate($current);
        },
    ]);
});

function pagenest_companion_media_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $config = pagenest_companion_media_config();
    pagenest_companion_admin_header(
        '媒体与文件夹',
        '管理画廊、PDF 与媒体文件夹的展示方式。图片和文件继续保存在原位置。',
        'pagenest-media',
    );
    echo '<div class="pn-admin-layout"><form class="pn-admin-main" method="post" action="options.php"><section class="pn-admin-section"><h2>媒体展示与组织</h2>';
    settings_fields('pagenest_companion_media');
    echo '<input type="hidden" name="pagenest_companion_media[enabled]" value="0"><p><label><input type="checkbox" name="pagenest_companion_media[enabled]" value="1" ' .
        checked($config['enabled'], true, false) .
        '> ' .
        esc_html(
            pagenest_companion_folder_text(
                'Enable galleries and PDF previews',
                '启用画廊与 PDF 预览',
            ),
        ) .
        '</label></p><p>' .
        esc_html(
            pagenest_companion_folder_text(
                'Existing shortcode or block owners take precedence. Disabling this module preserves the gallery mappings.',
                '优先使用已有的短代码或区块展示。关闭后保留画廊映射，随时可以重新启用。',
            ),
        ) .
        '</p>';
    echo '<p><label><input type="checkbox" name="pagenest_companion_media[native_library]" value="1" ' .
        checked(($config['library_provider'] ?? 'external') === 'native', true, false) .
        '> ' .
        esc_html(
            pagenest_companion_folder_text(
                'Use Companion native media folders',
                '使用 Companion 自有媒体文件夹',
            ),
        ) .
        '</label></p><p>' .
        esc_html(
            pagenest_companion_folder_text(
                'An existing provider keeps ownership until it is disabled. Back up and validate existing folders before switching. Disabling preserves all files and folder relationships.',
                '原管理插件停用前仍由其管理目录。切换前请备份并验证现有目录；关闭功能不会删除文件或目录归属。',
            ),
        ) .
        '</p>';
    echo '</section>';
    submit_button('保存媒体设置');
    echo '</form><aside class="pn-admin-help"><h2>文件保持原位</h2><p>设置影响展示与目录管理，不移动媒体文件。关闭功能保留已有画廊映射与文件夹关系。</p><h2>整理媒体</h2><p>启用自有文件夹后，可在媒体库按层级浏览，在媒体文件夹页面管理目录与归属。</p></aside></div></div>';
}
