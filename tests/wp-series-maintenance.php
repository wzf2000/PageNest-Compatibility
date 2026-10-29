<?php
/** Run only in an owned local synthetic WordPress installation via WP-CLI eval-file. */
$state_path = getenv('COMPONENT_LAB_STATE');
$state_real = $state_path ? realpath($state_path) : false;
if (
    PHP_SAPI !== 'cli' ||
    wp_get_environment_type() !== 'local' ||
    !$state_real ||
    !is_file($state_real) ||
    str_starts_with($state_real, realpath(ABSPATH) . DIRECTORY_SEPARATOR)
) {
    throw new RuntimeException('Owned local synthetic test environment required');
}
json_decode(file_get_contents($state_real), true, 32, JSON_THROW_ON_ERROR);
$checks = [];
$ids = [];
$user = get_current_user_id();
$profile = $GLOBALS['pagenest_companion_profile'];
$old_options = [
    get_option('pagenest_companion_features', null),
    get_option('pagenest_companion_views_settings', null),
    get_option('pagenest_companion_series_directories', null),
];
function series_wp_assert($ok, $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $GLOBALS['series_wp_checks'][] = $message;
}
$GLOBALS['series_wp_checks'] = [];
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
if (!$admins) {
    throw new RuntimeException('Synthetic administrator required');
}
wp_set_current_user((int) $admins[0]);
$series = 'Synthetic maintenance ' . wp_generate_uuid4();
$deny = $fail = null;
try {
    foreach (['2', '2', ''] as $index => $order) {
        $id = wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'Synthetic maintenance chapter ' . $index,
            'post_content' => '<p>Synthetic body stays intact.</p>',
            'meta_input' => [
                '_pagenest_series' => $series,
                '_pagenest_document_id' => 'SYN-' . $index,
                '_pagenest_chapter_order' => $order,
                '_synthetic_legacy_order' => $order,
            ],
        ]);
        if (!$id || is_wp_error($id)) {
            throw new RuntimeException('Cannot create synthetic chapter');
        }
        $ids[] = (int) $id;
    }
    $GLOBALS['pagenest_companion_profile']['chapters']['series_order'] = [];
    $GLOBALS['pagenest_companion_profile']['chapters']['order_meta'] = '_synthetic_legacy_order';
    $before = [];
    foreach ($ids as $id) {
        $before[$id] = [get_post($id)->to_array(), get_post_meta($id)];
    }
    $orders = [$ids[0] => '3', $ids[1] => '1', $ids[2] => '2'];
    $make = static function () use ($series, $orders) {
        $snapshot = pagenest_companion_series_snapshot($series);
        return [
            'pn_series' => $series,
            'pn_series_revision' => $snapshot['revision'],
            'pn_series_nonce' => wp_create_nonce('pagenest-series-maintenance'),
            'pn_series_orders' => $orders,
            'pn_series_action' => 'preview',
        ];
    };
    $form = $make();
    $bad = $form;
    $bad['pn_series_nonce'] = 'invalid';
    series_wp_assert(
        is_wp_error(pagenest_companion_series_request($bad)),
        'Invalid nonce rejects preview',
    );
    $preview = pagenest_companion_series_request($form);
    series_wp_assert(
        !is_wp_error($preview) && !$preview['saved'],
        'Real WordPress preview succeeds without saving',
    );
    series_wp_assert(
        get_post_meta($ids[0], '_pagenest_chapter_order', true) === '2',
        'Preview leaves metadata unchanged',
    );
    $save = $form;
    $save['pn_series_action'] = 'save';
    series_wp_assert(
        pagenest_companion_series_request($save)->get_error_code() === 'series-preview',
        'Unpreviewed save rejected',
    );
    $save['pn_series_preview'] = pagenest_companion_series_preview_token(
        $series,
        $form['pn_series_revision'],
        $orders,
    );
    $tampered = $save;
    $tampered['pn_series_orders'][$ids[0]] = '4';
    series_wp_assert(
        pagenest_companion_series_request($tampered)->get_error_code() === 'series-preview',
        'Changed order cannot reuse old preview',
    );
    $deny = static function ($caps, $cap, $uid, $args) use ($ids) {
        return $cap === 'edit_post' && (int) ($args[0] ?? 0) === $ids[1] ? ['do_not_allow'] : $caps;
    };
    add_filter('map_meta_cap', $deny, 99, 4);
    series_wp_assert(
        is_wp_error(pagenest_companion_series_request($save)),
        'Revoked per-post edit permission rejects save',
    );
    remove_filter('map_meta_cap', $deny, 99);
    $deny = null;
    $GLOBALS['pagenest_companion_profile']['chapters']['series_directories'][$series] = 999999;
    series_wp_assert(
        pagenest_companion_series_snapshot($series)['editable'],
        'Server directory mapping alone does not disable chapter editing',
    );
    $form = $make();
    $save = $form;
    $save['pn_series_action'] = 'save';
    $save['pn_series_preview'] = pagenest_companion_series_preview_token(
        $series,
        $form['pn_series_revision'],
        $orders,
    );
    update_post_meta($ids[0], '_pagenest_chapter_order', '8');
    series_wp_assert(
        pagenest_companion_series_request($save)->get_error_code() === 'series-conflict',
        'Concurrent old-value change rejects entire save',
    );
    update_post_meta($ids[0], '_pagenest_chapter_order', '2');
    $added = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => 'Synthetic newly added member',
        'meta_input' => ['_pagenest_series' => $series],
    ]);
    try {
        series_wp_assert(
            pagenest_companion_series_request($save)->get_error_code() === 'series-conflict',
            'Concurrent member addition rejects old complete-series proposal',
        );
    } finally {
        wp_delete_post($added, true);
    }
    $GLOBALS['pagenest_companion_profile']['chapters']['series_order'] = ['SYN-1'];
    series_wp_assert(
        !pagenest_companion_series_snapshot($series)['editable'],
        'Server document order makes series read-only',
    );
    $GLOBALS['pagenest_companion_profile']['chapters']['series_order'] = [];
    $form = $make();
    $save = $form;
    $save['pn_series_action'] = 'save';
    $save['pn_series_preview'] = pagenest_companion_series_preview_token(
        $series,
        $form['pn_series_revision'],
        $orders,
    );
    $fail = static function ($check, $id, $key) use ($ids) {
        return (int) $id === $ids[1] && $key === '_pagenest_chapter_order' ? false : $check;
    };
    add_filter('update_post_metadata', $fail, 99, 3);
    series_wp_assert(
        is_wp_error(pagenest_companion_series_request($save)),
        'Injected second write failure rejects batch',
    );
    remove_filter('update_post_metadata', $fail, 99);
    $fail = null;
    series_wp_assert(
        array_map(static fn($id) => get_post_meta($id, '_pagenest_chapter_order', true), $ids) === [
            '2',
            '2',
            '',
        ],
        'Transaction rolls back prior write and clears caches',
    );
    series_wp_assert(
        !is_wp_error(pagenest_companion_series_request($save)),
        'Complete previewed batch saves through WordPress metadata API',
    );
    series_wp_assert(
        array_map(static fn($id) => get_post_meta($id, '_pagenest_chapter_order', true), $ids) === [
            '3',
            '1',
            '2',
        ],
        'All requested numeric orders persisted',
    );
    series_wp_assert(
        array_column(pagenest_companion_series($ids[0]), 'ID') === [$ids[1], $ids[2], $ids[0]],
        'Reader navigation uses saved numeric order',
    );
    series_wp_assert(
        pagenest_companion_series_request($save)->get_error_code() === 'series-conflict',
        'Late duplicate save cannot overwrite newer revision',
    );
    foreach ($ids as $id) {
        $after_meta = get_post_meta($id);
        unset($after_meta['_pagenest_chapter_order']);
        $before_meta = $before[$id][1];
        unset($before_meta['_pagenest_chapter_order']);
        series_wp_assert(
            get_post($id)->to_array() === $before[$id][0] && $after_meta === $before_meta,
            'Body, identity, series and all unrelated metadata retained',
        );
    }
    series_wp_assert(
        [
            get_option('pagenest_companion_features', null),
            get_option('pagenest_companion_views_settings', null),
            get_option('pagenest_companion_series_directories', null),
        ] === $old_options,
        'Independent save preserves reading, views and directory settings',
    );
    echo wp_json_encode(
        ['checks' => $GLOBALS['series_wp_checks']],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . "\n";
} finally {
    if ($deny) {
        remove_filter('map_meta_cap', $deny, 99);
    }
    if ($fail) {
        remove_filter('update_post_metadata', $fail, 99);
    }
    foreach ($ids as $id) {
        wp_delete_post($id, true);
    }
    $GLOBALS['pagenest_companion_profile'] = $profile;
    wp_set_current_user($user);
}
