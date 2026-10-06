<?php
define('ABSPATH', '/test/');
$inline = [];
$override = null;
function wp_add_inline_script($handle, $code, $position)
{
    global $inline;
    $inline[$handle][] = [$code, $position];
}
function wp_json_encode($value)
{
    return json_encode($value);
}
function apply_filters($tag, $value, $handle, $scripts)
{
    global $override;
    if ($tag !== 'pagenest_prism_languages_path') {
        throw new RuntimeException('Unexpected filter');
    }
    return $override === null ? $value : $override;
}
require dirname(__DIR__) . '/prism-compatibility.php';
function registry($resources)
{
    $registered = [];
    foreach ($resources as $handle => [$src, $deps]) {
        $registered[$handle] = (object) ['src' => $src, 'deps' => $deps];
    }
    return (object) [
        'registered' => $registered,
        'base_url' => 'https://example.invalid/wordpress',
        'queue' => array_keys($resources),
    ];
}
$results = [];
function check($name, $ok)
{
    global $results;
    if (!$ok) {
        throw new RuntimeException($name);
    }
    $results[] = ['name' => $name, 'passed' => true];
}
foreach (
    ['wp-editormd/assets/Prism.js', 'markbridge/vendor/prism', 'renamed-provider/lib/prism']
    as $root
) {
    $base = 'https://example.invalid/wp-content/plugins/' . $root . '/';
    $scripts = registry([
        'prism-core-js' => [$base . 'components/prism-core.min.js', []],
        'prism-plugin-autoloader' => [
            $base . 'plugins/autoloader/prism-autoloader.min.js?v=1#x',
            [],
        ],
    ]);
    $inline = [];
    pagenest_configure_prism($scripts);
    check(
        $root . '_directory',
        str_contains($inline['prism-plugin-autoloader'][0][0], json_encode($base . 'components/')),
    );
    check(
        $root . '_core_dependency',
        $scripts->registered['prism-plugin-autoloader']->deps === ['prism-core-js'],
    );
    pagenest_configure_prism($scripts);
    check(
        $root . '_dependency_not_duplicated',
        $scripts->registered['prism-plugin-autoloader']->deps === ['prism-core-js'],
    );
}
$cases = [
    'relative_registration' => [
        'assets/prism/plugins/autoloader/prism-autoloader.js',
        'https://example.invalid/wordpress/assets/prism/components/',
    ],
    'root_relative' => [
        '/assets/prism/plugins/autoloader/prism-autoloader.js',
        '/assets/prism/components/',
    ],
    'protocol_relative' => [
        '//cdn.example.invalid/prism/plugins/autoloader/prism-autoloader.js',
        '//cdn.example.invalid/prism/components/',
    ],
];
foreach ($cases as $name => [$src, $expected]) {
    $scripts = registry(['other-autoloader' => [$src, []]]);
    $inline = [];
    pagenest_configure_prism($scripts);
    check($name, str_contains($inline['other-autoloader'][0][0] ?? '', json_encode($expected)));
    check($name . '_no_missing_dependency', $scripts->registered['other-autoloader']->deps === []);
}
foreach (['components/prism-core.js', 'prism.min.js'] as $core) {
    $scripts = registry([
        'custom-core' => ['https://cdn.example.invalid/prism/' . $core, []],
        'prism-plugin-autoloader' => [
            'https://cdn.example.invalid/custom-loader.js',
            ['custom-core'],
        ],
    ]);
    check(
        'core_dependency_' . $core,
        pagenest_prism_components_path($scripts, 'prism-plugin-autoloader') ===
            'https://cdn.example.invalid/prism/components/',
    );
}
foreach (
    [
        false,
        '',
        'https://example.invalid/unrelated.js',
        'javascript:prism-autoloader.js',
        'https://example.invalid/prism/../plugins/autoloader/prism-autoloader.js',
        'https://example.invalid/prism/%2e%2e/plugins/autoloader/prism-autoloader.js',
    ]
    as $src
) {
    $scripts = registry(['prism-plugin-autoloader' => [$src, []]]);
    $inline = [];
    pagenest_configure_prism($scripts);
    check(
        'unknown_or_invalid_' . json_encode($src),
        $inline === [] && $scripts->registered['prism-plugin-autoloader']->deps === [],
    );
}
$scripts = registry([
    'prism-plugin-autoloader' => [
        'https://example.invalid/prism/plugins/autoloader/prism-autoloader.js',
        [],
    ],
]);
$scripts->queue = [];
$inline = [];
pagenest_configure_prism($scripts);
check('registered_only_not_configured', $inline === []);
$scripts->registered['consumer'] = (object) [
    'src' => 'https://example.invalid/consumer.js',
    'deps' => ['prism-plugin-autoloader'],
];
$scripts->queue = ['consumer'];
pagenest_configure_prism($scripts);
check('queued_dependency_configured', isset($inline['prism-plugin-autoloader']));
$scripts->registered['prism-plugin-autoloader']->deps = ['consumer'];
$inline = [];
pagenest_configure_prism($scripts);
check('dependency_cycle_terminates', isset($inline['prism-plugin-autoloader']));
$scripts->queue = [];
$scripts->to_do = ['prism-plugin-autoloader'];
$inline = [];
pagenest_configure_prism($scripts);
check('selected_resource_configured', isset($inline['prism-plugin-autoloader']));
$inline = [];
pagenest_configure_prism(registry([]));
check('absent_prism_no_inline', $inline === []);
$scripts = registry([
    'unused-core' => ['https://example.invalid/other/components/prism-core.js', []],
    'prism-plugin-autoloader' => ['https://example.invalid/loader.js', []],
]);
check(
    'unrelated_registered_core_not_used',
    pagenest_prism_components_path($scripts, 'prism-plugin-autoloader') === '',
);
foreach (['https://cdn.example.invalid/custom', '/custom/'] as $path) {
    $override = $path;
    $inline = [];
    pagenest_configure_prism($scripts);
    check(
        'filter_' . $path,
        str_contains(
            $inline['prism-plugin-autoloader'][0][0],
            json_encode(rtrim($path, '/') . '/'),
        ),
    );
}
foreach (
    [
        '',
        false,
        'javascript:alert(1)',
        'data:text/javascript,x',
        'relative/path',
        'https://example.invalid/%5csecret',
    ]
    as $path
) {
    $override = $path;
    $inline = [];
    pagenest_configure_prism($scripts);
    check('invalid_filter_' . json_encode($path), $inline === []);
}
echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
