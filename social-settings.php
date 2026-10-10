<?php
/** Restricted administrator overrides for social login only. */
defined('ABSPATH') || exit();
function pagenest_social_settings_option()
{
    $value = get_option('pagenest_social_settings', null);
    if ($value === null) {
        return ['schema_version' => 1, 'switches' => [], 'providers' => []];
    }
    if (
        !is_array($value) ||
        ($value['schema_version'] ?? null) !== 1 ||
        array_diff(array_keys($value), ['schema_version', 'switches', 'providers']) ||
        !is_array($value['switches'] ?? null) ||
        !is_array($value['providers'] ?? null) ||
        array_diff(array_keys($value['switches']), ['enabled', 'allow_registration'])
    ) {
        throw new RuntimeException('Invalid social settings.');
    }
    foreach ($value['switches'] as $switch) {
        if (!is_bool($switch)) {
            throw new RuntimeException('Invalid social settings.');
        }
    }
    foreach ($value['providers'] as $provider => $fields) {
        if (
            !in_array($provider, ['qq', 'baidu', 'sina', 'github', 'google', 'wechat'], true) ||
            !is_array($fields) ||
            array_diff(array_keys($fields), [
                'enabled',
                'client_id',
                'client_secret',
                'redirect_uri',
            ])
        ) {
            throw new RuntimeException('Invalid social settings.');
        }
        foreach ($fields as $field => $field_value) {
            if (
                ($field === 'enabled' && !is_bool($field_value)) ||
                ($field !== 'enabled' && !is_string($field_value))
            ) {
                throw new RuntimeException('Invalid social settings.');
            }
        }
    }
    return $value;
}
function pagenest_social_settings_merge($server, $override)
{
    $server = pagenest_companion_validate_services($server);
    $merged = $server;
    foreach ($override['switches'] as $name => $value) {
        $merged['social'][$name] = $value;
    }
    foreach ($override['providers'] as $name => $fields) {
        $merged['social']['providers'][$name] = array_replace(
            $server['social']['providers'][$name] ?? [],
            $fields,
        );
    }
    $merged = pagenest_companion_validate_services($merged);
    foreach ($merged['social']['providers'] as $provider) {
        if ($provider['redirect_uri'] === '') {
            continue;
        }
        $uri = parse_url($provider['redirect_uri']);
        $home = parse_url(home_url('/'));
        if (
            ($uri['scheme'] ?? '') !== 'https' ||
            ($home['scheme'] ?? '') !== 'https' ||
            strtolower($uri['host'] ?? '') !== strtolower($home['host'] ?? '') ||
            ($uri['port'] ?? null) !== ($home['port'] ?? null)
        ) {
            throw new RuntimeException('Social callback must use this site HTTPS origin.');
        }
    }
    return $merged;
}
function pagenest_social_settings_bootstrap()
{
    if (!($GLOBALS['pagenest_companion_services_source_valid'] ?? false)) {
        $GLOBALS['pagenest_companion_services_profile'] = pagenest_companion_service_defaults();
        return;
    }
    try {
        $GLOBALS['pagenest_companion_services_profile'] = pagenest_social_settings_merge(
            $GLOBALS['pagenest_companion_services_source'],
            pagenest_social_settings_option(),
        );
    } catch (Throwable $error) {
        $GLOBALS['pagenest_companion_services_profile'][
            'social'
        ] = pagenest_companion_service_defaults()['social'];
        $GLOBALS['pagenest_social_settings_invalid'] = true;
    }
}
function pagenest_social_settings_update($request)
{
    if (
        !current_user_can('manage_options') ||
        ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
        !is_array($request) ||
        !is_string($request['_wpnonce'] ?? null) ||
        !wp_verify_nonce($request['_wpnonce'], 'pagenest-social-settings') ||
        !($GLOBALS['pagenest_companion_services_source_valid'] ?? false)
    ) {
        throw new RuntimeException('Social settings request refused.');
    }
    return pagenest_social_lock('social-settings', static function () use ($request) {
        // Bootstrap may have read this option before another request saved it.
        // Refresh positive, negative and legacy-autoload caches under the lock.
        wp_cache_delete('pagenest_social_settings', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
        $operation = $request['operation'] ?? '';
        if ($operation === 'reset_all') {
            delete_option('pagenest_social_settings');
            if (get_option('pagenest_social_settings', null) !== null) {
                throw new RuntimeException('Unable to restore social settings.');
            }
            return;
        }
        $override = pagenest_social_settings_option();
        global $wpdb;
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Unable to read current social settings.');
        }
        $server = $GLOBALS['pagenest_companion_services_source'];
        $effective = pagenest_social_settings_merge($server, $override);
        if ($operation === 'switches') {
            $override['switches'] = [
                'enabled' => ($request['enabled'] ?? '') === '1',
                'allow_registration' => ($request['allow_registration'] ?? '') === '1',
            ];
        } elseif ($operation === 'reset_switches') {
            $override['switches'] = [];
        } elseif (in_array($operation, ['provider', 'reset_provider'], true)) {
            $provider = $request['provider'] ?? '';
            if (
                !is_string($provider) ||
                !in_array($provider, ['qq', 'baidu', 'sina', 'github', 'google', 'wechat'], true)
            ) {
                throw new RuntimeException('Unknown social provider.');
            }
            if ($operation === 'reset_provider') {
                unset($override['providers'][$provider]);
            } else {
                foreach (['client_id', 'client_secret', 'redirect_uri'] as $field) {
                    if (!is_string($request[$field] ?? null)) {
                        throw new RuntimeException('Invalid social provider form.');
                    }
                }
                $old = $effective['social']['providers'][$provider] ?? [
                    'client_id' => '',
                    'client_secret' => '',
                ];
                $enabled = ($request['enabled'] ?? '') === '1';
                $clear = ($request['clear_secret'] ?? '') === '1';
                if (
                    ($clear && $enabled) ||
                    ($clear && $request['client_secret'] !== '') ||
                    ($request['client_id'] !== $old['client_id'] &&
                        $request['client_secret'] === '' &&
                        !$clear)
                ) {
                    throw new RuntimeException(
                        'Changed application requires a new secret; clear only while disabled.',
                    );
                }
                $override['providers'][$provider] = [
                    'enabled' => $enabled,
                    'client_id' => $request['client_id'],
                    'client_secret' => $clear
                        ? ''
                        : ($request['client_secret'] !== ''
                            ? $request['client_secret']
                            : $old['client_secret']),
                    'redirect_uri' => $request['redirect_uri'],
                ];
            }
        } else {
            throw new RuntimeException('Unknown social settings operation.');
        }
        pagenest_social_settings_merge($server, $override);
        update_option('pagenest_social_settings', $override, false);
        if (get_option('pagenest_social_settings', null) !== $override) {
            throw new RuntimeException('Unable to save social settings.');
        }
    });
}
function pagenest_social_settings_post()
{
    try {
        pagenest_social_settings_update(wp_unslash($_POST));
        $status = 'saved';
    } catch (Throwable $error) {
        $status = 'failed';
    }
    wp_safe_redirect(
        add_query_arg(
            'social_status',
            $status,
            admin_url('options-general.php?page=pagenest-social'),
        ),
    );
    exit();
}
function pagenest_social_settings_form_start()
{
    return '<form method="post" action="' .
        esc_url(admin_url('admin-post.php')) .
        '"><input type="hidden" name="action" value="pagenest_social_settings">' .
        wp_nonce_field('pagenest-social-settings', '_wpnonce', false, false);
}
function pagenest_social_settings_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('无权管理社交登录。', '', ['response' => 403]);
    }
    echo '<div class="wrap"><h1>PageNest 社交登录</h1><p><a href="' .
        esc_url(admin_url('options-general.php?page=pagenest-companion')) .
        '">返回 PageNest 设置</a></p>';
    echo '<p>服务器配置作为默认值。每个供应商单独保存；保存不会测试登录或发送邮件。密钥不会回填，留空保留当前密钥。更换应用编号时请同时填写新密钥。</p>';
    if (!($GLOBALS['pagenest_companion_services_source_valid'] ?? false)) {
        echo '<div class="notice notice-error"><p>服务器服务配置无效，无法使用后台覆盖。请先修复服务器配置。</p></div></div>';
        return;
    }
    if (($_GET['social_status'] ?? '') === 'saved') {
        echo '<div class="notice notice-success"><p>设置已保存。</p></div>';
    } elseif (($_GET['social_status'] ?? '') === 'failed') {
        echo '<div class="notice notice-error"><p>未能保存。请检查应用参数、同源 HTTPS 回调、密钥和历史应用范围。更换应用编号须填写新密钥；总开关开启时须保留至少一个已启用供应商。</p></div>';
    }
    try {
        $override = pagenest_social_settings_option();
        $effective = pagenest_social_settings_merge(
            $GLOBALS['pagenest_companion_services_source'],
            $override,
        )['social'];
    } catch (Throwable $error) {
        echo '<div class="notice notice-error"><p>后台社交设置无效，社交登录已关闭。可恢复服务器配置后重新设置。</p></div>' .
            pagenest_social_settings_form_start() .
            '<button class="button" name="operation" value="reset_all">全部恢复服务器配置</button></form></div>';
        return;
    }
    echo pagenest_social_settings_form_start() .
        '<h2>总开关</h2><p>配置来源：' .
        ($override['switches'] ? '后台覆盖' : '服务器默认') .
        '</p>';
    foreach (
        ['enabled' => '启用社交登录', 'allow_registration' => '允许通过社交登录创建新账号']
        as $field => $label
    ) {
        echo '<p><label><input type="checkbox" name="' .
            $field .
            '" value="1"' .
            ($effective[$field] ? ' checked' : '') .
            '> ' .
            esc_html($label) .
            '</label></p>';
    }
    echo '<p><button class="button button-primary" name="operation" value="switches">保存总开关</button> <button class="button" name="operation" value="reset_switches">恢复服务器总开关</button></p></form><p>新注册还需 WordPress「任何人都可以注册」开启。QQ／百度等既有方式请完成新方式验收后再停用。</p>';
    foreach (
        [
            'qq' => 'QQ',
            'baidu' => '百度',
            'sina' => '微博',
            'github' => 'GitHub',
            'google' => 'Google',
            'wechat' => '微信网站应用',
        ]
        as $provider => $label
    ) {
        $fields = $effective['providers'][$provider] ?? [
            'enabled' => false,
            'client_id' => '',
            'client_secret' => '',
            'redirect_uri' => '',
        ];
        echo '<section style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin:20px 0;max-width:800px">' .
            pagenest_social_settings_form_start() .
            '<h2>' .
            esc_html($label) .
            '</h2><p>配置来源：' .
            (isset($override['providers'][$provider]) ? '后台覆盖' : '服务器默认') .
            '；密钥：' .
            ($fields['client_secret'] !== '' ? '已配置' : '未配置') .
            '</p><input type="hidden" name="provider" value="' .
            esc_attr($provider) .
            '"><p><label><input type="checkbox" name="enabled" value="1"' .
            ($fields['enabled'] ? ' checked' : '') .
            '> 启用 ' .
            esc_html($label) .
            '</label></p>';
        foreach (
            [
                'client_id' => '应用编号（Client ID / AppID）',
                'client_secret' => '应用密钥（留空保留）',
                'redirect_uri' => '回调地址',
            ]
            as $field => $field_label
        ) {
            $value = $field === 'client_secret' ? '' : $fields[$field];
            if ($field === 'redirect_uri' && $value === '') {
                $value = admin_url(
                    'admin-post.php?action=pagenest_social_callback&provider=' . $provider,
                    'https',
                );
            }
            echo '<p><label for="pn-' .
                $provider .
                '-' .
                $field .
                '">' .
                esc_html($field_label) .
                '</label><br><input style="width:100%;max-width:600px" id="pn-' .
                $provider .
                '-' .
                $field .
                '" name="' .
                $field .
                '" type="' .
                ($field === 'client_secret'
                    ? 'password'
                    : ($field === 'redirect_uri'
                        ? 'url'
                        : 'text')) .
                '" autocomplete="' .
                ($field === 'client_secret' ? 'new-password' : 'off') .
                '" maxlength="4096" value="' .
                esc_attr($value) .
                '"></p>';
        }
        echo '<p><label><input type="checkbox" name="clear_secret" value="1"> 显式清除密钥（须先取消启用）</label></p><p><button class="button button-primary" name="operation" value="provider">保存此供应商</button> <button class="button" name="operation" value="reset_provider" formnovalidate>恢复服务器配置</button></p></form></section>';
    }
    echo '</div>';
}
add_action('admin_menu', static function () {
    add_options_page(
        'PageNest 社交登录',
        'PageNest 社交登录',
        'manage_options',
        'pagenest-social',
        'pagenest_social_settings_page',
    );
});
add_action('admin_post_pagenest_social_settings', 'pagenest_social_settings_post');
