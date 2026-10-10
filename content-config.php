<?php
/** Independent, optional content profile. Each module fails closed on its own. */
defined('ABSPATH') || exit();
function pagenest_companion_content_list($value, int $limit = 256): bool
{
    return is_array($value) &&
        count($value) <= $limit &&
        array_keys($value) === ($value ? range(0, count($value) - 1) : []);
}
function pagenest_companion_content_keys(array $value, array $allowed): void
{
    if (array_diff(array_keys($value), $allowed)) {
        throw new InvalidArgumentException('Unexpected field');
    }
}
function pagenest_companion_content_text($value, int $limit = 500): bool
{
    return is_string($value) &&
        strlen($value) <= $limit &&
        trim($value) === $value &&
        $value !== '' &&
        !preg_match('/[<>\x00-\x1f\x7f]/u', $value);
}
function pagenest_companion_content_identifier($value): bool
{
    return is_string($value) && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]{0,190}$/D', $value);
}
function pagenest_companion_content_url($value): bool
{
    if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\s\\\\\x00-\x1f]/', $value)) {
        return false;
    }
    $parts = parse_url($value);
    return $parts &&
        ($parts['scheme'] ?? '') === 'https' &&
        !empty($parts['host']) &&
        !isset($parts['user'], $parts['pass']) &&
        !isset($parts['user']) &&
        !isset($parts['pass']);
}
function pagenest_companion_content_heading($heading): void
{
    if (!is_array($heading)) {
        throw new InvalidArgumentException('Heading');
    }
    pagenest_companion_content_keys($heading, ['text', 'id', 'level']);
    if (
        !is_int($heading['level'] ?? null) ||
        $heading['level'] < 1 ||
        $heading['level'] > 6 ||
        isset($heading['text']) === isset($heading['id']) ||
        (isset($heading['text']) && !pagenest_companion_content_text($heading['text'])) ||
        (isset($heading['id']) && !pagenest_companion_content_identifier($heading['id']))
    ) {
        throw new InvalidArgumentException('Heading selector');
    }
}
function pagenest_companion_content_sections($sections): void
{
    if (!pagenest_companion_content_list($sections, 64)) {
        throw new InvalidArgumentException('Sections');
    }
    $anchors = $selectors = [];
    foreach ($sections as $section) {
        if (!is_array($section)) {
            throw new InvalidArgumentException('Section');
        }
        pagenest_companion_content_keys($section, [
            'heading',
            'anchor',
            'label',
            'list_heading',
            'list_kind',
            'default_open',
            'start_list',
        ]);
        pagenest_companion_content_heading($section['heading'] ?? null);
        $selector =
            $section['heading']['level'] .
            ':' .
            (isset($section['heading']['id'])
                ? 'id:' . $section['heading']['id']
                : 'text:' . $section['heading']['text']);
        if (isset($selectors[$selector])) {
            throw new InvalidArgumentException('Duplicate heading');
        }
        $selectors[$selector] = true;
        if (isset($section['anchor'])) {
            if (
                !pagenest_companion_content_identifier($section['anchor']) ||
                isset($anchors[$section['anchor']])
            ) {
                throw new InvalidArgumentException('Section anchor');
            }
            $anchors[$section['anchor']] = true;
        }
        if (isset($section['list_heading'])) {
            pagenest_companion_content_heading($section['list_heading']);
        }
        if (
            (isset($section['label']) && !pagenest_companion_content_text($section['label'])) ||
            (isset($section['list_kind']) &&
                !in_array($section['list_kind'], ['ul', 'ol'], true)) ||
            (isset($section['default_open']) && !is_bool($section['default_open'])) ||
            (isset($section['start_list']) && !is_bool($section['start_list']))
        ) {
            throw new InvalidArgumentException('Section fields');
        }
    }
}
function pagenest_companion_content_term($term): void
{
    if (
        !is_array($term) ||
        count($term) !== 2 ||
        !in_array($term['taxonomy'] ?? null, ['category', 'post_tag'], true) ||
        !is_int($term['term_id'] ?? null) ||
        $term['term_id'] < 1
    ) {
        throw new InvalidArgumentException('Term');
    }
    pagenest_companion_content_keys($term, ['taxonomy', 'term_id']);
}
function pagenest_companion_content_validate_module(string $name, $module): array
{
    if (!is_array($module) || !is_bool($module['enabled'] ?? null)) {
        throw new InvalidArgumentException('Module');
    }
    $labelKeys = [
        'resources' => [
            'search',
            'placeholder',
            'category',
            'all',
            'reset',
            'empty',
            'count',
            'filtered_count',
            'related',
            'show_related',
            'hide_related',
            'matched_related',
            'group_hint',
        ],
        'guide' => ['controls', 'overview', 'expand', 'list', 'closed', 'opened', 'partial'],
        'reading_links' => ['navigation'],
        'archives' => ['navigation', 'groups', 'starts'],
        'chapter_display' => ['navigation', 'return'],
    ][$name];
    $fields = ['enabled', 'labels'];
    if (in_array($name, ['resources', 'guide', 'reading_links'], true)) {
        $fields[] = 'pages';
    } elseif ($name === 'archives') {
        $fields[] = 'items';
    } else {
        $fields = array_merge($fields, [
            'document_meta',
            'heading_aliases_meta',
            'library_meta',
            'hide_first_title',
        ]);
    }
    pagenest_companion_content_keys($module, $fields);
    if (isset($module['labels'])) {
        if (!is_array($module['labels'])) {
            throw new InvalidArgumentException('Labels');
        }
        pagenest_companion_content_keys($module['labels'], $labelKeys);
        foreach ($module['labels'] as $label) {
            if (!pagenest_companion_content_text($label)) {
                throw new InvalidArgumentException('Label');
            }
        }
    }
    if (!$module['enabled']) {
        return ['enabled' => false];
    }
    if (in_array($name, ['resources', 'guide', 'reading_links'], true)) {
        if (!pagenest_companion_content_list($module['pages'] ?? null, 32) || !$module['pages']) {
            throw new InvalidArgumentException('Pages');
        }
        $selectors = [];
        foreach ($module['pages'] as $page) {
            if (!is_array($page)) {
                throw new InvalidArgumentException('Page');
            }
            $allowed = ['selector', 'targets_meta', 'sections'];
            if ($name !== 'reading_links') {
                $allowed[] = 'external_links';
            }
            if ($name === 'resources') {
                $allowed = array_merge($allowed, [
                    'release_meta',
                    'excerpt',
                    'icons',
                    'groups',
                    'provider',
                ]);
            }
            if ($name === 'reading_links') {
                $allowed[] = 'table_scroll';
            }
            pagenest_companion_content_keys($page, $allowed);
            $selector = $page['selector'] ?? null;
            if (
                !is_array($selector) ||
                count($selector) !== 2 ||
                !pagenest_companion_content_identifier($selector['meta'] ?? null) ||
                !pagenest_companion_content_text($selector['value'] ?? null, 191)
            ) {
                throw new InvalidArgumentException('Page selector');
            }
            pagenest_companion_content_keys($selector, ['meta', 'value']);
            $identity = $selector['meta'] . ':' . $selector['value'];
            if (isset($selectors[$identity])) {
                throw new InvalidArgumentException('Duplicate page');
            }
            $selectors[$identity] = true;
            if (
                ($name !== 'reading_links' || isset($page['targets_meta'])) &&
                !pagenest_companion_content_identifier($page['targets_meta'] ?? null)
            ) {
                throw new InvalidArgumentException('Targets metadata');
            }
            pagenest_companion_content_sections($page['sections'] ?? []);
            if (isset($page['external_links'])) {
                if (!pagenest_companion_content_list($page['external_links'])) {
                    throw new InvalidArgumentException('External list');
                }
                foreach ($page['external_links'] as $url) {
                    if (!pagenest_companion_content_url($url)) {
                        throw new InvalidArgumentException('External URL');
                    }
                }
            }
            if ($name === 'resources') {
                if (
                    !pagenest_companion_content_identifier($page['release_meta'] ?? null) ||
                    !pagenest_companion_content_text($page['excerpt'] ?? null) ||
                    !in_array($page['provider'] ?? 'none', ['none', 'site_tools'], true)
                ) {
                    throw new InvalidArgumentException('Resource fields');
                }
                $icons = [
                    'dice',
                    'text',
                    'dinosaur',
                    'tiles',
                    'fibonacci',
                    'graduate',
                    'medal',
                    'dinosaur-medal',
                    'tiles-calendar',
                    'tiles-trophy',
                    'fibonacci-calendar',
                    'fibonacci-trophy',
                    'graduate-trophy',
                    'image',
                    'cloud',
                ];
                if (
                    !is_array($page['icons'] ?? []) ||
                    count($page['icons'] ?? []) > 256 ||
                    !is_array($page['groups'] ?? []) ||
                    count($page['groups'] ?? []) > 256
                ) {
                    throw new InvalidArgumentException('Resource maps');
                }
                foreach ($page['icons'] ?? [] as $slug => $icon) {
                    if (
                        !pagenest_companion_content_identifier((string) $slug) ||
                        !in_array($icon, $icons, true)
                    ) {
                        throw new InvalidArgumentException('Icon');
                    }
                }
                $children = [];
                foreach ($page['groups'] ?? [] as $parent => $links) {
                    if (
                        !pagenest_companion_content_identifier((string) $parent) ||
                        !pagenest_companion_content_list($links, 8) ||
                        !$links
                    ) {
                        throw new InvalidArgumentException('Resource group');
                    }
                    foreach ($links as $link) {
                        if (
                            !is_array($link) ||
                            count($link) !== 2 ||
                            !pagenest_companion_content_identifier($link['slug'] ?? null) ||
                            !pagenest_companion_content_text($link['label'] ?? null, 96) ||
                            $link['slug'] === (string) $parent ||
                            isset($children[$link['slug']])
                        ) {
                            throw new InvalidArgumentException('Related entry');
                        }
                        pagenest_companion_content_keys($link, ['slug', 'label']);
                        $children[$link['slug']] = true;
                    }
                }
                if (array_intersect_key($children, $page['groups'] ?? [])) {
                    throw new InvalidArgumentException('Nested group');
                }
            }
            if (isset($page['table_scroll']) && !is_bool($page['table_scroll'])) {
                throw new InvalidArgumentException('Table option');
            }
        }
    } elseif ($name === 'archives') {
        if (!pagenest_companion_content_list($module['items'] ?? null, 32)) {
            throw new InvalidArgumentException('Archives');
        }
        $identities = [];
        foreach ($module['items'] as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Archive item');
            }
            pagenest_companion_content_keys($item, [
                'taxonomy',
                'term_id',
                'intro',
                'groups',
                'start_posts',
            ]);
            pagenest_companion_content_term([
                'taxonomy' => $item['taxonomy'] ?? null,
                'term_id' => $item['term_id'] ?? null,
            ]);
            $identity = $item['taxonomy'] . ':' . $item['term_id'];
            if (
                isset($identities[$identity]) ||
                !pagenest_companion_content_text($item['intro'] ?? null, 1000) ||
                !pagenest_companion_content_list($item['groups'] ?? null, 20) ||
                !pagenest_companion_content_list($item['start_posts'] ?? null, 8)
            ) {
                throw new InvalidArgumentException('Archive fields');
            }
            $identities[$identity] = true;
            $groups = [];
            foreach ($item['groups'] as $term) {
                pagenest_companion_content_term($term);
                $key = $term['taxonomy'] . ':' . $term['term_id'];
                if ($key === $identity || isset($groups[$key])) {
                    throw new InvalidArgumentException('Duplicate archive group');
                }
                $groups[$key] = true;
            }
            foreach ($item['start_posts'] as $id) {
                if (!is_int($id) || $id < 1) {
                    throw new InvalidArgumentException('Archive post');
                }
            }
            if (count(array_unique($item['start_posts'])) !== count($item['start_posts'])) {
                throw new InvalidArgumentException('Duplicate archive post');
            }
        }
    } else {
        foreach (['document_meta', 'heading_aliases_meta', 'library_meta'] as $key) {
            if (!pagenest_companion_content_identifier($module[$key] ?? null)) {
                throw new InvalidArgumentException('Chapter metadata');
            }
        }
        if (isset($module['hide_first_title']) && !is_bool($module['hide_first_title'])) {
            throw new InvalidArgumentException('Chapter option');
        }
    }
    return $module;
}
function pagenest_companion_content_validate($data): array
{
    if (
        !is_array($data) ||
        ($data['schema_version'] ?? null) !== 1 ||
        !is_array($data['modules'] ?? null)
    ) {
        throw new InvalidArgumentException('Content profile');
    }
    pagenest_companion_content_keys($data, ['schema_version', 'modules']);
    pagenest_companion_content_keys($data['modules'], [
        'resources',
        'guide',
        'reading_links',
        'archives',
        'chapter_display',
    ]);
    $result = ['schema_version' => 1, 'modules' => [], 'invalid_modules' => []];
    foreach ($data['modules'] as $name => $module) {
        try {
            $result['modules'][$name] = pagenest_companion_content_validate_module($name, $module);
        } catch (Throwable $error) {
            $result['modules'][$name] = ['enabled' => false];
            $result['invalid_modules'][] = $name;
        }
    }
    // One page cannot have two competing body owners, irrespective of declaration order.
    $selectors = $conflicts = [];
    foreach (['resources', 'guide', 'reading_links'] as $name) {
        if (empty($result['modules'][$name]['enabled'])) {
            continue;
        }
        foreach ($result['modules'][$name]['pages'] as $page) {
            $identity = $page['selector']['meta'] . ':' . $page['selector']['value'];
            if (isset($selectors[$identity])) {
                $conflicts[$name] = $conflicts[$selectors[$identity]] = true;
            } else {
                $selectors[$identity] = $name;
            }
        }
    }
    foreach (array_keys($conflicts) as $name) {
        $result['modules'][$name] = ['enabled' => false];
        $result['invalid_modules'][] = $name;
    }
    return $result;
}
function pagenest_companion_load_content($file, string $webRoot): array
{
    $path = is_string($file) && !str_contains($file, '://') ? realpath($file) : false;
    $web = realpath($webRoot);
    if (
        !$path ||
        !$web ||
        $path === $web ||
        str_starts_with($path, rtrim($web, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) ||
        !is_file($path) ||
        !is_readable($path) ||
        filesize($path) > 1048576
    ) {
        throw new InvalidArgumentException('Content profile location');
    }
    return pagenest_companion_content_validate(
        json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR),
    );
}
/** Read-only, validated configuration; also usable by external maintenance tools. */
function pagenest_companion_content_config(string $key = '', $default = null)
{
    $value = $GLOBALS['pagenest_companion_content_profile'] ?? [
        'schema_version' => 1,
        'modules' => [],
    ];
    if ($key === '') {
        return $value;
    }
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}
