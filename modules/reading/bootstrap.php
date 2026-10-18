<?php
/** Opt-in device reading and an in-memory authenticated record center. */
defined('ABSPATH') || exit();
function pagenest_reading_enabled(): bool
{
    return get_option('pagenest_companion_reading', false) === '1';
}
add_action('admin_init', static function () {
    register_setting('pagenest_reading', 'pagenest_companion_reading', [
        'type' => 'string',
        'default' => '0',
        'sanitize_callback' => static fn($value) => current_user_can('manage_options') &&
        $value === '1'
            ? '1'
            : '0',
    ]);
});
add_action('admin_menu', static function () {
    add_options_page(
        __('My reading', 'pagenest-companion'),
        __('My reading', 'pagenest-companion'),
        'manage_options',
        'pagenest-reading',
        static function () {
            if (!current_user_can('manage_options')) {
                return;
            }
            pagenest_companion_admin_header(
                __('My reading', 'pagenest-companion'),
                __(
                    'Offer device reading history and access to the signed-in reader’s own records.',
                    'pagenest-companion',
                ),
                'pagenest-reading',
            );
            echo '<form method="post" action="options.php" class="pn-admin-section">';
            settings_fields('pagenest_reading');
            echo '<label><input type="checkbox" name="pagenest_companion_reading" value="1" ' .
                checked(pagenest_reading_enabled(), true, false) .
                '> ' .
                esc_html__('Enable My reading', 'pagenest-companion') .
                '</label><p>' .
                esc_html__(
                    'Disabled by default. Readers separately choose whether to remember public articles on this device. Reading history is not saved to your account or synced between devices. Article IDs are sent only to check current public access.',
                    'pagenest-companion',
                ) .
                '</p><p>' .
                esc_html__(
                    'Add [pagenest_my_reading] to an existing page or use the PageNest reading entry. No page or menu is created automatically. Private records require sign-in and remain in page memory.',
                    'pagenest-companion',
                ) .
                '</p>';
            submit_button();
            echo '</form></div>';
        },
    );
});
function pagenest_reading_markup(): string
{
    if (!pagenest_reading_enabled()) {
        return '';
    }
    return '<section class="pn-reading" data-pn-reading><noscript>' .
        esc_html__(
            'My reading requires JavaScript for device storage and private record access.',
            'pagenest-companion',
        ) .
        '</noscript><div data-pn-device></div><hr><div data-pn-records></div></section>';
}
add_shortcode('pagenest_my_reading', 'pagenest_reading_markup');
add_action('pagenest_header_actions', static function () {
    if (pagenest_reading_enabled()) {
        echo '<button type="button" data-pn-open-reading hidden>' .
            esc_html__('My reading', 'pagenest-companion') .
            '</button>';
    }
});
add_action('wp_footer', static function () {
    if (!pagenest_reading_enabled() || !current_theme_supports('pagenest-independent-layout')) {
        return;
    }
    echo '<dialog class="pn-reading-dialog" aria-labelledby="pn-reading-dialog-title"><div class="pn-reading-dialog-toolbar"><h2 id="pn-reading-dialog-title">' .
        esc_html__('My reading', 'pagenest-companion') .
        '</h2><button type="button" data-pn-close-reading>' .
        esc_html__('Close', 'pagenest-companion') .
        '</button></div>' .
        pagenest_reading_markup() .
        '</dialog>';
});
add_action('template_redirect', static function () {
    if (!pagenest_reading_enabled()) {
        return;
    }
    $post = get_queried_object();
    if (
        is_user_logged_in() ||
        ($post && is_singular() && has_shortcode($post->post_content, 'pagenest_my_reading'))
    ) {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
    }
});
add_filter(
    'the_content',
    static function ($html) {
        if (
            !pagenest_reading_enabled() ||
            !is_singular(['post', 'page']) ||
            !in_the_loop() ||
            !is_main_query() ||
            !pagenest_discovery_public_post(get_post())
        ) {
            return $html;
        }
        return $html .
            '<div class="pn-reading-article" data-pagenest-generated><button type="button" data-pn-save-later hidden>' .
            esc_html__('Read later on this device', 'pagenest-companion') .
            '</button><span data-pn-later-status role="status"></span></div>';
    },
    99,
);
add_action('rest_api_init', static function () {
    register_rest_route('pagenest/v1', '/reading/resolve', [
        'methods' => 'POST',
        'permission_callback' => static fn() => pagenest_reading_enabled(),
        'callback' => static function ($request) {
            $input = $request->get_json_params();
            $ids = $input['ids'] ?? null;
            if (!is_array($ids) || count($ids) > 100) {
                return new WP_Error(
                    'invalid_ids',
                    __('Choose up to 100 articles.', 'pagenest-companion'),
                    ['status' => 400],
                );
            }
            $items = [];
            foreach (array_unique(array_map('absint', $ids)) as $id) {
                $post = get_post($id);
                if (!pagenest_discovery_public_post($post)) {
                    continue;
                }
                $items[] = [
                    'id' => $id,
                    'title' => pagenest_discovery_text($post->post_title, 180),
                    'url' => get_permalink($post),
                    'headings' => array_column(pagenest_discovery_sections($post), 'id'),
                ];
            }
            $response = new WP_REST_Response(['items' => $items]);
            $response->header('Cache-Control', 'private, no-store, max-age=0');
            return $response;
        },
    ]);
});
add_action('wp_enqueue_scripts', static function () {
    if (!pagenest_reading_enabled()) {
        return;
    }
    $manifest = require dirname(__DIR__, 2) . '/assets/manifest.php';
    $base = dirname(__DIR__, 2) . '/pagenest-compatibility.php';
    wp_enqueue_script(
        'pagenest-reading-center',
        plugins_url('assets/' . $manifest['reading_js'], $base),
        [],
        null,
        true,
    );
    wp_enqueue_style(
        'pagenest-reading-center',
        plugins_url('assets/' . $manifest['reading_css'], $base),
        [],
        null,
    );
    $post = is_singular(['post', 'page']) ? get_queried_object() : null;
    wp_localize_script('pagenest-reading-center', 'PageNestReading', [
        'resolve' => rest_url('pagenest/v1/reading/resolve'),
        'api' => rest_url(pagenest_companion_config('paragraphs', 'rest_namespace') . '/'),
        'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
        'login' => wp_login_url(get_permalink()),
        'current' =>
            $post && pagenest_discovery_public_post($post)
                ? [
                    'id' => (int) $post->ID,
                    'headings' => array_column(pagenest_discovery_sections($post), 'id'),
                ]
                : null,
        'text' => [
            'device' => __('On this device', 'pagenest-companion'),
            'consent' => __(
                'Remember public articles and reading positions on this device',
                'pagenest-companion',
            ),
            'deviceHelp' => __(
                'Optional browser storage. No account history or device sync. Article IDs are checked with the site before display. Reading position does not mark an article as read.',
                'pagenest-companion',
            ),
            'unavailable' => __(
                'Browser storage is unavailable. Device reading is paused.',
                'pagenest-companion',
            ),
            'clear' => __('Clear device records', 'pagenest-companion'),
            'recent' => __('Recent reading', 'pagenest-companion'),
            'later' => __('Read later', 'pagenest-companion'),
            'remove' => __('Remove', 'pagenest-companion'),
            'empty' => __('No records to show.', 'pagenest-companion'),
            'records' => __('My comments and private notes', 'pagenest-companion'),
            'privateHelp' => __(
                'Only your own records are shown. Private text stays in page memory and is cleared when you leave or hide the page.',
                'pagenest-companion',
            ),
            'login' => __('Sign in to view your records', 'pagenest-companion'),
            'load' => __('Load my records', 'pagenest-companion'),
            'more' => __('Load more', 'pagenest-companion'),
            'all' => __('All records', 'pagenest-companion'),
            'public' => __('Public comments', 'pagenest-companion'),
            'author_only' => __('Private notes', 'pagenest-companion'),
            'needs_review' => __('Reference needs review', 'pagenest-companion'),
            'q' => __('Search my text and references', 'pagenest-companion'),
            'search' => __('Search records', 'pagenest-companion'),
            'loading' => __('Loading…', 'pagenest-companion'),
            'error' => __(
                'Could not load records. Sign in again if your session expired.',
                'pagenest-companion',
            ),
            'edit' => __('Edit text', 'pagenest-companion'),
            'save' => __('Save text', 'pagenest-companion'),
            'cancel' => __('Cancel', 'pagenest-companion'),
            'delete' => __('Delete', 'pagenest-companion'),
            'confirmDelete' => __('Delete this record permanently?', 'pagenest-companion'),
            'conflict' => __(
                'The record changed. Reload and compare before saving. Your input is retained in this open page.',
                'pagenest-companion',
            ),
            'location' => __('Open article location', 'pagenest-companion'),
            'reviewHelp' => __(
                'The original paragraph changed or is ambiguous. The saved reference is preserved; review it before choosing a replacement.',
                'pagenest-companion',
            ),
            'relink' => __('Choose a replacement paragraph', 'pagenest-companion'),
            'replace' => __('Replace saved reference', 'pagenest-companion'),
            'choose' => __('Choose a paragraph', 'pagenest-companion'),
            'export' => __('Export my records as JSON', 'pagenest-companion'),
            'import' => __('Import a JSON backup', 'pagenest-companion'),
            'importHelp' => __(
                'Import keeps records private and reuses existing UUIDs. Review per-record results; it never publishes a record.',
                'pagenest-companion',
            ),
            'imported' => __(
                'Import processed. Reload records to review the results.',
                'pagenest-companion',
            ),
            'savedLater' => __('Saved for later on this device.', 'pagenest-companion'),
            'enableFirst' => __('Enable device memory in My reading first.', 'pagenest-companion'),
        ],
    ]);
});
