<?php
/** Bounded chapter maintenance. Directory bindings never define chapter membership. */
defined('ABSPATH') || exit();

function pagenest_companion_series_candidates(): array
{
    if (!pagenest_companion_series_meta_budget()) {
        return [];
    }
    $config = pagenest_companion_config('chapters');
    return get_posts([
        'post_type' => 'post',
        'post_status' => [
            'publish',
            'private',
            'draft',
            'pending',
            'future',
            'auto-draft',
            'trash',
        ],
        'posts_per_page' => 501,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_query' => [
            'relation' => 'OR',
            ['key' => '_pagenest_series', 'compare' => 'EXISTS'],
            ['key' => $config['series_meta'], 'compare' => 'EXISTS'],
        ],
        'orderby' => 'meta_value',
        'order' => 'ASC',
    ]);
}

function pagenest_companion_series_meta_budget(): bool
{
    global $wpdb;
    $config = pagenest_companion_config('chapters');
    $keys = array_values(
        array_unique([
            '_pagenest_series',
            '_pagenest_chapter_order',
            $config['series_meta'],
            $config['order_meta'],
            $config['document_meta'],
        ]),
    );
    $placeholders = implode(',', array_fill(0, count($keys), '%s'));
    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders) LIMIT 2501",
            ...$keys,
        ),
    );
    return !$wpdb->last_error && count($rows) <= 2500;
}

function pagenest_companion_series_snapshot(string $series): array
{
    $config = pagenest_companion_config('chapters');
    $within_budget = pagenest_companion_series_meta_budget();
    $candidates = $within_budget ? pagenest_companion_series_candidates() : [];
    $complete = $within_budget && count($candidates) <= 500;
    $members = [];
    $state = [];
    $restricted = false;
    $managed = false;
    foreach (array_slice($candidates, 0, 500) as $post) {
        if (
            !in_array($post->post_status, ['publish', 'private'], true) ||
            pagenest_companion_post_setting($post->ID, 'series') !== $series ||
            $series === ''
        ) {
            continue;
        }
        // Record membership and raw old values, including hidden members, without exposing titles.
        $document = get_post_meta($post->ID, $config['document_meta'], true);
        $state[$post->ID] = [
            $post->post_status,
            $post->post_password,
            get_post_meta($post->ID, '_pagenest_series', false),
            get_post_meta($post->ID, $config['series_meta'], false),
            get_post_meta($post->ID, '_pagenest_chapter_order', false),
            get_post_meta($post->ID, $config['order_meta'], false),
            $document,
        ];
        if (in_array($document, $config['series_order'], true)) {
            $managed = true;
        }
        if (
            $post->post_password !== '' ||
            !pagenest_companion_can_read($post) ||
            !current_user_can('edit_post', $post->ID)
        ) {
            $restricted = true;
            continue;
        }
        $members[] = $post;
    }
    if (count($state) > 100) {
        $complete = false;
    }
    $members = pagenest_companion_sort_series(array_slice($members, 0, 100));
    ksort($state);
    return [
        'members' => $members,
        'complete' => $complete,
        'restricted' => $restricted,
        'managed' => $managed,
        'editable' => $complete && !$restricted && !$managed && count($members) > 0,
        'revision' => hash('sha256', serialize([$series, $config, $state, $complete, $restricted])),
    ];
}

/** Validate a complete order proposal; no persistence, sanitization or identity guessing. */
function pagenest_companion_series_proposal(array $snapshot, $input)
{
    if (
        !$snapshot['editable'] ||
        !is_array($input) ||
        count($input) !== count($snapshot['members'])
    ) {
        return new WP_Error(
            'series-incomplete',
            __('This series cannot be reordered here.', 'pagenest-companion'),
        );
    }
    $orders = [];
    foreach ($snapshot['members'] as $post) {
        $value = $input[$post->ID] ?? null;
        if (
            !is_string($value) ||
            !preg_match('/^(0|[1-9][0-9]{0,6})$/D', $value) ||
            (int) $value > 1000000
        ) {
            return new WP_Error(
                'series-order',
                __(
                    'Enter a whole chapter order from 0 to 1000000 for every chapter.',
                    'pagenest-companion',
                ),
            );
        }
        $orders[$post->ID] = $value;
    }
    return $orders;
}

function pagenest_companion_series_preview_token(
    string $series,
    string $revision,
    array $orders,
): string {
    ksort($orders);
    return hash_hmac('sha256', serialize([$series, $revision, $orders]), wp_salt('nonce'));
}

