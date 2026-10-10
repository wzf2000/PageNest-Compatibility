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
            $owner = pagenest_social_owner($key);
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
            $remaining = array_filter($bindings, static function ($binding) {
                return pagenest_social_provider_config($binding['provider']) !== null &&
                    pagenest_social_provider_config($binding['provider'])['client_id'] ===
                        $binding['app'];
            });
            unset($remaining[$key]);
            $user = get_userdata($user_id);
            if (!$remaining && !pagenest_social_recoverable_email($user->user_email)) {
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
function pagenest_social_login_identity($provider, $config, $identity)
{
    $key = pagenest_social_identity_key($provider, $config['client_id'], $identity['subject']);
    return pagenest_social_lock($key, static function () use ($key, $identity, $provider, $config) {
        $owner = pagenest_social_owner($key);
        if (is_array($owner) && $owner['user_id']) {
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
                return (int) $legacy[0];
            }
        }
        if (
            (pagenest_social_config()['allow_registration'] ?? false) !== true ||
            !get_option('users_can_register')
        ) {
            throw new RuntimeException(
                'Account not connected. Sign in with an existing account to connect it.',
            );
        }
        $login = 'reader_' . bin2hex(random_bytes(12));
        $id = wp_insert_user([
            'user_login' => $login,
            'user_pass' => wp_generate_password(40, true, true),
            'user_email' => $login . '@social.invalid',
            'display_name' => $identity['name'],
            'role' => 'subscriber',
        ]);
        if (is_wp_error($id)) {
            throw new RuntimeException('Unable to create account.');
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
            // Only this request's newly created synthetic-email account may be rolled back.
            if (!function_exists('wp_delete_user')) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }
            if (!wp_delete_user($id)) {
                throw new RuntimeException('Registration rollback requires administrator repair.');
            }
            throw new RuntimeException('Unable to save new identity.');
        }
        return $id;
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
