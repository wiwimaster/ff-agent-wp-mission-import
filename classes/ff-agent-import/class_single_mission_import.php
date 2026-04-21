<?php

/**
 * Einzel-Import einer Mission von der FF Agent API.
 * Lädt Detail-JSON, parst in ffami_mission und legt/aktualisiert den CPT.
 */
class ffami_single_mission_import {



    /**
     * The mission object
     *
     * @var ffami_mission
     */
    private ffami_mission $mission;

    // removed unused $stored_mission,$mission_data

    private string $existing_post_hash = '';

    private int $existing_post_id = 0;

    private float $started_at = 0.0;



    /**
     * @param string $mission_id Interne/abgeleitete Einsatz-ID
     * @param string $mission_url Relativer API-Pfad (beginnend mit /hpWidget/...)
     */
    public function __construct($mission_id, $mission_url) {
        $this->started_at = microtime(true);

        $this->mission = new ffami_mission();
        $this->mission->id = $mission_id;
        $this->mission->url = $mission_url;

        $this->register_shutdown_logger();

        // 1. Daten abrufen
        if (!$this->fetch_mission_data()) {
            throw new RuntimeException('Mission detail fetch failed for ' . $mission_id);
        }

        // 2. Neu oder geändert?
        $isNew = $this->is_new_mission();
        $isUpdated = !$isNew && $this->is_updated_mission();
        if ($isNew || $isUpdated) {
            // 3. Speichern
            $this->import_mission_data();

            // 4. Bilder
            if ($this->mission->has_images) {
                new ffami_image_import($this->mission);
            }
        } 
    }

    private function log_event(string $message, array $context = []) : void {
        if (class_exists('ffami_debug_logger')) {
            ffami_debug_logger::log($message, $context);
        }
    }

    private function log_error(string $message, array $context = []) : void {
        $line = 'FFAMI Import: ' . $message;
        if (!empty($context)) {
            $json = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json) && $json !== '') {
                $line .= ' | ' . $json;
            }
        }
        error_log($line);
        $this->log_event('Import error: ' . $message, $context);
    }

    private function register_shutdown_logger() : void {
        $missionId = (string) $this->mission->id;
        $missionUrl = (string) $this->mission->url;
        $startedAt = $this->started_at;
        register_shutdown_function(function () use ($missionId, $missionUrl, $startedAt) {
            $error = error_get_last();
            if (!$error) {
                return;
            }
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($error['type'] ?? null, $fatalTypes, true)) {
                return;
            }
            $context = [
                'mission_id' => $missionId,
                'mission_url' => $missionUrl,
                'error_type' => $error['type'] ?? null,
                'message' => $error['message'] ?? '',
                'file' => $error['file'] ?? '',
                'line' => $error['line'] ?? 0,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
            error_log('FFAMI Import fatal shutdown: ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if (class_exists('ffami_debug_logger')) {
                ffami_debug_logger::log('Import fatal shutdown', $context);
            }
        });
    }



    /** Prüft, ob Einsatz-ID bereits existiert. */
    private function is_new_mission(): bool {
        $repo = new ffami_mission_repository();
        $existing = $repo->find_by_mission_id($this->mission->id);
        if ($existing) {
            $this->existing_post_id = $existing->post_id;
            $this->existing_post_hash = $existing->md5_hash;
            return false;
        }
        return true;
    }



    /** Prüft anhand Hash auf Datenänderung. */
    private function is_updated_mission(): bool {

        if (strlen($this->existing_post_hash) === 0) {
            return false; // nothing stored => treat as new elsewhere
        }
        // Compare newly computed mission hash (already set in mission object after fetch)
        return ($this->mission->md5_hash !== $this->existing_post_hash);
    }



    /** Durchführen (Speichern) des Imports. */
    private function import_mission_data() {
        $this->save_mission_data();
    }



    /** Lädt die JSON Detaildaten und befüllt das Missionsobjekt. */
    private function fetch_mission_data(): bool {
        $url = FFAMI_DATA_ROOT . $this->mission->url;

        $response = wp_remote_get($url, [
            'timeout'     => 10,
            'redirection' => 3,
            'headers'     => [ 'Accept' => 'application/json' ],
        ]);

        if (is_wp_error($response)) {
            $this->log_error('Mission fetch WP error', [
                'mission_id' => $this->mission->id,
                'url' => $url,
                'wp_error' => $response->get_error_message(),
            ]);
            if (is_admin()) {
                echo '<div class="notice notice-error"><p>Fehler beim Abrufen der Einsatzdaten: ' . esc_html($response->get_error_message()) . '</p></div>';
            }
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->log_error('Mission fetch HTTP error', [
                'mission_id' => $this->mission->id,
                'url' => $url,
                'http_code' => $code,
            ]);
            if (is_admin()) {
                echo '<div class="notice notice-error"><p>Fehler: Server antwortete mit HTTP ' . esc_html((string)$code) . '</p></div>';
            }
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            $this->log_error('Mission fetch empty body', [
                'mission_id' => $this->mission->id,
                'url' => $url,
            ]);
            return false;
        }

        $rawMissionData = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error('Mission JSON decode error', [
                'mission_id' => $this->mission->id,
                'url' => $url,
                'json_error' => json_last_error_msg(),
            ]);
            if (is_admin()) {
                echo '<div class="notice notice-error"><p>Fehler beim Parsen der Einsatzdaten: ' . esc_html(json_last_error_msg()) . '</p></div>';
            }
            return false;
        }

        // Import mission data into the domain object
        $this->mission->import_mission_data($rawMissionData);
        return true;
    }



    /** CPT (neu oder Update) speichern + Metadaten schreiben. */
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
    if ($this->existing_post_id) { // is_new_mission() bereits gesetzt earlier
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
            $this->log_error('Error creating mission post', [
                'mission_id' => $this->mission->id,
                'message' => $post_id->get_error_message(),
            ]);
            return 0;
        } else {
            //store the post ID in the mission object
            $this->mission->post_id = $post_id;

            // store the mission data as metadata in the post
            $this->mission->store_mission_metadata();

            //return the post ID
            if (class_exists('ffami_debug_logger')) {
                ffami_debug_logger::log('Mission gespeichert', [
                    'id'=>$this->mission->id,
                    'post_id'=>$post_id,
                    'hash'=>$this->mission->md5_hash,
                    'duration_ms' => (int) round((microtime(true) - $this->started_at) * 1000),
                ]);
            }
            return $post_id;
        }
    }
}
