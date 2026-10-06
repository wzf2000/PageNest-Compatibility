<?php
/** Paragraph comments and author-only legacy notes. */
if (!defined('ABSPATH')) {
    exit();
}

function pagenest_pc_enabled($id)
{
    return (bool) get_post_meta(
        $id,
        pagenest_companion_config('paragraphs', 'document_meta'),
        true,
    ) || get_post_meta($id, pagenest_companion_config('paragraphs', 'enabled_meta'), true) === '1';
}

function pagenest_pc_readable($id)
{
    $p = get_post($id);
    return $p &&
        pagenest_pc_enabled($id) &&
        !post_password_required($p) &&
        ($p->post_status === 'publish' || current_user_can('read_post', $id));
}
add_action('init', function () {
    register_post_type(pagenest_companion_config('paragraphs', 'post_type'), [
        'public' => false,
        'show_ui' => false,
        'show_in_rest' => false,
        'exclude_from_search' => true,
        'rewrite' => false,
        'query_var' => false,
        'supports' => [],
        'capabilities' => [
            'read_post' => 'do_not_allow',
            'edit_post' => 'do_not_allow',
            'delete_post' => 'do_not_allow',
            'edit_posts' => 'do_not_allow',
            'create_posts' => 'do_not_allow',
            'read_private_posts' => 'do_not_allow',
        ],
    ]);
});

// Registry changes only on saves/explicit CLI refresh. Frontend rendering is read-only.
function pagenest_pc_blocks($html, $id, $persist = false)
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $oldErrors = libxml_use_internal_errors(true);
    $dom->loadHTML(
        '<?xml encoding="UTF-8"><div id="pagenest-pc-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
    );
    libxml_clear_errors();
    libxml_use_internal_errors($oldErrors);
    $xp = new DOMXPath($dom);
    $nodes = $xp->query(
        '//*[@id="pagenest-pc-root"]//p | //*[@id="pagenest-pc-root"]//pre | //*[@id="pagenest-pc-root"]//li[not(descendant::p)]',
    );
    $items = [];
    foreach ($nodes as $node) {
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
        if (str_contains(' ' . $node->getAttribute('class') . ' ', ' wp-block-mbb-math ')) {
            $text = '$$ ' . $text . ' $$';
        }
        if ($text !== '') {
            $items[] = ['node' => $node, 'text' => $text, 'hash' => hash('sha256', $text)];
        }
    }
    $old = get_post_meta($id, pagenest_companion_config('paragraphs', 'registry_meta'), true);
    if (!is_array($old)) {
        $old = [];
    }
    $counts = array_count_values(array_column($items, 'hash'));
    $used = [];
    $registry = [];
    foreach ($items as $i => $item) {
        $prev = $items[$i - 1]['hash'] ?? '';
        $next = $items[$i + 1]['hash'] ?? '';
        $matches = array_values(
            array_filter($old, fn($v) => $v['hash'] === $item['hash'] && !isset($used[$v['id']])),
        );
        // Only during source-preserving migration: WordPress may remove a CJK soft-break space.
        if (!$matches && !empty($GLOBALS['mbb_migrating'])) {
            $key = static fn($text) => preg_replace(
                '/(?<=[\p{Han}。；！？：，])\s+|\s+(?=[\p{Han}$])/u',
                '',
                str_replace('…', '...', $text),
            );
            $normalized = $key($item['text']);
            $same = array_filter($items, fn($x) => $key($x['text']) === $normalized);
            if (count($same) === 1) {
                $matches = array_values(
                    array_filter(
                        $old,
                        fn($v) => !isset($used[$v['id']]) && $key($v['text']) === $normalized,
                    ),
                );
            }
        }
        if ($counts[$item['hash']] !== 1 || count($matches) !== 1) {
            $matches = array_values(
                array_filter($matches, fn($v) => $v['prev'] === $prev && $v['next'] === $next),
            );
        }
        $bid =
            count($matches) === 1
                ? $matches[0]['id']
                : ($persist
                    ? 'b-' . wp_generate_uuid4()
                    : null);
        if (!$bid) {
            continue;
        } // Ambiguous/new unsaved block cannot accept a note yet.
        $used[$bid] = true;
        $registry[] = [
            'id' => $bid,
            'hash' => $item['hash'],
            'text' => $item['text'],
            'prev' => $prev,
            'next' => $next,
            'active' => true,
        ];
        $item['node']->setAttribute('data-pagenest-block', $bid);
        $item['node']->setAttribute('id', $bid);
    }
    if ($persist) {
        foreach ($old as $v) {
            if (!isset($used[$v['id']])) {
                $v['active'] = false;
                $registry[] = $v;
            }
        }
        update_post_meta(
            $id,
            pagenest_companion_config('paragraphs', 'registry_meta'),
            wp_slash($registry),
        );
    }
    $root = $dom->getElementById('pagenest-pc-root');
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }
    return $out;
}
add_filter(
    'the_content',
    function ($html) {
        $id = get_the_ID();
        return pagenest_pc_readable($id)
            ? '<div class="pagenest-comments-body">' . pagenest_pc_blocks($html, $id) . '</div>'
            : $html;
    },
    35,
);
function pagenest_pc_refresh($id)
{
    if (!pagenest_pc_enabled($id)) {
        return;
    }
    $previous = $GLOBALS['post'] ?? null;
    $GLOBALS['post'] = get_post($id);
    // Reading adapter renders from Markdown; remove the read-only anchor pass here.
    $html = apply_filters('the_content', get_post_field('post_content', $id, 'raw'));
    pagenest_pc_blocks($html, $id, true);
    $GLOBALS['post'] = $previous;
}
add_action(
    'wp_after_insert_post',
    function ($id, $p) {
        if ($p->post_type === 'post') {
            pagenest_pc_refresh($id);
        }
    },
    100,
    2,
);

