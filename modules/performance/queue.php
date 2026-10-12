<?php
namespace PageNest\Performance;
defined('ABSPATH') || exit();
final class Queue
{
    public const LIMIT = 524288;
    public function __construct(private string $dir) {}
    public static function directory(string $dir): string
    {
        if (
            $dir === '' ||
            !str_starts_with($dir, '/') ||
            @realpath($dir) !== $dir ||
            !@is_dir($dir)
        ) {
            throw new \RuntimeException('invalid_directory');
        }
        for ($p = $dir; $p !== '/'; $p = dirname($p)) {
            if (@is_link($p)) {
                throw new \RuntimeException('invalid_directory');
            }
        }
        foreach ([ABSPATH, dirname(__DIR__, 2), $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH] as $root) {
            $root = @realpath($root);
            if ($root && ($dir === $root || str_starts_with($dir, $root . '/'))) {
                throw new \RuntimeException('invalid_directory');
            }
        }
        if ((@fileperms($dir) & 0007) !== 0 || !@is_writable($dir)) {
            throw new \RuntimeException('invalid_directory');
        }
        $names = @scandir($dir);
        if (!is_array($names)) {
            throw new \RuntimeException('queue_unavailable');
        }
        foreach ($names as $name) {
            if ($name !== '.' && $name !== '..' && @is_link($dir . '/' . $name)) {
                throw new \RuntimeException('invalid_directory');
            }
        }
        return $dir;
    }
    private function lock(string $name, int $mode)
    {
        $path = $this->dir . '/' . $name;
        if (
            @is_link($path) ||
            (file_exists($path) && (!@is_file($path) || @fileperms($path) & 0007))
        ) {
            throw new \RuntimeException('invalid_file');
        }
        $mask = umask(0077);
        try {
            $h = @fopen($path, 'c+b');
        } finally {
            umask($mask);
        }
        if (!$h) {
            throw new \RuntimeException('queue_unavailable');
        }
        @chmod($path, 0600);
        if (!@flock($h, $mode)) {
            @fclose($h);
            throw new \RuntimeException('queue_busy');
        }
        return $h;
    }
    public static function valid(array $j): bool
    {
        if (
            ($j['schema_version'] ?? null) !== 1 ||
            !is_string($j['id'] ?? null) ||
            !preg_match('/^[a-f0-9]{32}$/D', $j['id']) ||
            !in_array(
                $j['status'] ?? '',
                ['queued', 'running', 'completed', 'failed', 'cancelled'],
                true,
            ) ||
            !is_array($j['pages'] ?? null) ||
            !$j['pages'] ||
            count($j['pages']) > 8 ||
            !is_array($j['results'] ?? null) ||
            count($j['results']) > 24 ||
            !in_array($j['profile']['repeats'] ?? null, [1, 3], true) ||
            !in_array($j['profile']['device'] ?? '', ['desktop', 'mobile'], true) ||
            ($j['profile']['cache_mode'] ?? '') !== 'fresh-context'
        ) {
            return false;
        }
        foreach (['created_at', 'updated_at', 'expires_at'] as $k) {
            if (
                !is_string($j[$k] ?? null) ||
                !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{3})?Z$/D', $j[$k]) ||
                strtotime($j[$k]) === false
            ) {
                return false;
            }
        }
        if (
            !is_array($j['versions'] ?? null) ||
            !is_string($j['origin'] ?? null) ||
            !preg_match('~^https://[a-zA-Z0-9.-]+$~D', $j['origin']) ||
            !(
                ($j['error'] ?? null) === null ||
                in_array($j['error'], ['worker_expired', 'job_timeout', 'runner_failed'], true)
            )
        ) {
            return false;
        }
        try {
            if ($j['origin'] !== Pages::origin()) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }
        if (isset($j['environment'])) {
            $env = $j['environment'];
            if (
                !is_array($env) ||
                array_diff(array_keys($env), [
                    'runner',
                    'node_version',
                    'chromium_version',
                    'network',
                    'cache_mode',
                ]) ||
                ($env['runner'] ?? '') !== 'playwright-navigation-v1' ||
                ($env['network'] ?? '') !== 'server-local' ||
                ($env['cache_mode'] ?? '') !== 'fresh-context'
            ) {
                return false;
            }
            foreach (['node_version', 'chromium_version'] as $k) {
                if (!is_string($env[$k] ?? null) || strlen($env[$k]) > 100) {
                    return false;
                }
            }
        }
        foreach (['wordpress', 'companion', 'theme'] as $k) {
            if (!is_string($j['versions'][$k] ?? null) || strlen($j['versions'][$k]) > 100) {
                return false;
            }
        }
        $keys = [];
        foreach ($j['pages'] as $p) {
            if (
                !is_array($p) ||
                !is_string($p['key'] ?? null) ||
                !preg_match('/^(home|blog|post:[1-9][0-9]*)$/D', $p['key']) ||
                isset($keys[$p['key']]) ||
                !is_int($p['post_id'] ?? null) ||
                $p['post_id'] < 0
            ) {
                return false;
            }
            if (
                str_starts_with($p['key'], 'post:') &&
                $p['post_id'] !== (int) substr($p['key'], 5)
            ) {
                return false;
            }
            $keys[$p['key']] = true;
            foreach (['url', 'title', 'reason'] as $k) {
                if (!is_string($p[$k] ?? null) || strlen($p[$k]) > 4096) {
                    return false;
                }
            }
            try {
                Pages::url($p['url']);
            } catch (\Throwable $e) {
                return false;
            }
        }
        $seen = [];
        foreach ($j['results'] as $r) {
            if (
                !is_array($r) ||
                !isset($keys[$r['page_key'] ?? '']) ||
                !is_int($r['run'] ?? null) ||
                $r['run'] < 1 ||
                $r['run'] > $j['profile']['repeats'] ||
                !in_array($r['status'] ?? '', ['ok', 'failed'], true) ||
                !in_array($r['cache_header'] ?? '', ['HIT', 'MISS', 'BYPASS', 'unknown'], true) ||
                !(
                    ($r['error'] ?? null) === null ||
                    in_array(
                        $r['error'],
                        ['target_blocked', 'http_error', 'navigation_timeout', 'navigation_failed'],
                        true,
                    )
                )
            ) {
                return false;
            }
            if (isset($seen[$r['page_key'] . ':' . $r['run']])) {
                return false;
            }
            $seen[$r['page_key'] . ':' . $r['run']] = true;
            foreach (['ttfb_ms', 'fcp_ms', 'lcp_ms', 'cls', 'long_task_blocking_ms'] as $k) {
                if (
                    !array_key_exists($k, $r) ||
                    !(
                        $r[$k] === null ||
                        (is_numeric($r[$k]) &&
                            !is_string($r[$k]) &&
                            is_finite((float) $r[$k]) &&
                            $r[$k] >= 0)
                    )
                ) {
                    return false;
                }
            }
            foreach (
                ['request_count', 'transfer_bytes', 'failed_requests', 'blocked_requests']
                as $k
            ) {
                if (!is_int($r[$k] ?? null) || $r[$k] < 0) {
                    return false;
                }
            }
            if (
                !is_numeric($r['duration_ms'] ?? null) ||
                is_string($r['duration_ms']) ||
                !is_finite((float) $r['duration_ms']) ||
                $r['duration_ms'] < 0 ||
                !array_key_exists('http_status', $r) ||
                !(
                    $r['http_status'] === null ||
                    (is_int($r['http_status']) &&
                        $r['http_status'] >= 100 &&
                        $r['http_status'] <= 599)
                )
            ) {
                return false;
            }
        }
        return true;
    }
    public function reports(): array
    {
        $out = [];
        $paths = @glob($this->dir . '/job-*.json');
        if (!is_array($paths)) {
            throw new \RuntimeException('queue_unavailable');
        }
        foreach ($paths as $path) {
            if (
                !preg_match('/job-([a-f0-9]{32})\.json$/D', $path, $m) ||
                @is_link($path) ||
                !@is_file($path) ||
                @fileperms($path) & 0007 ||
                @filesize($path) > self::LIMIT
            ) {
                throw new \RuntimeException('invalid_report');
            }
            $raw = @file_get_contents($path, false, null, 0, self::LIMIT + 1);
            if (!is_string($raw)) {
                throw new \RuntimeException('invalid_report');
            }
            $j = json_decode($raw, true);
            if (
                strlen($raw) > self::LIMIT ||
                !is_array($j) ||
                !self::valid($j) ||
                $j['id'] !== $m[1]
            ) {
                throw new \RuntimeException('invalid_report');
            }
            $out[] = $j;
        }
        usort($out, static fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $out;
    }
    private function write(string $name, array $data): void
    {
        $path = $this->dir . '/' . $name;
        if (@is_link($path)) {
            throw new \RuntimeException('invalid_file');
        }
        $tmp = $this->dir . '/.tmp-' . bin2hex(random_bytes(16));
        $mask = umask(0077);
        try {
            $h = @fopen($tmp, 'x+b');
        } finally {
            umask($mask);
        }
        try {
            if (!$h || !@chmod($tmp, 0600)) {
                throw new \RuntimeException('queue_unavailable');
            }
            $raw = json_encode(
                $data,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            if (
                strlen($raw) > self::LIMIT ||
                @fwrite($h, $raw) !== strlen($raw) ||
                !@fflush($h) ||
                (function_exists('fsync') && !@fsync($h))
            ) {
                throw new \RuntimeException('queue_unavailable');
            }
            @fclose($h);
            $h = null;
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException('queue_unavailable');
            }
        } finally {
            if (is_resource($h)) {
                @fclose($h);
            }
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }
    public function start(array $keys, string $device, int $repeats): array
    {
        $pages = Pages::selection($keys);
        if (!in_array($device, ['desktop', 'mobile'], true) || !in_array($repeats, [1, 3], true)) {
            throw new \RuntimeException('invalid_profile');
        }
        $lock = $this->lock('queue.lock', LOCK_EX | LOCK_NB);
        $worker = null;
        try {
            $worker = $this->lock('worker.lock', LOCK_EX | LOCK_NB);
            $reports = $this->reports();
            foreach ($reports as $j) {
                if (in_array($j['status'], ['queued', 'running'], true)) {
                    if (strtotime($j['expires_at']) >= time()) {
                        throw new \RuntimeException('queue_busy');
                    }
                    $j['status'] = 'failed';
                    $j['error'] = 'worker_expired';
                    $j['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                    $this->write('job-' . $j['id'] . '.json', $j);
                }
            }
            $bytes = 0;
            $names = @scandir($this->dir);
            if (!is_array($names)) {
                throw new \RuntimeException('queue_unavailable');
            }
            foreach ($names as $name) {
                $path = $this->dir . '/' . $name;
                if (@is_file($path)) {
                    $bytes += @filesize($path);
                }
            }
            global $wp_version;
            $now = time();
            $j = [
                'schema_version' => 1,
                'id' => bin2hex(random_bytes(16)),
                'status' => 'queued',
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 1800),
                'profile' => [
                    'device' => $device,
                    'cache_mode' => 'fresh-context',
                    'repeats' => $repeats,
                ],
                'origin' => Pages::origin(),
                'pages' => $pages,
                'versions' => [
                    'wordpress' => (string) $wp_version,
                    'companion' => (string) get_file_data(
                        dirname(__DIR__, 2) . '/pagenest-compatibility.php',
                        ['Version' => 'Version'],
                    )['Version'],
                    'theme' => (string) wp_get_theme()->get('Version'),
                ],
                'results' => [],
                'error' => null,
            ];
            if (count($reports) >= 10 || $bytes + strlen(json_encode($j)) >= 5 * 1024 * 1024) {
                throw new \RuntimeException('budget_cleanup_required');
            }
            $this->write('job-' . $j['id'] . '.json', $j);
            return $j;
        } finally {
            if ($worker) {
                @fclose($worker);
            }
            @fclose($lock);
        }
    }
    public function cancel(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \RuntimeException('invalid_job');
        }
        $lock = $this->lock('queue.lock', LOCK_EX | LOCK_NB);
        try {
            foreach ($this->reports() as $j) {
                if ($j['id'] !== $id) {
                    continue;
                }
                if ($j['status'] === 'queued') {
                    $j['status'] = 'cancelled';
                    $j['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                    $this->write('job-' . $id . '.json', $j);
                } elseif ($j['status'] === 'running') {
                    $this->write('cancel-' . $id, ['schema_version' => 1, 'id' => $id]);
                }
                return;
            }
            throw new \RuntimeException('invalid_job');
        } finally {
            @fclose($lock);
        }
    }
}
