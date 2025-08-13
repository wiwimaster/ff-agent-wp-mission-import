<?php

/**
 * Periodischer Import/Abgleich der FF Agent Missionsdaten per WP-Cron.
 *
 * Ablauf alle 5 Minuten:
 * 1. Root-Index (FFAMI_FILE_MAIN) laden (enthält Jahre).
 * 2. Für jedes Jahr die Jahres-Datei laden und MD5 vergleichen (Option ffami_year_md5_<jahr>).
 * 3. Bei Änderung: Alle Missionen aus der Jahres-Datei importieren (Neu-/Update-Logik steckt bereits in ffami_single_mission_import).
 *
 * Vereinfachung: Wenn eine Jahresdatei sich ändert, werden ALLE darin aufgeführten Missionen erneut angefragt.
 * (Die eigentliche Klasse verhindert unnötiges Speichern falls Hash identisch.)
 */
class ffami_cron {

    public const CRON_HOOK = 'ffami_cron_import';
    private const OPTION_ROOT_MD5 = 'ffami_root_md5';
    private const OPTION_PENDING = 'ffami_pending_missions';
    private const MAX_PER_RUN = 10; // Limit pro Cron-Lauf

    public function __construct() {
        add_filter('cron_schedules', [ $this, 'add_schedules' ]);
        add_action(self::CRON_HOOK, [ $this, 'run' ]);
        // Sicherstellen, dass der Event existiert (auch nach manuellem Löschen)
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'five_minutes', self::CRON_HOOK);
        }
    }

    /**
     * Aktivierung: Cron einplanen.
     */
    public static function activate() : void {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'five_minutes', self::CRON_HOOK);
        }
    }

    /**
     * Deaktivierung: Cron bereinigen.
     */
    public static function deactivate() : void {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
    }

    /**
     * Ergänzt eigenes Intervall von 5 Minuten.
     */
    public function add_schedules($schedules) {
        if (! isset($schedules['five_minutes'])) {
            $schedules['five_minutes'] = [
                'interval' => 300,
                'display'  => __('Alle 5 Minuten (FF Agent Import)', 'ffami'),
            ];
        }
        return $schedules;
    }

    /**
     * Hauptlauf mit Queue & selektivem Diff.
     */
    public function run() : void {
        if (! defined('FFAMI_FILE_MAIN')) { return; }

        // Queue laden
        $queue = $this->load_queue();

        // Nur neue Diffs berechnen, wenn noch Platz für neue Einträge (spart Arbeit bei langer Queue)
        if (count($queue) < self::MAX_PER_RUN) {
            $this->update_queue_with_diffs($queue);
        }

        if (empty($queue)) { return; }

        // Bis zu MAX_PER_RUN Elemente importieren
        $toProcess = array_splice($queue, 0, self::MAX_PER_RUN);
        $imported = 0;
        foreach ($toProcess as $item) {
            $missionUrl = $item['url'] ?? null;
            $missionId  = $item['mission_id'] ?? null;
            if (!$missionUrl || !$missionId) { continue; }
            try {
                new ffami_single_mission_import($missionId, $missionUrl);
                $imported++;
            } catch (\Throwable $e) {
                error_log('FFAMI Cron: Fehler beim Import in Queue: ' . $e->getMessage());
            }
        }
        // Rest speichern
        $this->save_queue($queue);
        if ($imported > 0) {
            error_log('FFAMI Cron: Importiert ' . $imported . ' Mission(en); verbleibend: ' . count($queue));
        }
    }

    /**
     * Lädt Root & Jahresdaten, findet Diffs und hängt geänderte Missionen an die Queue an.
     */
    private function update_queue_with_diffs(array &$queue) : void {
    if (!defined('FFAMI_FILE_MAIN')) { return; }
    $mainUrl = constant('FFAMI_FILE_MAIN');
    if (!$mainUrl) { return; }
    $rootPayload = $this->fetch_json($mainUrl);
        if (!$rootPayload) { return; }
        [ $rootData, $rootRaw ] = $rootPayload;
        $rootMd5 = md5($rootRaw);
        $storedRoot = get_option(self::OPTION_ROOT_MD5, '');
        if ($storedRoot !== $rootMd5) {
            update_option(self::OPTION_ROOT_MD5, $rootMd5, false);
            update_option('ffami_root_json', $rootRaw, false);
        }
        if (! isset($rootData['years']) || ! is_array($rootData['years'])) { return; }

        foreach ($rootData['years'] as $year => $info) {
            if (!isset($info['url'])) { continue; }
            $yearUrl = FFAMI_DATA_ROOT . $info['url'];
            $yearPayload = $this->fetch_json($yearUrl);
            if (!$yearPayload) { continue; }
            [ $yearData, $yearRaw ] = $yearPayload;
            $yearMd5 = md5($yearRaw);
            $optKey = 'ffami_year_md5_' . sanitize_key((string)$year);
            $storedYearMd5 = get_option($optKey, '');
            if ($storedYearMd5 === $yearMd5) { continue; }

            // Vorherige Jahresdaten für Diff
            $oldRaw = get_option('ffami_year_json_' . sanitize_key((string)$year), '');
            $oldData = $oldRaw ? json_decode($oldRaw, true) : [];
            $newMissions = $this->extract_missions($yearData);
            $oldMissions = $this->extract_missions($oldData);
            $diff = $this->diff_missions($oldMissions, $newMissions);

            // Status speichern
            update_option($optKey, $yearMd5, false);
            update_option('ffami_year_json_' . sanitize_key((string)$year), $yearRaw, false);

            if (!empty($diff)) {
                $this->append_diff_to_queue($queue, $diff, (string)$year);
            }
        }
    }

    /**
     * Vergleicht alte und neue Missionslisten und liefert neue/geänderte Einträge.
     */
    private function diff_missions(array $old, array $new) : array {
        $oldIndex = [];
        foreach ($old as $entry) {
            $k = $this->mission_key($entry);
            if ($k) { $oldIndex[$k] = md5(json_encode($entry)); }
        }
        $changed = [];
        foreach ($new as $entry) {
            $k = $this->mission_key($entry);
            if (!$k) { continue; }
            $hash = md5(json_encode($entry));
            if (!isset($oldIndex[$k]) || $oldIndex[$k] !== $hash) {
                $changed[] = $entry;
            }
        }
        return $changed;
    }

    private function mission_key(array $entry) : ?string {
        if (!empty($entry['url'])) { return (string)$entry['url']; }
        if (!empty($entry['id'])) { return 'id:' . $entry['id']; }
        if (isset($entry['alarmDate'])) { return 'ad:' . (string)$entry['alarmDate']; }
        return null;
    }

    private function append_diff_to_queue(array &$queue, array $diff, string $year) : void {
        $existingKeys = [];
        foreach ($queue as $q) { if (!empty($q['queue_key'])) { $existingKeys[$q['queue_key']] = true; } }
        foreach ($diff as $entry) {
            $url = $entry['url'] ?? null; if (!$url) { continue; }
            $missionId = $this->derive_mission_id($entry);
            $queueKey = $url . '|' . $missionId;
            if (isset($existingKeys[$queueKey])) { continue; }
            $queue[] = [
                'queue_key'  => $queueKey,
                'mission_id' => $missionId,
                'url'        => $url,
                'year'       => $year,
                'source'     => 'year-diff'
            ];
        }
        $this->save_queue($queue);
    }

    private function load_queue() : array {
        $data = get_option(self::OPTION_PENDING, []);
        return is_array($data) ? $data : [];
    }

    private function save_queue(array $queue) : void {
        update_option(self::OPTION_PENDING, $queue, false);
    }

    /**
     * Liefert [decodedArray, rawString] oder null.
     */
    private function fetch_json(string $url) : ?array {
        $response = wp_remote_get($url, [ 'timeout' => 10, 'headers' => [ 'Accept' => 'application/json' ] ]);
        if (is_wp_error($response)) {
            error_log('FFAMI Cron fetch error: ' . $response->get_error_message() . ' URL: ' . $url);
            return null;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('FFAMI Cron HTTP ' . $code . ' URL: ' . $url);
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            return null;
        }
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('FFAMI Cron JSON decode error: ' . json_last_error_msg() . ' URL: ' . $url);
            return null;
        }
        return [ $data, $body ];
    }

    /**
     * Versucht Missionseinträge aus einer Jahresdatei zu extrahieren.
     */
    private function extract_missions($yearData) : array {
        if (is_array($yearData)) {
            // Häufige Varianten
            if (isset($yearData['missions']) && is_array($yearData['missions'])) {
                return $yearData['missions'];
            }
            if (isset($yearData['data']) && is_array($yearData['data'])) {
                return $yearData['data'];
            }
            // Falls direkt ein numerisches Array
            $isNumericArray = array_keys($yearData) === range(0, count($yearData) - 1);
            if ($isNumericArray) {
                return $yearData;
            }
        }
        return [];
    }

    /**
     * Leitet eine Mission-ID ab.
     * 1) Bevorzugt alarmDate (ms) -> Format Y-m-d_H-i-s
     * 2) Falls vorhanden field 'id'
     * 3) Fallback: Hash der URL
     */
    private function derive_mission_id(array $entry) : string {
        if (isset($entry['alarmDate']) && is_numeric($entry['alarmDate'])) {
            $ts = (int)($entry['alarmDate'] / 1000);
            return gmdate('Y-m-d_H-i-s', $ts);
        }
        if (!empty($entry['id'])) {
            return (string)$entry['id'];
        }
        if (!empty($entry['url'])) {
            return substr(md5($entry['url']), 0, 16);
        }
        return uniqid('mission_', true);
    }
}
