<?php
defined('ABSPATH') || exit();
function pagenest_social_identity_key($provider, $app, $subject)
{
    return '_pagenest_social_identity_' .
        hash('sha256', wp_json_encode([$provider, (string) $app, (string) $subject]));
}
function pagenest_social_lock($key, $callback)
{
    $lock = '_pagenest_social_lock_' . hash('sha256', $key);
    // Fail closed after a crashed request; an administrator may clear a stale lock.
    if (!add_option($lock, time(), '', false)) {
        throw new RuntimeException('Identity is busy. Please retry.');
    }
    try {
        return $callback();
    } finally {
        delete_option($lock);
    }
}
function pagenest_social_bind($user_id, $provider, $app, $subject, $migration = false)
{
    $key = pagenest_social_identity_key($provider, $app, $subject);
    return pagenest_social_lock('user:' . $user_id, static function () use (
        $user_id,
        $provider,
        $app,
        $subject,
        $key,
        $migration,
    ) {
        return pagenest_social_lock($key, static function () use (
            $user_id,
            $provider,
            $app,
            $subject,
            $key,
            $migration,
        ) {
            $owner = pagenest_social_available_owner($key);
            if ($migration && is_array($owner) && (int) $owner['user_id'] === 0) {
                if ((int) ($owner['unbound_from'] ?? 0) !== (int) $user_id) {
                    throw new RuntimeException('Migration identity conflict.');
                }
                return null;
            }
            if (
                is_array($owner) &&
                (int) $owner['user_id'] !== 0 &&
                (int) $owner['user_id'] !== (int) $user_id
            ) {
                throw new RuntimeException('Identity already belongs to another account.');
            }
            if (!get_userdata($user_id)) {
                throw new RuntimeException('Account unavailable.');
            }
            if (
                $owner === null &&
                (pagenest_social_config()['legacy_apps'][$provider] ?? '') === (string) $app
            ) {
                $legacy = get_users([
                    'fields' => 'ID',
                    'number' => 2,
                    'meta_key' => 'open_type_' . $provider,
                    'meta_value' => (string) $subject,
                ]);
                if (
                    count($legacy) > 1 ||
                    (count($legacy) === 1 && (int) $legacy[0] !== (int) $user_id)
                ) {
                    throw new RuntimeException('Legacy identity belongs to another account.');
                }
            }
            $bindings = get_user_meta($user_id, '_pagenest_social_bindings', true) ?: [];
            foreach ($bindings as $other => $binding) {
                if (
                    $binding['provider'] === $provider &&
                    $binding['app'] === (string) $app &&
                    $other !== $key
                ) {
                    throw new RuntimeException('This provider is already connected.');
                }
            }
            $previous = $bindings;
            $previous_exists = metadata_exists('user', $user_id, '_pagenest_social_bindings');
            $bindings[$key] = [
                'provider' => $provider,
                'app' => (string) $app,
                'subject' => (string) $subject,
            ];
            try {
                pagenest_social_save_bindings($user_id, $bindings);
                pagenest_social_save_owner($key, ['user_id' => (int) $user_id], $owner);
            } catch (Throwable $error) {
                try {
                    pagenest_social_restore_bindings($user_id, $previous, $previous_exists);
                } finally {
                    pagenest_social_restore_owner($key, $owner);
                }
                throw new RuntimeException('Unable to save binding.');
            }
            return $user_id;
        });
    });
}
function pagenest_social_recoverable_email($email)
{
    return is_email($email) && !preg_match('/@(fake\.com|[^@]*\.invalid)$/i', $email);
}
function pagenest_social_unbind($user_id, $key)
{
    return pagenest_social_lock('user:' . $user_id, static function () use ($user_id, $key) {
        return pagenest_social_lock($key, static function () use ($user_id, $key) {
            $bindings = get_user_meta($user_id, '_pagenest_social_bindings', true) ?: [];
            if (!isset($bindings[$key])) {
                throw new RuntimeException('Binding not found.');
            }
            $remaining = array_filter(
                $bindings,
                static function ($binding, $identity_key) use ($user_id) {
                    $owner = pagenest_social_owner($identity_key);
                    if (!is_array($owner) || (int) $owner['user_id'] !== (int) $user_id) {
                        return false;
                    }
                    return pagenest_social_provider_config($binding['provider']) !== null &&
                        pagenest_social_provider_config($binding['provider'])['client_id'] ===
                            $binding['app'];
                },
                ARRAY_FILTER_USE_BOTH,
            );
            unset($remaining[$key]);
            $user = get_userdata($user_id);
            if (!$remaining && !pagenest_social_email_verified($user_id)) {
                throw new RuntimeException('Keep at least one usable login method.');
            }
            $owner = pagenest_social_owner($key);
            if (!is_array($owner) || (int) $owner['user_id'] !== (int) $user_id) {
                throw new RuntimeException('Identity owner mismatch.');
            }
            // A tombstone overrides the retained historical field; migration never revives it.
            $previous = $bindings;
            unset($bindings[$key]);
            try {
                pagenest_social_save_owner(
                    $key,
                    ['user_id' => 0, 'unbound_from' => (int) $user_id],
                    $owner,
                );
                pagenest_social_save_bindings($user_id, $bindings);
            } catch (Throwable $error) {
                try {
                    pagenest_social_restore_bindings($user_id, $previous, true);
                } finally {
                    pagenest_social_restore_owner($key, $owner);
                }
                throw new RuntimeException('Unable to disconnect identity.');
            }
        });
    });
}
class PageNest_Social_Registration_Error extends RuntimeException
{
    public $errors;
    public function __construct($errors)
    {
        $this->errors = $errors;
        parent::__construct('Registration details need correction.');
    }
}
function pagenest_social_registration_details($details)
{
    $details = is_array($details) ? $details : [];
    $login = is_string($details['user_login'] ?? null) ? $details['user_login'] : '';
    $email = is_string($details['user_email'] ?? null) ? trim($details['user_email']) : '';
    $nickname = is_string($details['nickname'] ?? null)
        ? sanitize_text_field($details['nickname'])
        : '';
    $errors = [];
    if ($login === '') {
        $errors['user_login'] = '请填写用户名。';
    } elseif (
        mb_strlen($login) > 60 ||
        !validate_username($login) ||
        sanitize_user($login, true) !== $login
    ) {
        $errors['user_login'] = '用户名最长 60 个字符，请使用英文字母、数字、空格或 _ . - @。';
    } elseif (
        in_array(
            strtolower($login),
            array_map('strtolower', (array) apply_filters('illegal_user_logins', [])),
            true,
        )
    ) {
        $errors['user_login'] = '此用户名不可使用，请换一个用户名。';
    } elseif (username_exists($login)) {
        $errors['user_login'] = '此用户名已被使用，请换一个用户名，或登录已有账号后绑定。';
    }
    if ($email === '') {
        $errors['user_email'] = '请填写电子邮箱。';
    } elseif (
        strlen($email) > 100 ||
        !is_email($email) ||
        !pagenest_social_recoverable_email($email)
    ) {
        $errors['user_email'] = '请填写有效的电子邮箱，最长 100 个字符。';
    } elseif (email_exists($email)) {
        $errors['user_email'] = '此邮箱已注册，请登录已有账号后绑定，或使用其他邮箱。';
    }
    if (mb_strlen($nickname) > 250) {
        $errors['nickname'] = '昵称最长 250 个字符。';
    }
    if ($errors) {
        throw new PageNest_Social_Registration_Error($errors);
    }
    return [
        'user_login' => $login,
        'user_email' => $email,
        'nickname' => $nickname !== '' ? $nickname : $login,
    ];
}
function pagenest_social_login_identity(
    $provider,
    $config,
    $identity,
    $confirmed = false,
    $details = null,
) {
    $key = pagenest_social_identity_key($provider, $config['client_id'], $identity['subject']);
    return pagenest_social_lock($key, static function () use (
        $key,
        $identity,
        $provider,
        $config,
        $confirmed,
        $details,
    ) {
        $owner = pagenest_social_available_owner($key);
        if (is_array($owner) && $owner['user_id']) {
            if ($confirmed) {
                throw new RuntimeException('Identity already registered.');
            }
            return (int) $owner['user_id'];
        }
        // Exact provider/application scope only. Never merge by email or display name.
        if (
            $owner === null &&
            (pagenest_social_config()['legacy_apps'][$provider] ?? '') === $config['client_id']
        ) {
            $legacy = get_users([
                'fields' => 'ID',
                'number' => 2,
                'meta_key' => 'open_type_' . $provider,
                'meta_value' => $identity['subject'],
            ]);
            if (count($legacy) > 1) {
                throw new RuntimeException('Duplicate legacy identity.');
            }
            if (count($legacy) === 1) {
                if ($confirmed) {
                    throw new RuntimeException('Identity already registered.');
                }
                return (int) $legacy[0];
            }
        }
        if (!$confirmed) {
            return 0;
        }
        if (
            (pagenest_social_config()['allow_registration'] ?? false) !== true ||
            !get_option('users_can_register')
        ) {
            throw new RuntimeException(
                'Account not connected. Sign in with an existing account to connect it.',
            );
        }
        $details = pagenest_social_registration_details($details);
        return pagenest_social_lock(
            'register-login:' . strtolower($details['user_login']),
            static function () use ($details, $key, $owner, $provider, $config, $identity) {
                return pagenest_social_lock(
                    'register-email:' . strtolower($details['user_email']),
                    static function () use ($details, $key, $owner, $provider, $config, $identity) {
                        // Recheck after acquiring both registration locks. Never merge by email.
                        $details = pagenest_social_registration_details($details);
                        $id = wp_insert_user([
                            'user_login' => $details['user_login'],
                            'user_pass' => wp_generate_password(40, true, true),
                            'user_email' => $details['user_email'],
                            'nickname' => $details['nickname'],
                            'display_name' => $details['nickname'],
                            'role' => 'subscriber',
                        ]);
                        if (is_wp_error($id)) {
                            throw new PageNest_Social_Registration_Error([
                                'form' => '暂时无法创建账号，请检查资料后重试。',
                            ]);
                        }
                        $binding = [
                            'provider' => $provider,
                            'app' => $config['client_id'],
                            'subject' => $identity['subject'],
                        ];
                        try {
                            pagenest_social_save_bindings($id, [$key => $binding]);
                            pagenest_social_save_owner($key, ['user_id' => (int) $id], $owner);
                        } catch (Throwable $error) {
                            pagenest_social_restore_owner($key, $owner);
                            // Only this request's newly created account may be rolled back.
                            if (!function_exists('wp_delete_user')) {
                                require_once ABSPATH . 'wp-admin/includes/user.php';
                            }
                            if (!wp_delete_user($id)) {
                                throw new RuntimeException(
                                    'Registration rollback requires administrator repair.',
                                );
                            }
                            throw new RuntimeException('Unable to save new identity.');
                        }
                        return $id;
                    },
                );
            },
        );
    });
}

