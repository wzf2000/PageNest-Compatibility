<?php
defined('ABSPATH') || exit();
/** Fixed messages only; URLs never provide message text or success state. */
function pagenest_social_flash_messages()
{
    return [
        'password_ok' => ['success', '身份确认成功，当前会话十分钟内可管理连接。'],
        'reauth_ok' => ['success', '社交账号身份确认成功，当前会话十分钟内可管理连接。'],
        'bind_ok' => ['success', '社交账号已连接，可用于登录。'],
        'unbind_ok' => ['success', '社交账号连接已解除。'],
        'email_request_ok' => ['success', '验证邮件已提交发送，请查看邮箱并打开验证链接。'],
        'email_confirm_ok' => ['success', '当前邮箱已验证，可用于账号恢复。'],
        'password_error' => [
            'error',
            '身份确认失败。请检查当前密码，或使用已连接的社交账号确认；频繁尝试后请稍后重试。',
        ],
        'reauth_error' => ['error', '身份确认未完成。请使用当前账号已连接的第三方身份重新授权。'],
        'bind_error' => [
            'error',
            '连接未完成。请先确认身份，再重新授权；第三方身份不能同时连接其他账号。',
        ],
        'unbind_error' => [
            'error',
            '解除连接未完成。请先确认身份，并保留其他可用的社交登录方式或已验证的恢复邮箱。',
        ],
        'email_request_error' => [
            'error',
            '验证邮件未能提交发送。请先确认身份、保存有效邮箱，或稍后重试。',
        ],
        'email_confirm_error' => [
            'error',
            '邮箱验证未完成。链接可能已失效或邮箱已更改，请重新申请验证。',
        ],
    ];
}
function pagenest_social_flash_store($code)
{
    $session = pagenest_social_session_fingerprint(get_current_user_id());
    if (!$session || !isset(pagenest_social_flash_messages()[$code])) {
        return false;
    }
    return set_transient(
        'pn_social_flash_' . $session,
        ['code' => $code, 'expires' => time() + 120],
        120,
    );
}
function pagenest_social_flash_take()
{
    $session = pagenest_social_session_fingerprint(get_current_user_id());
    if (!$session) {
        return null;
    }
    try {
        return pagenest_social_lock('flash:' . $session, static function () use ($session) {
            $key = 'pn_social_flash_' . $session;
            $record = get_transient($key);
            if (
                !is_array($record) ||
                !delete_transient($key) ||
                ($record['expires'] ?? 0) <= time()
            ) {
                return null;
            }
            return pagenest_social_flash_messages()[$record['code'] ?? ''] ?? null;
        });
    } catch (Throwable $error) {
        return null;
    }
}
function pagenest_social_profile_result($code)
{
    pagenest_social_flash_store($code);
    nocache_headers();
    wp_safe_redirect(get_edit_profile_url(get_current_user_id()) . '#pagenest-social-account');
    exit();
}
