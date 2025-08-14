<?php

/**
 * Action Scheduler gesteuerter Import.
 *
 * - Alle 5 Minuten: Root + Jahresdateien prüfen (Hook: ffami_check_years)
 * - Für geänderte / neue Missionen: Einzel-Import Aktionen (Hook: ffami_import_single_mission)
 *
 * Soft Dependency: Action Scheduler (Plugin oder Bestandteil anderer Plugins wie WooCommerce).
 */
class ffami_scheduler {

    private const RECURRING_HOOK        = 'ffami_check_years';        // Stündlicher Jahres-Scan (aktuelles Jahr)
    private const RECENT_RECURRING_HOOK = 'ffami_poll_recent_missions'; // Alle 10 Minuten: letzte 5 Missionen direkt pollen
    private const FULL_RECURRING_HOOK   = 'ffami_check_years_full';   // (Optional) Wöchentlicher Full Scan
    private const IMPORT_HOOK           = 'ffami_import_single_mission';
    private const GROUP                 = 'ffami';
    private const RECENT_INTERVAL       = 600;    // 10 Minuten
    private const INTERVAL              = 3600;   // 1 Stunde (statt zuvor 5 Minuten)
    private const FULL_INTERVAL         = 604800; // 1 Woche (Full Scan)
    private const DAILY_INTERVAL        = 86400;  // 1 Tag (Root Refresh)
    private const DAILY_ROOT_HOOK       = 'ffami_refresh_root_years';
    private const OPTION_ROOT_MD5       = 'ffami_root_md5';

    private const OPTION_PENDING = 'ffami_pending_missions'; // array of mission_ids currently geplant aber noch nicht abgeschlossen

    public function __construct() {
        add_action(self::RECURRING_HOOK, [$this, 'check_years']); // stündlich aktuelles Jahr
        add_action(self::RECENT_RECURRING_HOOK, [$this, 'poll_recent_missions']); // alle 10 Minuten letzte 5 Missionen
        add_action(self::FULL_RECURRING_HOOK, [$this, 'check_years_full']); // alle Jahre (optional wöchentlich)
        add_action(self::DAILY_ROOT_HOOK, [$this, 'refresh_root_years']); // täglicher Root-Fetch
        add_action(self::IMPORT_HOOK, [$this, 'import_single_mission'], 10, 2);
        add_action('admin_notices', [$this, 'maybe_admin_notice']);
        add_action('plugins_loaded', [$this, 'schedule_recurring'], 20);
    }

