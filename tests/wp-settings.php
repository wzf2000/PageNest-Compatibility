<?php
/** Exercise author controls in an explicitly owned synthetic WordPress database. */
if (wp_get_environment_type() !== 'local' || !getenv('COMPONENT_LAB_STATE')) {
    throw new RuntimeException('Synthetic test environment required');
}
$state = json_decode(
    file_get_contents(getenv('COMPONENT_LAB_STATE')),
    true,
    32,
    JSON_THROW_ON_ERROR,
);
$GLOBALS['companion_settings_checks'] = [];
function settings_assert($ok, $message)
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $GLOBALS['companion_settings_checks'][] = $message;
}
$post = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_author' => $state['author'],
    'post_title' => 'Ordinary UI fixture',
    'post_content' => '<p>A unique ordinary paragraph.</p>',
]);
settings_assert(!pagenest_pc_enabled($post), 'Ordinary article starts disabled');
wp_set_current_user($state['reader']);
ob_start();
pagenest_companion_settings_page();
$unauthorized = ob_get_clean();
settings_assert($unauthorized === '', 'Subscriber cannot render administrator settings');
ob_start();
pagenest_companion_post_box(get_post($post));
$unauthorized = ob_get_clean();
settings_assert($unauthorized === '', 'Subscriber cannot render another author reading controls');
wp_set_current_user($state['author']);
ob_start();
pagenest_companion_post_box(get_post($post));
$controls = ob_get_clean();
settings_assert(
    str_contains($controls, 'pagenest-reading-options-nonce') &&
        str_contains($controls, 'pagenest-reading-comments') &&
        str_contains($controls, 'pagenest-reading-series') &&
        str_contains($controls, 'pagenest-reading-order'),
    'Authorized metabox renders nonce and feature controls',
);
$old_chapter_config = $GLOBALS['pagenest_companion_profile']['chapters'];
$GLOBALS['pagenest_companion_profile']['chapters']['series_meta'] = '_fixture_old_series';
$GLOBALS['pagenest_companion_profile']['chapters']['order_meta'] = '_fixture_old_order';
update_post_meta($post, '_fixture_old_series', '');
update_post_meta($post, '_fixture_old_order', '');
$_POST = [
    'pagenest-reading-options-nonce' => wp_create_nonce('pagenest-reading-options:' . $post),
    'pagenest-reading-comments' => 'keep',
    'pagenest-reading-series' => '',
    'pagenest-reading-order' => '',
];
pagenest_companion_save_post_options($post);
settings_assert(
    metadata_exists('post', $post, '_fixture_old_series') &&
        get_post_meta($post, '_fixture_old_series', true) === '' &&
        get_post_meta($post, '_fixture_old_order', true) === '' &&
        !metadata_exists('post', $post, '_pagenest_series') &&
        !metadata_exists('post', $post, '_pagenest_chapter_order') &&
        !metadata_exists('post', $post, '_pagenest_comments_enabled'),
    'Keep preserves legacy empty values without creating neutral overrides',
);
$form = [
    'pagenest-reading-options-nonce' => wp_create_nonce('pagenest-reading-options:' . $post),
    'pagenest-reading-comments' => '1',
    'pagenest-reading-series' => 'Synthetic series',
    'pagenest-reading-order' => '2',
];
$_POST = $form;
unset($_POST['pagenest-reading-options-nonce']);
pagenest_companion_save_post_options($post);
settings_assert(
    !metadata_exists('post', $post, '_pagenest_comments_enabled'),
    'Missing nonce cannot enable article or save metadata',
);
$_POST = $form;
$_POST['pagenest-reading-options-nonce'] = 'invalid';
pagenest_companion_save_post_options($post);
settings_assert(
    !metadata_exists('post', $post, '_pagenest_comments_enabled'),
    'Bad nonce leaves ordinary article disabled',
);
wp_set_current_user($state['reader']);
$_POST = $form;
pagenest_companion_save_post_options($post);
settings_assert(!pagenest_pc_enabled($post), 'Subscriber cannot enable another article');
wp_set_current_user($state['author']);
$_POST = $form;
$_POST['pagenest-reading-options-nonce'] = wp_create_nonce('pagenest-reading-options:' . $post);
pagenest_companion_save_post_options($post);
settings_assert(pagenest_pc_enabled($post), 'Owner explicitly enables ordinary article');
$registry = get_post_meta($post, pagenest_companion_config('paragraphs', 'registry_meta'), true);
settings_assert(count($registry) === 1, 'Only enabled article gets one registry paragraph');
settings_assert(
    pagenest_companion_post_setting($post, 'series') === 'Synthetic series' &&
        pagenest_companion_post_setting($post, 'order') === '2',
    'Series and order save through article UI',
);
$before = get_post_meta(
    $state['post'],
    pagenest_companion_config('paragraphs', 'registry_meta'),
    true,
);
settings_assert($before === $state['registry'], 'Another chapter registry stays unchanged');
$_POST['pagenest-reading-comments'] = 'keep';
$_POST['pagenest-reading-order'] = '2.5';
pagenest_companion_save_post_options($post);
settings_assert(
    pagenest_companion_post_setting($post, 'order') === '2',
    'Invalid numeric order refuses complete update',
);
update_post_meta($post, '_fixture_old_series', 'Legacy fixture series');
$_POST['pagenest-reading-series'] = '';
$_POST['pagenest-reading-order'] = '2';
pagenest_companion_save_post_options($post);
settings_assert(
    pagenest_companion_post_setting($post, 'series') === '' &&
        get_post_meta($post, '_fixture_old_series', true) === 'Legacy fixture series',
    'Explicit empty neutral series remains authoritative and preserves old key',
);
$revision = wp_save_post_revision($post);
if ($revision) {
    $_POST['pagenest-reading-comments'] = '0';
    pagenest_companion_save_post_options($revision);
    settings_assert(
        !metadata_exists('post', $revision, '_pagenest_comments_enabled'),
        'Revision cannot receive article feature meta',
    );
}
$_POST['pagenest-reading-comments'] = '0';
$_POST['pagenest-reading-order'] = '2';
pagenest_companion_save_post_options($post);
settings_assert(!pagenest_pc_enabled($post), 'Explicit disable suppresses new comments');
settings_assert(
    get_post_meta($post, pagenest_companion_config('paragraphs', 'registry_meta'), true) ===
        $registry,
    'Disable retains stable registry',
);
$_POST = [];
wp_set_current_user($state['reader']);
update_option('pagenest_companion_features', ['comments' => 0, 'chapters' => 1, 'likes' => 0]);
settings_assert(
    is_wp_error(pagenest_pc_permission()) &&
        pagenest_pc_permission()->get_error_data()['status'] === 503,
    'Global comment switch blocks private/write API',
);
settings_assert(
    pagenest_companion_like($state['reader'], $post)->get_error_code() === 'likes_disabled',
    'Global like switch blocks service',
);
delete_option('pagenest_companion_features');
$before = get_post_meta(
    $state['notes'][1]['id'],
    pagenest_companion_config('paragraphs', 'data_meta'),
    true,
);
settings_assert(
    ($before['visibility'] ?? null) === 'author_only',
    'Control changes do not publish old private records',
);
$GLOBALS['pagenest_companion_profile']['chapters'] = $old_chapter_config;
wp_delete_post($post, true);
echo wp_json_encode(
    [
        'count' => count($GLOBALS['companion_settings_checks']),
        'checks' => $GLOBALS['companion_settings_checks'],
    ],
    JSON_PRETTY_PRINT,
) . "\n";
