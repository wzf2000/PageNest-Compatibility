<?php
/** Private worker for the explicitly marked disposable native test only. */
if (PHP_SAPI !== 'cli' || count($argv) !== 5) {
    exit(2);
}
require rtrim($argv[1], '/') . '/wp-load.php';
if (
    !defined('PAGENEST_ACCOUNT_READING_TEST_LAB') ||
    !PAGENEST_ACCOUNT_READING_TEST_LAB ||
    !is_dir($argv[4]) ||
    !is_file($argv[4] . '/owned')
) {
    exit(3);
}
$user = (int) $argv[2];
wp_set_current_user($user);
file_put_contents($argv[4] . '/' . getmypid() . '.ready', 'ready');
$result = pagenest_account_reading_write($user, 'consent', ['revision' => $argv[3]]);
echo wp_json_encode([
    'status' => is_wp_error($result) ? $result->get_error_data()['status'] ?? 500 : 200,
]);
