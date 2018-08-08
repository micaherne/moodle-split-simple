<?php
global $CFG;

$CFG = new \stdClass();
$CFG->dirroot = $moodleroot;
$CFG->dataroot = sys_get_temp_dir();
$CFG->wwwroot = 'http://example.com';
$CFG->debug = E_ALL;
$CFG->debugdisplay = 1;
define('CLI_SCRIPT', true);
define('ABORT_AFTER_CONFIG', true); // We need just the values from config.php.
define('CACHE_DISABLE_ALL', true); // This prevents reading of existing caches.
define('IGNORE_COMPONENT_CACHE', true);
require_once($CFG->dirroot . '/lib/setup.php');
$plugins = [];
foreach (\core_component::get_plugin_types() as $plugintype => $plugintypedir) {
    foreach (\core_component::get_plugin_list($plugintype) as $plugin => $plugindir) {
        $name = \core_component::normalize_componentname("{$plugintype}_{$plugin}");
        $plugins[$name] = realpath($plugindir);
    }
}
echo json_encode($plugins, JSON_PRETTY_PRINT);