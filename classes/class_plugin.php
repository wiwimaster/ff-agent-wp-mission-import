<?php


/**
 * Bootstrap des Plugins (Registrierung CPT, Backend, Blöcke, Variablen).
 */
class ffami_plugin {

    public function __construct() {

        //define the variables
        $ffami_vars = new ffami_vars();

        //regsiter the custom post type
        new ffami_mission_post_type();

    // MP-Feuer-Export (REST-Endpunkt, Warteschlange, Zuordnung)
    new ffami_mpfeuer_module();

    // Frontend Blocks
    new ffami_blocks();

        //load the backend
        new ffami_backend();

        add_action('admin_init', [self::class, 'remove_legacy_cron']);
    }

    /** Reste des entfernten WP-Cron-Wegs (ffami_cron) einmalig abräumen. */
    public static function remove_legacy_cron(): void {
        if (wp_next_scheduled('ffami_cron_import')) {
            wp_clear_scheduled_hook('ffami_cron_import');
        }
        if (get_option('ffami_last_run', null) !== null) {
            delete_option('ffami_last_run');
            delete_option('ffami_last_run_imported');
            delete_option('ffami_queue_size');
        }
    }
}
