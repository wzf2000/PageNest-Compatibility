<?php
/** Synthetic workers exercise real database locks across separate WordPress processes. */
if (
    wp_get_environment_type() !== 'local' ||
    !getenv('COMPONENT_LAB_STATE') ||
    !getenv('COMPONENT_LAB_CONCURRENT')
) {
    throw new RuntimeException('Explicit synthetic lab required');
}
$fixture = json_decode(
    file_get_contents(getenv('COMPONENT_LAB_STATE')),
    true,
    32,
    JSON_THROW_ON_ERROR,
);
$path = getenv('COMPONENT_LAB_CONCURRENT');
$mode = getenv('COMPONENT_LAB_WORKER');
wp_set_current_user((int) $fixture['reader']);
if ($mode === 'prepare') {
    $post = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_author' => (int) $fixture['author'],
        'post_title' => 'Concurrent likes fixture',
    ]);
    $body = [
        'post_id' => (int) $fixture['post'],
        'block_id' => $fixture['registry'][0]['id'],
        'text' => 'Concurrent comment fixture',
        'uuid' => wp_generate_uuid4(),
        'public_confirmed' => true,
    ];
    file_put_contents($path, wp_json_encode(compact('post', 'body')));
    echo "{}\n";
    return;
}
$state = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
if ($mode === 'like') {
    $result = pagenest_companion_like((int) $fixture['reader'], (int) $state['post']);
} elseif ($mode === 'create') {
    $result = pagenest_pc_save($state['body'], false, true);
} elseif ($mode === 'edit') {
    $request = new WP_REST_Request('PATCH', '/');
    $request['id'] = $state['id'];
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(
        wp_json_encode([
            'version' => $state['version'],
            'text' => 'Worker ' . getenv('COMPONENT_LAB_WORKER_ID'),
        ]),
    );
    $result = pagenest_pc_mutate($request, false, true);
} elseif ($mode === 'inspect') {
    $result = [
        'count' => (int) get_post_meta(
            $state['post'],
            pagenest_companion_config('likes', 'counter_meta'),
            true,
        ),
    ];
} elseif ($mode === 'cleanup') {
    $note = get_post($state['id']);
    if ($note && pagenest_pc_own($note)) {
        wp_delete_post($note->ID, true);
    }
    $result = ['cleaned' => true];
} else {
    throw new RuntimeException('Unknown worker mode');
}
echo wp_json_encode(
    is_wp_error($result)
        ? [
            'error' => $result->get_error_code(),
            'status' => $result->get_error_data()['status'] ?? 500,
        ]
        : $result,
) . "\n";
