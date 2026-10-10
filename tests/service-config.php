<?php
/** Private configuration is strict while pre-service profiles keep all features available. */
define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/service-config.php';
$checks = 0;
function check($ok)
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('Service configuration contract failed');
    }
    $checks++;
}
function invalid($services)
{
    try {
        pagenest_companion_validate_services($services);
        return false;
    } catch (InvalidArgumentException $error) {
        return true;
    }
}
$old = ['services' => pagenest_companion_service_defaults()];
check(count($old['services']) === 4);
foreach ($old['services'] as $module) {
    check($module['enabled'] === false);
}
check(invalid(['mail' => ['enabled' => 1]]));
check(invalid(['mail' => ['api_key' => "secret\nvalue"]]));
check(invalid(['mail' => ['password' => 'unused']]));
check(invalid(['mail' => ['provider' => 'smtp']]));
check(invalid(['turnstile' => ['enabled' => true]]));
check(invalid(['turnstile' => ['scopes' => ['rest' => true]]]));
check(invalid(['social' => ['enabled' => true]]));
check(invalid(['social' => ['providers' => ['arbitrary' => []]]]));
check(
    invalid([
        'social' => [
            'providers' => ['qq' => ['redirect_uri' => 'https://user:secret@example.test/']],
        ],
    ]),
);
check(invalid(['social' => ['share' => ['providers' => ['custom']]]]));
check(invalid(['output' => ['purge' => true]]));
check(invalid(['cron' => []]));
$valid = [
    'services' => pagenest_companion_validate_services([
        'mail' => [
            'enabled' => true,
            'api_key' => 'synthetic-not-secret',
            'from_email' => 'sender@example.test',
            'from_name' => 'Sender',
        ],
        'social' => ['legacy_apps' => ['github' => 'legacy-unconfigured']],
    ]),
];
check(
    $valid['services']['mail']['enabled'] === true &&
        $valid['services']['social']['enabled'] === false,
);
$old_profile = pagenest_companion_validate(['schema_version' => 1]);
check(!array_key_exists('services', $old_profile));
$directory = sys_get_temp_dir() . '/pagenest-service-config-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$path = $directory . '/services.json';
try {
    file_put_contents($path, '{"schema_version":1,"services":{"output":{"enabled":false}}}');
    check(pagenest_companion_load_services($path, ABSPATH)['output']['enabled'] === false);
    file_put_contents($path, '{"schema_version":1,"services":{"output":{"enabled":1}}}');
    try {
        pagenest_companion_load_services($path, ABSPATH);
        check(false);
    } catch (InvalidArgumentException $error) {
        check(true);
    }
} finally {
    unlink($path);
    rmdir($directory);
}
echo "$checks optional service configuration checks passed\n";
