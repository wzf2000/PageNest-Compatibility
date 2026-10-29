<?php
/** Independent automatic-reading epochs and optional reading-preference patches. */
defined('ABSPATH') || exit();
function pagenest_reading_sync_choices(): array
{
    return [
        'size' => ['default', '18', '20', '22'],
        'width' => ['default', 'compact', 'wide'],
        'palette' => ['default', 'paper', 'dark'],
    ];
}
function pagenest_reading_sync_state(int $user, string $kind)
{
    global $wpdb;
    if (!in_array($kind, ['automatic', 'preferences'], true)) {
        return pagenest_account_reading_error('invalid', 400);
    }
    $rows = get_user_meta($user, '_pagenest_reading_' . $kind, false);
    if ($wpdb->last_error !== '' || !is_array($rows) || count($rows) > 1) {
        return pagenest_account_reading_error();
    }
    $default =
        $kind === 'automatic'
            ? ['enabled' => false, 'epoch' => '', 'started' => 0]
            : ['enabled' => false, 'epoch' => '', 'revision' => '', 'values' => []];
    if (!$rows) {
        return $default;
    }
    $state = $rows[0];
    if (
        !is_array($state) ||
        array_diff(array_keys($state), array_keys($default)) ||
        count($state) !== count($default) ||
        !is_bool($state['enabled'] ?? null) ||
        !is_string($state['epoch'] ?? null) ||
        !preg_match('/^[a-f0-9]{32}$/D', $state['epoch'])
    ) {
        return pagenest_account_reading_error();
    }
    if ($kind === 'automatic') {
        if (!is_int($state['started'] ?? null) || $state['started'] < 0) {
            return pagenest_account_reading_error();
        }
    } else {
        if (
            !is_string($state['revision'] ?? null) ||
            !preg_match('/^[a-f0-9]{32}$/D', $state['revision']) ||
            !is_array($state['values'] ?? null) ||
            is_wp_error(pagenest_reading_sync_values($state['values'], true))
        ) {
            return pagenest_account_reading_error();
        }
    }
    return array_replace($default, $state);
}
function pagenest_reading_sync_values($values, bool $empty = false)
{
    if (!is_array($values) || (!$empty && !$values) || count($values) > 3) {
        return pagenest_account_reading_error('invalid', 400);
    }
    $choices = pagenest_reading_sync_choices();
    foreach ($values as $name => $value) {
        if (
            !isset($choices[$name]) ||
            !is_string($value) ||
            !in_array($value, $choices[$name], true)
        ) {
            return pagenest_account_reading_error('invalid', 400);
        }
    }
    return $values;
}
function pagenest_reading_sync_store(int $user, string $kind, array $state)
{
    global $wpdb;
    update_user_meta($user, '_pagenest_reading_' . $kind, wp_slash($state));
    if ($wpdb->last_error !== '') {
        return pagenest_account_reading_error();
    }
    wp_cache_delete($user, 'user_meta');
    return pagenest_reading_sync_state($user, $kind) === $state
        ? $state
        : pagenest_account_reading_error();
}
/** All three record types use the same lock, including revocation and privacy erasure. */
function pagenest_reading_sync_transaction(int $user, callable $operation)
{
    global $wpdb;
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
        $result = $operation();
        if (is_wp_error($result)) {
            throw new RuntimeException();
        }
        if ($wpdb->query('COMMIT') === false) {
            $result = pagenest_account_reading_error();
            throw new RuntimeException();
        }
        return $result;
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        return is_wp_error($result) ? $result : pagenest_account_reading_error();
    } finally {
        wp_cache_delete($user, 'user_meta');
    }
}
function pagenest_reading_sync_revoke(int $user, string $kind)
{
    $state =
        $kind === 'automatic'
            ? ['enabled' => false, 'epoch' => bin2hex(random_bytes(16)), 'started' => 0]
            : [
                'enabled' => false,
                'epoch' => bin2hex(random_bytes(16)),
                'revision' => bin2hex(random_bytes(16)),
                'values' => [],
            ];
    return pagenest_reading_sync_store($user, $kind, $state);
}
function pagenest_reading_sync_status(int $user)
{
    $automatic = pagenest_reading_sync_state($user, 'automatic');
    $preferences = pagenest_reading_sync_state($user, 'preferences');
    if (is_wp_error($automatic)) {
        return $automatic;
    }
    if (is_wp_error($preferences)) {
        return $preferences;
    }
    if (!pagenest_account_reading_enabled()) {
        $preferences['values'] = [];
    }
    $reading = pagenest_account_reading_state($user);
    if (is_wp_error($reading)) {
        return $reading;
    }
    return [
        'available' => pagenest_account_reading_enabled(),
        'readingEnabled' => $reading['enabled'],
        'automatic' => $automatic,
        'preferences' => $preferences,
    ];
}
function pagenest_reading_sync_write(int $user, string $action, array $input)
{
    if (
        is_multisite() &&
        !in_array($action, ['automatic/withdraw', 'preferences/withdraw'], true)
    ) {
        return pagenest_account_reading_error('unsupported', 403);
    }
    return pagenest_reading_sync_transaction($user, static function () use (
        $user,
        $action,
        $input,
    ) {
        global $wpdb;
        if (
            !in_array($action, ['automatic/withdraw', 'preferences/withdraw'], true) &&
            !pagenest_account_reading_enabled()
        ) {
            return pagenest_account_reading_error('disabled', 403);
        }
        if (str_starts_with($action, 'preferences/')) {
            $state = pagenest_reading_sync_state($user, 'preferences');
            if (is_wp_error($state)) {
                return $state;
            }
            if ($state['revision'] !== $input['revision']) {
                return pagenest_account_reading_error('conflict', 409);
            }
            if ($action === 'preferences/withdraw') {
                return pagenest_reading_sync_revoke($user, 'preferences');
            }
            if ($action === 'preferences/consent') {
                if ($state['enabled']) {
                    return $state;
                }
                if (!$state['enabled']) {
                    $state['enabled'] = true;
                    $state['epoch'] = bin2hex(random_bytes(16));
                }
            } elseif ($action === 'preferences/update') {
                if (!$state['enabled'] || $state['epoch'] !== $input['epoch']) {
                    return pagenest_account_reading_error('conflict', 409);
                }
                $state['values'] = array_replace($state['values'], $input['values']);
            } else {
                return pagenest_account_reading_error('invalid', 400);
            }
            $state['revision'] = bin2hex(random_bytes(16));
            return pagenest_reading_sync_store($user, 'preferences', $state);
        }
        $automatic = pagenest_reading_sync_state($user, 'automatic');
        if (is_wp_error($automatic)) {
            return $automatic;
        }
        if ($automatic['epoch'] !== $input['epoch']) {
            return pagenest_account_reading_error('conflict', 409);
        }
        if ($action === 'automatic/withdraw') {
            return pagenest_reading_sync_revoke($user, 'automatic');
        }
        $reading = pagenest_account_reading_state($user);
        if (is_wp_error($reading)) {
            return $reading;
        }
        if ($action === 'automatic/consent') {
            if ($input['enabled'] && !$reading['enabled']) {
                return pagenest_account_reading_error('consent', 403);
            }
            if (!$input['enabled']) {
                return pagenest_reading_sync_revoke($user, 'automatic');
            }
            if (!$automatic['enabled']) {
                $automatic = [
                    'enabled' => true,
                    'epoch' => bin2hex(random_bytes(16)),
                    'started' => (int) (microtime(true) * 1000),
                ];
            }
            return pagenest_reading_sync_store($user, 'automatic', $automatic);
        }
        if ($action !== 'automatic/progress') {
            return pagenest_account_reading_error('invalid', 400);
        }
        if (!$automatic['enabled'] || !$reading['enabled']) {
            return pagenest_account_reading_error('conflict', 409);
        }
        // A realtime delta has no client timestamp and can never carry a historical list.
        $item = $input['item'];
        $item['time'] = (int) (microtime(true) * 1000);
        $item = [
            'id' => $item['id'],
            'time' => $item['time'],
            'later' => $item['later'],
            'anchor' => $item['anchor'],
        ];
        $incoming = pagenest_account_reading_project([$item], false);
        if (is_wp_error($incoming)) {
            return $incoming;
        }
        if (!$incoming) {
            return pagenest_account_reading_error('source', 403);
        }
        $stored = pagenest_account_reading_project($reading['items'], false);
        if (is_wp_error($stored)) {
            return $stored;
        }
        $reading['items'] = pagenest_account_reading_merge($stored, $incoming);
        $reading['revision'] = bin2hex(random_bytes(16));
        update_user_meta($user, '_pagenest_account_reading', wp_slash($reading));
        if ($wpdb->last_error !== '') {
            return pagenest_account_reading_error();
        }
        wp_cache_delete($user, 'user_meta');
        if (pagenest_account_reading_state($user) !== $reading) {
            return pagenest_account_reading_error();
        }
        return ['epoch' => $automatic['epoch'], 'revision' => $reading['revision']];
    });
}
function pagenest_reading_sync_request($request, string $action)
{
    if (strlen($request->get_body()) > 5000) {
        return pagenest_account_reading_error('invalid', 413);
    }
    $input = $request->get_json_params();
    $allowed = [
        'automatic/withdraw' => ['epoch'],
        'automatic/consent' => ['epoch', 'enabled'],
        'automatic/progress' => ['epoch', 'item'],
        'preferences/consent' => ['revision'],
        'preferences/update' => ['revision', 'epoch', 'values'],
        'preferences/withdraw' => ['revision'],
    ][$action];
    if (
        !is_array($input) ||
        count($input) !== count($allowed) ||
        array_diff(array_keys($input), $allowed)
    ) {
        return pagenest_account_reading_error('invalid', 400);
    }
    foreach (['epoch', 'revision'] as $name) {
        if (
            in_array($name, $allowed, true) &&
            (!is_string($input[$name]) || !preg_match('/^(?:[a-f0-9]{32})?$/D', $input[$name]))
        ) {
            return pagenest_account_reading_error('invalid', 400);
        }
    }
    if ($action === 'automatic/consent' && !is_bool($input['enabled'])) {
        return pagenest_account_reading_error('invalid', 400);
    }
    if ($action === 'automatic/progress') {
        $item = $input['item'];
        if (
            !is_array($item) ||
            count($item) !== 3 ||
            array_diff(array_keys($item), ['id', 'later', 'anchor']) ||
            !is_int($item['id'] ?? null) ||
            $item['id'] < 1 ||
            !is_bool($item['later'] ?? null) ||
            !is_string($item['anchor'] ?? null) ||
            strlen($item['anchor']) > 800
        ) {
            return pagenest_account_reading_error('invalid', 400);
        }
    }
    if (
        $action === 'preferences/update' &&
        is_wp_error(pagenest_reading_sync_values($input['values']))
    ) {
        return pagenest_account_reading_error('invalid', 400);
    }
    $result = pagenest_reading_sync_write(get_current_user_id(), $action, $input);
    if (!is_wp_error($result)) {
        $result['available'] = pagenest_account_reading_enabled();
        if (!$result['available'] && isset($result['values'])) {
            $result['values'] = [];
        }
    }
    return $result;
}
add_action('rest_api_init', static function () {
    register_rest_route('pagenest/v1', '/reading/account/sync', [
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
            return pagenest_reading_sync_status(get_current_user_id());
        },
    ]);
    foreach (
        [
            'automatic/consent',
            'automatic/withdraw',
            'automatic/progress',
            'preferences/consent',
            'preferences/update',
            'preferences/withdraw',
        ]
        as $action
    ) {
        register_rest_route('pagenest/v1', '/reading/account/' . $action, [
            'methods' => 'POST',
            'permission_callback' => 'pagenest_account_reading_permission',
            'callback' => static fn($request) => pagenest_reading_sync_request($request, $action),
        ]);
    }
});
function pagenest_reading_sync_export(int $user)
{
    $data = [];
    foreach (['automatic', 'preferences'] as $kind) {
        if (!metadata_exists('user', $user, '_pagenest_reading_' . $kind)) {
            continue;
        }
        $state = pagenest_reading_sync_state($user, $kind);
        if (is_wp_error($state)) {
            return $state;
        }
        $values = [['name' => 'enabled', 'value' => $state['enabled'] ? 'true' : 'false']];
        if ($kind === 'automatic') {
            $values[] = ['name' => 'started', 'value' => (string) $state['started']];
        } else {
            foreach ($state['values'] as $name => $value) {
                $values[] = ['name' => $name, 'value' => $value];
            }
        }
        $data[] = [
            'group_id' => 'pagenest-account-reading',
            'group_label' => __('Account reading', 'pagenest-companion'),
            'item_id' => 'pagenest-reading-' . $kind,
            'data' => $values,
        ];
    }
    return $data;
}
