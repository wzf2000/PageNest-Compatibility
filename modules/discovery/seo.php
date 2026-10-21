<?php
defined('ABSPATH') || exit();
function pagenest_seo_external_owner(): bool
{
    return (bool) apply_filters(
        'pagenest_companion_seo_external_owner',
        defined('WPSEO_VERSION') ||
            defined('RANK_MATH_VERSION') ||
            defined('AIOSEO_VERSION') ||
            defined('SEOPRESS_VERSION') ||
            defined('THE_SEO_FRAMEWORK_VERSION') ||
            defined('SLIM_SEO_VERSION'),
    );
}
/** A complete public category branch; corrupt or inaccessible ancestry is omitted. */
function pagenest_seo_category_branch($term): array
{
    $branch = [];
    $seen = [];
    while ($term) {
        if (
            is_wp_error($term) ||
            $term->taxonomy !== 'category' ||
            !is_term_publicly_viewable($term) ||
            isset($seen[$term->term_id]) ||
            count($seen) >= 30
        ) {
            return [];
        }
        $seen[$term->term_id] = true;
        $link = get_term_link($term);
        if (is_wp_error($link) || !$link) {
            return [];
        }
        $branch[] = ['name' => pagenest_discovery_text($term->name, 180), 'item' => $link];
        if (!$term->parent) {
            return array_reverse($branch);
        }
        $term = get_term($term->parent, 'category');
    }
    return [];
}
function pagenest_seo_breadcrumb_parents(): array
{
    $object = get_queried_object();
    if (is_singular('page')) {
        $parents = [];
        $seen = [(int) $object->ID => true];
        while ($object->post_parent) {
            $object = get_post($object->post_parent);
            if (
                !$object ||
                $object->post_type !== 'page' ||
                !pagenest_discovery_public_post($object) ||
                isset($seen[$object->ID]) ||
                count($seen) >= 30
            ) {
                return [];
            }
            $seen[$object->ID] = true;
            $parents[] = [
                'name' => pagenest_discovery_text($object->post_title, 180),
                'item' => get_permalink($object),
            ];
        }
        return array_reverse($parents);
    }
    if (is_category()) {
        $branch = pagenest_seo_category_branch($object);
        array_pop($branch);
        return $branch;
    }
    if (!is_singular('post')) {
        return [];
    }
    $terms = get_the_terms($object->ID, 'category');
    if (!$terms || is_wp_error($terms)) {
        return [];
    }
    // Prefer the deepest assigned public branch; ties use the lowest term ID.
    usort($terms, static fn($a, $b) => $a->term_id <=> $b->term_id);
    $selected = [];
    foreach (array_slice($terms, 0, 100) as $term) {
        $branch = pagenest_seo_category_branch($term);
        if (count($branch) > count($selected)) {
            $selected = $branch;
        }
    }
    // Series labels alone have no public entity URL and cannot become breadcrumbs.
    return $selected;
}
function pagenest_seo_metadata(): array
{
    if (
        empty(pagenest_discovery_options()['seo']) ||
        pagenest_seo_external_owner() ||
        is_admin() ||
        is_feed() ||
        is_search() ||
        is_404() ||
        is_preview() ||
        is_paged() ||
        (int) get_query_var('page') > 1 ||
        !get_option('blog_public')
    ) {
        return [];
    }
    $options = pagenest_discovery_options();
    $site = pagenest_discovery_text(get_bloginfo('name'));
    $defaults = pagenest_seo_defaults();
    $description = $defaults['description'];
    $url = home_url('/');
    $title = $site;
    $image = $defaults['image'];
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        '@id' => $url . '#website',
        'url' => $url,
        'name' => $site,
    ];
    $type = 'website';
    if (is_singular()) {
        $post = get_queried_object();
        if (!pagenest_discovery_public_post($post)) {
            return [];
        }
        $overrides = pagenest_seo_overrides($post->ID);
        $url = wp_get_canonical_url($post) ?: get_permalink($post);
        $defaults = pagenest_seo_defaults($post);
        $title = $overrides['title'] ?: $defaults['title'];
        $description = $overrides['description'] ?: $defaults['description'];
        $image = pagenest_seo_image($post, $overrides['image']);
        if (!is_front_page()) {
            $type = $post->post_type === 'post' ? 'article' : 'website';
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => $type === 'article' ? 'BlogPosting' : 'WebPage',
                '@id' => $url . '#content',
                'url' => $url,
                'headline' => $title,
                'name' => $title,
                'datePublished' => get_post_time(DATE_W3C, true, $post),
                'dateModified' => get_post_modified_time(DATE_W3C, true, $post),
                'isPartOf' => ['@id' => home_url('/') . '#website'],
            ];
            if ($type === 'article') {
                $schema['author'] = [
                    '@type' => 'Person',
                    'name' => pagenest_discovery_text(
                        get_the_author_meta('display_name', $post->post_author),
                    ),
                    'url' => get_author_posts_url($post->post_author),
                ];
            }
        }
    } elseif (is_home()) {
        $page = (int) get_option('page_for_posts');
        if ($page) {
            $post = get_post($page);
            if (!pagenest_discovery_public_post($post)) {
                return [];
            }
            $url = get_permalink($post);
            $title = pagenest_discovery_text($post->post_title, 180);
        }
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'url' => $url,
            'name' => $title,
        ];
    } elseif (is_category() || is_tag()) {
        $term = get_queried_object();
        if (!is_term_publicly_viewable($term)) {
            return [];
        }
        $url = get_term_link($term);
        if (is_wp_error($url)) {
            return [];
        }
        $title = pagenest_discovery_text($term->name, 180);
        if ($term->description) {
            $description = pagenest_discovery_text($term->description);
        }
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'url' => $url,
            'name' => $title,
        ];
    } elseif (!is_front_page()) {
        return [];
    }
    $image_url =
        $image && pagenest_seo_public_image($image)
            ? wp_get_attachment_image_url($image, 'full')
            : false;
    if ($image_url) {
        $schema['image'] = $image_url;
    }
    if ($description !== '') {
        $schema['description'] = $description;
    }
    $meta = [
        'og:type' => $type,
        'og:title' => $title,
        'og:url' => $url,
        'og:site_name' => $site,
        'og:locale' => get_locale(),
        'twitter:card' => $image_url ? 'summary_large_image' : 'summary',
        'twitter:title' => $title,
    ];
    if ($description !== '') {
        $meta += [
            'description' => $description,
            'og:description' => $description,
            'twitter:description' => $description,
        ];
    }
    if ($image_url) {
        $meta += ['og:image' => $image_url, 'twitter:image' => $image_url];
    }
    $breadcrumbs = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $site, 'item' => home_url('/')],
        ],
    ];
    if ($url !== home_url('/')) {
        $seen = [home_url('/') => true];
        foreach (
            array_merge(pagenest_seo_breadcrumb_parents(), [['name' => $title, 'item' => $url]])
            as $item
        ) {
            if (!$item['item'] || $item['name'] === '' || isset($seen[$item['item']])) {
                continue;
            }
            $seen[$item['item']] = true;
            $breadcrumbs['itemListElement'][] =
                ['@type' => 'ListItem', 'position' => count($breadcrumbs['itemListElement']) + 1] +
                $item;
        }
    }
    return ['meta' => $meta, 'schema' => [$schema, $breadcrumbs]];
}
add_action(
    'wp_head',
    static function (): void {
        $data = pagenest_seo_metadata();
        if (!$data) {
            return;
        }
        foreach ($data['meta'] as $key => $value) {
            echo '<meta ' .
                (str_starts_with($key, 'og:') ? 'property' : 'name') .
                '="' .
                esc_attr($key) .
                '" content="' .
                esc_attr($value) .
                '">' .
                "\n";
        }
        echo '<script type="application/ld+json">' .
            wp_json_encode(
                $data['schema'],
                JSON_HEX_TAG |
                    JSON_HEX_AMP |
                    JSON_HEX_APOS |
                    JSON_HEX_QUOT |
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES,
            ) .
            '</script>' .
            "\n";
    },
    5,
);
