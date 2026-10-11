<?php
namespace PageNest\Tools;
defined('ABSPATH') || exit();
/** Read-only framework diagnostics usable with empty, external or browser-only compositions. */
final class Diagnostics
{
    public static function index(): array
    {
        return [
            'schema_version' => 1,
            'entries' => array_map(
                static fn($tool) => array_intersect_key(
                    $tool,
                    array_flip([
                        'slug',
                        'title',
                        'description',
                        'category',
                        'version',
                        'canonical_url',
                        'kind',
                    ]),
                ),
                Registry::indexEntries(),
            ),
        ];
    }

    public static function diagnose(): array
    {
        $providers = [];
        foreach (Providers::runtimes() as $id => $runtime) {
            $valid = true;
            foreach ($runtime['manifest'] as $logical => $built) {
                $file = $runtime['assets_root'] . '/' . $built;
                $valid = $valid && is_file($file) && is_readable($file);
            }
            $providers[$id] = [
                'version' => $runtime['version'],
                'enabled' => $runtime['enabled'],
                'assets_present' => $valid,
            ];
        }
        $unavailable = [];
        foreach (Registry::all() as $tool) {
            if ($tool['kind'] === 'internal' && !Providers::has($tool['render'])) {
                $unavailable[] = $tool['slug'];
            }
        }
        return [
            'schema_version' => 1,
            'version' => VERSION,
            'profile_ready' => Profile::ready(),
            'registry_healthy' => Registry::healthy(),
            'providers' => $providers,
            'unavailable_tools' => $unavailable,
            'deployment_verified' => false,
        ];
    }

    public static function preflight(): array
    {
        return self::diagnose();
    }
}
