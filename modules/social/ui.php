<?php
defined('ABSPATH') || exit();
require_once __DIR__ . '/icons.php';
function pagenest_social_buttons($return = '')
{
    if (!pagenest_social_active() || is_user_logged_in()) {
        return '';
    }
    $labels = ['qq' => 'QQ', 'sina' => '微博', 'baidu' => '百度'];
    $html =
        '<div class="pagenest-social-login-options"><div class="pagenest-social-divider"><span>或使用第三方账号</span></div><div class="pagenest-social-buttons pagenest-social-providers" role="group" aria-label="社交登录">';
    foreach ($labels as $provider => $label) {
        if (!pagenest_social_provider_config($provider)) {
            continue;
        }
        $url = pagenest_social_query_url(
            [
                'action' => 'pagenest_social_start',
                'provider' => $provider,
                'return' => pagenest_social_return($return),
            ],
            admin_url('admin-post.php'),
        );
        $html .=
            '<a class="button pagenest-social-provider pagenest-social-provider--' .
            esc_attr($provider) .
            '" href="' .
            esc_url($url) .
            '">' .
            pagenest_social_provider_icon($provider) .
            '<span>使用 ' .
            esc_html($label) .
            ' 登录</span>' .
            '</a>';
    }
    return $html . '</div></div>';
}
function pagenest_social_login_panel($return = '')
{
    if (!pagenest_social_active()) {
        return '';
    }
    if (!is_user_logged_in()) {
        return pagenest_social_buttons($return);
    }
    return '<div class="pagenest-social-account-notice"><p>你已登录。可在个人资料中管理社交账号，或退出后切换账号。</p><div class="pagenest-social-buttons"><a class="button" href="' .
        esc_url(get_edit_profile_url(get_current_user_id())) .
        '">管理社交账号</a><a class="button" href="' .
        esc_url(wp_logout_url(wp_login_url(pagenest_social_return($return)))) .
        '">退出并切换账号</a></div></div>';
}
function pagenest_social_profile($user)
{
    if (!pagenest_social_active() || (int) $user->ID !== get_current_user_id()) {
        return;
    }
    echo '<h2>社交账号</h2><p>连接或解除用于登录的社交账号。</p>';
    echo '<p>连接、解除连接或发送邮箱验证前，请先确认身份；确认后十分钟内有效。</p>';
    echo '<p><label for="pn-social-password">当前密码</label> <input id="pn-social-password" type="password" name="password" autocomplete="current-password" form="pn-social-password-form"> <button class="button" type="submit" form="pn-social-password-form">确认身份</button></p>';
    $GLOBALS['pagenest_social_profile_forms'][] = [
        'id' => 'pn-social-password-form',
        'action' => 'pagenest_social_password',
    ];
    echo '<p>' .
        (pagenest_social_email_verified($user->ID)
            ? '当前邮箱已验证，可用于账号恢复。'
            : '当前邮箱尚未验证。请先保存可收信的邮箱，再发送验证邮件。') .
        '</p>';
    echo '<p><button class="button" type="submit" form="pn-social-email-form">发送邮箱验证邮件</button></p>';
    $GLOBALS['pagenest_social_profile_forms'][] = [
        'id' => 'pn-social-email-form',
        'action' => 'pagenest_social_email_request',
    ];
    $bindings = get_user_meta($user->ID, '_pagenest_social_bindings', true) ?: [];
    foreach ($bindings as $key => $binding) {
        $config = pagenest_social_provider_config($binding['provider']);
        $owner = pagenest_social_owner($key);
        if (
            $config &&
            $config['client_id'] === $binding['app'] &&
            is_array($owner) &&
            (int) $owner['user_id'] === (int) $user->ID
        ) {
            $reauth = 'pn-social-reauth-' . substr(hash('sha256', $key), 0, 16);
            echo '<p><button class="button" type="submit" form="' .
                esc_attr($reauth) .
                '">使用已连接的 ' .
                esc_html(strtoupper($binding['provider'])) .
                ' 确认身份</button></p>';
            $GLOBALS['pagenest_social_profile_forms'][] = [
                'id' => $reauth,
                'action' => 'pagenest_social_start',
                'provider' => $binding['provider'],
                'mode' => 'reauth',
            ];
        }
        $form = 'pn-social-unbind-' . substr(hash('sha256', $key), 0, 16);
        echo '<p>' .
            esc_html(strtoupper($binding['provider'])) .
            ' <button class="button" type="submit" form="' .
            esc_attr($form) .
            '">解除连接</button></p>';
        $GLOBALS['pagenest_social_profile_forms'][] = [
            'id' => $form,
            'action' => 'pagenest_social_unbind',
            'identity' => $key,
        ];
    }
    foreach (['qq' => 'QQ', 'sina' => '微博', 'baidu' => '百度'] as $provider => $label) {
        if (!pagenest_social_provider_config($provider)) {
            continue;
        }
        $form = 'pn-social-bind-' . $provider;
        echo '<p><button class="button" type="submit" form="' .
            esc_attr($form) .
            '">连接 ' .
            esc_html($label) .
            '</button></p>';
        $GLOBALS['pagenest_social_profile_forms'][] = [
            'id' => $form,
            'action' => 'pagenest_social_start',
            'provider' => $provider,
        ];
    }
}
function pagenest_social_profile_forms()
{
    foreach ($GLOBALS['pagenest_social_profile_forms'] ?? [] as $form) {
        echo '<form hidden id="' .
            esc_attr($form['id']) .
            '" method="post" action="' .
            esc_url(admin_url('admin-post.php')) .
            '">';
        foreach ($form as $name => $value) {
            if ($name !== 'id') {
                echo '<input type="hidden" name="' .
                    esc_attr($name) .
                    '" value="' .
                    esc_attr($value) .
                    '">';
            }
        }
        echo '<input type="hidden" name="return" value="' .
            esc_url(get_edit_profile_url(get_current_user_id())) .
            '">';
        wp_nonce_field(
            $form['action'] === 'pagenest_social_unbind'
                ? 'pagenest-social-unbind'
                : ($form['action'] === 'pagenest_social_start'
                    ? 'pagenest-social-bind'
                    : 'pagenest-social-security'),
        );
        echo '</form>';
    }
}
function pagenest_social_avatar($args, $id_or_email)
{
    if (
        !pagenest_social_active() ||
        (pagenest_social_config()['avatar_enabled'] ?? true) !== true
    ) {
        return $args;
    }
    $id = 0;
    if (is_numeric($id_or_email)) {
        $id = (int) $id_or_email;
    } elseif ($id_or_email instanceof WP_User) {
        $id = $id_or_email->ID;
    } elseif ($id_or_email instanceof WP_Comment) {
        $id = $id_or_email->user_id;
    } elseif ($id_or_email instanceof WP_Post) {
        $id = $id_or_email->post_author;
    } elseif (is_string($id_or_email) && is_email($id_or_email)) {
        $user = get_user_by('email', $id_or_email);
        $id = $user ? $user->ID : 0;
    }
    if ($id) {
        $avatar = pagenest_social_avatar_url(
            get_user_meta($id, '_pagenest_social_avatar', true) ?:
            get_user_meta($id, 'open_img', true),
        );
        if ($avatar) {
            $args['url'] = $avatar;
            $args['found_avatar'] = true;
        }
    }
    return $args;
}
