<?php
/** Real WordPress regressions using only an explicitly owned synthetic lab. */
if (
    wp_get_environment_type() !== 'local' ||
    !getenv('COMPONENT_LAB_PROFILE') ||
    !getenv('COMPONENT_LAB_STATE')
) {
    throw new RuntimeException('Explicit synthetic lab configuration required');
}
$state_path = getenv('COMPONENT_LAB_STATE');
$profile = json_decode(
    file_get_contents(getenv('COMPONENT_LAB_PROFILE')),
    true,
    32,
    JSON_THROW_ON_ERROR,
);
$paragraphs = $profile['paragraphs'];
$mode = getenv('COMPONENT_LAB_MODE');
$GLOBALS['component_lab_checks'] = [];
function lab_assert($condition, $name)
{
    if (!$condition) {
        throw new RuntimeException($name);
    }
    $GLOBALS['component_lab_checks'][] = $name;
}
function lab_request($method, $path, $user = 0, $body = null, $query = [])
{
    wp_set_current_user($user);
    $request = new WP_REST_Request($method, $path);
    if ($body !== null) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }
    $request->set_query_params($query);
    return rest_do_request($request);
}
if ($mode === 'seed') {
    $users = get_users(['orderby' => 'ID', 'order' => 'ASC']);
    lab_assert(count($users) >= 2, 'Synthetic users exist');
    $author = (int) $users[0]->ID;
    $reader = (int) $users[1]->ID;
    $post = wp_insert_post(
        wp_slash([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_author' => $author,
            'post_title' => 'Migration paragraph fixture',
            'post_content' =>
                '<h2>First section</h2><p>Unique migration paragraph.</p><h3>Nested section</h3><p>Second fixture paragraph.</p>',
            'meta_input' => [$paragraphs['document_meta'] => 'SYN-01'],
        ]),
        true,
    );
    lab_assert(!is_wp_error($post), 'Synthetic chapter saved');
    pagenest_pc_refresh($post);
    $registry = get_post_meta($post, $paragraphs['registry_meta'], true);
    lab_assert(
        is_array($registry) && count($registry) === 2,
        'Stable backend registered both paragraphs',
    );
    $notes = [];
    foreach (['public', 'author_only', null, 'unexpected'] as $index => $visibility) {
        $data = [
            'uuid' => wp_generate_uuid4(),
            'post_id' => $post,
            'document_id' => 'SYN-01',
            'block_id' => $registry[0]['id'],
            'quote' => $registry[0]['text'],
            'text' => 'Migration sentinel ' . $index,
            'created_at' => gmdate('c'),
            '_revision' => wp_generate_uuid4(),
            'source_version' => hash('sha256', ''),
        ];
        if ($visibility !== null) {
            $data['visibility'] = $visibility;
        }
        $id = wp_insert_post(
            wp_slash([
                'post_type' => $paragraphs['post_type'],
                'post_status' => 'private',
                'post_author' => $reader,
                'post_title' => 'Synthetic legacy record',
                'meta_input' => [
                    $paragraphs['data_meta'] => $data,
                    $paragraphs['uuid_meta'] => $data['uuid'],
                ],
            ]),
            true,
        );
        lab_assert(!is_wp_error($id), 'Seed legacy visibility case ' . $index);
        $notes[] = ['id' => $id, 'data' => $data];
    }
    update_post_meta($post, $profile['likes']['counter_meta'], 7);
    global $wpdb;
    $suffix = $profile['likes']['legacy_provider']['table_suffix'];
    lab_assert(
        (bool) preg_match('/^[a-zA-Z0-9_]+$/D', $suffix),
        'Synthetic history table identifier valid',
    );
    $table = $wpdb->prefix . $suffix;
    $sql =
        'CREATE TABLE IF NOT EXISTS `' .
        $table .
        '` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,event_key varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,user_id bigint unsigned NOT NULL,kind varchar(32) CHARACTER SET ascii NOT NULL,object_id bigint unsigned NOT NULL DEFAULT 0,local_day date NOT NULL,xp bigint NOT NULL,created_at bigint NOT NULL,detail text NULL,KEY user_day(user_id,local_day,kind),KEY object_kind(object_id,kind)) ENGINE=InnoDB ' .
        $wpdb->get_charset_collate();
    lab_assert($wpdb->query($sql) !== false, 'Synthetic historical event table created');
    lab_assert(
        $wpdb->insert($table, [
            'event_key' => 'like:' . $reader . ':' . $post,
            'user_id' => $reader,
            'kind' => 'like',
            'object_id' => $post,
            'local_day' => gmdate('Y-m-d'),
            'xp' => 0,
            'created_at' => time(),
        ]) !== false,
        'Historical like seeded',
    );
    file_put_contents(
        $state_path,
        wp_json_encode(compact('author', 'reader', 'post', 'notes', 'registry'), JSON_PRETTY_PRINT),
    );
} elseif ($mode === 'verify') {
    $state = json_decode(file_get_contents($state_path), true, 32, JSON_THROW_ON_ERROR);
    extract($state, EXTR_SKIP);
    $namespace = pagenest_companion_config('paragraphs', 'rest_namespace');
    foreach ($notes as $entry) {
        lab_assert(
            get_post_meta($entry['id'], $paragraphs['data_meta'], true) === $entry['data'],
            'Stored legacy record unchanged ' . $entry['id'],
        );
    }
    lab_assert(
        get_post_meta($post, $paragraphs['registry_meta'], true) === $registry,
        'Registry unchanged on load',
    );
    wp_set_current_user(0);
    $html = pagenest_pc_blocks(get_post_field('post_content', $post), $post);
    lab_assert(str_contains($html, $registry[0]['id']), 'Existing block IDs survive new render');
    lab_assert(
        get_post_meta($post, $paragraphs['registry_meta'], true) === $registry,
        'Frontend registry rendering does not write',
    );
    $public = lab_request('GET', '/' . $namespace . '/comments', 0, null, ['post' => $post]);
    lab_assert(
        $public->get_status() === 200 && count($public->get_data()) === 1,
        'Anonymous sees only explicitly public record',
    );
    lab_assert(
        !isset($public->get_data()[0]['uuid'], $public->get_data()[0]['version']),
        'Anonymous projection excludes private tokens',
    );
    $private = lab_request('GET', '/' . $namespace . '/notes', 0, null, ['post' => $post]);
    lab_assert($private->get_status() === 401, 'Anonymous private endpoint denied');
    $other = lab_request('GET', '/' . $namespace . '/notes', $author, null, ['post' => $post]);
    lab_assert(
        $other->get_status() === 200 && count($other->get_data()) === 0,
        'Other user cannot see old private records',
    );
    $own = lab_request('GET', '/' . $namespace . '/notes', $reader, null, ['post' => $post]);
    lab_assert(
        $own->get_status() === 200 && count($own->get_data()) === 4,
        'Owner retains all legacy records',
    );
    foreach ($own->get_data() as $entry) {
        if ($entry['id'] !== $notes[0]['id']) {
            lab_assert(
                $entry['visibility'] === 'author_only',
                'Unknown or absent visibility stays private ' . $entry['id'],
            );
        }
    }
    foreach (pagenest_companion_config('paragraphs', 'rest_aliases') as $alias) {
        $response = lab_request('GET', '/' . $alias . '/comments', 0, null, ['post' => $post]);
        lab_assert(
            $response->get_status() === 200 && count($response->get_data()) === 1,
            'External namespace alias works',
        );
    }
    $body = [
        'post_id' => $post,
        'block_id' => $registry[0]['id'],
        'text' => 'New public fixture',
        'uuid' => wp_generate_uuid4(),
        'public_confirmed' => true,
    ];
    $created = lab_request('POST', '/' . $namespace . '/comments', $reader, $body);
    lab_assert($created->get_status() === 200, 'Public comment creation succeeds');
    $new = $created->get_data();
    $repeat = lab_request('POST', '/' . $namespace . '/comments', $reader, $body);
    lab_assert($repeat->get_data()['id'] === $new['id'], 'Creation UUID is idempotent');
    $no_version = lab_request('PATCH', '/' . $namespace . '/comments/' . $new['id'], $reader, [
        'text' => 'Missing token',
    ]);
    lab_assert($no_version->get_status() === 409, 'Missing version cannot overwrite a comment');
    $foreign = lab_request('PATCH', '/' . $namespace . '/comments/' . $new['id'], $author, [
        'text' => 'Foreign edit',
        'version' => $new['version'],
    ]);
    lab_assert($foreign->get_status() === 403, 'Other user cannot edit public record');
    $edited = lab_request('PATCH', '/' . $namespace . '/comments/' . $new['id'], $reader, [
        'text' => 'Edited fixture',
        'version' => $new['version'],
    ]);
    lab_assert($edited->get_status() === 200, 'Owner edits public record with current version');
    $stale = lab_request('DELETE', '/' . $namespace . '/comments/' . $new['id'], $reader, [
        'version' => $new['version'],
    ]);
    lab_assert($stale->get_status() === 409, 'Stale deletion refused');
    $removed = lab_request('DELETE', '/' . $namespace . '/comments/' . $new['id'], $reader, [
        'version' => $edited->get_data()['version'],
    ]);
    lab_assert($removed->get_status() === 200, 'Owner removes public record');
    $like = lab_request('POST', '/pagenest-companion/v1/likes/' . $post, $reader);
    lab_assert(
        $like->get_status() === 200 &&
            $like->get_data()['already_liked'] &&
            $like->get_data()['count'] === 7,
        'Historical like is not counted again',
    );
    $self = lab_request('POST', '/pagenest-companion/v1/likes/' . $post, $author);
    lab_assert($self->get_status() === 403, 'Author self-like denied');
    $anonymous = lab_request('POST', '/pagenest-companion/v1/likes/' . $post);
    lab_assert($anonymous->get_status() === 401, 'Anonymous like denied');
    $fresh = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_author' => $author,
        'post_title' => 'Fresh likes fixture',
    ]);
    $first = lab_request('POST', '/pagenest-companion/v1/likes/' . $fresh, $reader);
    lab_assert(
        $first->get_status() === 200 && $first->get_data()['count'] === 1,
        'New like commits one count',
    );
    $second = lab_request('POST', '/pagenest-companion/v1/likes/' . $fresh, $reader);
    lab_assert(
        $second->get_status() === 200 &&
            $second->get_data()['already_liked'] &&
            $second->get_data()['count'] === 1,
        'New like record is idempotent',
    );
    $original_profile = $GLOBALS['pagenest_companion_profile'];
    $GLOBALS['pagenest_companion_profile']['chapters'] = [
        'series_meta' => '_synthetic_series',
        'document_meta' => '_synthetic_document',
        'order_meta' => '_synthetic_order',
        'series_order' => ['B', 'A', 'C'],
        'link_map' => [],
    ];
    $chapter_ids = [];
    foreach (['A', 'B', 'C'] as $document) {
        $chapter_ids[$document] = wp_insert_post([
            'post_type' => 'post',
            'post_status' => $document === 'C' ? 'private' : 'publish',
            'post_author' => $author,
            'post_title' => 'Series fixture ' . $document,
            'meta_input' => ['_synthetic_series' => 'Fixture', '_synthetic_document' => $document],
        ]);
    }
    wp_set_current_user(0);
    $series = pagenest_companion_series($chapter_ids['A']);
    lab_assert(
        array_map(static fn($item) => (int) $item->ID, $series) === [
            $chapter_ids['B'],
            $chapter_ids['A'],
        ],
        'Explicit chapter order preserves private visibility',
    );
    $GLOBALS['pagenest_companion_profile']['chapters']['link_map'] = [
        'chapter-next.md' => (int) $chapter_ids['B'],
        'chapter-private.md' => (int) $chapter_ids['C'],
    ];
    $links = apply_filters(
        'the_content',
        '<a href="../chapters/chapter-next.md#part">Next</a><a href="../chapter-private.md">Private</a><a href="https://example.invalid/chapter-next.md">External</a>',
    );
    lab_assert(
        str_contains($links, esc_url(get_permalink($chapter_ids['B']) . '#part')),
        'Parent-relative chapter link converts with fragment',
    );
    lab_assert(
        str_contains($links, 'href="../chapter-private.md"'),
        'Unreadable chapter link remains unchanged',
    );
    lab_assert(
        str_contains($links, 'https://example.invalid/chapter-next.md'),
        'External chapter-like URL remains unchanged',
    );
    $GLOBALS['pagenest_companion_profile'] = $original_profile;
    $hint_class = array_search(
        'pagenest-exercise-hint',
        $original_profile['theme']['class_aliases'],
        true,
    );
    if ($hint_class !== false) {
        $converted = apply_filters(
            'the_content',
            '<span class="' . esc_attr($hint_class) . '">Synthetic hint</span>',
        );
        lab_assert(
            str_contains($converted, 'pagenest-exercise-hint'),
            'Configured old hint class gets neutral presentation alias',
        );
    }
    $bad_profile = $original_profile;
    $bad_profile['paragraphs']['unknown_setting'] = 'value';
    try {
        pagenest_companion_validate($bad_profile);
        lab_assert(false, 'Unknown profile key must reject');
    } catch (InvalidArgumentException $error) {
        lab_assert(true, 'Unknown profile key rejected before feature loading');
    }
} else {
    throw new RuntimeException('Expected seed or verify mode');
}
echo wp_json_encode(
    [
        'mode' => $mode,
        'checks' => $GLOBALS['component_lab_checks'],
        'count' => count($GLOBALS['component_lab_checks']),
    ],
    JSON_PRETTY_PRINT,
) . "\n";
