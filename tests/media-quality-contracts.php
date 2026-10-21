<?php
/** Isolated attachment editing and bounded readable-content reference checks. */
define('ABSPATH', __DIR__);
$hooks = $meta = $candidates = $blocks = [];
$writes = 0;
$authorized = true;
$full_scope = true;
$post = (object) [
    'ID' => 4,
    'post_type' => 'attachment',
    'post_excerpt' => 'original',
    'post_author' => 7,
    'post_parent' => 90,
];
function add_action($key, $callback, ...$args)
{
    $GLOBALS['hooks'][$key][] = $callback;
}
function add_filter(...$args)
{
    add_action(...$args);
}
function get_post($id)
{
    return $id === 4 ? $GLOBALS['post'] : null;
}
function current_user_can($cap, $id = 0)
{
    if (
        in_array(
            $cap,
            ['read_private_posts', 'read_private_pages', 'edit_others_posts', 'edit_others_pages'],
            true,
        )
    ) {
        return $GLOBALS['full_scope'];
    }
    return $GLOBALS['authorized'] && $id !== 88;
}
function absint($value)
{
    return abs((int) $value);
}
function wp_verify_nonce($value, $action)
{
    return $value === 'valid';
}
function get_current_user_id()
{
    return 7;
}
function wp_attachment_is_image($id)
{
    return $id === 4;
}
function get_post_meta($id, $key, $single)
{
    return $GLOBALS['meta'][$id][$key] ?? '';
}
function update_post_meta($id, $key, $value)
{
    $GLOBALS['meta'][$id][$key] = $value;
    $GLOBALS['writes']++;
    return true;
}
function wp_update_post($args, $error)
{
    $GLOBALS['post']->post_excerpt = $args['post_excerpt'];
    $GLOBALS['writes']++;
    return 4;
}
function sanitize_text_field($text)
{
    return strip_tags($text);
}
function wp_kses_post($text)
{
    return $text;
}
function sanitize_textarea_field($text)
{
    return strip_tags($text);
}
function wp_slash($value)
{
    return $value;
}
function is_wp_error($value)
{
    return false;
}
function clean_post_cache($id) {}
function wp_get_attachment_url($id)
{
    return '/uploads/image.jpg';
}
function wp_get_attachment_image_url($id, $size)
{
    return '/uploads/image-150.jpg';
}
function get_intermediate_image_sizes()
{
    return ['thumbnail'];
}
function parse_blocks($content)
{
    return $GLOBALS['blocks'];
}
function get_shortcode_regex($tags)
{
    return '(\\[?)(gallery)\\s*([^\\]]*)\\](?:(x)(x))?(\\]?)';
}
function shortcode_parse_atts($text)
{
    return ['ids' => trim(explode('=', $text)[1] ?? '', '" ')];
}
class WP_Query
{
    public $posts;
    public function __construct($args)
    {
        if (
            $args['posts_per_page'] !== 21 ||
            $args['no_found_rows'] !== true ||
            $args['offset'] !== 20
        ) {
            throw new RuntimeException('Unbounded reference query');
        }
        $this->posts = isset($args['author'])
            ? array_values(
                array_filter(
                    $GLOBALS['candidates'],
                    static fn($post) => $post->post_author === $args['author'],
                ),
            )
            : $GLOBALS['candidates'];
    }
}
$wpdb = new class {
    public $posts = 'posts';
    public $postmeta = 'postmeta';
    public $last_error = '';
    public function get_results($sql)
    {
        return [];
    }
    public function query($sql)
    {
        return true;
    }
    public function prepare($sql, $id)
    {
        return str_contains($sql, 'ENGINE') ? 'INNODB' : $id;
    }
    public function get_var($id)
    {
        return $id;
    }
};
require dirname(__DIR__) . '/modules/media/quality.php';
function check($ok, $label)
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}
$input = [
    'alt' => 'new alt',
    'caption' => 'new caption',
    'expected_alt' => '',
    'expected_caption' => 'original',
];
$authorized = false;
check(
    pagenest_media_quality_save(4, $input) === 'forbidden' && $writes === 0,
    'No attachment edit permission means no writes',
);
$authorized = true;
$input['expected_caption'] = 'stale';
check(
    pagenest_media_quality_save(4, $input) === 'conflict' && $writes === 0,
    'Stale caption prevents both writes',
);
$input['expected_caption'] = 'original';
$meta[4]['_wp_attachment_image_alt'] = 'changed elsewhere';
check(
    pagenest_media_quality_save(4, $input) === 'conflict' && $writes === 0,
    'Stale alt prevents both writes',
);
$input['expected_alt'] = 'changed elsewhere';
check(
    pagenest_media_quality_save(4, $input) === 'saved' &&
        $writes === 2 &&
        $post->post_parent === 90 &&
        $post->post_author === 7,
    'Save only this attachment text, preserving ownership and parent',
);
check(
    pagenest_media_quality_save(4, $input) === 'conflict' && $writes === 2,
    'Repeated stale form cannot overwrite saved text',
);
check(
    pagenest_media_quality_references('<img class="wp-image-4">', 4, []),
    'Saved HTML ID reference',
);
check(!pagenest_media_quality_references('<img class="wp-image-40">', 4, []), 'Exact ID boundary');
$blocks = [['blockName' => 'pagenest/gallery', 'attrs' => ['ids' => [2, 4]], 'innerBlocks' => []]];
check(pagenest_media_quality_references('', 4, []), 'Native gallery IDs without rendering');
$blocks[0]['blockName'] = 'unknown/execute';
check(!pagenest_media_quality_references('', 4, []), 'Unknown dynamic attributes are not guessed');
$blocks = [];
$candidates = [
    (object) ['ID' => 88, 'post_content' => '/uploads/image.jpg'],
    (object) ['ID' => 9, 'post_content' => '/uploads/image-150.jpg'],
    (object) ['ID' => 10, 'post_content' => str_repeat('x', 1000001)],
];
$result = pagenest_media_quality_reference_page(4, 2);
check(
    $result['checked'] === 1 &&
        $result['skipped'] === 2 &&
        array_column($result['matches'], 'ID') === [9],
    'References exclude unreadable and oversized content, include resized attachment URL',
);
$full_scope = false;
$candidates = [(object) ['ID' => 9, 'post_author' => 7, 'post_content' => '/uploads/image.jpg']];
$own = pagenest_media_quality_reference_page(4, 2);
$candidates[] = (object) ['ID' => 88, 'post_author' => 8, 'post_content' => '/uploads/image.jpg'];
check(
    $own === pagenest_media_quality_reference_page(4, 2) && $own['scope'] === 'own',
    'Other authors private records cannot affect restricted reference counts or pagination',
);
$full_scope = true;
$post->post_excerpt = '<em>Existing formatted caption</em>';
$input = [
    'alt' => 'only alt',
    'caption' => $post->post_excerpt,
    'expected_alt' => 'new alt',
    'expected_caption' => $post->post_excerpt,
];
check(
    pagenest_media_quality_save(4, $input) === 'saved' &&
        $post->post_excerpt === '<em>Existing formatted caption</em>',
    'Editing alt preserves existing caption HTML exactly',
);
$post->post_excerpt = 'new caption';
$meta[4]['_wp_attachment_image_alt'] = 'new alt';
$input = [
    'alt' => 'batch alt',
    'caption' => 'batch caption',
    'expected_alt' => 'new alt',
    'expected_caption' => 'new caption',
    'nonce' => 'valid',
];
$result = pagenest_media_quality_submit([4 => $input, 5 => $input]);
check(
    $result === [4 => 'saved', 5 => 'forbidden'],
    'Explicit multi-item submit reports each result without applying another attachment data',
);
check(pagenest_media_quality_submit(array_fill(1, 21, $input)) === [], 'Batch limited to 20 items');
$input['nonce'] = 'bad';
check(
    pagenest_media_quality_submit([4 => $input]) === [4 => 'invalid'],
    'Each submitted attachment needs its own valid nonce',
);
echo "Media quality permissions, stale saves, field boundaries and readable bounded references passed\n";
