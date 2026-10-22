<?php
/** User controls write only neutral options/meta and leave compatibility keys intact. */
defined('ABSPATH') || exit();
require_once dirname(__DIR__) . '/admin-design.php';
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
    pagenest_companion_admin_header(
        '阅读与互动',
        '为读者开启合适的互动方式。关闭功能会保留已有内容和记录。',
        'pagenest-companion',
    );
    settings_errors('pagenest_companion_series_directories');
    echo '<div class="pn-admin-layout"><form class="pn-admin-main" action="options.php" method="post">';
    settings_fields('pagenest_companion');
    echo '<section class="pn-admin-section"><h2>文章互动</h2><p>按需选择，沿用每篇文章的配置。</p>';
    foreach (
        [
            'comments' => [
                '段落评论',
                '读者可围绕段落讨论；还需在具体文章中开启。旧私人笔记保持私人。',
            ],
            'chapters' => ['章节导航与链接', '连接同系列内容，让读者按章节继续阅读。'],
            'likes' => ['文章点赞', '保留轻量的文章反馈入口。'],
        ]
        as $name => [$label, $help]
    ) {
        echo '<div class="pn-admin-setting"><label><input type="checkbox" name="pagenest_companion_features[' .
            esc_attr($name) .
            ']" value="1" ' .
            checked(pagenest_companion_feature($name), true, false) .
            '><span>' .
            esc_html($label) .
            '</span></label><p class="pn-admin-description">' .
            esc_html($help) .
            '</p></div>';
    }
    echo '</section>';
    pagenest_companion_series_settings_fields();
    $views = pagenest_companion_views_settings();
    echo '<section class="pn-admin-section"><h2>浏览计数</h2><p>记录公开文章和页面的访问。默认关闭，不关联经验奖励。</p>';
    foreach (['enabled' => '启用浏览计数', 'exclude_bots' => '排除已知机器人'] as $key => $label) {
        echo '<input type="hidden" name="pagenest_companion_views_settings[' .
            esc_attr($key) .
            ']" value="0"><div class="pn-admin-setting"><label><input type="checkbox" name="pagenest_companion_views_settings[' .
            esc_attr($key) .
            ']" value="1" ' .
            checked($views[$key], 1, false) .
            '><span>' .
            esc_html($label) .
            '</span></label></div>';
    }
    echo '<div class="pn-admin-field"><label for="pn-view-audience">统计人群</label><select id="pn-view-audience" name="pagenest_companion_views_settings[count]">';
    foreach ([0 => '所有人', 1 => '访客', 2 => '登录用户'] as $value => $label) {
        echo '<option value="' .
            esc_attr($value) .
            '" ' .
            selected($views['count'], $value, false) .
            '>' .
            esc_html($label) .
            '</option>';
    }
    echo '</select></div><div class="pn-admin-field"><label for="pn-view-template">显示文字</label><input id="pn-view-template" type="text" class="regular-text" name="pagenest_companion_views_settings[template]" aria-describedby="pn-view-help" value="' .
        esc_attr($views['template']) .
        '"><p id="pn-view-help">使用 <code>%VIEW_COUNT%</code> 显示次数，支持纯文本。</p></div></section>';
    submit_button('保存阅读设置');
    echo '</form><aside class="pn-admin-help"><h2>先配置，再保存</h2><p>本页更改在保存后生效，已有评论、点赞和计数不会因关闭功能而删除。</p><h2>关于浏览量</h2><p>仅对实际渲染的公开页面计数；缓存命中可能不计入。启用不会扫描或迁移旧文章。</p><h2>更多站点能力</h2><p>搜索、媒体和社交登录各有独立设置页，不必在这里一次完成。</p></aside></div></div>';
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
