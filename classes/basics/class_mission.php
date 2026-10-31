<?php

/**
 * Domänenobjekt für einen Einsatz (Mission).
 *
 * Kapselt Rohdaten der API sowie abgeleitete Felder (Titel, Hash, Dauer etc.).
 * Enthält Parser für die API-JSON Struktur und speichert Metadaten am WP-Post.
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

    public int $alarm_ts = 0; // Alarmzeit als Unix-Timestamp (UTC), Schlüssel für die MP-Feuer-Zuordnung

    public DateInterval $duration;
    public int $duration_minutes = 0; // Normalisierter Minutenwert für Queries

    public string $location;

    public int $person_count;

    public string $mission_type;

    public array $vehicles = [];

    public string $md5_hash;

    // Ergänzungen aus dem MP-Feuer-Export (leer, wenn nicht zugeordnet)
    public string $mpfeuer_id = '';

    public ?int $start_ts = null;

    public ?int $end_ts = null;

    public string $stichwort = '';

    public string $einsatzart = '';

    public string $kategorie = '';

    public array $units = [];

    public string $ortsteil = '';



    /**
     * Übernimmt Rohdaten (assoziatives Array der API) und befüllt das Objekt.
     * Erwartet vorhandene Schlüssel analog zur FF-Agent API.
     */
    public function import_mission_data($mission_data) {
        $this->set_content($mission_data['detail']);
        $this->set_datetime($mission_data['alarmDate']);
        $this->set_image_urls($mission_data['images']);
        $this->set_duration($mission_data['duration'] ?? null);
        $this->set_location($mission_data['location']);
        $this->set_person_count($mission_data['personCount']);
        $this->set_mission_type($mission_data['type']);
        $this->set_vehicles($mission_data['vehicles']);
        $this->set_title($mission_data['title'], $mission_data['type'] ?? '');
        $this->set_raw_title($mission_data['title']);

        $this->set_md5_hash($mission_data);
    }


    /**
     * Speichert alle relevanten Eigenschaften als Post Meta.
     * Schreibt sowohl neue als auch legacy Meta-Keys (Hash-Kompatibilität).
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
        if ($this->alarm_ts > 0) {
            update_post_meta($this->post_id, 'ffami_alarm_ts', $this->alarm_ts);
        }
    }


    /**
     * Titel setzen (kombiniert Typ, Roh-Titel und Ort, sofern vorhanden).
     */
    public function set_title($title, $mission_type = ''): void {
        $base = is_string($title) ? trim($title) : '';
        if ($base === '') {
            $base = $this->resolve_title_from_type($mission_type);
        }
        $this->title = $this->expand_title_abbreviations($base);
    }



    /**
     * Einsatz-Beschreibung filtern (nur erlaubte HTML-Tags via wp_kses).
     */
    public function set_content($content): void {
        // Nur zugelassene Grund-Tags erlauben (Filter erweiterbar)
        $allowed = apply_filters('ffami_allowed_content_tags', [
            'p' => [],
            'br' => [],
            'strong' => [],
            'em' => [],
            'ul' => [],
            'ol' => [],
            'li' => [],
            'a' => ['href' => [], 'title' => []],
            'blockquote' => []
        ]);
        if (is_string($content)) {
            $clean = wp_kses($content, $allowed);
        } else {
            $clean = '';
        }
        $this->content = $clean;
    }



    /**
     * Zeitstempel übernehmen (ms seit Epoch) -> MySQL Format.
     */
    public function set_datetime($datetime): void {
        if (isset($datetime)) {
            // API liefert ms seit Unix-Epoch (angenommen UTC) -> in Site-TZ (z.B. Europe/Berlin mit DST) umrechnen
            $ts = (int) ($datetime / 1000);
            $this->alarm_ts = $ts;
            try {
                $dt = new DateTime('@' . $ts); // Basis UTC
                // WordPress-Site-Zeitzone (Einstellungen) verwenden, fällt zurück auf blog GMT offset
                if (function_exists('wp_timezone')) {
                    $dt->setTimezone(wp_timezone());
                } else {
                    $offset = (float) get_option('gmt_offset', 0);
                    $dt->modify(($offset >= 0 ? '+' : '-') . abs($offset) . ' hours');
                }
                $this->datetime = $dt->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                $this->datetime = current_time('mysql');
            }
        } else {
            $this->datetime = current_time('mysql');
        }
    }



    /**
     * Bild-URL Liste setzen und Flag has_images bestimmen.
     */
    public function set_image_urls($image_urls): void {
        $this->image_urls = $image_urls ?? [];

        // Check if there are images to process
        $this->has_images = !empty($this->image_urls);
    }



    /**
     * Dauer parsen; unterstützt mehrere Formate und speichert Minuten + DateInterval.
     */
    public function set_duration($duration): void {
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
                ffami_debug_logger::log('Unbekanntes Duration Format', ['raw' => $orig]);
            }
        }
        $hours       = intdiv($totalMinutes, 60);
        $minutesLeft = $totalMinutes % 60;
        $this->duration_minutes = $totalMinutes;
        $this->duration = new DateInterval(sprintf('PT%dH%dM', $hours, $minutesLeft));
    }


    /**
     * Ort bereinigen (entfernt Platzhaltertexte).
     */
    public function set_location($location): void {
        $this->location = $location ? str_replace(" -- Default ORT ---", "", $location) : "";
    }



    /**
     * Anzahl beteiligter Personen.
     */
    public function set_person_count($person_count): void {
        $this->person_count = isset($person_count) ? (int)$person_count : 0;
    }



    /**
     * Typ normalisieren (Mapping + Formatierung) – filterbar.
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
            $normalized_type = preg_replace('/\s+/', ' ', str_replace('_', ' ', $raw_type));
            $normalized_type = ucwords(strtolower($normalized_type));
        }
        $this->mission_type = apply_filters('ffami_normalized_mission_type', $normalized_type, $raw_type, $this);
    }

    public function set_raw_title($title): void {
        $base = $title ?? '';
        $this->raw_title = $this->expand_title_abbreviations($base);
    }

    private function resolve_title_from_type($mission_type): string {
        $raw_type = is_string($mission_type) ? trim($mission_type) : '';
        $upper = strtoupper($raw_type);
        $default_map = [
            'FIRST_RESPONDER' => 'First Responder Einsatz',
            'THL' => 'THL Einsatz',
            'FIRE' => 'Brandeinsatz',
            'OTHER' => 'Einsatz',
            'TECHNISCHE HILFELEISTUNG' => 'THL Einsatz',
            'BRAND' => 'Brandeinsatz',
            'SONSTIGES' => 'Einsatz',
        ];
        $map = apply_filters('ffami_type_title_map', $default_map, $this);
        if (is_array($map) && isset($map[$upper]) && is_string($map[$upper])) {
            return trim($map[$upper]);
        }
        if ($raw_type !== '') {
            return preg_replace('/\s+/', ' ', str_replace('_', ' ', $raw_type)) ?: 'Einsatz';
        }
        return 'Einsatz';
    }


    /**
     * Fahrzeugliste extrahieren (Titel sortiert, case-insensitiv).
     */
    public function set_vehicles($vehicles): void {
        $titles = [];
        foreach ($vehicles as $vehicle) {
            if (isset($vehicle['title'])) {
                $titles[] = (string)$vehicle['title'];
            }
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
            'lastModified',
            'lastUpdate',
            'updatedAt',
            'updateDate',
            'fetchedAt',
            '_timestamp',
            '_ts'
        ]);
        if (is_array($data)) {
            // numerische Liste vs. assoziativ unterscheiden
            $isList = array_keys($data) === range(0, count($data) - 1);
            if ($isList) {
                $out = [];
                foreach ($data as $v) {
                    $out[] = $this->normalize_for_hash($v);
                }
                return $out; // Reihenfolge beibehalten für Listen (z.B. Bilder)
            } else {
                // assoziativ -> flüchtige Keys entfernen und danach ksort für stabile Ordnung
                $out = [];
                foreach ($data as $k => $v) {
                    if (in_array($k, $ephemeral, true)) {
                        continue;
                    }
                    $out[$k] = $this->normalize_for_hash($v);
                }
                ksort($out, SORT_STRING);
                return $out;
            }
        }
        return $data;
    }

    /** Normalisierte Dauer in Minuten. */
    public function get_duration_minutes(): int {
        return $this->duration_minutes;
    }

    /** Ersetzt definierte Abkürzungen am Titelanfang (filterbar). */
    private function expand_title_abbreviations(string $title): string {
        if ($title === '') {
            return $title;
        }
        $abbrMap = apply_filters('ffami_title_abbreviation_map', ['VU' => 'Verkehrsunfall', 'RD' => 'Rettungsdienst', 'BMA' => 'Brandmeldeanlage']);
        if (!is_array($abbrMap)) {
            return $title;
        }
        foreach ($abbrMap as $abbr => $expanded) {
            $abbr = (string)$abbr;
            $expanded = (string)$expanded;
            if ($abbr === '') {
                continue;
            }
            $len = strlen($abbr);
            if (strncasecmp($title, $abbr, $len) === 0) {
                $pattern = '/^' . preg_quote($abbr, '/') . '(?=\b|\s)/i';
                $new = preg_replace($pattern, $expanded, $title, 1);
                if (is_string($new) && $new !== '') {
                    return $new;
                }
            }
        }
        return $title;
    }

    /**
     * Rekonstruiert ein Missionsobjekt aus einem bestehenden Post (Meta + Post Felder)
     */
    public static function from_post(int $post_id): ?self {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'mission') {
            return null;
        }
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
            $h = intdiv($m->duration_minutes, 60);
            $mi = $m->duration_minutes % 60;
            $m->duration = new DateInterval(sprintf('PT%dH%dM', $h, $mi));
        } else {
            $d = get_post_meta($post_id, 'ffami_mission_duration', true);
            if ($d instanceof DateInterval) {
                $m->duration = $d;
            } else {
                $m->duration = new DateInterval('PT0H0M');
            }
        }
        $m->alarm_ts = (int) get_post_meta($post_id, 'ffami_alarm_ts', true);
        $m->apply_mpfeuer_meta();
        // Abgeleitete Felder
        $m->set_title($m->raw_title, $m->mission_type);
        $m->has_images = has_post_thumbnail($post_id);
        $m->image_urls = []; // nicht rekonstruierbar aus WP direkt
        return $m;
    }

    /**
     * Legt zugeordnete MP-Feuer-Werte (Meta ffami_mpf_*) über die Widget-Felder,
     * wo sie präziser oder umfangreicher sind. Adresse und Besatzungsstärke
     * werden bewusst nicht geladen (nur intern gespeichert).
     */
    private function apply_mpfeuer_meta(): void {
        $this->mpfeuer_id = (string) get_post_meta($this->post_id, 'ffami_mpf_id', true);
        if ($this->mpfeuer_id === '') {
            return;
        }
        $this->stichwort = (string) get_post_meta($this->post_id, 'ffami_mpf_stichwort', true);
        $this->einsatzart = ffami_mpfeuer_record::clean_einsatzart((string) get_post_meta($this->post_id, 'ffami_mpf_einsatzart', true));
        $this->kategorie = (string) get_post_meta($this->post_id, 'ffami_mpf_kategorie', true);
        $this->units = array_values(array_filter((array) get_post_meta($this->post_id, 'ffami_mpf_units', true), 'is_string'));
        $this->ortsteil = (string) get_post_meta($this->post_id, 'ffami_mpf_ortsteil', true);

        // Beginn/Ende auf die Sekunde ersetzen Alarmzeit und Dauer-Text des Widgets
        $start = (int) get_post_meta($this->post_id, 'ffami_mpf_start_ts', true);
        $end = (int) get_post_meta($this->post_id, 'ffami_mpf_end_ts', true);
        if ($start > 0) {
            $this->start_ts = $start;
            $this->datetime = wp_date('Y-m-d H:i:s', $start);
        }
        if ($start > 0 && $end > $start) {
            $this->end_ts = $end;
            $this->duration_minutes = (int) round(($end - $start) / 60);
            $this->duration = new DateInterval(sprintf('PT%dH%dM', intdiv($this->duration_minutes, 60), $this->duration_minutes % 60));
        }

        // Fahrzeuge (eigene Namen): längere Liste gewinnt, bei Gleichstand MP-Feuer
        $vehicles = array_values(array_filter((array) get_post_meta($this->post_id, 'ffami_mpf_vehicles', true), 'is_string'));
        if ($vehicles && count($vehicles) >= count($this->vehicles)) {
            $this->vehicles = $vehicles;
        }

        // Personal: nur Anzahl, größere Zahl gewinnt
        $persons = (int) get_post_meta($this->post_id, 'ffami_mpf_person_count', true);
        if ($persons > $this->person_count) {
            $this->person_count = $persons;
        }
    }
}
