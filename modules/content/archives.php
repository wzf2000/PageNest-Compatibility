<?php
defined('ABSPATH') || exit();

function pagenest_companion_content_archive_term(array $target)
{
    if (
        !in_array($target['taxonomy'] ?? null, ['category', 'post_tag'], true) ||
        !is_int($target['term_id'] ?? null) ||
        $target['term_id'] < 1
    ) {
        return null;
    }
    $term = get_term($target['term_id'], $target['taxonomy']);
    return $term &&
        !is_wp_error($term) &&
        $term->taxonomy === $target['taxonomy'] &&
        (int) $term->term_id === $target['term_id']
        ? $term
        : null;
}

/** Only public, password-free posts establish a series link; term.count is not a permission check. */
function pagenest_companion_content_archive_has_public_posts(array $target): bool
{
    $query = new WP_Query([
        'post_type' => 'post',
        'post_status' => 'publish',
        'has_password' => false,
        'posts_per_page' => 1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'ignore_sticky_posts' => true,
        'suppress_filters' => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'tax_query' => [
            [
                'taxonomy' => $target['taxonomy'],
                'field' => 'term_id',
                'terms' => [$target['term_id']],
                'include_children' => true,
            ],
        ],
    ]);
    return !empty($query->posts);
}

/** Pure HTML projection: existing term names, post titles and public links only. */
function pagenest_companion_content_archive_guide(array $hub): string
{
    $term = pagenest_companion_content_archive_term($hub);
    if (!$term || !is_string($hub['intro'] ?? null)) {
        return '';
    }
    $groups = [];
    foreach ($hub['groups'] ?? [] as $group) {
        $groupTerm = pagenest_companion_content_archive_term($group);
        if (!$groupTerm || !pagenest_companion_content_archive_has_public_posts($group)) {
            continue;
        }
        $url = get_term_link($groupTerm);
        if (is_wp_error($url) || !is_string($url) || esc_url($url) === '') {
            continue;
        }
        $groups[] = '<a href="' . esc_url($url) . '">' . esc_html($groupTerm->name) . '</a>';
    }
    $starts = [];
    foreach ($hub['start_posts'] ?? [] as $id) {
        $post = get_post($id);
        if (
            !$post ||
            !in_array($post->post_type, ['post', 'page'], true) ||
            $post->post_status !== 'publish' ||
            $post->post_password !== ''
        ) {
            continue;
        }
        $url = get_permalink($post);
        if (!is_string($url) || esc_url($url) === '') {
            continue;
        }
        $starts[] = '<a href="' . esc_url($url) . '">' . esc_html($post->post_title) . '</a>';
    }
    $html =
        '<nav class="pagenest-content-archive" aria-label="' .
        esc_attr(
            pagenest_companion_content_config(
                'modules.archives.labels.navigation',
                'Content guide',
            ),
        ) .
        '"><p class="pagenest-content-archive-intro">' .
        esc_html($hub['intro']) .
        '</p>';
    if ($groups) {
        $html .=
            '<div class="pagenest-content-archive-row"><span class="pagenest-content-archive-label">' .
            esc_html(
                pagenest_companion_content_config('modules.archives.labels.groups', 'Series'),
            ) .
            '</span><div class="pagenest-content-archive-links">' .
            implode('', $groups) .
            '</div></div>';
    }
    if ($starts) {
        $html .=
            '<div class="pagenest-content-archive-row"><span class="pagenest-content-archive-label">' .
            esc_html(
                pagenest_companion_content_config(
                    'modules.archives.labels.starts',
                    'Start reading',
                ),
            ) .
            '</span><div class="pagenest-content-archive-links pagenest-content-archive-starts">' .
            implode('', $starts) .
            '</div></div>';
    }
    return $html . '</nav>';
}

function pagenest_companion_content_archive_current(): ?array
{
    if (
        is_admin() ||
        is_feed() ||
        is_paged() ||
        (defined('REST_REQUEST') && REST_REQUEST) ||
        (!is_category() && !is_tag())
    ) {
        return null;
    }
    $term = get_queried_object();
    if (!$term || !isset($term->taxonomy, $term->term_id)) {
        return null;
    }
    if (!pagenest_companion_content_config('modules.archives.enabled', false)) {
        return null;
    }
    foreach (pagenest_companion_content_config('modules.archives.items', []) as $hub) {
        if ($hub['taxonomy'] === $term->taxonomy && $hub['term_id'] === (int) $term->term_id) {
            return $hub;
        }
    }
    return null;
}

add_action('wp_enqueue_scripts', static function (): void {
    if (pagenest_companion_content_archive_current()) {
        pagenest_companion_content_assets(false);
    }
});
add_action('loop_start', static function ($query): void {
    static $rendered = false;
    if ($rendered || !$query->is_main_query()) {
        return;
    }
    $hub = pagenest_companion_content_archive_current();
    if (!$hub) {
        return;
    }
    $rendered = true;
    echo pagenest_companion_content_archive_guide($hub);
});
