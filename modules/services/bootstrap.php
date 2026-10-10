<?php
/** Independent, trusted-server service profiles. No activation or migration side effects. */
defined('ABSPATH') || exit();
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/turnstile.php';
require_once __DIR__ . '/output.php';
function pagenest_companion_services_bootstrap()
{
    if (!function_exists('pagenest_companion_service_config')) {
        return;
    }
    $mail = pagenest_companion_service_config('mail');
    if (
        !empty($mail['enabled']) &&
        !function_exists('easy_wp_smtp') &&
        !class_exists('EasyWPSMTP')
    ) {
        add_filter('pre_wp_mail', 'pagenest_companion_mail_prepare', PHP_INT_MAX, 2);
    }
    $turnstile = pagenest_companion_service_config('turnstile');
    if (!empty($turnstile['enabled']) && !function_exists('cfturnstile_api_url')) {
        pagenest_companion_turnstile_register();
    }
    $output = pagenest_companion_service_config('output');
    if (
        !empty($output['enabled']) &&
        !defined('WPSOL_ADDON_VERSION') &&
        !defined('WPSOL_VERSION')
    ) {
        add_action('template_redirect', 'pagenest_companion_output_start', PHP_INT_MAX);
    }
}
add_action('plugins_loaded', 'pagenest_companion_services_bootstrap', 20);
