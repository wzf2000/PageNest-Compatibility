<?php
/** Explicit native media organization. Existing taxonomy identities remain unchanged. */
defined('ABSPATH') || exit();

function pagenest_companion_folder_text(string $english, string $chinese): string
{
    return str_starts_with(determine_locale(), 'zh')
        ? $chinese
        : __($english, 'pagenest-companion');
}

function pagenest_companion_native_folders(): ?object
{
    $name = $GLOBALS['pagenest_native_folders'] ?? '';
    $taxonomy = $name ? get_taxonomy($name) : false;
    return $taxonomy ?: null;
}

function pagenest_companion_register_folders(): void
{
    $config = pagenest_companion_media_config();
    if (!$config['enabled'] || ($config['library_provider'] ?? 'external') !== 'native') {
        return;
    }
    $name = $config['library_taxonomy'];
    // Never replace another provider, even if it uses the same storage identifier.
    if (($GLOBALS['pagenest_native_folders'] ?? '') === $name && taxonomy_exists($name)) {
        return;
    }
    if (taxonomy_exists($name)) {
        $GLOBALS['pagenest_native_folders_conflict'] = true;
        return;
    }
    $label = pagenest_companion_folder_text('Media folders', '媒体文件夹');
    $result = register_taxonomy(
        $name,
        ['attachment'],
        [
            'label' => $label,
            'labels' => ['name' => $label, 'singular_name' => $label],
            'hierarchical' => true,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_rest' => false,
            'show_in_quick_edit' => false,
            'meta_box_cb' => false,
            'query_var' => false,
            'rewrite' => false,
            'update_count_callback' => '_update_generic_term_count',
            'capabilities' => [
                'manage_terms' => 'manage_options',
                'edit_terms' => 'manage_options',
                'delete_terms' => 'manage_options',
                'assign_terms' => 'upload_files',
            ],
        ],
    );
    if (!is_wp_error($result)) {
        $GLOBALS['pagenest_native_folders'] = $name;
    }
}
add_action('init', 'pagenest_companion_register_folders', 1100);

add_action('admin_notices', static function (): void {
    if (
        !empty($GLOBALS['pagenest_native_folders_conflict']) &&
        current_user_can('manage_options')
    ) {
        echo '<div class="notice notice-warning"><p>' .
            esc_html(
                pagenest_companion_folder_text(
                    'Another plugin owns the configured media folders. Companion has not taken ownership. Disable that provider only after validating a backup and the existing folder mappings.',
                    '其他插件仍在管理所配置的媒体文件夹，Companion 尚未接管。请先验证备份和现有目录映射，再停用原管理插件。',
                ),
            ) .
            '</p></div>';
    }
});

