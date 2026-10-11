<?php
namespace PageNest\Tools;
defined('ABSPATH') || exit();
final class Assets
{
    public static function url(string $file, ?string $handler = null): string
    {
        if ($handler !== null) {
            return Providers::asset($handler, $file);
        }
        if ($file !== 'classic-preview.js') {
            throw new \RuntimeException('Provider identifier required');
        }
        return plugins_url('assets/classic-preview.js', __FILE__);
    }
}
