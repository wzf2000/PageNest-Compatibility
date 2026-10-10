<?php
/** Included by social-contracts.php: synthetic certificates and mock HTTP only. */
defined('ABSPATH') || exit();
$modern_config = [
    'client_id' => 'synthetic-client',
    'client_secret' => 'synthetic-secret',
    'redirect_uri' => 'https://example.test/callback/',
];
$GLOBALS['current'] = 0;
foreach (['github', 'google'] as $modern_provider) {
    $modern_state = pagenest_social_state_create($modern_provider, $modern_config, 'login', '/');
    $modern_key = 'pagenest_social_state_' . hash('sha256', $modern_state);
    $modern_record = get_transient($modern_key);
    check(
        strlen($modern_record['pkce_verifier']) === 43,
        'PKCE verifier has 256-bit entropy encoding',
    );
    parse_str(
        parse_url(
            pagenest_social_authorize_url(
                $modern_provider,
                $modern_config,
                $modern_state,
                $modern_record,
            ),
            PHP_URL_QUERY,
        ),
        $modern_query,
    );
    check(
        $modern_query['code_challenge_method'] === 'S256' &&
            $modern_query['code_challenge'] ===
                pagenest_social_base64url(hash('sha256', $modern_record['pkce_verifier'], true)),
        'authorization includes matching S256 challenge',
    );
    check(
        !str_contains(
            pagenest_social_authorize_url(
                $modern_provider,
                $modern_config,
                $modern_state,
                $modern_record,
            ),
            $modern_record['pkce_verifier'],
        ),
        'authorization never exposes verifier',
    );
    if ($modern_provider === 'google') {
        check(
            $modern_query['nonce'] === $modern_record['nonce'] &&
                $modern_query['scope'] === 'openid profile',
            'Google has nonce and no email scope',
        );
    }
    $modern_record['browser'] = hash('sha256', 'synthetic-browser');
    $GLOBALS['options'][$modern_key] = $modern_record;
    check(
        pagenest_social_state_consume(
            $modern_state,
            'synthetic-browser',
            $modern_provider,
            $modern_config,
        ) === $modern_record,
        'state preserves PKCE nonce through single-use consumption',
    );
    rejects(
        static fn() => pagenest_social_state_consume(
            $modern_state,
            'synthetic-browser',
            $modern_provider,
            $modern_config,
        ),
        'new provider state cannot replay',
    );
}
$context = ['pkce_verifier' => str_repeat('v', 43), 'nonce' => str_repeat('a', 64)];
rejects(
    static fn() => pagenest_social_authorize_url('github', $modern_config, 'state'),
    'GitHub refuses missing PKCE',
);
rejects(
    static fn() => pagenest_social_exchange('google', $modern_config, 'code', [
        'pkce_verifier' => str_repeat('v', 43),
    ]),
    'Google refuses missing nonce before HTTP',
);
$GLOBALS['responses'] = [
    response('{"access_token":"synthetic-access","token_type":"bearer"}'),
    response(
        '{"id":123456789012345678901,"login":"renameable","name":"Synthetic","email":"ignored@example.test","avatar_url":"https://avatars.githubusercontent.com/avatar"}',
    ),
];
$GLOBALS['requests'] = [];
$github_identity = pagenest_social_exchange('github', $modern_config, 'synthetic-code', $context);
check(
    $github_identity['subject'] === '123456789012345678901' && !isset($github_identity['email']),
    'GitHub identity uses stable numeric ID without email',
);
check(
    $GLOBALS['requests'][0][0] === 'https://github.com/login/oauth/access_token' &&
        $GLOBALS['requests'][0][1]['method'] === 'POST' &&
        $GLOBALS['requests'][0][1]['headers']['Accept'] === 'application/json' &&
        $GLOBALS['requests'][0][1]['body']['code_verifier'] === $context['pkce_verifier'],
    'GitHub token uses JSON POST with PKCE',
);
check(
    $GLOBALS['requests'][1][0] === 'https://api.github.com/user' &&
        $GLOBALS['requests'][1][1]['headers']['Authorization'] === 'Bearer synthetic-access',
    'GitHub /user uses authorization header',
);
foreach ($GLOBALS['requests'] as [$url, $request]) {
    check(
        $request['sslverify'] === true &&
            $request['redirection'] === 0 &&
            $request['limit_response_size'] === 65536,
        'modern HTTP transport bounds',
    );
}
foreach (['0', '"name-not-id"', '12.5'] as $bad_id) {
    $GLOBALS['responses'] = [
        response('{"access_token":"synthetic-access","token_type":"bearer"}'),
        response('{"id":' . $bad_id . '}'),
    ];
    rejects(
        static fn() => pagenest_social_exchange('github', $modern_config, 'code', $context),
        'GitHub rejects unstable/noninteger subject',
    );
}
$GLOBALS['responses'] = [response('{"error":"bad_verification_code"}')];
rejects(
    static fn() => pagenest_social_exchange('github', $modern_config, 'code', $context),
    'GitHub denied grant rejected',
);
$GLOBALS['responses'] = [response('{}', 302)];
rejects(
    static fn() => pagenest_social_exchange('github', $modern_config, 'code', $context),
    'modern provider redirect rejected',
);

