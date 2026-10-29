<?php
/** In-memory state transitions only; native WordPress separately exercises persistence. */
require __DIR__ . '/account-reading-contracts.php';
$options['pagenest_companion_account_reading'] = '1';
$manual = pagenest_account_reading_state(1);
$auto = pagenest_reading_sync_state(1, 'automatic');
$pref = pagenest_reading_sync_state(1, 'preferences');
check('new automatic consent remains off', !$auto['enabled']);
$denied = pagenest_reading_sync_write(1, 'automatic/consent', [
    'epoch' => $auto['epoch'],
    'enabled' => true,
]);
check(
    'automatic consent never enables manual records',
    is_wp_error($denied) && !pagenest_account_reading_state(1)['enabled'],
);
$pref = pagenest_reading_sync_write(1, 'preferences/consent', ['revision' => $pref['revision']]);
check(
    'preferences consent is independent from manual consent',
    $pref['enabled'] && pagenest_account_reading_state(1) === $manual && !$pref['values'],
);
$pref = pagenest_reading_sync_write(1, 'preferences/update', [
    'revision' => $pref['revision'],
    'epoch' => $pref['epoch'],
    'values' => ['size' => '20'],
]);
check('preference update retains only changed fields', $pref['values'] === ['size' => '20']);
$joined = pagenest_reading_sync_write(1, 'preferences/consent', ['revision' => $pref['revision']]);
check('joining an existing preference consent preserves epoch and revision', $joined === $pref);
$manual = pagenest_account_reading_write(1, 'consent', ['revision' => $manual['revision']]);
$auto = pagenest_reading_sync_write(1, 'automatic/consent', [
    'epoch' => $auto['epoch'],
    'enabled' => true,
]);
$joined = pagenest_reading_sync_write(1, 'automatic/consent', [
    'epoch' => $auto['epoch'],
    'enabled' => true,
]);
check('joining automatic reading preserves its active epoch', $joined === $auto);
$before = (int) (microtime(true) * 1000);
$progress = pagenest_reading_sync_write(1, 'automatic/progress', [
    'epoch' => $auto['epoch'],
    'item' => ['id' => 1, 'later' => true, 'anchor' => 'stable'],
]);
$manual = pagenest_account_reading_state(1);
check(
    'automatic delta uses server time and current item only',
    !is_wp_error($progress) &&
        count($manual['items']) === 1 &&
        $manual['items'][0]['time'] >= $before &&
        $manual['items'][0]['later'],
);
check(
    'position writes do not invalidate preference revision',
    pagenest_reading_sync_state(1, 'preferences') === $pref,
);
$manual = pagenest_account_reading_write(1, 'remove', [
    'revision' => $manual['revision'],
    'id' => 1,
]);
check(
    'removal invalidates automatic epoch and pauses it',
    !$manual['items'] &&
        !pagenest_reading_sync_state(1, 'automatic')['enabled'] &&
        pagenest_reading_sync_state(1, 'automatic')['epoch'] !== $auto['epoch'],
);
$old_progress = pagenest_reading_sync_write(1, 'automatic/progress', [
    'epoch' => $auto['epoch'],
    'item' => ['id' => 1, 'later' => false, 'anchor' => 'stable'],
]);
$old_enable = pagenest_reading_sync_write(1, 'automatic/consent', [
    'epoch' => $auto['epoch'],
    'enabled' => true,
]);
check(
    'old devices cannot resurrect a removed record or consent',
    $old_progress->data['status'] === 409 &&
        $old_enable->data['status'] === 409 &&
        !pagenest_account_reading_state(1)['items'],
);
$auto = pagenest_reading_sync_state(1, 'automatic');
$auto = pagenest_reading_sync_write(1, 'automatic/consent', [
    'epoch' => $auto['epoch'],
    'enabled' => true,
]);
$cleared = pagenest_account_reading_write(1, 'clear', ['revision' => $manual['revision']]);
check(
    'clear also invalidates automatic consent',
    !pagenest_reading_sync_state(1, 'automatic')['enabled'] &&
        pagenest_reading_sync_state(1, 'automatic')['epoch'] !== $auto['epoch'],
);
check(
    'reading removal and clear preserve independent preferences',
    pagenest_reading_sync_state(1, 'preferences') === $pref,
);
$withdrawn = pagenest_reading_sync_write(1, 'preferences/withdraw', [
    'revision' => $pref['revision'],
]);
check(
    'preference withdrawal clears values and rotates both guards',
    !$withdrawn['enabled'] &&
        !$withdrawn['values'] &&
        $withdrawn['epoch'] !== $pref['epoch'] &&
        $withdrawn['revision'] !== $pref['revision'],
);
check(
    'old preference requests cannot restore revoked consent',
    pagenest_reading_sync_write(1, 'preferences/consent', ['revision' => $pref['revision']])->data[
        'status'
    ] === 409,
);
$auto = pagenest_reading_sync_state(1, 'automatic');
$auto = pagenest_reading_sync_write(1, 'automatic/consent', [
    'epoch' => $auto['epoch'],
    'enabled' => true,
]);
$options['pagenest_companion_account_reading'] = '0';
$stopped = pagenest_reading_sync_write(1, 'automatic/withdraw', ['epoch' => $auto['epoch']]);
check(
    'paused site permits independent automatic withdrawal without deleting manual state',
    !$stopped['enabled'] && pagenest_account_reading_state(1) === $cleared,
);
$options['pagenest_companion_account_reading'] = '1';
$pref = pagenest_reading_sync_write(1, 'preferences/consent', [
    'revision' => $withdrawn['revision'],
]);
$wpdb->fail = true;
$failed = pagenest_reading_sync_write(1, 'preferences/update', [
    'revision' => $pref['revision'],
    'epoch' => $pref['epoch'],
    'values' => ['width' => 'wide'],
]);
check(
    'preference write failure returns storage error and rolls back',
    is_wp_error($failed) && pagenest_reading_sync_state(1, 'preferences') === $pref,
);
$wpdb->fail = false;
$privacy = pagenest_account_reading_erase('reader@example.test');
check(
    'privacy erasure revokes all three record types atomically',
    $privacy['done'] &&
        !pagenest_account_reading_state(1)['enabled'] &&
        !pagenest_reading_sync_state(1, 'automatic')['enabled'] &&
        !pagenest_reading_sync_state(1, 'preferences')['enabled'],
);
check(
    'private or unregistered preference fields rejected',
    is_wp_error(pagenest_reading_sync_values(['appearance' => 'dark'])) &&
        is_wp_error(pagenest_reading_sync_values(['size' => 20])),
);
echo "Automatic reading and independent preference memory contracts passed.\n";