function pagenest_pc_own($note)
{
    return $note &&
        $note->post_type === pagenest_companion_config('paragraphs', 'post_type') &&
        (int) $note->post_author === get_current_user_id();
}

function pagenest_pc_version($data)
{
    return hash('sha256', serialize($data));
}

function pagenest_pc_visibility($data)
{
    // Legacy and unknown values never opt a note into public consumption.
    return ($data['visibility'] ?? null) === 'public' ? 'public' : 'author_only';
}

function pagenest_pc_data($note)
{
    $data = get_post_meta($note->ID, pagenest_companion_config('paragraphs', 'data_meta'), true);
    if (!is_array($data)) {
        return new WP_Error('invalid_note', '笔记记录无法读取。', ['status' => 500]);
    }
    $data['version'] = pagenest_pc_version($data);
    $data['visibility'] = pagenest_pc_visibility($data);
    $data['id'] = $note->ID;
    $registry = get_post_meta(
        $data['post_id'],
        pagenest_companion_config('paragraphs', 'registry_meta'),
        true,
    );
    $live = array_filter(
        is_array($registry) ? $registry : [],
        fn($block) => !empty($block['active']) && $block['id'] === $data['block_id'],
    );
    $data['association'] = count($live) === 1 ? 'attached' : 'needs_review';
    unset($data['_revision'], $data['_create_fingerprint']);
    return $data;
}

function pagenest_pc_permission()
{
    return is_user_logged_in()
        ? true
        : new WP_Error('login_required', '请先登录。', ['status' => 401]);
}

function pagenest_pc_error($code, $message, $status = 400)
{
    return new WP_Error($code, $message, ['status' => $status]);
}

function pagenest_pc_text($input, $limit, $label)
{
    if (!is_string($input)) {
        return pagenest_pc_error('invalid_text', $label . '格式不正确。');
    }
    $text = sanitize_textarea_field($input);
    if (trim($text) === '' || mb_strlen($text) > $limit) {
        return pagenest_pc_error('invalid_text', $label . '须为 1–' . $limit . ' 字。');
    }
    return $text;
}

