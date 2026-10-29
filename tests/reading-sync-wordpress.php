<?php
/** Native persistence/permission regressions for an explicitly disposable lab only. */
if (
    PHP_SAPI !== 'cli' ||
    !defined('ABSPATH') ||
    !defined('PAGENEST_READING_SYNC_TEST_LAB') ||
    !PAGENEST_READING_SYNC_TEST_LAB
) {
    throw new RuntimeException('An explicitly marked disposable reading-sync lab is required.');
}
function pn_sync_assert($value, string $name): void
{
    if (!$value) {
        throw new RuntimeException($name);
    }
}
function pn_sync_request(string $action = 'sync', array $body = [], bool $nonce = true)
{
    $request = new WP_REST_Request(
        $action === 'sync' ? 'GET' : 'POST',
        '/pagenest/v1/reading/account/' . $action,
    );
    if ($nonce) {
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    }
    if ($action !== 'sync') {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }
    return apply_filters(
        'rest_post_dispatch',
        rest_do_request($request),
        rest_get_server(),
        $request,
    );
}
$guard = static fn() => true;
add_filter('pre_wp_mail', $guard);
$token = 'pn-sync-' . wp_generate_password(8, false);
$owner = wp_create_user($token, wp_generate_password(), $token . '@example.test');
$other = wp_create_user($token . '-other', wp_generate_password(), $token . '-other@example.test');
$old = [
    get_option('pagenest_companion_reading', '0'),
    get_option('pagenest_companion_account_reading', '0'),
];
$posts = [];
try {
    update_option('pagenest_companion_reading', '1');
    update_option('pagenest_companion_account_reading', '1');
    wp_set_current_user(0);
    $anonymous = pn_sync_request();
    pn_sync_assert(
        $anonymous->get_status() === 401 &&
            str_contains($anonymous->get_headers()['Cache-Control'] ?? '', 'no-store'),
        'Anonymous sync status is private and denied',
    );
    wp_set_current_user($owner);
    pn_sync_assert(
        pn_sync_request('sync', [], false)->get_status() === 401,
        'Sync status requires REST nonce',
    );
    $state = pn_sync_request()->get_data();
    pn_sync_assert(
        !$state['automatic']['enabled'] &&
            !$state['preferences']['enabled'] &&
            !metadata_exists('user', $owner, '_pagenest_reading_automatic') &&
            !metadata_exists('user', $owner, '_pagenest_reading_preferences'),
        'Initial status creates no sync consent',
    );
    pn_sync_assert(
        pn_sync_request('automatic/consent', ['epoch' => '', 'enabled' => true])->get_status() ===
            403 && !pagenest_account_reading_state($owner)['enabled'],
        'Automatic consent does not promote manual consent',
    );
    $preferences = pn_sync_request('preferences/consent', ['revision' => ''])->get_data();
    pn_sync_assert(
        $preferences['enabled'] &&
            !$preferences['values'] &&
            !pagenest_account_reading_state($owner)['enabled'],
        'Preference consent is independent and uploads no old local values',
    );
    $preferences = pn_sync_request('preferences/update', [
        'revision' => $preferences['revision'],
        'epoch' => $preferences['epoch'],
        'values' => ['size' => '20'],
    ])->get_data();
    pn_sync_assert(
        $preferences['values'] === ['size' => '20'],
        'Preference patches contain only changed whitelist fields',
    );
    $joined = pn_sync_request('preferences/consent', [
        'revision' => $preferences['revision'],
    ])->get_data();
    pn_sync_assert(
        $joined === $preferences,
        'Explicit device join preserves existing preference revision and epoch',
    );
    pn_sync_assert(
        pn_sync_request('preferences/update', [
            'revision' => $preferences['revision'],
            'epoch' => $preferences['epoch'],
            'values' => ['appearance' => 'dark'],
        ])->get_status() === 400,
        'Global appearance is not an accepted reading preference',
    );
    pn_sync_assert(
        pn_sync_request('preferences/consent', [
            'revision' => $preferences['revision'],
            'user_id' => $other,
        ])->get_status() === 400,
        'Client cannot select another owner',
    );
    $manual = pagenest_account_reading_write($owner, 'consent', ['revision' => '']);
    pn_sync_assert(!is_wp_error($manual), 'Native manual consent established without history');
    $automatic = pn_sync_request('automatic/consent', [
        'epoch' => '',
        'enabled' => true,
    ])->get_data();
    $joined = pn_sync_request('automatic/consent', [
        'epoch' => $automatic['epoch'],
        'enabled' => true,
    ])->get_data();
    pn_sync_assert(
        $joined === $automatic && !pagenest_account_reading_state($owner)['items'],
        'Automatic device join preserves epoch and does not upload history',
    );
    foreach (['publish', 'private'] as $status) {
        $posts[] = wp_insert_post([
            'post_title' => 'Synthetic reading sync',
            'post_content' => '<h2 id="stable">Current heading</h2>',
            'post_status' => $status,
            'post_author' => $owner,
        ]);
    }
    $item = ['id' => $posts[0], 'later' => true, 'anchor' => 'stable'];
    pn_sync_assert(
        pn_sync_request('automatic/progress', [
            'epoch' => $automatic['epoch'],
            'item' => $item + ['time' => 946684800000],
        ])->get_status() === 400,
        'Automatic endpoint refuses client time and historical fields',
    );
    pn_sync_assert(
        pn_sync_request('automatic/progress', [
            'epoch' => $automatic['epoch'],
            'items' => [$item],
        ])->get_status() === 400,
        'Automatic endpoint refuses whole-history lists',
    );
    $before = (int) (microtime(true) * 1000);
    $progress = pn_sync_request('automatic/progress', [
        'epoch' => $automatic['epoch'],
        'item' => $item,
    ]);
    $manual = pagenest_account_reading_state($owner);
    pn_sync_assert(
        $progress->get_status() === 200 &&
            count($manual['items']) === 1 &&
            $manual['items'][0]['time'] >= $before &&
            $manual['items'][0]['anchor'] === 'stable',
        'Native automatic increment stores one visible article using server time',
    );
    pn_sync_assert(
        pagenest_reading_sync_state($owner, 'preferences')['revision'] === $preferences['revision'],
        'Reading increments never change preference revision',
    );
    pn_sync_assert(
        pn_sync_request('automatic/progress', [
            'epoch' => $automatic['epoch'],
            'item' => array_replace($item, ['id' => $posts[1]]),
        ])->get_status() === 403,
        'Private sources cannot enter automatic records',
    );
    wp_set_current_user($other);
    pn_sync_assert(
        pn_sync_request()->get_data()['preferences']['values'] === [] &&
            !metadata_exists('user', $other, '_pagenest_reading_preferences'),
        'Another account neither reads nor creates owner preference state',
    );
    wp_set_current_user($owner);
    $manual = pagenest_account_reading_write($owner, 'remove', [
        'revision' => $manual['revision'],
        'id' => $posts[0],
    ]);
    pn_sync_assert(!is_wp_error($manual) && !$manual['items'], 'Manual removal succeeds');
    pn_sync_assert(
        pn_sync_request('automatic/progress', [
            'epoch' => $automatic['epoch'],
            'item' => $item,
        ])->get_status() === 409 &&
            pn_sync_request('automatic/consent', [
                'epoch' => $automatic['epoch'],
                'enabled' => true,
            ])->get_status() === 409,
        'Old device cannot recreate removed item or automatic consent',
    );
    $automatic = pagenest_reading_sync_state($owner, 'automatic');
    $automatic = pn_sync_request('automatic/consent', [
        'epoch' => $automatic['epoch'],
        'enabled' => true,
    ])->get_data();
    foreach (['clear', 'withdraw'] as $action) {
        $manual = pagenest_account_reading_state($owner);
        $result = pagenest_account_reading_write($owner, $action, [
            'revision' => $manual['revision'],
        ]);
        pn_sync_assert(!is_wp_error($result), 'Native ' . $action . ' succeeds');
        pn_sync_assert(
            pn_sync_request('automatic/progress', [
                'epoch' => $automatic['epoch'],
                'item' => $item,
            ])->get_status() === 409 &&
                pn_sync_request('automatic/consent', [
                    'epoch' => $automatic['epoch'],
                    'enabled' => true,
                ])->get_status() === 409,
            'Old device cannot restore records or consent after ' . $action,
        );
        if ($action === 'withdraw') {
            $manual = pagenest_account_reading_state($owner);
            pn_sync_assert(
                !is_wp_error(
                    pagenest_account_reading_write($owner, 'consent', [
                        'revision' => $manual['revision'],
                    ]),
                ),
                'Explicit manual re-consent succeeds',
            );
        }
        $automatic = pagenest_reading_sync_state($owner, 'automatic');
        $automatic = pn_sync_request('automatic/consent', [
            'epoch' => $automatic['epoch'],
            'enabled' => true,
        ])->get_data();
    }
    update_option('pagenest_companion_account_reading', '0');
    $manual_before = pagenest_account_reading_state($owner);
    $stopped = pn_sync_request('automatic/withdraw', ['epoch' => $automatic['epoch']]);
    pn_sync_assert(
        $stopped->get_status() === 200 &&
            !$stopped->get_data()['enabled'] &&
            pagenest_account_reading_state($owner) === $manual_before,
        'Administrator pause still permits automatic-only revocation and keeps manual state',
    );
    $withdrawn = pn_sync_request('preferences/withdraw', [
        'revision' => $preferences['revision'],
    ])->get_data();
    pn_sync_assert(
        !$withdrawn['enabled'] && !$withdrawn['values'],
        'Administrator pause still permits preference-only revocation',
    );
    update_option('pagenest_companion_account_reading', '1');
    pn_sync_assert(
        pn_sync_request('preferences/consent', [
            'revision' => $preferences['revision'],
        ])->get_status() === 409,
        'Old revision cannot restore withdrawn preference consent',
    );
    $preferences = pn_sync_request('preferences/consent', [
        'revision' => $withdrawn['revision'],
    ])->get_data();
    $failure = static fn($check, $id, $key) => $id === $owner &&
    $key === '_pagenest_reading_preferences'
        ? false
        : $check;
    add_filter('update_user_metadata', $failure, 10, 3);
    $failed = pn_sync_request('preferences/update', [
        'revision' => $preferences['revision'],
        'epoch' => $preferences['epoch'],
        'values' => ['width' => 'wide'],
    ]);
    remove_filter('update_user_metadata', $failure, 10);
    pn_sync_assert(
        $failed->get_status() === 500 &&
            pagenest_reading_sync_state($owner, 'preferences')['revision'] ===
                $preferences['revision'],
        'Native failed preference write rolls back',
    );
    $export = pagenest_account_reading_export(get_userdata($owner)->user_email);
    pn_sync_assert(
        in_array('pagenest-reading-automatic', array_column($export['data'], 'item_id'), true) &&
            in_array(
                'pagenest-reading-preferences',
                array_column($export['data'], 'item_id'),
                true,
            ),
        'Native export includes both independent consent records',
    );
    $snapshot = [
        pagenest_account_reading_state($owner),
        pagenest_reading_sync_state($owner, 'automatic'),
        pagenest_reading_sync_state($owner, 'preferences'),
    ];
    $failure = static fn($check, $id, $key) => $id === $owner &&
    $key === '_pagenest_reading_automatic'
        ? false
        : $check;
    add_filter('update_user_metadata', $failure, 10, 3);
    $erased = pagenest_account_reading_erase(get_userdata($owner)->user_email);
    remove_filter('update_user_metadata', $failure, 10);
    pn_sync_assert(
        !$erased['done'] &&
            [
                pagenest_account_reading_state($owner),
                pagenest_reading_sync_state($owner, 'automatic'),
                pagenest_reading_sync_state($owner, 'preferences'),
            ] === $snapshot,
        'Native erasure is atomic across all three keys on failure',
    );
    update_option('pagenest_companion_account_reading', '0');
    $erased = pagenest_account_reading_erase(get_userdata($owner)->user_email);
    pn_sync_assert(
        $erased['done'] &&
            !pagenest_account_reading_state($owner)['enabled'] &&
            !pagenest_reading_sync_state($owner, 'preferences')['enabled'] &&
            !pagenest_reading_sync_state($owner, 'automatic')['enabled'],
        'Disabled-module privacy erasure revokes every consent',
    );
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $late_meta = static function ($id) use ($other): void {
        if ((int) $id !== (int) $other) {
            return;
        }
        foreach (
            [
                '_pagenest_account_reading',
                '_pagenest_reading_automatic',
                '_pagenest_reading_preferences',
            ]
            as $key
        ) {
            add_user_meta($id, $key, 'synthetic late state');
        }
        add_user_meta($id, '_pn_sync_unrelated', 'retained');
    };
    add_action('deleted_user', $late_meta, 1);
    wp_delete_user($other);
    remove_action('deleted_user', $late_meta, 1);
    foreach (
        [
            '_pagenest_account_reading',
            '_pagenest_reading_automatic',
            '_pagenest_reading_preferences',
        ]
        as $key
    ) {
        pn_sync_assert(
            !metadata_exists('user', $other, $key),
            'User deletion hook clears late ' . $key,
        );
    }
    pn_sync_assert(
        get_user_meta($other, '_pn_sync_unrelated', true) === 'retained',
        'User deletion hook is limited to the three reading keys',
    );
    delete_user_meta($other, '_pn_sync_unrelated');
    echo "Native reading sync permissions, epochs, independent revisions, privacy and rollback passed.\n";
} finally {
    foreach ($posts as $id) {
        wp_delete_post($id, true);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($owner);
    wp_delete_user($other);
    update_option('pagenest_companion_reading', $old[0]);
    update_option('pagenest_companion_account_reading', $old[1]);
    remove_filter('pre_wp_mail', $guard);
    wp_set_current_user(0);
}
