<?php
/** Synthetic-only content/config/access tests: no database, HTTP or production source. */
define('ABSPATH', __DIR__);
$hooks = $posts = $meta = $permissions = [];
$current = 10;
$locked = false;
$checks = 0;
function check($condition, $message)
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function add_filter($name, $callback, $priority = 10, $args = 1)
{
    global $hooks;
    $hooks[$name][$priority][] = $callback;
}
function add_action(...$args)
{
    add_filter(...$args);
}
function apply_filters($name, $value, ...$args)
{
    global $hooks;
    $priorities = $hooks[$name] ?? [];
    ksort($priorities);
    foreach ($priorities as $callbacks) {
        foreach ($callbacks as $callback) {
            $value = $callback($value, ...$args);
        }
    }
    return $value;
}
function get_post_meta($id, $key, $single = true)
{
    return $GLOBALS['meta'][$id][$key] ?? '';
}
function get_post($id)
{
    return $GLOBALS['posts'][$id] ?? null;
}
function get_the_ID()
{
    return $GLOBALS['current'];
}
function get_queried_object_id()
{
    return $GLOBALS['current'];
}
function post_password_required($post)
{
    return $post && $post->post_password !== '' && $GLOBALS['locked'];
}
function current_user_can($permission, $id = 0)
{
    return !empty($GLOBALS['permissions'][$permission][$id]);
}
function esc_attr($value)
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function esc_html($value)
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function esc_url($value)
{
    return esc_attr($value);
}
function wp_json_encode($value)
{
    return json_encode($value);
}
function wp_strip_all_tags($html)
{
    return strip_tags($html);
}
function get_permalink($post)
{
    return 'https://fixture.example/' . (is_object($post) ? $post->ID : $post);
}
function pagenest_companion_feature($feature)
{
    return true;
}
function pagenest_companion_config($section, $key = null)
{
    return [];
}
function pagenest_companion_post_setting($id, $key)
{
    return '';
}
function get_posts($query)
{
    return $GLOBALS['libraries'] ?? [];
}
function is_admin()
{
    return $GLOBALS['admin'] ?? false;
}
function is_feed()
{
    return $GLOBALS['feed'] ?? false;
}
function is_paged()
{
    return $GLOBALS['paged'] ?? false;
}
function is_category()
{
    return true;
}
function is_tag()
{
    return false;
}
function get_queried_object()
{
    return (object) ['taxonomy' => 'category', 'term_id' => 71];
}
function get_term($id, $taxonomy)
{
    return (object) ['taxonomy' => $taxonomy, 'term_id' => $id, 'name' => 'Synthetic series'];
}
function is_wp_error($value)
{
    return false;
}
function get_term_link($term)
{
    return 'https://fixture.example/term/' . $term->term_id;
}
class WP_Query
{
    public $posts = [101];
    public function __construct($args)
    {
        $GLOBALS['archive_queries'][] = $args;
    }
    public function is_main_query()
    {
        return true;
    }
}
// Exercise WordPress's shared handle registry: theme assets must survive content enqueue.
$assetQueue = [
    'style' => ['pagenest-content' => ['theme.css']],
    'script' => ['pagenest-content' => ['theme.js']],
];
function wp_enqueue_style($handle, ...$args)
{
    $GLOBALS['assetQueue']['style'][$handle] ??= $args;
}
function wp_enqueue_script($handle, ...$args)
{
    $GLOBALS['assetQueue']['script'][$handle] ??= $args;
}
function plugins_url($path, $file)
{
    return 'https://fixture.example/plugins/' . $path;
}
require dirname(__DIR__) . '/content-config.php';
require dirname(__DIR__) . '/modules/chapters.php';
require dirname(__DIR__) . '/modules/content/pages.php';
require dirname(__DIR__) . '/modules/content/archives.php';
require dirname(__DIR__) . '/modules/content/chapters.php';
$input = json_decode(
    file_get_contents(__DIR__ . '/content-example.json'),
    true,
    64,
    JSON_THROW_ON_ERROR,
);
$profile = pagenest_companion_content_validate($input);
$GLOBALS['pagenest_companion_content_profile'] = $profile;
check($profile['invalid_modules'] === [], 'Synthetic profile valid');
$iconProfile = $input;
$iconNames = [
    'dice',
    'text',
    'dinosaur',
    'tiles',
    'fibonacci',
    'graduate',
    'medal',
    'dinosaur-medal',
    'tiles-calendar',
    'tiles-trophy',
    'fibonacci-calendar',
    'fibonacci-trophy',
    'graduate-trophy',
    'image',
    'cloud',
    'braces',
    'clock',
    'mine',
    'puzzle',
    'encoding',
    'qr',
    'sudoku',
    'snake',
    'tiers',
    'heart',
    'shield',
    'evidence',
    'cards',
    'flasks',
    'bug',
    'route',
    'book',
    'sliders',
    'crop',
    'diff',
    'nonogram',
    'bulb',
    'markdown',
    'table',
    'mask',
    'moon',
    'wolf',
    'broom',
    'compare',
    'merge',
    'pivot',
    'database',
    'sprout',
    'constellation',
    'sand',
];
$iconProfile['modules']['resources']['pages'][0]['icons'] = array_combine($iconNames, $iconNames);
check(
    pagenest_companion_content_validate($iconProfile)['invalid_modules'] === [],
    'All built-in resource icons remain selectable',
);

