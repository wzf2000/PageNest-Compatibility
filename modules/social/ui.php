<?php
defined('ABSPATH') || exit();
function pagenest_social_buttons($return = '')
{
    if (!pagenest_social_active() || is_user_logged_in()) {
        return '';
    }
    $labels = ['qq' => 'QQ', 'sina' => '微博', 'baidu' => '百度'];
    $html = '<div class="pagenest-social-buttons" aria-label="社交登录">';
    foreach ($labels as $provider => $label) {
        if (!pagenest_social_provider_config($provider)) {
            continue;
        }
        $url = add_query_arg(
            [
                'action' => 'pagenest_social_start',
                'provider' => $provider,
                'return' => pagenest_social_return($return),
            ],
            admin_url('admin-post.php'),
        );
        $html .=
            '<a class="button" href="' .
            esc_url($url) .
            '">' .
            esc_html($label . ' 登录') .
            '</a> ';
    }
    return $html . '</div>';
}
function pagenest_social_profile($user)
{
    if (!pagenest_social_active() || (int) $user->ID !== get_current_user_id()) {
        return;
    }
    echo '<h2>社交账号</h2><p>连接或解除用于登录的社交账号。</p>';
    $bindings = get_user_meta($user->ID, '_pagenest_social_bindings', true) ?: [];
    foreach ($bindings as $key => $binding) {
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
                : 'pagenest-social-bind',
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
