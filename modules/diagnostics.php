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
function pagenest_companion_diagnostics_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    pagenest_companion_admin_header(
        __('Module diagnostics', 'pagenest-companion'),
        __(
            'Review configuration sources, output ownership and access boundaries without changing data.',
            'pagenest-companion',
        ),
        'pagenest-diagnostics',
    );
    echo '<table class="widefat striped"><thead><tr><th>' .
        esc_html__('Module', 'pagenest-companion') .
        '</th><th>' .
        esc_html__('Status', 'pagenest-companion') .
        '</th><th>' .
        esc_html__('Configuration source', 'pagenest-companion') .
        '</th><th>' .
        esc_html__('Notes', 'pagenest-companion') .
        '</th></tr></thead><tbody>';
    foreach (pagenest_companion_diagnostics() as [$name, $active, $source, $help]) {
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
    echo '</tbody></table><p>' .
        esc_html__(
            'Diagnostics are read-only and administrator-only. Configuration validity is not a live service test. No secrets, account identifiers, private record contents or filesystem paths are shown.',
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
