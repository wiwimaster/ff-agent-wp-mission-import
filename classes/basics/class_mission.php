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

    public string $raw_title;

    public string $url;

    public bool $has_images;

    public $image_urls;

    public string $content;

    public int $post_id;

    public string $datetime;

    public DateInterval $duration;
    public int $duration_minutes = 0; // Normalisierter Minutenwert für Queries

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
        $this->set_mission_type($mission_data['type']);
        $this->set_vehicles($mission_data['vehicles']);
        $this->set_title($mission_data['title']);
        $this->set_raw_title($mission_data['title']);

        $this->set_md5_hash($mission_data);
    }


    /**
     * Store the mission metadata in the post
     *
     * @return void
     */
    public function store_mission_metadata() {
        update_post_meta($this->post_id, 'ffami_mission_title', $this->raw_title);
        update_post_meta($this->post_id, 'ffami_mission_id', $this->id);
        update_post_meta($this->post_id, 'ffami_mission_url', $this->url);
        update_post_meta($this->post_id, 'ffami_mission_duration', $this->duration); // legacy serialized DateInterval
        update_post_meta($this->post_id, 'ffami_mission_duration_minutes', $this->duration_minutes);
        update_post_meta($this->post_id, 'ffami_mission_location', $this->location);
        update_post_meta($this->post_id, 'ffami_mission_person_count', $this->person_count);
        update_post_meta($this->post_id, 'ffami_mission_type', $this->mission_type);
        update_post_meta($this->post_id, 'ffami_mission_vehicles', $this->vehicles);
        update_post_meta($this->post_id, 'ffami_mission_hash', $this->md5_hash); // legacy key actually used elsewhere
        update_post_meta($this->post_id, 'ffami_mission_md5_hash', $this->md5_hash); // key referenced by import logic
    }


    /**
     * Set the title of the mission
     *
     * @param string $title Title of the mission
     * @return void
     */
    public function set_title($title): void {
        $base = $title ?? '';
        $parts = array_filter([
            $this->mission_type,
            $base !== '' ? '"' . $base . '"' : null,
            $this->location !== '' ? '(' . $this->location . ')' : null,
        ]);
        $this->title = implode(' ', $parts);
    }



    /**
     * Set the content of the mission
     *
     * @param string $content Content of the mission
     * @return void
     */
    public function set_content($content): void {
        // Nur zugelassene Grund-Tags erlauben (Filter erweiterbar)
        $allowed = apply_filters('ffami_allowed_content_tags', [
            'p' => [], 'br'=>[], 'strong'=>[], 'em'=>[], 'ul'=>[], 'ol'=>[], 'li'=>[], 'a'=>['href'=>[], 'title'=>[]], 'blockquote'=>[]
        ]);
        if (is_string($content)) {
            $clean = wp_kses($content, $allowed);
        } else { $clean = ''; }
        $this->content = $clean;
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
        $orig = $duration;
        $duration = trim((string)$duration);
        $totalMinutes = null;
        // Varianten erlauben: "2 h 15 min", "2h15min", "45 min", "1 h", "90" (reine Minuten), "2:30" (hh:mm)
        if (preg_match('/^(?P<h>\d+)\s*h(?:ou?rs?)?[\s,:-]*(?P<m>\d+)\s*m?(?:in)?$/i', $duration, $m)) {
            $totalMinutes = (int)$m['h'] * 60 + (int)$m['m'];
        } elseif (preg_match('/^(?P<h>\d+)\s*h(?:ou?rs?)?$/i', $duration, $m)) {
            $totalMinutes = (int)$m['h'] * 60;
        } elseif (preg_match('/^(?P<m>\d+)\s*m?(?:in)?$/i', $duration, $m)) { // reine Minuten
            $totalMinutes = (int)$m['m'];
        } elseif (preg_match('/^(?P<h>\d+):(\s)?(?P<m>\d{1,2})$/', $duration, $m)) { // hh:mm
            $totalMinutes = (int)$m['h'] * 60 + (int)$m['m'];
        }

        if ($totalMinutes === null) {
            // Fallback: unbekanntes Format -> 0 Minuten, loggen
            $totalMinutes = 0;
            if (class_exists('ffami_debug_logger')) {
                ffami_debug_logger::log('Unbekanntes Duration Format', ['raw'=>$orig]);
            }
        }
    $hours       = intdiv($totalMinutes, 60);
    $minutesLeft = $totalMinutes % 60;
    $this->duration_minutes = $totalMinutes;
    $this->duration = new DateInterval(sprintf('PT%dH%dM', $hours, $minutesLeft));
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

        $raw_type = isset($mission_type) ? trim((string)$mission_type) : '';
        $map = [
            'THL'   => 'Technische Hilfeleistung',
            'FIRE'  => 'Brand',
            'OTHER' => 'Sonstiges'
        ];
        $upper = strtoupper($raw_type);
        if (isset($map[$upper])) {
            $normalized_type = $map[$upper];
        } else {
            $normalized_type = preg_replace('/\s+/', ' ', str_replace('_',' ', $raw_type));
            $normalized_type = ucwords(strtolower($normalized_type));
        }
        $this->mission_type = apply_filters('ffami_normalized_mission_type', $normalized_type, $raw_type, $this);
    }

    public function set_raw_title($title): void {
        // Store the raw title for later use
        $this->raw_title = $title ?? "";
    }


    /**
     * Set the vehicles involved in the mission
     *
     * @param array $vehicles Array of vehicles
     * @return void
     */
    public function set_vehicles($vehicles): void {
        $titles = [];
        foreach ($vehicles as $vehicle) {
            if (isset($vehicle['title'])) { $titles[] = (string)$vehicle['title']; }
        }
        sort($titles, SORT_NATURAL | SORT_FLAG_CASE);
        $this->vehicles = $titles;
    }


    private function set_md5_hash($mission): void {
        $normalized = $this->normalize_for_hash($mission);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->md5_hash = md5(apply_filters('ffami_mission_hash_input', $json, $normalized, $this));
    }

    private function normalize_for_hash($data) {
        $ephemeral = apply_filters('ffami_ephemeral_mission_keys', [
            'lastModified','lastUpdate','updatedAt','updateDate','fetchedAt','_timestamp','_ts'
        ]);
        if (is_array($data)) {
            // numerische Liste vs. assoziativ unterscheiden
            $isList = array_keys($data) === range(0, count($data)-1);
            if ($isList) {
                $out = [];
                foreach ($data as $v) { $out[] = $this->normalize_for_hash($v); }
                return $out; // Reihenfolge beibehalten für Listen (z.B. Bilder)
            } else {
                // assoziativ -> flüchtige Keys entfernen und danach ksort für stabile Ordnung
                $out = [];
                foreach ($data as $k=>$v) {
                    if (in_array($k, $ephemeral, true)) { continue; }
                    $out[$k] = $this->normalize_for_hash($v);
                }
                ksort($out, SORT_STRING);
                return $out;
            }
        }
        return $data;
    }

    /**
     * Liefert die normalisierte Dauer in Minuten
     */
    public function get_duration_minutes() : int { return $this->duration_minutes; }

    /**
     * Rekonstruiert ein Missionsobjekt aus einem bestehenden Post (Meta + Post Felder)
     */
    public static function from_post(int $post_id) : ?self {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'mission') { return null; }
        $m = new self();
        $m->post_id = $post_id;
        $m->content = $post->post_content;
        $m->datetime = $post->post_date;
        $m->raw_title = (string) get_post_meta($post_id, 'ffami_mission_title', true);
        $m->id = (string) get_post_meta($post_id, 'ffami_mission_id', true);
        $m->url = (string) get_post_meta($post_id, 'ffami_mission_url', true);
        $m->location = (string) get_post_meta($post_id, 'ffami_mission_location', true);
        $m->person_count = (int) get_post_meta($post_id, 'ffami_mission_person_count', true);
        $m->mission_type = (string) get_post_meta($post_id, 'ffami_mission_type', true);
        $m->vehicles = (array) get_post_meta($post_id, 'ffami_mission_vehicles', true);
        $m->md5_hash = (string) (get_post_meta($post_id, 'ffami_mission_md5_hash', true) ?: get_post_meta($post_id, 'ffami_mission_hash', true));
        $m->duration_minutes = (int) get_post_meta($post_id, 'ffami_mission_duration_minutes', true);
        if ($m->duration_minutes > 0) {
            $h = intdiv($m->duration_minutes, 60); $mi = $m->duration_minutes % 60;
            $m->duration = new DateInterval(sprintf('PT%dH%dM', $h, $mi));
        } else {
            $d = get_post_meta($post_id, 'ffami_mission_duration', true);
            if ($d instanceof DateInterval) { $m->duration = $d; }
            else { $m->duration = new DateInterval('PT0H0M'); }
        }
        // Abgeleitete Felder
        $m->set_title($m->raw_title);
        $m->has_images = has_post_thumbnail($post_id);
        $m->image_urls = []; // nicht rekonstruierbar aus WP direkt
        return $m;
    }
}
