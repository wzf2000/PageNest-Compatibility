<?php
/** Neutral media configuration; reading never migrates or updates old data. */
defined('ABSPATH') || exit();

function pagenest_companion_media_defaults(): array
{
    return [
        'attachment_ids' => [],
        'layout' => 'slider',
        'columns' => 3,
        'gap' => 15,
        'size' => 'large',
        'target_size' => 'large',
        'link' => 'file',
        'orderby' => 'random',
        'order' => 'ASC',
        'autoplay' => true,
        'interval' => 2000,
        'lightbox' => true,
    ];
}

function pagenest_companion_media_ids($value): array
{
    if (!is_array($value) || count($value) > 10000) {
        throw new InvalidArgumentException('Media identities must be an array.');
    }
    $ids = [];
    foreach ($value as $id) {
        if (!is_int($id) || $id < 1) {
            throw new InvalidArgumentException('Media identities must be positive integers.');
        }
        $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

function pagenest_companion_media_validate($input): array
{
    if (is_object($input)) {
        $input = get_object_vars($input);
    }
    if (
        !is_array($input) ||
        ($input['schema_version'] ?? null) !== 1 ||
        !is_bool($input['enabled'] ?? null) ||
        array_diff(array_keys($input), [
            'schema_version',
            'enabled',
            'galleries',
            'library_taxonomy',
        ])
    ) {
        throw new InvalidArgumentException('Invalid media configuration header.');
    }
    $galleries = $input['galleries'] ?? [];
    if (is_object($galleries)) {
        $galleries = get_object_vars($galleries);
    }
    if (!is_array($galleries) || count($galleries) > 1000) {
        throw new InvalidArgumentException('Invalid media galleries.');
    }
    $taxonomy = $input['library_taxonomy'] ?? '';
    if (
        !is_string($taxonomy) ||
        ($taxonomy !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $taxonomy))
    ) {
        throw new InvalidArgumentException('Invalid library taxonomy.');
    }
    $result = [
        'schema_version' => 1,
        'enabled' => $input['enabled'],
        'galleries' => [],
        'library_taxonomy' => $taxonomy,
    ];
    foreach ($galleries as $id => $gallery) {
        if (is_object($gallery)) {
            $gallery = get_object_vars($gallery);
        }
        if (
            !preg_match('/^[1-9][0-9]*$/D', (string) $id) ||
            (int) $id < 1 ||
            (string) (int) $id !== (string) $id ||
            !is_array($gallery)
        ) {
            throw new InvalidArgumentException('Invalid gallery identity.');
        }
        $allowed = array_merge(array_keys(pagenest_companion_media_defaults()), ['source']);
        if (array_diff(array_keys($gallery), $allowed)) {
            throw new InvalidArgumentException('Unknown gallery configuration field.');
        }
        $config = array_merge(pagenest_companion_media_defaults(), $gallery);
        $config['attachment_ids'] = pagenest_companion_media_ids($config['attachment_ids']);
        foreach (
            ['columns' => [1, 12], 'gap' => [0, 80], 'interval' => [1000, 60000]]
            as $key => $range
        ) {
            if (!is_int($config[$key]) || $config[$key] < $range[0] || $config[$key] > $range[1]) {
                throw new InvalidArgumentException('Invalid gallery numeric setting.');
            }
        }
        foreach (['autoplay', 'lightbox'] as $key) {
            if (!is_bool($config[$key])) {
                throw new InvalidArgumentException('Invalid gallery boolean setting.');
            }
        }
        foreach (
            [
                'layout' => ['slider', 'grid', 'masonry'],
                'link' => ['file', 'post', 'none'],
                'orderby' => ['random', 'ids', 'date', 'title', 'id'],
                'order' => ['ASC', 'DESC'],
            ]
            as $key => $values
        ) {
            if (!in_array($config[$key], $values, true)) {
                throw new InvalidArgumentException('Invalid gallery display setting.');
            }
        }
        foreach (['size', 'target_size'] as $key) {
            if (
                !is_string($config[$key]) ||
                !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $config[$key])
            ) {
                throw new InvalidArgumentException('Invalid image size.');
            }
        }
        if (array_key_exists('source', $config)) {
            $source = is_object($config['source'])
                ? get_object_vars($config['source'])
                : $config['source'];
            if (
                !is_array($source) ||
                array_diff(array_keys($source), ['taxonomy', 'term_ids', 'include_children']) ||
                !is_string($source['taxonomy'] ?? null) ||
                !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $source['taxonomy']) ||
                !is_bool($source['include_children'] ?? false)
            ) {
                throw new InvalidArgumentException('Invalid gallery source.');
            }
            $config['source'] = [
                'taxonomy' => $source['taxonomy'],
                'term_ids' => pagenest_companion_media_ids($source['term_ids'] ?? []),
                'include_children' => $source['include_children'] ?? false,
            ];
        }
        $result['galleries'][(int) $id] = $config;
    }
    return $result;
}

function pagenest_companion_media_config(): array
{
    try {
        return pagenest_companion_media_validate(
            get_option('pagenest_companion_media', [
                'schema_version' => 1,
                'enabled' => false,
                'galleries' => [],
            ]),
        );
    } catch (InvalidArgumentException $error) {
        return ['schema_version' => 1, 'enabled' => false, 'galleries' => []];
    }
}
