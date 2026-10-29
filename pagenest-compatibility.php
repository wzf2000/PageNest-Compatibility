<?php
/**
 * Plugin Name: PageNest Companion
 * Description: 为栖页主题提供评论邮件通知、Markdown 编辑兼容、数学公式与代码高亮支持，并提供章节链接、段落评论和点赞。
 * Version: 1.12.0
 * Author: PageNest Contributors
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pagenest-companion
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */
defined('ABSPATH') || exit();
add_action(
    'init',
    static function () {
        load_plugin_textdomain(
            'pagenest-companion',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages',
        );
    },
    0,
);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/modules/privacy.php';
try {
    $GLOBALS['pagenest_companion_profile'] = defined('PAGENEST_COMPATIBILITY_PROFILE_FILE')
        ? pagenest_companion_load_profile(PAGENEST_COMPATIBILITY_PROFILE_FILE, ABSPATH)
        : pagenest_companion_defaults();
} catch (Throwable $error) {
    $GLOBALS['pagenest_companion_profile_invalid'] = true;
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>PageNest Companion compatibility profile is invalid; features are disabled.</p></div>';
    });
    return;
}
require_once __DIR__ . '/service-config.php';
$GLOBALS['pagenest_companion_services_profile'] = pagenest_companion_service_defaults();
$GLOBALS['pagenest_companion_services_source_valid'] = true;
if (defined('PAGENEST_COMPANION_SERVICES_FILE')) {
    try {
        $GLOBALS['pagenest_companion_services_profile'] = pagenest_companion_load_services(
            PAGENEST_COMPANION_SERVICES_FILE,
            ABSPATH,
        );
    } catch (Throwable $error) {
        $GLOBALS['pagenest_companion_services_source_valid'] = false;
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p>PageNest Companion service profile is invalid; optional services are disabled.</p></div>';
        });
    }
}
$GLOBALS['pagenest_companion_services_source'] = $GLOBALS['pagenest_companion_services_profile'];
require_once __DIR__ . '/social-settings.php';
pagenest_social_settings_bootstrap();
require_once __DIR__ . '/modules/services/bootstrap.php';
require_once __DIR__ . '/modules/social/bootstrap.php';
require_once __DIR__ . '/modules/settings.php';
require_once __DIR__ . '/modules/views.php';
require_once __DIR__ . '/prism-compatibility.php';
require_once __DIR__ . '/modules/resource-scope.php';
require_once __DIR__ . '/modules/theme-compatibility.php';
require_once __DIR__ . '/modules/chapters.php';
require_once __DIR__ . '/modules/paragraph-comments.php';
require_once __DIR__ . '/modules/likes.php';
require_once __DIR__ . '/modules/content/bootstrap.php';
require_once __DIR__ . '/modules/media/bootstrap.php';
require_once __DIR__ . '/modules/tools/bootstrap.php';
require_once __DIR__ . '/modules/discovery/bootstrap.php';
require_once __DIR__ . '/modules/performance/bootstrap.php';
require_once __DIR__ . '/modules/reading/bootstrap.php';
require_once __DIR__ . '/modules/notifications/bootstrap.php';
require_once __DIR__ . '/modules/content/editor.php';
require_once __DIR__ . '/modules/diagnostics.php';
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
        'pagenest-editor-choice',
        '使用的编辑器',
        static function ($post) {
            wp_nonce_field('pagenest-editor-choice', 'pagenest-editor-nonce');
            echo '<label><input type="checkbox" name="pagenest-block-editor" value="1" ' .
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
        !isset($_POST['pagenest-editor-nonce']) ||
        !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['pagenest-editor-nonce'])),
            'pagenest-editor-choice',
        )
    ) {
        return;
    }
    update_post_meta(
        $id,
        'use_block_editor',
        isset($_POST['pagenest-block-editor']) ? 'true' : 'false',
    );
});
add_action(
    'wp_enqueue_scripts',
    static function () {
        if (!current_theme_supports('pagenest-independent-layout')) {
            return;
        }
        if (is_singular(['post', 'page']) && !wp_script_is('mbb-math', 'enqueued')) {
            // Same renderer and delimiters as the current site; one owner, no parent header.
            wp_register_script(
                'pagenest-mathjax',
                'https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.7/MathJax.js?config=TeX-AMS_HTML',
                [],
                null,
                true,
            );
            wp_add_inline_script(
                'pagenest-mathjax',
                'window.MathJax={showProcessingMessages:false,tex2jax:{inlineMath:[["$","$"],["\\\\(","\\\\)"]],displayMath:[["$$","$$"],["\\\\[","\\\\]"]],processEscapes:true,skipTags:["script","noscript","style","textarea","pre","code"]},menuSettings:{zoom:"Hover"}};',
                'before',
            );
            wp_enqueue_script('pagenest-mathjax');
        }
        pagenest_configure_prism(wp_scripts());
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
if (!function_exists('pagenest_active')) {
    function pagenest_active()
    {
        return !is_admin() && !is_feed() && current_theme_supports('pagenest-independent-layout');
    }
}
if (!function_exists('pagenest_template_scope')) {
    function pagenest_template_scope()
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
        if (!current_theme_supports('pagenest-independent-layout')) {
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