$conflicting = $input;
$conflicting['modules']['guide']['pages'][0]['selector'] =
    $input['modules']['resources']['pages'][0]['selector'];
$conflictProfile = pagenest_companion_content_validate($conflicting);
check(
    !$conflictProfile['modules']['guide']['enabled'] &&
        !$conflictProfile['modules']['resources']['enabled'],
    'Competing page owners both disabled',
);
check(
    $conflictProfile['modules']['chapter_display']['enabled'],
    'Owner conflict does not disable other module',
);

check(
    pagenest_companion_content_config('modules.archives.items') ===
        $input['modules']['archives']['items'],
    'Read-only API keeps archive shape',
);
check(
    pagenest_companion_content_config('modules.missing', 'fallback') === 'fallback',
    'Missing config fallback',
);
foreach (
    [
        ['sections', [['heading' => ['regex' => '.*', 'level' => 2]]]],
        ['provider', 'arbitrary'],
        ['icons', ['hall' => '<svg>']],
        ['icons', ['hall' => 'unknown']],
        ['icons', ['hall' => ['svg' => '<svg onload=alert(1)>']]],
        ['external_links', ['https://user:secret@external.example/']],
        ['groups', ['a' => [['slug' => 'a', 'label' => 'Self']]]],
        ['selector', ['meta' => '_fixture', 'value' => '<script>']],
        ['release_meta', 'unsafe key'],
    ]
    as [$key, $bad]
) {
    $test = $input;
    $test['modules']['resources']['pages'][0][$key] = $bad;
    $invalid = pagenest_companion_content_validate($test);
    check(
        $invalid['invalid_modules'] === ['resources'],
        'Only invalid resources disabled: ' . $key,
    );
    check(
        $invalid['modules']['guide']['enabled'] &&
            $invalid['modules']['chapter_display']['enabled'],
        'Healthy module survives',
    );
}
foreach (
    [['enabled', 'true'], ['template', 'javascript'], ['labels', ['unknown' => 'Text']]]
    as [$key, $bad]
) {
    $test = $input;
    $test['modules']['guide'][$key] = $bad;
    check(
        pagenest_companion_content_validate($test)['invalid_modules'] === ['guide'],
        'Invalid guide field rejected',
    );
}
foreach (
    ['https://external.example/facility', 'https://external.example/facility?mode=one']
    as $url
) {
    check(pagenest_companion_content_url($url), 'Safe HTTPS URL');
}
foreach (
    [
        'javascript:alert(1)',
        '//external.example/',
        'https://external.example/a b',
        'https://external.example/\\a',
        'http://external.example/',
    ]
    as $url
) {
    check(!pagenest_companion_content_url($url), 'Unsafe external URL rejected');
}
$temp = tempnam(sys_get_temp_dir(), 'pagenest-content-');
file_put_contents($temp, json_encode($input));
check(
    pagenest_companion_load_content($temp, __DIR__)['invalid_modules'] === [],
    'Outside-web profile loads',
);
try {
    pagenest_companion_load_content(__DIR__ . '/content-example.json', __DIR__);
    check(false, 'Web-root profile rejected');
} catch (InvalidArgumentException $error) {
    check(true, 'Web-root profile rejected');
}
unlink($temp);
foreach ([10, 11, 12, 20, 101, 102, 103, 104] as $id) {
    $posts[$id] = (object) [
        'ID' => $id,
        'post_type' => $id >= 100 ? 'post' : 'page',
        'post_status' => 'publish',
        'post_password' => '',
        'post_title' => 'Synthetic ' . $id,
    ];
}
$posts[102]->post_status = 'private';
$posts[103]->post_password = 'locked';
$posts[104]->post_status = 'draft';
$meta[10] = [
    '_fixture_resource' => 'community-directory',
    '_fixture_release' => '1',
    '_fixture_targets' => [
        'https://fixture.example/hall' => 101,
        'https://fixture.example/event' => 101,
        'https://fixture.example/result' => 101,
        'https://fixture.example/private' => 102,
        'https://fixture.example/password' => 103,
        'https://fixture.example/draft' => 104,
        'https://external.example/facility' => 0,
        'https://external.example/facility/' => 0,
    ],
];
$source =
    '<h2>Facilities</h2><p id="facility-introduction">Public facility services.</p><p id="hidden-introduction" hidden>Hidden facility detail.</p><ul><li><a href="https://fixture.example/hall">Hall</a> — Public facility</li><li><a href="https://external.example/facility">External</a></li><li><a href="https://external.example/facility/">Wrong slash</a></li><li><a href="https://fixture.example/private">Private</a></li><li><a href="https://fixture.example/password">Password</a></li><li><a href="https://fixture.example/draft">Draft</a></li><li><a href="https://invented.example/">Unmapped</a></li></ul><h2>Events</h2><ul><li><a href="https://fixture.example/event">Event</a> — Festival</li></ul><h2>Results</h2><ul><li><a href="https://fixture.example/result">Result</a></li></ul>';
