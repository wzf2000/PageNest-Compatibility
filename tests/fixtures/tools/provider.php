<?php
namespace CompanionToolFixture;
final class Implementations
{
    public static function register(string $pack, string $root, string $url): void
    {
        $handlers =
            $pack === 'basic'
                ? ['text-count', 'random-number']
                : ($pack === 'games'
                    ? ['game', 'ranking']
                    : ['personal-drafts', 'local-jobs']);
        $definition = [
            'root' => $root . '/assets',
            'url' => $url . '/assets',
            'manifest' => [
                'tools.css' => 'tools.css',
                'text-count.js' => 'text-count.js',
                'random-number.js' => 'random-number.js',
            ],
            'handlers' => [],
        ];
        foreach ($handlers as $name) {
            $definition['handlers'][$name] = [
                'validate' => static function ($tool): void {
                    if ($tool['service'] !== null) {
                        throw new \InvalidArgumentException('Example browser service');
                    }
                },
                'render' => static fn() => '<textarea data-tool="' . $name . '"></textarea>',
            ];
        }
        $definition['validate_profile'] = static function ($data) use ($pack): void {
            if (
                ($pack === 'games' && !is_string($data['games']['runs_table'] ?? null)) ||
                ($pack === 'private' && ($data['services']['drafts_table'] ?? '') === 'users')
            ) {
                throw new \InvalidArgumentException('Example provider rejects configuration');
            }
        };
        $definition['start'] = static function () use ($pack): void {
            $GLOBALS['fixture_started'][] = $pack;
        };
        \PageNest\Tools\Providers::register('pagenest-' . $pack, $definition);
    }
}
