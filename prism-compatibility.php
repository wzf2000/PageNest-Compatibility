<?php
defined('ABSPATH') || exit();

// Accept web URLs only; a filter may provide a custom component directory.
function pagenest_prism_web_url($url)
{
    if (!is_string($url) || $url === '' || preg_match('/[\x00-\x20\\\\]/', $url)) {
        return '';
    }
    $parts = parse_url($url);
    if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }
    if (isset($parts['scheme'])) {
        if (
            !in_array(strtolower($parts['scheme']), ['http', 'https'], true) ||
            empty($parts['host'])
        ) {
            return '';
        }
    } elseif (!str_starts_with($url, '/')) {
        return '';
    }
    $path = rawurldecode($parts['path'] ?? '');
    if (preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x20\\\\]~', $path)) {
        return '';
    }
    return preg_replace('/[?#].*$/', '', $url);
}

function pagenest_prism_resource_url($scripts, $handle)
{
    $src = $scripts->registered[$handle]->src ?? null;
    if (!is_string($src) || $src === '') {
        return '';
    }
    // WP_Scripts resolves relative registrations against base_url when printing.
    if (!str_starts_with($src, '/') && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $src)) {
        $src = rtrim($scripts->base_url ?? '', '/') . '/' . $src;
    }
    return pagenest_prism_web_url($src);
}

function pagenest_prism_components_path($scripts, $handle)
{
    $url = pagenest_prism_resource_url($scripts, $handle);
    if (preg_match('~/plugins/autoloader/prism-autoloader(?:\.min)?\.js$~i', $url)) {
        return preg_replace(
            '~/plugins/autoloader/prism-autoloader(?:\.min)?\.js$~i',
            '/components/',
            $url,
        );
    }
    // Custom autoloader locations can still use a standard Prism core dependency.
    foreach ($scripts->registered[$handle]->deps ?? [] as $dependency) {
        $core = pagenest_prism_resource_url($scripts, $dependency);
        if (preg_match('~/components/prism-core(?:\.min)?\.js$~i', $core)) {
            return preg_replace('~prism-core(?:\.min)?\.js$~i', '', $core);
        }
        if (preg_match('~/prism(?:\.min)?\.js$~i', $core)) {
            return preg_replace('~prism(?:\.min)?\.js$~i', 'components/', $core);
        }
    }
    return '';
}

function pagenest_configure_prism($scripts)
{
    // Registered assets may belong to inactive providers. Follow only the active
    // queue (including dependencies) and resources already selected or printed.
    $active = [];
    $pending = array_merge($scripts->queue ?? [], $scripts->to_do ?? [], $scripts->done ?? []);
    while ($pending !== []) {
        $handle = array_pop($pending);
        if (isset($active[$handle]) || !isset($scripts->registered[$handle])) {
            continue;
        }
        $active[$handle] = true;
        $pending = array_merge($pending, $scripts->registered[$handle]->deps);
    }
    if (isset($active['prism-core-js'])) {
        wp_add_inline_script(
            'prism-core-js',
            'if(window.Prism){Prism.languages.text = Prism.languages.plaintext = Prism.languages.plain = {};}',
            'after',
        );
    }
    foreach ($scripts->registered as $handle => $script) {
        if (!isset($active[$handle])) {
            continue;
        }
        $url = pagenest_prism_resource_url($scripts, $handle);
        if (
            $handle !== 'prism-plugin-autoloader' &&
            !preg_match('~/prism-autoloader(?:\.min)?\.js$~i', $url)
        ) {
            continue;
        }
        $path = pagenest_prism_components_path($scripts, $handle);
        /**
         * Override the detected component directory, or return an empty string to leave
         * the autoloader's own configuration intact. Receives path, handle and WP_Scripts.
         */
        $path = pagenest_prism_web_url(
            apply_filters('pagenest_prism_languages_path', $path, $handle, $scripts),
        );
        if ($path === '') {
            continue;
        }
        if (isset($active['prism-core-js']) && !in_array('prism-core-js', $script->deps, true)) {
            $script->deps[] = 'prism-core-js';
        }
        wp_add_inline_script(
            $handle,
            'if(window.Prism&&Prism.plugins&&Prism.plugins.autoloader){Prism.plugins.autoloader.languages_path = ' .
                wp_json_encode(rtrim($path, '/') . '/') .
                ';}',
            'after',
        );
    }
}