function pagenest_pc_literal_quote($input)
{
    // This is a plain-text historical snapshot, not HTML. Sanitizing tags or percent
    // escapes here would corrupt code/URLs on a JSON export/import round trip.
    // Generated/legacy quotes have no character cap; import must not truncate or reject
    // a snapshot solely because of its length (including a legacy empty snapshot).
    if (!is_string($input) || !mb_check_encoding($input, 'UTF-8')) {
        return pagenest_pc_error('invalid_quote', '原引用须为有效 UTF-8 纯文本。');
    }
    return $input;
}

// Serializes cooperating create/edit/delete requests without new tables or rewriting note IDs.
// Names include the database and table prefix so unrelated sites never share locks.
function pagenest_pc_locked($key, $callback)
{
    global $wpdb;
    $name =
        'pagenest-pc:' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $key), 0, 52);
    $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', $name));
    if ((string) $acquired !== '1') {
        return pagenest_pc_error('note_busy', '笔记正在保存，请稍后重试。', 503);
    }
    try {
        return $callback();
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }
}

function pagenest_pc_live_block($post_id, $block_id)
{
    $registry = get_post_meta(
        $post_id,
        pagenest_companion_config('paragraphs', 'registry_meta'),
        true,
    );
    $matches = array_values(
        array_filter(
            is_array($registry) ? $registry : [],
            fn($block) => !empty($block['active']) && $block['id'] === $block_id,
        ),
    );
    return count($matches) === 1 ? $matches[0] : null;
}

function pagenest_pc_quote($post_id, $block)
{
    // Preserve code line breaks for new quotes without changing the registry's identity algorithm.
    $html = pagenest_pc_blocks(get_post_field('post_content', $post_id, 'raw'), $post_id);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $old_errors = libxml_use_internal_errors(true);
    $dom->loadHTML(
        '<?xml encoding="UTF-8"><div>' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
    );
    libxml_clear_errors();
    libxml_use_internal_errors($old_errors);
    foreach ($dom->getElementsByTagName('pre') as $node) {
        if ($node->getAttribute('data-pagenest-block') === $block['id']) {
            return $node->textContent;
        }
    }
    return $block['text'];
}

