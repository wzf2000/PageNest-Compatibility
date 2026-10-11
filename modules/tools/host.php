<?php
namespace PageNest\Tools;
defined('ABSPATH') || exit();
final class Host
{
    public static function init(): void
    {
        Registry::boot();
        foreach (Profile::get('shortcodes', []) as $shortcode) {
            add_shortcode(
                $shortcode,
                fn($a) => self::render(
                    is_array($a) && is_string($a['slug'] ?? null) ? $a['slug'] : '',
                ),
            );
        }
        foreach (Profile::get('blocks', []) as $block) {
            register_block_type($block, [
                'api_version' => 3,
                'attributes' => ['slug' => ['type' => 'string', 'default' => '']],
                'render_callback' => fn($a) => self::render($a['slug'] ?? ''),
            ]);
        }
    }
    public static function pageAllowed(string $slug, int $id): bool
    {
        return Registry::pageAllowed($slug, $id);
    }
    public static function render(string $slug, bool $editorPreview = false): string
    {
        $tool = Registry::get($slug);
        if (
            !$tool ||
            $tool['kind'] !== 'internal' ||
            (!$editorPreview && !self::pageAllowed($slug, (int) get_the_ID()))
        ) {
            return '<p class="site-tools-unavailable">此工具尚未在本页启用。</p>';
        }
        $page = get_post($tool['binding']['post_id']);
        $pageReadable =
            $page &&
            !post_password_required($page) &&
            ($page->post_status === 'publish' ||
                ($page->post_status === 'private' &&
                    is_user_logged_in() &&
                    current_user_can('read_post', $page->ID)));
        if (
            !Registry::allowed($tool) ||
            !$pageReadable ||
            (Providers::has($tool['render']) &&
                Providers::needsServiceAccess($tool['render']) &&
                !Registry::serviceAllowed($slug))
        ) {
            if (
                is_user_logged_in() &&
                current_user_can('read') &&
                Providers::has($tool['render'])
            ) {
                return self::archive($tool, $editorPreview);
            }
            return '<p class="site-tools-unavailable">此工具已暂停使用，已有数据保留。</p>';
        }
        try {
            $links = self::assets($tool, $editorPreview);
            $content = Providers::render($tool, $editorPreview);
            return $links . $content;
        } catch (\Throwable $e) {
            error_log('site-tools render failed');
            return '<p class="site-tools-unavailable">工具暂时不可用，请稍后重试。</p>';
        }
    }
    private static function assets(array $tool, bool $preview): string
    {
        $styles = [];
        $scripts = [];
        foreach (Providers::legacyStyles($tool) as $file) {
            $handle = 'site-tools-legacy-' . substr(hash('sha256', $file), 0, 12);
            wp_enqueue_style($handle, home_url($file), [], null);
            $styles[] = $handle;
        }
        foreach (Providers::assets($tool) as $file) {
            $handle =
                'site-tools-' . Providers::identity($tool['render']) . '-' . sanitize_key($file);
            $url = Assets::url($file, $tool['render']);
            if (str_ends_with($file, '.js')) {
                if (!$preview) {
                    wp_enqueue_script($handle, $url, $scripts, VERSION, true);
                    $scripts[] = $handle;
                }
            } else {
                wp_enqueue_style($handle, $url, [], VERSION);
                $styles[] = $handle;
            }
        }
        if (!$styles) {
            return '';
        }
        ob_start();
        wp_print_styles($styles);
        return ob_get_clean();
    }
    private static function archive(array $tool, bool $preview): string
    {
        if (
            !current_user_can('read_post', $tool['binding']['post_id']) ||
            post_password_required(get_post($tool['binding']['post_id']))
        ) {
            return '<p class="site-tools-unavailable">请先解锁本页，或使用个人数据取回入口。</p>';
        }
        try {
            return self::assets($tool, $preview) . Providers::archive($tool);
        } catch (\Throwable $e) {
            return '<p class="site-tools-unavailable">个人数据取回入口暂不可用，请稍后重试。</p>';
        }
    }
}
