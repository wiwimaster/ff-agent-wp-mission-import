<?php

/**
 * Plugin Name: FF Agent WP Mission Import
 * Plugin URI: https://github.com/wiwimaster/ff-agent-wp-mission-import
 * Description: Importiert Einsatzdaten von FF Agent nach Wordpress.
 * Version: 0.1
 * Author: Jan Runge
 * Author URI: https://github.com/wiwimaster
 * License: GPLv3
 */

define('FFAMI_FILE',                       __FILE__);
define('FFAMI_PATH',                       realpath(plugin_dir_path(FFAMI_FILE)) . '/');
define('FFAMI_BASENAME',                   plugin_basename(FFAMI_FILE));
define('FFAMI_URL',                        plugins_url('', FFAMI_FILE));
define('FFAMI_JS_PATH',                    realpath(FFAMI_PATH) . '/assets/javascript/');
define('FFAMI_JS_PUBLIC_URL',              FFAMI_URL . '/assets/javascript/');

// Ursprüngliche Default UID kann leer sein; tatsächliche UID wird als Option gespeichert (ffami_uid)
if (!defined('FFAMI_UID')) {
	define('FFAMI_UID', '');
}
define('FFAMI_DATA_ROOT',                  'https://pd.service.ff-agent.com');
define('FFAMI_DATA_PATH',                  'https://pd.service.ff-agent.com/hpWidget/');

// Load the autoloader
require_once FFAMI_PATH . 'classes/class_autoloader.php';

//initialize the plugin
new ffami_plugin();

// Aktivierungs-/Deaktivierungs-Hooks für Cron Registrierung
register_activation_hook(__FILE__, function() { ffami_cron::activate(); });
register_deactivation_hook(__FILE__, function() { ffami_cron::deactivate(); });
