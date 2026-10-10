<?php
/** Synthetic real WordPress workers. Orchestrator must prepare an owned /tmp directory. */
$path = getenv('PAGENEST_SOCIAL_TEST_STATE');
$lab = $path ? realpath(dirname($path)) : false;
if (
    wp_get_environment_type() !== 'local' ||
    !$lab ||
    (dirname($lab) !== '/tmp' && $lab !== '/lab') ||
    !is_file($lab . '/social-owned') ||
    trim(file_get_contents($lab . '/social-owned')) !== 'pagenest-social-synthetic'
) {
    throw new RuntimeException('Owned synthetic social lab required.');
}
require_once dirname(__DIR__) . '/modules/social/bootstrap.php';
$mode = $args[0] ?? '';
if ($mode === 'prepare') {
    if (file_exists($path)) {
        throw new RuntimeException('Existing social fixture refused.');
    }
    $prefix = 'social_race_' . bin2hex(random_bytes(6));
    $users = [];
    foreach ([1, 2] as $suffix) {
        $id = wp_insert_user([
            'user_login' => $prefix . '_' . $suffix,
            'user_pass' => wp_generate_password(),
            'user_email' => $prefix . '_' . $suffix . '@fake.com',
            'role' => 'subscriber',
        ]);
        if (is_wp_error($id)) {
            throw new RuntimeException('Fixture preparation failed.');
        }
        $users[] = $id;
    }
    $key = pagenest_social_identity_key('qq', $prefix, 'shared-synthetic-subject');
    file_put_contents($path, wp_json_encode(compact('users', 'prefix', 'key')));
    echo "{}\n";
    return;
}
$state = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
if ($mode === 'bind') {
    $index = (int) ($args[1] ?? -1);
    if (!isset($state['users'][$index])) {
        throw new RuntimeException('Unknown fixture user.');
    }
    try {
        pagenest_social_bind(
            $state['users'][$index],
            'qq',
            $state['prefix'],
            'shared-synthetic-subject',
        );
        echo "{\"result\":\"bound\"}\n";
    } catch (RuntimeException $error) {
        echo "{\"result\":\"refused\"}\n";
    }
} elseif ($mode === 'inspect') {
    $owner = get_option($state['key'], null);
    $bindings = 0;
    foreach ($state['users'] as $id) {
        if (isset(get_user_meta($id, '_pagenest_social_bindings', true)[$state['key']])) {
            $bindings++;
        }
    }
    if (
        !is_array($owner) ||
        !in_array($owner['user_id'], $state['users'], true) ||
        $bindings !== 1
    ) {
        throw new RuntimeException('Concurrent identity uniqueness failed.');
    }
    echo "{\"owners\":1,\"bindings\":1}\n";
} elseif ($mode === 'cleanup') {
    delete_option($state['key']);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($state['users'] as $id) {
        wp_delete_user($id);
    }
    unlink($path);
    echo "{}\n";
} else {
    throw new RuntimeException('Unknown worker mode.');
}