    public function maybe_admin_notice() {
        if (!class_exists('ActionScheduler')) {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-warning"><p><strong>FF Agent Mission Import:</strong> Action Scheduler nicht gefunden. Bitte Plugin "Action Scheduler" installieren oder WooCommerce aktivieren.</p></div>';
            }
        }
    }

    public function schedule_recurring() {
        if (!function_exists('as_schedule_recurring_action')) {
            return;
        }
        if (! $this->has_uid()) {
            return;
        }
        if (!as_has_scheduled_action(self::RECENT_RECURRING_HOOK, [], self::GROUP)) {
            as_schedule_recurring_action(time() + 60, self::RECENT_INTERVAL, self::RECENT_RECURRING_HOOK, [], self::GROUP);
        }
        if (!as_has_scheduled_action(self::RECURRING_HOOK, [], self::GROUP)) {
            as_schedule_recurring_action(time() + 180, self::INTERVAL, self::RECURRING_HOOK, [], self::GROUP);
        }
        if (!as_has_scheduled_action(self::FULL_RECURRING_HOOK, [], self::GROUP)) {
            as_schedule_recurring_action(time() + 300, self::FULL_INTERVAL, self::FULL_RECURRING_HOOK, [], self::GROUP);
        }
        if (!as_has_scheduled_action(self::DAILY_ROOT_HOOK, [], self::GROUP)) {
            as_schedule_recurring_action(time() + 180, self::DAILY_INTERVAL, self::DAILY_ROOT_HOOK, [], self::GROUP);
        }
    }

    private function has_uid(): bool {
        $uid = get_option('ffami_uid', '');
        return !empty($uid);
    }

    public function check_years(): void { // Quick Scan: nur höchstes Jahr aus cache
        $this->run_scan('current_cached');
    }

    /**
     * Alle 10 Minuten: letzte 5 Missionen (Detaildateien) direkt pollen.
     * Vorgehen: aktuelles Jahr laden, Missionsliste sortieren, die neuesten 5 Einzelimporte planen.
     * Duplicate- und Unverändert-Erkennung übernimmt ffami_single_mission_import selbst via Hash.
     */
    public function poll_recent_missions() : void {
        if (!$this->has_uid()) { return; }
        $root = $this->get_cached_root_data(true);
        if (!$root || empty($root['years']) || !is_array($root['years'])) { return; }
        $highest = $this->get_highest_year($root['years']);
        if ($highest === null) { return; }
        $info = $root['years'][$highest] ?? null; if (!$info || empty($info['url'])) { return; }
        $yearUrl = FFAMI_DATA_ROOT . $info['url'];
        $payload = $this->fetch_json($yearUrl);
        if (!$payload) { return; }
        [ $yearData, $raw ] = $payload;
        $missions = $this->collect_missions_generic($yearData);
        if (empty($missions)) { return; }
        // Sortiere nach alarmDate (ms) desc falls vorhanden, sonst fallback auf detailUrl string
        usort($missions, function($a,$b){
            $adA = $a['alarmDate'] ?? 0; $adB = $b['alarmDate'] ?? 0;
            if ($adA == $adB) { return strcmp(($b['detailUrl']??$b['url']??''), ($a['detailUrl']??$a['url']??'')); }
            return ($adA < $adB) ? 1 : -1; // desc
        });
        $slice = array_slice($missions, 0, 5);
        $scheduled = 0;
        foreach ($slice as $mission) {
            $missionUrl = $mission['detailUrl'] ?? ($mission['url'] ?? null);
            if (!$missionUrl) { continue; }
            $missionId = $this->derive_mission_id($mission);
            if ($this->is_pending($missionId)) { continue; }
            $args = ['mission_id'=>$missionId,'mission_url'=>$missionUrl];
            if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::IMPORT_HOOK, $args, self::GROUP)) { continue; }
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(self::IMPORT_HOOK, $args, self::GROUP);
                $this->add_pending($missionId);
                $scheduled++;
            }
        }
        if ($scheduled && class_exists('ffami_debug_logger')) {
            ffami_debug_logger::log('Recent Missions geplant', ['count'=>$scheduled]);
        }
    }

    public function check_years_full(): void { // Weekly full scan
        $this->run_scan('full');
    }

    private function run_scan(string $mode): void {
        if (!$this->has_uid()) { return; }
        ffami_debug_logger::log('Scan Start', ['mode'=>$mode]);
        $rootData = $this->get_cached_root_data($mode === 'full');
        if (!$rootData) { ffami_debug_logger::log('Kein Root Cache verfügbar'); return; }
        if (!isset($rootData['years']) || !is_array($rootData['years'])) { ffami_debug_logger::log('Root ohne years'); return; }

        $scheduled = 0; $scanStats = [];
        $years = $rootData['years'];
        if ($mode === 'current_cached') {
            $highest = $this->get_highest_year($years);
            if ($highest === null || !isset($years[$highest])) { ffami_debug_logger::log('Höchstes Jahr nicht gefunden'); return; }
            $years = [ $highest => $years[$highest] ];
        }
        foreach ($years as $year => $info) {
            if (empty($info['url'])) {
                continue;
            }
            $yearUrl = FFAMI_DATA_ROOT . $info['url'];
            $yearPayload = $this->fetch_json($yearUrl);
            if (!$yearPayload) {
                ffami_debug_logger::log('Jahresdatei nicht ladbar', ['year' => $year, 'url' => $yearUrl]);
                continue;
            }
            [$yearData, $yearRaw] = $yearPayload;
            ffami_debug_logger::log('Year Keys', ['year' => $year, 'keys' => array_slice(array_keys((array)$yearData), 0, 50)]);
            update_option('ffami_year_sample_' . sanitize_key((string)$year), substr($yearRaw, 0, 2000), false);
            $yearMd5 = md5($yearRaw);
            $optKey = 'ffami_year_md5_' . sanitize_key((string)$year);
            $storedYearMd5 = get_option($optKey, '');
            if ($storedYearMd5 === $yearMd5) {
                // MD5 unverändert -> prüfen ob Jahr bereits vollständig importiert ist
                $completeFlagKey = 'ffami_year_completed_' . sanitize_key((string)$year);
                if ($mode === 'current_cached' && get_option($completeFlagKey)) { // bereits als vollständig markiert
                    continue;
                }
                // Missionsliste extrahieren und fehlende planen
                $allMissions = $this->collect_missions_generic($yearData);
                $missionCount = count($allMissions);
                if ($missionCount === 0) { // nichts gefunden
                    continue;
                }
                $missing = $this->find_missing_missions($allMissions);
                if (!empty($missing)) {
                    ffami_debug_logger::log('Jahr Vollständigkeits-Check: fehlende Missionen', [
                        'year'=>$year,
                        'missing'=>count($missing),
                        'total'=>$missionCount,
                        'pending_count'=>count($this->get_pending())
                    ]);
                    foreach ($missing as $mission) {
                        $missionUrl = $mission['detailUrl'] ?? ($mission['url'] ?? null);
                        if (!$missionUrl) { continue; }
                        $missionId = $this->derive_mission_id($mission);
                        // Wenn bereits pending -> nicht erneut planen
                        if ($this->is_pending($missionId)) {
                            ffami_debug_logger::log('Mission bereits pending, nicht erneut geplant', ['year'=>$year,'id'=>$missionId]);
                            continue;
                        }
                        $args = ['mission_id'=>$missionId,'mission_url'=>$missionUrl];
                        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::IMPORT_HOOK, $args, self::GROUP)) { continue; }
                        if (function_exists('as_enqueue_async_action')) {
                            as_enqueue_async_action(self::IMPORT_HOOK, $args, self::GROUP);
                            $scheduled++; $this->add_pending($missionId);
                            ffami_debug_logger::log('Mission nachgeplant (Vollständigkeit)', ['year'=>$year,'id'=>$missionId]);
                        }
                    }
                }
                // Wenn keine fehlenden, Jahr als vollständig markieren
                if ($mode === 'current_cached' && empty($missing)) {
                    update_option($completeFlagKey, 1, false);
                    ffami_debug_logger::log('Jahr als vollständig markiert', ['year'=>$year,'missions'=>$missionCount]);
                }
                continue;
            }
            ffami_debug_logger::log('Jahr geändert', ['year' => $year, 'md5' => $yearMd5]);

            $oldRaw = get_option('ffami_year_json_' . sanitize_key((string)$year), '');
            $oldData = $oldRaw ? json_decode($oldRaw, true) : [];
            $newMissions = $this->collect_missions_generic($yearData);
            $oldMissions = $this->collect_missions_generic($oldData);
            $diff = $this->diff_missions($oldMissions, $newMissions);
            $removed = $this->removed_missions($oldMissions, $newMissions);

            update_option($optKey, $yearMd5, false);
            update_option('ffami_year_json_' . sanitize_key((string)$year), $yearRaw, false);

            foreach ($diff as $mission) {
                ffami_debug_logger::log('Missions', $mission['detailUrl']);

                $missionUrl = $mission['detailUrl'] ?? ($mission['url'] ?? null);
                if (!$missionUrl) {
                    continue;
                }
                $missionId = $this->derive_mission_id($mission);
                if ($this->is_pending($missionId)) {
                    ffami_debug_logger::log('Mission diff erkannt aber noch pending', ['year'=>$year,'id'=>$missionId]);
                    continue;
                }
                $args = ['mission_id' => $missionId, 'mission_url' => $missionUrl];
                if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::IMPORT_HOOK, $args, self::GROUP)) {
                    continue;
                }
                if (function_exists('as_enqueue_async_action')) {
                    as_enqueue_async_action(self::IMPORT_HOOK, $args, self::GROUP);
                    $scheduled++;
                    $this->add_pending($missionId);
                    ffami_debug_logger::log('Mission geplant', ['year' => $year, 'id' => $missionId, 'url' => $missionUrl]);
                }
            }
            // Entfernte Missionen verarbeiten
            $removedDeleted = 0;
            if (!empty($removed)) {
                foreach ($removed as $mission) {
                    $missionId = $this->derive_mission_id($mission);
                    if ($this->delete_mission_post($missionId)) {
                        $removedDeleted++;
                        ffami_debug_logger::log('Mission entfernt & gelöscht', ['year'=>$year,'id'=>$missionId]);
                    } else {
                        ffami_debug_logger::log('Mission als entfernt erkannt (kein Post gefunden)', ['year'=>$year,'id'=>$missionId]);
                    }
                }
            }
            $scanStats[$year] = [
                'new_count' => count($newMissions),
                'old_count' => count($oldMissions),
                'diff' => count($diff),
                'removed' => count($removed),
                'removed_deleted' => $removedDeleted
            ];
            ffami_debug_logger::log('Jahr diff Ergebnis', ['year' => $year] + $scanStats[$year]);
        }
        if ($scheduled > 0) {
            update_option('ffami_last_scheduled', $scheduled, false);
        }
        update_option('ffami_last_check', current_time('mysql'), false);
        update_option('ffami_scan_stats', $scanStats, false);
        ffami_debug_logger::log('Scan abgeschlossen', ['mode'=>$mode,'scheduled' => $scheduled]);
    }

    /**
     * Täglicher Root-Refresh: holt Root JSON, speichert MD5, bestimmt höchstes Jahr.
     */
    public function refresh_root_years() : void {
        if (!$this->has_uid()) { return; }
        $rootUrl = $this->get_root_url(); if (!$rootUrl) { return; }
        $payload = $this->fetch_json($rootUrl);
        if (!$payload) { ffami_debug_logger::log('Root Refresh fehlgeschlagen', ['url'=>$rootUrl]); return; }
        [ $data, $raw ] = $payload;
        $rootMd5 = md5($raw);
        update_option(self::OPTION_ROOT_MD5, $rootMd5, false);
        update_option('ffami_root_json', $raw, false);
        update_option('ffami_root_fetched_at', time(), false);
        if (isset($data['years']) && is_array($data['years'])) {
            $highest = $this->get_highest_year($data['years']);
            if ($highest !== null) { update_option('ffami_highest_year', (int)$highest, false); }
            ffami_debug_logger::log('Root Refresh', ['years'=>count($data['years']),'highest'=>$highest]);
        } else {
            ffami_debug_logger::log('Root Refresh ohne years Struktur');
        }
    }

    private function get_highest_year(array $years) : ?int {
        $nums = [];
        foreach ($years as $y => $_) { if (preg_match('/^(20\d{2})$/', (string)$y)) { $nums[] = (int)$y; } }
        if (empty($nums)) { return null; }
        rsort($nums, SORT_NUMERIC);
        return $nums[0];
    }

    private function get_cached_root_data(bool $allowFetchFallback=false) : ?array {
        $raw = get_option('ffami_root_json', '');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) { return $decoded; }
        }
        if ($allowFetchFallback) { // fallback fetch wenn gewünscht
            $rootUrl = $this->get_root_url();
            $payload = $rootUrl ? $this->fetch_json($rootUrl) : null;
            if ($payload) {
                [ $data, $rawNew ] = $payload;
                update_option('ffami_root_json', $rawNew, false);
                update_option('ffami_root_fetched_at', time(), false);
                return $data;
            }
        }
        return null;
    }

    public function import_single_mission($mission_id, $mission_url): void {
        try {
            new ffami_single_mission_import($mission_id, $mission_url);
            // nach erfolgreichem Import pending entfernen
            $this->remove_pending($mission_id);
            ffami_debug_logger::log('Mission Import abgeschlossen', ['id'=>$mission_id,'pending_rest'=>count($this->get_pending())]);
        } catch (\Throwable $e) {
            error_log('FFAMI Scheduler: Fehler beim Einzelimport: ' . $e->getMessage());
        }
    }

    private function get_root_url(): string {
        if (defined('FFAMI_FILE_MAIN')) {
            return constant('FFAMI_FILE_MAIN');
        }
        $uid = get_option('ffami_uid', '');
        return $uid ? FFAMI_DATA_PATH . $uid : '';
    }

    private function fetch_json(string $url): ?array {
        $response = wp_remote_get($url, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($response)) {
            ffami_debug_logger::log('Fetch Fehler', ['url' => $url, 'error' => $response->get_error_message()]);
            return null;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            ffami_debug_logger::log('HTTP Code ungleich 200', ['url' => $url, 'code' => $code]);
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            ffami_debug_logger::log('Leerer Body', ['url' => $url]);
            return null;
        }
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            ffami_debug_logger::log('JSON Decode Fehler', ['url' => $url, 'error' => json_last_error_msg()]);
            return null;
        }
        return [$data, $body];
    }

    private function extract_missions($yearData): array {
        if (is_array($yearData)) {
            if (isset($yearData['missions']) && is_array($yearData['missions'])) {
                return $yearData['missions'];
            }
            if (isset($yearData['data']) && is_array($yearData['data'])) {
                return $yearData['data'];
            }
            $numeric = array_keys($yearData) === range(0, count($yearData) - 1);
            if ($numeric) {
                return $yearData;
            }
        }
        return [];
    }

    /**
     * Rekursive Suche nach Missions-Arrays (heuristisch: Elemente mit key 'url' der '/mission/' enthält).
     */
    private function scan_for_missions($node, &$collected = []): array { // legacy kept
        return $this->collect_missions_generic($node, $collected);
    }

    /**
     * Generischer rekursiver Scan: sammelt alle Arrays, die ein 'url' Feld enthalten mit '/mission/'.
     * Erkennt sowohl Listen als auch einzelne associative Arrays.
     */
    private function collect_missions_generic($node, &$collected = []): array {
        if (!is_array($node)) {
            return $collected;
        }
        // Einzelnes Missionsobjekt?
        $candUrl = $node['detailUrl'] ?? ($node['url'] ?? null);
        if ($candUrl && strpos((string)$candUrl, '/mission/') !== false) {
            $collected[] = $node;
        }
        $isList = array_keys($node) === range(0, count($node) - 1);
        if ($isList) {
            $hasAny = false;
            foreach ($node as $e) {
                $u = is_array($e) ? ($e['detailUrl'] ?? ($e['url'] ?? null)) : null;
                if ($u && strpos((string)$u, '/mission/') !== false) {
                    $hasAny = true;
                    break;
                }
            }
            if ($hasAny) {
                foreach ($node as $e) {
                    if (is_array($e)) {
                        $u = $e['detailUrl'] ?? ($e['url'] ?? null);
                        if ($u && strpos((string)$u, '/mission/') !== false) {
                            $collected[] = $e;
                        }
                    }
                }
            }
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $this->collect_missions_generic($v, $collected);
            }
        }
        // Deduplizieren nach (detail)URL
        $seen = [];
        $result = [];
        foreach ($collected as $c) {
            $u = $c['detailUrl'] ?? ($c['url'] ?? null);
            if (!$u) {
                continue;
            }
            if (isset($seen[$u])) {
                continue;
            }
            $seen[$u] = true;
            $result[] = $c;
        }
        return $result;
    }

    private function diff_missions(array $old, array $new): array {
        $oldIndex = [];
        foreach ($old as $e) {
            $k = $this->mission_key($e);
            if ($k) {
                $oldIndex[$k] = md5(json_encode($e));
            }
        }
        $changed = [];
        foreach ($new as $e) {
            $k = $this->mission_key($e);
            if (!$k) {
                continue;
            }
            $h = md5(json_encode($e));
            if (!isset($oldIndex[$k]) || $oldIndex[$k] !== $h) {
                $changed[] = $e;
            }
        }
        return $changed;
    }

    private function mission_key(array $entry): ?string {
        if (!empty($entry['detailUrl'])) return (string)$entry['detailUrl'];
        if (!empty($entry['url'])) return (string)$entry['url'];
        if (!empty($entry['id'])) return 'id:' . $entry['id'];
        if (isset($entry['alarmDate'])) return 'ad:' . $entry['alarmDate'];
        return null;
    }

    /**
     * Bestimmt Missionen, die im alten Set existieren aber im neuen fehlen.
     * @param array $old
     * @param array $new
     * @return array removed entries (raw arrays from old)
     */
    private function removed_missions(array $old, array $new) : array {
        $oldKeys = [];
        foreach ($old as $e) { $k = $this->mission_key($e); if ($k) { $oldKeys[$k] = $e; } }
        if (empty($oldKeys)) { return []; }
        $newKeys = [];
        foreach ($new as $e) { $k = $this->mission_key($e); if ($k) { $newKeys[$k] = true; } }
        $removed = [];
        foreach ($oldKeys as $k=>$entry) {
            if (!isset($newKeys[$k])) { $removed[] = $entry; }
        }
        return $removed;
    }

    /**
     * Löscht den WP Post (force delete) anhand mission_id Meta.
     * @param string $missionId
     * @return bool true wenn ein Post gelöscht wurde
     */
    private function delete_mission_post(string $missionId) : bool {
        $q = new WP_Query([
            'post_type'=>'mission',
            'meta_query'=>[
                ['key'=>'ffami_mission_id','value'=>$missionId,'compare'=>'=']
            ],
            'posts_per_page'=>1,
            'fields'=>'ids'
        ]);
        if ($q->have_posts()) {
            $pid = $q->posts[0];
            return (bool) wp_delete_post($pid, true);
        }
        return false;
    }

    private function derive_mission_id(array $entry): string {
        if (!empty($entry['id'])) {
            return (string)$entry['id'];
        }
        if (isset($entry['alarmDate']) && is_numeric($entry['alarmDate'])) {
            $ts = (int)($entry['alarmDate'] / 1000);
            return gmdate('Y-m-d_H-i-s', $ts);
        }
        $u = $entry['detailUrl'] ?? ($entry['url'] ?? '');
        if ($u) {
            return substr(md5($u), 0, 16);
        }
        return uniqid('mission_', true);
    }

    /**
     * Ermittelt welche Missionen aus einer Liste noch nicht als CPT vorhanden sind.
     * Erwartet, dass Missionen ein 'id' Feld besitzen (wie Beispiel), sonst wird derive_mission_id genutzt.
     * Nutzt eine einzelne SQL IN Abfrage für Effizienz.
     * @param array $missions
     * @return array fehlende Missions-Arrays
     */
    private function find_missing_missions(array $missions) : array {
        global $wpdb;
        if (empty($missions)) { return []; }
        $ids = [];
        $index = [];
        foreach ($missions as $m) {
            $mid = !empty($m['id']) ? (string)$m['id'] : $this->derive_mission_id($m);
            $ids[] = $mid; // kann Duplikate enthalten
            $index[$mid] = $m; // letztes gewinnt, ausreichend für unsere Zwecke
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) { return []; }
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));
        $sql = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='ffami_mission_id' AND meta_value IN ($placeholders)";
        // prepare akzeptiert Array-Expand nicht nativ vor 6.2 -> spread
        $prepared = $wpdb->prepare($sql, $ids);
        $existing = $wpdb->get_col($prepared);
        $existing = array_flip($existing ?: []);
        $missing = [];
        foreach ($ids as $mid) {
            if (!isset($existing[$mid]) && !$this->is_pending($mid)) { $missing[] = $index[$mid]; }
        }
        return $missing;
    }

    private function get_pending() : array {
        $p = get_option(self::OPTION_PENDING, []);
        return is_array($p) ? $p : [];
    }
    private function save_pending(array $pending) : void {
        update_option(self::OPTION_PENDING, array_values(array_unique($pending)), false);
    }
    private function is_pending(string $id) : bool {
        $p = $this->get_pending();
        return in_array($id, $p, true);
    }
    private function add_pending(string $id) : void {
        $p = $this->get_pending();
        if (!in_array($id, $p, true)) { $p[] = $id; $this->save_pending($p); }
    }
    private function remove_pending(string $id) : void {
        $p = $this->get_pending();
        $new = [];
        foreach ($p as $x) { if ($x !== $id) { $new[] = $x; } }
        $this->save_pending($new);
    }
}