/** Exact folder only, or unassigned. Preserve every other query restriction. */
function pagenest_companion_folder_query(array $query, $value): array
{
    $tax = pagenest_companion_native_folders();
    if (!$tax || !current_user_can('upload_files') || $value === '' || $value === null) {
        return $query;
    }
    if (!is_string($value) || strlen($value) > 200) {
        $query['post__in'] = [0];
        return $query;
    }
    if ($value === '-1') {
        $clause = ['taxonomy' => $tax->name, 'operator' => 'NOT EXISTS'];
    } else {
        $term = get_term_by('slug', $value, $tax->name);
        if (!$term || is_wp_error($term)) {
            $query['post__in'] = [0];
            return $query;
        }
        $clause = [
            'taxonomy' => $tax->name,
            'field' => 'term_id',
            'terms' => [(int) $term->term_id],
            'include_children' => false,
        ];
    }
    $query['tax_query'] = empty($query['tax_query'])
        ? [$clause]
        : ['relation' => 'AND', $query['tax_query'], $clause];
    return $query;
}
add_filter('ajax_query_attachments_args', static function (array $query): array {
    $tax = pagenest_companion_native_folders();
    $input =
        isset($_REQUEST['query']) && is_array($_REQUEST['query'])
            ? wp_unslash($_REQUEST['query'])
            : [];
    return $tax ? pagenest_companion_folder_query($query, $input[$tax->name] ?? '') : $query;
});
add_action('pre_get_posts', static function ($query): void {
    if (
        !is_admin() ||
        !$query->is_main_query() ||
        $query->get('post_type') !== 'attachment' ||
        !isset($_GET['pagenest_folder'])
    ) {
        return;
    }
    foreach (
        pagenest_companion_folder_query($query->query_vars, wp_unslash($_GET['pagenest_folder']))
        as $key => $value
    ) {
        $query->set($key, $value);
    }
});
add_action('restrict_manage_posts', static function ($type): void {
    $tax = pagenest_companion_native_folders();
    if ($type !== 'attachment' || !$tax || !current_user_can('upload_files')) {
        return;
    }
    $current =
        isset($_GET['pagenest_folder']) && is_string($_GET['pagenest_folder'])
            ? wp_unslash($_GET['pagenest_folder'])
            : '';
    echo '<select name="pagenest_folder" aria-label="' . esc_attr($tax->label) . '">';
    foreach (
        [
            '' => pagenest_companion_folder_text('All folders', '全部文件夹'),
            '-1' => pagenest_companion_folder_text('Unassigned', '未分类'),
        ]
        as $value => $label
    ) {
        echo '<option value="' .
            esc_attr((string) $value) .
            '" ' .
            selected($current, (string) $value, false) .
            '>' .
            esc_html($label) .
            '</option>';
    }
    foreach (pagenest_companion_folder_choices($tax) as $term) {
        echo '<option value="' .
            esc_attr($term['slug']) .
            '" ' .
            selected($current, $term['slug'], false) .
            '>' .
            esc_html($term['path']) .
            '</option>';
    }
    echo '</select>';
});

function pagenest_companion_folder_choices(object $tax): array
{
    $terms = pagenest_companion_media_library_terms($tax);
    $by_id = array_column($terms, null, 'id');
    foreach ($terms as &$term) {
        $names = [$term['name']];
        $seen = [$term['id'] => true];
        $parent = $term['parent'];
        while ($parent && isset($by_id[$parent]) && !isset($seen[$parent])) {
            $seen[$parent] = true;
            array_unshift($names, $by_id[$parent]['name']);
            $parent = $by_id[$parent]['parent'];
        }
        $term['path'] = implode(' / ', $names);
    }
    unset($term);
    return $terms;
}

add_action('wp_enqueue_media', static function (): void {
    $tax = pagenest_companion_native_folders();
    if (!$tax || !current_user_can('upload_files')) {
        return;
    }
    $root = dirname(__DIR__, 2);
    $manifest = require $root . '/assets/manifest.php';
    wp_enqueue_style(
        'pagenest-media-folders',
        plugins_url(
            'assets/' . $manifest['media_folders_css'],
            $root . '/pagenest-compatibility.php',
        ),
        ['media-views'],
        null,
    );
    wp_enqueue_script(
        'pagenest-media-folders',
        plugins_url(
            'assets/' . $manifest['media_folders_js'],
            $root . '/pagenest-compatibility.php',
        ),
        ['media-views'],
        null,
        true,
    );
    wp_localize_script('pagenest-media-folders', 'pagenestNativeFolders', [
        'taxonomy' => $tax->name,
        'terms' => pagenest_companion_folder_choices($tax),
        'assignUrl' => admin_url('upload.php?page=pagenest-media-assign'),
        'labels' => [
            'all' => pagenest_companion_folder_text('All folders', '全部文件夹'),
            'unassigned' => pagenest_companion_folder_text('Unassigned', '未分类'),
            'assign' => pagenest_companion_folder_text('Assign folders', '分配文件夹'),
        ],
    ]);
});

