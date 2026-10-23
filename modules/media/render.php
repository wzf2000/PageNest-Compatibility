<?php
/** Attachment access checks and progressive HTML rendering. */
defined('ABSPATH') || exit();

/** Built-in English/Chinese labels; installed translations retain precedence. */
function pagenest_companion_media_labels(): array
{
    $chinese = strpos(determine_locale(), 'zh_') === 0;
    $messages = [
        'previousImages' => ['Previous images', '上一组图片'],
        'nextImages' => ['Next images', '下一组图片'],
        'playSlideshow' => ['Play slideshow', '播放幻灯片'],
        'pauseSlideshow' => ['Pause slideshow', '暂停幻灯片'],
        'imageViewer' => ['Image viewer', '图片查看器'],
        'previousImage' => ['Previous image', '上一张图片'],
        'nextImage' => ['Next image', '下一张图片'],
        'closeViewer' => ['Close viewer', '关闭查看器'],
        'folders' => ['Folders', '文件夹'],
        'library' => ['Media Library', '媒体库'],
        'parentFolder' => ['Parent folder', '上一级文件夹'],
        'searchFolders' => ['Find folder…', '搜索文件夹…'],
        'allFiles' => ['All files', '全部文件'],
        'manageFolders' => ['Manage folders', '管理／新建文件夹'],
        'noFolders' => ['No matching folders', '没有匹配的文件夹'],
        'refreshFolders' => ['Refresh folders', '刷新文件夹'],
        'noSubfolders' => ['No subfolders', '没有子文件夹'],
        'unassigned' => ['Unassigned', '未分类'],
        'foldersUnavailable' => ['Unable to refresh folders', '无法刷新文件夹'],
    ];
    $labels = [];
    foreach ($messages as $key => [$english, $chinese_text]) {
        $translated = translate($english, 'pagenest-companion');
        $labels[$key] = $chinese && $translated === $english ? $chinese_text : $translated;
    }
    return $labels;
}

function pagenest_companion_media_id($value): int
{
    return (is_int($value) || is_string($value)) &&
        preg_match('/^[1-9][0-9]*$/D', (string) $value) &&
        (string) (int) $value === (string) $value
        ? (int) $value
        : 0;
}

function pagenest_companion_media_readable($id, array $visited = []): bool
{
    $post = get_post((int) $id);
    if (
        !$post ||
        isset($visited[$post->ID]) ||
        count($visited) > 30 ||
        post_password_required($post)
    ) {
        return false;
    }
    $visited[$post->ID] = true;
    if (
        $post->post_type === 'attachment' &&
        $post->post_parent &&
        !pagenest_companion_media_readable($post->post_parent, $visited)
    ) {
        return false;
    }
    if ($post->post_type === 'attachment' && $post->post_status === 'inherit') {
        return true;
    }
    return $post->post_status === 'publish' ||
        (in_array($post->post_status, ['private', 'draft', 'pending', 'future'], true) &&
            current_user_can('read_post', $post->ID));
}

function pagenest_companion_media_context_readable(): bool
{
    $post = get_post();
    return !$post || pagenest_companion_media_readable($post->ID);
}

function pagenest_companion_media_assets(bool $editor = false): void
{
    $root = dirname(__DIR__, 2);
    $manifest = require $root . '/assets/manifest.php';
    if (isset($manifest['media_css'])) {
        wp_enqueue_style(
            'pagenest-media',
            plugins_url('assets/' . $manifest['media_css'], $root . '/pagenest-compatibility.php'),
            [],
            null,
        );
    }
    $key = $editor ? 'media_editor_js' : 'media_js';
    if (isset($manifest[$key])) {
        wp_enqueue_script(
            'pagenest-media' . ($editor ? '-editor' : ''),
            plugins_url('assets/' . $manifest[$key], $root . '/pagenest-compatibility.php'),
            $editor
                ? [
                    'wp-blocks',
                    'wp-element',
                    'wp-block-editor',
                    'wp-components',
                    'wp-i18n',
                    'wp-server-side-render',
                ]
                : [],
            null,
            true,
        );
        if (!$editor) {
            wp_localize_script('pagenest-media', 'PageNestMedia', [
                'pdfViewerUrl' => plugins_url(
                    'assets/vendor/pdfjs-6.4.299/web/viewer.html',
                    $root . '/pagenest-compatibility.php',
                ),
                'pdfViewerStyles' => plugins_url(
                    'assets/' . $manifest['pdf_viewer_css'],
                    $root . '/pagenest-compatibility.php',
                ),
            ]);
        }
        if ($editor) {
            wp_localize_script('pagenest-media-editor', 'pagenestCompanionMediaEditor', [
                'blocks' => array_keys($GLOBALS['pagenest_companion_media_owned'] ?? []),
            ]);
        }
    }
}

