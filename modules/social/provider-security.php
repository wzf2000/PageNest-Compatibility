<?php
/** PKCE and Google OIDC validation. No provider token is persisted. */
defined('ABSPATH') || exit();
require_once __DIR__ . '/google-proxy.php';
function pagenest_social_base64url($bytes)
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}
function pagenest_social_pkce_authorize($provider, $record)
{
    $verifier = $record['pkce_verifier'] ?? null;
    if (!is_string($verifier) || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)) {
        throw new RuntimeException('PKCE context unavailable.');
    }
    $params = [
        'code_challenge' => pagenest_social_base64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
    ];
    if ($provider === 'google') {
        if (
            !is_string($record['nonce'] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/D', $record['nonce'])
        ) {
            throw new RuntimeException('OIDC context unavailable.');
        }
        $params['nonce'] = $record['nonce'];
    }
    return $params;
}
function pagenest_social_json_request($url, $body = null, $headers = [])
{
    $response = pagenest_social_google_proxy_request($url, [
        'method' => $body === null ? 'GET' : 'POST',
        'body' => $body,
        'headers' => $headers + ['Accept' => 'application/json'],
        'timeout' => 15,
        'redirection' => 0,
        'sslverify' => true,
        'limit_response_size' => 65536,
    ]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        throw new RuntimeException('Provider request failed.');
    }
    $text = wp_remote_retrieve_body($response);
    $data = json_decode($text, true, 32, JSON_BIGINT_AS_STRING);
    if (strlen($text) > 65536 || !is_array($data) || !$data || isset($data['error'])) {
        throw new RuntimeException('Provider returned invalid JSON.');
    }
    return [$data, $response];
}
function pagenest_social_modern_exchange($provider, $config, $code, $record)
{
    pagenest_social_pkce_authorize($provider, $record);
    $urls = pagenest_social_endpoints($provider);
    [$token] = pagenest_social_json_request($urls['token'], [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri' => $config['redirect_uri'],
        'code_verifier' => $record['pkce_verifier'],
    ]);
    if ($provider === 'google') {
        $claims = pagenest_social_google_token(
            $token['id_token'] ?? null,
            $config['client_id'],
            $record['nonce'],
        );
        return [
            'subject' => $claims['sub'],
            'name' => sanitize_text_field(
                is_string($claims['name'] ?? null) ? $claims['name'] : 'Reader',
            ),
            'avatar' => pagenest_social_avatar_url($claims['picture'] ?? ''),
        ];
    }
    if (
        !is_string($token['access_token'] ?? null) ||
        !preg_match('/^[A-Za-z0-9_.~-]{1,4096}$/D', $token['access_token']) ||
        !is_string($token['token_type'] ?? null) ||
        strtolower($token['token_type'] ?? '') !== 'bearer'
    ) {
        throw new RuntimeException('Invalid access token.');
    }
    [$profile] = pagenest_social_json_request($urls['profile'], null, [
        'Authorization' => 'Bearer ' . $token['access_token'],
        'User-Agent' => 'PageNest-Companion',
        'X-GitHub-Api-Version' => '2022-11-28',
    ]);
    $subject = $profile['id'] ?? null;
    if (
        (!is_int($subject) && !is_string($subject)) ||
        !preg_match('/^[1-9][0-9]{0,39}$/D', (string) $subject)
    ) {
        throw new RuntimeException('Invalid GitHub subject.');
    }
    $name = $profile['name'] ?? ($profile['login'] ?? 'Reader');
    return [
        'subject' => (string) $subject,
        'name' => sanitize_text_field(is_string($name) ? $name : 'Reader'),
        'avatar' => pagenest_social_avatar_url($profile['avatar_url'] ?? ''),
    ];
}
function pagenest_social_google_certificates($refresh = false, &$fetched = false)
{
    $cache_key = 'pn_social_google_certificates';
    $cached = get_transient($cache_key);
    if (
        !$refresh &&
        is_array($cached) &&
        ($cached['expires'] ?? 0) > time() &&
        is_array($cached['certificates'] ?? null)
    ) {
        return $cached['certificates'];
    }
    // The same fixed X.509 endpoint is used by Google's official auth libraries.
    $fetched = true;
    [$certificates, $response] = pagenest_social_json_request(
        'https://www.googleapis.com/oauth2/v1/certs',
    );
    if (count($certificates) > 20) {
        throw new RuntimeException('Invalid certificate response.');
    }
    foreach ($certificates as $kid => $pem) {
        if (
            !preg_match('/^[A-Za-z0-9_-]{1,200}$/D', (string) $kid) ||
            !is_string($pem) ||
            strlen($pem) > 16384 ||
            !str_starts_with($pem, '-----BEGIN CERTIFICATE-----')
        ) {
            throw new RuntimeException('Invalid certificate response.');
        }
    }
    $control = (string) wp_remote_retrieve_header($response, 'cache-control');
    $age = (int) wp_remote_retrieve_header($response, 'age');
    $ttl = preg_match('/(?:^|,)\s*max-age=(\d+)/i', $control, $match)
        ? max(0, min(3600, (int) $match[1] - max(0, $age)))
        : 0;
    if ($ttl > 0 && !preg_match('/(?:no-store|no-cache)/i', $control)) {
        set_transient(
            $cache_key,
            ['expires' => time() + $ttl, 'certificates' => $certificates],
            $ttl,
        );
    } else {
        delete_transient($cache_key);
    }
    return $certificates;
}
function pagenest_social_jwt_part($part)
{
    if (!is_string($part) || !preg_match('/^[A-Za-z0-9_-]+$/D', $part)) {
        throw new RuntimeException('Invalid ID token encoding.');
    }
    $decoded = base64_decode(strtr($part, '-_', '+/'), true);
    if ($decoded === false || pagenest_social_base64url($decoded) !== $part) {
        throw new RuntimeException('Invalid ID token encoding.');
    }
    return $decoded;
}
function pagenest_social_google_token($token, $client_id, $nonce)
{
    if (
        !is_string($token) ||
        strlen($token) > 32768 ||
        count($parts = explode('.', $token)) !== 3 ||
        !is_string($nonce) ||
        !preg_match('/^[a-f0-9]{64}$/D', $nonce)
    ) {
        throw new RuntimeException('Invalid ID token.');
    }
    $header = json_decode(pagenest_social_jwt_part($parts[0]), true, 16);
    $claims = json_decode(pagenest_social_jwt_part($parts[1]), true, 16);
    $signature = pagenest_social_jwt_part($parts[2]);
    if (
        !is_array($header) ||
        !is_array($claims) ||
        ($header['alg'] ?? '') !== 'RS256' ||
        !is_string($header['kid'] ?? null) ||
        !preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $header['kid']) ||
        isset($header['crit']) ||
        isset($header['jku']) ||
        isset($header['jwk']) ||
        isset($header['x5u']) ||
        (isset($header['typ']) && $header['typ'] !== 'JWT')
    ) {
        throw new RuntimeException('Unsupported ID token header.');
    }
    $fetched = false;
    $certificates = pagenest_social_google_certificates(false, $fetched);
    if (!isset($certificates[$header['kid']]) && !$fetched) {
        $certificates = pagenest_social_google_certificates(true);
    }
    $pem = $certificates[$header['kid']] ?? '';
    $certificate = $pem ? @openssl_x509_read($pem) : false;
    $certificate_info = $certificate ? openssl_x509_parse($certificate) : false;
    $public = $certificate ? openssl_pkey_get_public($certificate) : false;
    $key_info = $public ? openssl_pkey_get_details($public) : false;
    if (
        !$certificate_info ||
        ($certificate_info['validFrom_time_t'] ?? PHP_INT_MAX) > time() ||
        ($certificate_info['validTo_time_t'] ?? 0) <= time() ||
        !$key_info ||
        $key_info['type'] !== OPENSSL_KEYTYPE_RSA ||
        $key_info['bits'] < 2048 ||
        openssl_verify($parts[0] . '.' . $parts[1], $signature, $public, OPENSSL_ALGO_SHA256) !== 1
    ) {
        throw new RuntimeException('ID token signature rejected.');
    }
    $audience = $claims['aud'] ?? null;
    $audiences = is_string($audience) ? [$audience] : $audience;
    if (
        !in_array(
            $claims['iss'] ?? null,
            ['https://accounts.google.com', 'accounts.google.com'],
            true,
        ) ||
        !is_array($audiences) ||
        !$audiences ||
        count($audiences) > 10 ||
        count(array_filter($audiences, 'is_string')) !== count($audiences) ||
        !in_array($client_id, $audiences, true) ||
        (count($audiences) > 1 && !isset($claims['azp'])) ||
        (isset($claims['azp']) && $claims['azp'] !== $client_id) ||
        !is_int($claims['exp'] ?? null) ||
        !is_int($claims['iat'] ?? null) ||
        $claims['exp'] <= time() ||
        $claims['iat'] > time() + 60 ||
        $claims['iat'] < time() - 7200 ||
        $claims['exp'] <= $claims['iat'] ||
        $claims['exp'] - $claims['iat'] > 7200 ||
        (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > time() + 60)) ||
        !is_string($claims['nonce'] ?? null) ||
        !hash_equals($nonce, $claims['nonce']) ||
        !is_string($claims['sub'] ?? null) ||
        !preg_match('/^[\x21-\x7e]{1,255}$/D', $claims['sub'])
    ) {
        throw new RuntimeException('ID token claims rejected.');
    }
    return $claims;
}