function pagenest_pc_save($input, $import = false, $public_comment = false)
{
    if (!is_array($input)) {
        return pagenest_pc_error('invalid', '笔记格式错误。');
    }
    if (
        $public_comment &&
        ($import ||
            ($input['public_confirmed'] ?? null) !== true ||
            array_diff(array_keys($input), [
                'post_id',
                'block_id',
                'text',
                'uuid',
                'public_confirmed',
            ]))
    ) {
        return pagenest_pc_error('invalid_comment', '请明确发布公开评论。');
    }
    if (
        !$public_comment &&
        !$import &&
        array_key_exists('visibility', $input) &&
        $input['visibility'] !== 'author_only'
    ) {
        return pagenest_pc_error('invalid_visibility', '新笔记默认私人；保存后可单独确认公开。');
    }
    $post_id = absint($input['post_id'] ?? 0);
    if (!pagenest_pc_readable($post_id)) {
        return pagenest_pc_error('denied', '无法读取对应章节。', 403);
    }
    $block_id = $input['block_id'] ?? '';
    if (!is_string($block_id) || $block_id === '' || strlen($block_id) > 200) {
        return pagenest_pc_error('block_missing', '请选择有效段落。', 409);
    }
    $text = pagenest_pc_text($input['text'] ?? null, 10000, '笔记');
    if (is_wp_error($text)) {
        return $text;
    }
    if (isset($input['uuid']) && (!is_string($input['uuid']) || !wp_is_uuid($input['uuid']))) {
        return pagenest_pc_error('invalid_uuid', '笔记标识无效。');
    }
    $uuid = $input['uuid'] ?? wp_generate_uuid4();
    $fingerprint = hash('sha256', wp_json_encode([$post_id, $block_id, $text]));
    return pagenest_pc_locked('create:' . get_current_user_id() . ':' . $uuid, function () use (
        $input,
        $import,
        $public_comment,
        $post_id,
        $block_id,
        $text,
        $uuid,
        $fingerprint,
    ) {
        $existing = get_posts([
            'post_type' => pagenest_companion_config('paragraphs', 'post_type'),
            'post_status' => 'private',
            'author' => get_current_user_id(),
            'meta_key' => pagenest_companion_config('paragraphs', 'uuid_meta'),
            'meta_value' => $uuid,
            'posts_per_page' => 1,
        ]);
        if ($existing) {
            $stored = get_post_meta(
                $existing[0]->ID,
                pagenest_companion_config('paragraphs', 'data_meta'),
                true,
            );
            if (!is_array($stored) || (int) $stored['post_id'] !== $post_id) {
                return pagenest_pc_error('uuid_conflict', '此笔记标识已用于其他章节。', 409);
            }
            if (
                $public_comment &&
                (($stored['_entry_kind'] ?? '') !== 'comment' ||
                    pagenest_pc_visibility($stored) !== 'public')
            ) {
                return pagenest_pc_error(
                    'uuid_conflict',
                    '此提交标识不可用于发布评论，请重新核对。',
                    409,
                );
            }
            $original =
                $stored['_create_fingerprint'] ??
                hash(
                    'sha256',
                    wp_json_encode([
                        (int) $stored['post_id'],
                        $stored['block_id'],
                        $stored['text'],
                    ]),
                );
            if (!$import && !hash_equals($original, $fingerprint)) {
                return pagenest_pc_error(
                    'uuid_conflict',
                    '此提交已保存不同内容，请重新载入笔记。',
                    409,
                );
            }
            return pagenest_pc_data($existing[0]);
        }
        $block = pagenest_pc_live_block($post_id, $block_id);
        if (!$block && !$import) {
            return pagenest_pc_error(
                'block_missing',
                '段落已变化或无法唯一定位，请重新选择。',
                409,
            );
        }
        $quote = $block ? pagenest_pc_quote($post_id, $block) : '';
        if ($import) {
            $quote = pagenest_pc_literal_quote($input['quote'] ?? null);
            if (is_wp_error($quote)) {
                return $quote;
            }
        }
        $data = [
            'uuid' => $uuid,
            'post_id' => $post_id,
            'document_id' => get_post_meta(
                $post_id,
                pagenest_companion_config('paragraphs', 'document_meta'),
                true,
            ),
            'block_id' => $block_id,
            'quote' => $quote,
            'source_version' => hash(
                'sha256',
                get_post_field('post_content_filtered', $post_id, 'raw'),
            ),
            'text' => $text,
            'visibility' => $public_comment ? 'public' : 'author_only',
            'created_at' => gmdate('c'),
            '_revision' => wp_generate_uuid4(),
            '_create_fingerprint' => $fingerprint,
        ];
        if ($public_comment) {
            $data['_entry_kind'] = 'comment';
        }
        if ($import) {
            if (
                isset($input['created_at']) &&
                is_string($input['created_at']) &&
                strlen($input['created_at']) <= 64
            ) {
                $data['created_at'] = sanitize_text_field($input['created_at']);
            }
            if (
                isset($input['source_version']) &&
                is_string($input['source_version']) &&
                preg_match('/^[a-f0-9]{64}$/', $input['source_version'])
            ) {
                $data['source_version'] = $input['source_version'];
            }
            // Export/import retains the last explicitly replaced quote as well as the current snapshot.
            if (array_key_exists('previous_quote', $input)) {
                $previous = pagenest_pc_literal_quote($input['previous_quote']);
                if (is_wp_error($previous)) {
                    return $previous;
                }
                $data['previous_quote'] = $previous;
            }
            if (
                isset($input['previous_block_id']) &&
                is_string($input['previous_block_id']) &&
                strlen($input['previous_block_id']) <= 200
            ) {
                $data['previous_block_id'] = $input['previous_block_id'];
            }
        }
        $note_id = wp_insert_post(
            wp_slash([
                'post_type' => pagenest_companion_config('paragraphs', 'post_type'),
                'post_status' => 'private',
                'post_author' => get_current_user_id(),
                'post_title' => '私人笔记',
                'post_content' => '',
                'meta_input' => [
                    pagenest_companion_config('paragraphs', 'data_meta') => $data,
                    pagenest_companion_config('paragraphs', 'uuid_meta') => $uuid,
                ],
            ]),
            true,
        );
        if (is_wp_error($note_id)) {
            return $note_id;
        }
        if (
            get_post_meta($note_id, pagenest_companion_config('paragraphs', 'data_meta'), true) !==
                $data ||
            get_post_meta($note_id, pagenest_companion_config('paragraphs', 'uuid_meta'), true) !==
                $uuid
        ) {
            wp_delete_post($note_id, true);
            return pagenest_pc_error('save_failed', '保存失败，输入尚未丢失，请重试。', 500);
        }
        return pagenest_pc_data(get_post($note_id));
    });
}

