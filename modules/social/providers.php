<?php
/** Fixed provider endpoints; never accept endpoints supplied by a browser. */
defined('ABSPATH') || exit();
require_once __DIR__ . '/provider-security.php';
function pagenest_social_endpoints($provider)
{
    return [
        'qq' => [
            'authorize' => 'https://graph.qq.com/oauth2.0/authorize',
            'token' => 'https://graph.qq.com/oauth2.0/token',
            'subject' => 'https://graph.qq.com/oauth2.0/me',
            'profile' => 'https://graph.qq.com/user/get_user_info',
        ],
        'sina' => [
            'authorize' => 'https://api.weibo.com/oauth2/authorize',
            'token' => 'https://api.weibo.com/oauth2/access_token',
            'profile' => 'https://api.weibo.com/2/users/show.json',
        ],
        'baidu' => [
            'authorize' => 'https://openapi.baidu.com/oauth/2.0/authorize',
            'token' => 'https://openapi.baidu.com/oauth/2.0/token',
            'profile' => 'https://openapi.baidu.com/rest/2.0/passport/users/getLoggedInUser',
        ],
        'github' => [
            'authorize' => 'https://github.com/login/oauth/authorize',
            'token' => 'https://github.com/login/oauth/access_token',
            'profile' => 'https://api.github.com/user',
        ],
        'google' => [
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
        ],
        'wechat' => [
            'authorize' => 'https://open.weixin.qq.com/connect/qrconnect',
            'token' => 'https://api.weixin.qq.com/sns/oauth2/access_token',
            'profile' => 'https://api.weixin.qq.com/sns/userinfo',
        ],
    ][$provider] ?? null;
}
function pagenest_social_query_url($params, $url)
{
    // These are fixed provider/admin endpoints. Encode every new scalar value exactly once.
    $separator = !str_contains($url, '?')
        ? '?'
        : (str_ends_with($url, '?') || str_ends_with($url, '&')
            ? ''
            : '&');
    return $url . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}
function pagenest_social_http($url, $params, $post = false)
{
    $args = [
        'timeout' => 15,
        'redirection' => 0,
        'sslverify' => true,
        'limit_response_size' => 65536,
    ];
    if ($post) {
        $args['method'] = 'POST';
        $args['body'] = $params;
    } else {
        $url = pagenest_social_query_url($params, $url);
    }
    $response = wp_remote_request($url, $args);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        throw new RuntimeException('Provider request failed.');
    }
    $body = wp_remote_retrieve_body($response);
    if (preg_match('/^\s*callback\s*\(\s*(\{.*\})\s*\)\s*;?\s*$/s', $body, $match)) {
        $body = $match[1];
    }
    $data = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
    if (!is_array($data)) {
        parse_str($body, $data);
    }
    if (!$data || isset($data['error']) || isset($data['error_code']) || !empty($data['ret'])) {
        throw new RuntimeException('Provider rejected request.');
    }
    return $data;
}
function pagenest_social_authorize_url($provider, $config, $state, $record = [])
{
    $params = [
        'response_type' => 'code',
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'state' => $state,
    ];
    if ($provider === 'wechat') {
        unset($params['client_id']);
        $params['appid'] = $config['client_id'];
        $params['scope'] = 'snsapi_login';
        return pagenest_social_query_url(
            $params,
            pagenest_social_endpoints($provider)['authorize'],
        ) . '#wechat_redirect';
    }
    if ($provider === 'qq') {
        $params['scope'] = 'get_user_info';
    }
    if ($provider === 'baidu') {
        $params['scope'] = 'basic';
    }
    if (in_array($provider, ['github', 'google'], true)) {
        $params += pagenest_social_pkce_authorize($provider, $record);
        $params['scope'] = $provider === 'google' ? 'openid profile' : 'read:user';
    }
    return pagenest_social_query_url($params, pagenest_social_endpoints($provider)['authorize']);
}
function pagenest_social_exchange($provider, $config, $code, $record = [])
{
    if ($provider === 'wechat') {
        return pagenest_social_wechat_exchange($config, $code);
    }
    if (in_array($provider, ['github', 'google'], true)) {
        return pagenest_social_modern_exchange($provider, $config, $code, $record);
    }
    $urls = pagenest_social_endpoints($provider);
    $token = pagenest_social_http(
        $urls['token'],
        [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect_uri'],
        ],
        $provider !== 'qq',
    );
    if (empty($token['access_token']) || !is_string($token['access_token'])) {
        throw new RuntimeException('Missing access token.');
    }
    $params = ['access_token' => $token['access_token']];
    if ($provider === 'qq') {
        $identity = pagenest_social_http($urls['subject'], $params);
        if ((string) ($identity['client_id'] ?? '') !== $config['client_id']) {
            throw new RuntimeException('Application mismatch.');
        }
        $subject = $identity['openid'] ?? '';
        $params += ['oauth_consumer_key' => $config['client_id'], 'openid' => $subject];
    } elseif ($provider === 'sina') {
        $subject = $token['uid'] ?? '';
        $params['uid'] = $subject;
    }
    $profile = pagenest_social_http($urls['profile'], $params);
    if ($provider === 'baidu') {
        $subject = $profile['uid'] ?? '';
    }
    if (
        $provider === 'sina' &&
        (string) ($profile['idstr'] ?? ($profile['id'] ?? '')) !== (string) $subject
    ) {
        throw new RuntimeException('Subject mismatch.');
    }
    $subject = (string) $subject;
    if (!preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $subject)) {
        throw new RuntimeException('Invalid subject.');
    }
    $avatar =
        $provider === 'baidu'
            ? 'https://himg.bdimg.com/sys/portrait/item/' .
                rawurlencode($profile['portrait'] ?? '') .
                '.jpg'
            : ($provider === 'qq'
                ? $profile['figureurl_qq_2'] ?? ($profile['figureurl_qq_1'] ?? '')
                : $profile['avatar_large'] ?? ($profile['profile_image_url'] ?? ''));
    return [
        'subject' => $subject,
        'name' => sanitize_text_field(
            $profile['nickname'] ?? ($profile['screen_name'] ?? ($profile['uname'] ?? 'Reader')),
        ),
        'avatar' => pagenest_social_avatar_url($avatar),
    ];
}
function pagenest_social_avatar_url($url)
{
    if (!is_string($url)) {
        return '';
    }
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }
    if (str_starts_with($url, 'http://')) {
        $url = 'https://' . substr($url, 7);
    }
    $parts = wp_parse_url($url);
    if (
        ($parts['scheme'] ?? '') !== 'https' ||
        empty($parts['host']) ||
        isset($parts['user']) ||
        isset($parts['pass']) ||
        isset($parts['port'])
    ) {
        return '';
    }
    $hosts = [
        'qlogo.cn',
        'qq.com',
        'sinaimg.cn',
        'sinaimg.com',
        'bdimg.com',
        'githubusercontent.com',
        'github.com',
        'gravatar.com',
        'googleusercontent.com',
        'qpic.cn',
    ];
    foreach ($hosts as $host) {
        if ($parts['host'] === $host || str_ends_with(strtolower($parts['host']), '.' . $host)) {
            return esc_url_raw($url);
        }
    }
    return '';
}