function pagenest_social_wechat_exchange($config, $code)
{
    $urls = pagenest_social_endpoints('wechat');
    [$token] = pagenest_social_json_request(
        pagenest_social_query_url(
            [
                'appid' => $config['client_id'],
                'secret' => $config['client_secret'],
                'code' => $code,
                'grant_type' => 'authorization_code',
            ],
            $urls['token'],
        ),
    );
    if (
        !empty($token['errcode']) ||
        !is_string($token['access_token'] ?? null) ||
        !preg_match('/^[A-Za-z0-9_.~-]{1,4096}$/D', $token['access_token']) ||
        !is_string($token['openid'] ?? null) ||
        !preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $token['openid']) ||
        !is_string($token['scope'] ?? null) ||
        !in_array('snsapi_login', explode(',', $token['scope']), true)
    ) {
        throw new RuntimeException('Invalid WeChat login grant.');
    }
    [$profile] = pagenest_social_json_request(
        pagenest_social_query_url(
            [
                'access_token' => $token['access_token'],
                'openid' => $token['openid'],
                'lang' => 'zh_CN',
            ],
            $urls['profile'],
        ),
    );
    if (!empty($profile['errcode']) || ($profile['openid'] ?? null) !== $token['openid']) {
        throw new RuntimeException('WeChat subject mismatch.');
    }
    // OpenID belongs to this application. UnionID never merges local accounts.
    return [
        'subject' => $token['openid'],
        'name' => sanitize_text_field(
            is_string($profile['nickname'] ?? null) ? $profile['nickname'] : 'Reader',
        ),
        'avatar' => pagenest_social_avatar_url($profile['headimgurl'] ?? ''),
    ];
}
