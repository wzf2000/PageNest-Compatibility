<?php
namespace PageNest\Tools;
defined('ABSPATH') || exit();
final class Registry
{
    private static array $tools = [];
    private static array $categories = [];
    private static bool $healthy = false;
    public static function boot(): void
    {
        try {
            self::load(Profile::get('catalog', []));
        } catch (\Throwable $e) {
            self::$tools = [];
            self::$healthy = false;
            error_log('site-tools catalog invalid');
        }
    }
    /** Also used by private CLI preflight; never exposed as an HTTP configuration endpoint. */
    public static function load(array $catalog): void
    {
        self::$tools = [];
        self::$healthy = false;
        if (
            ($catalog['schema_version'] ?? null) !== 1 ||
            !is_array($catalog['categories'] ?? null) ||
            !is_array($catalog['tools'] ?? null)
        ) {
            throw new \InvalidArgumentException('Catalog shape');
        }
        self::$categories = $catalog['categories'];
        foreach (self::$categories as $category) {
            if (!is_string($category) || $category === '') {
                throw new \InvalidArgumentException('Category');
            }
        }
        foreach ($catalog['tools'] as $tool) {
            self::register($tool);
        }
        self::$healthy = true;
    }
    public static function register(array $t): void
    {
        foreach (
            [
                'schema_version',
                'slug',
                'kind',
                'title',
                'description',
                'category',
                'version',
                'status',
                'binding',
                'listed',
                'access',
                'assets',
                'input_schema',
                'render',
                'service',
                'data',
                'limits',
                'owner',
                'maintenance',
            ]
            as $key
        ) {
            if (!array_key_exists($key, $t)) {
                throw new \InvalidArgumentException('Missing field ' . $key);
            }
        }
        if (
            $t['schema_version'] !== 1 ||
            !is_string($t['slug']) ||
            !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $t['slug']) ||
            isset(self::$tools[$t['slug']])
        ) {
            throw new \InvalidArgumentException('Invalid or duplicate slug');
        }
        foreach (
            ['title', 'description', 'category', 'version', 'status', 'owner', 'maintenance']
            as $key
        ) {
            if (!is_string($t[$key])) {
                throw new \InvalidArgumentException('Text field');
            }
        }
        if (
            !in_array($t['category'], self::$categories, true) ||
            !in_array($t['status'], ['experimental', 'active', 'disabled', 'retired'], true) ||
            !is_bool($t['listed'])
        ) {
            throw new \InvalidArgumentException('Category/status/listing');
        }
        $mode = $t['access']['mode'] ?? '';
        if (
            !in_array($mode, ['public', 'authenticated', 'capability'], true) ||
            ($mode === 'capability' &&
                (!is_string($t['access']['capability'] ?? null) ||
                    $t['access']['capability'] === ''))
        ) {
            throw new \InvalidArgumentException('Access');
        }
        if (!is_array($t['assets']) || !is_array($t['legacy_styles'] ?? [])) {
            throw new \InvalidArgumentException('Assets');
        }
        if ($t['kind'] === 'external') {
            $url = $t['canonical_url'] ?? '';
            $u = is_string($url) ? wp_parse_url($url) : false;
            if (
                !$u ||
                ($u['scheme'] ?? '') !== 'https' ||
                empty($u['host']) ||
                isset($u['user']) ||
                isset($u['pass']) ||
                $t['binding'] !== null ||
                $t['render'] !== null ||
                $t['service'] !== null ||
                $t['assets'] ||
                ($t['legacy_styles'] ?? [])
            ) {
                throw new \InvalidArgumentException('External link');
            }
        } elseif ($t['kind'] === 'internal') {
            $b = $t['binding'];
            if (
                !is_array($b) ||
                !is_int($b['post_id'] ?? null) ||
                $b['post_id'] < 0 ||
                !is_string($b['post_name'] ?? null)
            ) {
                throw new \InvalidArgumentException('Binding');
            }
            foreach (self::$tools as $other) {
                if ($b['post_id'] > 0 && ($other['binding']['post_id'] ?? null) === $b['post_id']) {
                    throw new \InvalidArgumentException('Duplicate page binding');
                }
            }
            if (!is_string($t['render']) || !preg_match('/^[a-z0-9-]+$/D', $t['render'])) {
                throw new \InvalidArgumentException('Handler identifier');
            }
            if (
                !is_null($t['service']) &&
                (!is_string($t['service']) || !preg_match('/^[a-z0-9-]+$/D', $t['service']))
            ) {
                throw new \InvalidArgumentException('Service identifier');
            }
            if (
                isset($t['source']) &&
                (!is_string($t['source']) ||
                    !preg_match('~^content/[a-zA-Z0-9-]+\.md$~D', $t['source']))
            ) {
                throw new \InvalidArgumentException('Source');
            }
            if (Providers::has($t['render'])) {
                Providers::validateTool($t);
            }
            $t['canonical_url'] = $b['post_id'] ? (get_permalink($b['post_id']) ?: '') : '';
        } else {
            throw new \InvalidArgumentException('Entry kind');
        }
        foreach ($t['assets'] as $asset) {
            if (!is_string($asset) || !preg_match('/^[a-z0-9.-]+\.(?:js|css)$/D', $asset)) {
                throw new \InvalidArgumentException('Asset identifier');
            }
            if ($t['kind'] === 'internal' && Providers::has($t['render'])) {
                try {
                    Providers::asset($t['render'], $asset);
                } catch (\Throwable $error) {
                    throw new \InvalidArgumentException('Asset file', 0, $error);
                }
            }
        }
        foreach ($t['legacy_styles'] ?? [] as $style) {
            if (!is_string($style) || !preg_match('~^/2048/[a-z0-9_.-]+\.css$~D', $style)) {
                throw new \InvalidArgumentException('Legacy stylesheet');
            }
        }
        if (isset($t['template'])) {
            throw new \InvalidArgumentException('Templates must be selected by trusted providers');
        }
        self::$tools[$t['slug']] = $t;
    }
    public static function healthy(): bool
    {
        return self::$healthy;
    }
    public static function all(): array
    {
        return array_values(self::$tools);
    }
    public static function categories(): array
    {
        return self::$categories;
    }
    public static function get(string $slug): ?array
    {
        return self::$tools[$slug] ?? null;
    }
    public static function allowed(array $t): bool
    {
        if ($t['kind'] === 'internal' && !Providers::has($t['render'])) {
            return false;
        }
        if (!in_array($t['status'], ['active', 'experimental'], true)) {
            return false;
        }
        return match ($t['access']['mode']) {
            'public' => true,
            'authenticated' => is_user_logged_in() && current_user_can('read'),
            'capability' => is_user_logged_in() && current_user_can($t['access']['capability']),
            default => false,
        };
    }
    public static function pageAllowed(string $slug, int $id): bool
    {
        $t = self::get($slug);
        if (!$t || $t['kind'] !== 'internal' || $id < 1 || $t['binding']['post_id'] !== $id) {
            return false;
        }
        $p = get_post($id);
        return $p && $p->post_type === 'page' && $p->post_name === $t['binding']['post_name'];
    }
    public static function forPage(int $id): ?array
    {
        foreach (self::$tools as $t) {
            if (self::pageAllowed($t['slug'], $id)) {
                return $t;
            }
        }
        return null;
    }
    public static function serviceAllowed(string $slug): bool
    {
        $t = self::get($slug);
        if (!$t || !self::allowed($t) || !self::pageAllowed($slug, $t['binding']['post_id'] ?? 0)) {
            return false;
        }
        $p = get_post($t['binding']['post_id']);
        return in_array($p->post_status, ['publish', 'private'], true) &&
            current_user_can('read_post', $p->ID) &&
            !post_password_required($p);
    }
    /** Public index contract: public listing is independent of the CLI administrator's permissions. */
    public static function indexEntries(): array
    {
        if (!self::$healthy) {
            throw new \RuntimeException('Catalog unavailable; keep existing index');
        }
        $out = [];
        foreach (self::$tools as $t) {
            if (
                ($t['kind'] === 'internal' && !Providers::has($t['render'])) ||
                !$t['listed'] ||
                $t['status'] !== 'active' ||
                $t['access']['mode'] !== 'public'
            ) {
                continue;
            }
            if ($t['kind'] === 'internal') {
                $id = $t['binding']['post_id'];
                $p = get_post($id);
                if (
                    !self::pageAllowed($t['slug'], $id) ||
                    !$p ||
                    $p->post_status !== 'publish' ||
                    $p->post_password !== ''
                ) {
                    continue;
                }
            }
            $out[] = $t;
        }
        return $out;
    }
    public static function externalUrlAllowed(string $url): bool
    {
        foreach (self::indexEntries() as $t) {
            if ($t['kind'] === 'external' && $t['canonical_url'] === $url) {
                return true;
            }
        }
        return false;
    }
    public static function visible(): array
    {
        $out = [];
        foreach (self::$tools as $t) {
            if (self::allowed($t)) {
                $item = array_intersect_key(
                    $t,
                    array_flip([
                        'slug',
                        'title',
                        'description',
                        'category',
                        'version',
                        'status',
                        'canonical_url',
                        'kind',
                    ]),
                );
                $item['access_mode'] = $t['access']['mode'];
                $out[] = $item;
            }
        }
        return $out;
    }
}
