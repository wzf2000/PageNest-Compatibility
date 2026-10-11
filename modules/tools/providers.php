<?php
namespace PageNest\Tools;
defined('ABSPATH') || exit();

/** Only installed, trusted PHP registers implementations. JSON contains identifiers only. */
final class Providers
{
    private static array $providers = [];
    private static array $handlers = [];
    private static array $disabled = [];
    private static bool $started = false;
    private static bool $strict = false;

    public static function strictProfiles(bool $strict): void
    {
        self::$strict = $strict;
    }

    public static function register(string $id, array $provider): void
    {
        if (self::$started || !preg_match('/^[a-z0-9-]+$/D', $id) || isset(self::$providers[$id])) {
            throw new \InvalidArgumentException('Duplicate or invalid provider');
        }
        foreach (['root', 'url', 'manifest', 'handlers'] as $key) {
            if (!array_key_exists($key, $provider)) {
                throw new \InvalidArgumentException('Incomplete provider');
            }
        }
        $root = is_string($provider['root']) ? realpath($provider['root']) : false;
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir()['basedir'] ?? '' : '';
        $uploads = $uploads ? realpath($uploads) : false;
        if (
            !$root ||
            !is_dir($root) ||
            is_link($provider['root']) ||
            !str_starts_with($provider['root'], '/') ||
            in_array('..', explode('/', $provider['root']), true) ||
            (is_string($provider['root']) && str_contains($provider['root'], '://')) ||
            ($uploads &&
                ($root === $uploads || str_starts_with($root, $uploads . DIRECTORY_SEPARATOR))) ||
            !is_string($provider['url']) ||
            !preg_match('~^https?://~D', $provider['url']) ||
            !is_array($provider['manifest']) ||
            !is_array($provider['handlers']) ||
            !$provider['handlers']
        ) {
            throw new \InvalidArgumentException('Provider asset root');
        }
        foreach (['validate_profile', 'start'] as $key) {
            if (isset($provider[$key]) && !is_callable($provider[$key])) {
                throw new \InvalidArgumentException('Provider callback');
            }
        }
        $provider['root'] = $root;
        foreach ($provider['manifest'] as $logical => $built) {
            self::assetPath($provider, $logical, $built);
        }
        foreach ($provider['handlers'] as $name => $handler) {
            if (
                !is_string($name) ||
                !preg_match('/^[a-z0-9-]+$/D', $name) ||
                isset(self::$handlers[$name]) ||
                !is_array($handler) ||
                !is_callable($handler['render'] ?? null) ||
                !is_callable($handler['validate'] ?? null)
            ) {
                throw new \InvalidArgumentException('Duplicate or invalid handler');
            }
            foreach (['assets', 'legacy_styles', 'archive'] as $key) {
                if (isset($handler[$key]) && !is_callable($handler[$key])) {
                    throw new \InvalidArgumentException('Handler callback');
                }
            }
        }
        // All validation finishes before mutating the registry.
        self::$providers[$id] = $provider;
        foreach ($provider['handlers'] as $name => $handler) {
            self::$handlers[$name] = ['provider' => $id, 'definition' => $handler];
        }
    }

    private static function assetPath(array $provider, $logical, $built): string
    {
        if (
            !is_string($logical) ||
            !is_string($built) ||
            !preg_match('/^[a-z0-9.-]+\.(?:js|css)$/D', $logical) ||
            !preg_match('/^[a-z0-9.-]+\.(?:js|css)$/D', $built) ||
            pathinfo($logical, PATHINFO_EXTENSION) !== pathinfo($built, PATHINFO_EXTENSION)
        ) {
            throw new \InvalidArgumentException('Manifest path');
        }
        $file = realpath($provider['root'] . '/' . $built);
        if (
            !$file ||
            !is_file($file) ||
            !str_starts_with($file, $provider['root'] . DIRECTORY_SEPARATOR)
        ) {
            throw new \InvalidArgumentException('Asset outside trusted root or missing');
        }
        return $file;
    }

    public static function validateProfile(array $data): void
    {
        foreach (self::$providers as $id => $provider) {
            try {
                if (isset($provider['validate_profile'])) {
                    $provider['validate_profile']($data);
                }
            } catch (\Throwable $error) {
                self::$disabled[$id] = true;
                if (self::$strict) {
                    throw $error;
                }
                error_log('PageNest tool provider configuration rejected: ' . $id);
            }
        }
    }

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;
        foreach (self::$providers as $id => $provider) {
            if (!isset(self::$disabled[$id]) && isset($provider['start'])) {
                try {
                    $provider['start']();
                } catch (\Throwable $error) {
                    self::$disabled[$id] = true;
                    error_log('PageNest tool provider unavailable: ' . $id);
                }
            }
        }
    }

    /** Trusted runtime identities for read-only package/resource diagnostics. */
    public static function runtimes(): array
    {
        $out = [];
        foreach (self::$providers as $id => $provider) {
            $out[$id] = [
                'root' => dirname($provider['root']),
                'assets_root' => $provider['root'],
                'manifest' => $provider['manifest'],
                'version' => $provider['version'] ?? VERSION,
                'enabled' => !isset(self::$disabled[$id]),
            ];
        }
        return $out;
    }

    public static function has(string $handler): bool
    {
        return isset(self::$handlers[$handler]) &&
            !isset(self::$disabled[self::$handlers[$handler]['provider']]);
    }

    private static function definition(string $handler): array
    {
        if (!self::has($handler)) {
            throw new \RuntimeException('Tool provider unavailable');
        }
        return self::$handlers[$handler]['definition'];
    }

    public static function identity(string $handler): string
    {
        self::definition($handler);
        return self::$handlers[$handler]['provider'];
    }

    public static function needsServiceAccess(string $handler): bool
    {
        return !empty(self::definition($handler)['requires_service_access']);
    }

    public static function validateTool(array $tool): void
    {
        self::definition($tool['render'])['validate']($tool);
    }

    public static function render(array $tool, bool $preview): string
    {
        return self::definition($tool['render'])['render']($tool, $preview);
    }

    public static function archive(array $tool): string
    {
        $definition = self::definition($tool['render']);
        return isset($definition['archive'])
            ? $definition['archive']($tool)
            : '<p class="site-tools-unavailable">此工具已暂停使用，已有数据保留。</p>';
    }

    public static function assets(array $tool): array
    {
        $definition = self::definition($tool['render']);
        return isset($definition['assets']) ? $definition['assets']($tool) : $tool['assets'];
    }

    public static function legacyStyles(array $tool): array
    {
        $definition = self::definition($tool['render']);
        return isset($definition['legacy_styles'])
            ? $definition['legacy_styles']($tool)
            : $tool['legacy_styles'] ?? [];
    }

    public static function asset(string $handler, string $file): string
    {
        self::definition($handler);
        $provider = self::$providers[self::$handlers[$handler]['provider']];
        if (!isset($provider['manifest'][$file])) {
            throw new \RuntimeException('Unregistered asset');
        }
        self::assetPath($provider, $file, $provider['manifest'][$file]);
        return rtrim($provider['url'], '/') . '/' . $provider['manifest'][$file];
    }
}
