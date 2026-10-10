<?php
/** Real WordPress core, synthetic users only; mail and provider HTTP never leave the fixture. */
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
require_once dirname(__DIR__) . '/modules/social/bootstrap.php';
$checks = 0;
$assert = static function ($condition, $label) use (&$checks) {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Account security: ' . $label);
    }
};
$reject = static function ($call, $label) use ($assert) {
    $caught = false;
    try {
        $call();
    } catch (RuntimeException $error) {
        $caught = true;
    }
    $assert($caught, $label);
};
$prefix = 'security_' . bin2hex(random_bytes(6));
$original_profile = $GLOBALS['pagenest_companion_services_profile'];
$original_registration = get_option('users_can_register');
$fixture_origin = static fn($url) => preg_replace(
    '#^https?://[^/]+#',
    'https://example.test',
    $url,
);
add_filter('home_url', $fixture_origin, 99);
$GLOBALS['pagenest_companion_services_profile']['social'] = [
    'enabled' => true,
    'allow_registration' => true,
    'legacy_apps' => [],
    'providers' => [
        'qq' => [
            'enabled' => true,
            'client_id' => $prefix,
            'client_secret' => 'synthetic',
            'redirect_uri' => home_url('/callback/'),
        ],
        'baidu' => [
            'enabled' => true,
            'client_id' => $prefix,
            'client_secret' => 'synthetic',
            'redirect_uri' => home_url('/callback/'),
        ],
    ],
];
$created = [];
$mail = [];
$mail_ok = true;
$capture = static function ($pre, $atts) use (&$mail, &$mail_ok) {
    $mail[] = $atts;
    return $mail_ok;
};
$http = static fn() => new WP_Error('fixture_network_blocked', 'No external requests.');
add_filter('pre_wp_mail', $capture, PHP_INT_MAX, 2);
add_filter('pre_http_request', $http, PHP_INT_MAX);
$password = 'Synthetic-password-Only-42!';
$login = static function ($id, $token = null) {
    wp_set_current_user($id);
    if (!$id) {
        unset($_COOKIE[LOGGED_IN_COOKIE]);
        return '';
    }
    $token = $token ?: WP_Session_Tokens::get_instance($id)->create(time() + 3600);
    $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($id, time() + 3600, 'logged_in', $token);
    return $token;
};
$latest_token = static function () use (&$mail) {
    $text = end($mail)['message'];
    if (!preg_match('/[?&]token=([a-f0-9]{64})/', $text, $match)) {
        throw new RuntimeException('Synthetic mail token missing.');
    }
    return $match[1];
};
try {
    update_option('users_can_register', 1);
    foreach ([1, 2, 3] as $number) {
        $id = wp_insert_user([
            'user_login' => $prefix . '_' . $number,
            'user_pass' => $password,
            'user_email' => $prefix . '_' . $number . '@example.test',
            'role' => 'subscriber',
        ]);
        $assert(!is_wp_error($id), 'create synthetic user');
        $created[] = $id;
    }
    [$one, $two, $three] = $created;
    $session = $login($one);
    $reject(
        static fn() => pagenest_social_require_recent($one),
        'existing session is not automatically recent',
    );
    $reject(
        static fn() => pagenest_social_verify_password($one, 'wrong'),
        'wrong password refused',
    );
    pagenest_social_verify_password($one, $password);
    pagenest_social_require_recent($one);
    $assert(true, 'real password establishes recent authentication');
    pagenest_social_mark_recent($one);
    pagenest_social_mark_recent($one);
    pagenest_social_require_recent($one);
    $assert(true, 'same-second repeated authentication is not a storage failure');
    $fingerprint = pagenest_social_session_fingerprint($one);
    $login($one);
    $reject(
        static fn() => pagenest_social_require_recent($one),
        'another session of same user cannot reuse recent authentication',
    );
    $login($one, $session);
    set_transient('pn_social_recent_' . $fingerprint, time() - 601, 600);
    $reject(
        static fn() => pagenest_social_require_recent($one),
        'expired recent authentication refused',
    );
    pagenest_social_mark_recent($one);
    $assert(!pagenest_social_email_verified($one), 'historical email not automatically trusted');
    $key = pagenest_social_identity_key('qq', $prefix, 'one');
    pagenest_social_bind($one, 'qq', $prefix, 'one');
    $reject(
        static fn() => pagenest_social_unbind($one, $key),
        'last identity refuses unverified syntactically valid email',
    );
    pagenest_social_email_request($one);
    $token = $latest_token();
    $record = get_user_meta($one, '_pagenest_social_email_challenge', true);
    $assert(
        $record['token'] !== $token && $record['token'] === hash('sha256', $token),
        'only token hash stored',
    );
    $assert(!pagenest_social_email_verified($one), 'accepted mail does not verify email');
    $reject(
        static fn() => pagenest_social_email_confirm($one, str_repeat('0', 64)),
        'wrong verification token refused',
    );
    $login($two);
    $reject(
        static fn() => pagenest_social_email_confirm($one, $token),
        'other signed-in user cannot confirm target',
    );
    $reject(
        static fn() => pagenest_social_email_confirm($two, $token),
        'token cannot transfer to another account',
    );
    $login($one, $session);
    pagenest_social_email_confirm($one, $token);
    $assert(pagenest_social_email_verified($one), 'correct token verifies current email');
    $reject(
        static fn() => pagenest_social_email_confirm($one, $token),
        'verification token single-use',
    );
    pagenest_social_unbind($one, $key);
    $assert(
        pagenest_social_owner($key)['user_id'] === 0 && get_userdata($one),
        'verified email permits unlink without deleting account',
    );
    wp_update_user(['ID' => $one, 'user_email' => $prefix . '_changed@example.test']);
    $assert(!pagenest_social_email_verified($one), 'WordPress email update invalidates trust');
    wp_update_user(['ID' => $one, 'user_email' => $prefix . '_1@example.test']);
    $assert(!pagenest_social_email_verified($one), 'changing email back does not revive trust');
    pagenest_social_email_request($one);
    $token = $latest_token();
    $record = get_user_meta($one, '_pagenest_social_email_challenge', true);
    $record['expires'] = time() - 1;
    update_user_meta($one, '_pagenest_social_email_challenge', $record);
    $reject(
        static fn() => pagenest_social_email_confirm($one, $token),
        'expired mail confirmation refused',
    );
    $mail_ok = false;
    $reject(static fn() => pagenest_social_email_request($one), 'mail transport failure reported');
    $assert(
        !get_user_meta($one, '_pagenest_social_email_challenge', true),
        'failed mail leaves no confirmable challenge',
    );
    $mail_ok = true;
    $reject(static fn() => pagenest_social_email_request($one), 'email send rate limit enforced');
    wp_set_password('Synthetic-replacement-password-43!', $one);
    $reject(
        static fn() => pagenest_social_require_recent($one),
        'password change invalidates recent authentication',
    );
    $login($three);
    for ($i = 0; $i < 5; $i++) {
        $reject(
            static fn() => pagenest_social_verify_password($three, 'wrong'),
            'password attempt refused',
        );
    }
    $reject(
        static fn() => pagenest_social_verify_password($three, $password),
        'password attempt limit applies even to valid sixth attempt',
    );
    $login($two);
    pagenest_social_bind($two, 'qq', $prefix, 'two');
    pagenest_social_bind($two, 'baidu', $prefix, 'two');
    $reject(
        static fn() => pagenest_social_reauthenticate_identity($two, 'qq', $prefix, 'unbound'),
        'new identity cannot authenticate existing account',
    );
    $reject(
        static fn() => pagenest_social_reauthenticate_identity($two, 'qq', $prefix, 'one'),
        'another identity cannot authenticate this account',
    );
    pagenest_social_reauthenticate_identity($two, 'baidu', $prefix, 'two');
    pagenest_social_require_recent($two);
    $assert(true, 'owned existing identity authenticates current session');
    $ui_output = getenv('PAGENEST_SOCIAL_UI_OUTPUT');
    $ui = [];
    if ($ui_output) {
        $parent = realpath(dirname($ui_output));
        if (!$parent || ($parent !== '/lab' && !str_starts_with($parent, '/tmp/pagenest-'))) {
            throw new RuntimeException('Owned synthetic UI output required.');
        }
        if (!is_dir($ui_output)) {
            mkdir($ui_output, 0700);
        }
        $GLOBALS['pagenest_social_profile_forms'] = [];
        ob_start();
        echo '<form id="your-profile" method="post">';
        pagenest_social_profile(get_userdata($two));
        echo '</form>';
        pagenest_social_profile_forms();
        $ui['profile'] = ob_get_clean();
    }
    $two_qq = pagenest_social_identity_key('qq', $prefix, 'two');
    $two_baidu = pagenest_social_identity_key('baidu', $prefix, 'two');
    update_option($two_baidu, ['user_id' => $three], false);
    $reject(
        static fn() => pagenest_social_unbind($two, $two_qq),
        'stale or conflicting remaining binding not counted as recovery',
    );
    update_option($two_baidu, ['user_id' => $two], false);
    pagenest_social_unbind($two, $two_qq);
    $assert(
        pagenest_social_owner($two_qq)['user_id'] === 0,
        'valid alternate provider permits unlink',
    );
    $login(0);
    $config = pagenest_social_provider_config('qq');
    $identity = ['subject' => $prefix . '_new', 'name' => 'Synthetic new reader'];
    $assert(
        pagenest_social_login_identity('qq', $config, $identity) === 0,
        'unknown callback cannot auto-register',
    );
    $new = pagenest_social_login_identity('qq', $config, $identity, true);
    $created[] = $new;
    $assert(get_userdata($new)->roles === ['subscriber'], 'explicit registration minimum role');
    $assert(
        pagenest_social_login_identity('qq', $config, $identity) === $new,
        'existing identity retains original WordPress user',
    );
    $reject(
        static fn() => pagenest_social_login_identity('qq', $config, $identity, true),
        'racing registration cannot claim existing owner',
    );
    update_option('users_can_register', 0);
    $reject(
        static fn() => pagenest_social_login_identity(
            'qq',
            $config,
            ['subject' => 'closed-core', 'name' => 'Synthetic'],
            true,
        ),
        'core registration flag enforced',
    );
    update_option('users_can_register', 1);
    $GLOBALS['pagenest_companion_services_profile']['social']['allow_registration'] = false;
    $reject(
        static fn() => pagenest_social_login_identity(
            'qq',
            $config,
            ['subject' => 'closed-service', 'name' => 'Synthetic'],
            true,
        ),
        'service registration flag enforced',
    );
    $GLOBALS['pagenest_companion_services_profile']['social']['allow_registration'] = true;
    $pending = str_repeat('b', 64);
    $pending_key = 'pn_social_pending_' . hash('sha256', $pending);
    $pending_record = [
        'expires' => time() + 600,
        'browser' => hash('sha256', 'fixture-browser'),
        'provider' => 'qq',
        'app' => $prefix,
        'identity' => $identity,
        'return' => home_url('/'),
    ];
    set_transient($pending_key, $pending_record, 600);
    if ($ui_output) {
        $page_markup = '';
        $die = static function () use (&$page_markup) {
            return static function ($message) use (&$page_markup) {
                if ($page_markup === '') {
                    $page_markup = (string) $message;
                }
                throw new RuntimeException('Synthetic page captured.');
            };
        };
        add_filter('wp_die_handler', $die, PHP_INT_MAX);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_COOKIE['pn_social_pending'] = 'fixture-browser';
        $_GET = $_REQUEST = ['action' => 'pagenest_social_register', 'pending' => $pending];
        try {
            pagenest_social_registration_route();
        } catch (RuntimeException $error) {
        }
        $ui['registration'] = $page_markup;
        $assert(
            str_contains($page_markup, '创建新的本站账号') &&
                str_contains($page_markup, '登录已有账号'),
            'real registration choice markup',
        );
        $assert(
            get_transient($pending_key) === $pending_record,
            'GET choice does not consume authorization',
        );
        $login($two);
        $page_markup = '';
        $_GET = $_REQUEST = [
            'action' => 'pagenest_social_email_confirm',
            'token' => str_repeat('d', 64),
        ];
        try {
            pagenest_social_security_route();
        } catch (RuntimeException $error) {
        }
        $ui['email-confirmation'] = $page_markup;
        $assert(str_contains($page_markup, '确认邮箱'), 'real email confirmation markup');
        $assert(!pagenest_social_email_verified($two), 'email GET never marks verification');
        remove_filter('wp_die_handler', $die, PHP_INT_MAX);
        foreach ($ui as $name => $html) {
            file_put_contents($ui_output . '/' . $name . '.html', $html);
        }
        $login(0);
    }
    $reject(
        static fn() => pagenest_social_pending_read($pending, 'another-browser'),
        'pending registration browser-bound',
    );
    $login($two);
    $reject(
        static fn() => pagenest_social_pending_read($pending, 'fixture-browser'),
        'pending registration never becomes logged-in binding',
    );
    $login(0);
    $assert(
        pagenest_social_pending_read($pending, 'fixture-browser') === $pending_record,
        'pending read does not consume',
    );
    $assert(
        pagenest_social_pending_consume($pending, 'fixture-browser') === $pending_record,
        'pending consume succeeds',
    );
    $reject(
        static fn() => pagenest_social_pending_consume($pending, 'fixture-browser'),
        'pending selection single-use',
    );
    $pending_record['expires'] = time() - 1;
    set_transient($pending_key, $pending_record, 600);
    $reject(
        static fn() => pagenest_social_pending_read($pending, 'fixture-browser'),
        'pending choice expiry enforced',
    );
    delete_transient($pending_key);
    $login($two);
    $state = 'pn.qq.' . str_repeat('c', 64);
    $state_key = 'pagenest_social_state_' . hash('sha256', $state);
    $state_record = [
        'expires' => time() + 600,
        'browser' => hash('sha256', 'fixture-browser'),
        'provider' => 'qq',
        'app' => $prefix,
        'redirect_uri' => $config['redirect_uri'],
        'mode' => 'reauth',
        'user_id' => $two,
        'session' => pagenest_social_session_fingerprint($two),
        'return' => home_url('/'),
    ];
    set_transient($state_key, $state_record, 600);
    $login($two);
    $reject(
        static fn() => pagenest_social_state_consume($state, 'fixture-browser', 'qq', $config),
        'reauth callback rejects another session of same user',
    );
    delete_transient($state_key);

    // Reproduce normal WordPress user deletion, which leaves the option index.
    $login(0);
    $deleted_config = $config;
    $deleted_config['client_id'] = $prefix . '_deleted';
    $deleted_identity = ['subject' => 'deleted-login', 'name' => 'Synthetic deleted account'];
    $deleted = pagenest_social_login_identity('qq', $deleted_config, $deleted_identity, true);
    $created[] = $deleted;
    $deleted_key = pagenest_social_identity_key(
        'qq',
        $deleted_config['client_id'],
        'deleted-login',
    );
    $deleted_bind_key = pagenest_social_identity_key(
        'baidu',
        $deleted_config['client_id'],
        'deleted-bind',
    );
    pagenest_social_bind($deleted, 'baidu', $deleted_config['client_id'], 'deleted-bind');
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $assert(wp_delete_user($deleted), 'delete synthetic WordPress account');
    $assert(
        get_option($deleted_key) === ['user_id' => $deleted],
        'normal deletion leaves social owner',
    );
    $assert(
        pagenest_social_login_identity('qq', $deleted_config, $deleted_identity) === 0,
        'deleted identity reaches explicit registration choice',
    );
    $assert(get_option($deleted_key, null) === null, 'verified missing user releases owner');
    $replacement = pagenest_social_login_identity('qq', $deleted_config, $deleted_identity, true);
    $created[] = $replacement;
    $assert(
        $replacement !== $deleted && get_userdata($replacement)->roles === ['subscriber'],
        'explicit registration creates new account',
    );
    $assert(
        get_option($deleted_key) === ['user_id' => $replacement],
        'replacement owns only confirmed identity',
    );
    $assert(
        pagenest_social_bind($one, 'baidu', $deleted_config['client_id'], 'deleted-bind') === $one,
        'existing account may bind identity whose old user was deleted',
    );
    $assert(
        get_option($deleted_bind_key) === ['user_id' => $one],
        'binding replaces deleted owner',
    );
    $reject(
        static fn() => pagenest_social_bind(
            $two,
            'qq',
            $deleted_config['client_id'],
            'deleted-login',
        ),
        'replacement live owner still rejects another account',
    );
    echo wp_json_encode([
        'checks' => $checks,
        'mail' => 'captured only',
        'provider_http' => 'blocked',
        'users' => 'synthetic only',
    ]) . "\n";
} finally {
    $login(0);
    global $wpdb;
    $owners = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('_pagenest_social_identity_') . '%',
        ),
        ARRAY_A,
    );
    foreach ($owners as $owner) {
        $value = maybe_unserialize($owner['option_value']);
        if (
            in_array($value['user_id'] ?? 0, $created, true) ||
            in_array($value['unbound_from'] ?? 0, $created, true)
        ) {
            delete_option($owner['option_name']);
        }
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($created as $id) {
        WP_Session_Tokens::get_instance($id)->destroy_all();
        wp_delete_user($id);
    }
    update_option('users_can_register', $original_registration);
    $GLOBALS['pagenest_companion_services_profile'] = $original_profile;
    remove_filter('home_url', $fixture_origin, 99);
    remove_filter('pre_wp_mail', $capture, PHP_INT_MAX);
    remove_filter('pre_http_request', $http, PHP_INT_MAX);
}
