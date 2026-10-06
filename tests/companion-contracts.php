<?php
/** Synthetic permission, compatibility and transaction fixtures. */
define('ABSPATH', __DIR__);
define('DB_NAME', 'synthetic');
$hooks = [];
$routes = [];
$meta = [];
$posts = [];
$user = 0;
$allowed = [];
$writes = 0;
$fail_counter = false;
class WP_Error
{
    public function __construct(public $code, public $message, public $data = []) {}
}
function is_wp_error($value)
{
    return $value instanceof WP_Error;
}
function add_action($name, $fn, ...$rest)
{
    global $hooks;
    $hooks[$name][] = $fn;
}
function add_filter($name, $fn, ...$rest)
{
    add_action($name, $fn);
}
function add_shortcode(...$rest) {}
function do_action($name, ...$args)
{
    global $hooks;
    foreach ($hooks[$name] ?? [] as $fn) {
        $fn(...$args);
    }
}
function apply_filters($name, $value, ...$args)
{
    global $hooks;
    foreach ($hooks[$name] ?? [] as $fn) {
        $value = $fn($value, ...$args);
    }
    return $value;
}
function get_current_user_id()
{
    global $user;
    return $user;
}
function is_user_logged_in()
{
    return get_current_user_id() > 0;
}
function current_user_can($cap, $id = 0)
{
    global $allowed;
    return in_array($id, $allowed, true);
}
function get_post($id)
{
    global $posts;
    return $posts[$id] ?? null;
}
function post_password_required($p)
{
    return $p->post_password !== '';
}
function get_post_meta($id, $key, $single = true)
{
    global $meta;
    return $meta[$id][$key] ?? '';
}
function get_posts($args)
{
    global $posts;
    return array_values(
        array_filter(
            $posts,
            static fn($p) => $p->post_type === $args['post_type'] &&
                (!isset($args['meta_key']) || get_post_meta($p->ID, $args['meta_key']) !== ''),
        ),
    );
}
function clean_post_cache($id) {}
function add_post_meta($id, $key, $value, $unique = false)
{
    global $meta, $writes;
    if (isset($meta[$id][$key])) {
        return false;
    }
    $meta[$id][$key] = $value;
    $writes++;
    return 1;
}
function update_post_meta($id, $key, $value, $previous = null)
{
    global $meta, $writes, $fail_counter;
    if ($fail_counter && $key === 'bigfa_ding') {
        return false;
    }
    if ($previous !== null && get_post_meta($id, $key) !== $previous) {
        return false;
    }
    $meta[$id][$key] = $value;
    $writes++;
    return 1;
}
function wp_slash($v)
{
    return $v;
}
function absint($v)
{
    return abs((int) $v);
}
function get_userdata($id)
{
    return (object) ['ID' => $id, 'display_name' => 'Synthetic reader'];
}
function get_option($key, $default = false)
{
    return $default;
}
function get_permalink($p)
{
    return '/post/' . (is_object($p) ? $p->ID : $p);
}
function esc_url($v)
{
    return $v;
}
function register_rest_route($namespace, $path, $args)
{
    global $routes;
    $routes[$namespace . $path] = $args;
}
class DatabaseFixture
{
    public $prefix = 'synthetic_',
        $postmeta = 'synthetic_postmeta',
        $last_error = '',
        $history = false,
        $history_error = false,
        $locked = false,
        $engine = 'InnoDB';
    private $snapshot = [];
    function prepare($sql, ...$args)
    {
        return $sql . ' ' . json_encode($args);
    }
    function get_var($sql)
    {
        if (str_contains($sql, 'GET_LOCK')) {
            return $this->locked ? '0' : '1';
        }
        if (str_contains($sql, 'RELEASE_LOCK')) {
            return '1';
        }
        if (str_contains($sql, 'information_schema')) {
            return $this->engine;
        }
        if (str_contains($sql, 'SELECT 1 FROM')) {
            $this->last_error = $this->history_error ? 'missing history table' : '';
            return $this->history ? '1' : null;
        }
        return null;
    }
    function query($sql)
    {
        global $meta;
        if ($sql === 'START TRANSACTION') {
            $this->snapshot = $meta;
        }
        if ($sql === 'ROLLBACK') {
            $meta = $this->snapshot;
        }
        return 1;
    }
}
$wpdb = new DatabaseFixture();
require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/modules/theme-compatibility.php';
require dirname(__DIR__) . '/modules/paragraph-comments.php';
require dirname(__DIR__) . '/modules/chapters.php';
require dirname(__DIR__) . '/modules/likes.php';
require dirname(__DIR__) . '/modules/resource-scope.php';
$checks = 0;
function check($name, $condition)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($name);
    }
    $checks++;
}
function rejects($profile)
{
    try {
        pagenest_companion_validate($profile);
        return false;
    } catch (Throwable $error) {
        return true;
    }
}
check('load performs no writes', $writes === 0);
check('schema version required', rejects([]));
check('unknown section rejected', rejects(['schema_version' => 1, 'unknown' => []]));
check(
    'SQL identifier rejected',
    rejects([
        'schema_version' => 1,
        'likes' => ['legacy_provider' => ['table_suffix' => 'bad;DROP']],
    ]),
);
check(
    'malformed namespace rejected',
    rejects(['schema_version' => 1, 'paragraphs' => ['rest_namespace' => 'unsafe/url']]),
);
check(
    'experience page rejects string',
    rejects(['schema_version' => 1, 'experience' => ['panel_page_id' => '7']]),
);
check(
    'negative page rejected',
    rejects(['schema_version' => 1, 'experience' => ['panel_page_id' => -1]]),
);
$temp = tempnam(sys_get_temp_dir(), 'profile');
file_put_contents($temp, '{"schema_version":1}');
check(
    'outside webroot profile accepted',
    pagenest_companion_load_profile($temp, ABSPATH)['schema_version'] === 1,
);
try {
    pagenest_companion_load_profile(__FILE__, ABSPATH);
    check('webroot profile rejected', false);
} catch (InvalidArgumentException $error) {
    check('webroot profile rejected', true);
}
unlink($temp);
foreach ([10, 11, 12, 13, 14] as $id) {
    $posts[$id] = (object) [
        'ID' => $id,
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_password' => '',
        'post_author' => 2,
    ];
}
check('unmarked post disabled', !pagenest_pc_readable(10));
$meta[10]['_pagenest_comments_enabled'] = '1';
check('explicit ordinary post enabled', pagenest_pc_readable(10));
$posts[10]->post_status = 'private';
check('private post denied anonymously', !pagenest_pc_readable(10));
$allowed = [10];
$user = 1;
check('private chapter permitted to authorized reader', pagenest_pc_readable(10));
$posts[10]->post_password = 'locked';
check('password remains enforced', !pagenest_pc_readable(10));
$posts[10]->post_password = '';
$posts[10]->post_status = 'publish';
check(
    'unknown visibility stays private',
    pagenest_pc_visibility(['visibility' => 'unknown']) === 'author_only',
);
$profile = [
    'schema_version' => 1,
    'paragraphs' => [
        'post_type' => 'fixture_note',
        'data_meta' => '_fixture_data',
        'registry_meta' => '_fixture_registry',
        'document_meta' => '_fixture_doc',
    ],
];
$GLOBALS['pagenest_companion_profile'] = pagenest_companion_validate($profile);
$note = (object) ['ID' => 90, 'post_type' => 'fixture_note', 'post_author' => 1];
$meta[90]['_fixture_data'] = [
    'post_id' => 10,
    'block_id' => 'b-neutral',
    'text' => 'private synthetic text',
    'quote' => '  code\n',
    'uuid' => 'private-uuid',
    'source_version' => 'private-source',
    'previous_quote' => 'private-history',
];
$data = pagenest_pc_data($note);
check(
    'old record reads configured storage',
    $data['text'] === 'private synthetic text' && $data['visibility'] === 'author_only',
);
$projection = pagenest_pc_comment_projection($data, $note);
check(
    'public projection excludes private identifiers',
    !isset($projection['uuid'], $projection['source_version'], $projection['previous_quote']),
);
$user = 3;
check('other author cannot mutate', !pagenest_pc_own($note));
$user = 1;
$meta[10]['_fixture_registry'] = [
    ['id' => 'b-neutral', 'active' => true],
    ['id' => 'b-neutral', 'active' => true],
];
check('ambiguous registry cannot relink', pagenest_pc_live_block(10, 'b-neutral') === null);
$GLOBALS['pagenest_companion_profile'] = pagenest_companion_validate(['schema_version' => 1]);
foreach ([10, 11, 12, 13] as $id) {
    $meta[$id]['_pagenest_document_id'] = 'D' . $id;
}
$posts[12]->post_status = 'private';
$posts[13]->post_password = 'locked';
$allowed = [];
$series = pagenest_companion_series(10);
check('series removes private and password posts', array_column($series, 'ID') === [10, 11]);
$GLOBALS['pagenest_companion_profile'] = pagenest_companion_validate([
    'schema_version' => 1,
    'chapters' => ['series_order' => ['D11', 'D10']],
]);
check(
    'explicit series order applied',
    array_column(pagenest_companion_series(10), 'ID') === [11, 10],
);
$user = 0;
check('anonymous like denied', pagenest_companion_like(0, 10)->code === 'login_required');
$user = 2;
check('author like denied', pagenest_companion_like(2, 10)->code === 'like_self');
$user = 1;
$events = [];
$hooks['pagenest_like_recorded'] = [
    static function (...$args) use (&$events) {
        $events[] = $args;
    },
];
$GLOBALS['pagenest_companion_profile'] = pagenest_companion_validate([
    'schema_version' => 1,
    'likes' => ['legacy_provider' => ['table_suffix' => 'fixture_events']],
]);
$meta[10]['bigfa_ding'] = 5;
$wpdb->history = true;
$result = pagenest_companion_like(1, 10);
check(
    'historical like no duplicate count',
    $result['already_liked'] && $result['count'] === 5 && $writes === 0,
);
$wpdb->history = false;
$wpdb->history_error = true;
check(
    'missing legacy table fails closed',
    pagenest_companion_like(1, 10)->code === 'like_history_unavailable',
);
$wpdb->history_error = false;
$meta[10]['_pagenest_like_users'] = 'corrupt';
check(
    'bad record refuses overwrite',
    pagenest_companion_like(1, 10)->code === 'like_storage_invalid',
);
unset($meta[10]['_pagenest_like_users']);
$meta[10]['bigfa_ding'] = 'invalid';
check(
    'bad counter refuses overwrite',
    pagenest_companion_like(1, 10)->code === 'like_storage_invalid',
);
$meta[10]['bigfa_ding'] = 5;
$fail_counter = true;
check('failed counter returns error', pagenest_companion_like(1, 10)->code === 'like_save_failed');
check(
    'transaction restores record',
    !isset($meta[10]['_pagenest_like_users']) && $meta[10]['bigfa_ding'] === 5,
);
$fail_counter = false;
$result = pagenest_companion_like(1, 10);
check('new like commits count', $result['count'] === 6 && !$result['already_liked']);
$result = pagenest_companion_like(1, 10);
check('retry remains idempotent', $result['count'] === 6 && $result['already_liked']);
check('durable event exposed', $events[count($events) - 1] === [1, 10, 6]);
$wpdb->locked = true;
check('lock contention rejected', pagenest_companion_like(1, 10)->code === 'like_busy');
$registry = (object) [
    'queue' => ['extension'],
    'registered' => [
        'extension' => (object) ['deps' => ['middle']],
        'middle' => (object) ['deps' => ['prism-core-js']],
        'prism-core-js' => (object) ['deps' => []],
    ],
];
check(
    'indirect dependency retains Prism',
    pagenest_prism_has_external_dependency($registry, ['prism-core-js']),
);
$registry->registered['middle']->deps = ['extension'];
check(
    'cycle ends without target',
    !pagenest_prism_has_external_dependency($registry, ['prism-core-js']),
);
$registry->queue = ['prism-core-js'];
check(
    'own queued bundle permits scoping',
    !pagenest_prism_has_external_dependency($registry, ['prism-core-js']),
);
echo "$checks Companion contract checks passed\n";