function pagenest_social_save_bindings($id, $bindings)
{
    update_user_meta($id, '_pagenest_social_bindings', $bindings);
    if (get_user_meta($id, '_pagenest_social_bindings', true) !== $bindings) {
        throw new RuntimeException('Binding metadata write failed.');
    }
}
function pagenest_social_save_owner($key, $record, $previous)
{
    if ($previous === null) {
        if (!add_option($key, $record, '', false)) {
            throw new RuntimeException('Identity write failed.');
        }
    } else {
        update_option($key, $record, false);
    }
    if (get_option($key, null) !== $record) {
        throw new RuntimeException('Identity write failed.');
    }
}
function pagenest_social_restore_bindings($id, $previous, $existed)
{
    if ($existed) {
        pagenest_social_save_bindings($id, $previous);
    } else {
        delete_user_meta($id, '_pagenest_social_bindings');
        if (metadata_exists('user', $id, '_pagenest_social_bindings')) {
            throw new RuntimeException('Identity repair required.');
        }
    }
}
function pagenest_social_restore_owner($key, $previous)
{
    if ($previous === null) {
        delete_option($key);
        if (get_option($key, null) !== null) {
            throw new RuntimeException('Identity repair required.');
        }
    } else {
        update_option($key, $previous, false);
        if (get_option($key, null) !== $previous) {
            throw new RuntimeException('Identity repair required.');
        }
    }
}