function pagenest_pc_mutate($request, $delete = false, $public_only = false)
{
    $note_id = absint($request['id']);
    $input = $request->get_json_params();
    if (!is_array($input)) {
        return pagenest_pc_error('invalid', '请提供保存版本与修改内容。');
    }
    return pagenest_pc_locked('note:' . $note_id, function () use (
        $note_id,
        $input,
        $delete,
        $public_only,
    ) {
        clean_post_cache($note_id);
        $note = get_post($note_id);
        if (!pagenest_pc_own($note)) {
            return pagenest_pc_error('denied', '无权操作此笔记。', 403);
        }
        $old = get_post_meta($note_id, pagenest_companion_config('paragraphs', 'data_meta'), true);
        if (!is_array($old) || !pagenest_pc_readable($old['post_id'])) {
            return pagenest_pc_error('denied', '无权读取对应章节。', 403);
        }
        if (
            $public_only &&
            (pagenest_pc_visibility($old) !== 'public' ||
                array_diff(array_keys($input), $delete ? ['version'] : ['version', 'text']))
        ) {
            return pagenest_pc_error('denied', '此评论不可操作。', 403);
        }
        if (
            !isset($input['version']) ||
            !is_string($input['version']) ||
            !hash_equals(pagenest_pc_version($old), $input['version'])
        ) {
            return pagenest_pc_error(
                'version_conflict',
                '笔记已更新，请重新载入后核对；你的输入仍保留。',
                409,
            );
        }
        if ($delete) {
            if (!wp_delete_post($note_id, true)) {
                return pagenest_pc_error('delete_failed', '删除失败，请稍后重试。', 500);
            }
            return ['deleted' => true];
        }
        $editing = array_key_exists('text', $input);
        $relinking = array_key_exists('block_id', $input);
        $visibility = array_key_exists('visibility', $input);
        if (
            (int) $editing + (int) $relinking + (int) $visibility !== 1 ||
            array_diff(array_keys($input), ['text', 'block_id', 'visibility', 'version'])
        ) {
            return pagenest_pc_error('invalid', '修改正文、重新关联与可见性须分别保存。');
        }
        $data = $old;
        if ($visibility) {
            if (!in_array($input['visibility'], ['author_only', 'public'], true)) {
                return pagenest_pc_error('invalid_visibility', '请选择私人或公开。');
            }
            $data['visibility'] = $input['visibility'];
        } elseif ($editing) {
            $text = pagenest_pc_text($input['text'], 10000, '笔记');
            if (is_wp_error($text)) {
                return $text;
            }
            $data['text'] = $text;
        } else {
            if (!is_string($input['block_id'])) {
                return pagenest_pc_error('missing', '请选择有效段落。', 409);
            }
            $block = pagenest_pc_live_block($old['post_id'], $input['block_id']);
            if (!$block) {
                return pagenest_pc_error('missing', '段落已变化或无法唯一定位，请重新选择。', 409);
            }
            $data['previous_quote'] = $old['quote'];
            $data['previous_block_id'] = $old['block_id'];
            $data['block_id'] = $block['id'];
            $data['quote'] = pagenest_pc_quote($old['post_id'], $block);
            $data['source_version'] = hash(
                'sha256',
                get_post_field('post_content_filtered', $old['post_id'], 'raw'),
            );
        }
        $data['updated_at'] = gmdate('c');
        $data['_revision'] = wp_generate_uuid4();
        if (
            !update_post_meta(
                $note_id,
                pagenest_companion_config('paragraphs', 'data_meta'),
                wp_slash($data),
                $old,
            )
        ) {
            return pagenest_pc_error(
                'save_conflict',
                '保存失败或笔记已变化，请重新载入核对。',
                409,
            );
        }
        return pagenest_pc_data(get_post($note_id));
    });
}