function pagenest_companion_media_gallery_settings(array $attrs): ?array
{
    $profile = pagenest_companion_media_config();
    $id = pagenest_companion_media_id($attrs['gallery_id'] ?? 0);
    if (!$profile['enabled'] || !isset($profile['galleries'][$id])) {
        return null;
    }
    $config = $profile['galleries'][$id];
    $aliases = [
        'display' => 'layout',
        'gutterwidth' => 'gap',
        'targetsize' => 'target_size',
        'wpmf_orderby' => 'orderby',
        'wpmf_order' => 'order',
        'auto_animation' => 'autoplay',
        'duration' => 'interval',
    ];
    foreach (
        [
            'columns',
            'size',
            'link',
            'layout',
            'gap',
            'target_size',
            'orderby',
            'order',
            'autoplay',
            'interval',
            'lightbox',
        ]
        as $key
    ) {
        if (isset($attrs[$key])) {
            $config[$key] = $attrs[$key];
        }
    }
    foreach ($aliases as $old => $new) {
        if (isset($attrs[$old])) {
            $config[$new] = $attrs[$old];
        }
    }
    foreach (['columns', 'gap', 'interval'] as $key) {
        if (is_string($config[$key]) && preg_match('/^[0-9]+$/D', $config[$key])) {
            $config[$key] = (int) $config[$key];
        }
    }
    foreach (['autoplay', 'lightbox'] as $key) {
        if (in_array($config[$key], ['0', '1', 0, 1], true)) {
            $config[$key] = (bool) $config[$key];
        }
    }
    if (!is_string($config['orderby'])) {
        return null;
    }
    $config['orderby'] =
        ['rand' => 'random', 'post__in' => 'ids', 'ID' => 'id'][$config['orderby']] ??
        $config['orderby'];
    if ($config['layout'] === 'default') {
        $config['layout'] = 'grid';
    }
    try {
        return pagenest_companion_media_validate([
            'schema_version' => 1,
            'enabled' => true,
            'galleries' => [$id => $config],
        ])['galleries'][$id];
    } catch (InvalidArgumentException $error) {
        return null;
    }
}

function pagenest_companion_media_members(array $config): array
{
    $ids = $config['attachment_ids'];
    if (!empty($config['source']['term_ids']) && taxonomy_exists($config['source']['taxonomy'])) {
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
            'tax_query' => [
                [
                    'taxonomy' => $config['source']['taxonomy'],
                    'field' => 'term_id',
                    'terms' => $config['source']['term_ids'],
                    'include_children' => $config['source']['include_children'],
                ],
            ],
        ]);
        $ids = array_merge($ids, array_map('intval', $query->posts));
    }
    $posts = [];
    foreach (array_unique($ids) as $id) {
        $post = get_post($id);
        if (
            $post &&
            $post->post_type === 'attachment' &&
            strpos($post->post_mime_type, 'image/') === 0 &&
            pagenest_companion_media_readable($id)
        ) {
            $posts[] = $post;
        }
    }
    if ($config['orderby'] === 'random') {
        shuffle($posts);
    } elseif ($config['orderby'] !== 'ids') {
        $field = ['date' => 'post_date', 'title' => 'post_title', 'id' => 'ID'][$config['orderby']];
        usort($posts, static fn($a, $b) => $a->{$field} <=> $b->{$field});
    }
    if ($config['orderby'] !== 'random' && $config['order'] === 'DESC') {
        $posts = array_reverse($posts);
    }
    return $posts;
}

function pagenest_companion_media_gallery($attrs): string
{
    if (!pagenest_companion_media_context_readable()) {
        return '';
    }
    $config = pagenest_companion_media_gallery_settings(is_array($attrs) ? $attrs : []);
    if (!$config) {
        return '';
    }
    return pagenest_companion_media_gallery_render($config);
}

function pagenest_companion_media_gallery_render(array $config): string
{
    if (!pagenest_companion_media_context_readable()) {
        return '';
    }
    $items = pagenest_companion_media_members($config);
    if (!$items) {
        return '';
    }
    pagenest_companion_media_assets();
    $options = array_intersect_key(
        $config,
        array_flip(['layout', 'columns', 'autoplay', 'interval', 'lightbox']),
    );
    $options['labels'] = pagenest_companion_media_labels();
    $html =
        '<section class="pagenest-media pagenest-media--' .
        esc_attr($config['layout']) .
        '" aria-label="' .
        esc_attr__('Image gallery', 'pagenest-companion') .
        '" data-pagenest-media="' .
        esc_attr(wp_json_encode($options)) .
        '" style="--pagenest-media-columns:' .
        (int) $config['columns'] .
        ';--pagenest-media-gap:' .
        (int) $config['gap'] .
        'px"><div class="pagenest-media-track">';
    foreach ($items as $index => $item) {
        $image = wp_get_attachment_image($item->ID, $config['size'], false, [
            'class' => 'pagenest-media-image',
            'loading' => 'lazy',
        ]);
        if (!$image) {
            continue;
        }
        $full = wp_get_attachment_image_url(
            $item->ID,
            $item->post_mime_type === 'image/gif' ? 'full' : $config['target_size'],
        );
        $href = $config['link'] === 'post' ? get_attachment_link($item->ID) : $full;
        $caption = wp_strip_all_tags(
            $item->post_excerpt !== '' ? $item->post_excerpt : $item->post_title,
        );
        $html .=
            '<figure class="pagenest-media-item" data-attachment-id="' . (int) $item->ID . '">';
        if ($config['link'] !== 'none' && $href) {
            $html .=
                '<a class="pagenest-media-link" href="' .
                esc_url($href) .
                '"' .
                ($config['link'] === 'file' && $config['lightbox']
                    ? ' data-pagenest-lightbox="1" data-caption="' . esc_attr($caption) . '"'
                    : '') .
                '>' .
                $image .
                '</a>';
        } else {
            $html .= $image;
        }
        if (!empty($config['captions']) && trim($item->post_excerpt) !== '') {
            $html .=
                '<figcaption>' . esc_html(wp_strip_all_tags($item->post_excerpt)) . '</figcaption>';
        }
        $html .= '</figure>';
    }
    return $html . '</div></section>';
}