add_filter(
    'pagenest_companion_resource_entries',
    static fn($entries) => [
        ['slug' => 'hall', 'canonical_url' => 'https://fixture.example/hall'],
        ['slug' => 'event', 'canonical_url' => 'https://fixture.example/event'],
        ['slug' => 'result', 'canonical_url' => 'https://fixture.example/result'],
        ['slug' => 'invented', 'canonical_url' => 'https://invented.example/'],
    ],
);
$rendered = pagenest_companion_content_render($source, 10);
check(
    str_contains($rendered, 'data-pagenest-list="0"') &&
        str_contains($rendered, 'id="facility-introduction"') &&
        substr_count($rendered, 'data-pagenest-list=') === 3,
    'Resource list pairs across its introduction paragraphs',
);
[$sectionDom, $sectionXp, $sectionRoot] = pagenest_companion_content_dom($source);
pagenest_companion_content_sections_apply(
    $sectionDom,
    $sectionXp,
    $sectionRoot,
    $input['modules']['resources']['pages'][0]['sections'],
);
check(
    $sectionXp->query('.//*[@data-pagenest-list="0"]', $sectionRoot)->length === 0,
    'Guide/default section matching keeps its native introduction boundary',
);

check(
    str_contains($rendered, 'data-resource-icon="image"'),
    'Trusted resource icon decorates approved link',
);
check(str_contains($rendered, 'data-resource-parent="event"'), 'Approved grouped link');
check(
    str_contains($rendered, 'data-pagenest-related="1" hidden'),
    'Related decoration starts hidden without JS',
);
foreach (['Wrong slash', '>Private<', '>Password<', '>Draft<', 'Unmapped'] as $label) {
    check(!str_contains($rendered, $label), 'Unapproved link removed: ' . $label);
}
check(
    str_contains($rendered, '>External<') && str_contains($rendered, '>Hall<'),
    'Native public links preserved',
);
check(
    pagenest_companion_content_render($rendered, 10) === $rendered,
    'Resource projection idempotent',
);
$outer = '<div class="pagenest-comments-body">' . $rendered . '</div>';
$outerRendered = pagenest_companion_content_render($outer, 10);
check(
    substr_count($outerRendered, 'data-pagenest-content="resources"') === 1,
    'REST preserves a single resource wrapper under paragraph wrapper',
);
check(
    substr_count($outerRendered, 'class="pagenest-comments-body"') === 1,
    'Outer paragraph identity preserved',
);
check(
    pagenest_companion_content_render($outerRendered, 10) === $outerRendered,
    'Nested projection idempotent',
);
$providerHooks = $hooks['pagenest_companion_resource_entries'];
$hooks['pagenest_companion_resource_entries'] = [];
$generic = pagenest_companion_content_render($source, 10);
check(
    str_contains($generic, '>Hall<') && !str_contains($generic, 'data-resource-slug'),
    'Missing provider keeps approved native links and generic decoration',
);
add_filter('pagenest_companion_resource_entries', static function () {
    throw new RuntimeException('Synthetic provider failure');
});
check(
    pagenest_companion_content_render($source, 10) === $generic,
    'Provider failure degrades safely',
);
$hooks['pagenest_companion_resource_entries'] = $providerHooks;
$permissions['edit_post'][10] = true;
check(
    !str_contains(pagenest_companion_content_render($source, 10), '>Private<'),
    'Editor cannot decorate nonpublic target',
);
$permissions = [];
$meta[10]['_fixture_release'] = '0';
check(pagenest_companion_content_render($source, 10) === '', 'Unapproved resource hidden');
check(
    apply_filters('get_the_excerpt', 'unsafe', $posts[10]) === '',
    'Unreadable resource excerpt hidden',
);
$data = apply_filters('wp_insert_post_data', ['post_status' => 'publish'], ['ID' => 10]);
check($data['post_status'] === 'draft', 'Resource publication guard');
check(
    apply_filters('wp_insert_post_data', ['post_status' => 'trash'], ['ID' => 10])[
        'post_status'
    ] === 'trash',
    'Trash allowed',
);
$meta[10]['_fixture_release'] = '1';
check(
    apply_filters('get_the_excerpt', 'unsafe', $posts[10]) ===
        $input['modules']['resources']['pages'][0]['excerpt'],
    'Configured summary',
);
$targets = $meta[10]['_fixture_targets'];
$meta[10]['_fixture_targets'] = '';
check(pagenest_companion_content_render($source, 10) === '', 'Missing target map fails closed');
$meta[10]['_fixture_targets'] = $targets;
$meta[11] = [
    '_fixture_guide' => 'festival-visit',
    '_fixture_guide_targets' => [
        'https://fixture.example/hall' => 101,
        'https://fixture.example/private' => 102,
    ],
];
$guide =
    '<span id="arrival"></span><h2>Arrival</h2><p>Native introduction.</p><h3>Visit stops</h3><ol><li><a href="https://fixture.example/hall">Hall stop</a></li><li><a href="https://fixture.example/private">Secret stop</a></li></ol><h2 id="venue-existing">Venue</h2><h3>Suggested stops</h3><ul><li>Venue detail</li></ul><h2>Departure</h2><h3>Final stops</h3><ol><li>Exit detail</li></ol>';
