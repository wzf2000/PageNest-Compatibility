<?php
/** Two-process single-use and registration races in an owned synthetic WordPress only. */
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
        throw new RuntimeException('Existing fixture refused.');
    }
    $prefix = 'security_race_' . bin2hex(random_bytes(6));
    $user = wp_insert_user([
        'user_login' => $prefix,
        'user_pass' => wp_generate_password(40),
        'user_email' => $prefix . '@example.test',
        'role' => 'subscriber',
    ]);
    if (is_wp_error($user)) {
        throw new RuntimeException('Fixture preparation failed.');
    }
    $token = bin2hex(random_bytes(32));
    update_user_meta($user, '_pagenest_social_email_challenge', [
        'token' => hash('sha256', $token),
        'email' => hash('sha256', get_userdata($user)->user_email),
        'expires' => time() + 600,
    ]);
    $pending = bin2hex(random_bytes(32));
    $pending_key = 'pn_social_pending_' . hash('sha256', $pending);
    $record = [
        'expires' => time() + 600,
        'browser' => hash('sha256', 'fixture-browser'),
        'provider' => 'qq',
        'app' => $prefix,
        'identity' => ['subject' => $prefix, 'name' => 'Synthetic'],
        'return' => home_url('/'),
    ];
    set_transient($pending_key, $record, 600);
    $original_registration = get_option('users_can_register');
    update_option('users_can_register', 1);
    file_put_contents(
        $path,
        wp_json_encode(
            compact('prefix', 'user', 'token', 'pending', 'pending_key', 'original_registration'),
        ),
    );
    chmod($path, 0600);
    echo "{}\n";
    return;
}
$data = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
$config = [
    'enabled' => true,
    'client_id' => $data['prefix'],
    'client_secret' => 'synthetic',
    'redirect_uri' => 'https://example.test/callback/',
];
$GLOBALS['pagenest_companion_services_profile']['social'] = [
    'enabled' => true,
    'allow_registration' => true,
    'legacy_apps' => [],
    'providers' => ['qq' => $config],
];
$key = pagenest_social_identity_key('qq', $data['prefix'], $data['prefix']);
if (
    in_array($mode, ['register', 'register-username', 'register-email', 'email', 'pending'], true)
) {
    try {
        if ($mode === 'register') {
            pagenest_social_login_identity(
                'qq',
                $config,
                ['subject' => $data['prefix'], 'name' => 'Synthetic'],
                true,
                [
                    'user_login' => $data['prefix'] . '_new',
                    'user_email' => $data['prefix'] . '_new@example.test',
                ],
            );
        } elseif (in_array($mode, ['register-username', 'register-email'], true)) {
            $suffix = bin2hex(random_bytes(5));
            // Different identity in each process: these races must be stopped
            // by the username/email locks, not the provider identity lock.
            pagenest_social_login_identity(
                'qq',
                $config,
                [
                    'subject' => $data['prefix'] . '_' . $mode . '_' . $suffix,
                    'name' => 'Synthetic',
                ],
                true,
                [
                    'user_login' =>
                        $data['prefix'] .
                        ($mode === 'register-username' ? '_shared_login' : '_e_' . $suffix),
                    'user_email' =>
                        $data['prefix'] .
                        ($mode === 'register-email' ? '_shared' : '_n_' . $suffix) .
                        '@example.test',
                ],
            );
        } elseif ($mode === 'email') {
            wp_set_current_user($data['user']);
            pagenest_social_email_confirm($data['user'], $data['token']);
        } else {
            wp_set_current_user(0);
            pagenest_social_pending_consume($data['pending'], 'fixture-browser');
        }
        echo "{\"result\":\"accepted\"}\n";
    } catch (RuntimeException $error) {
        echo "{\"result\":\"refused\"}\n";
    }
} elseif ($mode === 'inspect') {
    global $wpdb;
    $owner = pagenest_social_owner($key);
    if (
        !is_array($owner) ||
        !$owner['user_id'] ||
        !get_userdata($owner['user_id']) ||
        count(get_user_meta($owner['user_id'], '_pagenest_social_bindings', true)) !== 1 ||
        !pagenest_social_email_verified($data['user']) ||
        get_user_meta($data['user'], '_pagenest_social_email_challenge', true) ||
        get_transient($data['pending_key'])
    ) {
        throw new RuntimeException('Concurrent security postcondition failed.');
    }
    foreach (
        [
            ['user_login', $data['prefix'] . '_shared_login'],
            ['user_email', $data['prefix'] . '_shared@example.test'],
        ]
        as [$field, $value]
    ) {
        $ids = $wpdb->get_col(
            $wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE {$field} = %s", $value),
        );
        if (count($ids) !== 1) {
            throw new RuntimeException('Registration field uniqueness failed.');
        }
        $bindings = get_user_meta((int) $ids[0], '_pagenest_social_bindings', true);
        if (
            count($bindings) !== 1 ||
            pagenest_social_owner(array_key_first($bindings))['user_id'] !== (int) $ids[0]
        ) {
            throw new RuntimeException('Registration field owner failed.');
        }
    }
    echo "{\"registration_owners\":1,\"shared_username_owners\":1,\"shared_email_owners\":1,\"email_verified\":true,\"pending_consumed\":true}\n";
} elseif ($mode === 'cleanup') {
    $owner = pagenest_social_owner($key);
    delete_option($key);
    delete_transient($data['pending_key']);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    if (is_array($owner) && $owner['user_id']) {
        wp_delete_user($owner['user_id']);
    }
    foreach (
        get_users([
            'search' => $data['prefix'] . '_*',
            'search_columns' => ['user_login'],
            'fields' => 'ID',
        ])
        as $id
    ) {
        // Only users bearing this fixture's random prefix are owned here.
        if (str_starts_with(get_userdata($id)->user_login, $data['prefix'] . '_')) {
            wp_delete_user($id);
        }
    }
    wp_delete_user($data['user']);
    update_option('users_can_register', $data['original_registration']);
    unlink($path);
    echo "{}\n";
} else {
    throw new RuntimeException('Unknown fixture mode.');
}
