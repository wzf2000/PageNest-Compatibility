<?php
namespace PageNest\Performance;
defined('ABSPATH') || exit();
require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/reports.php';
function enabled(): bool
{
    return defined('PAGENEST_COMPANION_PERFORMANCE_ENABLED') &&
        PAGENEST_COMPANION_PERFORMANCE_ENABLED === true &&
        defined('PAGENEST_COMPANION_PERFORMANCE_DIR') &&
        is_string(PAGENEST_COMPANION_PERFORMANCE_DIR);
}
function queue(): Queue
{
    if (!enabled()) {
        throw new \RuntimeException('disabled');
    }
    return new Queue(Queue::directory(PAGENEST_COMPANION_PERFORMANCE_DIR));
}
function no_store(): void
{
    nocache_headers();
    if (!headers_sent()) {
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
    }
}
function authorize(): void
{
    no_store();
    if (
        !current_user_can('manage_options') ||
        ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
        !is_string($_POST['_wpnonce'] ?? null) ||
        !wp_verify_nonce(wp_unslash($_POST['_wpnonce']), 'pagenest_performance')
    ) {
        wp_die('页面测速请求未通过权限验证。', '', ['response' => 403]);
    }
}
function handle(): void
{
    authorize();
    $notice = 'saved';
    try {
        $q = queue();
        $action = $_POST['operation'] ?? '';
        if ($action === 'export') {
            $data = export_data($q, is_string($_POST['job'] ?? null) ? $_POST['job'] : '');
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="job-' . $data['id'] . '.json"');
            echo json_encode(
                $data,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            exit();
        } elseif ($action === 'cancel') {
            $q->cancel(is_string($_POST['job'] ?? null) ? $_POST['job'] : '');
            $notice = 'cancel_requested';
        } else {
            $keys = $_POST['pages'] ?? [];
            if (!is_array($keys)) {
                throw new \RuntimeException('invalid_selection');
            }
            $extra = $_POST['post_ids'] ?? '';
            if (!is_string($extra) || strlen($extra) > 200) {
                throw new \RuntimeException('invalid_selection');
            }
            foreach (preg_split('/[\s,，]+/u', trim($extra), -1, PREG_SPLIT_NO_EMPTY) as $id) {
                if (!preg_match('/^[1-9][0-9]*$/D', $id)) {
                    throw new \RuntimeException('invalid_selection');
                }
                $keys[] = 'post:' . $id;
            }
            $pages = Pages::selection($keys);
            $keys = array_column($pages, 'key');
            if ($action === 'save') {
                update_option('pagenest_companion_performance_selection', $keys, false);
            } elseif ($action === 'start') {
                $q->start(
                    $keys,
                    is_string($_POST['device'] ?? null) ? $_POST['device'] : '',
                    (int) ($_POST['repeats'] ?? 0),
                    is_string($_POST['cache_mode'] ?? null)
                        ? $_POST['cache_mode']
                        : 'fresh-context',
                );
                $notice = 'queued';
            } else {
                throw new \RuntimeException('invalid_action');
            }
        }
    } catch (\Throwable $e) {
        $notice =
            $e->getMessage() === 'budget_cleanup_required'
                ? 'budget_cleanup_required'
                : 'request_rejected';
    }
    wp_safe_redirect(
        add_query_arg(
            ['page' => 'pagenest-performance', 'performance_notice' => $notice],
            admin_url('tools.php'),
        ),
    );
    exit();
}
function find_report(Queue $q, string $id): array
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
        throw new \RuntimeException('invalid_job');
    }
    foreach ($q->reports() as $j) {
        if (
            $j['id'] === $id &&
            in_array($j['status'], ['completed', 'failed', 'cancelled'], true)
        ) {
            return $j;
        }
    }
    throw new \RuntimeException('invalid_job');
}
function export_data(Queue $q, string $id): array
{
    authorize();
    if (!enabled()) {
        throw new \RuntimeException('disabled');
    }
    return Reports::export(find_report($q, $id));
}
function form_start(bool $comparison = false): void
{
    echo '<form method="post" action="' .
        esc_url(admin_url($comparison ? 'tools.php?page=pagenest-performance' : 'admin-post.php')) .
        '"><input type="hidden" name="action" value="pagenest_performance">';
    wp_nonce_field('pagenest_performance');
}
function screen(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('没有页面测速权限。', '', ['response' => 403]);
    }
    no_store();
    echo '<div class="wrap"><h1>页面测速</h1><p>默认关闭，管理员专用。仅有限公开代表样本，不代表全站。独立执行器需要由服务器管理员手动运行；本工具不会启动命令或定时任务。</p>';
    if (!enabled()) {
        echo '<p>尚未启用。服务器需要显式设置 PAGENEST_COMPANION_PERFORMANCE_ENABLED=true 和 Web 根之外已存在的私有 PAGENEST_COMPANION_PERFORMANCE_DIR。</p></div>';
        return;
    }
    try {
        $q = queue();
        $reports = $q->reports();
        $suggested = Pages::suggested();
    } catch (\Throwable $e) {
        echo '<p>配置或队列不可用。请服务器管理员检查私有目录和报告协议。</p></div>';
        return;
    }
    $notices = [
        'saved' => '固定选择已保存。',
        'queued' => '任务已排队，等待独立执行器。',
        'cancel_requested' => '取消请求已保存，执行器会检查并中止测量。',
        'request_rejected' => '请求被拒绝：请检查公开页面、配置、任务状态与执行器锁。',
        'budget_cleanup_required' =>
            '报告达到保留预算；请服务器管理员在私有目录归档或清理报告后再试。',
    ];
    $notice = $_GET['performance_notice'] ?? '';
    if (is_string($notice) && isset($notices[$notice])) {
        echo '<p>' . esc_html($notices[$notice]) . '</p>';
    }
    $saved = get_option('pagenest_companion_performance_selection', []);
    $saved = is_array($saved) ? array_values(array_filter($saved, 'is_string')) : [];
    $options = [];
    foreach ($suggested as $p) {
        $options[$p['key']] = $p;
    }
    foreach (array_slice($saved, 0, 8) as $key) {
        try {
            $options[$key] = Pages::resolve($key);
        } catch (\Throwable $e) {
        }
    }
    $selected = $saved ?: array_column($suggested, 'key');
    echo '<p>候选仅从最近最多 100 篇公开无密码文章/页面及健康公开工具注册表生成。最多 8 页，单次导航最多 30 秒，整任务最多 12 分钟；排队有效期 30 分钟。手动刷新查看进度。</p><p>首次访问每轮创建全新浏览器环境；配对模式在同一环境再次访问目标页，首次失败会跳过再次导航。浏览器 HTTP 缓存保持正常行为，服务端缓存观察仅记录配置响应头的 HIT/MISS/BYPASS，其他值不保留。禁用 XHR/fetch/beacon/API 网络，load 后采样 1.5 秒。长任务阻塞代理值不是 Lighthouse TBT；没有 Lighthouse 评分、INP 或完整用户旅程。</p>';
    form_start();
    foreach ($options as $p) {
        echo '<p><label><input type="checkbox" name="pages[]" value="' .
            esc_attr($p['key']) .
            '" ' .
            (in_array($p['key'], $selected, true) ? 'checked' : '') .
            '> ' .
            esc_html($p['title'] . ' — ' . $p['reason'] . ' (' . $p['key'] . ')') .
            '</label></p>';
    }
    echo '<p><label>额外公开文章/页面 ID（逗号分隔） <input name="post_ids" type="text" maxlength="200"></label></p><p><label>设备 <select name="device"><option value="desktop">桌面</option><option value="mobile">手机</option></select></label> <label>访问方式 <select name="cache_mode"><option value="fresh-context">首次访问</option><option value="paired">首次 / 再次成对访问</option></select></label> <label>重复 <select name="repeats"><option value="1">1 次</option><option value="3">3 次</option></select></label></p><p><button class="button" name="operation" value="save">保存固定选择</button> <button class="button button-primary" name="operation" value="start">开始测速</button></p></form>';
    $terminal = array_values(
        array_filter(
            $reports,
            static fn($j) => in_array($j['status'], ['completed', 'failed', 'cancelled'], true),
        ),
    );
    form_start(true);
    foreach (['before' => '前值报告', 'after' => '后值报告'] as $key => $label) {
        echo '<label>' . esc_html($label) . ' <select name="' . esc_attr($key) . '">';
        foreach ($terminal as $j) {
            echo '<option value="' .
                esc_attr($j['id']) .
                '">' .
                esc_html($j['created_at'] . ' / ' . $j['status'] . ' / ' . $j['id']) .
                '</option>';
        }
        echo '</select></label> ';
    }
    echo '<button class="button" name="operation" value="compare">对照历史报告</button></form>';
    if (
        ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&
        ($_POST['operation'] ?? '') === 'compare'
    ) {
        authorize();
        try {
            comparison(
                find_report($q, is_string($_POST['before'] ?? null) ? $_POST['before'] : ''),
                find_report($q, is_string($_POST['after'] ?? null) ? $_POST['after'] : ''),
            );
        } catch (\Throwable $e) {
            echo '<p>所选终态报告不可用。</p>';
        }
    }
    foreach ($reports as $j) {
        report($j);
    }
    echo '</div>';
}
add_action('admin_menu', static function () {
    add_management_page(
        '页面测速',
        '页面测速',
        'manage_options',
        'pagenest-performance',
        __NAMESPACE__ . '\\screen',
    );
});
// No REST, frontend resources, option reads or filesystem access at bootstrap.
if (enabled()) {
    add_action('admin_post_pagenest_performance', __NAMESPACE__ . '\\handle');
}
