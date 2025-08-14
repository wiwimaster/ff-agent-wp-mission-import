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

    // (Legacy Cron entfernt – Scheduler aktiv)

    // Frontend Blocks
    new ffami_blocks();

        //load the backend
        new ffami_backend();
    }
}
