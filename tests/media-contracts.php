<?php
/** Synthetic permission/configuration boundaries: no WordPress/database/HTTP writes. */
define('ABSPATH', __DIR__);
$GLOBALS['options'] = [];
$GLOBALS['shortcodes'] = [];
$GLOBALS['posts'] = [];
$GLOBALS['permissions'] = [];
$GLOBALS['locked'] = [];
$GLOBALS['context'] = null;
$GLOBALS['hooks'] = [];
$GLOBALS['source_ids'] = [];
$checks = 0;
function determine_locale()
{
    return 'zh_CN';
}
function translate($text, $domain)
{
    return $text;
}
function check($condition, $message)
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function add_action($name, $callback, $priority = 10, $args = 1)
{
    $GLOBALS['hooks'][$name][] = $callback;
}
function get_option($name, $default = false)
{
    if ($name !== 'pagenest_companion_media') {
        throw new RuntimeException('Legacy or unrelated option read: ' . $name);
    }
    return $GLOBALS['options'][$name] ?? $default;
}
function update_option(...$args)
{
    throw new RuntimeException('Unexpected option write');
}
function update_post_meta(...$args)
{
    throw new RuntimeException('Unexpected metadata write');
}
function wp_update_post(...$args)
{
    throw new RuntimeException('Unexpected content write');
}
function wp_remote_get(...$args)
{
    throw new RuntimeException('Unexpected remote request');
}
function wp_safe_remote_head(...$args)
{
    throw new RuntimeException('Unexpected remote HEAD');
}
function shortcode_exists($name)
{
    return isset($GLOBALS['shortcodes'][$name]);
}
function add_shortcode($name, $callback)
{
    $GLOBALS['shortcodes'][$name] = $callback;
}
class WP_Block_Type_Registry
{
    public array $blocks = [];
    public static function get_instance()
    {
        static $instance;
        return $instance ??= new self();
    }
    public function is_registered($name)
    {
        return isset($this->blocks[$name]);
    }
}
function register_block_type($name, $args)
{
    WP_Block_Type_Registry::get_instance()->blocks[$name] = $args;
}
function get_post($id = null)
{
    return $id === null ? $GLOBALS['context'] : $GLOBALS['posts'][(int) $id] ?? null;
}
function post_password_required($post)
{
    return !empty($GLOBALS['locked'][$post->ID]);
}
function current_user_can($capability, $id = 0)
{
    return !empty($GLOBALS['permissions'][$capability][$id]);
}
function taxonomy_exists($name)
{
    return $name === 'media_folder';
}
class WP_Query
{
    public array $posts;
    public function __construct($args)
    {
        check(
            ($args['tax_query'][0]['include_children'] ?? null) === false,
            'Source does not silently include descendants',
        );
        $this->posts = $GLOBALS['source_ids'];
    }
}
function wp_get_attachment_url($id)
{
    return $GLOBALS['posts'][$id]->url ?? false;
}
function attachment_url_to_postid($url)
{
    foreach ($GLOBALS['posts'] as $post) {
        if (($post->url ?? '') === $url) {
            return $post->ID;
        }
    }
    return 0;
}
function wp_upload_dir()
{
    return ['baseurl' => 'https://example.test/uploads'];
}
function wp_parse_url($value)
{
    return parse_url($value);
}
function esc_attr($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function esc_html($value)
{
    return esc_attr($value);
}
function esc_url($value)
{
    return esc_attr($value);
}
function esc_attr__($value, $domain)
{
    return esc_attr($value);
}
function wp_json_encode($value)
{
    return json_encode($value);
}
function wp_strip_all_tags($value)
{
    return strip_tags($value);
}
function plugins_url($path, $file)
{
    return 'https://example.test/plugins/companion/' . $path;
}
function wp_enqueue_style(...$args) {}
function wp_enqueue_script(...$args) {}
function wp_localize_script(...$args) {}
function wp_get_attachment_image($id, $size, $icon, $attrs)
{
    return '<img src="https://example.test/uploads/' . $id . '.jpg" alt="Example">';
}
function wp_get_attachment_image_url($id, $size)
{
    $GLOBALS['last_image_size'][$id] = $size;
    return wp_get_attachment_url($id);
}
function get_attachment_link($id)
{
    return 'https://example.test/attachment/' . $id;
}
function get_shortcode_regex($names)
{
    return '(\\[?)\\[(' . implode('|', $names) . ')([^\\]]*)(\\/?)\\](?:(.*?)\\[\\/\\2\\])?(\\]?)';
}
function shortcode_parse_atts($text)
{
    preg_match_all('/([a-z_]+)="([^"]*)"/', $text, $matches, PREG_SET_ORDER);
    $result = [];
    foreach ($matches as $match) {
        $result[$match[1]] = $match[2];
    }
    return $result;
}
require dirname(__DIR__) . '/modules/media/bootstrap.php';
check(!pagenest_companion_media_config()['enabled'], 'Default closed');
pagenest_companion_media_bootstrap();
check(
    !$GLOBALS['shortcodes'] && !WP_Block_Type_Registry::get_instance()->blocks,
    'Default does not claim handlers',
);
$valid = [
    'schema_version' => 1,
    'enabled' => true,
    'galleries' => [
        17 => [
            'attachment_ids' => [11, 12, 13, 14, 15],
            'source' => ['taxonomy' => 'media_folder', 'term_ids' => [9]],
            'orderby' => 'ids',
        ],
    ],
];
$normalized = pagenest_companion_media_validate($valid);
check(
    $normalized['galleries'][17]['interval'] === 2000 && $normalized['galleries'][17]['gap'] === 15,
    'Neutral display defaults',
);
check(
    $normalized['galleries'][17]['source']['include_children'] === false,
    'Direct source membership is the default',
);
foreach (
    [
        ['schema_version' => 2],
        ['enabled' => '1'],
        ['galleries' => ['bad' => []]],
        ['galleries' => [17 => ['attachment_ids' => ['11']]]],
        ['galleries' => [17 => ['columns' => 99]]],
        ['galleries' => [17 => ['size' => 'large" onload="alert(1)']]],
        ['galleries' => [17 => ['source' => ['taxonomy' => 'bad field', 'term_ids' => [9]]]]],
        ['galleries' => [17 => ['url' => 'https://remote.test']]],
    ]
    as $bad
) {
    $GLOBALS['options']['pagenest_companion_media'] = array_replace($valid, $bad);
    check(!pagenest_companion_media_config()['enabled'], 'Invalid configuration fails closed');
}
$GLOBALS['options']['pagenest_companion_media'] = $valid;
$GLOBALS['shortcodes']['wpmfgallery'] = 'old_callback';
WP_Block_Type_Registry::get_instance()->blocks['wpmf/pdfembed'] = ['owner' => 'old'];
pagenest_companion_media_bootstrap();
check(
    $GLOBALS['shortcodes']['wpmfgallery'] === 'old_callback',
    'Existing shortcode owner preserved',
);
check(
    !isset(WP_Block_Type_Registry::get_instance()->blocks['wpmf/block-gallery']),
    'Shortcode conflict also leaves its block alone',
);
check(
    !isset($GLOBALS['shortcodes']['wpmfpdf']),
    'Existing block owner prevents competing shortcode',
);
$GLOBALS['shortcodes'] = [];
WP_Block_Type_Registry::get_instance()->blocks = [];
pagenest_companion_media_bootstrap();
check(
    isset($GLOBALS['shortcodes']['wpmfpdf'], $GLOBALS['shortcodes']['wpmfgallery']),
    'Enabled module claims free handlers',
);
check(
    !isset($GLOBALS['hooks']['wp_ajax_nopriv_wpmf_load_gallery']),
    'No legacy anonymous endpoints registered',
);
foreach ([11, 12, 13, 14, 15, 16, 18] as $id) {
    $GLOBALS['posts'][$id] = (object) [
        'ID' => $id,
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'post_parent' => 0,
        'post_mime_type' => 'image/jpeg',
        'post_title' => 'Example ' . $id,
        'post_excerpt' => '',
        'post_date' => '2020-01-01',
        'url' => 'https://example.test/uploads/' . $id . '.jpg',
    ];
}
$GLOBALS['posts'][100] = (object) [
    'ID' => 100,
    'post_type' => 'post',
    'post_status' => 'private',
    'post_parent' => 0,
];
$GLOBALS['posts'][101] = (object) [
    'ID' => 101,
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_parent' => 0,
];
$GLOBALS['posts'][12]->post_parent = 100;
$GLOBALS['posts'][13]->post_parent = 101;
$GLOBALS['locked'][101] = true;
$GLOBALS['posts'][14]->post_status = 'trash';
$GLOBALS['posts'][15]->post_mime_type = 'application/pdf';
$GLOBALS['posts'][15]->url = 'https://example.test/uploads/15.pdf';
$GLOBALS['posts'][18]->post_mime_type = 'image/gif';
$GLOBALS['source_ids'] = [11, 16, 18];
$members = pagenest_companion_media_members($normalized['galleries'][17]);
check(
    array_column($members, 'ID') === [11, 16, 18],
    'Union is deduplicated and filters private/password/trash/non-image members',
);
$GLOBALS['permissions']['read_post'][100] = true;
check(pagenest_companion_media_readable(12), 'Authorized private-parent reader');
check(!pagenest_companion_media_readable(13), 'Password lock still excludes attachment');
$GLOBALS['posts'][12]->post_status = 'publish';
unset($GLOBALS['permissions']['read_post'][100]);
check(!pagenest_companion_media_readable(12), 'Published attachment cannot bypass private parent');
$GLOBALS['posts'][100]->post_parent = 12;
$GLOBALS['posts'][100]->post_type = 'attachment';
$GLOBALS['posts'][100]->post_status = 'inherit';
check(!pagenest_companion_media_readable(12), 'Cyclic parent chain denied');
$html = pagenest_companion_media_gallery(['gallery_id' => 17]);
check(
    str_contains($html, 'data-attachment-id="18"') &&
        !str_contains($html, 'data-attachment-id="12"'),
    'HTML respects attachment access boundary',
);
check($GLOBALS['last_image_size'][18] === 'full', 'GIF lightbox uses original GIF');
check(
    str_contains($html, '<a') && !str_contains($html, 'visibility: hidden'),
    'No-JS image links remain visible',
);
check(
    pagenest_companion_media_gallery(['gallery_id' => 17, 'columns' => '3" onclick="x']) === '',
    'Injected display parameter rejected',
);
check(
    pagenest_companion_media_gallery(['gallery_id' => '17evil']) === '',
    'Malformed gallery identity rejected',
);
check(
    pagenest_companion_media_gallery(['gallery_id' => 17, 'wpmf_orderby' => []]) === '',
    'Array display injection fails closed',
);
check(
    pagenest_companion_media_pdf(['id' => '15evil', 'embed' => 1]) === '',
    'Malformed attachment identity rejected',
);
check(pagenest_companion_media_pdf(['id' => 11, 'embed' => 1]) === '', 'PDF requires PDF MIME');
check(
    pagenest_companion_media_pdf([
        'id' => 15,
        'url' => 'https://remote.test/file.pdf',
        'embed' => 1,
    ]) === '',
    'Remote URL rejected without HTTP',
);
check(
    pagenest_companion_media_pdf([
        'url' => 'https://user@example.test/uploads/15.pdf',
        'embed' => 1,
    ]) === '',
    'URL credentials rejected',
);
check(
    pagenest_companion_media_pdf([
        'url' => 'https://example.test/uploads/missing.pdf',
        'embed' => 1,
    ]) === '',
    'Unknown local URL rejected',
);
check(
    pagenest_companion_media_pdf([
        'id' => 11,
        'url' => 'https://example.test/uploads/15.pdf',
        'embed' => 1,
    ]) === '',
    'ID and URL must agree',
);
$pdf = pagenest_companion_media_pdf([
    'id' => 15,
    'embed' => 1,
    'width' => '100%" onload="x',
    'height' => '999999',
    'target' => 'evil',
]);
check(
    str_contains($pdf, 'width="100%" height="800"') &&
        !str_contains($pdf, 'onload') &&
        !str_contains($pdf, 'target="evil"'),
    'PDF dimensions and target are constrained',
);
check(
    str_contains($pdf, 'pdfjs-6.4.299/web/viewer.html?file=') && str_contains($pdf, '<a href='),
    'Independent PDF viewer and native fallback link',
);
check(
    !str_contains(pagenest_companion_media_pdf(['id' => 15, 'embed' => 'off']), '<iframe'),
    'Link-only PDF semantics',
);
$GLOBALS['posts'][15]->post_parent = 101;
check(
    pagenest_companion_media_pdf(['id' => 15, 'embed' => 1]) === '',
    'Password parent protects PDF',
);
$GLOBALS['posts'][15]->post_parent = 0;
$render = pagenest_companion_media_block_render(
    'wpmfpdf',
    ['id' => 11],
    '[wpmfpdf id="15" embed="1" height="600"]',
);
check(
    str_contains($render, 'height="600"'),
    'Stored inner shortcode overrides block attributes without content writes',
);
$GLOBALS['context'] = $GLOBALS['posts'][101];
check(
    pagenest_companion_media_gallery(['gallery_id' => 17]) === '' &&
        pagenest_companion_media_pdf(['id' => 15, 'embed' => 1]) === '',
    'Locked containing post cannot render through preview callback',
);
check(
    $GLOBALS['options']['pagenest_companion_media'] === $valid,
    'Reads and renders leave neutral and legacy storage untouched',
);
check(pagenest_companion_media_validate($valid)['library_taxonomy'] === '', 'Folder UI is opt-in');
try {
    pagenest_companion_media_validate(array_merge($valid, ['library_taxonomy' => '../invalid']));
    check(false, 'Invalid taxonomy must fail');
} catch (InvalidArgumentException $error) {
    check(true, 'Invalid taxonomy rejected');
}
function get_taxonomy($name)
{
    return $GLOBALS['folder_taxonomy'] ?? false;
}
$GLOBALS['options']['pagenest_companion_media'] = array_merge($valid, [
    'library_taxonomy' => 'folders',
]);
check(pagenest_companion_media_library_taxonomy() === null, 'Folder UI requires upload permission');
$GLOBALS['permissions']['upload_files'][0] = true;
$GLOBALS['folder_taxonomy'] = (object) [
    'name' => 'folders',
    'hierarchical' => true,
    'object_type' => ['attachment'],
    'cap' => (object) ['assign_terms' => 'assign_folders'],
];
check(
    pagenest_companion_media_library_taxonomy() === null,
    'Folder UI requires assignment capability',
);
$GLOBALS['permissions']['assign_folders'][0] = true;
check(
    pagenest_companion_media_library_taxonomy() !== null,
    'Authorized attachment taxonomy accepted',
);
$GLOBALS['folder_taxonomy']->object_type = ['post'];
check(pagenest_companion_media_library_taxonomy() === null, 'Post-only taxonomy excluded');
check(
    !isset($GLOBALS['hooks']['wp_ajax_nopriv_pagenest_media_folders']),
    'Folder refresh has no anonymous endpoint',
);
check(pagenest_companion_media_labels()['folders'] === '文件夹', 'Chinese locale labels');
echo "Media contracts: {$checks} checks passed.\n";
