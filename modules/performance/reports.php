<?php
namespace PageNest\Performance;
defined('ABSPATH') || exit();
final class Reports
{
    public const METRICS = [
        'ttfb_ms' => 'TTFB (ms)',
        'fcp_ms' => 'FCP (ms)',
        'lcp_ms' => 'LCP (ms)',
        'cls' => 'CLS',
        'long_task_blocking_ms' => '长任务阻塞代理值 (ms)',
        'request_count' => '请求数',
        'browser_cache_requests' => '浏览器缓存请求数',
        'transfer_bytes' => '传输字节',
        'failed_requests' => '失败请求',
        'blocked_requests' => '拦截请求',
        'duration_ms' => '采样耗时 (ms)',
    ];
    public static function median(array $values): ?float
    {
        if (!$values) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $n = count($values);
        return ($values[(int) floor(($n - 1) / 2)] + $values[(int) floor($n / 2)]) / 2;
    }
    public static function phases(array $j): array
    {
        return $j['profile']['cache_mode'] === 'paired' ? ['fresh', 'warm'] : ['fresh'];
    }
    public static function stats(array $j, string $key, string $phase, string $metric): array
    {
        $values = [];
        foreach ($j['results'] as $r) {
            if (
                $r['page_key'] === $key &&
                ($r['cache_phase'] ?? 'fresh') === $phase &&
                $r['status'] === 'ok' &&
                isset($r[$metric])
            ) {
                $values[] = $r[$metric];
            }
        }
        return [
            'median' => self::median($values),
            'n' => count($values),
            'total' => $j['profile']['repeats'],
        ];
    }
    public static function delta(?float $before, ?float $after): array
    {
        return [
            'absolute' => $before === null || $after === null ? null : $after - $before,
            'percent' =>
                $before === null || $after === null || $before == 0
                    ? null
                    : (($after - $before) / $before) * 100,
        ];
    }
    public static function paired(array $j, string $key, string $metric): array
    {
        $runs = [];
        $before = [];
        $after = [];
        $differences = [];
        foreach ($j['results'] as $r) {
            if ($r['page_key'] === $key) {
                $runs[$r['run']][$r['cache_phase'] ?? 'fresh'] = $r;
            }
        }
        foreach ($runs as $pair) {
            if (
                ($pair['fresh']['status'] ?? '') === 'ok' &&
                ($pair['warm']['status'] ?? '') === 'ok' &&
                isset($pair['fresh'][$metric], $pair['warm'][$metric])
            ) {
                $before[] = $pair['fresh'][$metric];
                $after[] = $pair['warm'][$metric];
                $differences[] = $pair['warm'][$metric] - $pair['fresh'][$metric];
            }
        }
        return [
            'fresh' => self::median($before),
            'warm' => self::median($after),
            'difference' => self::median($differences),
            'n' => count($before),
            'total' => $j['profile']['repeats'],
        ];
    }
    public static function compare(array $a, array $b): array
    {
        $reasons = [];
        if ($a['status'] !== 'completed' || $b['status'] !== 'completed') {
            $reasons[] = '两份报告必须都已完成（completed）。';
        }
        if ($a['schema_version'] !== 2 || $b['schema_version'] !== 2) {
            $reasons[] = '旧协议报告缺少可比环境记录。';
        }
        if ($a['origin'] !== $b['origin']) {
            $reasons[] = '站点 origin 不同。';
        }
        foreach (['device', 'repeats', 'cache_mode'] as $k) {
            if ($a['profile'][$k] !== $b['profile'][$k]) {
                $reasons[] = '测量配置不同：' . $k;
            }
        }
        if (!isset($a['environment'], $b['environment'])) {
            $reasons[] = '缺少执行环境记录。';
        } else {
            foreach (
                [
                    'runner',
                    'node_version',
                    'chromium_version',
                    'network',
                    'cache_mode',
                    'sample_window_ms',
                    'policy_hash',
                    'limits_hash',
                ]
                as $k
            ) {
                if (
                    !array_key_exists($k, $a['environment']) ||
                    !array_key_exists($k, $b['environment'])
                ) {
                    $reasons[] = '执行环境缺少：' . $k;
                } elseif ($a['environment'][$k] !== $b['environment'][$k]) {
                    $reasons[] = '执行环境不同：' . $k;
                }
            }
        }
        $out = [
            'comparable' => false,
            'reasons' => $reasons,
            'versions_changed' => $a['versions'] !== $b['versions'],
            'pages' => [],
        ];
        if ($reasons) {
            return $out;
        }
        $map = [];
        foreach ($b['pages'] as $p) {
            $map[$p['key']] = $p;
        }
        foreach ($a['pages'] as $p) {
            if (!isset($map[$p['key']])) {
                $out['reasons'][] = '第二份报告没有共同页：' . $p['key'];
                continue;
            }
            if ($map[$p['key']]['url'] !== $p['url']) {
                $out['reasons'][] = '共同页 URL 不同：' . $p['key'];
                continue;
            }
            foreach (self::phases($a) as $phase) {
                $metrics = [];
                $hasData = false;
                foreach (self::METRICS as $metric => $label) {
                    $before = self::stats($a, $p['key'], $phase, $metric);
                    $after = self::stats($b, $p['key'], $phase, $metric);
                    if ($before['median'] !== null && $after['median'] !== null) {
                        $hasData = true;
                    }
                    $metrics[$metric] = [
                        'before' => $before,
                        'after' => $after,
                        'delta' => self::delta($before['median'], $after['median']),
                    ];
                }
                if (!$hasData) {
                    $out['reasons'][] =
                        '共同页阶段没有两份报告都有效的样本：' . $p['key'] . ' / ' . $phase;
                    continue;
                }
                $out['pages'][] = [
                    'key' => $p['key'],
                    'title' => $p['title'],
                    'phase' => $phase,
                    'metrics' => $metrics,
                ];
            }
        }
        if (!$out['pages']) {
            $out['reasons'][] = '没有 URL 一致的共同页面。';
        }
        $out['comparable'] = (bool) $out['pages'];
        return $out;
    }
    public static function export(array $j): array
    {
        if (!in_array($j['status'] ?? '', ['completed', 'failed', 'cancelled'], true)) {
            throw new \RuntimeException('invalid_export');
        }
        $out = self::pick($j, [
            'schema_version',
            'id',
            'status',
            'created_at',
            'updated_at',
            'expires_at',
            'origin',
            'error',
        ]);
        $out['profile'] = self::pick($j['profile'], ['device', 'cache_mode', 'repeats']);
        $out['versions'] = self::pick($j['versions'], ['wordpress', 'companion', 'theme']);
        if (isset($j['environment'])) {
            $out['environment'] = self::pick($j['environment'], [
                'runner',
                'node_version',
                'chromium_version',
                'network',
                'cache_mode',
                'sample_window_ms',
                'policy_hash',
                'limits_hash',
            ]);
        }
        $out['pages'] = array_map(
            static fn($p) => self::pick($p, ['key', 'post_id', 'url', 'title', 'reason']),
            $j['pages'],
        );
        $out['results'] = array_map(
            static fn($r) => self::pick($r, [
                'page_key',
                'run',
                'cache_phase',
                'status',
                'http_status',
                ...array_keys(self::METRICS),
                'cache_header',
                'error',
            ]),
            $j['results'],
        );
        if (!Queue::valid($out)) {
            throw new \RuntimeException('invalid_export');
        }
        return $out;
    }
    private static function pick(array $a, array $keys): array
    {
        return array_intersect_key($a, array_flip($keys));
    }
    public static function display($value): string
    {
        return $value === null ? '—' : (string) round($value, 3);
    }
    public static function statistic(array $s): string
    {
        return self::display($s['median']) . ' (' . $s['n'] . '/' . $s['total'] . ')';
    }
}
function report(array $j): void
{
    ui_report($j);
}
function comparison(array $a, array $b): void
{
    ui_comparison($a, $b);
}
