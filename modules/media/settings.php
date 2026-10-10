<?php
/** Administrator toggle preserves the separately imported gallery configuration. */
defined('ABSPATH') || exit();
add_action('admin_menu', static function (): void {
    add_options_page(
        __('Media compatibility', 'pagenest-companion'),
        __('Companion Media', 'pagenest-companion'),
        'manage_options',
        'pagenest-media',
        'pagenest_companion_media_settings_page',
    );
});
add_action('admin_init', static function (): void {
    register_setting('pagenest_companion_media', 'pagenest_companion_media', [
        'type' => 'array',
        'sanitize_callback' => static function ($input) {
            $current = pagenest_companion_media_config();
            if (!current_user_can('manage_options')) {
                return $current;
            }
            $stored = get_option('pagenest_companion_media', $current);
            try {
                $current = pagenest_companion_media_validate($stored);
            } catch (InvalidArgumentException $error) {
                add_settings_error(
                    'pagenest_companion_media',
                    'invalid_media',
                    __(
                        'The stored gallery configuration is invalid. Ask your migration administrator to repair it before changing the toggle.',
                        'pagenest-companion',
                    ),
                );
                return $stored;
            }
            // The form can change only the toggle, never gallery sources or identities.
            $current['enabled'] = is_array($input) && ($input['enabled'] ?? '') === '1';
            return $current;
        },
    ]);
});

function pagenest_companion_media_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $config = pagenest_companion_media_config();
    echo '<div class="wrap"><h1>' .
        esc_html__('Media compatibility', 'pagenest-companion') .
        '</h1><p>' .
        esc_html__(
            'Display existing gallery and PDF blocks. Advanced gallery mappings are supplied by your migration administrator; media files and source folders remain editable through WordPress Media and your organization plugin.',
            'pagenest-companion',
        ) .
        '</p><form method="post" action="options.php">';
    settings_fields('pagenest_companion_media');
    echo '<input type="hidden" name="pagenest_companion_media[enabled]" value="0"><p><label><input type="checkbox" name="pagenest_companion_media[enabled]" value="1" ' .
        checked($config['enabled'], true, false) .
        '> ' .
        esc_html__('Enable media compatibility', 'pagenest-companion') .
        '</label></p><p>' .
        esc_html__(
            'Existing shortcode or block owners take precedence. Disabling this module preserves the gallery mappings.',
            'pagenest-companion',
        ) .
        '</p>';
    submit_button();
    echo '</form></div>';
}
