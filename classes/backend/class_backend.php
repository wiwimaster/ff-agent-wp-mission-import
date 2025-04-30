<?php

class ffami_backend {

    private ffami_admin_panel $admin_panel;

    public function __construct() {

        if (is_admin()) {

            // Initialize the admin panel
            $this->admin_panel = new ffami_admin_panel();
            add_action('admin_menu', array($this->admin_panel, 'add_admin_menu'));
        }
    }
}