function pagenest_pc_avatar_url($user_id)
{
    if (!$user_id || !get_option('show_avatars')) {
        return '';
    }
    // Keep the same avatar filters as the account menu; only project the image URL.
    $html = get_avatar((int) $user_id, 62, '', '', ['loading' => 'lazy']);
    if (!is_string($html) || $html === '') {
        return '';
    }
    if (!class_exists('WP_HTML_Tag_Processor')) {
        return '';
    }
    $tags = new WP_HTML_Tag_Processor($html);
    if (!$tags->next_tag('IMG')) {
        return '';
    }
    $src = $tags->get_attribute('src');
    if (!is_string($src)) {
        return '';
    }
    $src = trim($src);
    $absolute = str_starts_with($src, '//') ? 'https:' . $src : $src;
    $parts = wp_parse_url($absolute);
    if (
        !is_array($parts) ||
        empty($parts['host']) ||
        !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
    ) {
        return '';
    }
    return esc_url_raw($src, ['http', 'https']);
}

function pagenest_pc_comment_projection($data, $note)
{
    $author = get_userdata($note->post_author);
    $out = [
        'id' => $note->ID,
        'post_id' => (int) $data['post_id'],
        'block_id' => $data['block_id'],
        'quote' => $data['quote'],
        'text' => $data['text'],
        'association' => $data['association'],
        'author_name' => $author ? $author->display_name : '读者',
        'author_avatar_url' => $author ? pagenest_pc_avatar_url($author->ID) : '',
        'created_at' => $data['created_at'] ?? '',
        'can_edit' => pagenest_pc_own($note),
    ];
    if ($out['can_edit']) {
        $out['version'] = $data['version'];
    }
    return $out;
}

