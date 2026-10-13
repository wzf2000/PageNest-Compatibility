<?php
namespace PageNest\Performance;
defined('ABSPATH') || exit();
/** Presentation only: measurement, queue and authorization contracts stay elsewhere. */
function ui_status(string $status): string
{
    return [
        'queued' => '等待执行',
        'running' => '正在执行',
        'completed' => '执行完成',
        'failed' => '执行失败',
        'cancelled' => '已取消',
    ][$status] ?? $status;
}
function ui_phase(string $phase): string
{
    return $phase === 'warm' ? '再次访问' : '首次访问';
}
function ui_error(?string $code): string
{
    return [
        'navigation_failed' => '页面导航未能完成。',
        'resource_limit' => '达到资源预算，测量已停止；已记录的样本保留。',
        'warmup_failed' => '首次访问失败，已跳过再次导航。',
        'job_timeout' => '任务达到总时间上限，已停止测量。',
        'cancelled' => '测量已取消。',
        'worker_expired' => '执行器任务记录已过期。请服务器管理员检查执行器状态。',
        'browser_failed' => '浏览器未能完成采样。请服务器管理员检查执行环境。',
        'runner_failed' => '执行器未能完成任务。请服务器管理员检查执行环境。',
    ][$code ?? ''] ?? '测量未能完成，请展开详情查看安全错误码。';
}
function ui_time(string $utc): string
{
    $timestamp = strtotime($utc);
    return $timestamp !== false && function_exists('wp_date')
        ? wp_date('Y-m-d H:i', $timestamp)
        : str_replace(['T', 'Z'], [' ', ' UTC'], $utc);
}
function ui_badge(string $status): void
{
    echo '<span class="pn-perf-badge pn-perf-' .
        esc_attr($status) .
        '">' .
        esc_html(ui_status($status)) .
        '</span>';
}
function ui_notice(string $text, bool $error = false): void
{
    echo '<div class="pn-perf-notice' .
        ($error ? ' pn-perf-notice-error' : '') .
        '" role="' .
        ($error ? 'alert' : 'status') .
        '"><p>' .
        esc_html($text) .
        '</p></div>';
}
function ui_form_start(string $class = '', bool $comparison = false): void
{
    echo '<form class="' .
        esc_attr($class) .
        '" method="post" action="' .
        esc_url(
            admin_url(
                $comparison
                    ? 'tools.php?page=pagenest-performance#performance-history'
                    : 'admin-post.php',
            ),
        ) .
        '"><input type="hidden" name="action" value="pagenest_performance">';
    wp_nonce_field('pagenest_performance');
}
function ui_actions(array $j): void
{
    ui_form_start('pn-perf-actions');
    echo '<input type="hidden" name="job" value="' . esc_attr($j['id']) . '">';
    if (in_array($j['status'], ['queued', 'running'], true)) {
        echo '<button class="button" name="operation" value="cancel">取消任务</button>';
    } else {
        echo '<button class="button" name="operation" value="export">导出私有 JSON</button>';
    }
    echo '</form>';
}
function ui_active(array $j): void
{
    $expected = count($j['pages']) * $j['profile']['repeats'] * count(Reports::phases($j));
    echo '<section class="pn-admin-section pn-perf-active"><div class="pn-perf-heading"><h2>活动任务</h2>';
    ui_badge($j['status']);
    echo '</div><p>' .
        esc_html(
            $j['status'] === 'queued'
                ? '任务只已排队。服务器管理员手动运行独立执行器后，请刷新本页查看结果。'
                : '执行器正在处理任务。请手动刷新查看最新记录；本页不会自动轮询。',
        ) .
        '</p><p class="pn-perf-meta">' .
        esc_html(
            '已记录 ' .
                count($j['results']) .
                ' / ' .
                $expected .
                ' 条阶段结果 · ' .
                count($j['pages']) .
                ' 页 · ' .
                $j['profile']['repeats'] .
                ' 轮',
        ) .
        '</p><p class="pn-perf-muted">记录数包含失败或跳过的阶段，不代表实际导航次数。</p><p class="pn-perf-meta">创建 ' .
        esc_html(ui_time($j['created_at'])) .
        ' · 更新 ' .
        esc_html(ui_time($j['updated_at'])) .
        '</p><div class="pn-perf-action-row"><a class="button" href="' .
        esc_url(admin_url('tools.php?page=pagenest-performance')) .
        '">刷新状态</a>';
    ui_actions($j);
    echo '</div></section>';
}
function ui_selection(array $options, array $selected, bool $busy): void
{
    echo '<section id="performance-new" class="pn-admin-section"><h2>新建测速任务</h2><p class="pn-perf-muted">选择 1–8 个公开页面。候选来自有限公开样本，提交时仍会验证页面是否公开可读。</p>';
    ui_form_start('pn-perf-selection');
    echo '<div class="pn-perf-selection-grid"><fieldset><legend>1. 选择页面</legend><div class="pn-perf-choices">';
    foreach ($options as $p) {
        echo '<label class="pn-perf-choice"><input type="checkbox" name="pages[]" value="' .
            esc_attr($p['key']) .
            '"' .
            (in_array($p['key'], $selected, true) ? ' checked' : '') .
            '><span><strong>' .
            esc_html($p['title']) .
            '</strong><span class="pn-perf-muted">' .
            esc_html($p['reason'] . ' · ' . $p['key']) .
            '</span></span></label>';
    }
    if (!$options) {
        echo '<p>暂无可用候选。可在下方填写公开文章或页面 ID。</p>';
    }
    echo '</div><details class="pn-perf-details"><summary>补充公开文章或页面</summary><div><label for="pn-perf-post-ids">文章 / 页面 ID</label><input id="pn-perf-post-ids" name="post_ids" type="text" maxlength="200" inputmode="numeric" aria-describedby="pn-perf-ids-help"><p id="pn-perf-ids-help" class="pn-perf-muted">多个 ID 用逗号或空格分隔，和上方勾选项合计最多 8 项。公开性由服务器验证。</p></div></details></fieldset><fieldset class="pn-perf-config"><legend>2. 配置采样</legend><div class="pn-admin-field"><label for="pn-perf-device">设备</label><select id="pn-perf-device" name="device"><option value="desktop">桌面</option><option value="mobile">手机</option></select></div><div class="pn-admin-field"><label for="pn-perf-mode">访问方式</label><select id="pn-perf-mode" name="cache_mode" aria-describedby="pn-perf-mode-help"><option value="fresh-context">仅首次访问</option><option value="paired">首次 / 再次成对访问</option></select><p id="pn-perf-mode-help" class="pn-perf-muted">首次使用全新环境；再次仅复用同页同轮的 HTTP 缓存，不保证缓存命中。</p></div><div class="pn-admin-field"><label for="pn-perf-repeats">重复轮数</label><select id="pn-perf-repeats" name="repeats"><option value="1">1 轮</option><option value="3">3 轮</option></select></div><div class="pn-perf-estimate"><p data-pn-perf-count>请选择 1–8 项。</p><p data-pn-perf-estimate class="pn-perf-muted">最多 8 页、3 轮；成对模式最多 48 次导航。</p></div></fieldset></div><p data-pn-perf-validation role="status" aria-live="polite" hidden></p><div class="pn-perf-submit"><div><button class="button button-primary" name="operation" value="start">提交测速任务</button><p class="pn-perf-muted">只排队。服务器管理员手动运行执行器后，刷新本页查看结果。</p>' .
        ($busy
            ? '<p>已有活动记录。提交时由服务器确认是否可新建任务；活动锁仍被占用时会拒绝提交。</p>'
            : '') .
        '</div><div><button class="button" name="operation" value="save">保存页面选择</button><p class="pn-perf-muted">只保存页面，不保存设备、访问方式或轮数。</p></div></div></form></section>';
}
function ui_metadata(array $j): void
{
    echo '<details class="pn-perf-details"><summary>报告身份与执行环境</summary><div><dl class="pn-perf-definition">';
    $data = [
        '安全错误码' => $j['error'] ?? '—',
        '报告 ID' => $j['id'],
        '创建时间（UTC）' => $j['created_at'],
        '更新时间（UTC）' => $j['updated_at'],
        '协议版本' => $j['schema_version'],
        '站点' => $j['origin'],
        'WordPress' => $j['versions']['wordpress'],
        'Companion' => $j['versions']['companion'],
        '主题' => $j['versions']['theme'],
    ];
    foreach (
        array_intersect_key(
            $j['environment'] ?? [],
            array_flip([
                'runner',
                'node_version',
                'chromium_version',
                'network',
                'cache_mode',
                'sample_window_ms',
                'policy_hash',
                'limits_hash',
            ]),
        )
        as $key => $value
    ) {
        $data[$key] = $value;
    }
    foreach ($data as $label => $value) {
        echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd>';
    }
    echo '</dl></div></details>';
}
function ui_metric_value(?float $value, string $metric): string
{
    if ($value === null) {
        return '未采集';
    }
    return Reports::display($value) . ($metric === 'cls' ? '' : ' ms');
}
function ui_metric_cards(array $j, string $key): void
{
    $core = [
        'ttfb_ms' => ['TTFB', '主文档首字节等待时间'],
        'fcp_ms' => ['FCP', '首次内容绘制时间'],
        'lcp_ms' => ['LCP', '观察窗口内的最大内容绘制时间'],
        'cls' => ['CLS', '观察窗口内的累计布局偏移，无单位'],
    ];
    echo '<div class="pn-perf-metrics">';
    foreach ($core as $metric => [$label, $meaning]) {
        echo '<div class="pn-perf-metric"><h4>' .
            esc_html($label) .
            '</h4><p class="pn-perf-muted">' .
            esc_html($meaning) .
            '</p>';
        if ($j['profile']['cache_mode'] === 'paired') {
            $s = Reports::paired($j, $key, $metric);
            echo '<dl><dt>首次</dt><dd>' .
                esc_html(ui_metric_value($s['fresh'], $metric)) .
                '</dd><dt>再次</dt><dd>' .
                esc_html(ui_metric_value($s['warm'], $metric)) .
                '</dd></dl><p class="pn-perf-difference">再次 − 首次 <strong>' .
                esc_html(ui_metric_value($s['difference'], $metric)) .
                '</strong></p><p class="pn-perf-muted">同轮有效配对 ' .
                esc_html($s['n'] . '/' . $s['total']) .
                '</p>';
        } else {
            $s = Reports::stats($j, $key, 'fresh', $metric);
            echo '<p class="pn-perf-value">' .
                esc_html(ui_metric_value($s['median'], $metric)) .
                '</p><p class="pn-perf-muted">首次有效样本 ' .
                esc_html($s['n'] . '/' . $s['total']) .
                '</p>';
        }
        echo '</div>';
    }
    echo '</div>';
}
function ui_stat_cell(array $s): void
{
    echo '<span class="pn-perf-number">' .
        esc_html($s['median'] === null ? '未采集' : Reports::display($s['median'])) .
        '</span><small>有效 ' .
        esc_html($s['n'] . '/' . $s['total']) .
        '</small>';
}
function ui_resource_table(array $j, string $key): void
{
    echo '<div class="pn-perf-table-wrap" tabindex="0" role="region" aria-label="缓存与资源指标"><table class="pn-perf-table"><caption>缓存与资源 · 各阶段成功样本中位数</caption><thead><tr><th scope="col">指标</th>';
    foreach (Reports::phases($j) as $phase) {
        echo '<th scope="col">' . ui_phase($phase) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach (
        array_diff_key(Reports::METRICS, array_flip(['ttfb_ms', 'fcp_ms', 'lcp_ms', 'cls']))
        as $metric => $label
    ) {
        echo '<tr><th scope="row">' . esc_html($label) . '</th>';
        foreach (Reports::phases($j) as $phase) {
            echo '<td>';
            ui_stat_cell(Reports::stats($j, $key, $phase, $metric));
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div><p class="pn-perf-muted">拦截请求由测量保护策略阻止，不直接代表网站故障。长任务阻塞代理值不是 Lighthouse TBT。</p><div class="pn-perf-origin"><h4>服务端 origin 缓存 · 逐轮主文档观测</h4>';
    foreach (Reports::phases($j) as $phase) {
        $counts = array_fill_keys(['HIT', 'MISS', 'BYPASS', 'unknown'], 0);
        foreach ($j['results'] as $r) {
            if ($r['page_key'] === $key && ($r['cache_phase'] ?? 'fresh') === $phase) {
                $counts[$r['cache_header']]++;
            }
        }
        echo '<p><strong>' .
            ui_phase($phase) .
            '</strong> ' .
            esc_html(
                implode(
                    ' · ',
                    array_map(
                        static fn($name, $n) => $name . ' ' . $n,
                        array_keys($counts),
                        $counts,
                    ),
                ),
            ) .
            '</p>';
    }
    echo '<p class="pn-perf-muted">与浏览器 HTTP 缓存请求数独立。unknown 表示没有可用观测，包括浏览器缓存返回或 304 再验证。</p></div>';
}
function ui_samples(array $j, array $p): void
{
    echo '<details class="pn-perf-details"><summary>逐轮记录与完整阶段摘要</summary><div><p class="pn-perf-muted">— 表示该指标未采集；0 是已记录的零值。每个指标分别统计有效样本。</p>';
    foreach (Reports::phases($j) as $phase) {
        echo '<h4>' . ui_phase($phase) . ' · 成功样本中位数</h4><dl class="pn-perf-definition">';
        foreach (Reports::METRICS as $metric => $label) {
            echo '<dt>' .
                esc_html($label) .
                '</dt><dd>' .
                esc_html(Reports::statistic(Reports::stats($j, $p['key'], $phase, $metric))) .
                '</dd>';
        }
        echo '</dl>';
    }
    if ($j['profile']['cache_mode'] === 'paired') {
        echo '<h4>同页同轮成功配对 · 所有指标</h4><p class="pn-perf-muted">首次 → 再次 / 逐对差值中位数（有效配对）。独立阶段中位数不用于计算配对差值。</p><dl class="pn-perf-definition">';
        foreach (Reports::METRICS as $metric => $label) {
            $s = Reports::paired($j, $p['key'], $metric);
            echo '<dt>' .
                esc_html($label) .
                '</dt><dd>' .
                esc_html(
                    Reports::display($s['fresh']) .
                        ' → ' .
                        Reports::display($s['warm']) .
                        ' / ' .
                        Reports::display($s['difference']) .
                        ' (' .
                        $s['n'] .
                        '/' .
                        $s['total'] .
                        ')',
                ) .
                '</dd>';
        }
        echo '</dl>';
    }
    foreach ($j['results'] as $r) {
        if ($r['page_key'] !== $p['key']) {
            continue;
        }
        echo '<details class="pn-perf-sample"><summary>' .
            esc_html(
                '第 ' .
                    $r['run'] .
                    ' 轮 · ' .
                    ui_phase($r['cache_phase'] ?? 'fresh') .
                    ' · ' .
                    ($r['status'] === 'ok'
                        ? '采样成功'
                        : ($r['error'] === 'warmup_failed'
                            ? '首次失败，未再次导航'
                            : '采样失败')),
            ) .
            '</summary><dl class="pn-perf-definition"><dt>HTTP 状态</dt><dd>' .
            esc_html($r['http_status'] ?? '—') .
            '</dd><dt>origin 缓存</dt><dd>' .
            esc_html($r['cache_header']) .
            '</dd>';
        foreach (Reports::METRICS as $metric => $label) {
            echo '<dt>' .
                esc_html($label) .
                '</dt><dd>' .
                esc_html(Reports::display($r[$metric] ?? null)) .
                '</dd>';
        }
        if ($r['error']) {
            echo '<dt>采样说明</dt><dd>' .
                esc_html(ui_error($r['error'])) .
                '</dd><dt>安全错误码</dt><dd>' .
                esc_html($r['error']) .
                '</dd>';
        }
        echo '</dl></details>';
    }
    echo '</div></details>';
}
function ui_report(array $j): void
{
    $failed = count(array_filter($j['results'], static fn($r) => $r['status'] === 'failed'));
    echo '<article class="pn-perf-report"><div class="pn-perf-heading"><h3><time datetime="' .
        esc_attr($j['created_at']) .
        '">' .
        esc_html(ui_time($j['created_at'])) .
        '</time></h3>';
    ui_badge($j['status']);
    echo '</div><p class="pn-perf-meta">' .
        esc_html(
            ($j['profile']['device'] === 'mobile' ? '手机' : '桌面') .
                ' · ' .
                ($j['profile']['cache_mode'] === 'paired' ? '首次 / 再次成对访问' : '仅首次访问') .
                ' · ' .
                $j['profile']['repeats'] .
                ' 轮 · ' .
                count($j['pages']) .
                ' 页',
        ) .
        '</p>';
    if ($j['error']) {
        ui_notice(ui_error($j['error']), true);
    }
    if ($failed) {
        ui_notice(
            '已记录 ' . $failed . ' 条失败或跳过的阶段结果。执行完成不表示所有页面采样成功。',
            true,
        );
    }
    if (!$j['results']) {
        echo '<p>尚无采样记录。</p>';
    } else {
        echo '<p class="pn-perf-muted">受控实验室样本。HTTP 200 仅表示主文档响应成功，不能据此判断全站性能。</p>';
        if ($j['profile']['cache_mode'] === 'paired') {
            echo '<p class="pn-perf-muted">核心指标使用同页同轮成功配对；差值为逐对「再次 − 首次」的中位数。缺少配对时可展开完整阶段摘要。</p>';
        }
        foreach ($j['pages'] as $index => $p) {
            $pageRows = array_filter($j['results'], static fn($r) => $r['page_key'] === $p['key']);
            $ok = count(array_filter($pageRows, static fn($r) => $r['status'] === 'ok'));
            $skipped = count(
                array_filter(
                    $pageRows,
                    static fn($r) => $r['status'] === 'failed' && $r['error'] === 'warmup_failed',
                ),
            );
            $pageFailed = count($pageRows) - $ok - $skipped;

            echo '<details class="pn-perf-page"' .
                ($index === 0 ? ' open' : '') .
                '><summary><span>' .
                esc_html($p['title']) .
                '</span><small' .
                ($pageFailed || $skipped ? ' class="pn-perf-page-issues"' : '') .
                '>' .
                esc_html(
                    $p['key'] .
                        ' · 记录：成功 ' .
                        $ok .
                        ' · 失败 ' .
                        $pageFailed .
                        ' · 跳过 ' .
                        $skipped,
                ) .
                '</small></summary><div class="pn-perf-page-body"><p class="pn-perf-url">' .
                esc_html($p['url']) .
                '</p>';
            ui_metric_cards($j, $p['key']);
            ui_resource_table($j, $p['key']);
            ui_samples($j, $p);
            echo '</div></details>';
        }
    }
    ui_metadata($j);
    ui_actions($j);
    echo '</article>';
}
function ui_comparison(array $a, array $b): void
{
    $c = Reports::compare($a, $b);
    echo '<section class="pn-perf-comparison"><h3>历史报告对照</h3><p class="pn-perf-meta">基线 ' .
        esc_html(ui_time($a['created_at'])) .
        ' → 对照 ' .
        esc_html(ui_time($b['created_at'])) .
        '</p><p class="pn-perf-muted">只对照相同 URL 与执行条件。内容与机器负载未固定；差值不证明因果，不产生全站分数。</p>';
    if ($c['versions_changed']) {
        echo '<p>组件版本已变更，可展开下方报告身份核对。</p>';
    }
    foreach ($c['reasons'] as $reason) {
        ui_notice($reason, !$c['comparable']);
    }
    if (!$c['comparable']) {
        echo '<p>这些报告仍可单独查看和导出。</p>';
    }
    foreach ($c['pages'] as $p) {
        echo '<div class="pn-perf-compare-page"><h4>' .
            esc_html($p['title'] . ' · ' . ui_phase($p['phase'])) .
            '</h4>';
        foreach ([true, false] as $core) {
            if (!$core) {
                echo '<details class="pn-perf-details"><summary>其他指标对照</summary><div>';
            }
            echo '<div class="pn-perf-compare-metrics">';
            foreach ($p['metrics'] as $metric => $s) {
                if (in_array($metric, ['ttfb_ms', 'fcp_ms', 'lcp_ms', 'cls'], true) !== $core) {
                    continue;
                }
                $percent =
                    $s['delta']['percent'] === null
                        ? ($s['before']['median'] === 0.0 && $s['after']['median'] !== null
                            ? '基线为 0，不计算百分比'
                            : '缺少有效样本，不计算百分比')
                        : Reports::display($s['delta']['percent']) . '%';
                echo '<div class="pn-perf-compare-metric"><h5>' .
                    esc_html(Reports::METRICS[$metric]) .
                    '</h5><dl><dt>基线</dt><dd>' .
                    esc_html(Reports::statistic($s['before'])) .
                    '</dd><dt>对照</dt><dd>' .
                    esc_html(Reports::statistic($s['after'])) .
                    '</dd><dt>对照 − 基线</dt><dd>' .
                    esc_html(Reports::display($s['delta']['absolute'])) .
                    '</dd></dl><p class="pn-perf-muted">' .
                    esc_html($percent) .
                    '</p></div>';
            }
            echo '</div>';
            if (!$core) {
                echo '</div></details>';
            }
        }
        echo '</div>';
    }
    echo '<p class="pn-perf-muted">括号内为每项指标有效 N / 轮数。— 表示缺少有效样本。</p>';
    ui_metadata($a);
    ui_metadata($b);
    echo '</section>';
}
