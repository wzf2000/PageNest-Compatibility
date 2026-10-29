<?php
/** Bounded membership, preview authorization and shared public ordering. */
define('ABSPATH', __DIR__);
$meta = $posts = [];
$allowed = true;
$denied = [];
$wpdb = new class {
    public $postmeta = 'synthetic_postmeta';
    public $posts = 'synthetic_posts';
    public $last_error = '';
    public $over_budget = false;
    public function prepare($query, ...$args)
    {
        return $query;
    }
    public function get_col($query)
    {
        return $this->over_budget ? range(1, 2501) : [];
    }
    public function get_var($query)
    {
        return 'MyISAM';
    }
};
function add_action(...$args) {}
function add_filter(...$args) {}
function get_posts($query)
{
    $GLOBALS['candidate_reads'] = ($GLOBALS['candidate_reads'] ?? 0) + 1;
    return array_slice($GLOBALS['posts'], 0, $query['posts_per_page']);
}
function get_post_meta($id, $key, $single = true)
{
    $value = $GLOBALS['meta'][$id][$key] ?? null;
    return $single ? $value ?? '' : ($value === null ? [] : [$value]);
}
function current_user_can($cap, $id = null)
{
    return $GLOBALS['allowed'] && !in_array($id, $GLOBALS['denied'], true);
}
function post_password_required($post)
{
    return $post->post_password !== '';
}
function wp_verify_nonce($value, $action)
{
    return $value === 'valid';
}
function wp_salt($scheme)
{
    return 'synthetic-test-salt';
}
function __($text, $domain = '')
{
    return $text;
}
function pagenest_companion_post_setting($id, $field)
{
    $key = $field === 'series' ? '_pagenest_series' : '_pagenest_chapter_order';
    return $GLOBALS['meta'][$id][$key] ??
        ($GLOBALS['meta'][$id][$field === 'series' ? '_legacy_series' : '_legacy_order'] ?? '');
}
function is_wp_error($value)
{
    return $value instanceof WP_Error;
}
class WP_Error
{
    public function __construct(public $code, public $message) {}
    public function get_error_code()
    {
        return $this->code;
    }
    public function get_error_message()
    {
        return $this->message;
    }
}
function check($ok, $message)
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/modules/chapters.php';
$GLOBALS['pagenest_companion_profile'] = pagenest_companion_defaults();
$GLOBALS['pagenest_companion_profile']['chapters']['series_meta'] = '_legacy_series';
$GLOBALS['pagenest_companion_profile']['chapters']['order_meta'] = '_legacy_order';
foreach ([10, 11, 12] as $id) {
    $posts[] = (object) [
        'ID' => $id,
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_password' => '',
    ];
    $meta[$id] = [
        '_pagenest_series' => ' Exact series ',
        '_pagenest_document_id' => 'D' . $id,
        '_legacy_order' => '1',
    ];
}
$meta[12]['_legacy_order'] = '';
$snapshot = pagenest_companion_series_snapshot(' Exact series ');
check($snapshot['editable'], 'Complete editable article series available');
check(
    array_column($snapshot['members'], 'ID') === [10, 11, 12],
    'Original numeric/document/ID order retained',
);
check(
    !pagenest_companion_series_snapshot('Exact series')['editable'],
    'Exact identity is not trimmed',
);
$orders = [10 => '3', 11 => '2', 12 => '1'];
check(
    array_column(pagenest_companion_sort_series($posts, $orders), 'ID') === [12, 11, 10],
    'Preview shares public comparator',
);
$form = [
    'pn_series' => ' Exact series ',
    'pn_series_revision' => $snapshot['revision'],
    'pn_series_nonce' => 'valid',
    'pn_series_orders' => $orders,
    'pn_series_action' => 'preview',
];
check(
    !is_wp_error(pagenest_companion_series_request($form)),
    'Authorized preview succeeds without writes',
);
$bad = $form;
$bad['pn_series_nonce'] = 'bad';
check(is_wp_error(pagenest_companion_series_request($bad)), 'Preview requires nonce');
$allowed = false;
check(is_wp_error(pagenest_companion_series_request($form)), 'Preview requires manage_options');
$allowed = true;
$bad = $form;
unset($bad['pn_series_orders'][12]);
check(is_wp_error(pagenest_companion_series_request($bad)), 'Omitted member rejected');
$bad = $form;
$bad['pn_series_orders'][12] = '-1';
check(is_wp_error(pagenest_companion_series_request($bad)), 'Invalid numeric order rejected');
$bad = $form;
$bad['pn_series_action'] = 'save';
check(
    pagenest_companion_series_request($bad)->get_error_code() === 'series-preview',
    'Save requires exact prior preview',
);
$bad['pn_series_preview'] = pagenest_companion_series_preview_token(
    $form['pn_series'],
    $snapshot['revision'],
    $orders,
);
check(
    pagenest_companion_series_request($bad)->get_error_code() === 'series-storage',
    'Nontransactional engine rejected before any writes',
);
$bad['pn_series_orders'][10] = '4';
check(
    pagenest_companion_series_request($bad)->get_error_code() === 'series-preview',
    'Altered proposal cannot reuse preview token',
);
$meta[11]['_pagenest_chapter_order'] = '9';
check(
    pagenest_companion_series_request($form)->get_error_code() === 'series-conflict',
    'Raw neutral old value participates in conflict revision',
);
unset($meta[11]['_pagenest_chapter_order']);
$denied = [11];
$snapshot = pagenest_companion_series_snapshot(' Exact series ');
check(
    !$snapshot['editable'] && $snapshot['restricted'] && count($snapshot['members']) === 2,
    'Hidden uneditable member makes whole series read-only',
);
$denied = [];
$GLOBALS['pagenest_companion_profile']['chapters']['series_directories'] = [' Exact series ' => 99];
check(
    pagenest_companion_series_snapshot(' Exact series ')['editable'],
    'Server directory relationship does not own chapter orders',
);
$GLOBALS['pagenest_companion_profile']['chapters']['document_series'] = ['D99' => ' Exact series '];
$meta[99] = ['_pagenest_document_id' => 'D99'];
$posts[] = (object) [
    'ID' => 99,
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_password' => '',
];
check(
    count(pagenest_companion_series_snapshot(' Exact series ')['members']) === 3,
    'Directory-only document binding is not chapter membership',
);
$GLOBALS['pagenest_companion_profile']['chapters']['series_order'] = ['D11', 'D10'];
check(
    !pagenest_companion_series_snapshot(' Exact series ')['editable'],
    'Server document ranking prevents bulk order save',
);
check(
    array_column(pagenest_companion_sort_series(array_slice($posts, 0, 3), $orders), 'ID') === [
        11,
        10,
        12,
    ],
    'Server ranking still wins over numeric proposal',
);
$GLOBALS['pagenest_companion_profile']['chapters']['series_order'] = [];
for ($id = 100; $id < 601; $id++) {
    $posts[] = (object) [
        'ID' => $id,
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_password' => '',
    ];
}
check(
    !pagenest_companion_series_snapshot(' Exact series ')['editable'],
    'Candidate budget prevents incomplete series save',
);

$reads = $GLOBALS['candidate_reads'];
$wpdb->over_budget = true;
$over = pagenest_companion_series_snapshot(' Exact series ');
check(
    !$over['editable'] &&
        !$over['complete'] &&
        $over['members'] === [] &&
        $GLOBALS['candidate_reads'] === $reads,
    'Metadata budget rejects before reading candidate posts or their metadata',
);
check(
    pagenest_companion_series_candidates() === [] && $GLOBALS['candidate_reads'] === $reads,
    'Series name picker also respects early metadata budget',
);
echo "Series maintenance authorization, membership, preview and ordering contracts passed\n";
