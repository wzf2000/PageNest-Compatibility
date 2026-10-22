<?php
/** Chapter ordering and file links use WordPress metadata and explicit mappings. */
defined('ABSPATH') || exit();
function pagenest_companion_can_read($post)
{
    return $post &&
        !post_password_required($post) &&
        ($post->post_status === 'publish' ||
            ($post->post_status === 'private' && current_user_can('read_post', $post->ID)));
}

/** Exact stored series labels are identities; neither slugs nor document IDs are inferred. */
function pagenest_companion_series_directories(): array
{
    $admin = get_option('pagenest_companion_series_directories', []);
    $admin = is_array($admin) && count($admin) <= 200 ? $admin : [];
    return array_replace($admin, pagenest_companion_config('chapters', 'series_directories'));
}
/** Explicit bindings let legacy documents join directory navigation without metadata migration. */
function pagenest_companion_directory_series(int $id): string
{
    $series = pagenest_companion_post_setting($id, 'series');
    if (is_string($series) && $series !== '') {
        return $series;
    }
    if ($series !== '') {
        return '';
    }
    $bindings = pagenest_companion_config('chapters', 'document_series');
    if (!$bindings) {
        return '';
    }
    $document = get_post_meta($id, pagenest_companion_config('chapters', 'document_meta'), true);
    return is_string($document) && isset($bindings[$document]) ? $bindings[$document] : '';
}
/** Public ancestry is checked even for administrators and password-cookie holders. */
function pagenest_companion_series_directory(int $id): array
{
    if (!pagenest_companion_feature('chapters')) {
        return [];
    }
    $series = pagenest_companion_directory_series($id);
    if ($series === '') {
        return [];
    }
    $page_id = pagenest_companion_series_directories()[$series] ?? null;
    if (!is_int($page_id) || $page_id < 1 || $page_id === $id) {
        return [];
    }
    $branch = pagenest_companion_public_directory_branch($page_id);
    if (!$branch) {
        return [];
    }
    $target = $branch[count($branch) - 1];
    return [
        'series' => $series,
        'page_id' => $page_id,
        'url' => $target['item'],
        'title' => $target['name'],
        'parents' => $branch,
    ];
}
function pagenest_companion_public_directory_branch(int $page_id): array
{
    $branch = [];
    $seen = [];
    $page = get_post($page_id);
    while ($page) {
        if (
            $page->post_type !== 'page' ||
            $page->post_status !== 'publish' ||
            $page->post_password !== '' ||
            !is_post_publicly_viewable($page) ||
            (function_exists('pagenest_discovery_public_post') &&
                !pagenest_discovery_public_post($page)) ||
            isset($seen[$page->ID]) ||
            count($seen) >= 30
        ) {
            return [];
        }
        $seen[$page->ID] = true;
        $url = get_permalink($page);
        if (!$url) {
            return [];
        }
        $branch[] = ['id' => (int) $page->ID, 'name' => $page->post_title, 'item' => $url];
        if (!$page->post_parent) {
            return array_reverse($branch);
        }
        $page = get_post($page->post_parent);
    }
    return [];
}
add_filter(
    'pagenest_series_directory',
    static function ($directory, $id) {
        // Chapter-display pages already have a return link above the article.
        if (
            function_exists('pagenest_companion_content_chapter') &&
            pagenest_companion_content_chapter((int) $id)
        ) {
            return [];
        }
        return pagenest_companion_series_directory((int) $id);
    },
    10,
    2,
);
require_once __DIR__ . '/series-settings.php';

function pagenest_companion_series($id)
{
    if (!pagenest_companion_feature('chapters')) {
        return [];
    }
    $config = pagenest_companion_config('chapters');
    $series = pagenest_companion_post_setting($id, 'series');
    $document = get_post_meta($id, $config['document_meta'], true);
    if ($series === '' && $document === '') {
        return [];
    }
    $all = get_posts([
        'post_type' => 'post',
        'post_status' => ['publish', 'private'],
        'posts_per_page' => -1,
        'meta_query' =>
            $series !== ''
                ? [
                    'relation' => 'OR',
                    ['key' => '_pagenest_series', 'compare' => 'EXISTS'],
                    ['key' => $config['series_meta'], 'compare' => 'EXISTS'],
                ]
                : [['key' => $config['document_meta'], 'compare' => 'EXISTS']],
        'orderby' => 'meta_value',
        'order' => 'ASC',
    ]);
    $all = array_values(
        array_filter(
            $all,
            static fn($p) => pagenest_companion_can_read($p) &&
                ($series === '' || pagenest_companion_post_setting($p->ID, 'series') === $series),
        ),
    );
    $rank = array_flip($config['series_order']);
    usort($all, static function ($a, $b) use ($config, $rank) {
        $ad = (string) get_post_meta($a->ID, $config['document_meta'], true);
        $bd = (string) get_post_meta($b->ID, $config['document_meta'], true);
        $ar = $rank[$ad] ?? PHP_INT_MAX;
        $br = $rank[$bd] ?? PHP_INT_MAX;
        if ($ar !== $br) {
            return $ar <=> $br;
        }
        $ao = pagenest_companion_post_setting($a->ID, 'order');
        $bo = pagenest_companion_post_setting($b->ID, 'order');
        if (is_numeric($ao) && is_numeric($bo) && $ao != $bo) {
            return (float) $ao <=> (float) $bo;
        }
        return strcmp($ad, $bd) ?: $a->ID <=> $b->ID;
    });
    return $all;
}
add_filter(
    'pagenest_related_posts',
    static function ($posts, $id) {
        $all = pagenest_companion_series($id);
        if (!$all) {
            return $posts;
        }
        $index = array_search((int) $id, array_map(static fn($p) => (int) $p->ID, $all), true);
        if ($index === false) {
            return [];
        }
        $out = [];
        foreach ([1, -1, 2, -2] as $offset) {
            if (isset($all[$index + $offset])) {
                $out[] = $all[$index + $offset];
            }
        }
        return array_slice($out, 0, 3);
    },
    10,
    2,
);
add_filter(
    'pagenest_related_title',
    static fn($title, $id) => pagenest_companion_series($id) ? '同系列章节' : $title,
    10,
    2,
);
add_filter(
    'the_content',
    static function ($html) {
        if (!pagenest_companion_feature('chapters')) {
            return $html;
        }
        $map = pagenest_companion_config('chapters', 'link_map');
        if (!$map || !class_exists('WP_HTML_Tag_Processor')) {
            return $html;
        }
        $tags = new WP_HTML_Tag_Processor($html);
        while ($tags->next_tag('A')) {
            $href = $tags->get_attribute('href');
            if (!is_string($href)) {
                continue;
            }
            $parts = wp_parse_url($href);
            if (
                !is_array($parts) ||
                isset($parts['host']) ||
                isset($parts['scheme']) ||
                isset($parts['query'])
            ) {
                continue;
            }
            $path = $parts['path'] ?? '';
            $file = basename($path);
            if (!isset($map[$file])) {
                continue;
            }
            $post = get_post($map[$file]);
            if (!pagenest_companion_can_read($post)) {
                continue;
            }
            $url = get_permalink($post);
            if (isset($parts['fragment'])) {
                $url .= '#' . $parts['fragment'];
            }
            $tags->set_attribute('href', esc_url($url));
        }
        return $tags->get_updated_html();
    },
    32,
);
