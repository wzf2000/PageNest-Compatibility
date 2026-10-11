<?php
/** Real taxonomy and attachment checks, restricted to the disposable CI database. */
if (wp_get_environment_type() !== 'local' || !getenv('COMPONENT_LAB_STATE')) {
    throw new RuntimeException('Synthetic test environment required');
}
$checks = [];
$check = static function ($ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks[] = $message;
};
$reject = static function (callable $call, string $message) use ($check): void {
    try {
        $call();
    } catch (InvalidArgumentException | RuntimeException $error) {
        $check(true, $message);
        return;
    }
    $check(false, $message);
};
$before = get_option('pagenest_companion_media', null);
$original_user = get_current_user_id();
$tax = 'fixture_media_folders';
$attachments = [];
$users = [];
$terms = [];
try {
    wp_set_current_user(1);
    $config = [
        'schema_version' => 1,
        'enabled' => true,
        'galleries' => [],
        'library_provider' => 'native',
        'library_taxonomy' => $tax,
    ];
    update_option('pagenest_companion_media', $config);
    register_taxonomy($tax, ['attachment'], ['hierarchical' => true]);
    $external = get_taxonomy($tax);
    pagenest_companion_register_folders();
    $check(
        get_taxonomy($tax) === $external && !pagenest_companion_native_folders(),
        'Existing provider is never replaced',
    );
    unregister_taxonomy($tax);
    unset($GLOBALS['pagenest_native_folders_conflict']);
    pagenest_companion_register_folders();
    $native = pagenest_companion_native_folders();
    $check(
        $native &&
            !$native->public &&
            !$native->show_in_rest &&
            $native->cap->manage_terms === 'manage_options',
        'Native registration is private with administrator management',
    );
    $make_term = static function (string $name, int $parent = 0) use ($tax, &$terms): int {
        $result = wp_insert_term($name, $tax, ['parent' => $parent]);
        if (is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }
        $terms[] = (int) $result['term_id'];
        return (int) $result['term_id'];
    };
    $parent = $make_term('Parent');
    $other = $make_term('Other');
    $child = $make_term('Same name', $parent);
    $sibling = $make_term('Same name', $other);
    $check(
        $child !== $sibling,
        'Same-name folders under different parents retain separate identities',
    );
    $paths = array_column(pagenest_companion_folder_choices($native), 'path', 'id');
    $check(
        $paths[$child] === 'Parent / Same name' && $paths[$sibling] === 'Other / Same name',
        'Folder choices disambiguate parent paths',
    );
    $author = wp_insert_user([
        'user_login' => 'fixture-folder-author',
        'user_pass' => wp_generate_password(),
        'role' => 'author',
    ]);
    $reader = wp_insert_user([
        'user_login' => 'fixture-folder-reader',
        'user_pass' => wp_generate_password(),
        'role' => 'subscriber',
    ]);
    if (is_wp_error($author) || is_wp_error($reader)) {
        throw new RuntimeException('Fixture users failed');
    }
    $users = [$author, $reader];
    foreach ([$author, 1, $author] as $owner) {
        $attachments[] = wp_insert_attachment([
            'post_title' => 'Synthetic folder attachment',
            'post_status' => 'inherit',
            'post_author' => $owner,
            'post_mime_type' => 'image/png',
        ]);
    }
    [$a, $b, $empty] = $attachments;
    $members = static function (int $id) use ($tax): array {
        $ids = wp_get_object_terms($id, $tax, ['fields' => 'ids']);
        sort($ids);
        return $ids;
    };
    $result = pagenest_companion_folder_assign([$a, $b], [$parent, $child], 'add');
    $check(
        $result['completed'] === [$a, $b] && !$result['failed'] && count($members($a)) === 2,
        'Multiple folders can be assigned to multiple attachments',
    );
    pagenest_companion_folder_assign([$a], [$other], 'add');
    pagenest_companion_folder_assign([$a], [$child], 'remove');
    $expected = [$parent, $other];
    sort($expected);
    $check($members($a) === $expected, 'Add/remove retains unrelated memberships');
    $result = pagenest_companion_folder_assign([$a], [$child], 'remove');
    $check(
        !$result['failed'] && $members($a) === $expected,
        'Removing an absent membership is idempotent',
    );
    $reject(
        static fn() => pagenest_companion_folder_assign([$a], [PHP_INT_MAX], 'add'),
        'Unknown folder is rejected without creating a term',
    );
    $reject(
        static fn() => pagenest_companion_folder_assign([$a, PHP_INT_MAX], [$child], 'add'),
        'Invalid attachment rejects complete selection before writing',
    );
    $check($members($a) === $expected, 'Rejected selection preserves previous memberships');
    wp_set_current_user($author);
    $reject(
        static fn() => pagenest_companion_folder_assign([$a, $b], [$child], 'add'),
        'Author cannot change another user attachment',
    );
    $check($members($a) === $expected, 'Mixed authorized/forbidden batch writes nothing');
    $result = pagenest_companion_folder_assign([$a], [$child], 'add');
    $check(
        !$result['failed'] && !current_user_can($native->cap->manage_terms),
        'Author may assign own attachment but cannot manage folders',
    );
    wp_set_current_user($reader);
    $reject(
        static fn() => pagenest_companion_folder_assign([$a], [$other], 'remove'),
        'Subscriber cannot assign folders',
    );
    wp_set_current_user(1);
    $base = [
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post__in' => $attachments,
    ];
    $slug = get_term($child, $tax)->slug;
    $found = get_posts(pagenest_companion_folder_query($base, $slug));
    sort($found);
    $expected = [$a, $b];
    sort($expected);
    $check($found === $expected, 'Real media query selects exact folder');
    $found = get_posts(pagenest_companion_folder_query($base, '-1'));
    $check($found === [$empty], 'Unassigned filter finds only unassigned attachments');
    $restricted = $base + [
        'tax_query' => [
            [
                'taxonomy' => $tax,
                'field' => 'term_id',
                'terms' => [$other],
                'include_children' => false,
            ],
        ],
    ];
    $check(
        get_posts(pagenest_companion_folder_query($restricted, $slug)) === [$a],
        'Folder query intersects existing restrictions',
    );
    $check(
        get_posts(pagenest_companion_folder_query($base, ['bad'])) === [] &&
            get_posts(pagenest_companion_folder_query($base, 'missing')) === [],
        'Invalid or unknown filter cannot return all attachments',
    );
    $identity = get_term($child, $tax)->term_taxonomy_id;
    wp_update_term($child, $tax, ['name' => 'Renamed', 'parent' => $other]);
    $check(
        get_term($child, $tax)->term_taxonomy_id === $identity &&
            in_array($child, $members($a), true),
        'Rename and reparent preserve identity and memberships',
    );
    wp_delete_term($child, $tax);
    $check(
        get_post_type($a) === 'attachment' &&
            get_post_type($b) === 'attachment' &&
            in_array($parent, $members($a), true),
        'Deleting folder preserves files and other assignments',
    );
    $_GET = ['ids' => (string) $a];
    ob_start();
    pagenest_companion_folder_assignment_page();
    $html = ob_get_clean();
    $check(
        str_contains($html, '_wpnonce') && str_contains($html, 'terms[]'),
        'Assignment form includes nonce and explicit choices',
    );
    $reject(
        static fn() => pagenest_companion_folder_selection('1,0'),
        'Invalid selection is rejected',
    );
    $check(
        pagenest_companion_folder_selection('1,2,1') === [1, 2],
        'Valid selection is deduplicated',
    );
} finally {
    wp_set_current_user(1);
    foreach ($attachments as $id) {
        wp_delete_attachment($id, true);
    }
    foreach ($terms as $id) {
        wp_delete_term($id, $tax);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $id) {
        wp_delete_user($id);
    }
    unregister_taxonomy($tax);
    unset($GLOBALS['pagenest_native_folders'], $GLOBALS['pagenest_native_folders_conflict']);
    if ($before === null) {
        delete_option('pagenest_companion_media');
    } else {
        update_option('pagenest_companion_media', $before);
    }
    wp_set_current_user($original_user);
}
echo wp_json_encode(['checks' => $checks], JSON_PRETTY_PRINT) . "\n";
