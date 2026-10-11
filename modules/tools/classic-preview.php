<?php
namespace PageNest\Tools;
defined('ABSPATH') || exit();
final class ClassicPreview
{
    public static function assets(string $hook): void
    {
        global $post;
        if (
            $hook !== 'post.php' ||
            !Profile::get('rest_namespaces', []) ||
            !$post ||
            !Registry::forPage((int) $post->ID) ||
            !current_user_can('edit_post', $post->ID) ||
            !array_filter(
                Profile::get('shortcodes', []),
                fn($name) => has_shortcode($post->post_content, $name),
            )
        ) {
            return;
        }
        if (function_exists('use_block_editor_for_post') && use_block_editor_for_post($post)) {
            return;
        }
        wp_enqueue_script(
            'site-tools-classic-preview',
            Assets::url('classic-preview.js'),
            [],
            VERSION,
            true,
        );
        wp_add_inline_script(
            'site-tools-classic-preview',
            'window.siteToolsPreview=' .
                wp_json_encode([
                    'url' => rest_url(Profile::get('rest_namespaces')[0] . '/preview'),
                    'nonce' => wp_create_nonce('wp_rest'),
                    'postId' => $post->ID,
                    'shortcodes' => Profile::get('shortcodes', []),
                ]) .
                ';',
            'before',
        );
    }
    public static function route(): void
    {
        foreach (Profile::get('rest_namespaces', []) as $namespace) {
            register_rest_route($namespace, '/preview', [
                'methods' => 'POST',
                'permission_callback' => function ($r) {
                    if (!Host::pageAllowed((string) $r['slug'], (int) $r['post_id'])) {
                        return new \WP_Error('denied', '未启用的预览。', ['status' => 403]);
                    }
                    if (!is_user_logged_in()) {
                        return new \WP_Error('login_required', '请登录。', ['status' => 401]);
                    }
                    if (!wp_verify_nonce($r->get_header('X-WP-Nonce'), 'wp_rest')) {
                        return new \WP_Error('invalid_nonce', '验证已过期。', ['status' => 403]);
                    }
                    return current_user_can('edit_post', (int) $r['post_id'])
                        ? true
                        : new \WP_Error('denied', '无编辑权限。', ['status' => 403]);
                },
                'args' => [
                    'post_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                    'slug' => [
                        'type' => 'string',
                        'required' => true,
                        'pattern' => '^[a-z0-9-]{1,64}$',
                    ],
                ],
                'callback' => function ($r) {
                    $input = $r->get_json_params();
                    if (!is_array($input) || array_diff(array_keys($input), ['post_id', 'slug'])) {
                        return new \WP_Error('invalid_fields', '字段无效。', ['status' => 400]);
                    }
                    return ['html' => Host::render($r['slug'], true), 'interactive' => false];
                },
            ]);
        }
    }
}
add_action('admin_enqueue_scripts', [ClassicPreview::class, 'assets']);
add_action('rest_api_init', [ClassicPreview::class, 'route']);
