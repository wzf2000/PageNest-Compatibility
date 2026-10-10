<?php
/** Optional services use a separate, explicitly configured private profile. */
defined('ABSPATH') || exit();
function pagenest_companion_service_defaults()
{
    return [
        'mail' => [
            'enabled' => false,
            'provider' => 'brevo',
            'api_key' => '',
            'from_email' => '',
            'from_name' => '',
            'reply_to_email' => '',
            'reply_to_name' => '',
        ],
        'turnstile' => [
            'enabled' => false,
            'site_key' => '',
            'secret_key' => '',
            'hostname' => '',
            'scopes' => ['login' => true, 'register' => true, 'reset' => true, 'comments' => true],
            'theme' => 'auto',
            'language' => 'zh-cn',
            'size' => 'normal',
            'appearance' => 'always',
        ],
        'social' => [
            'enabled' => false,
            'allow_registration' => false,
            'avatar_enabled' => true,
            'share' => [
                'enabled' => false,
                'posts' => true,
                'pages' => false,
                'providers' => ['qq', 'weibo', 'qqzone', 'wechat'],
            ],
            'providers' => [],
            'legacy_apps' => [],
            'google_proxy' => ['enabled' => false, 'unix_socket' => ''],
        ],
        'output' => ['enabled' => false, 'html_whitespace' => false],
    ];
}
function pagenest_companion_service_values($input, $defaults)
{
    if (!is_array($input) || array_diff(array_keys($input), array_keys($defaults))) {
        throw new InvalidArgumentException('Unknown service configuration key.');
    }
    foreach ($input as $key => $value) {
        $default = $defaults[$key];
        if (is_bool($default)) {
            if (!is_bool($value)) {
                throw new InvalidArgumentException('Service switches must be booleans.');
            }
        } elseif (is_string($default)) {
            if (
                !is_string($value) ||
                strlen($value) > 4096 ||
                preg_match('/[\\x00-\\x1f\\x7f]/', $value)
            ) {
                throw new InvalidArgumentException('Invalid service configuration string.');
            }
        } elseif (!is_array($value)) {
            throw new InvalidArgumentException('Invalid service configuration collection.');
        }
        $defaults[$key] = $value;
    }
    return $defaults;
}
function pagenest_companion_validate_services($input)
{
    $defaults = pagenest_companion_service_defaults();
    if (!is_array($input) || array_diff(array_keys($input), array_keys($defaults))) {
        throw new InvalidArgumentException('Unknown optional service.');
    }
    foreach ($input as $name => $values) {
        $defaults[$name] = pagenest_companion_service_values($values, $defaults[$name]);
    }
    $captcha = &$defaults['turnstile'];
    $captcha['scopes'] = pagenest_companion_service_values(
        $captcha['scopes'],
        pagenest_companion_service_defaults()['turnstile']['scopes'],
    );
    foreach (
        [
            'theme' => ['auto', 'light', 'dark'],
            'size' => ['normal', 'compact', 'flexible'],
            'appearance' => ['always', 'execute', 'interaction-only'],
        ]
        as $key => $choices
    ) {
        if (!in_array($captcha[$key], $choices, true)) {
            throw new InvalidArgumentException('Invalid Turnstile display setting.');
        }
    }
    if ($captcha['hostname'] !== '' && !preg_match('/^[a-zA-Z0-9.-]+$/D', $captcha['hostname'])) {
        throw new InvalidArgumentException('Invalid Turnstile hostname.');
    }
    if (!preg_match('/^(?:auto|[a-z]{2}(?:-[A-Za-z]{2})?)$/D', $captcha['language'])) {
        throw new InvalidArgumentException('Invalid Turnstile language.');
    }
    if (
        $captcha['enabled'] &&
        ($captcha['site_key'] === '' ||
            $captcha['secret_key'] === '' ||
            $captcha['hostname'] === '')
    ) {
        throw new InvalidArgumentException('Incomplete Turnstile service.');
    }
    $mail = $defaults['mail'];
    if ($mail['provider'] !== 'brevo') {
        throw new InvalidArgumentException('Unsupported mail provider.');
    }
    foreach (['from_email', 'reply_to_email'] as $key) {
        if ($mail[$key] !== '' && !filter_var($mail[$key], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid configured mail address.');
        }
    }
    if (
        $mail['enabled'] &&
        ($mail['api_key'] === '' || $mail['from_email'] === '' || $mail['from_name'] === '')
    ) {
        throw new InvalidArgumentException('Incomplete mail service.');
    }
    $social = &$defaults['social'];
    $social['google_proxy'] = pagenest_companion_service_values(
        $social['google_proxy'],
        pagenest_companion_service_defaults()['social']['google_proxy'],
    );
    $socket = $social['google_proxy']['unix_socket'];
    if (
        ($socket !== '' &&
            (!preg_match('#^/[A-Za-z0-9_./-]+$#D', $socket) ||
                strlen($socket) > 107 ||
                preg_match('#(?:^|/)\.\.(?:/|$)#', $socket))) ||
        ($social['google_proxy']['enabled'] && $socket === '')
    ) {
        throw new InvalidArgumentException('Invalid Google proxy socket configuration.');
    }
    $social['share'] = pagenest_companion_service_values(
        $social['share'],
        pagenest_companion_service_defaults()['social']['share'],
    );
    if (
        array_values($social['share']['providers']) !== $social['share']['providers'] ||
        array_diff($social['share']['providers'], ['qq', 'weibo', 'qqzone', 'wechat'])
    ) {
        throw new InvalidArgumentException('Invalid social share provider.');
    }
    $provider_defaults = [
        'enabled' => false,
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => '',
        'legacy_app_id' => '',
    ];
    foreach ($social['providers'] as $provider => $values) {
        if (!in_array($provider, ['qq', 'sina', 'baidu', 'github', 'google', 'wechat'], true)) {
            throw new InvalidArgumentException('Unsupported social provider.');
        }
        $values = pagenest_companion_service_values($values, $provider_defaults);
        if (
            in_array($provider, ['github', 'google', 'wechat'], true) &&
            $values['legacy_app_id'] !== ''
        ) {
            throw new InvalidArgumentException(
                'New social providers cannot claim legacy identities.',
            );
        }
        if (
            in_array($provider, ['github', 'google', 'wechat'], true) &&
            $values['client_id'] !== '' &&
            ($social['legacy_apps'][$provider] ?? '') === $values['client_id']
        ) {
            throw new InvalidArgumentException(
                'New social application must use a distinct legacy scope.',
            );
        }
        if ($values['redirect_uri'] !== '') {
            $url = parse_url($values['redirect_uri']);
            if (
                !$url ||
                !in_array($url['scheme'] ?? '', ['http', 'https'], true) ||
                empty($url['host']) ||
                isset($url['user']) ||
                isset($url['pass']) ||
                isset($url['fragment'])
            ) {
                throw new InvalidArgumentException('Invalid social callback URL.');
            }
        }
        if (
            $values['enabled'] &&
            ($values['client_id'] === '' ||
                $values['client_secret'] === '' ||
                $values['redirect_uri'] === '')
        ) {
            throw new InvalidArgumentException('Incomplete social provider.');
        }
        $social['providers'][$provider] = $values;
    }
    foreach ($social['legacy_apps'] as $provider => $app) {
        if (
            !preg_match('/^[a-z0-9_-]+$/D', (string) $provider) ||
            !is_string($app) ||
            $app === '' ||
            strlen($app) > 200 ||
            preg_match('/[\\x00-\\x20\\x7f]/', $app)
        ) {
            throw new InvalidArgumentException('Invalid legacy application scope.');
        }
    }
    if (
        $social['enabled'] &&
        !array_filter($social['providers'], static fn($provider) => $provider['enabled'])
    ) {
        throw new InvalidArgumentException('No enabled social provider.');
    }
    return $defaults;
}
function pagenest_companion_load_services($path, $web_root)
{
    $real = realpath($path);
    $root = realpath($web_root);
    if (
        !$real ||
        !$root ||
        !is_file($real) ||
        !is_readable($real) ||
        $real === $root ||
        str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) ||
        filesize($real) > 1048576
    ) {
        throw new InvalidArgumentException(
            'Service profile must be readable outside the web root.',
        );
    }
    $input = json_decode(file_get_contents($real), true, 32, JSON_THROW_ON_ERROR);
    if (
        !is_array($input) ||
        ($input['schema_version'] ?? null) !== 1 ||
        array_diff(array_keys($input), ['schema_version', 'services']) ||
        !isset($input['services'])
    ) {
        throw new InvalidArgumentException('Invalid service profile schema.');
    }
    return pagenest_companion_validate_services($input['services']);
}
function pagenest_companion_service_config($name)
{
    $services =
        $GLOBALS['pagenest_companion_services_profile'] ?? pagenest_companion_service_defaults();
    return $services[$name] ?? ['enabled' => false];
}