/** Validate the complete selection before any relation changes. No replacement writes. */
function pagenest_companion_folder_assign(array $ids, array $terms, string $operation): array
{
    $tax = pagenest_companion_native_folders();
    if (!$tax || !current_user_can('upload_files') || !current_user_can($tax->cap->assign_terms)) {
        throw new RuntimeException('Forbidden');
    }
    $ids = pagenest_companion_media_ids($ids);
    $terms = pagenest_companion_media_ids($terms);
    if (
        !$ids ||
        count($ids) > 200 ||
        !$terms ||
        count($terms) > 200 ||
        !in_array($operation, ['add', 'remove'], true)
    ) {
        throw new InvalidArgumentException('Invalid selection');
    }
    foreach ($ids as $id) {
        if (get_post_type($id) !== 'attachment' || !current_user_can('edit_post', $id)) {
            throw new RuntimeException('Forbidden attachment');
        }
    }
    foreach ($terms as $id) {
        $term = get_term($id, $tax->name);
        if (!$term || is_wp_error($term)) {
            throw new InvalidArgumentException('Unknown folder');
        }
    }
    $completed = [];
    foreach ($ids as $id) {
        // Recheck object authorization directly before writing.
        if (!current_user_can('edit_post', $id)) {
            return ['completed' => $completed, 'failed' => $id];
        }
        $result =
            $operation === 'add'
                ? wp_set_object_terms($id, $terms, $tax->name, true)
                : wp_remove_object_terms($id, $terms, $tax->name);
        $remaining = wp_get_object_terms($id, $tax->name, ['fields' => 'ids']);
        if (
            is_wp_error($result) ||
            is_wp_error($remaining) ||
            ($operation === 'add'
                ? array_diff($terms, $remaining)
                : array_intersect($terms, $remaining))
        ) {
            return ['completed' => $completed, 'failed' => $id];
        }
        $completed[] = $id;
    }
    return ['completed' => $completed, 'failed' => null];
}

function pagenest_companion_folder_selection($input): array
{
    if (!is_string($input) || !preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*){0,199}$/D', $input)) {
        throw new InvalidArgumentException('Invalid attachment selection');
    }
    return pagenest_companion_media_ids(array_map('intval', explode(',', $input)));
}

add_action('admin_menu', static function (): void {
    if (pagenest_companion_native_folders()) {
        $hook = add_submenu_page(
            'upload.php',
            pagenest_companion_folder_text('Assign folders', '分配文件夹'),
            pagenest_companion_folder_text('Assign folders', '分配文件夹'),
            'upload_files',
            'pagenest-media-assign',
            'pagenest_companion_folder_assignment_page',
        );
        add_action('load-' . $hook, static function (): void {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                check_admin_referer('pagenest-folder-assign');
            }
        });
    }
});
add_filter('bulk_actions-upload', static function (array $actions): array {
    if (pagenest_companion_native_folders()) {
        $actions['pagenest_assign_folders'] = pagenest_companion_folder_text(
            'Assign folders',
            '分配文件夹',
        );
    }
    return $actions;
});
add_filter(
    'handle_bulk_actions-upload',
    static function ($redirect, $action, $ids) {
        return $action === 'pagenest_assign_folders' && pagenest_companion_native_folders()
            ? add_query_arg(
                [
                    'page' => 'pagenest-media-assign',
                    'ids' => implode(',', array_map('intval', $ids)),
                ],
                admin_url('upload.php'),
            )
            : $redirect;
    },
    10,
    3,
);
add_filter(
    'attachment_fields_to_edit',
    static function (array $fields, $post): array {
        $tax = pagenest_companion_native_folders();
        if (
            !$tax ||
            !current_user_can('upload_files') ||
            !current_user_can('edit_post', $post->ID)
        ) {
            return $fields;
        }
        $names = wp_get_object_terms($post->ID, $tax->name, ['fields' => 'names']);
        $url = add_query_arg(
            ['page' => 'pagenest-media-assign', 'ids' => $post->ID],
            admin_url('upload.php'),
        );
        $fields['pagenest_folders'] = [
            'label' => $tax->label,
            'input' => 'html',
            'html' =>
                '<p>' .
                esc_html(is_wp_error($names) ? '' : implode(', ', $names)) .
                '</p><a target="_blank" rel="noopener" href="' .
                esc_url($url) .
                '">' .
                esc_html(pagenest_companion_folder_text('Assign folders', '分配文件夹')) .
                '</a>',
        ];
        return $fields;
    },
    10,
    2,
);

