<?php
/** Real search and metadata privacy regressions. Disposable CI WordPress only. */
if (wp_get_environment_type() !== 'local' || !getenv('COMPONENT_LAB_STATE')) {
    throw new RuntimeException('Synthetic test environment required');
}
$checks = [];
$check = static function ($ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
$original = get_option('pagenest_companion_discovery', null);
$original_public = get_option('blog_public');
$original_user = get_current_user_id();
$posts = [];
$make = static function (array $args = []) use (&$posts): int {
    $id = wp_insert_post(
        array_merge(
            [
                'post_title' => 'DiscoveryFixture public',
                'post_content' => 'Public body [private_fixture secret="never-index"]',
                'post_excerpt' => 'Safe hand-written summary',
                'post_status' => 'publish',
                'post_type' => 'post',
                'post_author' => 1,
            ],
            $args,
        ),
        true,
    );
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $posts[] = $id;
    return $id;
};
$rest = static function (string $q, string $kind = 'all') {
    $request = new WP_REST_Request('GET', '/pagenest/v1/discovery');
    $request->set_param('q', $q);
    $request->set_param('kind', $kind);
    return rest_do_request($request);
};
$visit = static function (array $vars): void {
    $GLOBALS['wp_query'] = new WP_Query($vars);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
};
try {
    wp_set_current_user(1);
    update_option('blog_public', 1);
    update_option('pagenest_companion_discovery', ['search' => true, 'seo' => true]);
    add_shortcode('private_fixture', static function () {
        throw new RuntimeException('Shortcode must never execute');
    });
    $public = $make();
    $page = $make(['post_type' => 'page']);
    $password = $make(['post_password' => 'fixture-password']);
    $private = $make(['post_status' => 'private']);
    $draft = $make(['post_status' => 'draft']);
    $check(
        !pagenest_discovery_public_post(get_post($password)),
        'Password posts excluded even for administrator',
    );
    $check(
        !pagenest_discovery_public_post(get_post($private)),
        'Private posts excluded even for administrator',
    );
    $response = $rest('DiscoveryFixture');
    $check($response->get_status() === 200, 'Public suggestions work');
    $ids = array_column($response->get_data()['items'], 'id');
    $check(
        in_array($public, $ids) &&
            in_array($page, $ids) &&
            !array_intersect($ids, [$password, $private, $draft]),
        'Suggestions contain only published unprotected posts and pages',
    );
    $check(
        ($response->get_headers()['Cache-Control'] ?? '') === 'private, no-store',
        'Suggestions prohibit shared cache',
    );
    $check(
        $rest('x')->get_status() === 400 &&
            $rest(str_repeat('x', 101))->get_status() === 400 &&
            $rest('DiscoveryFixture', 'invalid')->get_status() === 400,
        'REST query length and scope validated: ' .
            wp_json_encode([
                $rest('x')->get_status(),
                $rest(str_repeat('x', 101))->get_status(),
                $rest('DiscoveryFixture', 'invalid')->get_status(),
            ]),
    );
    $check(
        array_column($rest('DiscoveryFixture', 'post')->get_data()['items'], 'id') === [$public],
        'Article scope excludes pages',
    );
    $check(
        !str_contains(wp_json_encode($response->get_data()), 'never-index'),
        'Suggestions do not expose body parameters',
    );
    $visit(['p' => $public]);
    $data = pagenest_seo_metadata();
    $check(
        $data['meta']['description'] === 'Safe hand-written summary' &&
            $data['meta']['og:type'] === 'article',
        'Article uses explicit excerpt and article share type',
    );
    $check(
        $data['schema'][0]['@type'] === 'BlogPosting' &&
            $data['schema'][1]['@type'] === 'BreadcrumbList',
        'Article schema and breadcrumbs emitted',
    );
    wp_update_post(['ID' => $public, 'post_excerpt' => '']);
    update_option('pagenest_companion_discovery', [
        'search' => true,
        'seo' => true,
        'description' => 'Site summary',
    ]);
    $visit(['p' => $public]);
    $check(
        pagenest_seo_metadata()['meta']['description'] === 'Site summary',
        'Missing excerpt falls back without parsing body',
    );
    $visit(['p' => $password]);
    $check(pagenest_seo_metadata() === [], 'Password SEO withheld even for administrator');
    $visit(['p' => $private]);
    $check(pagenest_seo_metadata() === [], 'Private SEO withheld');
    $visit(['s' => 'DiscoveryFixture']);
    $check(pagenest_seo_metadata() === [], 'Search SEO omitted');
    $visit(['p' => $public, 'preview' => true]);
    $check(pagenest_seo_metadata() === [], 'Preview SEO omitted');
    $visit(['p' => $public, 'page' => 2]);
    $check(pagenest_seo_metadata() === [], 'Multipage article secondary pages omitted');
    $visit(['p' => $public]);
    update_option('blog_public', 0);
    $check(pagenest_seo_metadata() === [], 'Site privacy setting respected');
    update_option('blog_public', 1);
    add_filter('pagenest_companion_seo_external_owner', '__return_true');
    $check(pagenest_seo_metadata() === [], 'External SEO owner suppresses output');
    remove_filter('pagenest_companion_seo_external_owner', '__return_true');
    wp_update_post([
        'ID' => $public,
        'post_title' => 'DiscoveryFixture </script><script>alert(1)</script>',
    ]);
    $visit(['p' => $public]);
    ob_start();
    do_action('wp_head');
    $head = ob_get_clean();
    $check(
        !str_contains($head, '</script><script>alert'),
        'Structured data and metadata escape injected markup',
    );
    update_option('pagenest_companion_discovery', []);
    $check(
        in_array($rest('DiscoveryFixture')->get_status(), [401, 403], true) &&
            pagenest_seo_metadata() === [],
        'Modules default off',
    );
    update_option('pagenest_companion_discovery', ['search' => true, 'seo' => true]);
    // Load the real optional registry without enabling any production host or provider.
    require_once dirname(__DIR__) . '/modules/tools/providers.php';
    require_once dirname(__DIR__) . '/modules/tools/registry.php';
    \PageNest\Tools\Providers::register('fixture', [
        'root' => __DIR__,
        'url' => home_url('/fixture/'),
        'manifest' => [],
        'handlers' => [
            'fixture' => ['render' => static fn() => '', 'validate' => static function () {}],
        ],
    ]);
    $toolpage = $make(['post_type' => 'page', 'post_name' => 'fixture-tool']);
    $tool = [
        'schema_version' => 1,
        'slug' => 'fixture',
        'kind' => 'internal',
        'title' => 'Fixture',
        'description' => '',
        'category' => 'test',
        'version' => '1.0.0',
        'status' => 'active',
        'binding' => ['post_id' => $toolpage, 'post_name' => 'fixture-tool'],
        'listed' => true,
        'access' => ['mode' => 'authenticated'],
        'assets' => [],
        'input_schema' => [],
        'render' => 'fixture',
        'service' => null,
        'data' => [],
        'limits' => [],
        'owner' => 'fixture',
        'maintenance' => 'fixture',
    ];
    $load = static fn($tools) => \PageNest\Tools\Registry::load([
        'schema_version' => 1,
        'categories' => ['test'],
        'tools' => $tools,
    ]);
    $load([$tool]);
    $check(
        !pagenest_discovery_public_post(get_post($toolpage)),
        'Authenticated tool omitted despite administrator caller',
    );
    wp_update_post(['ID' => $toolpage, 'post_name' => 'renamed-tool']);
    $check(
        !pagenest_discovery_public_post(get_post($toolpage)),
        'Renamed restricted tool cannot become discoverable',
    );
    unset($_GET['pn_kind']);
    $main = new WP_Query();
    $GLOBALS['wp_the_query'] = $main;
    $main->query(['s' => 'DiscoveryFixture', 'posts_per_page' => 2]);
    $check(
        !array_intersect(array_column($main->posts, 'ID'), [
            $password,
            $private,
            $draft,
            $toolpage,
        ]) && $main->get('posts_per_page') === 2,
        'Old search URLs get privacy filters and retain pagination',
    );
    $tool['binding']['post_name'] = 'renamed-tool';
    $tool['access']['mode'] = 'public';
    $load([$tool]);
    $check(
        pagenest_discovery_public_post(get_post($toolpage)) &&
            array_column($rest('DiscoveryFixture', 'tool')->get_data()['items'], 'id') === [
                $toolpage,
            ],
        'Public registered provider appears in tool scope',
    );
    $tool['render'] = 'missing-provider';
    $load([$tool]);
    $check(
        !pagenest_discovery_public_post(get_post($toolpage)),
        'Unavailable provider is not promoted',
    );
    try {
        \PageNest\Tools\Registry::load([]);
    } catch (InvalidArgumentException $e) {
    }
    $check(
        !pagenest_discovery_public_post(get_post($page)) &&
            pagenest_discovery_public_post(get_post($public)),
        'Broken catalog fails closed for pages while retaining posts',
    );
    $check(
        array_column($rest('DiscoveryFixture')->get_data()['items'], 'id') === [$public],
        'Broken catalog never leaks tool pages',
    );
    $load([]);
    echo wp_json_encode(['checks' => count($checks), 'passed' => $checks], JSON_PRETTY_PRINT) .
        "\n";
} finally {
    foreach ($posts as $id) {
        wp_delete_post($id, true);
    }
    if ($original === null) {
        delete_option('pagenest_companion_discovery');
    } else {
        update_option('pagenest_companion_discovery', $original);
    }
    update_option('blog_public', $original_public);
    wp_set_current_user($original_user);
    remove_shortcode('private_fixture');
}
