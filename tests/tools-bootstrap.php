<?php
/** Exercise owner discovery before and after legacy bootstrap, including network activation. */
$mode = $argv[1] ?? 'disabled';
define('ABSPATH', __DIR__);
function add_action(...$args)
{
    $GLOBALS['hooks'][] = $args;
}
function get_option($name, $fallback)
{
    return $GLOBALS['plugins'] ?? $fallback;
}
function get_site_option($name, $fallback)
{
    return $GLOBALS['network'] ?? $fallback;
}
function do_action(...$args) {}
function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}
function home_url()
{
    return 'http://localhost';
}
if ($mode !== 'disabled') {
    define('PAGENEST_COMPANION_TOOLS_ENABLED', true);
}
// Profile constants alone are deliberately insufficient to enable ownership.
define('SITE_TOOLS_PROFILE_FILE', '/missing/tools.json');
if ($mode === 'legacy-first') {
    eval('namespace SiteTools; final class Profile {}');
}
require dirname(__DIR__) . '/modules/tools/bootstrap.php';
if ($mode === 'legacy-late') {
    eval('namespace SiteTools; final class Profile {}');
}
if ($mode === 'active') {
    $GLOBALS['plugins'] = ['renamed-folder/wp-tool-integration.php'];
}
if ($mode === 'network') {
    $GLOBALS['network'] = ['wp-tool-integration/wp-tool-integration.php' => 1];
}
PageNest\Tools\bootstrap();
$loaded = class_exists('PageNest\\Tools\\Host', false);
if (($mode === 'missing-profile') !== $loaded) {
    throw new RuntimeException('Unexpected core ownership: ' . $mode);
}
if ($mode === 'missing-profile' && SiteTools\Profile::ready()) {
    throw new RuntimeException('Missing profile enabled tools');
}
echo "Tools bootstrap $mode passed\n";
