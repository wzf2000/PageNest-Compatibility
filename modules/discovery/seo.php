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
    $description = pagenest_discovery_text($options['description'] ?: get_bloginfo('description'));
    $url = home_url('/');
    $title = $site;
    $image = (int) ($options['image'] ?: get_theme_mod('custom_logo', 0));
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
        $url = wp_get_canonical_url($post) ?: get_permalink($post);
        $title = pagenest_discovery_text($post->post_title, 180);
        if ($post->post_excerpt !== '') {
            $description = pagenest_discovery_text($post->post_excerpt);
        }
        $image = (int) (get_post_thumbnail_id($post) ?: $image);
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
    $image_url = $image ? wp_get_attachment_image_url($image, 'full') : false;
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
        $breadcrumbs['itemListElement'][] = [
            '@type' => 'ListItem',
            'position' => 2,
            'name' => $title,
            'item' => $url,
        ];
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
