<?php

/**
 * Plugin Name: FF Agent WP Mission Import
 * Plugin URI:  https://github.com/wiwimaster/ff-agent-wp-mission-import
 * Description: Importiert Einsatzdaten (Einsätze) aus FF Agent in einen Custom Post Type.
 * Version:     0.1.1
 * Author:      Jan Runge
 * Author URI:  https://github.com/wiwimaster
 * License:     GPLv3
 */

define('FFAMI_FILE',                       __FILE__);
define('FFAMI_PATH',                       realpath(plugin_dir_path(FFAMI_FILE)) . '/');
define('FFAMI_BASENAME',                   plugin_basename(FFAMI_FILE));
define('FFAMI_URL',                        plugins_url('', FFAMI_FILE));
if (!defined('FFAMI_VERSION')) {
	define('FFAMI_VERSION', '0.1.1');
}
if (!defined('FFAMI_TEXTDOMAIN')) {
	define('FFAMI_TEXTDOMAIN', 'ffami');
}

// Ursprüngliche Default UID kann leer sein; tatsächliche UID wird als Option gespeichert (ffami_uid)
if (!defined('FFAMI_UID')) {
	define('FFAMI_UID', '');
}
// Basis-Endpunkte in Variablen-Klasse definieren (Rückwärtskompatibel falls bereits definiert)
if (!defined('FFAMI_DATA_ROOT')) { define('FFAMI_DATA_ROOT', 'https://pd.service.ff-agent.com'); }
if (!defined('FFAMI_DATA_PATH')) { define('FFAMI_DATA_PATH', 'https://pd.service.ff-agent.com/hpWidget/'); }

// Load the autoloader
require_once FFAMI_PATH . 'classes/class_autoloader.php';

// Bootstrap Hooks (statt sofortige Instanzierung – erlaubt MU/Tests Filter einzuhängen)
add_action('plugins_loaded', function() {
	load_plugin_textdomain(FFAMI_TEXTDOMAIN, false, dirname(FFAMI_BASENAME) . '/languages');
	new ffami_plugin();
	new ffami_scheduler();
});

// Activation / Deactivation: evtl. Cron / Scheduler initialisieren
register_activation_hook(__FILE__, function() {
	// Stelle sicher, dass UID Option existiert (leerer Default) – erleichtert Logik an anderer Stelle
	if (get_option('ffami_uid', null) === null) { add_option('ffami_uid', ''); }
});


