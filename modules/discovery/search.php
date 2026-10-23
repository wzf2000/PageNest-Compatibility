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
        $kinds['tool'] = __('Tools and games', 'pagenest-companion');
    }
    return $kinds;
});
function pagenest_discovery_query(
    string $text,
    string $kind,
    int $limit = 8,
    array $filters = [],
): array {
    $args = [
        'post_type' => in_array($kind, ['post', 'page'], true) ? $kind : ['post', 'page'],
        'post_status' => 'publish',
        'has_password' => false,
        's' => $text,
        'posts_per_page' => $limit,
        'no_found_rows' => true,
        'ignore_sticky_posts' => true,
    ];
    $args['orderby'] =
        ($filters['sort'] ?? '') === 'modified'
            ? ['modified' => 'DESC', 'ID' => 'DESC']
            : 'relevance';
    if (!empty($filters['category'])) {
        $args['category__in'] = [absint($filters['category'])];
    }
    if (!empty($filters['series'])) {
        $legacy = pagenest_companion_config('chapters', 'series_meta');
        $args['meta_query'] = [
            'relation' => 'OR',
            ['key' => '_pagenest_series', 'value' => $filters['series']],
            [
                'relation' => 'AND',
                ['key' => '_pagenest_series', 'compare' => 'NOT EXISTS'],
                ['key' => $legacy, 'value' => $filters['series']],
            ],
        ];
    }
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
        $args = pagenest_discovery_query(
            (string) $query->get('s'),
            $kind,
            8,
            pagenest_discovery_filters($_GET),
        );
        foreach (
            [
                'post_type',
                'post_status',
                'has_password',
                'post__in',
                'post__not_in',
                'category__in',
                'meta_query',
                'orderby',
            ]
            as $key
        ) {
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
            'category' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            'series' => ['type' => 'string', 'maxLength' => 100, 'default' => ''],
            'sort' => [
                'type' => 'string',
                'enum' => ['relevance', 'modified'],
                'default' => 'relevance',
            ],
            'kind' => [
                'default' => 'all',
                'type' => 'string',
                'enum' => ['all', 'post', 'page', 'tool'],
            ],
        ],
        'callback' => static function ($request) {
            $query = new WP_Query(
                pagenest_discovery_query(
                    $request['q'],
                    $request['kind'],
                    8,
                    pagenest_discovery_filters($request->get_params()),
                ),
            );
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
                    'excerpt' => pagenest_discovery_excerpt($post, $request['q']),
                    'sections' => pagenest_discovery_sections($post, $request['q']),
                    'kind_label' => in_array($post->ID, $tools, true)
                        ? __('Tool', 'pagenest-companion')
                        : ($post->post_type === 'post'
                            ? __('Article', 'pagenest-companion')
                            : __('Page', 'pagenest-companion')),
                ];
            }
            $response = new WP_REST_Response(['items' => $items]);
            $response->header('Cache-Control', 'private, no-store');
            return $response;
        },
    ]);
});

/** Saved content only. Never render blocks, shortcodes or content filters while searching. */
function pagenest_discovery_filters($input): array
{
    return [
        'category' => absint($input['category'] ?? ($input['pn_category'] ?? 0)),
        'series' => mb_substr(
            sanitize_text_field($input['series'] ?? ($input['pn_series'] ?? '')),
            0,
            100,
        ),
        'sort' =>
            ($input['sort'] ?? ($input['pn_sort'] ?? '')) === 'modified' ? 'modified' : 'relevance',
    ];
}
function pagenest_discovery_excerpt($post, string $query): string
{
    $text = pagenest_discovery_text($post->post_excerpt ?? '', 200000);
    if ($text === '') {
        $text = pagenest_discovery_text($post->post_content, 200000);
    }
    $position = $query !== '' ? mb_stripos($text, $query) : false;
    $start = $position === false ? 0 : max(0, $position - 50);
    return ($start ? '…' : '') .
        mb_substr($text, $start, 180) .
        (mb_strlen($text) > $start + 180 ? '…' : '');
}
function pagenest_discovery_sections($post, string $query = ''): array
{
    // Inspect the saved static block HTML, including paired MarkBridge content. Never render it.
    if (
        !$post ||
        strlen($post->post_content) > 1000000 ||
        pagenest_discovery_has_shortcodes($post->post_content)
    ) {
        return [];
    }
    if (function_exists('parse_blocks')) {
        $static = static function ($blocks) use (&$static): bool {
            foreach ($blocks as $block) {
                if (
                    !in_array(
                        $block['blockName'],
                        [
                            null,
                            'core/heading',
                            'core/paragraph',
                            'core/list',
                            'core/list-item',
                            'core/group',
                            'core/columns',
                            'core/column',
                            'core/html',
                            'core/freeform',
                            'core/image',
                            'core/code',
                            'core/preformatted',
                            'core/quote',
                            'core/table',
                            'core/separator',
                            'core/details',
                            'core/file',
                            'core/video',
                            'core/audio',
                            'mbb/math',
                        ],
                        true,
                    ) ||
                    !$static($block['innerBlocks'] ?? [])
                ) {
                    return false;
                }
            }
            return true;
        };
        if (!$static(parse_blocks($post->post_content))) {
            return [];
        }
    }
    $dom = new DOMDocument('1.0', 'UTF-8');
    $errors = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $post->post_content, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($errors);
    $xp = new DOMXPath($dom);
    $counts = [];
    foreach ($xp->query('//*[@id]') as $node) {
        $id = $node->getAttribute('id');
        $counts[$id] = ($counts[$id] ?? 0) + 1;
    }
    $out = [];
    foreach (
        $xp->query('//h1[@id]|//h2[@id]|//h3[@id]|//h4[@id]|//h5[@id]|//h6[@id]')
        as $heading
    ) {
        $id = $heading->getAttribute('id');
        $label = pagenest_discovery_text($heading->textContent, 180);
        if (
            ($counts[$id] ?? 0) !== 1 ||
            !preg_match('/^[^\s\x00-\x1f]{1,200}$/u', $id) ||
            $label === '' ||
            ($query !== '' && mb_stripos($label, $query) === false) ||
            $xp->query(
                'ancestor-or-self::*[@data-mbb-generated or @data-pagenest-generated or @hidden or @aria-hidden="true"]',
                $heading,
            )->length
        ) {
            continue;
        }
        $out[] = [
            'id' => $id,
            'title' => $label,
            'url' => get_permalink($post) . '#' . rawurlencode($id),
        ];
        if (count($out) >= 100) {
            break;
        }
    }
    return $out;
}
add_filter(
    'pagenest_search_excerpt',
    static fn($value, $post, $query) => pagenest_discovery_public_post($post)
        ? pagenest_discovery_excerpt($post, $query)
        : $value,
    10,
    3,
);
add_filter(
    'pagenest_search_sections',
    static fn($value, $post, $query) => pagenest_discovery_public_post($post)
        ? pagenest_discovery_sections($post, $query)
        : [],
    10,
    3,
);
add_filter('pagenest_search_series', static function ($values) {
    foreach (
        get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => 200,
            'no_found_rows' => true,
        ])
        as $post
    ) {
        if (!pagenest_discovery_public_post($post)) {
            continue;
        }
        $series = pagenest_companion_post_setting($post->ID, 'series');
        if (is_string($series) && $series !== '') {
            $values[$series] = $series;
        }
    }
    return $values;
});