$guideRendered = pagenest_companion_content_render($guide, 11);
check(
    substr_count($guideRendered, 'id="arrival"') === 1,
    'Historical empty anchor preserved without duplicate',
);
check(
    str_contains($guideRendered, 'id="venue-existing"') &&
        !str_contains($guideRendered, 'id="venue"'),
    'Existing heading ID wins',
);
check(!str_contains($guideRendered, 'Secret stop'), 'Guide target permission enforced');
check(
    str_contains($guideRendered, 'Native introduction.') && str_contains($guideRendered, '<ol'),
    'Full native guide preserved',
);
check(pagenest_companion_content_render($guideRendered, 11) === $guideRendered, 'Guide idempotent');
$posts[11]->post_status = 'draft';
check(pagenest_companion_content_render($guide, 11) === '', 'Anonymous draft guide hidden');
$permissions['edit_post'][11] = true;
check(pagenest_companion_content_render($guide, 11) !== '', 'Owner can preview draft guide');
$posts[11]->post_password = 'secret';
$locked = true;
check(
    pagenest_companion_content_render($guide, 11) === '',
    'Locked password guide hidden even for editor',
);
$locked = false;
$posts[11]->post_password = '';
$posts[11]->post_status = 'publish';
$permissions = [];
$meta[12] = ['_fixture_report' => 'venue-report'];
$report =
    '<h2>Observations</h2><p>Native report.</p><h2 id="existing-findings">Findings</h2><table><tr><td>42</td></tr></table>';
$reportRendered = pagenest_companion_content_render($report, 12);
check(
    str_contains($reportRendered, 'href="#observations"') &&
        str_contains($reportRendered, 'href="#existing-findings"'),
    'Server-side reading navigation works without JS',
);
check(
    pagenest_companion_content_render($reportRendered, 12) === $reportRendered,
    'Reading navigation idempotent',
);
$posts[20]->post_type = 'post';
$meta[20] = [
    '_fixture_document' => 'chapter-a',
    '_fixture_aliases' => ['repeat' => ['old-repeat'], 'repeat-1' => ['old-second', 'existing-id']],
];
$chapter =
    '<h1>Collection page</h1><h2>Repeat</h2><p data-pagenest-block="b-stable" id="b-stable">中文API text $x_1$.</p><h2 id="existing-id">Repeat</h2><h2>Repeat</h2><span id="old-empty"></span><pre><code>中文API &lt;h2&gt;not a heading&lt;/h2&gt;</code></pre>';
