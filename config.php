<?php
/** Explicit, server-owned compatibility configuration; no auto-discovery. */
defined('ABSPATH') || exit();
function pagenest_companion_defaults()
{
    return [
        'schema_version' => 1,
        'experience' => [
            'table_suffix' => 'reader_experience_events',
            'live_option' => 'reader_experience_live',
            'rank_option' => 'reader_experience_rank_ids',
            'week_option' => 'reader_experience_week_rotated',
            'event_lock' => 'reader_experience_events',
            'weekly_lock' => 'reader_experience_scoring',
            'rest_namespace' => 'reader-experience/v1',
            'rest_aliases' => [],
            'shortcodes' => ['reader_experience'],
            'panel_page_id' => 0,
            'weekly_hook' => 'wp_weekly_function_hook',
        ],
        'theme' => [
            'legacy_stylesheet' => '',
            'setting_prefix' => '',
            'menu_locations' => [],
            'anchor_prefixes' => [],
            'class_aliases' => [],
        ],
        'mail' => ['sent_meta_keys' => [], 'lock_option_prefixes' => []],
        'paragraphs' => [
            'post_type' => 'pagenest_note',
            'data_meta' => '_pagenest_note_data',
            'uuid_meta' => '_pagenest_note_uuid',
            'document_meta' => '_pagenest_document_id',
            'registry_meta' => '_pagenest_block_registry',
            'library_meta' => '_pagenest_library_index',
            'enabled_meta' => '_pagenest_comments_enabled',
            'rest_namespace' => 'pagenest-comments/v1',
            'rest_aliases' => [],
            'shortcodes' => ['pagenest_library'],
        ],
        'chapters' => [
            'series_meta' => '_pagenest_series',
            'order_meta' => '_pagenest_chapter_order',
            'document_meta' => '_pagenest_document_id',
            'link_map' => [],
            'series_order' => [],
        ],
        'likes' => [
            'counter_meta' => 'bigfa_ding',
            'record_meta' => '_pagenest_like_users',
            'legacy_provider' => [
                'table_suffix' => '',
                'user_column' => 'user_id',
                'post_column' => '',
                'event_column' => '',
                'event_value' => '',
                'record_column' => 'event_key',
                'record_prefix' => 'like:',
            ],
        ],
    ];
}
function pagenest_companion_validate($input)
{
    $defaults = pagenest_companion_defaults();
    if (
        !is_array($input) ||
        ($input['schema_version'] ?? null) !== 1 ||
        array_diff(array_keys($input), array_keys($defaults))
    ) {
        throw new InvalidArgumentException('Invalid profile schema.');
    }
    foreach ($input as $section => $values) {
        if ($section === 'schema_version') {
            continue;
        }
        if (
            !is_array($values) ||
            array_diff(array_keys($values), array_keys($defaults[$section]))
        ) {
            throw new InvalidArgumentException('Unknown profile section key.');
        }
        foreach ($values as $key => $value) {
            if (is_string($defaults[$section][$key])) {
                if (
                    !is_string($value) ||
                    strlen($value) > 200 ||
                    preg_match('/[\x00-\x1f]/', $value)
                ) {
                    throw new InvalidArgumentException('Invalid profile string.');
                }
            } elseif (is_int($defaults[$section][$key])) {
                if (!is_int($value) || $value < 0) {
                    throw new InvalidArgumentException('Invalid profile integer.');
                }
            } elseif (!is_array($value)) {
                throw new InvalidArgumentException('Invalid profile collection.');
            }
        }
        $defaults[$section] = array_replace($defaults[$section], $values);
    }
    foreach (
        [
            'table_suffix',
            'live_option',
            'rank_option',
            'week_option',
            'event_lock',
            'weekly_lock',
            'weekly_hook',
        ]
        as $key
    ) {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $defaults['experience'][$key])) {
            throw new InvalidArgumentException('Invalid experience identifier.');
        }
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $defaults['experience']['table_suffix'])) {
        throw new InvalidArgumentException('Invalid experience table.');
    }
    foreach (['rest_aliases', 'shortcodes'] as $key) {
        if (array_values($defaults['experience'][$key]) !== $defaults['experience'][$key]) {
            throw new InvalidArgumentException('Expected experience list.');
        }
    }
    foreach (
        array_merge(
            [$defaults['experience']['rest_namespace']],
            $defaults['experience']['rest_aliases'],
        )
        as $namespace
    ) {
        if (
            !is_string($namespace) ||
            !preg_match('/^[a-zA-Z0-9_-]+\/v[1-9][0-9]*$/D', $namespace)
        ) {
            throw new InvalidArgumentException('Invalid experience namespace.');
        }
    }
    foreach ($defaults['experience']['shortcodes'] as $name) {
        if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $name)) {
            throw new InvalidArgumentException('Invalid experience shortcode.');
        }
    }
    $ids = [
        'post_type',
        'data_meta',
        'uuid_meta',
        'document_meta',
        'registry_meta',
        'library_meta',
        'enabled_meta',
    ];
    foreach ($ids as $key) {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $defaults['paragraphs'][$key])) {
            throw new InvalidArgumentException('Invalid storage identifier.');
        }
    }
    if (strlen($defaults['paragraphs']['post_type']) > 20) {
        throw new InvalidArgumentException('Post type exceeds WordPress limit.');
    }
    foreach (['series_meta', 'order_meta', 'document_meta'] as $key) {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $defaults['chapters'][$key])) {
            throw new InvalidArgumentException('Invalid chapter identifier.');
        }
    }
    foreach (['counter_meta', 'record_meta'] as $key) {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $defaults['likes'][$key])) {
            throw new InvalidArgumentException('Invalid like identifier.');
        }
    }
    foreach (
        array_merge(
            [$defaults['paragraphs']['rest_namespace']],
            $defaults['paragraphs']['rest_aliases'],
        )
        as $namespace
    ) {
        if (
            !is_string($namespace) ||
            !preg_match('/^[a-zA-Z0-9_-]+\/v[1-9][0-9]*$/D', $namespace)
        ) {
            throw new InvalidArgumentException('Invalid REST namespace.');
        }
    }
    foreach (['shortcodes', 'rest_aliases'] as $key) {
        if (array_values($defaults['paragraphs'][$key]) !== $defaults['paragraphs'][$key]) {
            throw new InvalidArgumentException('Expected list.');
        }
    }
    foreach ($defaults['paragraphs']['shortcodes'] as $name) {
        if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $name)) {
            throw new InvalidArgumentException('Invalid shortcode.');
        }
    }
    foreach (['sent_meta_keys', 'lock_option_prefixes'] as $key) {
        foreach ($defaults['mail'][$key] as $name) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $name)) {
                throw new InvalidArgumentException('Invalid mail identifier.');
            }
        }
    }
    foreach (['menu_locations', 'anchor_prefixes', 'class_aliases'] as $key) {
        foreach ($defaults['theme'][$key] as $from => $to) {
            if (
                !is_string($from) ||
                !is_string($to) ||
                !preg_match('/^[a-zA-Z0-9_-]+$/D', $from) ||
                !preg_match('/^[a-zA-Z0-9_-]+$/D', $to)
            ) {
                throw new InvalidArgumentException('Invalid theme alias.');
            }
        }
    }
    foreach ($defaults['chapters']['link_map'] as $file => $post_id) {
        if (
            !is_string($file) ||
            !preg_match('/^[a-zA-Z0-9_.-]+\.md$/D', $file) ||
            !is_int($post_id) ||
            $post_id < 1
        ) {
            throw new InvalidArgumentException('Invalid chapter link.');
        }
    }
    foreach ($defaults['chapters']['series_order'] as $id) {
        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException('Invalid chapter order.');
        }
    }
    $provider = $defaults['likes']['legacy_provider'];
    $base = pagenest_companion_defaults()['likes']['legacy_provider'];
    if (array_diff(array_keys($provider), array_keys($base))) {
        throw new InvalidArgumentException('Unknown legacy provider key.');
    }
    $provider = array_replace($base, $provider);
    foreach ($provider as $key => $value) {
        if (!is_string($value) || strlen($value) > 200) {
            throw new InvalidArgumentException('Invalid legacy provider.');
        }
        if (
            !in_array($key, ['record_prefix', 'event_value'], true) &&
            $value !== '' &&
            !preg_match('/^[a-zA-Z0-9_]+$/D', $value)
        ) {
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }
    }
    if (
        $provider['table_suffix'] !== '' &&
        ($provider['user_column'] === '' ||
            ($provider['post_column'] === '' && $provider['record_column'] === ''))
    ) {
        throw new InvalidArgumentException('Incomplete legacy provider.');
    }
    if (($provider['event_column'] === '') !== ($provider['event_value'] === '')) {
        throw new InvalidArgumentException('Incomplete event rule.');
    }
    $defaults['likes']['legacy_provider'] = $provider;
    return $defaults;
}
function pagenest_companion_load_profile($path, $web_root)
{
    $real = realpath($path);
    $root = realpath($web_root);
    if (
        !$real ||
        !$root ||
        !is_file($real) ||
        !is_readable($real) ||
        $real === $root ||
        str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) ||
        filesize($real) > 1048576
    ) {
        throw new InvalidArgumentException('Profile must be readable outside the web root.');
    }
    $input = json_decode(file_get_contents($real), true, 32, JSON_THROW_ON_ERROR);
    return pagenest_companion_validate($input);
}
function pagenest_companion_config($section, $key = null)
{
    $config = $GLOBALS['pagenest_companion_profile'] ?? pagenest_companion_defaults();
    return $key === null ? $config[$section] : $config[$section][$key];
}
