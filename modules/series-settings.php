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
function pagenest_companion_series_settings_fields(): void
{
    $stored = get_option('pagenest_companion_series_directories', []);
    $stored = is_array($stored) ? $stored : [];
    $server = pagenest_companion_config('chapters', 'series_directories');
    $pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'has_password' => false,
        'posts_per_page' => 200,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);
    echo '<section class="pn-admin-section" data-pn-series-directories><h2>系列目录</h2><p>用文章现有的系列名称关联公开目录页面。同名系列共用一个目录；名称须与文章设置完全一致。清空目录页 ID 可解除关联。</p>';
    echo '<input type="hidden" name="pagenest_companion_series_directories[revision]" value="' .
        esc_attr(pagenest_companion_series_directory_revision()) .
        '">';
    echo '<input type="hidden" name="pagenest_companion_series_directories[nonce]" value="' .
        esc_attr(wp_create_nonce('pagenest-series-directories')) .
        '">';
    $rows = $stored;
    // Keep every stored row, including values not currently visible in the public page picker.
    $index = 0;
    foreach (array_merge(array_keys($rows), ['']) as $series) {
        if (array_key_exists($series, $server)) {
            // Preserve shadowed administrative values without accepting edits to managed keys.
            continue;
        }
        $page = $rows[$series] ?? '';
        $prefix = 'pagenest_companion_series_directories[rows][' . $index++ . ']';
        echo '<div class="pn-admin-field"><label>系列名称<input type="text" maxlength="100" name="' .
            esc_attr($prefix . '[series]') .
            '" value="' .
            esc_attr((string) $series) .
            '"' .
            ($series !== '' ? ' readonly' : '') .
            '></label>';
        echo '<label>目录页 ID<input type="number" min="1" step="1" list="pn-series-public-pages" name="' .
            esc_attr($prefix . '[page]') .
            '" value="' .
            esc_attr((string) $page) .
            '"></label></div>';
    }
    echo '<datalist id="pn-series-public-pages">';
    foreach ($pages as $page) {
        echo '<option value="' .
            esc_attr($page->ID) .
            '">' .
            esc_html($page->post_title) .
            '</option>';
    }
    echo '</datalist><p class="pn-admin-description">输入目录页 ID，或从建议中选择；可关联建议列表之外的公开页面。每次保存可增加一个系列，最多 200 个。</p>';
    if ($server) {
        echo '<h3>服务器配置的关联</h3><ul>';
        foreach ($server as $series => $page) {
            echo '<li>' . esc_html((string) $series) . '：' . esc_html((string) $page) . '</li>';
        }
        echo '</ul><p class="pn-admin-description">这些关联由服务器管理，优先于后台设置。</p>';
        $bindings = pagenest_companion_config('chapters', 'document_series');
        if ($bindings) {
            echo '<p class="pn-admin-description">服务器还为 ' .
                esc_html((string) count($bindings)) .
                ' 个已有文档明确指定了系列，仅用于目录关联。</p>';
        }
    }
    echo '</section>';
}
