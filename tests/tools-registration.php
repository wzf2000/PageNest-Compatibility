<?php
/** Provider validation is atomic; one invalid pack cannot disable unrelated entries. */
define('ABSPATH', __DIR__);
function wp_upload_dir()
{
    return ['basedir' => __DIR__ . '/uploads'];
}
function get_permalink($id)
{
    return 'https://example.invalid/' . $id;
}
function wp_parse_url($url)
{
    return parse_url($url);
}
require dirname(__DIR__) . '/modules/tools/providers.php';
require dirname(__DIR__) . '/modules/tools/registry.php';
require __DIR__ . '/fixtures/tools/provider.php';
use PageNest\Tools\Providers;
use PageNest\Tools\Registry;
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
$root = __DIR__ . '/fixtures/tools';
CompanionToolFixture\Implementations::register('basic', $root, 'https://example.invalid/basic');
check(
    'duplicate provider rejected',
    rejects(
        fn() => CompanionToolFixture\Implementations::register(
            'basic',
            $root,
            'https://example.invalid/basic',
        ),
    ),
);
$provider = [
    'root' => $root . '/assets',
    'url' => 'https://example.invalid/assets',
    'manifest' => ['tools.css' => 'tools.css'],
    'handlers' => [
        'text-count' => ['render' => static fn() => '', 'validate' => static function () {}],
    ],
];
check(
    'duplicate handler rejected',
    rejects(fn() => Providers::register('duplicate-handler', $provider)),
);
$provider['handlers'] = [
    'good' => ['render' => static fn() => '', 'validate' => static function () {}],
];
$bad = $provider;
$bad['manifest'] = ['tools.css' => '../config.php'];
check('manifest traversal rejected', rejects(fn() => Providers::register('traversal', $bad)));
$bad = $provider;
$bad['root'] = 'php://memory';
check('stream roots rejected', rejects(fn() => Providers::register('stream', $bad)));
$bad = $provider;
$bad['handlers']['good']['render'] = 'undefined_callable';
check('invalid callbacks rejected', rejects(fn() => Providers::register('bad-callback', $bad)));
Providers::register('valid-after-errors', $provider);
check('atomic registration retained', Providers::has('good'));
CompanionToolFixture\Implementations::register('games', $root, 'https://example.invalid/games');
CompanionToolFixture\Implementations::register('private', $root, 'https://example.invalid/private');
$data = [
    'games' => ['runs_table' => ['invalid' => 'type']],
    'services' => [
        'enabled' => false,
        'drafts_table' => 'drafts',
        'jobs_table' => 'jobs',
        'lock' => 'private',
        'max_items' => 3,
        'max_bytes' => 4096,
        'job_timeout' => 300,
    ],
];
Providers::validateProfile($data);
check(
    'game invalidity isolated',
    !Providers::has('game') &&
        !Providers::has('ranking') &&
        Providers::has('text-count') &&
        Providers::has('personal-drafts'),
);
$data['services']['drafts_table'] = 'users';
Providers::validateProfile($data);
check(
    'private invalidity isolated',
    !Providers::has('personal-drafts') &&
        !Providers::has('local-jobs') &&
        Providers::has('text-count'),
);
echo $GLOBALS['checks'] . " tool registration contracts passed\n";
