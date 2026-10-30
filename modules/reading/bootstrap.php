<?php
/** Opt-in device reading and an in-memory authenticated record center. */
defined('ABSPATH') || exit();
function pagenest_reading_enabled(): bool
{
    return get_option('pagenest_companion_reading', false) === '1';
}
require_once __DIR__ . '/account.php';
add_action('admin_init', static function () {
    register_setting('pagenest_reading', 'pagenest_companion_account_reading', [
        'type' => 'string',
        'default' => '0',
        'sanitize_callback' => static fn($value) => current_user_can('manage_options') &&
        $value === '1'
            ? '1'
            : '0',
    ]);
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
                    'Disabled by default. Device reading memory remains optional. Account reading and reading preferences each require explicit consent. Device reading sends article IDs only to check current public access.',
                    'pagenest-companion',
                ) .
                '</p><p>' .
                esc_html__(
                    'Add [pagenest_my_reading] to an existing page or use the PageNest reading entry. No page or menu is created automatically. Private records require sign-in and remain in page memory.',
                    'pagenest-companion',
                ) .
                '</p>';
            echo '<p><label><input type="checkbox" name="pagenest_companion_account_reading" value="1" ' .
                checked(get_option('pagenest_companion_account_reading', '0'), '1', false) .
                (is_multisite() ? ' disabled' : '') .
                '> ' .
                esc_html__('Offer account reading sync', 'pagenest-companion') .
                '</label></p><p>' .
                esc_html__(
                    'Disabled by default. Readers separately consent to manual account records, automatic current positions and reading preferences. Private comments and notes are never included.',
                    'pagenest-companion',
                ) .
                '</p>';
            if (is_multisite()) {
                echo '<p>' .
                    esc_html__(
                        'Account reading sync currently supports single-site WordPress only.',
                        'pagenest-companion',
                    ) .
                    '</p>';
            }
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
function pagenest_reading_header_entry(): void
{
    if (pagenest_reading_enabled()) {
        echo '<button type="button" data-pn-open-reading hidden>' .
            esc_html__('My reading', 'pagenest-companion') .
            '</button>';
    }
}
add_action('pagenest_account_actions', static function () {
    if (current_theme_supports('pagenest-account-actions')) {
        pagenest_reading_header_entry();
    }
});
add_action('pagenest_header_actions', static function () {
    if (!current_theme_supports('pagenest-account-actions')) {
        pagenest_reading_header_entry();
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
        $post = get_post();
        if (
            !pagenest_reading_enabled() ||
            !is_singular('post') ||
            !in_the_loop() ||
            !is_main_query() ||
            !pagenest_discovery_public_post($post) ||
            $post->post_type !== 'post'
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
    $post = is_singular('post') ? get_queried_object() : null;
    wp_localize_script('pagenest-reading-center', 'PageNestReading', [
        'accountApi' => pagenest_account_reading_offered()
            ? rest_url('pagenest/v1/reading/account')
            : '',
        'resolve' => rest_url('pagenest/v1/reading/resolve'),
        'api' => rest_url(pagenest_companion_config('paragraphs', 'rest_namespace') . '/'),
        'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
        'login' => wp_login_url(get_permalink()),
        'current' =>
            $post && $post->post_type === 'post' && pagenest_discovery_public_post($post)
                ? [
                    'id' => (int) $post->ID,
                    'headings' => array_column(pagenest_discovery_sections($post), 'id'),
                ]
                : null,
        'sync' =>
            pagenest_account_reading_offered() && is_user_logged_in()
                ? [
                    'actor' => hash_hmac('sha256', (string) get_current_user_id(), wp_salt('auth')),
                    'text' => [
                        'saving' => __(
                            'Saving reading preferences. You can withdraw after this request finishes.',
                            'pagenest-companion',
                        ),
                        'title' => __(
                            'Automatic reading and reading preferences',
                            'pagenest-companion',
                        ),
                        'load' => __('Load sync settings', 'pagenest-companion'),
                        'automatic' => __(
                            'Automatically save future reading positions on this device',
                            'pagenest-companion',
                        ),
                        'preferences' => __(
                            'Sync reading size, width and palette on this device',
                            'pagenest-companion',
                        ),
                        'help' => __(
                            'Automatic reading saves only the current public article after consent. Earlier device history still needs a manual merge. Reading preferences have separate consent; withdrawing them keeps device preferences. Stopping automatic reading keeps saved reading records. Stopping automatic reading or withdrawing preferences also pauses other devices until they confirm again.',
                            'pagenest-companion',
                        ),
                        'enabled' => __('Enabled on this device.', 'pagenest-companion'),
                        'withdrawnPreferences' => __(
                            'Account reading preferences cleared. Device preferences were kept.',
                            'pagenest-companion',
                        ),
                        'stoppedAutomatic' => __(
                            'Automatic saving stopped. Saved account and device records were kept.',
                            'pagenest-companion',
                        ),
                        'saved' => __('Saved to your account.', 'pagenest-companion'),
                        'applied' => __(
                            'Account reading preferences applied to this page. Device values were kept.',
                            'pagenest-companion',
                        ),
                        'reconfirm' => __(
                            'This device is paused. Load the latest settings, then check the option to confirm again.',
                            'pagenest-companion',
                        ),
                        'storage' => __(
                            'Browser storage is unavailable. Automatic syncing is paused.',
                            'pagenest-companion',
                        ),
                        'savedStorage' => __(
                            'Account consent was saved, but this device could not save its sync marker. Syncing on this device is paused.',
                            'pagenest-companion',
                        ),
                        'localRequired' => __(
                            'Enable device reading memory with available browser storage before automatic saving.',
                            'pagenest-companion',
                        ),
                        'failed' => __(
                            'Syncing failed and is paused. Load the latest settings and confirm again.',
                            'pagenest-companion',
                        ),
                        'conflict' => __(
                            'Account state changed. Syncing is paused; load settings and confirm again. Older changes will not be resent.',
                            'pagenest-companion',
                        ),
                        'login' => __(
                            'Sign-in expired. Device preferences were restored; sign in and confirm syncing again.',
                            'pagenest-companion',
                        ),
                        'paused' => __(
                            'Account syncing is paused on this site. You can still withdraw existing consent.',
                            'pagenest-companion',
                        ),
                        'savedPaused' => __(
                            'The account update was saved, but this site paused syncing. Device values were kept.',
                            'pagenest-companion',
                        ),
                        'accountFirst' => __(
                            'Enable account reading above before separately allowing automatic positions.',
                            'pagenest-companion',
                        ),
                        'noTheme' => __(
                            'This theme has no compatible reading-preference interface. Nothing was applied; you can still withdraw account preferences.',
                            'pagenest-companion',
                        ),
                        'withdrawPreferences' => __(
                            'Withdraw consent and clear account preferences',
                            'pagenest-companion',
                        ),
                        'stopAutomatic' => __('Stop automatic reading', 'pagenest-companion'),
                    ],
                ]
                : null,
        'accountText' => [
            'serverPaused' => __(
                'Account records were saved, but this site paused syncing. Device records were kept.',
                'pagenest-companion',
            ),
            'savedReadback' => __(
                'Account reading was saved, but its current list could not be loaded. Refresh before making another change.',
                'pagenest-companion',
            ),
            'paused' => __(
                'Account reading is paused on this site. Records are not loaded; you can still withdraw consent and clear account records.',
                'pagenest-companion',
            ),
            'title' => __('Account reading', 'pagenest-companion'),
            'intro' => __(
                'Load this list when needed. Automatic positions and reading preferences each need separate consent.',
                'pagenest-companion',
            ),
            'load' => __('Load account reading', 'pagenest-companion'),
            'refresh' => __('Refresh account reading', 'pagenest-companion'),
            'consent' => __(
                'I agree to save public reading positions to my account',
                'pagenest-companion',
            ),
            'enable' => __('Enable account reading', 'pagenest-companion'),
            'off' => __(
                'Account reading is off. Enabling it records your consent only; it does not upload device records.',
                'pagenest-companion',
            ),
            'enabled' => __(
                'Account reading is enabled. Merge earlier device history manually; automatic positions need separate consent.',
                'pagenest-companion',
            ),
            'merge' => __('Merge device and account records', 'pagenest-companion'),
            'mergeHelp' => __(
                'Requires device memory. Combines both lists, uses the newer reading position and keeps Read later from either list. Only the 100 most recent public articles are kept. Comments and private notes are excluded.',
                'pagenest-companion',
            ),
            'manage' => __('Manage account records', 'pagenest-companion'),
            'deleteHelp' => __(
                'Removing account records does not delete device records. A later manual merge can add them again.',
                'pagenest-companion',
            ),
            'remove' => __('Remove from account', 'pagenest-companion'),
            'clear' => __('Clear account records', 'pagenest-companion'),
            'withdraw' => __('Withdraw consent and clear account records', 'pagenest-companion'),
            'confirmRemove' => __(
                'Remove this account record? Device records will remain.',
                'pagenest-companion',
            ),
            'confirmClear' => __(
                'Clear all account reading records? Device records will remain.',
                'pagenest-companion',
            ),
            'confirmWithdraw' => __(
                'Stop account reading and clear its records? Device records will remain.',
                'pagenest-companion',
            ),
            'localRequired' => __(
                'Enable device memory with available browser storage before merging. You can still follow account links without downloading records to this device.',
                'pagenest-companion',
            ),
            'conflict' => __(
                'Account records changed. Refresh, review the latest list and confirm the action again.',
                'pagenest-companion',
            ),
            'error' => __(
                'Account reading could not be saved or loaded. Refresh and try again.',
                'pagenest-companion',
            ),
            'changed' => __(
                'Account records changed in another panel. Load them again before continuing.',
                'pagenest-companion',
            ),
            'merged' => __(
                'Account and device records merged. The 100 most recent public articles are kept.',
                'pagenest-companion',
            ),
            'serverOnly' => __(
                'The account merge succeeded, but device records could not be updated because consent, records or browser storage changed. Review the account list before merging again.',
                'pagenest-companion',
            ),
            'saved' => __(
                'Account reading updated. Device records were kept.',
                'pagenest-companion',
            ),
            'mergeSummary' => __(
                'Manual merge keeps the 100 most recent public articles.',
                'pagenest-companion',
            ),
            'loginRequired' => __(
                'Sign in again before loading account reading.',
                'pagenest-companion',
            ),
            'featureUnavailable' => __(
                'Account reading is currently unavailable. Load again after it is enabled.',
                'pagenest-companion',
            ),
            'needConsent' => __(
                'Account reading consent has changed. Load again and review your choice.',
                'pagenest-companion',
            ),
        ],
        'text' => [
            'reading' => __('My reading', 'pagenest-companion'),
            'notes' => __('Comments and notes', 'pagenest-companion'),
            'continue' => __('Continue reading', 'pagenest-companion'),
            'refresh' => __('Refresh records', 'pagenest-companion'),
            'devicePrompt' => __(
                'Enable the option above to keep reading here.',
                'pagenest-companion',
            ),
            'manage' => __('Manage device records', 'pagenest-companion'),
            'deviceEnabled' => __('Device memory enabled.', 'pagenest-companion'),
            'deviceDisabled' => __(
                'Device memory disabled and records cleared.',
                'pagenest-companion',
            ),
            'deviceCleared' => __('Device records cleared.', 'pagenest-companion'),
            'filter' => __('Filter records', 'pagenest-companion'),
            'backup' => __('Backup and import', 'pagenest-companion'),
            'privateIntro' => __(
                'Your records load only when you choose to view them.',
                'pagenest-companion',
            ),
            'device' => __('On this device', 'pagenest-companion'),
            'consent' => __('Remember my reading on this device', 'pagenest-companion'),
            'deviceSummary' => __(
                'Device history stays here. Optional account features require separate consent.',
                'pagenest-companion',
            ),
            'help' => __('How this works', 'pagenest-companion'),
            'deviceHelp' => __(
                'Optional browser storage. Earlier device history stays local unless you manually merge it. Automatic positions and reading preferences each need separate consent. Article IDs are checked before display. Position does not mean an article was read.',
                'pagenest-companion',
            ),
            'unavailable' => __(
                'Browser storage is unavailable. Device reading is paused.',
                'pagenest-companion',
            ),
            'clear' => __('Clear device records', 'pagenest-companion'),
            'deviceLoadError' => __(
                "Could not load this device's reading list. Try again.",
                'pagenest-companion',
            ),
            'retry' => __('Retry', 'pagenest-companion'),
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
