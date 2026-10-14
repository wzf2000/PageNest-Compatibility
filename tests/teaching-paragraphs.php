<?php
/** Synthetic paragraph identities must ignore generated teaching UI, not hidden author text. */
define('ABSPATH', __DIR__);
$fixture_meta = [];
function add_action(...$args) {}
function add_filter(...$args) {}
function pagenest_companion_config($section, $key)
{
    return $key === 'shortcodes' ? [] : '_fixture_registry';
}
function get_post_meta($id, ...$args)
{
    global $fixture_meta;
    return $fixture_meta[$id] ?? [];
}
function update_post_meta($id, $key, $value)
{
    global $fixture_meta;
    $fixture_meta[$id] = $value;
}
function wp_slash($value)
{
    return $value;
}
function wp_generate_uuid4()
{
    static $sequence = 0;
    return '00000000-0000-4000-8000-' . str_pad((string) ++$sequence, 12, '0', STR_PAD_LEFT);
}
require dirname(__DIR__) . '/modules/paragraph-comments.php';
function teaching_assert($value, $message)
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}
$source =
    '<div hidden><p>Hidden authored paragraph.</p></div>' .
    '<div data-mbb-generated><p>Output 1</p><pre>Trace 1</pre><ul><li>Count 1</li></ul></div>' .
    '<p>Authored <span data-mbb-generated>runtime status</span> explanation.</p>' .
    '<pre>  code\n  preserved</pre>';
pagenest_pc_blocks($source, 10, true);
$before = $fixture_meta[10];
teaching_assert(count($before) === 3, 'Generated teaching UI gained paragraph identities.');
teaching_assert(
    $before[0]['text'] === 'Hidden authored paragraph.',
    'Hidden authored text lost identity.',
);
teaching_assert(
    $before[1]['text'] === 'Authored explanation.',
    'Generated descendant polluted quote.',
);
$changed = str_replace(
    ['Output 1', 'Trace 1', 'Count 1', 'runtime status'],
    ['Output 2', 'Trace 2', 'Count 2', 'stopped'],
    $source,
);
$rendered = pagenest_pc_blocks($changed, 10, true);
teaching_assert(
    $fixture_meta[10] === $before,
    'Reader runtime state changed author paragraph identities.',
);
teaching_assert(
    substr_count($rendered, 'data-pagenest-block=') === 3,
    'Comment anchors missing or duplicated.',
);
teaching_assert(str_contains($rendered, 'Output 2'), 'Generated display content was deleted.');
echo "Teaching paragraph identity contracts passed.\n";