function pagenest_companion_media_local_url(string $url): bool
{
    $uploads = wp_upload_dir();
    $base = wp_parse_url($uploads['baseurl']);
    $parts = wp_parse_url($url);
    return is_array($parts) &&
        is_array($base) &&
        !isset($parts['user']) &&
        !isset($parts['pass']) &&
        !isset($parts['query']) &&
        !isset($parts['fragment']) &&
        in_array($parts['scheme'] ?? '', ['http', 'https'], true) &&
        ($parts['scheme'] ?? '') === ($base['scheme'] ?? '') &&
        ($parts['host'] ?? '') === ($base['host'] ?? '') &&
        ($parts['port'] ?? null) === ($base['port'] ?? null) &&
        strpos($parts['path'] ?? '', rtrim($base['path'] ?? '', '/') . '/') === 0;
}

function pagenest_companion_media_dimension($value, bool $width): string
{
    $default = $width ? '100%' : '800';
    if (!is_scalar($value)) {
        return $default;
    }
    $value = (string) $value;
    if ($width && preg_match('/^([1-9][0-9]?|100)%$/D', $value)) {
        return $value;
    }
    if (preg_match('/^[0-9]{2,4}$/D', $value) && (int) $value >= 100 && (int) $value <= 3000) {
        return (string) (int) $value;
    }
    return $default;
}

function pagenest_companion_media_pdf($attrs): string
{
    if (
        !pagenest_companion_media_config()['enabled'] ||
        !pagenest_companion_media_context_readable()
    ) {
        return '';
    }
    $attrs = is_array($attrs) ? $attrs : [];
    $id = pagenest_companion_media_id($attrs['id'] ?? 0);
    if (!empty($attrs['url'])) {
        if (!is_string($attrs['url']) || !pagenest_companion_media_local_url($attrs['url'])) {
            return '';
        }
        $resolved = attachment_url_to_postid($attrs['url']);
        if (
            !$resolved ||
            ($id && $id !== $resolved) ||
            wp_get_attachment_url($resolved) !== $attrs['url']
        ) {
            return '';
        }
        $id = $resolved;
    }
    $post = get_post($id);
    if (
        !$post ||
        $post->post_type !== 'attachment' ||
        $post->post_mime_type !== 'application/pdf' ||
        !pagenest_companion_media_readable($id)
    ) {
        return '';
    }
    $url = wp_get_attachment_url($id);
    if (!is_string($url) || !pagenest_companion_media_local_url($url)) {
        return '';
    }
    $link =
        '<a href="' .
        esc_url($url) .
        '"' .
        (($attrs['target'] ?? '') === '_blank'
            ? ' target="_blank" rel="noopener noreferrer"'
            : '') .
        '>' .
        esc_html($post->post_title) .
        '</a>';
    if (empty($attrs['embed']) || in_array($attrs['embed'], ['off', '0'], true)) {
        return $link;
    }
    $viewer = plugins_url(
        'assets/vendor/pdfjs-6.4.299/web/viewer.html',
        dirname(__DIR__, 2) . '/pagenest-compatibility.php',
    );
    $width = pagenest_companion_media_dimension($attrs['width'] ?? '', true);
    $height = pagenest_companion_media_dimension($attrs['height'] ?? '', false);
    pagenest_companion_media_assets();
    return '<div class="pagenest-media-pdf"><iframe class="pagenest-media-pdf-frame" data-pagenest-pdf-viewer title="' .
        esc_attr__('PDF viewer', 'pagenest-companion') .
        '" src="' .
        esc_url($viewer . '?file=' . rawurlencode($url)) .
        '" width="' .
        esc_attr($width) .
        '" height="' .
        esc_attr($height) .
        '" loading="lazy" allowfullscreen></iframe><p>' .
        $link .
        '</p></div>';
}
