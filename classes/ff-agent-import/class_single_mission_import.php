<?php

/**
 * Class for importing a single mission from the FF Agent API
 *
 * This class handles the import of a single mission from the FF Agent API.
 * It fetches the mission data, processes it, and saves it as a custom post in WordPress.
 *
 * @package ffami
 */
class ffami_single_mission_import {



    /**
     * The mission object
     *
     * @var ffami_mission
     */
    private ffami_mission $mission;

    private ffami_mission $stored_mission;

    private array $mission_data = [];

    private string $existing_post_hash = '';

    private int $existing_post_id = 0;



    /**
     * Constructor for the ffami_single_mission_import class
     *
     * @param string $mission_id The ID of the mission to import
     * @param string $mission_url The URL of the mission to import
     */
    public function __construct($mission_id, $mission_url) {

        $this->mission = new ffami_mission();
        $this->mission->id = $mission_id;
        $this->mission->url = $mission_url;

        // Fetch the mission data from the FF Agent API
        $this->fetch_mission_data();

        // only import if the mission is new or updated
        if ($this->is_new_mission() || $this->is_updated_mission()) {

            // Initialize the import process
            $this->import_mission_data();

            //add images to the post
            if ($this->mission->has_images) {
                new ffami_image_import($this->mission);
            }
        } 
    }



    /**
     * Check if the mission is new or already imported
     *
     * @return bool
     */
    private function is_new_mission(): bool {

        //check if there is a post with the post meta field "ffami_mission_id" and value of $this->mission->id
        $args = [
            'post_type'  => 'mission',
            'meta_query' => [
                [
                    'key'     => 'ffami_mission_id',
                    'value'   => $this->mission->id,
                    'compare' => '=',
                ],
            ],
        ];
        $query = new WP_Query($args);
        if ($query->have_posts()) {
            $post = $query->posts[0];
            $this->existing_post_id = (int)$post->ID;
            // Read hash from either key (backward compatibility)
            $hash = get_post_meta($this->existing_post_id, 'ffami_mission_md5_hash', true);
            if (!$hash) {
                $hash = get_post_meta($this->existing_post_id, 'ffami_mission_hash', true);
            }
            $this->existing_post_hash = (string)$hash;
            return false; // not new
        }
        return true; // no posts found -> new
    }



    /**
     * Check if the mission was updated
     *
     * @return mixed post_id if the mission was already imported, false if not
     */
    private function is_updated_mission(): bool {

        if (strlen($this->existing_post_hash) === 0) {
            return false; // nothing stored => treat as new elsewhere
        }
        // Compare newly computed mission hash (already set in mission object after fetch)
        return ($this->mission->md5_hash !== $this->existing_post_hash);
    }



    /**
     * Import the mission data from the FF Agent API
     *
     * @return void
     */
    private function import_mission_data() {
        $this->save_mission_data();
    }



    /**
     * Does the nitty-gritty of fetching the mission data from the FF Agent API
     *
     * @return array|false
     */
    private function fetch_mission_data(): bool {
        $url = FFAMI_DATA_ROOT . $this->mission->url;

        $response = wp_remote_get($url, [
            'timeout'     => 10,
            'redirection' => 3,
            'headers'     => [ 'Accept' => 'application/json' ],
        ]);

        if (is_wp_error($response)) {
            error_log('FFAMI mission fetch error: ' . $response->get_error_message());
            if (is_admin()) {
                echo '<div class="notice notice-error"><p>Fehler beim Abrufen der Einsatzdaten: ' . esc_html($response->get_error_message()) . '</p></div>';
            }
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('FFAMI mission fetch HTTP ' . $code . ' for ' . $url);
            if (is_admin()) {
                echo '<div class="notice notice-error"><p>Fehler: Server antwortete mit HTTP ' . esc_html((string)$code) . '</p></div>';
            }
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            error_log('FFAMI mission fetch empty body for ' . $url);
            return false;
        }

        $rawMissionData = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('FFAMI mission JSON decode error: ' . json_last_error_msg());
            if (is_admin()) {
                echo '<div class="notice notice-error"><p>Fehler beim Parsen der Einsatzdaten: ' . esc_html(json_last_error_msg()) . '</p></div>';
            }
            return false;
        }

        // Import mission data into the domain object
        $this->mission->import_mission_data($rawMissionData);
        return true;
    }



    /**
     * Save the mission data as a custom post in WordPress and store the metadata
     *
     * @param array $mission_data
     * @return int post ID of the created or updated post
     */
    private function save_mission_data(): int {

        // Generate permalink in the format "YYYY-MM-DD_" + post_title
        $permalink = date('Y-m-d-h-i', strtotime($this->mission->datetime)) . '_' . sanitize_title($this->mission->title);

        // Create a new custom post (type "mission")
        $post_arr = [
            'post_type'    => 'mission',
            'post_title'   => $this->mission->title,
            'post_content' => $this->mission->content,
            'post_date'    => $this->mission->datetime,
            'post_status'  => 'publish',
            'post_name'    => $permalink,
        ];

        // If the mission was already imported, update the existing post
        if ($this->existing_post_id && !$this->is_new_mission()) {
            // If it's an update scenario and hashes differ, update existing post
            if ($this->is_updated_mission()) {
                $post_arr['ID'] = $this->existing_post_id;
            } else {
                // No changes -> skip persistence of duplicate content
                return $this->existing_post_id;
            }
        }

        $post_id = wp_insert_post($post_arr);

        if (is_wp_error($post_id)) {
            error_log('Error creating mission post: ' . $post_id->get_error_message());
            return 0;
        } else {
            //store the post ID in the mission object
            $this->mission->post_id = $post_id;

            // store the mission data as metadata in the post
            $this->mission->store_mission_metadata();

            //return the post ID
            return $post_id;
        }
    }
}
