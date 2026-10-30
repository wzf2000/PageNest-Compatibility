<?php
/** Native-body projections and progressive reading controls. */
defined('ABSPATH') || exit();
function pagenest_companion_content_page(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    foreach (['resources', 'guide', 'reading_links'] as $name) {
        $module = pagenest_companion_content_config('modules.' . $name, []);
        if (empty($module['enabled'])) {
            continue;
        }
        foreach ($module['pages'] as $page) {
            if (
                get_post_meta($id, $page['selector']['meta'], true) === $page['selector']['value']
            ) {
                return ['name' => $name, 'page' => $page, 'labels' => $module['labels'] ?? []];
            }
        }
    }
    return null;
}
function pagenest_companion_content_readable(int $id, array $config): bool
{
    $post = get_post($id);
    if (
        !$post ||
        !in_array($post->post_type, ['post', 'page'], true) ||
        post_password_required($post)
    ) {
        return false;
    }
    if (current_user_can('edit_post', $id)) {
        return true;
    }
    return $post->post_status === 'publish' &&
        $post->post_password === '' &&
        ($config['name'] !== 'resources' ||
            get_post_meta($id, $config['page']['release_meta'], true) === '1');
}
function pagenest_companion_content_public_target($id): bool
{
    $post = is_int($id) && $id > 0 ? get_post($id) : null;
    return $post &&
        in_array($post->post_type, ['post', 'page'], true) &&
        $post->post_status === 'publish' &&
        $post->post_password === '';
}
function pagenest_companion_content_dom(string $html): array
{
    $dom = new DOMDocument();
    $old = libxml_use_internal_errors(true);
    $dom->loadHTML(
        '<?xml encoding="utf-8"><div id="pagenest-content-document">' . $html . '</div>',
        LIBXML_NONET,
    );
    libxml_clear_errors();
    libxml_use_internal_errors($old);
    return [$dom, new DOMXPath($dom), $dom->getElementById('pagenest-content-document')];
}
function pagenest_companion_content_contains(DOMNode $root, DOMNode $node): bool
{
    while ($node->parentNode) {
        if ($node->parentNode === $root) {
            return true;
        }
        $node = $node->parentNode;
    }
    return false;
}
function pagenest_companion_content_inner(DOMDocument $dom, DOMNode $root): string
{
    $html = '';
    foreach ($root->childNodes as $node) {
        $html .= $dom->saveHTML($node);
    }
    return $html;
}
function pagenest_companion_content_match(DOMElement $heading, array $selector): bool
{
    return strtolower($heading->tagName) === 'h' . $selector['level'] &&
        (isset($selector['id'])
            ? $heading->getAttribute('id') === $selector['id']
            : trim($heading->textContent) === $selector['text']);
}
/** Preserve existing heading IDs and historical empty anchors, adding only missing IDs. */
function pagenest_companion_content_sections_apply(
    DOMDocument $dom,
    DOMXPath $xp,
    DOMElement $root,
    array $sections,
    bool $allowIntroductions = false,
): array {
    $found = [];
    foreach ($sections as $index => $section) {
        foreach ($xp->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $root) as $heading) {
            if (!pagenest_companion_content_match($heading, $section['heading'])) {
                continue;
            }
            $anchor = $section['anchor'] ?? '';
            if (!$heading->hasAttribute('id') && $anchor !== '') {
                $owner = $dom->getElementById($anchor);
                // An old empty span remains the owner of its ID. Do not duplicate it.
                if (!$owner) {
                    $heading->setAttribute('id', $anchor);
                }
            }
            $target = $heading->getAttribute('id') ?: $anchor;
            if ($target === '') {
                continue;
            }
            $heading->setAttribute('data-pagenest-section', (string) $index);
            $heading->setAttribute('data-pagenest-anchor', $target);
            $list = null;
            $scan = $heading->nextSibling;
            $listHeadingSeen = !isset($section['list_heading']);
            while ($scan) {
                if ($scan instanceof DOMElement) {
                    if (
                        preg_match('/^h([1-6])$/', strtolower($scan->tagName), $level) &&
                        (int) $level[1] <= $section['heading']['level']
                    ) {
                        break;
                    }
                    if (
                        isset($section['list_heading']) &&
                        pagenest_companion_content_match($scan, $section['list_heading'])
                    ) {
                        $listHeadingSeen = true;
                    } elseif (
                        $listHeadingSeen &&
                        strtolower($scan->tagName) === ($section['list_kind'] ?? 'ul')
                    ) {
                        $list = $scan;
                        break;
                    } elseif (
                        $listHeadingSeen &&
                        $scan->tagName !== 'span' &&
                        !($allowIntroductions && strtolower($scan->tagName) === 'p') &&
                        trim($scan->textContent) !== ''
                    ) {
                        if (!isset($section['list_heading'])) {
                            break;
                        }
                    }
                }
                $scan = $scan->nextSibling;
            }
            if ($list) {
                $list->setAttribute('data-pagenest-list', (string) $index);
                if (!empty($section['start_list'])) {
                    $list->setAttribute(
                        'class',
                        trim($list->getAttribute('class') . ' pagenest-content-start'),
                    );
                }
            }
            $found[] = [
                'index' => $index,
                'anchor' => $target,
                'label' => $section['label'] ?? trim($heading->textContent),
            ];
            break;
        }
    }
    return $found;
}
/** A provider decorates already-authorized links; it never supplies new links. */
function pagenest_companion_resource_entries(array $page): array
{
    $entries = [];
    if (
        ($page['provider'] ?? 'none') === 'site_tools' &&
        class_exists('SiteTools\\Registry', false)
    ) {
        try {
            $entries = \SiteTools\Registry::indexEntries();
        } catch (Throwable $error) {
            $entries = [];
        }
    }
    try {
        $entries = apply_filters('pagenest_companion_resource_entries', $entries, $page);
    } catch (Throwable $error) {
        return [];
    }
    $byUrl = [];
    if (!is_array($entries)) {
        return [];
    }
    foreach ($entries as $entry) {
        if (
            !is_array($entry) ||
            (!is_string($entry['slug'] ?? null) && !is_int($entry['slug'] ?? null)) ||
            !pagenest_companion_content_identifier((string) ($entry['slug'] ?? '')) ||
            !pagenest_companion_content_url($entry['canonical_url'] ?? null)
        ) {
            continue;
        }
        $url = rtrim($entry['canonical_url'], '/');
        // Ambiguous provider projections cannot establish grouping identities.
        if (array_key_exists($url, $byUrl)) {
            $byUrl[$url] = null;
        } else {
            $byUrl[$url] = [
                'slug' => (string) $entry['slug'],
                'icon' => $page['icons'][$entry['slug']] ?? null,
            ];
        }
    }
    return $byUrl;
}
function pagenest_companion_content_allowed_link(string $url, array $targets, array $external): bool
{
    if (str_starts_with($url, '#')) {
        return true;
    }
    $target = $targets[$url] ?? null;
    return pagenest_companion_content_public_target($target) ||
        ($target === 0 && in_array($url, $external, true));
}
function pagenest_companion_content_project(
    DOMDocument $dom,
    DOMXPath $xp,
    DOMElement $root,
    array $page,
    int $id,
    bool $resources,
): void {
    $targets = get_post_meta($id, $page['targets_meta'], true);
    $entries = $resources ? pagenest_companion_resource_entries($page) : [];
    $cards = $duplicates = [];
    // Strip all derived decoration before each projection (REST can run twice).
    foreach (iterator_to_array($xp->query('.//*[@data-pagenest-related]', $root)) as $related) {
        $related->parentNode->removeChild($related);
    }
    foreach ($xp->query('.//li', $root) as $li) {
        foreach (
            ['data-resource-icon', 'data-resource-slug', 'data-resource-parent']
            as $attribute
        ) {
            $li->removeAttribute($attribute);
        }
    }
    foreach (iterator_to_array($xp->query('.//a[@href]', $root)) as $a) {
        if (!$a->parentNode || !pagenest_companion_content_contains($root, $a)) {
            continue;
        }
        $url = $a->getAttribute('href');
        if (
            !pagenest_companion_content_allowed_link($url, $targets, $page['external_links'] ?? [])
        ) {
            $li = $a->parentNode;
            while (
                $li &&
                $li !== $root &&
                !($li instanceof DOMElement && strtolower($li->tagName) === 'li')
            ) {
                $li = $li->parentNode;
            }
            if ($li && $li !== $root) {
                $li->parentNode->removeChild($li);
            } else {
                // Keep prose while removing an unauthorized destination.
                $a->parentNode->replaceChild($dom->createTextNode($a->textContent), $a);
            }
            continue;
        }
        if (!$resources || !isset($entries[rtrim($url, '/')])) {
            continue;
        }
        $li = $a->parentNode;
        while (
            $li &&
            $li !== $root &&
            !($li instanceof DOMElement && strtolower($li->tagName) === 'li')
        ) {
            $li = $li->parentNode;
        }
        if (!$li || $li === $root) {
            continue;
        }
        $entry = $entries[rtrim($url, '/')];
        $li->setAttribute('data-resource-slug', $entry['slug']);
        if ($entry['icon']) {
            $li->setAttribute('data-resource-icon', $entry['icon']);
        }
        if (isset($cards[$entry['slug']])) {
            $duplicates[$entry['slug']] = true;
        }
        $cards[$entry['slug']] = ['node' => $li, 'url' => $url];
    }
    foreach ($page['groups'] ?? [] as $parent => $links) {
        if (
            !isset($cards[$parent]) ||
            isset($duplicates[$parent]) ||
            !pagenest_companion_content_contains($root, $cards[$parent]['node'])
        ) {
            continue;
        }
        $related = $dom->createElement('div');
        $related->setAttribute('class', 'pagenest-resource-related');
        $related->setAttribute('data-pagenest-related', '1');
        $related->setAttribute('hidden', '');
        foreach ($links as $link) {
            $child = $link['slug'];
            if (
                !isset($cards[$child]) ||
                isset($duplicates[$child]) ||
                !pagenest_companion_content_contains($root, $cards[$child]['node'])
            ) {
                continue;
            }
            $a = $dom->createElement('a');
            $a->setAttribute('href', $cards[$child]['url']);
            $a->appendChild($dom->createTextNode($link['label']));
            $related->appendChild($a);
            $cards[$child]['node']->setAttribute('data-resource-parent', (string) $parent);
        }
        if ($related->hasChildNodes()) {
            $cards[$parent]['node']->appendChild($related);
        }
    }
}
function pagenest_companion_content_render(string $html, int $id): string
{
    $config = pagenest_companion_content_page($id);
    if (!$config) {
        return $html;
    }
    if (!pagenest_companion_content_readable($id, $config)) {
        return '';
    }
    $page = $config['page'];
    if (
        isset($page['targets_meta']) &&
        !is_array(get_post_meta($id, $page['targets_meta'], true))
    ) {
        return '';
    }
    [$dom, $xp, $root] = pagenest_companion_content_dom($html);
    if (!$root) {
        return '';
    }
    // REST may surround our previous projection with a paragraph/provider wrapper.
    // Unwrap only our own module wherever it occurs, preserving the other identities.
    foreach (
        array_reverse(
            iterator_to_array(
                $xp->query('.//*[@data-pagenest-content="' . $config['name'] . '"]', $root),
            ),
        )
        as $wrapper
    ) {
        while ($wrapper->firstChild) {
            $wrapper->parentNode->insertBefore($wrapper->firstChild, $wrapper);
        }
        $wrapper->parentNode->removeChild($wrapper);
    }
    foreach (iterator_to_array($xp->query('.//*[@data-pagenest-reading-nav]', $root)) as $nav) {
        $nav->parentNode->removeChild($nav);
    }
    if (isset($page['targets_meta'])) {
        pagenest_companion_content_project(
            $dom,
            $xp,
            $root,
            $page,
            $id,
            $config['name'] === 'resources',
        );
    }
    $found = pagenest_companion_content_sections_apply(
        $dom,
        $xp,
        $root,
        $page['sections'] ?? [],
        $config['name'] === 'resources',
    );
    if ($config['name'] === 'reading_links' && $found) {
        $nav = $dom->createElement('nav');
        $nav->setAttribute('class', 'pagenest-content-nav');
        $nav->setAttribute('data-pagenest-reading-nav', '1');
        $nav->setAttribute('aria-label', $config['labels']['navigation'] ?? 'Reading links');
        foreach ($found as $section) {
            $a = $dom->createElement('a');
            $a->setAttribute('href', '#' . rawurlencode($section['anchor']));
            $a->appendChild($dom->createTextNode($section['label']));
            $nav->appendChild($a);
        }
        $root->insertBefore($nav, $root->firstChild);
    }
    $data = ['sections' => $page['sections'] ?? [], 'labels' => $config['labels']];
    return '<div class="pagenest-content-' .
        $config['name'] .
        (!empty($page['table_scroll']) ? ' pagenest-content-table-scroll' : '') .
        '" data-pagenest-content="' .
        $config['name'] .
        '" data-pagenest-options="' .
        esc_attr(wp_json_encode($data)) .
        '">' .
        pagenest_companion_content_inner($dom, $root) .
        '</div>';
}
function pagenest_companion_content_assets(bool $javascript = true): void
{
    $manifest = require dirname(__DIR__, 2) . '/assets/manifest.php';
    wp_enqueue_style(
        'pagenest-companion-content',
        plugins_url(
            'assets/' . $manifest['content_css'],
            dirname(__DIR__, 2) . '/pagenest-compatibility.php',
        ),
        [],
        null,
    );
    if ($javascript) {
        wp_enqueue_script(
            'pagenest-companion-content',
            plugins_url(
                'assets/' . $manifest['content_js'],
                dirname(__DIR__, 2) . '/pagenest-compatibility.php',
            ),
            [],
            null,
            true,
        );
    }
}
add_action('wp_enqueue_scripts', static function (): void {
    $id = (int) get_queried_object_id();
    $config = pagenest_companion_content_page($id);
    if (
        is_singular(['post', 'page']) &&
        $config &&
        pagenest_companion_content_readable($id, $config)
    ) {
        pagenest_companion_content_assets();
    }
});
add_action('template_redirect', static function (): void {
    $id = (int) get_queried_object_id();
    $config = pagenest_companion_content_page($id);
    if (
        !is_singular(['post', 'page']) ||
        !$config ||
        !in_array($config['name'], ['resources', 'guide'], true)
    ) {
        return;
    }
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    nocache_headers();
    header('Cache-Control: private, no-store, max-age=0');
    if (!pagenest_companion_content_readable($id, $config)) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
    }
});
add_filter(
    'wp_insert_post_data',
    static function ($data, $postarr) {
        $id = (int) ($postarr['ID'] ?? 0);
        $config = pagenest_companion_content_page($id);
        if (
            $config &&
            $config['name'] === 'resources' &&
            get_post_meta($id, $config['page']['release_meta'], true) !== '1' &&
            !in_array($data['post_status'], ['draft', 'trash', 'auto-draft'], true)
        ) {
            $data['post_status'] = 'draft';
        }
        return $data;
    },
    99,
    2,
);
add_filter(
    'the_content',
    static fn($html) => pagenest_companion_content_render($html, (int) get_the_ID()),
    20,
);
foreach (['post', 'page'] as $type) {
    add_filter(
        'rest_prepare_' . $type,
        static function ($response, $post) {
            if (isset($response->data['content']['rendered'])) {
                $config = pagenest_companion_content_page((int) $post->ID);
                if ($config && in_array($config['name'], ['resources', 'guide'], true)) {
                    if (!defined('DONOTCACHEPAGE')) {
                        define('DONOTCACHEPAGE', true);
                    }
                    $response->header('Cache-Control', 'private, no-store, max-age=0');
                }
                $response->data['content']['rendered'] = pagenest_companion_content_render(
                    $response->data['content']['rendered'],
                    (int) $post->ID,
                );
            }
            return $response;
        },
        20,
        2,
    );
}
add_filter(
    'get_the_excerpt',
    static function ($excerpt, $post) {
        $config = pagenest_companion_content_page((int) $post->ID);
        if (!$config) {
            return $excerpt;
        }
        if (!pagenest_companion_content_readable((int) $post->ID, $config)) {
            return '';
        }
        return $config['name'] === 'resources'
            ? $config['page']['excerpt']
            : wp_strip_all_tags(
                pagenest_companion_content_render((string) $excerpt, (int) $post->ID),
            );
    },
    20,
    2,
);
