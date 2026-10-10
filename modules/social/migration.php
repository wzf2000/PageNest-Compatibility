<?php
/** Call through WP-CLI eval-file after loading WordPress; no automatic migration. */
defined('ABSPATH') || exit();
function pagenest_social_migration($apply = false)
{
    $run = static function () use ($apply) {
        $apps = pagenest_social_config()['legacy_apps'] ?? [];
        $plan = [];
        $seen = [];
        $counts = [];
        // Interpret only identity fields; preserve all existing user metadata.
        foreach (get_users(['fields' => 'ID']) as $id) {
            $meta = get_user_meta($id);
            foreach ($meta as $name => $values) {
                if (!str_starts_with($name, 'open_type_') || $name === 'open_type_wechat_unionid') {
                    continue;
                }
                $provider = substr($name, strlen('open_type_'));
                if (
                    !isset($apps[$provider]) ||
                    !is_string($apps[$provider]) ||
                    $apps[$provider] === ''
                ) {
                    throw new RuntimeException(
                        'Legacy provider has no application mapping: ' . $provider,
                    );
                }
                if (count($values) !== 1 || !is_scalar($values[0]) || (string) $values[0] === '') {
                    throw new RuntimeException('Malformed legacy binding.');
                }
                $subject = (string) $values[0];
                $key = pagenest_social_identity_key($provider, $apps[$provider], $subject);
                if (isset($seen[$key]) && $seen[$key] !== (int) $id) {
                    throw new RuntimeException('Duplicate legacy identity.');
                }
                $seen[$key] = (int) $id;
                $owner = pagenest_social_owner($key);
                if (
                    is_array($owner) &&
                    (int) $owner['user_id'] !== (int) $id &&
                    !(
                        (int) $owner['user_id'] === 0 &&
                        (int) ($owner['unbound_from'] ?? 0) === (int) $id
                    )
                ) {
                    throw new RuntimeException('Migration identity conflict.');
                }
                $counts[$provider] = ($counts[$provider] ?? 0) + 1;
                if (is_array($owner) && (int) $owner['user_id'] === 0) {
                    continue;
                }
                $plan[] = [
                    'id' => (int) $id,
                    'provider' => $provider,
                    'app' => $apps[$provider],
                    'subject' => $subject,
                ];
            }
        }
        if ($apply) {
            foreach ($plan as $binding) {
                pagenest_social_bind(
                    $binding['id'],
                    $binding['provider'],
                    $binding['app'],
                    $binding['subject'],
                    true,
                );
            }
        }
        return [
            'apply' => (bool) $apply,
            'legacy_counts' => $counts,
            'identities' => count($seen),
            'planned' => count($plan),
        ];
    };
    return $apply ? pagenest_social_lock('migration', $run) : $run();
}
