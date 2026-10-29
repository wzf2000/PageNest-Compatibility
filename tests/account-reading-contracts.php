<?php
/** In-memory logic checks; transaction races are covered only by the disposable WordPress lab. */
require __DIR__ . '/reading-contracts.php';
$user_meta = [];
$multisite = false;
function is_multisite()
{
    return $GLOBALS['multisite'];
}
function wp_verify_nonce($nonce, $action)
{
    return $nonce === 'synthetic';
}
function get_user_by($field, $value)
{
    return $field === 'email' && $value === 'reader@example.test' ? (object) ['ID' => 1] : false;
}
class AccountReadingMemoryDB
{
    public $users = 'users',
        $usermeta = 'usermeta',
        $last_error = '',
        $snapshot = [],
        $fail = false;
    public function prepare($sql, ...$args)
    {
        return $sql;
    }
    public function get_var($sql)
    {
        $this->last_error = '';
        return str_contains($sql, 'ENGINE') ? 'InnoDB' : get_current_user_id();
    }
    public function query($sql)
    {
        $this->last_error = '';
        if ($sql === 'START TRANSACTION') {
            $this->snapshot = $GLOBALS['user_meta'];
        }
        if ($sql === 'ROLLBACK') {
            $GLOBALS['user_meta'] = $this->snapshot;
        }
        return true;
    }
}
$wpdb = new AccountReadingMemoryDB();
function get_user_meta($user, $key, $single = false)
{
    $rows = $GLOBALS['user_meta'][$user][$key] ?? [];
    return $single ? $rows[0] ?? '' : $rows;
}
function update_user_meta($user, $key, $value)
{
    if ($GLOBALS['wpdb']->fail) {
        return false;
    }
    $GLOBALS['user_meta'][$user][$key] = [$value];
    return true;
}
function wp_cache_delete(...$args) {}
function wp_slash($value)
{
    return $value;
}
$now = (int) (microtime(true) * 1000);
$item = ['id' => 1, 'time' => $now, 'later' => false, 'anchor' => 'stable'];
check('account switch separately defaults off', !pagenest_account_reading_enabled());
check(
    'empty read creates no account state',
    pagenest_account_reading_state(1)['revision'] === '' && !$user_meta,
);
check('duplicate IDs rejected', is_wp_error(pagenest_account_reading_items([$item, $item])));
check(
    'wrong boolean rejected',
    is_wp_error(pagenest_account_reading_items([array_replace($item, ['later' => '1'])])),
);
check(
    'future clock rejected',
    is_wp_error(pagenest_account_reading_items([array_replace($item, ['time' => $now + 600000])])),
);
check(
    'unexpected private fields rejected',
    is_wp_error(pagenest_account_reading_items([$item + ['note' => 'private']])),
);
$options['pagenest_companion_reading'] = $options['pagenest_companion_account_reading'] = '1';
$state = pagenest_account_reading_write(1, 'consent', ['revision' => '']);
check('consent records no uploads', !is_wp_error($state) && $state['enabled'] && !$state['items']);
$state = pagenest_account_reading_write(1, 'merge', [
    'revision' => $state['revision'],
    'items' => [$item, array_replace($item, ['id' => 2])],
]);
check(
    'only public source is retained',
    count($state['items']) === 1 && $state['items'][0]['id'] === 1,
);
$old = $state;
$state = pagenest_account_reading_write(1, 'merge', [
    'revision' => $state['revision'],
    'items' => [array_replace($item, ['time' => $now - 1000, 'later' => true, 'anchor' => 'old'])],
]);
check(
    'newer position and either later flag retained',
    $state['items'][0]['anchor'] === 'stable' && $state['items'][0]['later'],
);
check(
    'stale revision returns conflict',
    pagenest_account_reading_write(1, 'clear', ['revision' => $old['revision']])->data['status'] ===
        409,
);
$wpdb->fail = true;
$failed = pagenest_account_reading_write(1, 'clear', ['revision' => $state['revision']]);
check(
    'failed metadata write rolls back',
    is_wp_error($failed) && pagenest_account_reading_state(1) === $state,
);
$wpdb->fail = false;
$options['pagenest_companion_account_reading'] = '0';
$withdraw = pagenest_account_reading_write(1, 'withdraw', ['revision' => $state['revision']]);
check(
    'withdraw works with feature disabled',
    !is_wp_error($withdraw) && !$withdraw['enabled'] && !$withdraw['items'],
);
check(
    'tombstone rejects stale consent',
    pagenest_account_reading_write(1, 'consent', ['revision' => $state['revision']])->data[
        'status'
    ] === 409,
);
check(
    'invalid anchor clears in projection',
    pagenest_account_reading_project([array_replace($item, ['anchor' => 'bad'])], false)[0][
        'anchor'
    ] === '',
);
$options['pagenest_companion_account_reading'] = '1';
$state = pagenest_account_reading_write(1, 'consent', ['revision' => $withdraw['revision']]);
$state = pagenest_account_reading_write(1, 'merge', [
    'revision' => $state['revision'],
    'items' => [$item],
]);
$multisite = true;
check(
    'multisite hides account tab without disabling device reading',
    !pagenest_account_reading_enabled() &&
        !pagenest_account_reading_offered() &&
        pagenest_reading_enabled(),
);
$account_request = static fn($action = '', $revision = '') => new class ($action, $revision) {
    public function __construct(private $action, private $revision) {}
    public function get_header($name)
    {
        return 'synthetic';
    }
    public function get_route()
    {
        return '/pagenest/v1/reading/account' . ($this->action ? '/' . $this->action : '');
    }
    public function get_method()
    {
        return $this->action ? 'POST' : 'GET';
    }
    public function get_body()
    {
        return json_encode($this->get_json_params());
    }
    public function get_json_params()
    {
        return ['revision' => $this->revision];
    }
};
check(
    'multisite never projects old global IDs in the current site',
    pagenest_account_reading_project([$item])->data['status'] === 403,
);
check(
    'multisite GET rejects old global account projections',
    pagenest_account_reading_permission($account_request())->data['status'] === 403,
);
foreach (['consent', 'merge', 'remove', 'clear'] as $action) {
    check(
        'multisite denies normal ' . $action . ' route',
        pagenest_account_reading_permission($account_request($action))->data['status'] === 403,
    );
    check(
        'multisite denies direct ' . $action . ' write',
        pagenest_account_reading_write(1, $action, [
            'revision' => $state['revision'],
            'items' => [$item],
            'id' => 1,
        ])->data['status'] === 403,
    );
}
check(
    'multisite permits authenticated withdrawal',
    pagenest_account_reading_permission($account_request('withdraw')) === true,
);
$withdrawn = pagenest_account_reading_request(
    $account_request('withdraw', $state['revision']),
    'withdraw',
);
check(
    'multisite withdrawal deletes old items without projection',
    !is_wp_error($withdrawn) &&
        !$withdrawn['enabled'] &&
        !$withdrawn['items'] &&
        !$withdrawn['available'],
);
// Seed old single-site state to model a converted network; privacy only clears this user key.
$user_meta[1]['_pagenest_account_reading'] = [$state];
$erased = pagenest_account_reading_erase('reader@example.test');
check(
    'multisite native privacy erasure still clears old global state',
    $erased['done'] &&
        !pagenest_account_reading_state(1)['enabled'] &&
        !pagenest_account_reading_state(1)['items'],
);
$multisite = false;
echo "Account reading memory contracts passed (no database concurrency claim).\n";
