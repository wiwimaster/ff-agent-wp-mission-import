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

        // only import if the mission is new or updated
        if ($this->is_new_mission() || $this->updated_mission()) {

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

        return true;
    }



    /**
     * Check if the mission was updated
     *
     * @return mixed post_id if the mission was already imported, false if not
     */
    private function updated_mission(): mixed {

        //check if the mission was already imported

        return false;
    }



    /**
     * Import the mission data from the FF Agent API
     *
     * @return void
     */
    private function import_mission_data() {
        // Fetch the mission data from the FF Agent API
        $mission_data = $this->fetch_mission_data();

        // Process the mission data
        if ($mission_data) {
            $this->save_mission_data($mission_data);
        } else {
            // Handle error: No data fetched
            error_log('No mission data fetched from FF Agent API.');
        }
    }



    /**
     * Does the nitty-gritty of fetching the mission data from the FF Agent API
     *
     * @return array|false
     */
    private function fetch_mission_data() {
        $url = FFAMI_DATA_ROOT . $this->mission->url;

        $urlContent = file_get_contents($url);

        $urlData = json_decode($urlContent, true);
        if (json_last_error() === JSON_ERROR_NONE) {

            return $urlData;
        } else {
            echo '<div>Fehler beim Parsen des JSON: ' . json_last_error_msg() . '</div>';
            return false;
        }
    }



    /**
     * Save the mission data as a custom post in WordPress and store the metadata
     *
     * @param array $mission_data
     * @return int post ID of the created or updated post
     */
    private function save_mission_data($mission_data): int {

        //import mission data into the mission object
        $this->mission->import_mission_data($mission_data);

        // Generate permalink in the format "YYYY-MM-DD_" + post_title
        $permalink = date('Y-m-d-h-i', strtotime($post_date)) . '_' . sanitize_title($post_title);

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
        if ($this->updated_mission()) {
            $post_arr['ID'] = $this->updated_mission();
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
