<?php


/** Registriert den Custom Post Type 'mission'. */
class ffami_mission_post_type {

    public function __construct() {
        // Register the custom post type
        add_action('init', array($this, 'register_mission_post_type'));
    }
    public function register_mission_post_type() {
        $labels = array(
            'name' => __('Einsätze', 'textdomain'),
            'singular_name' => __('Einsatz', 'textdomain'),
            'add_new' => __('Neu hinzufügen', 'textdomain'),
            'add_new_item' => __('Neuen Einsatz hinzufügen', 'textdomain'),
            'edit_item' => __('Einsatz bearbeiten', 'textdomain'),
            'new_item' => __('Neuer Einsatz', 'textdomain'),
            'view_item' => __('Einsatz ansehen', 'textdomain'),
            'search_items' => __('Einsätze durchsuchen', 'textdomain'),
            'not_found' => __('Keine Einsätze gefunden', 'textdomain'),
            'not_found_in_trash' => __('Keine Einsätze im Papierkorb gefunden', 'textdomain'),
        );

        $args = array(
            'labels' => $labels,
            'public' => true,
            'has_archive' => true,
            'supports' => array('title', 'editor', 'thumbnail'),
        );

        register_post_type('mission', $args);
    }
}