function pagenest_companion_folder_assignment_page(): void
{
    $tax = pagenest_companion_native_folders();
    if (!$tax || !current_user_can('upload_files')) {
        wp_die('Forbidden', '', ['response' => 403]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        check_admin_referer('pagenest-folder-assign');
    }
    echo '<div class="wrap"><h1>' .
        esc_html(pagenest_companion_folder_text('Assign media folders', '分配媒体文件夹')) .
        '</h1>';
    try {
        $ids = pagenest_companion_folder_selection(
            wp_unslash($_POST['ids'] ?? ($_GET['ids'] ?? '')),
        );
        foreach ($ids as $id) {
            if (get_post_type($id) !== 'attachment' || !current_user_can('edit_post', $id)) {
                throw new RuntimeException('Forbidden attachment');
            }
        }
    } catch (Throwable $error) {
        echo '<p>' .
            esc_html(
                pagenest_companion_folder_text(
                    'Select up to 200 attachments in the media grid or list, then choose Assign folders.',
                    '请先在媒体网格或列表中选择不超过 200 个附件，再选择“分配文件夹”。',
                ),
            ) .
            '</p></div>';
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        try {
            $raw = $_POST['terms'] ?? [];
            if (
                !is_array($raw) ||
                count($raw) > 200 ||
                array_filter(
                    $raw,
                    static fn($v) => !is_string($v) || !preg_match('/^[1-9][0-9]*$/D', $v),
                )
            ) {
                throw new InvalidArgumentException('Invalid folders');
            }
            $result = pagenest_companion_folder_assign(
                $ids,
                array_map('intval', $raw),
                is_string($_POST['operation'] ?? null) ? $_POST['operation'] : '',
            );
            $message = $result['failed']
                ? pagenest_companion_folder_text(
                    'Some attachments could not be updated. Completed changes remain; review the folders and retry.',
                    '部分附件未能更新，已完成的修改保留；请检查目录归属后重试。',
                )
                : pagenest_companion_folder_text(
                    'Folder assignments saved. Media files were not moved.',
                    '目录归属已保存，媒体文件未移动。',
                );
            echo '<div class="notice notice-' .
                ($result['failed'] ? 'error' : 'success') .
                '"><p>' .
                esc_html($message) .
                '</p></div>';
        } catch (Throwable $error) {
            echo '<div class="notice notice-error"><p>' .
                esc_html(
                    pagenest_companion_folder_text(
                        'Unable to update folders. Check your selection and permissions.',
                        '无法更新文件夹，请检查所选附件、目录和权限。',
                    ),
                ) .
                '</p></div>';
        }
    }
    echo '<p>' .
        esc_html(
            pagenest_companion_folder_text(
                'Add or remove only the selected folders; other assignments remain. Refresh an already open media picker after saving.',
                '仅增加或移除所选目录，其他归属保持不变。保存后请刷新已打开的媒体选择器。',
            ),
        ) .
        '</p><ul>';
    foreach ($ids as $id) {
        $names = wp_get_object_terms($id, $tax->name, ['fields' => 'names']);
        echo '<li>' .
            esc_html(get_the_title($id)) .
            ' — ' .
            esc_html(is_wp_error($names) ? '' : implode(', ', $names)) .
            '</li>';
    }
    echo '</ul><form method="post">';
    wp_nonce_field('pagenest-folder-assign');
    echo '<input type="hidden" name="ids" value="' .
        esc_attr(implode(',', $ids)) .
        '"><fieldset><legend>' .
        esc_html($tax->label) .
        '</legend>';
    foreach (pagenest_companion_folder_choices($tax) as $term) {
        echo '<p><label><input type="checkbox" name="terms[]" value="' .
            (int) $term['id'] .
            '"> ' .
            esc_html($term['path']) .
            '</label></p>';
    }
    echo '</fieldset><p><label><select name="operation"><option value="add">' .
        esc_html(pagenest_companion_folder_text('Add selected folders', '增加所选目录归属')) .
        '</option><option value="remove">' .
        esc_html(pagenest_companion_folder_text('Remove selected folders', '移除所选目录归属')) .
        '</option></select></label></p>';
    submit_button();
    echo '</form></div>';
}