function pagenest_pc_register_routes($namespace)
{
    register_rest_route($namespace, '/comments', [
        [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => function ($request) {
                $post_id = absint($request['post']);
                if (!pagenest_pc_readable($post_id)) {
                    return pagenest_pc_error('denied', '无权读取对应章节。', 403);
                }
                $out = [];
                foreach (
                    get_posts([
                        'post_type' => pagenest_companion_config('paragraphs', 'post_type'),
                        'post_status' => 'private',
                        'posts_per_page' => -1,
                        'orderby' => 'ID',
                        'order' => 'ASC',
                    ])
                    as $note
                ) {
                    $data = pagenest_pc_data($note);
                    if (
                        !is_wp_error($data) &&
                        (int) $data['post_id'] === $post_id &&
                        $data['visibility'] === 'public'
                    ) {
                        $out[] = pagenest_pc_comment_projection($data, $note);
                    }
                }
                return $out;
            },
        ],
        [
            'methods' => 'POST',
            'permission_callback' => 'pagenest_pc_permission',
            'callback' => function ($request) {
                $data = pagenest_pc_save($request->get_json_params(), false, true);
                return is_wp_error($data)
                    ? $data
                    : pagenest_pc_comment_projection($data, get_post($data['id']));
            },
        ],
    ]);
    register_rest_route($namespace, '/comments/(?P<id>\d+)', [
        [
            'methods' => 'PATCH',
            'permission_callback' => 'pagenest_pc_permission',
            'callback' => function ($request) {
                $data = pagenest_pc_mutate($request, false, true);
                return is_wp_error($data)
                    ? $data
                    : pagenest_pc_comment_projection($data, get_post($data['id']));
            },
        ],
        [
            'methods' => 'DELETE',
            'permission_callback' => 'pagenest_pc_permission',
            'callback' => fn($request) => pagenest_pc_mutate($request, true, true),
        ],
    ]);
    register_rest_route($namespace, '/public-notes', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function ($request) {
            $post_id = absint($request['post']);
            if (!pagenest_pc_readable($post_id)) {
                return pagenest_pc_error('denied', '无权读取对应章节。', 403);
            }
            $out = [];
            foreach (
                get_posts([
                    'post_type' => pagenest_companion_config('paragraphs', 'post_type'),
                    'post_status' => 'private',
                    'posts_per_page' => -1,
                ])
                as $note
            ) {
                $data = pagenest_pc_data($note);
                if (
                    is_wp_error($data) ||
                    (int) $data['post_id'] !== $post_id ||
                    $data['visibility'] !== 'public'
                ) {
                    continue;
                }
                // Explicit projection: never expose tokens, identifiers for import, or quote history.
                $author = get_userdata($note->post_author);
                $out[] = [
                    'id' => $data['id'],
                    'post_id' => (int) $data['post_id'],
                    'block_id' => $data['block_id'],
                    'quote' => $data['quote'],
                    'text' => $data['text'],
                    'association' => $data['association'],
                    'author_name' => $author ? $author->display_name : '用户',
                ];
            }
            return $out;
        },
    ]);
    register_rest_route($namespace, '/notes', [
        [
            'methods' => 'GET',
            'permission_callback' => 'pagenest_pc_permission',
            'callback' => function ($request) {
                $post_id = absint($request['post']);
                if (!pagenest_pc_readable($post_id)) {
                    return pagenest_pc_error('denied', '无权读取对应章节。', 403);
                }
                $out = [];
                foreach (
                    get_posts([
                        'post_type' => pagenest_companion_config('paragraphs', 'post_type'),
                        'post_status' => 'private',
                        'author' => get_current_user_id(),
                        'posts_per_page' => -1,
                    ])
                    as $note
                ) {
                    $data = pagenest_pc_data($note);
                    if (!is_wp_error($data) && (int) $data['post_id'] === $post_id) {
                        $out[] = $data;
                    }
                }
                return $out;
            },
        ],
        [
            'methods' => 'POST',
            'permission_callback' => 'pagenest_pc_permission',
            'callback' => fn($request) => pagenest_pc_save($request->get_json_params()),
        ],
    ]);
    register_rest_route($namespace, '/notes/(?P<id>\d+)', [
        [
            'methods' => 'PATCH',
            'permission_callback' => 'pagenest_pc_permission',
            'callback' => fn($request) => pagenest_pc_mutate($request),
        ],
        [
            'methods' => 'DELETE',
            'permission_callback' => 'pagenest_pc_permission',
            'callback' => fn($request) => pagenest_pc_mutate($request, true),
        ],
    ]);
    register_rest_route($namespace, '/export', [
        'methods' => 'GET',
        'permission_callback' => 'pagenest_pc_permission',
        'callback' => function () {
            $out = [];
            foreach (
                get_posts([
                    'post_type' => pagenest_companion_config('paragraphs', 'post_type'),
                    'post_status' => 'private',
                    'author' => get_current_user_id(),
                    'posts_per_page' => -1,
                ])
                as $note
            ) {
                $data = pagenest_pc_data($note);
                if (!is_wp_error($data) && pagenest_pc_readable($data['post_id'])) {
                    $out[] = $data;
                }
            }
            return ['schema' => 1, 'exported_at' => gmdate('c'), 'notes' => $out];
        },
    ]);
    register_rest_route($namespace, '/import', [
        'methods' => 'POST',
        'permission_callback' => 'pagenest_pc_permission',
        'callback' => function ($request) {
            $data = $request->get_json_params();
            if (
                !is_array($data) ||
                ($data['schema'] ?? null) !== 1 ||
                !is_array($data['notes'] ?? null) ||
                count($data['notes']) > 500
            ) {
                return pagenest_pc_error('invalid', '导入格式错误或超过 500 条。');
            }
            $out = [];
            foreach ($data['notes'] as $input) {
                if (
                    !is_array($input) ||
                    !isset($input['document_id']) ||
                    !is_string($input['document_id'])
                ) {
                    $out[] = ['error' => '章节标识格式错误'];
                    continue;
                }
                $posts = get_posts([
                    'post_type' => 'post',
                    'post_status' => ['private', 'publish', 'draft'],
                    'meta_key' => pagenest_companion_config('paragraphs', 'document_meta'),
                    'meta_value' => sanitize_text_field($input['document_id']),
                    'posts_per_page' => 2,
                ]);
                if (count($posts) !== 1) {
                    $out[] = ['error' => '章节不存在或不唯一'];
                    continue;
                }
                $input['post_id'] = $posts[0]->ID;
                $result = pagenest_pc_save($input, true);
                $out[] = is_wp_error($result) ? ['error' => $result->get_error_message()] : $result;
            }
            return $out;
        },
    ]);
}
add_action('rest_api_init', static function () {
    foreach (
        array_unique(
            array_merge(
                [pagenest_companion_config('paragraphs', 'rest_namespace')],
                pagenest_companion_config('paragraphs', 'rest_aliases'),
            ),
        )
        as $namespace
    ) {
        pagenest_pc_register_routes($namespace);
    }
});

