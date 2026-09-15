<?php
/**
 * Plugin Name: PageNest Compatibility
 * Description: 为栖页主题提供评论邮件通知、Markdown 编辑兼容、数学公式与代码高亮支持，并衔接站点原有功能。
 * Version: 0.4.0
 * Plugin URI: https://github.com/wzf2000/PageNest-Compatibility
 * Author: wzf2000
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */
defined('ABSPATH') || exit();
// Keep only the old Markdown preservation contract, never old reward/like handlers.
add_filter('jetpack_markdown_preserve_pattern', static function ($patterns) {
    $patterns[] = '/(\$)([^\n\r\$]+?)(\$)/s';
    $patterns[] = '/(\s*?[\$]{2})(\r?\n((\s*[^\s].*\r?\n)+?))(\s*?[\$]{2})/m';
    return array_values(array_unique($patterns));
});
add_filter(
    'use_block_editor_for_post',
    static function ($use, $post) {
        return get_post_meta($post->ID, 'use_block_editor', true) === 'true' ? true : $use;
    },
    100,
    2,
);
add_action('add_meta_boxes_post', static function ($post) {
    add_meta_box(
        'wzfj-editor-choice',
        '使用的编辑器',
        static function ($post) {
            wp_nonce_field('wzfj-editor-choice', 'wzfj-editor-nonce');
            echo '<label><input type="checkbox" name="wzfj-block-editor" value="1" ' .
                checked(get_post_meta($post->ID, 'use_block_editor', true), 'true', false) .
                '> 使用块编辑器（保存后重新打开）</label>';
        },
        'post',
        'side',
    );
});
add_action('save_post_post', static function ($id) {
    if (
        wp_is_post_revision($id) ||
        wp_is_post_autosave($id) ||
        !current_user_can('edit_post', $id) ||
        !isset($_POST['wzfj-editor-nonce']) ||
        !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['wzfj-editor-nonce'])),
            'wzfj-editor-choice',
        )
    ) {
        return;
    }
    update_post_meta(
        $id,
        'use_block_editor',
        isset($_POST['wzfj-block-editor']) ? 'true' : 'false',
    );
});
add_action(
    'wp_enqueue_scripts',
    static function () {
        if (!current_theme_supports('wzf-independent-layout')) {
            return;
        }
        if (is_singular(['post', 'page']) && !wp_script_is('mbb-math', 'enqueued')) {
            // Same renderer and delimiters as the current site; one owner, no parent header.
            wp_register_script(
                'wzfj-mathjax',
                'https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.7/MathJax.js?config=TeX-AMS_HTML',
                [],
                null,
                true,
            );
            wp_add_inline_script(
                'wzfj-mathjax',
                'window.MathJax={showProcessingMessages:false,tex2jax:{inlineMath:[["$","$"],["\\\\(","\\\\)"]],displayMath:[["$$","$$"],["\\\\[","\\\\]"]],processEscapes:true,skipTags:["script","noscript","style","textarea","pre","code"]},menuSettings:{zoom:"Hover"}};',
                'before',
            );
            wp_enqueue_script('wzfj-mathjax');
        }
        global $wp_filter;
        $hook = $wp_filter['wp_print_footer_scripts'] ?? null;
        if ($hook) {
            foreach ($hook->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $cb) {
                    $fn = $cb['function'];
                    if (
                        is_array($fn) &&
                        is_object($fn[0]) &&
                        get_class($fn[0]) === 'EditormdApp\\PrismJSAuto' &&
                        $fn[1] === 'prism_wp_footer_scripts'
                    ) {
                        remove_action('wp_print_footer_scripts', $fn, $priority);
                    }
                }
            }
        }
        wp_add_inline_script(
            'prism-core-js',
            'Prism.languages.text = Prism.languages.plaintext = Prism.languages.plain = {};',
            'after',
        );
        $scripts = wp_scripts();
        if (isset($scripts->registered['prism-plugin-autoloader'])) {
            $scripts->registered['prism-plugin-autoloader']->deps[] = 'prism-core-js';
            wp_add_inline_script(
                'prism-plugin-autoloader',
                'Prism.plugins.autoloader.languages_path = ' .
                    wp_json_encode(plugins_url('wp-editormd/assets/Prism.js/components/')) .
                    ';',
                'after',
            );
        }
    },
    120,
);
// Preserve old embedded login prompts without old login UI, score routes or rewards.
add_shortcode(
    '2048_get_login_button',
    static fn() => is_user_logged_in()
        ? ''
        : '<p><a href="' . esc_url(wp_login_url(get_permalink())) . '">登录</a>后可记录成绩。</p>',
);
// Stable compatibility names consumed by the existing resource-scope adapter.
if (!function_exists('wzfl_active')) {
    function wzfl_active()
    {
        return !is_admin() && !is_feed() && current_theme_supports('wzf-independent-layout');
    }
}
if (!function_exists('wzfl_template_scope')) {
    function wzfl_template_scope()
    {
        return is_front_page() ||
            is_home() ||
            is_singular(['post', 'page']) ||
            is_archive() ||
            is_search();
    }
}

// Companion's Materialis-only control inherits a Kirki class supplied by that theme.
// Keep Companion active, but do not register this control for an independent theme.
add_action(
    'customize_register',
    static function () {
        if (!current_theme_supports('wzf-independent-layout')) {
            return;
        }
        global $wp_filter;
        $hook = $wp_filter['customize_register'] ?? null;
        $owner = realpath(WP_PLUGIN_DIR . '/materialis-companion/src/Companion.php');
        if (!$hook || !$owner) {
            return;
        }
        foreach ($hook->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $cb) {
                $fn = $cb['function'];
                if (
                    $fn instanceof Closure &&
                    realpath((new ReflectionFunction($fn))->getFileName()) === $owner
                ) {
                    remove_action('customize_register', $fn, $priority);
                }
            }
        }
    },
    -100,
);

require_once __DIR__ . '/comment-notifications.php';
