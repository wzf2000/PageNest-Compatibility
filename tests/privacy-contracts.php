<?php
/** Privacy requests follow the requested email, never the administrator running the job. */
define('ABSPATH', __DIR__);
class WP_Error
{
    public function __construct(...$args) {}
}
$hooks = $notes = $note_data = $like_rows = $bindings = [];
function add_filter($name, $fn)
{
    $GLOBALS['hooks'][$name] = $fn;
}
function __($text, ...$args)
{
    return $text;
}
function esc_html($text)
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function sanitize_email($text)
{
    return filter_var($text, FILTER_VALIDATE_EMAIL) ? $text : '';
}
function get_user_by($field, $value)
{
    return $value === 'owner@example.test' ? (object) ['ID' => 7] : false;
}
function get_current_user_id()
{
    return 999;
}
function pagenest_companion_config($section, $key = null)
{
    $config = [
        'paragraphs' => ['post_type' => 'legacy_private_note', 'data_meta' => '_legacy_data'],
        'likes' => ['record_meta' => '_owned_like_record'],
    ];
    return $key === null ? $config[$section] : $config[$section][$key];
}
function get_post_stati()
{
    return ['private' => 'private', 'publish' => 'publish', 'trash' => 'trash'];
}
function get_post_meta($id, $key, $single)
{
    if ($key !== '_legacy_data') {
        throw new RuntimeException('Non-owned metadata queried');
    }
    return $GLOBALS['note_data'][$id] ?? '';
}
function get_user_meta($id, $key, $single)
{
    if ($id !== 7 || $key !== '_pagenest_social_bindings') {
        throw new RuntimeException('Non-target identity queried');
    }
    return $GLOBALS['bindings'];
}
function maybe_unserialize($text)
{
    return unserialize($text, ['allowed_classes' => false]);
}
class WP_Query
{
    public $posts;
    public function __construct($args)
    {
        if (
            $args['author'] !== 7 ||
            $args['post_type'] !== 'legacy_private_note' ||
            $args['posts_per_page'] !== 21 ||
            !$args['no_found_rows']
        ) {
            throw new RuntimeException('Invalid privacy scope/query bound');
        }
        $this->posts = array_slice($GLOBALS['notes'], $args['offset'], 21);
    }
}
$wpdb = new class {
    public $postmeta = 'postmeta';
    public $last_error = '';
    public function prepare($sql, ...$values)
    {
        if (
            !str_contains($sql, 'ORDER BY meta_id ASC LIMIT 21 OFFSET') ||
            $values[0] !== '_owned_like_record'
        ) {
            throw new RuntimeException('Unbounded/unowned likes query');
        }
        return $values[1];
    }
    public function get_results($offset)
    {
        return array_slice($GLOBALS['like_rows'], $offset, 21);
    }
};
require dirname(__DIR__) . '/modules/privacy.php';
function check($ok, $label)
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}
foreach (range(1, 43) as $id) {
    $notes[] = (object) ['ID' => $id, 'post_author' => 7, 'post_type' => 'legacy_private_note'];
    $note_data[$id] = [
        'text' => '<script>literal record</script>',
        'quote' => 'Owned snapshot',
        'post_id' => 99999,
        'block_id' => 'lost-anchor',
        'unknown_secret' => 'must-not-export',
        'previous_quote' => 'Earlier own quotation',
        'previous_block_id' => 'earlier-anchor',
        'updated_at' => '2026-10-21T01:00:00Z',
    ];
    $like_rows[] = (object) [
        'meta_id' => $id,
        'post_id' => $id,
        'meta_value' => serialize(
            $id === 25
                ? [7 => '2026-10-21T00:00:00Z', 999 => 'other-user-time']
                : [999 => 'other-user-time'],
        ),
    ];
}
$ids = [];
foreach ([1, 2, 3] as $page) {
    $result = pagenest_privacy_export_notes('owner@example.test', $page);
    $ids = array_merge($ids, array_column($result['data'], 'item_id'));
    check($result['done'] === ($page === 3), 'Stable owned notes pagination');
    $json = json_encode($result);
    check(
        !str_contains($json, 'must-not-export') &&
            !str_contains($json, '<script>') &&
            str_contains($json, 'author_only'),
        'Fixed note whitelist, safe literal text and old private default',
    );
}
check(
    str_contains(json_encode($result), 'Earlier own quotation') &&
        str_contains(json_encode($result), 'earlier-anchor'),
    'Own historical quotation and anchor remain in the whitelist',
);
check(count(array_unique($ids)) === 43, 'Lost target and legacy private records remain exportable');
$result = pagenest_privacy_export_likes('owner@example.test', 1);
check(
    $result['data'] === [] && !$result['done'],
    'Empty matching like page still advances through bounded metadata candidates',
);
$result = pagenest_privacy_export_likes('owner@example.test', 2);
check(
    count($result['data']) === 1 && !str_contains(json_encode($result), 'other-user-time'),
    'Only target user like timestamp is exported, never other users',
);
check(
    count($result['data'][0]['data']) === 2,
    'Likes expose only target post ID and timestamp, including private/lost targets',
);
foreach (range(1, 23) as $id) {
    $bindings['binding-' . sprintf('%02d', $id)] = [
        'provider' => 'github',
        'subject' => 'subject-' . $id,
        'app' => 'app-secret',
        'token' => 'access-token',
    ];
}
$result = pagenest_privacy_export_social('owner@example.test', 1);
check(count($result['data']) === 20 && !$result['done'], 'Social bindings bounded pagination');
check(
    !str_contains(json_encode($result), 'app-secret') &&
        !str_contains(json_encode($result), 'access-token') &&
        !str_contains(json_encode($result), 'subject-'),
    'Only provider and subject hash, no application or credentials',
);
check(
    pagenest_privacy_export_social('owner@example.test', 2)['done'],
    'Final social page terminates',
);
foreach (['notes', 'likes', 'social'] as $kind) {
    check(
        call_user_func('pagenest_privacy_export_' . $kind, '', 1) === [
            'data' => [],
            'done' => true,
        ],
        'Unknown email ends empty',
    );
}
check(
    count($hooks['wp_privacy_personal_data_exporters']([])) === 3 &&
        !isset($hooks['wp_privacy_personal_data_erasers']),
    'Export registration independent of features, without erasers',
);
$raw = '<script>literal</script> &lt; & \ path%20name';
$datum = pagenest_privacy_item('notes', 1, ['Raw' => $raw])['data'][0]['value'];
check(
    html_entity_decode(stripslashes(stripslashes($datum)), ENT_QUOTES | ENT_HTML5, 'UTF-8') ===
        $raw,
    'Core HTML-safe export value decodes exactly once to the original literal text',
);
$GLOBALS['pagenest_companion_profile_invalid'] = true;
check(
    pagenest_privacy_export_notes('owner@example.test') instanceof WP_Error &&
        pagenest_privacy_export_likes('owner@example.test') instanceof WP_Error,
    'Invalid profile fails explicitly instead of silently exporting default mapping',
);
echo "Privacy target identity, pagination, legacy ownership and whitelist contracts passed\n";
