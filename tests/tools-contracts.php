<?php
/** Generic host boundaries and independent provider composition, without a database. */
$fixture = sys_get_temp_dir() . '/pagenest-tools-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
mkdir($fixture . '/web', 0700);
define('ABSPATH', $fixture . '/web/');
define('PageNest\\Tools\\VERSION', '1.2.0-rc.1');
$GLOBALS['logged'] = false;
$GLOBALS['capable'] = false;
$GLOBALS['page_status'] = 'publish';
$GLOBALS['password'] = false;
function add_action(...$args)
{
    $GLOBALS['hooks'][] = $args;
}
function get_option($key, $fallback)
{
    return $GLOBALS['active_plugins'] ?? $fallback;
}
function get_site_option($key, $fallback)
{
    return $GLOBALS['network_plugins'] ?? $fallback;
}
function get_post($id)
{
    return (object) [
        'ID' => $id,
        'post_name' => 'page-' . $id,
        'post_type' => 'page',
        'post_status' => $GLOBALS['page_status'],
        'post_password' => $GLOBALS['password'] ? 'secret' : '',
    ];
}
function get_permalink($id)
{
    return 'https://example.invalid/page-' . $id;
}
function get_the_ID()
{
    return 1;
}
function is_user_logged_in()
{
    return $GLOBALS['logged'];
}
function current_user_can(...$args)
{
    return $GLOBALS['capable'];
}
function post_password_required($page)
{
    return $GLOBALS['password'];
}
function wp_parse_url($url)
{
    return parse_url($url);
}
function sanitize_key($value)
{
    return preg_replace('/[^a-z0-9_-]/', '', $value);
}
function wp_enqueue_style(...$args)
{
    $GLOBALS['styles'][] = $args;
}
function wp_enqueue_script(...$args)
{
    $GLOBALS['scripts'][] = $args;
}
function wp_print_styles($handles) {}
function wp_unique_id($prefix)
{
    return $prefix . 'test';
}
function esc_attr($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}
function esc_html($value)
{
    return esc_attr($value);
}
function wp_upload_dir()
{
    return ['basedir' => ABSPATH . 'uploads'];
}
function check($label, $condition)
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
}
function rejects($fn)
{
    try {
        $fn();
    } catch (Throwable $error) {
        return true;
    }
    return false;
}
$core = dirname(__DIR__) . '/modules/tools';
foreach (['providers', 'profile', 'registry', 'asset-loader', 'host'] as $file) {
    require $core . '/' . $file . '.php';
}
use PageNest\Tools\Providers;
use PageNest\Tools\Registry;
use PageNest\Tools\Host;
$tool = static function ($slug, $render, $id = 1) {
    return [
        'schema_version' => 1,
        'slug' => $slug,
        'kind' => 'internal',
        'title' => 'Tool',
        'description' => 'Local browser',
        'category' => 'tools',
        'version' => '1.0.0',
        'status' => 'active',
        'binding' => ['post_id' => $id, 'post_name' => 'page-' . $id],
        'listed' => true,
        'access' => ['mode' => 'public'],
        'assets' => [],
        'input_schema' => [],
        'render' => $render,
        'service' => null,
        'data' => [],
        'limits' => [],
        'owner' => 'test',
        'maintenance' => 'test',
    ];
};
$catalog = static fn($tools) => [
    'schema_version' => 1,
    'categories' => ['tools'],
    'tools' => $tools,
];
$external = $tool('link', 'unused');
$external = array_replace($external, [
    'kind' => 'external',
    'binding' => null,
    'render' => null,
    'canonical_url' => 'https://example.invalid/tool',
]);
try {
    Registry::load($catalog([]));
    check('empty catalog healthy', Registry::healthy() && Registry::all() === []);
    Registry::load($catalog([$external]));
    check('external without provider', Registry::indexEntries() === [$external]);
    $missing = $tool('missing', 'uninstalled', 2);
    Registry::load($catalog([$external, $missing]));
    check(
        'missing provider keeps catalog',
        Registry::healthy() &&
            Registry::get('missing') ===
                array_replace($missing, ['canonical_url' => get_permalink(2)]),
    );
    check(
        'missing provider fails closed',
        !Registry::allowed(Registry::get('missing')) && Registry::indexEntries() === [$external],
    );
    check(
        'missing handler render safe',
        str_contains(Host::render('missing', true), 'site-tools-unavailable'),
    );
    $packRoot = __DIR__ . '/fixtures/tools';
    require $packRoot . '/provider.php';
    CompanionToolFixture\Implementations::register(
        'basic',
        $packRoot,
        'https://example.invalid/basic',
    );
    Providers::validateProfile(['catalog' => $catalog([])]);
    Providers::start();
    check(
        'basic starts without games or private runtime',
        ($GLOBALS['fixture_started'] ?? []) === ['basic'] &&
            !class_exists(SiteTools\Games::class, false) &&
            !class_exists(SiteTools\PrivateServices::class, false),
    );
    $text = $tool('count', 'text-count');
    $text['assets'] = ['tools.css', 'text-count.js'];
    $random = $tool('random', 'random-number', 3);
    $random['assets'] = ['tools.css', 'random-number.js'];
    Registry::load($catalog([$external, $text, $random, $missing]));
    check('basic and external healthy', count(Registry::indexEntries()) === 3);
    check(
        'anonymous public rendering',
        str_contains(Host::render('count'), 'data-tool="text-count"'),
    );
    check(
        'random renderer',
        str_contains(Host::render('random', true), 'data-tool="random-number"'),
    );
    check('provider resource URLs', str_contains($GLOBALS['styles'][0][1], '/basic/assets/'));
    check('provider resource handles', str_contains($GLOBALS['styles'][0][0], 'pagenest-basic'));
    $GLOBALS['password'] = true;
    check('password public tool hidden', !str_contains(Host::render('count'), 'textarea'));
    $GLOBALS['password'] = false;
    $GLOBALS['page_status'] = 'private';
    check(
        'private public tool hidden anonymously',
        !str_contains(Host::render('count'), 'textarea'),
    );
    $GLOBALS['logged'] = true;
    $GLOBALS['capable'] = true;
    check('private page read permission', str_contains(Host::render('count'), 'textarea'));
    $GLOBALS['page_status'] = 'publish';
    $bad = $text;
    $bad['assets'][] = 'missing-fixture.js';
    $invalidResource = false;
    try {
        Registry::load($catalog([$bad]));
    } catch (InvalidArgumentException $error) {
        $invalidResource = true;
    }
    check('missing declared resource preserves catalog exception contract', $invalidResource);
    $bad = $text;
    $bad['assets'] = ['../evil.js'];
    check('path traversal rejected', rejects(fn() => Registry::load($catalog([$bad]))));
    $bad = $text;
    $bad['template'] = 'arbitrary.php';
    check('data cannot select PHP files', rejects(fn() => Registry::load($catalog([$bad]))));
    $bad = $text;
    $bad['service'] = 'own-data';
    check('browser service mismatch rejected', rejects(fn() => Registry::load($catalog([$bad]))));
    $bad = $random;
    $bad['binding'] = $text['binding'];
    check('duplicate binding rejected', rejects(fn() => Registry::load($catalog([$text, $bad]))));
    // Registration is immutable once implementations have started.
    check(
        'late provider registration rejected',
        rejects(
            fn() => CompanionToolFixture\Implementations::register(
                'basic',
                $packRoot,
                'https://example.invalid/basic',
            ),
        ),
    );
    echo $GLOBALS['checks'] . " generic tool contracts passed\n";
} finally {
    rmdir($fixture . '/web');
    rmdir($fixture);
}
