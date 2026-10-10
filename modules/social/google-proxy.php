<?php
/** Server-only Google Unix SOCKS relay; never a general WordPress proxy. */
defined('ABSPATH') || exit();
function pagenest_social_google_proxy_request($url, $args)
{
    $targets = [
        'https://oauth2.googleapis.com/token',
        'https://www.googleapis.com/oauth2/v1/certs',
    ];
    $proxy = pagenest_social_config()['google_proxy'] ?? ['enabled' => false];
    if (!in_array($url, $targets, true) || ($proxy['enabled'] ?? false) !== true) {
        return wp_remote_request($url, $args);
    }
    $socket = is_string($proxy['unix_socket'] ?? null) ? realpath($proxy['unix_socket']) : false;
    $transport = class_exists('WpOrg\\Requests\\Transport\\Curl')
        ? 'WpOrg\\Requests\\Transport\\Curl'
        : (class_exists('Requests_Transport_cURL')
            ? 'Requests_Transport_cURL'
            : null);
    if (
        !$socket ||
        !preg_match('#^/[A-Za-z0-9_./-]+$#D', $socket) ||
        @filetype($socket) !== 'socket' ||
        !is_writable($socket) ||
        !function_exists('curl_setopt_array') ||
        !defined('CURLOPT_UNIX_SOCKET_PATH') ||
        !defined('CURL_VERSION_UNIX_SOCKETS') ||
        !(curl_version()['features'] & CURL_VERSION_UNIX_SOCKETS) ||
        curl_version()['version_number'] < 0x075400 ||
        !$transport
    ) {
        throw new RuntimeException('Google proxy transport is unavailable.');
    }
    $forced = false;
    $target_handle = null;
    $applied = false;
    $before = static function (&$request_url, &$headers, &$data, &$type, &$options) use (
        $url,
        $transport,
        &$forced,
    ) {
        if ($request_url !== $url) {
            return;
        }
        $options['transport'] = $transport;
        $options['follow_redirects'] = false;
        $options['redirects'] = 0;
        $options['verifyname'] = true;
        if (empty($options['verify'])) {
            $options['verify'] = true;
        }
        // Proxy hooks may already be registered by Requests. The final cURL
        // settings below replace them and clear their credentials.
        $options['proxy'] = false;
        foreach (array_keys($headers) as $name) {
            if (strtolower((string) $name) === 'proxy-authorization') {
                unset($headers[$name]);
            }
        }
        $forced = true;
    };
    $configure = static function ($handle) use ($socket, &$applied) {
        if (
            !curl_setopt_array($handle, [
                CURLOPT_PROXY => 'socks5h://localhost' . $socket,
                CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
                CURLOPT_HTTPPROXYTUNNEL => false,
                CURLOPT_UNIX_SOCKET_PATH => null,
                CURLOPT_NOPROXY => '',
                CURLOPT_PROXYAUTH => 0,
                CURLOPT_SOCKS5_AUTH => CURLAUTH_NONE,
                CURLOPT_PROXYUSERPWD => '',
                CURLOPT_PRE_PROXY => '',
                CURLOPT_PROXYHEADER => [],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
            ])
        ) {
            throw new RuntimeException('Unable to configure Google proxy transport.');
        }
        $applied = true;
    };
    $curl = static function ($handle, $request, $request_url) use (
        $url,
        &$target_handle,
        $configure,
    ) {
        if ($request_url === $url) {
            $target_handle = $handle;
            $configure($handle);
        }
    };
    $final_curl = static function ($handle) use (&$target_handle, $configure) {
        if ($target_handle !== null && $handle === $target_handle) {
            $configure($handle);
        }
    };
    add_action('requests-requests.before_request', $before, PHP_INT_MAX, 5);
    add_action('http_api_curl', $curl, PHP_INT_MAX, 3);
    add_action('requests-curl.before_send', $final_curl, PHP_INT_MAX, 1);
    try {
        $response = wp_remote_request($url, $args);
        if (!$forced || !$applied) {
            throw new RuntimeException('Google proxy transport was not used.');
        }
        return $response;
    } finally {
        remove_action('requests-requests.before_request', $before, PHP_INT_MAX);
        remove_action('http_api_curl', $curl, PHP_INT_MAX);
        remove_action('requests-curl.before_send', $final_curl, PHP_INT_MAX);
    }
}
