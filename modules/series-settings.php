<?php
/** Series directory relationships are independent from guide content and article metadata. */
defined('ABSPATH') || exit();
function pagenest_companion_series_directory_revision(): string
{
    return hash('sha256', serialize(get_option('pagenest_companion_series_directories', [])));
}
function pagenest_companion_series_directory_sanitize($input): array
{
    static $accepted = null;
    if ($accepted !== null && $input === $accepted) {
        return $accepted;
    }
    $old = get_option('pagenest_companion_series_directories', []);
    $old = is_array($old) ? $old : [];
    $valid =
        current_user_can('manage_options') &&
        is_array($input) &&
        isset($input['revision'], $input['nonce'], $input['rows']) &&
        is_string($input['revision']) &&
        is_string($input['nonce']) &&
        wp_verify_nonce($input['nonce'], 'pagenest-series-directories') &&
        hash_equals(pagenest_companion_series_directory_revision(), $input['revision']) &&
        is_array($input['rows']) &&
        count($input['rows']) <= 201;
    $out = [];
    $seen = [];
    $server = pagenest_companion_config('chapters', 'series_directories');
    foreach ($valid ? $input['rows'] : [] as $row) {
        if (
            !is_array($row) ||
            !isset($row['series'], $row['page']) ||
            !is_string($row['series']) ||
            !is_string($row['page'])
        ) {
            $valid = false;
            break;
        }
        $series = $row['series'];
        $page = $row['page'];
        if (
            isset($row['pick'], $row['original']) &&
            is_string($row['pick']) &&
            is_string($row['original']) &&
            $row['pick'] !== '' &&
            $row['pick'] !== $row['original']
        ) {
            $page = $row['pick'];
        }
        if (isset($seen[$series]) && $series !== '') {
            $valid = false;
            break;
        }
        $seen[$series] = true;
        if ($page === '' || $page === '0') {
            continue;
        }
        if (
            $series === '' ||
            mb_strlen($series) > 100 ||
            preg_match('/[\x00-\x1f\x7f]/u', $series) ||
            !preg_match('/^[1-9][0-9]*$/D', $page) ||
            (string) (int) $page !== $page ||
            isset($out[$series]) ||
            array_key_exists($series, $server)
        ) {
            $valid = false;
            break;
        }
        if (!pagenest_companion_public_directory_branch((int) $page)) {
            $valid = false;
            break;
        }
        $out[$series] = (int) $page;
    }
    foreach ($old as $series => $page) {
        if (array_key_exists($series, $server)) {
            $out[$series] = $page;
        } elseif (!isset($seen[$series])) {
            $valid = false;
        }
    }
    if (!$valid || count($out) > 200) {
        add_settings_error(
            'pagenest_companion_series_directories',
            'series-directory-invalid',
            '系列目录未保存。请刷新页面后重试；系列名称须唯一，目录和上级页面都须公开、无密码且层级有效。服务器托管项不能在此修改。',
        );
        return $old;
    }
    // WordPress sanitizes a newly added option twice in the same request.
    $accepted = $out;
    return $out;
}
add_action('admin_init', static function (): void {
    register_setting('pagenest_companion', 'pagenest_companion_series_directories', [
        'type' => 'array',
        'sanitize_callback' => 'pagenest_companion_series_directory_sanitize',
    ]);
});
/** Shared picker data stays bounded; IDs outside the picker remain supported. */
function pagenest_companion_series_page_choices(): array
{
    $pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'has_password' => false,
        'posts_per_page' => 201,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);
    return array_values(
        array_filter(
            array_slice($pages, 0, 200),
            static fn($page) => (bool) pagenest_companion_public_directory_branch((int) $page->ID),
        ),
    );
}
function pagenest_companion_series_directory_preview($id): void
{
    $branch = is_numeric($id) ? pagenest_companion_public_directory_branch((int) $id) : [];
    echo '<p class="pn-admin-description" data-pn-directory-preview>';
    if ($branch) {
        $target = $branch[count($branch) - 1];
        echo esc_html__('Current directory:', 'pagenest-companion') .
            ' <a href="' .
            esc_url($target['item']) .
            '">' .
            esc_html(implode(' / ', array_column($branch, 'name'))) .
            '</a>';
    } elseif ($id !== '' && (int) $id !== 0) {
        echo esc_html__(
            'Unavailable directory. The saved ID is retained; choose a public page or clear it to unlink.',
            'pagenest-companion',
        );
    } else {
        echo esc_html__('No directory linked.', 'pagenest-companion');
    }
    echo '</p>';
}
function pagenest_companion_series_settings_fields(): void
{
    $stored = get_option('pagenest_companion_series_directories', []);
    $stored = is_array($stored) ? $stored : [];
    $server = pagenest_companion_config('chapters', 'series_directories');
    $pages = pagenest_companion_series_page_choices();
    echo '<section class="pn-admin-section" data-pn-series-directories><h2>' .
        esc_html__('Series directories', 'pagenest-companion') .
        '</h2><p>' .
        esc_html__(
            'Link an exact article series name to a public directory page. Clear its ID to unlink.',
            'pagenest-companion',
        ) .
        '</p>';
    echo '<input type="hidden" name="pagenest_companion_series_directories[revision]" value="' .
        esc_attr(pagenest_companion_series_directory_revision()) .
        '">';
    echo '<input type="hidden" name="pagenest_companion_series_directories[nonce]" value="' .
        esc_attr(wp_create_nonce('pagenest-series-directories')) .
        '">';
    $index = 0;
    foreach (array_merge(array_keys($stored), ['']) as $series) {
        if (array_key_exists($series, $server)) {
            continue;
        }
        $page = $stored[$series] ?? '';
        $prefix = 'pagenest_companion_series_directories[rows][' . $index++ . ']';
        echo '<input type="hidden" name="' .
            esc_attr($prefix . '[original]') .
            '" value="' .
            esc_attr((string) $page) .
            '">';
        echo '<div class="pn-series-directory-row" data-pn-directory-row><div class="pn-admin-field"><label>' .
            esc_html__('Series name', 'pagenest-companion') .
            '<input type="text" maxlength="100" name="' .
            esc_attr($prefix . '[series]') .
            '" value="' .
            esc_attr((string) $series) .
            '"' .
            ($series !== '' ? ' readonly' : '') .
            '></label>';
        echo '<label>' .
            esc_html__('Choose directory by title', 'pagenest-companion') .
            '<select data-pn-directory-picker name="' .
            esc_attr($prefix . '[pick]') .
            '"><option value="">' .
            esc_html__('Choose a page…', 'pagenest-companion') .
            '</option>';
        foreach ($pages as $choice) {
            echo '<option value="' .
                esc_attr($choice->ID) .
                '" data-url="' .
                esc_url(get_permalink($choice)) .
                '"' .
                ((string) $page === (string) $choice->ID ? ' selected' : '') .
                '>' .
                esc_html($choice->post_title . ' (#' . $choice->ID . ')') .
                '</option>';
        }
        echo '</select></label><label>' .
            esc_html__('Directory page ID', 'pagenest-companion') .
            '<input data-pn-directory-id type="number" min="1" step="1" name="' .
            esc_attr($prefix . '[page]') .
            '" value="' .
            esc_attr((string) $page) .
            '"></label></div>';
        pagenest_companion_series_directory_preview($page);
        echo '</div>';
    }
    echo '<p class="pn-admin-description">' .
        esc_html__(
            'The title picker lists up to 200 public pages. You can also enter an ID, including pages outside this list. Without JavaScript, enter the page ID directly. Add one series per save, up to 200.',
            'pagenest-companion',
        ) .
        '</p>';
    if ($server) {
        echo '<h3>' . esc_html__('Server-managed directories', 'pagenest-companion') . '</h3><ul>';
        foreach ($server as $series => $page) {
            echo '<li>' . esc_html((string) $series) . ' · #' . esc_html((string) $page);
            pagenest_companion_series_directory_preview($page);
            echo '</li>';
        }
        echo '</ul><p class="pn-admin-description">' .
            esc_html__(
                'Server-managed links take priority and cannot be edited here.',
                'pagenest-companion',
            ) .
            '</p>';
    }
    $bindings = pagenest_companion_config('chapters', 'document_series');
    if ($bindings) {
        echo '<p class="pn-admin-description">' .
            esc_html(
                sprintf(
                    __(
                        '%d existing documents have server-managed series bindings for directory links only. These bindings do not add chapters to a series.',
                        'pagenest-companion',
                    ),
                    count($bindings),
                ),
            ) .
            '</p>';
    }
    echo '</section>';
}
require_once __DIR__ . '/series-maintenance.php';