function pagenest_pc_route_matches($route)
{
    foreach (
        array_merge(
            [pagenest_companion_config('paragraphs', 'rest_namespace')],
            pagenest_companion_config('paragraphs', 'rest_aliases'),
        )
        as $namespace
    ) {
        if (str_starts_with($route, '/' . $namespace . '/')) {
            return true;
        }
    }
    return false;
}
add_filter(
    'rest_post_dispatch',
    function ($response, $server, $request) {
        if (pagenest_pc_route_matches($request->get_route())) {
            $response->header('Cache-Control', 'private, no-store, max-age=0');
            $response->header('Vary', 'Cookie');
        }
        return $response;
    },
    10,
    3,
);
add_action('template_redirect', function () {
    if (
        is_singular() &&
        (pagenest_pc_enabled(get_queried_object_id()) ||
            get_post_meta(
                get_queried_object_id(),
                pagenest_companion_config('paragraphs', 'library_meta'),
                true,
            ))
    ) {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
    }
});
add_action(
    'wp_enqueue_scripts',
    function () {
        $id = get_queried_object_id();
        if (!is_singular('post') || !pagenest_pc_readable($id)) {
            return;
        }
        $assets = require dirname(__DIR__) . '/assets/manifest.php';
        $script = $assets['comments_js'];
        $style = $assets['comments_css'];
        wp_enqueue_script(
            'pagenest-paragraph-comments',
            plugins_url('../assets/' . $script, __FILE__),
            [],
            null,
            true,
        );
        wp_enqueue_style(
            'pagenest-paragraph-comments',
            plugins_url('../assets/' . $style, __FILE__),
            [],
            null,
        );
        wp_localize_script('pagenest-paragraph-comments', 'PageNestComments', [
            'post' => $id,
            'api' => rest_url(pagenest_companion_config('paragraphs', 'rest_namespace') . '/'),
            'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'user' => get_current_user_id(),
            'loginUrl' => wp_login_url(get_permalink($id)),
            'displayName' => is_user_logged_in() ? wp_get_current_user()->display_name : '',
            'avatarUrl' => is_user_logged_in() ? pagenest_pc_avatar_url(get_current_user_id()) : '',
        ]);
    },
    20,
);

function pagenest_pc_library()
{
    $posts = get_posts([
        'post_type' => 'post',
        'post_status' => ['private', 'publish'],
        'meta_key' => pagenest_companion_config('paragraphs', 'document_meta'),
        'posts_per_page' => 100,
    ]);
    usort(
        $posts,
        fn($a, $b) => strcmp(
            get_post_meta($a->ID, pagenest_companion_config('paragraphs', 'document_meta'), true),
            get_post_meta($b->ID, pagenest_companion_config('paragraphs', 'document_meta'), true),
        ),
    );
    $out = '<ol class="pagenest-library">';
    foreach ($posts as $p) {
        if (!pagenest_pc_readable($p->ID)) {
            continue;
        }
        $out .=
            '<li><a href="' .
            esc_url(get_permalink($p)) .
            '">' .
            esc_html($p->post_title) .
            '</a></li>';
    }
    return $out . '</ol>';
}
foreach (pagenest_companion_config('paragraphs', 'shortcodes') as $shortcode) {
    add_shortcode($shortcode, 'pagenest_pc_library');
}
