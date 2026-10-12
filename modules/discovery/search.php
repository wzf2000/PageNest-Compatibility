<?php
defined('ABSPATH') || exit();
function pagenest_discovery_tools(): array
{
    if (!class_exists('PageNest\\Tools\\Registry') || !\PageNest\Tools\Registry::healthy()) {
        return [];
    }
    return array_values(
        array_filter(
            \PageNest\Tools\Registry::indexEntries(),
            static fn($t) => $t['kind'] === 'internal',
        ),
    );
}
add_filter(
    'pagenest_search_endpoint',
    static fn($value) => !empty(pagenest_discovery_options()['search'])
        ? rest_url('pagenest/v1/discovery')
        : $value,
);
add_filter('pagenest_search_kinds', static function ($kinds) {
    if (!empty(pagenest_discovery_options()['search']) && pagenest_discovery_tools()) {
        $kinds['tool'] = '工具与游戏';
    }
    return $kinds;
});
function pagenest_discovery_query(string $text, string $kind, int $limit = 8): array
{
    $args = [
        'post_type' => in_array($kind, ['post', 'page'], true) ? $kind : ['post', 'page'],
        'post_status' => 'publish',
        'has_password' => false,
        's' => $text,
        'posts_per_page' => $limit,
        'no_found_rows' => true,
        'ignore_sticky_posts' => true,
    ];
    $tools = pagenest_discovery_tools();
    if ($kind === 'tool') {
        $args['post_type'] = 'page';
        $args['post__in'] = array_map(static fn($t) => $t['binding']['post_id'], $tools) ?: [0];
    }
    // Exclude restricted tool pages even in the generic page/all scopes.
    if (class_exists('PageNest\\Tools\\Registry')) {
        $excluded = [];
        foreach (\PageNest\Tools\Registry::all() as $tool) {
            if (
                $tool['kind'] === 'internal' &&
                !pagenest_discovery_public_post(get_post($tool['binding']['post_id']))
            ) {
                $excluded[] = $tool['binding']['post_id'];
            }
        }
        if ($excluded) {
            if (isset($args['post__in'])) {
                $args['post__in'] = array_values(array_diff($args['post__in'], $excluded)) ?: [0];
            } else {
                $args['post__not_in'] = $excluded;
            }
        }
    }
    if (class_exists('PageNest\\Tools\\Registry') && !\PageNest\Tools\Registry::healthy()) {
        if ($kind === 'page' || $kind === 'tool') {
            $args['post__in'] = [0];
        } else {
            $args['post_type'] = 'post';
        }
    }
    return $args;
}
add_action(
    'pre_get_posts',
    static function ($query): void {
        if (
            is_admin() ||
            !$query->is_main_query() ||
            !$query->is_search() ||
            empty(pagenest_discovery_options()['search'])
        ) {
            return;
        }
        $kind = is_string($_GET['pn_kind'] ?? null)
            ? sanitize_key(wp_unslash($_GET['pn_kind']))
            : 'all';
        if (!in_array($kind, ['all', 'post', 'page', 'tool'], true)) {
            $kind = 'all';
        }
        $args = pagenest_discovery_query((string) $query->get('s'), $kind);
        foreach (['post_type', 'post_status', 'has_password', 'post__in', 'post__not_in'] as $key) {
            if (isset($args[$key])) {
                $query->set($key, $args[$key]);
            }
        }
    },
    30,
);
add_action('rest_api_init', static function (): void {
    register_rest_route('pagenest/v1', '/discovery', [
        'methods' => 'GET',
        'permission_callback' => static fn() => !empty(pagenest_discovery_options()['search']),
        'args' => [
            'q' => [
                'required' => true,
                'type' => 'string',
                'minLength' => 2,
                'maxLength' => 100,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => static function ($value) {
                    $text = trim(sanitize_text_field($value));
                    return mb_strlen($text) >= 2
                        ? $text
                        : new WP_Error('rest_invalid_param', '请输入至少两个字符。', [
                            'status' => 400,
                        ]);
                },
            ],
            'kind' => [
                'default' => 'all',
                'type' => 'string',
                'enum' => ['all', 'post', 'page', 'tool'],
            ],
        ],
        'callback' => static function ($request) {
            $query = new WP_Query(pagenest_discovery_query($request['q'], $request['kind']));
            $tools = array_column(array_column(pagenest_discovery_tools(), 'binding'), 'post_id');
            $items = [];
            foreach ($query->posts as $post) {
                if (!pagenest_discovery_public_post($post)) {
                    continue;
                }
                $items[] = [
                    'id' => $post->ID,
                    'title' => pagenest_discovery_text($post->post_title, 180),
                    'url' => get_permalink($post),
                    'kind_label' => in_array($post->ID, $tools, true)
                        ? '工具'
                        : ($post->post_type === 'post'
                            ? '文章'
                            : '页面'),
                ];
            }
            $response = new WP_REST_Response(['items' => $items]);
            $response->header('Cache-Control', 'private, no-store');
            return $response;
        },
    ]);
});
