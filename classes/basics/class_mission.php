<?php

/**
 * Class for representing a mission
 *
 * This class handles the properties and methods related to a mission.
 *
 * @package ffami
 */
class ffami_mission {

    public string $id;

    public string $title;

    public string $url;

    public bool $has_images;

    public $image_urls;

    public string $content;

    public int $post_id;

    public string $datetime;

    public DateInterval $duration;

    public string $location;

    public int $person_count;

    public string $mission_type;

    public array $vehicles = [];

    public string $md5_hash;



    /**
     * Processes the mission data and sets the properties
     *
     * @param array $mission_data
     * @return void
     */
    public function import_mission_data($mission_data) {
        $this->set_content($mission_data['detail']);
        $this->set_datetime($mission_data['alarmDate']);
        $this->set_image_urls($mission_data['images']);
        $this->set_duration($mission_data['duration']);
        $this->set_location($mission_data['location']);
        $this->set_person_count($mission_data['personCount']);
        $this->set_mission_type($mission_data['missionType']);
        $this->set_vehicles($mission_data['vehicles']);
        $this->set_title($mission_data['title']);
        
        $this->set_md5_hash($mission_data);
    }


    /**
     * Store the mission metadata in the post
     *
     * @return void
     */
    public function store_mission_metadata() {
        update_post_meta($this->post_id, 'ffami_mission_id', $this->id);
        update_post_meta($this->post_id, 'ffami_mission_url', $this->url);
        update_post_meta($this->post_id, 'ffami_mission_duration', $this->duration);
        update_post_meta($this->post_id, 'ffami_mission_location', $this->location);
        update_post_meta($this->post_id, 'ffami_mission_person_count', $this->person_count);
        update_post_meta($this->post_id, 'ffami_mission_type', $this->mission_type);
        update_post_meta($this->post_id, 'ffami_mission_vehicles', $this->vehicles);
        update_post_meta($this->post_id, 'ffami_mission_hash', $this->md5_hash);
    }


    /**
     * Set the title of the mission
     *
     * @param string $title Title of the mission
     * @return void
     */
    public function set_title($title): void {
        $this->title = $title ?? "Einsatz " . $this->mission_type . " (" . $this->location . ")";
    }

    

    /**
     * Set the content of the mission
     *
     * @param string $content Content of the mission
     * @return void
     */
    public function set_content($content): void {
        $this->content = $content ?? "";
    }



    /**
     * Set the datetime of the mission
     *
     * @param int $datetime Datetime in milliseconds since epoch
     * @return void
     */
    public function set_datetime($datetime): void {
        $this->datetime = isset($datetime)
            ? date('Y-m-d H:i:s', $datetime / 1000)
            : current_time('mysql');
    }



    /*
     * Set the image URLs
     *
     * @param array $image_urls Array of image URLs
     * @return void
     */
    public function set_image_urls($image_urls): void {
        $this->image_urls = $image_urls ?? [];

        // Check if there are images to process
        $this->has_images = !empty($this->image_urls);
    }



    /**
     * Set the duration of the mission
     *
     * @param string $duration Duration in format "1 hour 30 minutes"
     * @return void
     */
    public function set_duration(string $duration): void {
        // 1) Gesamtminuten ermitteln wie gehabt
        if (preg_match(
            '/^(?P<h>\d+)\s*h(?:ou?rs?)?[\s,]*(?P<m>\d+)\s*min$/i',
            $duration, $m
        )) {
            $totalMinutes = (int)$m['h'] * 60 + (int)$m['m'];
        }
        elseif (preg_match('/^(?P<m>\d+)\s*min$/i', $duration, $m)) {
            $totalMinutes = (int)$m['m'];
        }
        else {
            throw new \InvalidArgumentException("Ungültiges Duration-Format: $duration");
        }
    
        // 2) Stunden und verbleibende Minuten aufteilen
        $hours       = intdiv($totalMinutes, 60);
        $minutesLeft = $totalMinutes % 60;
        
        // 3) DateInterval korrekt anlegen
        $this->duration = new DateInterval(
            sprintf('PT%dH%dM', $hours, $minutesLeft)
        );
    
    }


    /**
     * Set the location of the mission
     *
     * @param string $location Location of the mission
     * @return void
     */
    public function set_location($location): void {
        $this->location = $location ? str_replace(" -- Default ORT ---", "", $location) : "";
    }



    /**
     * Set the person count of the mission
     *
     * @param int $person_count Person count of the mission
     * @return void
     */
    public function set_person_count($person_count): void {
        $this->person_count = isset($person_count) ? (int)$person_count : 0;
    }



    /**
     * Set the mission type
     *
     * @param string $mission_type Mission type of the mission
     * @return void
     */
    public function set_mission_type($mission_type): void {
        $this->mission_type = $mission_type ?? "";
    }



    /**
     * Set the vehicles involved in the mission
     *
     * @param array $vehicles Array of vehicles
     * @return void
     */
    public function set_vehicles($vehicles): void {
        foreach ($vehicles as $vehicle) {
            $this->vehicles[] = $vehicle['title'];
        }
    }


    private function set_md5_hash($mission): void {
        $this->md5_hash = md5(serialize($mission));;
    }
}