// Fresh in-memory RSA certificate: no key material is written or printed.
$fixture_key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'private_key_bits' => 2048,
]);
$fixture_csr = openssl_csr_new(['commonName' => 'synthetic.example.test'], $fixture_key, [
    'digest_alg' => 'sha256',
]);
$fixture_certificate = openssl_csr_sign($fixture_csr, null, $fixture_key, 1, [
    'digest_alg' => 'sha256',
]);
openssl_x509_export($fixture_certificate, $fixture_pem);
$claims = [
    'iss' => 'https://accounts.google.com',
    'aud' => $modern_config['client_id'],
    'azp' => $modern_config['client_id'],
    'sub' => 'synthetic-google-subject',
    'iat' => time(),
    'exp' => time() + 3600,
    'nonce' => $context['nonce'],
    'name' => 'Synthetic Google',
    'picture' => 'https://lh3.googleusercontent.com/avatar',
    'email' => 'ignored@example.test',
    'email_verified' => true,
];
$sign = static function ($payload, $header = null) use ($fixture_key) {
    $header = $header ?? ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'synthetic-kid'];
    $message =
        pagenest_social_base64url(json_encode($header)) .
        '.' .
        pagenest_social_base64url(json_encode($payload));
    openssl_sign($message, $signature, $fixture_key, OPENSSL_ALGO_SHA256);
    return $message . '.' . pagenest_social_base64url($signature);
};
$certificate_response = response(json_encode(['synthetic-kid' => $fixture_pem]));
$certificate_response['headers'] = ['cache-control' => 'public, max-age=7200', 'age' => '20'];
$GLOBALS['responses'] = [
    response(json_encode(['id_token' => $sign($claims)])),
    $certificate_response,
];
$GLOBALS['requests'] = [];
delete_transient('pn_social_google_certificates');
$google_identity = pagenest_social_exchange('google', $modern_config, 'code', $context);
check(
    $google_identity['subject'] === $claims['sub'] &&
        $google_identity['name'] === 'Synthetic Google' &&
        !isset($google_identity['email']) &&
        !isset($google_identity['email_verified']),
    'Google trusts verified sub but ignores provider email',
);
check(
    count($GLOBALS['requests']) === 2 &&
        $GLOBALS['requests'][1][0] === 'https://www.googleapis.com/oauth2/v1/certs',
    'Google verifies using fixed certificate endpoint only',
);
check(
    get_transient('pn_social_google_certificates')['expires'] <= time() + 3600,
    'Google certificate cache capped at one hour',
);
check(
    $GLOBALS['requests'][0][1]['body']['code_verifier'] === $context['pkce_verifier'],
    'Google code exchange carries PKCE verifier',
);
foreach (
    [
        ['iss' => 'https://evil.example'],
        ['aud' => 'other-client'],
        ['azp' => 'other-client'],
        ['aud' => ['other-client']],
        ['exp' => time() - 1],
        ['iat' => time() + 120],
        ['iat' => time() - 8000],
        ['exp' => (string) (time() + 3600)],
        ['nonce' => str_repeat('b', 64)],
        ['sub' => ''],
        ['sub' => 123],
        ['sub' => str_repeat('x', 256)],
        ['nbf' => time() + 120],
    ]
    as $bad_claims
) {
    $bad_token = $sign(array_replace($claims, $bad_claims));
    rejects(
        static fn() => pagenest_social_google_token(
            $bad_token,
            $modern_config['client_id'],
            $context['nonce'],
        ),
        'signed invalid Google claims refused',
    );
}
$multi_audience = array_replace($claims, [
    'aud' => [$modern_config['client_id'], 'another-audience'],
]);
unset($multi_audience['azp']);
rejects(
    static fn() => pagenest_social_google_token(
        $sign($multi_audience),
        $modern_config['client_id'],
        $context['nonce'],
    ),
    'multiple audiences require exact authorized party',
);
check(
    pagenest_social_google_token(
        $sign(array_replace($claims, ['iss' => 'accounts.google.com'])),
        $modern_config['client_id'],
        $context['nonce'],
    )['sub'] === $claims['sub'],
    'documented bare Google issuer accepted',
);
$wrong_signature = explode('.', $sign($claims));
$wrong_signature[2] = pagenest_social_base64url(str_repeat('x', 256));
rejects(
    static fn() => pagenest_social_google_token(
        implode('.', $wrong_signature),
        $modern_config['client_id'],
        $context['nonce'],
    ),
    'Google invalid RSA signature refused',
);
foreach (
    [
        ['alg' => 'none', 'kid' => 'synthetic-kid'],
        ['alg' => 'HS256', 'kid' => 'synthetic-kid'],
        ['alg' => 'RS256', 'kid' => 'synthetic-kid', 'jku' => 'https://evil.example/certs'],
        ['alg' => 'RS256', 'kid' => 'synthetic-kid', 'crit' => ['unknown']],
    ]
    as $bad_header
) {
    rejects(
        static fn() => pagenest_social_google_token(
            $sign($claims, $bad_header),
            $modern_config['client_id'],
            $context['nonce'],
        ),
        'Google algorithm and key-injection headers refused',
    );
}
$GLOBALS['responses'] = [$certificate_response];
$GLOBALS['requests'] = [];
rejects(
    static fn() => pagenest_social_google_token(
        $sign($claims, ['alg' => 'RS256', 'kid' => 'unknown-kid']),
        $modern_config['client_id'],
        $context['nonce'],
    ),
    'unknown Google kid rejected',
);
check(
    count($GLOBALS['requests']) === 1,
    'unknown kid refreshes fixed certificate endpoint at most once',
);
$GLOBALS['options']['pn_social_google_certificates']['expires'] = time() - 1;
$GLOBALS['responses'] = [response('{}', 503)];
rejects(
    static fn() => pagenest_social_google_token(
        $sign($claims),
        $modern_config['client_id'],
        $context['nonce'],
    ),
    'expired certificates are not used after network failure',
);
delete_transient('pn_social_google_certificates');
$GLOBALS['responses'] = [$certificate_response];
$GLOBALS['requests'] = [];
rejects(
    static fn() => pagenest_social_google_token(
        $sign($claims, ['alg' => 'RS256', 'kid' => 'unknown-kid']),
        $modern_config['client_id'],
        $context['nonce'],
    ),
    'unknown kid after fresh certificate fetch rejected',
);
check(count($GLOBALS['requests']) === 1, 'fresh certificate miss does not fetch twice');
$rotated_response = response(json_encode(['rotated-kid' => $fixture_pem]));
$rotated_response['headers'] = ['cache-control' => 'public, max-age=300'];
$GLOBALS['responses'] = [$rotated_response];
$GLOBALS['requests'] = [];
check(
    pagenest_social_google_token(
        $sign($claims, ['alg' => 'RS256', 'kid' => 'rotated-kid']),
        $modern_config['client_id'],
        $context['nonce'],
    )['sub'] === $claims['sub'],
    'valid rotated Google certificate accepted after one refresh',
);
check(count($GLOBALS['requests']) === 1, 'rotation refresh bounded to one HTTP request');
$no_store = $certificate_response;
$no_store['headers']['cache-control'] = 'no-store, max-age=3600';
$GLOBALS['responses'] = [$no_store];
pagenest_social_google_certificates(true);
check(
    get_transient('pn_social_google_certificates') === false,
    'no-store response is not retained',
);
foreach (['not-a-jwt', 'a.b.c', str_repeat('x', 32769)] as $malformed_jwt) {
    rejects(
        static fn() => pagenest_social_google_token(
            $malformed_jwt,
            $modern_config['client_id'],
            $context['nonce'],
        ),
        'malformed or oversized JWT rejected',
    );
}
$GLOBALS['responses'] = [response(str_repeat('x', 65537))];
rejects(
    static fn() => pagenest_social_google_certificates(true),
    'oversized certificate response refused',
);
unset($fixture_key, $fixture_csr, $fixture_certificate, $fixture_pem, $sign);

