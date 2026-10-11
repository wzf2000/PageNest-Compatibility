<?php
namespace PageNest\Tools;
defined('ABSPATH') || exit();

/** Server configuration is data, never executable PHP or a public endpoint. */
final class Profile
{
    private static ?array $data = null;
    private static bool $loaded = false;

    public static function ready(): bool
    {
        self::load();
        return self::$data !== null;
    }

    public static function get(string $key, $default = null)
    {
        self::load();
        $value = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        if (!defined('PAGENEST_COMPANION_TOOLS_FILE') && !defined('SITE_TOOLS_PROFILE_FILE')) {
            return;
        }
        try {
            $input = defined('PAGENEST_COMPANION_TOOLS_FILE')
                ? PAGENEST_COMPANION_TOOLS_FILE
                : SITE_TOOLS_PROFILE_FILE;
            $path = is_string($input) && !str_contains($input, '://') ? realpath($input) : false;
            $web = realpath(ABSPATH);
            if (
                !$path ||
                !$web ||
                !is_file($path) ||
                !is_readable($path) ||
                str_starts_with($path, rtrim($web, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) ||
                filesize($path) > 1048576
            ) {
                throw new \InvalidArgumentException('Profile location');
            }
            $data = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) ||
                ($data['schema_version'] ?? null) !== 1 ||
                array_diff(array_keys($data), [
                    'schema_version',
                    'allowed_hosts',
                    'catalog',
                    'shortcodes',
                    'blocks',
                    'rest_namespaces',
                    'games',
                    'maintenance',
                    'services',
                ])
            ) {
                throw new \InvalidArgumentException('Profile shape');
            }
            foreach (['allowed_hosts', 'shortcodes', 'blocks', 'rest_namespaces'] as $key) {
                if (
                    !is_array($data[$key] ?? null) ||
                    array_values($data[$key]) !== $data[$key] ||
                    ($key === 'allowed_hosts' && !$data[$key]) ||
                    count($data[$key]) > 32
                ) {
                    throw new \InvalidArgumentException('Profile aliases');
                }
                $pattern = match ($key) {
                    'allowed_hosts' => '/^[a-zA-Z0-9.-]+$/D',
                    'shortcodes' => '/^[a-zA-Z0-9_]+$/D',
                    'blocks' => '~^[a-z0-9-]+/[a-z0-9-]+$~D',
                    default => '~^[a-zA-Z0-9_-]+/v[0-9]+$~D',
                };
                foreach ($data[$key] as $value) {
                    if (!is_string($value) || !preg_match($pattern, $value)) {
                        throw new \InvalidArgumentException('Profile alias');
                    }
                }
            }
            if (!is_array($data['catalog'] ?? null)) {
                throw new \InvalidArgumentException('Profile payload');
            }
            Providers::validateProfile($data);
            if (isset($data['maintenance'])) {
                $maintenance = $data['maintenance'];
                if (
                    !is_array($maintenance) ||
                    array_diff(array_keys($maintenance), [
                        'source_root',
                        'state_root',
                        'slug_meta',
                        'user_id',
                    ]) ||
                    !is_int($maintenance['user_id'] ?? null) ||
                    $maintenance['user_id'] < 1 ||
                    !is_string($maintenance['slug_meta'] ?? null) ||
                    !preg_match('/^[a-zA-Z0-9_]+$/D', $maintenance['slug_meta'])
                ) {
                    throw new \InvalidArgumentException('Maintenance configuration');
                }
                foreach (['source_root', 'state_root'] as $key) {
                    $value = $maintenance[$key] ?? null;
                    if (
                        !is_string($value) ||
                        !str_starts_with($value, '/') ||
                        str_contains($value, '://') ||
                        in_array('..', explode('/', $value), true) ||
                        str_starts_with(
                            $value,
                            rtrim($web, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
                        )
                    ) {
                        throw new \InvalidArgumentException('Maintenance directory');
                    }
                }
            }
            self::$data = $data;
        } catch (\Throwable $error) {
            error_log('Site tools configuration rejected; tools are disabled.');
        }
    }
}