function pagenest_social_owner($key)
{
    $owner = get_option($key, null);
    if (
        $owner !== null &&
        (!is_array($owner) ||
            !isset($owner['user_id']) ||
            !is_int($owner['user_id']) ||
            $owner['user_id'] < 0)
    ) {
        throw new RuntimeException('Invalid identity index.');
    }
    return $owner;
}

/** Called only while holding this identity's lock, before login or binding. */
function pagenest_social_available_owner($key)
{
    global $wpdb;
    $owner = pagenest_social_owner($key);
    if ($owner === null || $owner['user_id'] === 0) {
        return $owner;
    }
    // User deletion removes metadata but not our option index. Read the primary
    // user table directly: a stale object cache or failed lookup is not proof
    // that an account was deleted. Tombstones retain their legacy override.
    $existing = $wpdb->get_var(
        $wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID = %d", $owner['user_id']),
    );
    if ($wpdb->last_error !== '') {
        throw new RuntimeException('Unable to verify identity owner.');
    }
    if ($existing !== null) {
        if ((int) $existing !== $owner['user_id']) {
            throw new RuntimeException('Invalid identity owner lookup.');
        }
        return $owner;
    }
    delete_option($key);
    if (get_option($key, null) !== null) {
        throw new RuntimeException('Unable to release deleted identity owner.');
    }
    return null;
}

function pagenest_social_deleted_user($user_id)
{
    global $wpdb;
    $user_id = (int) $user_id;
    if (
        $user_id <= 0 ||
        $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID = %d", $user_id)) !==
            null ||
        $wpdb->last_error !== ''
    ) {
        return;
    }
    $keys = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('_pagenest_social_identity_') . '%',
        ),
    );
    if ($wpdb->last_error !== '') {
        return;
    }
    foreach ($keys as $key) {
        try {
            pagenest_social_lock($key, static function () use ($key, $user_id) {
                global $wpdb;
                $owner = pagenest_social_owner($key);
                if (
                    $owner === null ||
                    !(
                        $owner['user_id'] === $user_id ||
                        ($owner['user_id'] === 0 && ($owner['unbound_from'] ?? 0) === $user_id)
                    )
                ) {
                    return;
                }
                if (
                    $wpdb->get_var(
                        $wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID = %d", $user_id),
                    ) !== null ||
                    $wpdb->last_error !== ''
                ) {
                    return;
                }
                delete_option($key);
                if (get_option($key, null) !== null) {
                    throw new RuntimeException('Deleted identity cleanup incomplete.');
                }
            });
        } catch (Throwable $error) {
            // Deletion must not fail after WordPress removed the account. Busy
            // identities remain fail-closed and are repaired on their next use.
        }
    }
}