/** All writes are WordPress neutral metadata APIs inside one InnoDB transaction. */
function pagenest_companion_series_save(string $series, string $revision, array $orders)
{
    global $wpdb;
    if (!current_user_can('manage_options')) {
        return new WP_Error(
            'series-permission',
            __('You cannot maintain this series.', 'pagenest-companion'),
        );
    }
    $failure = new WP_Error(
        'series-save',
        __(
            'Chapter orders were not saved. Refresh the series and try again.',
            'pagenest-companion',
        ),
    );
    foreach ([$wpdb->posts, $wpdb->postmeta] as $table) {
        $engine = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table,
            ),
        );
        if (strtoupper((string) $engine) !== 'INNODB') {
            return new WP_Error(
                'series-storage',
                __(
                    'Atomic chapter saving requires InnoDB tables. No orders were saved.',
                    'pagenest-companion',
                ),
            );
        }
    }
    if (
        $wpdb->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE') === false ||
        $wpdb->query('START TRANSACTION') === false
    ) {
        return $failure;
    }
    $ids = [];
    try {
        // Lock the bounded candidate domain, including gaps, before reading its metadata.
        // Key-range locks prevent new memberships/overrides while the snapshot is checked.
        $config = pagenest_companion_config('chapters');
        $keys = array_values(
            array_unique([
                '_pagenest_series',
                '_pagenest_chapter_order',
                $config['series_meta'],
                $config['order_meta'],
                $config['document_meta'],
            ]),
        );
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $locked = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders) LIMIT 2501 FOR UPDATE",
                ...$keys,
            ),
        );
        if ($wpdb->last_error || count($locked) > 2500) {
            throw new RuntimeException('lock-budget');
        }
        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = {$wpdb->posts}.ID AND meta_key IN (%s, %s)) ORDER BY ID LIMIT 501 FOR UPDATE",
                '_pagenest_series',
                $config['series_meta'],
            ),
        );
        if ($wpdb->last_error || count($post_ids) > 500) {
            throw new RuntimeException('lock-budget');
        }
        foreach ($post_ids as $id) {
            clean_post_cache((int) $id);
        }
        $fresh = pagenest_companion_series_snapshot($series);
        if (!hash_equals($fresh['revision'], $revision)) {
            throw new RuntimeException('conflict');
        }
        $validated = pagenest_companion_series_proposal($fresh, $orders);
        if (is_wp_error($validated)) {
            throw new RuntimeException('permission-or-membership');
        }
        foreach ($fresh['members'] as $post) {
            $id = (int) $post->ID;
            $ids[] = $id;
            if (!current_user_can('edit_post', $id)) {
                throw new RuntimeException('permission');
            }
            $old = get_post_meta($id, '_pagenest_chapter_order', true);
            if (metadata_exists('post', $id, '_pagenest_chapter_order') && $old === $orders[$id]) {
                continue;
            }
            if (!update_post_meta($id, '_pagenest_chapter_order', $orders[$id])) {
                throw new RuntimeException('write');
            }
        }
        if ($wpdb->query('COMMIT') === false) {
            throw new RuntimeException('commit');
        }
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        foreach ($ids as $id) {
            clean_post_cache($id);
        }
        if ($error->getMessage() === 'lock-budget') {
            return new WP_Error(
                'series-budget',
                __(
                    'The atomic save exceeded its locking budget. No chapter orders were saved.',
                    'pagenest-companion',
                ),
            );
        }
        if ($error->getMessage() === 'conflict') {
            return new WP_Error(
                'series-conflict',
                __(
                    'This series changed after it was loaded. Refresh it before saving again.',
                    'pagenest-companion',
                ),
            );
        }
        return $failure;
    }
    foreach ($ids as $id) {
        clean_post_cache($id);
    }
    return true;
}

/** Called for both preview and save. The settings API form has no role in this endpoint. */
function pagenest_companion_series_request(array $input)
{
    $series = $input['pn_series'] ?? null;
    $revision = $input['pn_series_revision'] ?? null;
    $nonce = $input['pn_series_nonce'] ?? null;
    $action = $input['pn_series_action'] ?? null;
    if (
        !current_user_can('manage_options') ||
        !is_string($series) ||
        $series === '' ||
        mb_strlen($series) > 100 ||
        !is_string($revision) ||
        !is_string($nonce) ||
        !wp_verify_nonce($nonce, 'pagenest-series-maintenance') ||
        !in_array($action, ['preview', 'save'], true)
    ) {
        return new WP_Error(
            'series-request',
            __('Series request rejected. Refresh the page and try again.', 'pagenest-companion'),
        );
    }
    $snapshot = pagenest_companion_series_snapshot($series);
    if (!hash_equals($snapshot['revision'], $revision)) {
        return new WP_Error(
            'series-conflict',
            __(
                'This series changed after it was loaded. Refresh it before saving again.',
                'pagenest-companion',
            ),
        );
    }
    $orders = pagenest_companion_series_proposal($snapshot, $input['pn_series_orders'] ?? null);
    if (is_wp_error($orders)) {
        return $orders;
    }
    if ($action === 'save') {
        $token = $input['pn_series_preview'] ?? null;
        if (
            !is_string($token) ||
            !hash_equals(
                pagenest_companion_series_preview_token($series, $revision, $orders),
                $token,
            )
        ) {
            return new WP_Error(
                'series-preview',
                __('Preview these chapter orders before saving.', 'pagenest-companion'),
            );
        }
        $saved = pagenest_companion_series_save($series, $revision, $orders);
        if (is_wp_error($saved)) {
            return $saved;
        }
    }
    return ['orders' => $orders, 'saved' => $action === 'save'];
}

