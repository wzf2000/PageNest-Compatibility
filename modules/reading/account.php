<?php
/** Explicit, versioned account reading. Separate from device storage and private notes. */
defined('ABSPATH') || exit();
function pagenest_account_reading_enabled(): bool
{
    return !is_multisite() &&
        pagenest_reading_enabled() &&
        get_option('pagenest_companion_account_reading', '0') === '1';
}
function pagenest_account_reading_offered(): bool
{
    return !is_multisite() &&
        pagenest_reading_enabled() &&
        (pagenest_account_reading_enabled() ||
            (get_current_user_id() &&
                metadata_exists('user', get_current_user_id(), '_pagenest_account_reading')));
}
function pagenest_account_reading_error(string $code = 'storage', int $status = 500): WP_Error
{
    return new WP_Error(
        'account_reading_' . $code,
        __(
            'Account reading could not be saved or loaded. Refresh and try again.',
            'pagenest-companion',
        ),
        ['status' => $status],
    );
}
function pagenest_account_reading_state(int $user)
{
    global $wpdb;
    $rows = get_user_meta($user, '_pagenest_account_reading', false);
    if ($wpdb->last_error !== '' || !is_array($rows) || count($rows) > 1) {
        return pagenest_account_reading_error();
    }
    if (!$rows) {
        return ['enabled' => false, 'revision' => '', 'items' => []];
    }
    $state = $rows[0];
    if (
        !is_array($state) ||
        !is_bool($state['enabled'] ?? null) ||
        !is_string($state['revision'] ?? null) ||
        !preg_match('/^[a-f0-9]{32}$/D', $state['revision']) ||
        !is_array($state['items'] ?? null)
    ) {
        return pagenest_account_reading_error();
    }
    $items = pagenest_account_reading_items($state['items']);
    if (is_wp_error($items)) {
        return pagenest_account_reading_error();
    }
    return ['enabled' => $state['enabled'], 'revision' => $state['revision'], 'items' => $items];
}
function pagenest_account_reading_items($items)
{
    if (!is_array($items) || array_values($items) !== $items || count($items) > 100) {
        return pagenest_account_reading_error('invalid', 400);
    }
    $ids = [];
    foreach ($items as $item) {
        if (
            !is_array($item) ||
            count($item) !== 4 ||
            !is_int($item['id'] ?? null) ||
            $item['id'] < 1 ||
            isset($ids[$item['id']]) ||
            !is_int($item['time'] ?? null) ||
            $item['time'] < 946684800000 ||
            $item['time'] > (int) (microtime(true) * 1000) + 300000 ||
            !is_bool($item['later'] ?? null) ||
            !is_string($item['anchor'] ?? null) ||
            strlen($item['anchor']) > 800
        ) {
            return pagenest_account_reading_error('invalid', 400);
        }
        $ids[$item['id']] = true;
    }
    return $items;
}
/** Re-check visibility on every projection; never persist title, URL or content. */
function pagenest_account_reading_project(array $items, bool $display = true)
{
    global $wpdb;
    if (is_multisite()) {
        return pagenest_account_reading_error('unsupported', 403);
    }
    $out = [];
    foreach ($items as $item) {
        $wpdb->last_error = '';
        $post = get_post($item['id']);
        if ($wpdb->last_error !== '') {
            return pagenest_account_reading_error();
        }
        if (
            !$post ||
            !in_array($post->post_type, ['post', 'page'], true) ||
            !pagenest_discovery_public_post($post)
        ) {
            continue;
        }
        $headings = array_column(pagenest_discovery_sections($post), 'id');
        $item['anchor'] = in_array($item['anchor'], $headings, true) ? $item['anchor'] : '';
        if ($display) {
            $item['title'] = pagenest_discovery_text($post->post_title, 180);
            $item['url'] = get_permalink($post);
        }
        if ($wpdb->last_error !== '') {
            return pagenest_account_reading_error();
        }
        $out[] = $item;
    }
    return $out;
}
function pagenest_account_reading_merge(array $stored, array $incoming): array
{
    $items = [];
    foreach (array_merge($stored, $incoming) as $item) {
        $old = $items[$item['id']] ?? null;
        $later = $item['later'] || ($old['later'] ?? false);
        if (!$old || $item['time'] > $old['time']) {
            $items[$item['id']] = $item;
        }
        $items[$item['id']]['later'] = $later;
    }
    $items = array_values($items);
    usort($items, static fn($a, $b) => $b['time'] <=> $a['time'] ?: $a['id'] <=> $b['id']);
    return array_slice($items, 0, 100);
}
/** All writers, including privacy erasure, serialize on the existing users row. */
function pagenest_account_reading_write(
    int $user,
    string $action,
    array $input = [],
    bool $privacy = false,
) {
    global $wpdb;
    if (is_multisite() && $action !== 'withdraw') {
        return pagenest_account_reading_error('unsupported', 403);
    }
    foreach ([$wpdb->users, $wpdb->usermeta] as $table) {
        $engine = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table,
            ),
        );
        if (strtoupper((string) $engine) !== 'INNODB') {
            return pagenest_account_reading_error();
        }
    }
    if ($wpdb->query('START TRANSACTION') === false) {
        return pagenest_account_reading_error();
    }
    $result = pagenest_account_reading_error();
    try {
        $locked = $wpdb->get_var(
            $wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE", $user),
        );
        if ((int) $locked !== $user || $wpdb->last_error !== '') {
            throw new RuntimeException();
        }
        wp_cache_delete($user, 'user_meta');
        $state = pagenest_account_reading_state($user);
        if (is_wp_error($state)) {
            $result = $state;
            throw new RuntimeException();
        }
        if (!$privacy && $state['revision'] !== $input['revision']) {
            $result = pagenest_account_reading_error('conflict', 409);
            throw new RuntimeException();
        }
        if (!$privacy && $action !== 'withdraw' && !pagenest_account_reading_enabled()) {
            $result = pagenest_account_reading_error('disabled', 403);
            throw new RuntimeException();
        }
        if ($action === 'consent') {
            $state['enabled'] = true;
        } elseif ($action === 'withdraw') {
            $state = ['enabled' => false, 'items' => []];
        } else {
            if (!$state['enabled']) {
                $result = pagenest_account_reading_error('consent', 403);
                throw new RuntimeException();
            }
            if ($action === 'merge') {
                $stored = pagenest_account_reading_project($state['items'], false);
                if (is_wp_error($stored)) {
                    $result = $stored;
                    throw new RuntimeException();
                }
                $incoming = pagenest_account_reading_project($input['items'], false);
                if (is_wp_error($incoming)) {
                    $result = $incoming;
                    throw new RuntimeException();
                }
                $state['items'] = pagenest_account_reading_merge($stored, $incoming);
            } elseif ($action === 'clear') {
                $state['items'] = [];
            } elseif ($action === 'remove') {
                $state['items'] = array_values(
                    array_filter($state['items'], static fn($item) => $item['id'] !== $input['id']),
                );
            } else {
                throw new RuntimeException();
            }
        }
        $state['revision'] = bin2hex(random_bytes(16));
        $state = [
            'enabled' => $state['enabled'],
            'revision' => $state['revision'],
            'items' => $state['items'],
        ];
        update_user_meta($user, '_pagenest_account_reading', wp_slash($state));
        if ($wpdb->last_error !== '') {
            throw new RuntimeException();
        }
        wp_cache_delete($user, 'user_meta');
        if (pagenest_account_reading_state($user) !== $state) {
            throw new RuntimeException();
        }
        if ($wpdb->query('COMMIT') === false) {
            throw new RuntimeException();
        }
        wp_cache_delete($user, 'user_meta');
        return $state;
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        wp_cache_delete($user, 'user_meta');
        return $result;
    }
}
function pagenest_account_reading_permission($request)
{
    if (!get_current_user_id() || !wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
        return pagenest_account_reading_error('authentication', 401);
    }
    if (is_multisite() && !str_ends_with($request->get_route(), '/withdraw')) {
        return pagenest_account_reading_error('unsupported', 403);
    }
    return str_ends_with($request->get_route(), '/withdraw') ||
        pagenest_account_reading_enabled() ||
        ($request->get_method() === 'GET' && pagenest_account_reading_offered())
        ? true
        : pagenest_account_reading_error('disabled', 403);
}
function pagenest_account_reading_request($request, string $action)
{
    if (is_multisite() && $action !== 'withdraw') {
        return pagenest_account_reading_error('unsupported', 403);
    }
    if (strlen($request->get_body()) > 100000) {
        return pagenest_account_reading_error('invalid', 413);
    }
    $input = $request->get_json_params();
    $allowed = ['revision'];
    if ($action === 'merge') {
        $allowed[] = 'items';
    }
    if ($action === 'remove') {
        $allowed[] = 'id';
    }
    if ($action === 'consent') {
        $allowed[] = 'enabled';
    }
    if (
        !is_array($input) ||
        array_diff(array_keys($input), $allowed) ||
        !is_string($input['revision'] ?? null) ||
        !preg_match('/^(?:[a-f0-9]{32})?$/D', $input['revision'])
    ) {
        return pagenest_account_reading_error('invalid', 400);
    }
    if ($action === 'consent' && ($input['enabled'] ?? null) !== true) {
        return pagenest_account_reading_error('invalid', 400);
    }
    if ($action === 'remove' && (!is_int($input['id'] ?? null) || $input['id'] < 1)) {
        return pagenest_account_reading_error('invalid', 400);
    }
    if ($action === 'merge') {
        $items = pagenest_account_reading_items($input['items'] ?? null);
        if (is_wp_error($items)) {
            return $items;
        }
    }
    $state = pagenest_account_reading_write(get_current_user_id(), $action, $input);
    if (is_wp_error($state)) {
        return $state;
    }
    $state['available'] = pagenest_account_reading_enabled();
    $items = $state['available'] ? pagenest_account_reading_project($state['items']) : [];
    if (is_wp_error($items)) {
        return new WP_Error(
            'account_reading_readback',
            __(
                'Account reading was saved, but its current list could not be loaded. Refresh before making another change.',
                'pagenest-companion',
            ),
            ['status' => 500],
        );
    }
    $state['items'] = $items;
    return $state;
}
add_action('rest_api_init', static function () {
    register_rest_route('pagenest/v1', '/reading/account', [
        'methods' => 'GET',
        'permission_callback' => 'pagenest_account_reading_permission',
        'callback' => static function ($request) {
            if (is_multisite()) {
                return pagenest_account_reading_error('unsupported', 403);
            }
            if (array_diff(array_keys($request->get_query_params()), ['rest_route'])) {
                return pagenest_account_reading_error('invalid', 400);
            }
            wp_cache_delete(get_current_user_id(), 'user_meta');
            $state = pagenest_account_reading_state(get_current_user_id());
            if (!is_wp_error($state)) {
                $state['available'] = pagenest_account_reading_enabled();
                $items = $state['available']
                    ? pagenest_account_reading_project($state['items'])
                    : [];
                if (is_wp_error($items)) {
                    return $items;
                }
                $state['items'] = $items;
            }
            return $state;
        },
    ]);
    foreach (['consent', 'merge', 'remove', 'clear', 'withdraw'] as $action) {
        register_rest_route('pagenest/v1', '/reading/account/' . $action, [
            'methods' => 'POST',
            'permission_callback' => 'pagenest_account_reading_permission',
            'callback' => static fn($request) => pagenest_account_reading_request(
                $request,
                $action,
            ),
        ]);
    }
});
add_filter(
    'rest_post_dispatch',
    static function ($response, $server, $request) {
        if (preg_match('#^/pagenest/v1/reading/account(?:/|$)#', $request->get_route())) {
            $response->header('Cache-Control', 'private, no-store, max-age=0');
            $response->header('Vary', 'Cookie');
        }
        return $response;
    },
    10,
    3,
);
add_filter('wp_privacy_personal_data_exporters', static function (array $exporters): array {
    $exporters['pagenest-account-reading'] = [
        'exporter_friendly_name' => __('Account reading', 'pagenest-companion'),
        'callback' => 'pagenest_account_reading_export',
    ];
    return $exporters;
});
function pagenest_account_reading_export(string $email, int $page = 1)
{
    $user = get_user_by('email', $email);
    if (!$user || $page > 1) {
        return ['data' => [], 'done' => true];
    }
    $state = pagenest_account_reading_state((int) $user->ID);
    if (is_wp_error($state)) {
        return $state;
    }
    $data = [
        [
            'group_id' => 'pagenest-account-reading',
            'group_label' => __('Account reading', 'pagenest-companion'),
            'item_id' => 'pagenest-account-reading-consent',
            'data' => [['name' => 'enabled', 'value' => $state['enabled'] ? 'true' : 'false']],
        ],
    ];
    foreach ($state['items'] as $item) {
        $data[] = [
            'group_id' => 'pagenest-account-reading',
            'group_label' => __('Account reading', 'pagenest-companion'),
            'item_id' => 'pagenest-account-reading-' . $item['id'],
            'data' => array_map(
                static fn($key, $value) => [
                    'name' => $key,
                    'value' => esc_html(
                        is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                    ),
                ],
                array_keys($item),
                array_values($item),
            ),
        ];
    }
    return ['data' => $data, 'done' => true];
}
add_filter('wp_privacy_personal_data_erasers', static function (array $erasers): array {
    $erasers['pagenest-account-reading'] = [
        'eraser_friendly_name' => __('Account reading', 'pagenest-companion'),
        'callback' => 'pagenest_account_reading_erase',
    ];
    return $erasers;
});
function pagenest_account_reading_erase(string $email, int $page = 1): array
{
    $user = get_user_by('email', $email);
    if (!$user || $page > 1) {
        return [
            'items_removed' => false,
            'items_retained' => false,
            'messages' => [],
            'done' => true,
        ];
    }
    $result = pagenest_account_reading_write((int) $user->ID, 'withdraw', [], true);
    $failed = is_wp_error($result);
    return [
        'items_removed' => !$failed,
        'items_retained' => $failed,
        'messages' => $failed
            ? [
                __(
                    'Account reading could not be erased. Retry after storage is available.',
                    'pagenest-companion',
                ),
            ]
            : [],
        'done' => !$failed,
    ];
}

/**
 * Core snapshots/deletes metadata before deleting users. Drain only our key after
 * the row is gone, including writes that committed while core waited for its lock.
 * Both wp_delete_user and wpmu_delete_user fire deleted_user after their work;
 * a Multisite membership removal leaves the global users row and must keep state.
 */
function pagenest_account_reading_deleted_user(int $user): void
{
    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID = %d", $user));
    if ($wpdb->last_error !== '' || $exists !== null) {
        return;
    }
    delete_user_meta($user, '_pagenest_account_reading');
}
add_action('deleted_user', 'pagenest_account_reading_deleted_user', PHP_INT_MAX);
