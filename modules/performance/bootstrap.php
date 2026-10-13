<?php
namespace PageNest\Performance;
defined('ABSPATH') || exit();
require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/ui-helper.php';
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
    echo '<div class="wrap pn-admin pn-performance"><header class="pn-admin-header"><p class="pn-admin-eyebrow">PAGENEST / COMPANION</p><h1>页面测速</h1><p class="pn-admin-intro">为少量公开代表页采集首次与再次访问结果，查看历史并在相同条件下对照。管理员专用，结果仅为受控实验室样本。</p></header>';
    if (!enabled()) {
        echo '<section class="pn-admin-section"><h2>尚未启用</h2><p>页面测速默认关闭。请服务器管理员完成独立执行器部署与验收，再启用本工具。</p><details class="pn-perf-details"><summary>服务器配置要求</summary><div><p>显式设置 PAGENEST_COMPANION_PERFORMANCE_ENABLED=true，并设置 Web 根之外已存在的私有 PAGENEST_COMPANION_PERFORMANCE_DIR。安装插件不会自动部署浏览器或运行任务。</p></div></details></section></div>';
        return;
    }
    try {
        $q = queue();
        $reports = $q->reports();
        $suggested = Pages::suggested();
    } catch (\Throwable $e) {
        echo '<section class="pn-admin-section"><h2>测速暂不可用</h2>';
        ui_notice('配置或队列不可用。请服务器管理员检查私有目录和报告协议。', true);
        echo '</section></div>';
        return;
    }
    echo '<nav class="pn-admin-nav" aria-label="页面测速"><a href="#performance-new">新建任务</a><a href="#performance-latest">最新报告</a><a href="#performance-history">历史与对照</a><a href="#performance-limits">测量范围</a></nav>';
    $notices = [
        'saved' => '页面选择已保存。设备、访问方式和轮数不会保存。',
        'queued' => '任务已排队。请服务器管理员手动运行独立执行器，再刷新本页查看结果。',
        'cancel_requested' =>
            '取消请求已保存。若任务正在运行，执行器会检查请求并中止测量；请刷新确认终态。',
        'request_rejected' =>
            '请求被拒绝。请检查页面是否公开、选择是否为 1–8 项，以及配置、活动任务和执行器锁。',
        'budget_cleanup_required' =>
            '报告达到保留预算。请服务器管理员在私有目录归档或清理报告后再提交。',
    ];
    $notice = $_GET['performance_notice'] ?? '';
    if (is_string($notice) && isset($notices[$notice])) {
        ui_notice(
            $notices[$notice],
            in_array($notice, ['request_rejected', 'budget_cleanup_required'], true),
        );
    }
    $active = array_values(
        array_filter(
            $reports,
            static fn($j) => in_array($j['status'], ['queued', 'running'], true),
        ),
    );
    foreach ($active as $j) {
        ui_active($j);
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
    ui_selection($options, $saved ?: array_column($suggested, 'key'), (bool) $active);
    $terminal = array_values(
        array_filter(
            $reports,
            static fn($j) => in_array($j['status'], ['completed', 'failed', 'cancelled'], true),
        ),
    );
    echo '<section id="performance-latest" class="pn-admin-section"><h2>最新报告</h2>';
    if ($terminal) {
        report($terminal[0]);
    } else {
        echo '<p>还没有终态报告。提交任务并由服务器管理员手动运行执行器后，在此查看采样结果。</p>';
    }
    echo '</section><section id="performance-history" class="pn-admin-section"><h2>历史与对照</h2><p class="pn-perf-muted">最多保留 10 份报告。已完成、失败和取消的报告均可查看或导出；数值对照需要满足相同执行条件。</p>';
    if (count($terminal) < 2) {
        echo '<p>至少有两份终态报告后，才能选择基线与对照报告。</p>';
    } else {
        ui_form_start('pn-perf-compare-form', true);
        foreach (['before' => '基线报告', 'after' => '对照报告'] as $key => $label) {
            echo '<div><label for="pn-perf-' .
                esc_attr($key) .
                '">' .
                esc_html($label) .
                '</label><select id="pn-perf-' .
                esc_attr($key) .
                '" name="' .
                esc_attr($key) .
                '">';
            foreach ($terminal as $index => $j) {
                echo '<option value="' .
                    esc_attr($j['id']) .
                    '"' .
                    ((is_string($_POST[$key] ?? null)
                            ? $_POST[$key] === $j['id']
                            : ($key === 'before'
                                ? $index === 1
                                : $index === 0))
                        ? ' selected'
                        : '') .
                    '>' .
                    esc_html(
                        ui_time($j['created_at']) .
                            ' · ' .
                            ui_status($j['status']) .
                            ' · ' .
                            substr($j['id'], 0, 8),
                    ) .
                    '</option>';
            }
            echo '</select></div>';
        }
        echo '<button class="button" name="operation" value="compare">对照报告</button></form>';
    }
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
            ui_notice('所选终态报告不可用，请重新选择。', true);
        }
    }
    foreach (array_slice($terminal, 1) as $j) {
        echo '<details class="pn-perf-history"><summary>' .
            esc_html(
                ui_time($j['created_at']) .
                    ' · ' .
                    ui_status($j['status']) .
                    ' · ' .
                    count($j['pages']) .
                    ' 页',
            ) .
            '</summary><div>';
        report($j);
        echo '</div></details>';
    }
    echo '</section><details id="performance-limits" class="pn-admin-section pn-perf-limits"><summary>测量范围与限制</summary><div><p>最多 8 页、3 轮，单次导航最多 30 秒，整任务最多 12 分钟，排队有效期 30 分钟。候选仅从最近最多 100 篇公开无密码文章 / 页面及健康公开工具注册表生成。</p><p>首次每轮创建全新浏览器环境；再次仅复用同页同轮的 HTTP 缓存，不跨页或跨轮复用。首次失败时跳过再次导航，浏览器缓存正常服从服务器策略，不保证命中。</p><p>匿名浏览器禁止交互与 XHR/fetch/beacon/API 网络，load 后观察 1.5 秒。FCP、LCP、CLS 仅覆盖这一观察窗口。服务端 origin 缓存和浏览器 HTTP 缓存分别观察；没有 Lighthouse 评分、INP 或完整用户旅程，也不产生全站评分。</p><p>本工具只排队、查看和导出，不运行命令、不注册定时任务。结果管理员专用，私有且不缓存。报告超出保留预算时由服务器管理员归档或清理，不自动删除。</p></div></details></div>';
}
add_action('admin_enqueue_scripts', static function ($hook): void {
    if ($hook !== 'tools_page_pagenest-performance' || !current_user_can('manage_options')) {
        return;
    }
    $manifest = require dirname(__DIR__, 2) . '/assets/manifest.php';
    foreach (['admin_design_css', 'performance_admin_css'] as $key) {
        wp_enqueue_style(
            'pagenest-' . $key,
            plugins_url(
                'assets/' . $manifest[$key],
                dirname(__DIR__, 2) . '/pagenest-compatibility.php',
            ),
            [],
            null,
        );
    }
    wp_enqueue_script(
        'pagenest-performance-admin',
        plugins_url(
            'assets/' . $manifest['performance_admin_js'],
            dirname(__DIR__, 2) . '/pagenest-compatibility.php',
        ),
        [],
        null,
        true,
    );
});
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