function pagenest_companion_series_maintenance(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $request = isset($_POST['pn_series_action']) ? wp_unslash($_POST) : [];
    $chosen = $request['pn_series'] ?? ($_GET['pn_series'] ?? '');
    $series =
        is_string($chosen) && mb_strlen($chosen) <= 100
            ? ($request
                ? $chosen
                : wp_unslash($chosen))
            : '';
    $result = $request ? pagenest_companion_series_request($request) : null;
    echo '<section class="pn-admin-section pn-series-maintenance" id="pn-series-maintenance" data-pn-series-maintenance><h2>' .
        esc_html__('Series chapters', 'pagenest-companion') .
        '</h2><p>' .
        esc_html__(
            'Choose an article series to inspect its navigation order. Directory-only document bindings do not define chapter membership. Saving here changes chapter order only.',
            'pagenest-companion',
        ) .
        '</p>';
    if (!pagenest_companion_series_meta_budget()) {
        echo '<p role="status">' .
            esc_html__(
                'The related chapter metadata exceeds this view’s 2500-row budget. Chapter lookup is unavailable and no orders can be saved.',
                'pagenest-companion',
            ) .
            '</p></section>';
        return;
    }
    $names = [];
    foreach (pagenest_companion_series_candidates() as $post) {
        if (
            $post->post_password !== '' ||
            !pagenest_companion_can_read($post) ||
            !current_user_can('edit_post', $post->ID)
        ) {
            continue;
        }
        $name = pagenest_companion_post_setting($post->ID, 'series');
        if (is_string($name) && $name !== '' && mb_strlen($name) <= 100) {
            $names[$name] = true;
        }
    }
    if ($series !== '') {
        $names[$series] = true;
    }
    ksort($names);
    echo '<form method="get" action="' .
        esc_url(admin_url('options-general.php#pn-series-maintenance')) .
        '"><input type="hidden" name="page" value="pagenest-companion"><label>' .
        esc_html__('Article series', 'pagenest-companion') .
        '<select name="pn_series"><option value="">' .
        esc_html__('Choose a series…', 'pagenest-companion') .
        '</option>';
    foreach ($names as $name => $_) {
        echo '<option value="' .
            esc_attr((string) $name) .
            '"' .
            ((string) $name === $series ? ' selected' : '') .
            '>' .
            esc_html((string) $name) .
            '</option>';
    }
    echo '</select></label><button class="button" type="submit">' .
        esc_html__('View chapters', 'pagenest-companion') .
        '</button></form>';
    echo '<p class="pn-admin-description">' .
        esc_html__(
            'This view scans up to 500 candidate articles, lists up to 100 chapters, and allows up to 2500 rows for the related chapter metadata keys. A partial or restricted series is read-only.',
            'pagenest-companion',
        ) .
        '</p>';
    if ($result !== null) {
        echo '<p role="status" tabindex="-1" data-pn-series-result class="pn-series-result">' .
            esc_html(
                is_wp_error($result)
                    ? $result->get_error_message()
                    : ($result['saved']
                        ? __('Chapter orders saved.', 'pagenest-companion')
                        : __(
                            'Preview ready. Review the resulting order, then save.',
                            'pagenest-companion',
                        )),
            ) .
            '</p>';
    }
    if ($series === '') {
        echo '</section>';
        return;
    }
    $snapshot = pagenest_companion_series_snapshot($series);
    if (!$snapshot['complete'] || $snapshot['restricted']) {
        echo '<p role="status">' .
            esc_html__(
                'The complete editable series is unavailable within this view. Only accessible chapters are shown; their positions are relative to this subset. No orders can be saved.',
                'pagenest-companion',
            ) .
            '</p>';
    }
    if ($snapshot['managed']) {
        echo '<p role="status">' .
            esc_html__(
                'Server document order takes priority for this series. This view is read-only.',
                'pagenest-companion',
            ) .
            '</p>';
    }
    if (!$snapshot['members']) {
        echo '<p>' .
            esc_html__(
                'No editable chapters found for this exact article series.',
                'pagenest-companion',
            ) .
            '</p></section>';
        return;
    }
    $proposal = is_array($result) && !$result['saved'] ? $result['orders'] : [];
    $draft = $proposal;
    if (is_wp_error($result) && is_array($request['pn_series_orders'] ?? null)) {
        foreach ($snapshot['members'] as $member) {
            $value = $request['pn_series_orders'][$member->ID] ?? null;
            if (is_string($value) && strlen($value) <= 30) {
                $draft[$member->ID] = $value;
            }
        }
    }
    $members = $proposal
        ? pagenest_companion_sort_series($snapshot['members'], $proposal)
        : $snapshot['members'];
    $counts = [];
    foreach ($members as $post) {
        $value = pagenest_companion_post_setting($post->ID, 'order');
        if (is_numeric($value)) {
            $counts[(string) (float) $value] = ($counts[(string) (float) $value] ?? 0) + 1;
        }
    }
    echo '<form method="post" action="' .
        esc_url(admin_url('options-general.php?page=pagenest-companion#pn-series-maintenance')) .
        '" data-pn-series-form><input type="hidden" name="pn_series" value="' .
        esc_attr($series) .
        '"><input type="hidden" name="pn_series_revision" value="' .
        esc_attr($snapshot['revision']) .
        '"><input type="hidden" name="pn_series_nonce" value="' .
        esc_attr(wp_create_nonce('pagenest-series-maintenance')) .
        '">';
    if ($proposal) {
        echo '<input type="hidden" name="pn_series_preview" value="' .
            esc_attr(
                pagenest_companion_series_preview_token($series, $snapshot['revision'], $proposal),
            ) .
            '">';
    }
    echo '<ol class="pn-series-chapters" data-pn-series-chapters>';
    foreach ($members as $index => $post) {
        $value = pagenest_companion_post_setting($post->ID, 'order');
        $warning = !is_numeric($value)
            ? __('Missing order', 'pagenest-companion')
            : (($counts[(string) (float) $value] ?? 0) > 1
                ? __('Duplicate order', 'pagenest-companion')
                : '');
        echo '<li data-pn-series-chapter><div><span class="pn-series-position">' .
            esc_html((string) ($index + 1)) .
            '.</span> <a href="' .
            esc_url(get_permalink($post)) .
            '">' .
            esc_html($post->post_title) .
            '</a> <span class="pn-admin-description">#' .
            esc_html((string) $post->ID) .
            '</span>';
        echo '<p class="pn-admin-description">' .
            esc_html__('Saved order:', 'pagenest-companion') .
            ' ' .
            esc_html((string) $value) .
            ($warning ? ' · ' . esc_html($warning) : '') .
            '</p></div>';
        echo '<div class="pn-series-controls"><label>' .
            esc_html__('Chapter order', 'pagenest-companion') .
            '<input type="number" min="0" max="1000000" step="1" name="pn_series_orders[' .
            esc_attr((string) $post->ID) .
            ']" value="' .
            esc_attr($draft[$post->ID] ?? (string) $value) .
            '"' .
            (!$snapshot['editable'] ? ' disabled' : '') .
            '></label>';
        if ($snapshot['editable']) {
            echo '<button type="button" class="button" data-pn-series-move="-1" hidden>' .
                esc_html__('Move up', 'pagenest-companion') .
                '</button><button type="button" class="button" data-pn-series-move="1" hidden>' .
                esc_html__('Move down', 'pagenest-companion') .
                '</button>';
        }
        echo '</div></li>';
    }
    echo '</ol>';
    if ($snapshot['editable']) {
        echo '<p class="pn-admin-description">' .
            esc_html__(
                'Enter numeric orders, or move chapters with the buttons (keyboard accessible). Moving chapters numbers the entire displayed series from 1. Preview uses the same rules as reader navigation; duplicate orders fall back to document ID and article ID.',
                'pagenest-companion',
            ) .
            '</p><p data-pn-savebar><button class="button" type="submit" name="pn_series_action" value="preview">' .
            esc_html__('Preview chapter order', 'pagenest-companion') .
            '</button> ';
        if ($proposal) {
            echo '<button class="button button-primary" type="submit" name="pn_series_action" value="save">' .
                esc_html__('Save chapter orders', 'pagenest-companion') .
                '</button>';
        }
        echo '</p><p role="status" data-pn-series-state></p>';
    }
    echo '</form></section>';
}
