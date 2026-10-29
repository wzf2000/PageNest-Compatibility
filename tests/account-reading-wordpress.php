<?php
/** Run only in an explicitly marked disposable WordPress lab. */
if (
    PHP_SAPI !== 'cli' ||
    !defined('ABSPATH') ||
    !defined('PAGENEST_ACCOUNT_READING_TEST_LAB') ||
    !PAGENEST_ACCOUNT_READING_TEST_LAB
) {
    throw new RuntimeException('An explicitly marked disposable account reading lab is required.');
}
function pn_account_check($condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException($name);
    }
}
function pn_account_request(
    string $method,
    string $suffix = '',
    array $input = [],
    bool $nonce = true,
) {
    $request = new WP_REST_Request($method, '/pagenest/v1/reading/account' . $suffix);
    if ($nonce) {
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    }
    if ($method === 'GET') {
        $request->set_query_params($input);
    } else {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($input));
    }
    return apply_filters(
        'rest_post_dispatch',
        rest_get_server()->dispatch($request),
        rest_get_server(),
        $request,
    );
}
$token = 'pn-account-' . wp_generate_password(8, false);
$guard = static fn() => true;
add_filter('pre_wp_mail', $guard);
$owner = wp_create_user($token, wp_generate_password(), $token . '@example.test');
$other = wp_create_user($token . '-other', wp_generate_password(), $token . '-other@example.test');
$old = [
    get_option('pagenest_companion_reading', '0'),
    get_option('pagenest_companion_account_reading', '0'),
];
$posts = [];
$race = null;
$deleted = 0;
$late_meta = null;
try {
    update_option('pagenest_companion_reading', '1');
    update_option('pagenest_companion_account_reading', '0');
    wp_set_current_user($owner);
    pn_account_check(pn_account_request('GET')->get_status() === 403, 'Independent switch off');
    update_option('pagenest_companion_account_reading', '1');
    wp_set_current_user(0);
    pn_account_check(pn_account_request('GET')->get_status() === 401, 'Anonymous denied');
    wp_set_current_user($owner);
    pn_account_check(
        pn_account_request('GET', '', [], false)->get_status() === 401,
        'Missing nonce denied',
    );
    $first = pn_account_request('GET');
    pn_account_check(
        $first->get_status() === 200 &&
            $first->get_data()['revision'] === '' &&
            !metadata_exists('user', $owner, '_pagenest_account_reading'),
        'GET creates no state',
    );
    pn_account_check(
        str_contains($first->get_headers()['Cache-Control'], 'no-store') &&
            $first->get_headers()['Vary'] === 'Cookie',
        'Authenticated response is private',
    );
    pn_account_check(
        pn_account_request('GET', '', ['user_id' => $other])->get_status() === 400,
        'Caller cannot target another user',
    );
    $state = pn_account_request('POST', '/consent', [
        'enabled' => true,
        'revision' => '',
    ])->get_data();
    pn_account_check($state['enabled'] && !$state['items'], 'Explicit consent has no upload');
    foreach (['publish', 'private', 'publish'] as $index => $status) {
        $posts[] = wp_insert_post(
            wp_slash([
                'post_title' => 'Synthetic account article ' . $index,
                'post_content' =>
                    '<h2 id="stable">Heading</h2><h2 id="part\\one">Slash heading</h2><p>Body</p>',
                'post_status' => $status,
                'post_type' => $index === 2 ? 'page' : 'post',
                'post_password' => $index === 2 ? 'synthetic' : '',
                'post_author' => $owner,
            ]),
        );
    }
    pn_account_check(
        str_contains(get_post($posts[0])->post_content, 'id="part\\one"'),
        'Synthetic source really retains the backslash heading',
    );
    $now = (int) (microtime(true) * 1000);
    $item = ['id' => $posts[0], 'time' => $now, 'later' => false, 'anchor' => 'stable'];
    $merge = static fn($items, $revision) => pn_account_request('POST', '/merge', [
        'items' => $items,
        'revision' => $revision,
    ]);
    pn_account_check(
        $merge([$item, $item], $state['revision'])->get_status() === 400,
        'Duplicate IDs rejected',
    );
    pn_account_check(
        $merge([array_replace($item, ['later' => 1])], $state['revision'])->get_status() === 400,
        'Wrong primitive type rejected',
    );
    pn_account_check(
        $merge(array_fill(0, 101, $item), $state['revision'])->get_status() === 400,
        'Bounded item count',
    );
    pn_account_check(
        $merge(
            [array_replace($item, ['time' => $now + 600000])],
            $state['revision'],
        )->get_status() === 400,
        'Far future timestamp rejected',
    );
    $huge = new WP_REST_Request('POST', '/pagenest/v1/reading/account/merge');
    $huge->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $huge->set_body(str_repeat(' ', 100001));
    pn_account_check(
        rest_get_server()->dispatch($huge)->get_status() === 413,
        'Oversized request rejected',
    );
    $state = $merge(
        [
            $item,
            array_replace($item, ['id' => $posts[1]]),
            array_replace($item, ['id' => $posts[2]]),
        ],
        $state['revision'],
    )->get_data();
    pn_account_check(
        count($state['items']) === 1 && $state['items'][0]['anchor'] === 'stable',
        'Hidden and password sources excluded',
    );
    $state = $merge(
        [array_replace($item, ['time' => $now + 1, 'anchor' => 'part\\one'])],
        $state['revision'],
    )->get_data();
    pn_account_check(
        ($state['items'][0]['anchor'] ?? '') === 'part\\one',
        'WordPress metadata preserves valid backslash heading',
    );
    $state = $merge([array_replace($item, ['time' => $now + 2])], $state['revision'])->get_data();
    $raw = get_user_meta($owner, '_pagenest_account_reading', true);
    pn_account_check(
        array_keys($raw['items'][0]) === ['id', 'time', 'later', 'anchor'],
        'Only minimal records persisted',
    );
    global $wpdb;
    $break_post_read = static function ($sql) use ($posts, $wpdb) {
        return str_contains($sql, 'FROM ' . $wpdb->posts) &&
            preg_match('/WHERE\\s+ID\\s*=\\s*' . $posts[0] . '(?:\\s|$)/i', $sql)
            ? 'SELECT * FROM pagenest_account_reading_missing_table'
            : $sql;
    };
    $suppressed = $wpdb->suppress_errors(true);
    clean_post_cache($posts[0]);
    add_filter('query', $break_post_read);
    $broken_get = pn_account_request('GET');
    $broken_merge = $merge([], $state['revision']);
    remove_filter('query', $break_post_read);
    pn_account_check(
        $broken_get->get_status() === 500 &&
            $broken_merge->get_status() === 500 &&
            get_user_meta($owner, '_pagenest_account_reading', true)['revision'] ===
                $state['revision'],
        'Source query failure fails GET and rolls back stored-side merge',
    );
    $break_incoming = static function ($sql) use ($posts, $wpdb) {
        return str_contains($sql, 'FROM ' . $wpdb->posts) &&
            preg_match('/WHERE\\s+ID\\s*=\\s*' . $posts[1] . '(?:\\s|$)/i', $sql)
            ? 'SELECT * FROM pagenest_account_reading_missing_table'
            : $sql;
    };
    clean_post_cache($posts[1]);
    add_filter('query', $break_incoming);
    $broken_incoming = $merge([array_replace($item, ['id' => $posts[1]])], $state['revision']);
    remove_filter('query', $break_incoming);
    pn_account_check(
        $broken_incoming->get_status() === 500 &&
            get_user_meta($owner, '_pagenest_account_reading', true)['revision'] ===
                $state['revision'],
        'Incoming-side source failure rolls back merge',
    );
    $after_write_failure = static function ($meta_id, $user_id, $key) use (
        $owner,
        $posts,
        $break_post_read,
    ) {
        if ($user_id === $owner && $key === '_pagenest_account_reading') {
            clean_post_cache($posts[0]);
            add_filter('query', $break_post_read);
        }
    };
    add_action('updated_user_meta', $after_write_failure, 10, 3);
    $broken_post = pn_account_request('POST', '/consent', [
        'enabled' => true,
        'revision' => $state['revision'],
    ]);
    remove_action('updated_user_meta', $after_write_failure, 10);
    remove_filter('query', $break_post_read);
    $wpdb->suppress_errors($suppressed);
    pn_account_check(
        $broken_post->get_status() === 500 &&
            $broken_post->get_data()['code'] === 'account_reading_readback' &&
            get_user_meta($owner, '_pagenest_account_reading', true)['revision'] !==
                $state['revision'],
        'Post-commit projection failure reports saved but unreadable',
    );
    $state = pn_account_request('GET')->get_data();
    $stale = $state['revision'];
    $state = $merge(
        [array_replace($item, ['time' => $now - 1000, 'later' => true, 'anchor' => 'bad'])],
        $state['revision'],
    )->get_data();
    pn_account_check(
        $state['items'][0]['later'] && $state['items'][0]['anchor'] === 'stable',
        'Newer position and later OR',
    );
    pn_account_check(
        pn_account_request('POST', '/clear', ['revision' => $stale])->get_status() === 409,
        'Stale request cannot overwrite',
    );
    wp_update_post(['ID' => $posts[0], 'post_content' => '<h2 id="changed">Changed</h2>']);
    pn_account_check(
        pn_account_request('GET')->get_data()['items'][0]['anchor'] === '',
        'Lost heading not projected',
    );
    wp_update_post(['ID' => $posts[0], 'post_status' => 'private']);
    pn_account_check(
        pn_account_request('GET')->get_data()['items'] === [],
        'Current private source omitted',
    );
    wp_set_current_user($other);
    pn_account_check(
        pn_account_request('GET')->get_data()['items'] === [] &&
            !metadata_exists('user', $other, '_pagenest_account_reading'),
        'Other account sees no data',
    );
    wp_set_current_user($owner);
    $failure = static fn($check, $id, $key) => $id === $owner &&
    $key === '_pagenest_account_reading'
        ? false
        : $check;
    add_filter('update_user_metadata', $failure, 10, 3);
    $failed = pn_account_request('POST', '/clear', ['revision' => $state['revision']]);
    remove_filter('update_user_metadata', $failure, 10);
    pn_account_check(
        $failed->get_status() === 500 &&
            get_user_meta($owner, '_pagenest_account_reading', true)['revision'] ===
                $state['revision'],
        'Write failure rolls back honestly',
    );
    $export = pagenest_account_reading_export(get_userdata($owner)->user_email);
    pn_account_check(
        count($export['data']) === 2 &&
            !str_contains(wp_json_encode($export), 'Synthetic account article'),
        'Privacy exports raw record and consent only',
    );
    $state = pn_account_request('POST', '/clear', ['revision' => $state['revision']])->get_data();
    pn_account_check(!$state['items'], 'Clear retains enabled consent');
    pn_account_check(
        $merge([$item], $stale)->get_status() === 409,
        'Pre-clear requests stay stale',
    );
    update_option('pagenest_companion_account_reading', '0');
    $paused = pn_account_request('GET');
    pn_account_check(
        $paused->get_status() === 200 &&
            $paused->get_data()['available'] === false &&
            $paused->get_data()['items'] === [],
        'Disabled account feature permits only consent status for withdrawal',
    );
    $state = $paused->get_data();
    $before_withdraw = $state['revision'];
    $state = pn_account_request('POST', '/withdraw', [
        'revision' => $state['revision'],
    ])->get_data();
    pn_account_check(!$state['enabled'] && !$state['items'], 'Withdrawal allowed when switch off');
    update_option('pagenest_companion_account_reading', '1');
    pn_account_check(
        pn_account_request('POST', '/consent', [
            'enabled' => true,
            'revision' => $before_withdraw,
        ])->get_status() === 409,
        'Tombstone blocks old device resurrection',
    );
    delete_user_meta($owner, '_pagenest_account_reading');
    $state['revision'] = '';
    // Hold the native row lock until both independent connections are ready to write.
    $race = sys_get_temp_dir() . '/' . $token;
    mkdir($race, 0700);
    file_put_contents($race . '/owned', 'synthetic');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    $wpdb->get_var(
        $wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE", $owner),
    );
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/account-reading-concurrency.php',
                ABSPATH,
                (string) $owner,
                $state['revision'],
                $race,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        pn_account_check(is_resource($process), 'Independent database worker starts');
        $workers[] = [$process, $pipes];
    }
    $deadline = microtime(true) + 15;
    while (count(glob($race . '/*.ready')) < 2 && microtime(true) < $deadline) {
        usleep(20000);
    }
    $ready = count(glob($race . '/*.ready')) === 2;
    $blocked =
        $ready &&
        proc_get_status($workers[0][0])['running'] &&
        proc_get_status($workers[1][0])['running'];
    $wpdb->query('COMMIT');
    $statuses = [];
    foreach ($workers as [$process, $pipes]) {
        $result = json_decode(stream_get_contents($pipes[1]), true);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $statuses[] = $result['status'] ?? 0;
    }
    sort($statuses);
    pn_account_check(
        $blocked &&
            $statuses === [200, 409] &&
            count(get_user_meta($owner, '_pagenest_account_reading', false)) === 1,
        'Concurrent connections wait on users row and only one revision wins',
    );
    update_option('pagenest_companion_account_reading', '0');
    $erase = pagenest_account_reading_erase(get_userdata($owner)->user_email);
    pn_account_check(
        $erase['done'] && !get_user_meta($owner, '_pagenest_account_reading', true)['enabled'],
        'Native privacy erase still works when disabled',
    );
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $retained = get_user_meta($owner, '_pagenest_account_reading', true);
    update_user_meta($other, '_pagenest_account_reading', wp_slash($retained));
    pagenest_account_reading_deleted_user($other);
    pn_account_check(
        get_user_meta($other, '_pagenest_account_reading', true) === $retained,
        'Cleanup guard preserves account state while global user row exists (membership-removal guard, not a Multisite run)',
    );
    delete_user_meta($other, '_pagenest_account_reading');
    $deleted = wp_create_user(
        $token . '-deleted',
        wp_generate_password(),
        $token . '-deleted@example.test',
    );
    update_user_meta($deleted, '_pagenest_account_reading', wp_slash($retained));
    $late_meta = static function ($user_id) use ($deleted, $retained) {
        if ($user_id === $deleted) {
            update_user_meta($user_id, '_pagenest_account_reading', wp_slash($retained));
            update_user_meta(
                $user_id,
                '_pn_account_deletion_probe',
                'unrelated synthetic metadata',
            );
        }
    };
    add_action('deleted_user', $late_meta, 0);
    $deleted_ok = wp_delete_user($deleted);
    remove_action('deleted_user', $late_meta, 0);
    $late_meta = null;
    pn_account_check(
        $deleted_ok &&
            !get_userdata($deleted) &&
            !metadata_exists('user', $deleted, '_pagenest_account_reading') &&
            get_user_meta($deleted, '_pn_account_deletion_probe', true) ===
                'unrelated synthetic metadata',
        'Native deleted_user hook drains injected late account metadata and leaves unrelated metadata untouched (hook acceptance, not a deletion-race test)',
    );
    delete_user_meta($deleted, '_pn_account_deletion_probe');
    echo "Native WordPress account reading, failure, privacy and concurrency checks passed.\n";
} finally {
    if ($late_meta) {
        remove_action('deleted_user', $late_meta, 0);
    }
    if ($deleted) {
        delete_user_meta($deleted, '_pn_account_deletion_probe');
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($deleted);
    }
    if ($race) {
        foreach (glob($race . '/*') as $file) {
            unlink($file);
        }
        rmdir($race);
    }
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