$wechat_url = pagenest_social_authorize_url(
    'wechat',
    $modern_config,
    'pn.wechat.' . str_repeat('c', 64),
);
parse_str(parse_url($wechat_url, PHP_URL_QUERY), $wechat_query);
check(
    str_starts_with($wechat_url, 'https://open.weixin.qq.com/connect/qrconnect?') &&
        str_ends_with($wechat_url, '#wechat_redirect') &&
        $wechat_query['scope'] === 'snsapi_login' &&
        isset($wechat_query['appid']) &&
        !isset($wechat_query['client_id']),
    'WeChat website authorization uses qrconnect and appid',
);
$wechat_token = [
    'access_token' => 'synthetic-wechat-token',
    'openid' => 'synthetic-openid',
    'scope' => 'snsapi_login',
];
$wechat_profile = [
    'openid' => 'synthetic-openid',
    'unionid' => 'never-merge',
    'nickname' => 'Synthetic WeChat',
    'headimgurl' => 'https://wx.qlogo.cn/avatar',
];
$GLOBALS['responses'] = [
    response(json_encode($wechat_token)),
    response(json_encode($wechat_profile)),
];
$GLOBALS['requests'] = [];
$wechat_identity = pagenest_social_exchange('wechat', $modern_config, 'code');
check(
    $wechat_identity['subject'] === 'synthetic-openid' && !isset($wechat_identity['unionid']),
    'WeChat uses scoped OpenID and never merges UnionID',
);
parse_str(parse_url($GLOBALS['requests'][0][0], PHP_URL_QUERY), $wechat_token_query);
check(
    $wechat_token_query['appid'] === $modern_config['client_id'] &&
        $wechat_token_query['secret'] === $modern_config['client_secret'] &&
        $GLOBALS['requests'][0][1]['method'] === 'GET',
    'WeChat token follows official website protocol',
);
foreach ([['scope' => 'snsapi_userinfo'], ['errcode' => 40029], ['openid' => '']] as $bad_grant) {
    $GLOBALS['responses'] = [response(json_encode(array_replace($wechat_token, $bad_grant)))];
    rejects(
        static fn() => pagenest_social_exchange('wechat', $modern_config, 'code'),
        'WeChat rejects error or non-website grant',
    );
}
$GLOBALS['responses'] = [
    response(json_encode($wechat_token)),
    response(json_encode(array_replace($wechat_profile, ['openid' => 'other-openid']))),
];
rejects(
    static fn() => pagenest_social_exchange('wechat', $modern_config, 'code'),
    'WeChat rejects token/profile OpenID mismatch',
);
$GLOBALS['responses'] = [
    response(json_encode($wechat_token)),
    response('{"errcode":40003,"errmsg":"synthetic failure"}'),
];
rejects(
    static fn() => pagenest_social_exchange('wechat', $modern_config, 'code'),
    'WeChat userinfo error refused',
);
check(
    pagenest_social_identity_key('wechat', 'app-one', 'same-openid') !==
        pagenest_social_identity_key('wechat', 'app-two', 'same-openid'),
    'WeChat identities remain application scoped',
);
$wechat_state = pagenest_social_state_create('wechat', $modern_config, 'login', '/');
check(
    strlen($wechat_state) < 128 && str_starts_with($wechat_state, 'pn.wechat.'),
    'WeChat state retains sufficient entropy within bounded length',
);
delete_transient('pagenest_social_state_' . hash('sha256', $wechat_state));
foreach (['github', 'google', 'wechat'] as $new_provider) {
    $GLOBALS['config']['legacy_apps'][$new_provider] = $modern_config['client_id'];
    update_user_meta(1, 'open_type_' . $new_provider, 'historical-new-subject');
    check(
        pagenest_social_login_identity($new_provider, $modern_config, [
            'subject' => 'historical-new-subject',
        ]) === 0,
        'new providers never claim matching legacy fields',
    );
    check(
        pagenest_social_bind(
            2,
            $new_provider,
            $modern_config['client_id'],
            'historical-new-subject',
        ) === 2,
        'explicit authenticated binding is independent of new-provider legacy field',
    );
}
