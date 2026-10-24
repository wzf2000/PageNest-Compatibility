<?php
/** Administrator-only state summaries: never print filesystem paths, credentials or private payloads. */
defined('ABSPATH') || exit();
function pagenest_companion_diagnostics(): array
{
    $stored = static fn($key) => get_option($key, null) === null
        ? __('Default', 'pagenest-companion')
        : __('WordPress setting', 'pagenest-companion');
    $rows = [];
    foreach (
        [
            'comments' => __('Paragraph comments', 'pagenest-companion'),
            'chapters' => __('Chapter navigation', 'pagenest-companion'),
            'likes' => __('Likes', 'pagenest-companion'),
        ]
        as $key => $label
    ) {
        $rows[] = [
            $label,
            pagenest_companion_feature($key),
            $stored('pagenest_companion_features'),
            __(
                'Existing object permissions and compatibility profile apply.',
                'pagenest-companion',
            ),
        ];
    }
    $discovery = pagenest_discovery_options();
    $rows[] = [
        __('Public search suggestions', 'pagenest-companion'),
        $discovery['search'],
        $stored('pagenest_companion_discovery'),
        __('Only public, unprotected and discoverable entries are returned.', 'pagenest-companion'),
    ];
    $rows[] = [
        __('Search and sharing metadata', 'pagenest-companion'),
        $discovery['seo'] && !pagenest_seo_external_owner(),
        $stored('pagenest_companion_discovery'),
        pagenest_seo_external_owner()
            ? __('Another SEO plugin owns output.', 'pagenest-companion')
            : __('Per-article overrides inherit site defaults when blank.', 'pagenest-companion'),
    ];
    $rows[] = [
        __('My reading', 'pagenest-companion'),
        pagenest_reading_enabled(),
        $stored('pagenest_companion_reading'),
        __(
            'Device memory needs reader consent. Private records require authentication.',
            'pagenest-companion',
        ),
    ];
    if (function_exists('pagenest_notifications_enabled')) {
        $rows[] = [
            __('Notifications', 'pagenest-companion'),
            pagenest_notifications_enabled(),
            $stored('pagenest_companion_notifications'),
            __(
                'Replies to signed-in readers’ ordinary comments. Email settings are separate.',
                'pagenest-companion',
            ),
        ];
    }
    $rows[] = [
        __('Content guide', 'pagenest-companion'),
        pagenest_guide_options()['enabled'],
        $stored('pagenest_companion_guide'),
        __('Independent of the server-owned content configuration.', 'pagenest-companion'),
    ];
    $media = pagenest_companion_media_config();
    $rows[] = [
        __('Media display', 'pagenest-companion'),
        $media['enabled'],
        $stored('pagenest_companion_media'),
        __(
            'Existing shortcode and block owners take precedence. Attachment access is checked during rendering.',
            'pagenest-companion',
        ),
    ];
    $rows[] = [
        __('Native media authoring', 'pagenest-companion'),
        pagenest_media_authoring_enabled(),
        $stored('pagenest_companion_media_authoring'),
        __(
            'New blocks select existing attachments and do not migrate old blocks.',
            'pagenest-companion',
        ),
    ];
    $rows[] = [
        __('Server content provider', 'pagenest-companion'),
        (bool) array_filter(
            pagenest_companion_content_config('modules', []),
            static fn($module) => !empty($module['enabled']),
        ),
        defined('PAGENEST_COMPANION_CONTENT_FILE')
            ? __('Read-only server configuration', 'pagenest-companion')
            : __('Not configured', 'pagenest-companion'),
        __('No file paths or content payloads are included in diagnostics.', 'pagenest-companion'),
    ];
    if (class_exists('PageNest\\Tools\\Registry')) {
        $rows[] = [
            __('Tool registry', 'pagenest-companion'),
            \PageNest\Tools\Registry::healthy(),
            __('Server configuration', 'pagenest-companion'),
            __(
                'Tools keep their own access controls; this page does not run or repair them.',
                'pagenest-companion',
            ),
        ];
    }
    $rows[] = [
        __('Optional service configuration', 'pagenest-companion'),
        !empty($GLOBALS['pagenest_companion_services_source_valid']),
        defined('PAGENEST_COMPANION_SERVICES_FILE')
            ? __('Server configuration and WordPress setting', 'pagenest-companion')
            : __('WordPress setting or defaults', 'pagenest-companion'),
        __(
            'This is a configuration check only. It does not send mail, authenticate with a provider, or test delivery.',
            'pagenest-companion',
        ),
    ];
    return $rows;
}
/** Only values assembled above from fixed labels and booleans may enter the export. */
function pagenest_companion_diagnostics_export(): array
{
    $headers = get_file_data(dirname(__DIR__) . '/pagenest-compatibility.php', [
        'version' => 'Version',
    ]);
    return [
        'schema_version' => 1,
        'companion_version' => preg_match('/^[0-9A-Za-z.+-]{1,40}$/D', $headers['version'])
            ? $headers['version']
            : '',
        'wordpress_version' => preg_match('/^[0-9A-Za-z.+-]{1,40}$/D', get_bloginfo('version'))
            ? get_bloginfo('version')
            : '',
        'modules' => array_map(
            static fn($row) => [
                'module' => $row[0],
                'available' => (bool) $row[1],
                'source_type' => $row[2],
                'boundary' => $row[3],
            ],
            pagenest_companion_diagnostics(),
        ),
    ];
}
add_action('admin_post_pagenest_diagnostics_export', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Administrator access required.', 'pagenest-companion'), '', [
            'response' => 403,
        ]);
    }
    check_admin_referer('pagenest-diagnostics-export');
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="companion-diagnostics.json"');
    header('X-Content-Type-Options: nosniff');
    echo wp_json_encode(
        pagenest_companion_diagnostics_export(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
    );
    exit();
});
function pagenest_companion_diagnostics_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $query = is_string($_GET['pn_module'] ?? null)
        ? mb_substr(sanitize_text_field(wp_unslash($_GET['pn_module'])), 0, 100)
        : '';
    $status =
        is_string($_GET['pn_status'] ?? null) &&
        in_array($_GET['pn_status'], ['enabled', 'disabled'], true)
            ? $_GET['pn_status']
            : '';
    pagenest_companion_admin_header(
        __('Module diagnostics', 'pagenest-companion'),
        __(
            'Review configuration sources, output ownership and access boundaries without changing data.',
            'pagenest-companion',
        ),
        'pagenest-diagnostics',
    );
    echo '<form method="get" class="pn-diagnostics-filters"><input type="hidden" name="page" value="pagenest-diagnostics"><label for="pn-module-search">' .
        esc_html__('Search modules', 'pagenest-companion') .
        '<input id="pn-module-search" type="search" name="pn_module" maxlength="100" value="' .
        esc_attr($query) .
        '"></label><label for="pn-module-status">' .
        esc_html__('Status', 'pagenest-companion') .
        '<select id="pn-module-status" name="pn_status">';
    foreach (
        [
            '' => __('All statuses', 'pagenest-companion'),
            'enabled' => __('Enabled or available', 'pagenest-companion'),
            'disabled' => __('Disabled or unavailable', 'pagenest-companion'),
        ]
        as $key => $label
    ) {
        echo '<option value="' .
            esc_attr($key) .
            '"' .
            selected($status, $key, false) .
            '>' .
            esc_html($label) .
            '</option>';
    }
    echo '</select></label><button class="button" type="submit">' .
        esc_html__('Filter', 'pagenest-companion') .
        '</button><a class="button" href="' .
        esc_url(
            wp_nonce_url(
                admin_url('admin-post.php?action=pagenest_diagnostics_export'),
                'pagenest-diagnostics-export',
            ),
        ) .
        '">' .
        esc_html__('Export safe JSON', 'pagenest-companion') .
        '</a></form><table class="widefat striped pn-diagnostics-table"><thead><tr><th>' .
        esc_html__('Module', 'pagenest-companion') .
        '</th><th>' .
        esc_html__('Status', 'pagenest-companion') .
        '</th><th>' .
        esc_html__('Configuration source', 'pagenest-companion') .
        '</th><th>' .
        esc_html__('Notes', 'pagenest-companion') .
        '</th></tr></thead><tbody>';
    $count = 0;
    foreach (pagenest_companion_diagnostics() as [$name, $active, $source, $help]) {
        if (
            ($status === 'enabled' && !$active) ||
            ($status === 'disabled' && $active) ||
            ($query !== '' && mb_stripos(implode(' ', [$name, $source, $help]), $query) === false)
        ) {
            continue;
        }
        $count++;
        echo '<tr><th scope="row">' .
            esc_html($name) .
            '</th><td>' .
            esc_html(
                $active
                    ? __('Enabled or available', 'pagenest-companion')
                    : __('Disabled or unavailable', 'pagenest-companion'),
            ) .
            '</td><td>' .
            esc_html($source) .
            '</td><td>' .
            esc_html($help) .
            '</td></tr>';
    }
    if (!$count) {
        echo '<tr><td colspan="4">' .
            esc_html__('No matching modules.', 'pagenest-companion') .
            '</td></tr>';
    }
    echo '</tbody></table><p>' .
        esc_html__(
            'Diagnostics are read-only and administrator-only. The export includes all modules, fixed boundary notes and versions. It excludes paths, URLs, accounts, credentials and user content. Configuration validity is not a live service test.',
            'pagenest-companion',
        ) .
        '</p></div>';
}
add_action('admin_menu', static function () {
    add_options_page(
        __('Module diagnostics', 'pagenest-companion'),
        __('Module diagnostics', 'pagenest-companion'),
        'manage_options',
        'pagenest-diagnostics',
        'pagenest_companion_diagnostics_page',
    );
});
