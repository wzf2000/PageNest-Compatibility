<?php
defined('ABSPATH') || exit();
require_once __DIR__ . '/icons.php';
function pagenest_social_provider_labels()
{
    return [
        'qq' => 'QQ',
        'baidu' => '百度',
        'github' => 'GitHub',
        'google' => 'Google',
        'wechat' => '微信',
        'sina' => '微博',
    ];
}
function pagenest_social_buttons($return = '')
{
    if (!pagenest_social_active() || is_user_logged_in()) {
        return '';
    }
    $labels = pagenest_social_provider_labels();
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
    $GLOBALS['pagenest_social_profile_forms'] = [];
    $add = static function ($id, $action, $extra = []) {
        $GLOBALS['pagenest_social_profile_forms'][] = array_merge(
            ['id' => $id, 'action' => $action],
            $extra,
        );
    };
    $button = static function ($id, $label, $style = 'secondary') {
        $style = in_array($style, ['primary', 'danger'], true) ? $style : 'secondary';
        return '<button class="button pn-social-button--' .
            $style .
            '" type="submit" form="' .
            esc_attr($id) .
            '">' .
            esc_html($label) .
            '</button>';
    };
    echo '<section id="pagenest-social-account" class="pagenest-social-account" aria-labelledby="pn-social-title"><h2 id="pn-social-title">社交账号</h2><p class="pn-social-intro">管理登录方式与账号恢复。连接和解除连接前需要确认身份。</p>';
    $flash = pagenest_social_flash_take();
    if ($flash) {
        echo '<div class="pn-social-notice pn-social-notice--' .
            esc_attr($flash[0]) .
            '" role="' .
            ($flash[0] === 'error' ? 'alert' : 'status') .
            '">' .
            esc_html($flash[1]) .
            '</div>';
    }
    $session = pagenest_social_session_fingerprint($user->ID);
    $at = $session ? (int) get_transient('pn_social_recent_' . $session) : 0;
    $recent = $at > time() - 600 && $at <= time();
    echo '<div class="pn-social-security-grid"><section class="pn-social-card" aria-labelledby="pn-social-security-title"><h3 id="pn-social-security-title">安全确认</h3><p class="pn-social-status">' .
        ($recent ? '近期已确认身份' : '请先确认身份') .
        '</p><p class="pn-social-help">' .
        ($recent
            ? '本次确认有效至 ' . esc_html(wp_date('H:i:s', $at + 600)) . '，之后操作需重新确认。'
            : '使用当前密码，或下方已连接的社交账号确认。确认后十分钟内有效。') .
        '</p><label for="pn-social-password">当前密码</label><div class="pn-social-password-row"><input id="pn-social-password" type="password" name="password" autocomplete="current-password" required form="pn-social-password-form">' .
        $button('pn-social-password-form', '确认身份', 'primary') .
        '</div></section>';
    $add('pn-social-password-form', 'pagenest_social_password');
    $verified = pagenest_social_email_verified($user->ID);
    echo '<section class="pn-social-card" aria-labelledby="pn-social-email-title"><h3 id="pn-social-email-title">恢复邮箱</h3><p class="pn-social-status">' .
        ($verified ? '邮箱已验证' : '邮箱尚未验证') .
        '</p><p class="pn-social-help">' .
        ($verified
            ? '当前已保存邮箱可用于账号恢复。更改邮箱后需要重新验证。'
            : '先在个人资料中保存可收信的邮箱，再发送验证邮件。邮件提交发送不代表已经送达。') .
        '</p>';
    if (!$verified) {
        echo $button('pn-social-email-form', '发送验证邮件');
        $add('pn-social-email-form', 'pagenest_social_email_request');
    }
    echo '</section></div><h3 class="pn-social-section-title">登录方式</h3><div class="pn-social-provider-grid">';
    $bindings = get_user_meta($user->ID, '_pagenest_social_bindings', true);
    $bindings = is_array($bindings) ? $bindings : [];
    $historical = [];
    foreach (pagenest_social_provider_labels() as $provider => $label) {
        $config = pagenest_social_provider_config($provider);
        $current = [];
        foreach ($bindings as $key => $binding) {
            if (($binding['provider'] ?? '') !== $provider) {
                continue;
            }
            $owner = pagenest_social_owner($key);
            if (
                $config &&
                $config['client_id'] === ($binding['app'] ?? '') &&
                is_array($owner) &&
                (int) ($owner['user_id'] ?? 0) === (int) $user->ID
            ) {
                $current[$key] = $binding;
            } else {
                $historical[$key] = $label;
            }
        }
        if (!$config) {
            continue;
        }
        echo '<section class="pn-social-card pagenest-social-provider--' .
            esc_attr($provider) .
            '" aria-label="' .
            esc_attr($label) .
            ' 登录方式"><div class="pn-social-provider-heading">' .
            pagenest_social_provider_icon($provider) .
            '<h4>' .
            esc_html($label) .
            '</h4><span class="pn-social-badge' .
            ($current ? ' pn-social-badge--connected' : '') .
            '">' .
            ($current ? '已连接' : '未连接') .
            '</span></div><p class="pn-social-help">' .
            ($current
                ? '可使用此账号登录本站，也可用于确认身份。'
                : '连接后可使用此账号登录本站。') .
            '</p><div class="pn-social-actions">';
        if ($current) {
            $reauth = 'pn-social-reauth-' . $provider;
            echo $button($reauth, '确认身份', 'primary');
            $add($reauth, 'pagenest_social_start', ['provider' => $provider, 'mode' => 'reauth']);
            foreach ($current as $key => $binding) {
                $form = 'pn-social-unbind-' . substr(hash('sha256', $key), 0, 16);
                echo $button($form, '解除连接', 'danger');
                $add($form, 'pagenest_social_unbind', ['identity' => $key]);
            }
        } else {
            $form = 'pn-social-bind-' . $provider;
            echo $button($form, '连接 ' . $label, 'primary');
            $add($form, 'pagenest_social_start', ['provider' => $provider]);
        }
        echo '</div></section>';
    }
    echo '</div>';
    if ($historical) {
        echo '<section class="pn-social-history" aria-labelledby="pn-social-history-title"><h3 id="pn-social-history-title">历史连接</h3><p class="pn-social-help">以下连接不属于当前可用的登录配置，当前不可用于登录。可确认身份后解除连接。</p>';
        foreach ($historical as $key => $label) {
            $form = 'pn-social-unbind-' . substr(hash('sha256', $key), 0, 16);
            echo '<div class="pn-social-history-row"><span>' .
                esc_html($label) .
                ' · 历史连接，当前不可用于登录</span>' .
                $button($form, '解除历史连接', 'danger') .
                '</div>';
            $add($form, 'pagenest_social_unbind', ['identity' => $key]);
        }
        echo '</section>';
    }
    echo '</section>';
}
function pagenest_social_profile_forms()
{
    $forms = $GLOBALS['pagenest_social_profile_forms'] ?? [];
    $GLOBALS['pagenest_social_profile_forms'] = [];
    foreach ($forms as $form) {
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
