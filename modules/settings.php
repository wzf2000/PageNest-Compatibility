<?php
/** User controls write only neutral options/meta and leave compatibility keys intact. */
defined('ABSPATH') || exit();
function pagenest_companion_feature($name)
{
    $features = get_option('pagenest_companion_features', null);
    if ($features === null) {
        return true;
    }
    return is_array($features) && in_array($features[$name] ?? false, [true, 1, '1'], true);
}
function pagenest_companion_sanitize_features($input)
{
    $out = [];
    foreach (['comments', 'chapters', 'likes'] as $name) {
        $out[$name] =
            is_array($input) &&
            isset($input[$name]) &&
            in_array($input[$name], [true, 1, '1'], true)
                ? 1
                : 0;
    }
    return $out;
}
function pagenest_companion_post_setting($id, $field)
{
    $neutral = $field === 'series' ? '_pagenest_series' : '_pagenest_chapter_order';
    if (metadata_exists('post', $id, $neutral)) {
        return get_post_meta($id, $neutral, true);
    }
    return get_post_meta(
        $id,
        pagenest_companion_config('chapters', $field === 'series' ? 'series_meta' : 'order_meta'),
        true,
    );
}
add_action('admin_init', static function () {
    register_setting('pagenest_companion', 'pagenest_companion_features', [
        'type' => 'array',
        'sanitize_callback' => 'pagenest_companion_sanitize_features',
    ]);
});
add_action('admin_menu', static function () {
    add_options_page(
        'PageNest Companion',
        'PageNest Companion',
        'manage_options',
        'pagenest-companion',
        'pagenest_companion_settings_page',
    );
});
function pagenest_companion_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    echo '<div class="wrap"><h1>PageNest Companion</h1><p>选择读者可使用的功能。关闭功能保留文章配置与既有记录；启用段落评论还需逐篇文章选择。</p><form action="options.php" method="post">';
    settings_fields('pagenest_companion');
    foreach (
        ['comments' => '段落评论', 'chapters' => '章节导航与链接', 'likes' => '文章点赞']
        as $name => $label
    ) {
        echo '<p><label><input type="checkbox" name="pagenest_companion_features[' .
            esc_attr($name) .
            ']" value="1" ' .
            checked(pagenest_companion_feature($name), true, false) .
            '> ' .
            esc_html($label) .
            '</label></p>';
    }
    submit_button();
    echo '</form><p>旧站兼容配置由服务器管理员维护。这里的选择不会公开旧私人笔记或迁移数据库。</p></div>';
}
add_action('add_meta_boxes_post', static function () {
    add_meta_box(
        'pagenest-reading-options',
        'PageNest 阅读功能',
        'pagenest_companion_post_box',
        'post',
        'side',
    );
});
function pagenest_companion_post_box($post)
{
    if (!current_user_can('edit_post', $post->ID)) {
        return;
    }
    wp_nonce_field('pagenest-reading-options:' . $post->ID, 'pagenest-reading-options-nonce');
    $enabled = pagenest_pc_enabled($post->ID);
    echo '<p><label>段落评论<select name="pagenest-reading-comments"><option value="keep">保持现有（' .
        ($enabled ? '启用' : '停用') .
        '）</option><option value="1">启用</option><option value="0">停用</option></select></label></p><p>保存文章后，读者可围绕正文段落明确发布公开评论。旧私人记录保持私人。</p>';
    echo '<p><label>系列名称<input class="widefat" name="pagenest-reading-series" maxlength="100" value="' .
        esc_attr(pagenest_companion_post_setting($post->ID, 'series')) .
        '"></label></p>';
    echo '<p><label>章节顺序<input class="widefat" name="pagenest-reading-order" type="number" min="0" max="1000000" step="1" value="' .
        esc_attr(pagenest_companion_post_setting($post->ID, 'order')) .
        '"></label></p><p>同名系列按章节顺序排列；留空沿用文档顺序。配置只影响这篇文章。</p>';
}
function pagenest_companion_save_post_options($id)
{
    if (
        wp_is_post_revision($id) ||
        wp_is_post_autosave($id) ||
        !current_user_can('edit_post', $id) ||
        !isset($_POST['pagenest-reading-options-nonce']) ||
        !is_string($_POST['pagenest-reading-options-nonce']) ||
        !wp_verify_nonce(
            wp_unslash($_POST['pagenest-reading-options-nonce']),
            'pagenest-reading-options:' . $id,
        )
    ) {
        return;
    }
    $post = get_post($id);
    if (!$post || $post->post_type !== 'post') {
        return;
    }
    // Reject the complete update on invalid input; never partially save this form.
    $mode = $_POST['pagenest-reading-comments'] ?? 'keep';
    $series = $_POST['pagenest-reading-series'] ?? null;
    $order = $_POST['pagenest-reading-order'] ?? null;
    if (
        !is_string($mode) ||
        !in_array($mode, ['keep', '0', '1'], true) ||
        !is_string($series) ||
        !is_string($order)
    ) {
        return;
    }
    $series = sanitize_text_field(wp_unslash($series));
    $order = wp_unslash($order);
    if (
        mb_strlen($series) > 100 ||
        ($order !== '' && (!preg_match('/^[0-9]+$/D', $order) || (int) $order > 1000000))
    ) {
        return;
    }
    if ($mode !== 'keep') {
        update_post_meta($id, '_pagenest_comments_enabled', $mode);
    }
    foreach (['series' => $series, 'order' => $order] as $field => $value) {
        if ((string) pagenest_companion_post_setting($id, $field) !== $value) {
            update_post_meta(
                $id,
                $field === 'series' ? '_pagenest_series' : '_pagenest_chapter_order',
                $value,
            );
        }
    }
    if ($mode === '1' && pagenest_companion_feature('comments')) {
        pagenest_pc_refresh($id);
    }
}
add_action('save_post_post', 'pagenest_companion_save_post_options', 50);
