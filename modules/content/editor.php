<?php
/** An independent display provider. Server-owned content.json remains read-only. */
defined('ABSPATH') || exit();
function pagenest_guide_options(): array
{
    $value = get_option('pagenest_companion_guide', []);
    return is_array($value)
        ? [
            'enabled' => !empty($value['enabled']),
            'groups' => is_array($value['groups'] ?? null) ? $value['groups'] : [],
        ]
        : ['enabled' => false, 'groups' => []];
}
function pagenest_guide_sanitize($input): array
{
    $old = pagenest_guide_options();
    if (!current_user_can('manage_options')) {
        return $old;
    }
    $input = is_array($input) ? $input : [];
    $old_ids = [];
    foreach ($old['groups'] as $group) {
        foreach ($group['items'] ?? [] as $id) {
            $old_ids[] = absint($id);
        }
    }
    $out = ['enabled' => ($input['enabled'] ?? '') === '1', 'groups' => []];
    $total = 0;
    foreach (
        array_slice(is_array($input['groups'] ?? null) ? $input['groups'] : [], 0, 20)
        as $group
    ) {
        if (!is_array($group)) {
            continue;
        }
        $title = mb_substr(
            sanitize_text_field(is_string($group['title'] ?? null) ? $group['title'] : ''),
            0,
            100,
        );
        if ($title === '') {
            continue;
        }
        $items = [];
        foreach (is_array($group['items'] ?? null) ? $group['items'] : [] as $id) {
            $id = is_scalar($id) ? absint($id) : 0;
            if (!$id || in_array($id, $items, true) || $total >= 200) {
                continue;
            }
            if (!pagenest_discovery_public_post(get_post($id)) && !in_array($id, $old_ids, true)) {
                continue;
            }
            $items[] = $id;
            $total++;
        }
        $out['groups'][] = ['title' => $title, 'items' => $items];
    }
    return $out;
}
add_action('admin_init', static function () {
    register_setting('pagenest_guide', 'pagenest_companion_guide', [
        'type' => 'array',
        'sanitize_callback' => 'pagenest_guide_sanitize',
    ]);
});
add_action('admin_menu', static function () {
    add_options_page(
        __('Content guide', 'pagenest-companion'),
        __('Content guide', 'pagenest-companion'),
        'manage_options',
        'pagenest-guide',
        'pagenest_guide_page',
    );
});
function pagenest_guide_render($attributes = []): string
{
    $options = pagenest_guide_options();
    if (!$options['enabled']) {
        return '';
    }
    $out =
        '<nav class="pn-guide" aria-label="' .
        esc_attr__('Content guide', 'pagenest-companion') .
        '">';
    foreach (array_slice($options['groups'], 0, 20) as $group) {
        $items = '';
        foreach (array_slice($group['items'] ?? [], 0, 200) as $id) {
            $post = get_post(absint($id));
            if (!pagenest_discovery_public_post($post)) {
                continue;
            }
            $items .=
                '<li><a href="' .
                esc_url(get_permalink($post)) .
                '">' .
                esc_html($post->post_title) .
                '</a></li>';
        }
        if ($items) {
            $out .=
                '<section><h2>' .
                esc_html($group['title']) .
                '</h2><ol>' .
                $items .
                '</ol></section>';
        }
    }
    return $out . '</nav>';
}
add_shortcode('pagenest_content_guide', 'pagenest_guide_render');
add_action('wp_enqueue_scripts', static function () {
    if (!pagenest_guide_options()['enabled']) {
        return;
    }
    $manifest = require dirname(__DIR__, 2) . '/assets/manifest.php';
    wp_enqueue_style(
        'pagenest-content-guide',
        plugins_url(
            'assets/' . $manifest['guide_css'],
            dirname(__DIR__, 2) . '/pagenest-compatibility.php',
        ),
        [],
        null,
    );
});
function pagenest_guide_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $options = pagenest_guide_options();
    pagenest_companion_admin_header(
        __('Content guide', 'pagenest-companion'),
        __(
            'Organize links to existing public articles and pages. Source content and chapter order stay with their existing owners.',
            'pagenest-companion',
        ),
        'pagenest-guide',
    );
    echo '<p>' .
        esc_html__(
            'This guide uses its own setting and [pagenest_content_guide] shortcode. Existing server content configuration is read-only and is not imported or overwritten.',
            'pagenest-companion',
        ) .
        '</p><div class="pn-admin-layout"><form class="pn-admin-main" method="post" action="options.php" data-pn-guide-form>';
    settings_fields('pagenest_guide');
    echo '<section class="pn-admin-section"><label><input type="checkbox" name="pagenest_companion_guide[enabled]" value="1" ' .
        checked($options['enabled'], true, false) .
        '> ' .
        esc_html__('Enable content guide', 'pagenest-companion') .
        '</label><p>' .
        esc_html__(
            'Up to 20 groups and 200 links. Use Move up and Move down with a keyboard to change order. Save to update the public guide.',
            'pagenest-companion',
        ) .
        '</p><div data-pn-guide-groups>';
    foreach ($options['groups'] as $i => $group) {
        echo '<fieldset data-pn-guide-group><legend>' .
            esc_html__('Group', 'pagenest-companion') .
            '</legend><label>' .
            esc_html__('Group title', 'pagenest-companion') .
            '<input data-pn-group-title name="pagenest_companion_guide[groups][' .
            (int) $i .
            '][title]" maxlength="100" value="' .
            esc_attr($group['title']) .
            '"></label><div data-pn-group-actions></div><ol data-pn-guide-items>';
        foreach ($group['items'] as $j => $id) {
            $post = get_post($id);
            $valid = pagenest_discovery_public_post($post);
            echo '<li data-pn-guide-item><input type="hidden" name="pagenest_companion_guide[groups][' .
                (int) $i .
                '][items][' .
                (int) $j .
                ']" value="' .
                absint($id) .
                '"><span>' .
                esc_html(
                    $valid
                        ? $post->post_title
                        : sprintf(__('Unavailable source #%d', 'pagenest-companion'), $id),
                ) .
                '</span> ';
            if ($valid) {
                echo '<a href="' .
                    esc_url(get_permalink($post)) .
                    '" target="_blank" rel="noopener">' .
                    esc_html__('Preview source', 'pagenest-companion') .
                    '</a>';
            }
            echo '<div data-pn-item-actions></div></li>';
        }
        echo '</ol></fieldset>';
    }
    echo '</div><button type="button" class="button" data-pn-add-group>' .
        esc_html__('Add group', 'pagenest-companion') .
        '</button></section><section class="pn-admin-section"><h2>' .
        esc_html__('Find existing content', 'pagenest-companion') .
        '</h2><label>' .
        esc_html__('Search public articles and pages', 'pagenest-companion') .
        '<input type="search" data-pn-guide-query maxlength="100"></label><button class="button" type="button" data-pn-guide-search>' .
        esc_html__('Search', 'pagenest-companion') .
        '</button><label>' .
        esc_html__('Add to group', 'pagenest-companion') .
        '<select data-pn-guide-target></select></label><p data-pn-guide-status role="status"></p><ul data-pn-guide-catalog></ul><button type="button" class="button" data-pn-guide-more hidden>' .
        esc_html__('Load more', 'pagenest-companion') .
        '</button></section><section class="pn-admin-section"><h2>' .
        esc_html__('Preview saved guide', 'pagenest-companion') .
        '</h2>' .
        pagenest_guide_render() .
        '</section>';
    submit_button();
    echo '</form></div></div>';
}
add_action('rest_api_init', static function () {
    register_rest_route('pagenest/v1', '/guide/catalog', [
        'methods' => 'GET',
        'permission_callback' => static fn() => current_user_can('manage_options'),
        'callback' => static function ($request) {
            $q = is_string($request['q'])
                ? mb_substr(sanitize_text_field($request['q']), 0, 100)
                : '';
            $page = max(1, min(1000, absint($request['page'])));
            $posts = get_posts([
                'post_type' => ['post', 'page'],
                'post_status' => 'publish',
                'has_password' => false,
                's' => $q,
                'posts_per_page' => 21,
                'offset' => ($page - 1) * 20,
                'orderby' => 'ID',
                'order' => 'DESC',
                'no_found_rows' => true,
            ]);
            $items = [];
            foreach (array_slice($posts, 0, 20) as $post) {
                if (pagenest_discovery_public_post($post)) {
                    $items[] = [
                        'id' => (int) $post->ID,
                        'title' => wp_strip_all_tags($post->post_title),
                        'url' => get_permalink($post),
                    ];
                }
            }
            $response = new WP_REST_Response([
                'items' => $items,
                'next_page' => count($posts) > 20 ? $page + 1 : null,
            ]);
            $response->header('Cache-Control', 'private, no-store');
            return $response;
        },
    ]);
});
add_action('admin_enqueue_scripts', static function ($hook) {
    if ($hook !== 'settings_page_pagenest-guide' || !current_user_can('manage_options')) {
        return;
    }
    $manifest = require dirname(__DIR__, 2) . '/assets/manifest.php';
    wp_enqueue_script(
        'pagenest-guide-admin',
        plugins_url(
            'assets/' . $manifest['guide_admin_js'],
            dirname(__DIR__, 2) . '/pagenest-compatibility.php',
        ),
        [],
        null,
        true,
    );
    wp_localize_script('pagenest-guide-admin', 'PageNestGuide', [
        'api' => rest_url('pagenest/v1/guide/catalog'),
        'nonce' => wp_create_nonce('wp_rest'),
        'text' => [
            'group' => __('Group', 'pagenest-companion'),
            'title' => __('Group title', 'pagenest-companion'),
            'up' => __('Move up', 'pagenest-companion'),
            'down' => __('Move down', 'pagenest-companion'),
            'remove' => __('Remove', 'pagenest-companion'),
            'add' => __('Add link', 'pagenest-companion'),
            'preview' => __('Preview source', 'pagenest-companion'),
            'loading' => __('Loading…', 'pagenest-companion'),
            'empty' => __('No matching public content.', 'pagenest-companion'),
            'error' => __('Could not load content. Try again.', 'pagenest-companion'),
            'limit' => __('The guide has reached its group or link limit.', 'pagenest-companion'),
            'unsaved' => __('You have unsaved guide changes.', 'pagenest-companion'),
        ],
    ]);
});