$libraries = [$posts[10]];
$chapterRendered = pagenest_companion_content_chapter_render($chapter, 20);
check(str_contains($chapterRendered, 'Return to collection'), 'Readable return navigation');
foreach (
    [
        'id="repeat"',
        'id="old-repeat"',
        'id="old-second"',
        'id="existing-id"',
        'id="repeat-2"',
        'id="old-empty"',
        'data-pagenest-block="b-stable"',
        '$x_1$',
    ]
    as $part
) {
    check(str_contains($chapterRendered, $part), 'Chapter identity preserved: ' . $part);
}
check(substr_count($chapterRendered, 'id="existing-id"') === 1, 'Existing IDs never duplicated');
check(
    pagenest_companion_content_chapter_render($chapterRendered, 20) === $chapterRendered,
    'Chapter display idempotent',
);
$posts[10]->post_password = 'secret';
$locked = true;
check(
    !str_contains(pagenest_companion_content_chapter_render($chapter, 20), 'Return to collection'),
    'Password-protected library never linked',
);
$posts[10]->post_password = '';
$posts[10]->post_status = 'private';
$locked = false;
check(
    !str_contains(pagenest_companion_content_chapter_render($chapter, 20), 'Return to collection'),
    'Unauthorized private library never linked',
);
$permissions['read_post'][10] = true;
check(
    str_contains(pagenest_companion_content_chapter_render($chapter, 20), 'Return to collection'),
    'Authorized private library linked',
);
$posts[20]->post_status = 'private';
$permissions = [];
check(
    pagenest_companion_content_chapter_render($chapter, 20) === $chapter,
    'Unauthorized private chapter is not enhanced',
);
$posts[20]->post_status = 'publish';
$archive = pagenest_companion_content_archive_guide($input['modules']['archives']['items'][0]);
check(
    str_contains($archive, 'Synthetic 101') &&
        !str_contains($archive, 'Synthetic 102') &&
        !str_contains($archive, 'Synthetic 103'),
    'Archive starts exclude private and password targets',
);
check(
    ($GLOBALS['archive_queries'][0]['has_password'] ?? null) === false &&
        $GLOBALS['archive_queries'][0]['post_status'] === 'publish',
    'Archive group requires actual public posts',
);
check(
    pagenest_companion_content_archive_current() !== null,
    'Matching first-page archive selected',
);
foreach (['admin', 'feed', 'paged'] as $context) {
    $GLOBALS[$context] = true;
    check(
        pagenest_companion_content_archive_current() === null,
        'Archive suppressed for ' . $context,
    );
    $GLOBALS[$context] = false;
}
$secondary = new class {
    public function is_main_query()
    {
        return false;
    }
};
ob_start();
foreach ($hooks['loop_start'][10] as $callback) {
    $callback($secondary);
}
$secondaryHtml = ob_get_clean();
check($secondaryHtml === '', 'Secondary loop has no guide');
ob_start();
foreach ($hooks['loop_start'][10] as $callback) {
    $callback(new WP_Query([]));
    $callback(new WP_Query([]));
}
$mainHtml = ob_get_clean();
check(substr_count($mainHtml, '<nav') === 1, 'First main loop renders only once');
$response = new class {
    public $data;
    public $headers = [];
    public function header($key, $value)
    {
        $this->headers[$key] = $value;
    }
};
$posts[10]->post_status = 'publish';
$response->data = ['content' => ['rendered' => $rendered]];
$response = apply_filters('rest_prepare_page', $response, $posts[10]);
check(
    $response->data['content']['rendered'] === $rendered,
    'REST shares idempotent resource projection',
);
check(
    $response->headers['Cache-Control'] === 'private, no-store, max-age=0',
    'REST private/no-store',
);
$meta[10]['_fixture_release'] = '0';
$response = apply_filters('rest_prepare_page', $response, $posts[10]);
check($response->data['content']['rendered'] === '', 'REST cannot expose unreleased resource');
check(
    isset($hooks['the_content'][20]) &&
        isset($hooks['the_content'][30]) &&
        isset($hooks['the_content'][32]),
    'Original filter priorities kept',
);
pagenest_companion_content_assets();
foreach (['style', 'script'] as $type) {
    check(
        isset($assetQueue[$type]['pagenest-companion-content']),
        "Companion has its own $type handle",
    );
    check(
        $assetQueue[$type]['pagenest-content'][0] ===
            ($type === 'style' ? 'theme.css' : 'theme.js'),
        "Theme $type registration survives",
    );
    check(
        str_contains($assetQueue[$type]['pagenest-companion-content'][0], '/plugins/assets/'),
        "Companion $type resolves to its own asset",
    );
}
echo "Content contracts: $checks checks passed\n";
if (in_array('--fixture', $argv, true)) {
    $meta[10]['_fixture_release'] = '1';
    $GLOBALS['fixture_content'] = [
        'resources' => $rendered,
        'guide' => $guideRendered,
        'reading' => $reportRendered,
        'chapter' => $chapterRendered,
    ];
}
