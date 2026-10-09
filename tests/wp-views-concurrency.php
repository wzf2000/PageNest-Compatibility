<?php
/** Real WordPress workers; only an explicitly owned, local synthetic lab is accepted. */
$path = getenv('PAGENEST_VIEWS_TEST_STATE');
$lab = $path ? realpath(dirname($path)) : false;
if (
    wp_get_environment_type() !== 'local' ||
    !$lab ||
    dirname($lab) !== '/tmp' ||
    (json_decode(file_get_contents($lab . '/state.json'), true)['owner'] ?? '') !==
        'component-migration'
) {
    throw new RuntimeException('Explicit owned local lab required.');
}
$mode = $args[0] ?? '';
$key = '_pagenest_companion_views';
$legacy = '_fixture_views_legacy';
$GLOBALS['pagenest_companion_profile']['views'] = [
    'legacy_meta' => $legacy,
    'legacy_owner_function' => '',
];
if ($mode === 'prepare') {
    if (file_exists($path)) {
        throw new RuntimeException('Existing view fixture refused.');
    }
    $before = get_option('pagenest_companion_views_settings', null);
    $post = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => 'Synthetic concurrent views',
    ]);
    if (!$post || is_wp_error($post)) {
        throw new RuntimeException('Could not prepare view fixture.');
    }
    add_post_meta($post, $legacy, '40', true);
    update_option('pagenest_companion_views_settings', [
        'enabled' => 1,
        'count' => 0,
        'exclude_bots' => 0,
        'template' => '%VIEW_COUNT%',
    ]);
    file_put_contents($path, wp_json_encode(compact('post', 'before')));
    echo "{}\n";
    return;
}
$state = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
if ($mode === 'increment') {
    $value = pagenest_companion_views_increment((int) $state['post']);
    if (is_wp_error($value)) {
        throw new RuntimeException($value->get_error_message());
    }
    echo wp_json_encode(['count' => $value]) . "\n";
} elseif ($mode === 'inspect') {
    $rows = pagenest_companion_views_rows((int) $state['post'], $key);
    echo wp_json_encode([
        'count' => pagenest_companion_views_get((int) $state['post']),
        'rows' => count($rows),
        'source' => get_post_meta($state['post'], $legacy, true),
    ]) . "\n";
} elseif ($mode === 'cleanup') {
    wp_delete_post((int) $state['post'], true);
    if ($state['before'] === null) {
        delete_option('pagenest_companion_views_settings');
    } else {
        update_option('pagenest_companion_views_settings', $state['before']);
    }
    unlink($path);
    echo "{}\n";
} else {
    throw new RuntimeException('Unknown worker mode.');
}
