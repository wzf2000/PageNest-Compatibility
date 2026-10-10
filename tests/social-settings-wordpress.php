<?php
/** Synthetic local WordPress only: administrator overrides, no external requests or mail. */
if (
    wp_get_environment_type() !== 'local' ||
    !in_array(
        wp_parse_url(home_url('/'), PHP_URL_HOST),
        ['example.test', 'localhost', '127.0.0.1'],
        true,
    )
) {
    throw new RuntimeException('Synthetic local WordPress required.');
}
require_once dirname(__DIR__) . '/service-config.php';
require_once dirname(__DIR__) . '/social-settings.php';
require_once dirname(__DIR__) . '/modules/social/identities.php';
$checks = 0;
$assert = static function ($condition, $label) use (&$checks) {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Social settings: ' . $label);
    }
};
$reject = static function ($call, $label) use ($assert) {
    try {
        $call();
    } catch (Throwable $error) {
        $assert(true, $label);
        return;
    }
    $assert(false, $label);
};
$original = [];
foreach (
    [
        'pagenest_companion_services_source',
        'pagenest_companion_services_source_valid',
        'pagenest_companion_services_profile',
        'pagenest_social_settings_invalid',
    ]
    as $key
) {
    $original[$key] = $GLOBALS[$key] ?? null;
}
$old_option = get_option('pagenest_social_settings', null);
$original_user = get_current_user_id();
$original_method = $_SERVER['REQUEST_METHOD'] ?? '';
$created = [];
$origin = static fn($url) => preg_replace('#^https?://[^/]+#', 'https://example.test', $url);
add_filter('home_url', $origin, 99);
$blocked = static fn() => new WP_Error('fixture_external_blocked', 'No external requests.');
$mail_calls = 0;
$mail_blocked = static function () use (&$mail_calls) {
    $mail_calls++;
    return false;
};
add_filter('pre_http_request', $blocked, PHP_INT_MAX);
add_filter('pre_wp_mail', $mail_blocked, PHP_INT_MAX);
$prefix = 'social_settings_' . bin2hex(random_bytes(5));
try {
    foreach (['administrator', 'subscriber'] as $role) {
        $user = wp_insert_user([
            'user_login' => $prefix . '_' . $role,
            'user_pass' => wp_generate_password(40),
            'user_email' => $prefix . '_' . $role . '@example.test',
            'role' => $role,
        ]);
        $assert(!is_wp_error($user), 'create synthetic user');
        $created[] = $user;
    }
    [$admin, $reader] = $created;
    wp_set_current_user($admin);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $nonce = wp_create_nonce('pagenest-social-settings');
    $source = pagenest_companion_validate_services([
        'social' => [
            'enabled' => true,
            'allow_registration' => false,
            'legacy_apps' => ['github' => 'historic-scope'],
            'providers' => [
                'qq' => [
                    'enabled' => true,
                    'client_id' => 'synthetic-qq',
                    'client_secret' => 'synthetic-private-qq-secret',
                    'redirect_uri' => home_url('/callback/'),
                ],
                'baidu' => [
                    'enabled' => true,
                    'client_id' => 'synthetic-baidu',
                    'client_secret' => 'synthetic-private-baidu-secret',
                    'redirect_uri' => home_url('/callback/'),
                ],
            ],
        ],
    ]);
    $GLOBALS['pagenest_companion_services_source'] = $source;
    $GLOBALS['pagenest_companion_services_source_valid'] = true;
    $GLOBALS['pagenest_companion_services_profile'] = $source;
    delete_option('pagenest_social_settings');
    pagenest_social_settings_bootstrap();
    $assert(
        $GLOBALS['pagenest_companion_services_profile'] === $source &&
            get_option('pagenest_social_settings', null) === null,
        'server settings inherit without an automatic database write',
    );
    $request = [
        'operation' => 'provider',
        'provider' => 'qq',
        'enabled' => '1',
        'client_id' => 'synthetic-qq',
        'client_secret' => '',
        'redirect_uri' => home_url('/callback/'),
        '_wpnonce' => $nonce,
    ];
    wp_set_current_user($reader);
    $reject(static fn() => pagenest_social_settings_update($request), 'reader cannot save');
    wp_set_current_user(0);
    $reject(static fn() => pagenest_social_settings_update($request), 'anonymous cannot save');
    wp_set_current_user($admin);
    $reject(
        static fn() => pagenest_social_settings_update(
            array_replace($request, ['_wpnonce' => 'invalid']),
        ),
        'invalid nonce cannot save',
    );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $reject(static fn() => pagenest_social_settings_update($request), 'GET cannot save');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $assert(
        get_option('pagenest_social_settings', null) === null,
        'rejected requests do not write settings',
    );
    pagenest_social_settings_update($request);
    $stored = pagenest_social_settings_option();
    $assert(
        $stored['providers']['qq']['client_secret'] === 'synthetic-private-qq-secret',
        'blank secret preserves current inherited secret',
    );
    $assert(
        array_keys($stored['providers']) === ['qq'] && $stored['switches'] === [],
        'saving one provider does not persist other providers or switches',
    );
    global $wpdb;
    $autoload = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
            'pagenest_social_settings',
        ),
    );
    $assert(
        in_array($autoload, ['no', 'off', 'auto-off'], true),
        'private settings option is not autoloaded',
    );
    pagenest_social_settings_bootstrap();
    foreach (['mail', 'turnstile', 'output'] as $service) {
        $assert(
            $GLOBALS['pagenest_companion_services_profile'][$service] === $source[$service],
            'unrelated service remains unchanged',
        );
    }
    $assert(
        !isset($GLOBALS['pagenest_companion_services_profile']['social']['providers']['wechat']),
        'WeChat is not installed or enabled by saving other providers',
    );
    $reject(
        static fn() => pagenest_social_settings_update(
            array_replace($request, ['client_id' => 'new-app']),
        ),
        'application change cannot reuse old secret',
    );
    $reject(
        static fn() => pagenest_social_settings_update(
            array_replace($request, ['clear_secret' => '1']),
        ),
        'enabled provider cannot clear secret',
    );
    $reject(
        static fn() => pagenest_social_settings_update(
            array_replace($request, ['redirect_uri' => 'https://elsewhere.example/callback/']),
        ),
        'foreign origin callback refused',
    );
    $reject(
        static fn() => pagenest_social_settings_update(
            array_replace($request, ['redirect_uri' => 'http://example.test/callback/']),
        ),
        'plaintext callback refused',
    );
    $reject(
        static fn() => pagenest_social_settings_update(
            array_replace($request, [
                'provider' => 'github',
                'client_id' => 'historic-scope',
                'client_secret' => 'new-secret',
            ]),
        ),
        'historic application scope collision refused',
    );
    $assert(
        pagenest_social_settings_option() === $stored,
        'invalid edits leave saved option intact',
    );
    pagenest_social_settings_update(
        array_replace($request, ['enabled' => '', 'clear_secret' => '1']),
    );
    $assert(
        pagenest_social_settings_option()['providers']['qq']['client_secret'] === '' &&
            pagenest_social_settings_option()['providers']['qq']['enabled'] === false,
        'disabled provider explicitly clears secret',
    );
    pagenest_social_settings_update([
        'operation' => 'reset_provider',
        'provider' => 'qq',
        '_wpnonce' => $nonce,
    ]);
    $assert(
        pagenest_social_settings_merge($source, pagenest_social_settings_option()) === $source,
        'provider reset restores inherited source',
    );
    pagenest_social_settings_update(
        array_replace($request, [
            'provider' => 'github',
            'enabled' => '',
            'client_id' => 'synthetic-github',
            'client_secret' => 'synthetic-private-github-secret',
        ]),
    );
    pagenest_social_settings_update($request);
    $assert(
        count(pagenest_social_settings_option()['providers']) === 2,
        'later provider save preserves earlier provider override',
    );
    $stale = pagenest_social_settings_option();
    $other_request = $stale;
    $other_request['providers']['github']['client_secret'] = 'synthetic-private-rotated-secret';
    // Simulate a different PHP request committing while this request retains
    // its prior option cache. Direct DB update is confined to this owned fixture.
    $wpdb->update(
        $wpdb->options,
        ['option_value' => maybe_serialize($other_request)],
        ['option_name' => 'pagenest_social_settings'],
    );
    wp_cache_set('pagenest_social_settings', $stale, 'options');
    $assert(
        get_option('pagenest_social_settings') === $stale,
        'fixture reproduces stale per-request option cache',
    );
    pagenest_social_settings_update($request);
    $assert(
        pagenest_social_settings_option()['providers']['github']['client_secret'] ===
            'synthetic-private-rotated-secret',
        'locked save preserves concurrent provider secret rotation',
    );
    wp_cache_delete('pagenest_social_settings', 'options');
    wp_cache_set('notoptions', ['pagenest_social_settings' => true], 'options');
    pagenest_social_settings_update($request);
    $assert(
        count(pagenest_social_settings_option()['providers']) === 2,
        'locked save also refreshes stale negative cache',
    );
    pagenest_social_settings_update([
        'operation' => 'switches',
        'enabled' => '1',
        'allow_registration' => '1',
        '_wpnonce' => $nonce,
    ]);
    $assert(
        pagenest_social_settings_merge($source, pagenest_social_settings_option())['social'][
            'allow_registration'
        ] === true,
        'registration switch can be separately overridden',
    );
    pagenest_social_settings_update(['operation' => 'reset_switches', '_wpnonce' => $nonce]);
    $assert(
        pagenest_social_settings_option()['switches'] === [] &&
            count(pagenest_social_settings_option()['providers']) === 2,
        'switch reset preserves provider overrides',
    );
    ob_start();
    pagenest_social_settings_page();
    $page = ob_get_clean();
    $assert(
        !str_contains($page, 'synthetic-private-') && substr_count($page, 'type="password"') === 6,
        'administrator page never returns any stored secret',
    );
    $assert(
        str_contains($page, 'pagenest-companion') && str_contains($page, 'manage') === false,
        'settings page links back to normal settings',
    );
    $output = getenv('PAGENEST_SOCIAL_UI_OUTPUT');
    if ($output) {
        $real = realpath($output);
        if (!$real || !($real === '/lab/ui' || str_starts_with($real, '/tmp/pagenest-'))) {
            throw new RuntimeException('Owned UI output required.');
        }
        file_put_contents($real . '/provider-settings.html', $page);
    }
    $valid_override = pagenest_social_settings_option();
    update_option(
        'pagenest_social_settings',
        ['schema_version' => 1, 'switches' => [], 'providers' => [], 'mail' => ['enabled' => true]],
        false,
    );
    $GLOBALS['pagenest_companion_services_profile'] = $source;
    pagenest_social_settings_bootstrap();
    $assert(
        $GLOBALS['pagenest_companion_services_profile']['social']['enabled'] === false &&
            $GLOBALS['pagenest_companion_services_profile']['mail'] === $source['mail'],
        'malformed override fails closed for social only',
    );
    pagenest_social_settings_update(['operation' => 'reset_all', '_wpnonce' => $nonce]);
    $assert(
        get_option('pagenest_social_settings', null) === null,
        'administrator can restore source after malformed option',
    );
    update_option('pagenest_social_settings', $valid_override, false);
    $GLOBALS['pagenest_companion_services_source_valid'] = false;
    $reject(
        static fn() => pagenest_social_settings_update($request),
        'invalid server source cannot be changed using overrides',
    );
    pagenest_social_settings_bootstrap();
    $assert(
        $GLOBALS['pagenest_companion_services_profile'] === pagenest_companion_service_defaults(),
        'invalid source cannot be rescued by valid database settings',
    );
    $bad_source = $source;
    $bad_source['social']['enabled'] = 'invalid';
    $reject(
        static fn() => pagenest_social_settings_merge($bad_source, $valid_override),
        'source is validated before override can mask invalid values',
    );
    $assert($mail_calls === 0, 'settings operations send no mail');
    echo wp_json_encode([
        'checks' => $checks,
        'users' => 'synthetic only',
        'mail' => 'blocked',
        'external_http' => 'blocked',
    ]) . "\n";
} finally {
    wp_set_current_user($original_user);
    $_SERVER['REQUEST_METHOD'] = $original_method;
    if ($old_option === null) {
        delete_option('pagenest_social_settings');
    } else {
        update_option('pagenest_social_settings', $old_option, false);
    }
    foreach ($original as $key => $value) {
        if ($value === null) {
            unset($GLOBALS[$key]);
        } else {
            $GLOBALS[$key] = $value;
        }
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($created as $id) {
        wp_delete_user($id);
    }
    remove_filter('home_url', $origin, 99);
    remove_filter('pre_http_request', $blocked, PHP_INT_MAX);
    remove_filter('pre_wp_mail', $mail_blocked, PHP_INT_MAX);
}
