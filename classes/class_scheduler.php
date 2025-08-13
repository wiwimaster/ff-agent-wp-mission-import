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

    private const RECURRING_HOOK = 'ffami_check_years';
    private const IMPORT_HOOK    = 'ffami_import_single_mission';
    private const GROUP          = 'ffami';
    private const INTERVAL       = 300; // 5 Minuten
    private const OPTION_ROOT_MD5 = 'ffami_root_md5';

    public function __construct() {
        add_action(self::RECURRING_HOOK, [ $this, 'check_years' ]);
        add_action(self::IMPORT_HOOK, [ $this, 'import_single_mission' ], 10, 2);
        add_action('admin_notices', [ $this, 'maybe_admin_notice' ]);
        add_action('plugins_loaded', [ $this, 'schedule_recurring' ], 20);
    }

    public function maybe_admin_notice() {
        if (!class_exists('ActionScheduler')) {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-warning"><p><strong>FF Agent Mission Import:</strong> Action Scheduler nicht gefunden. Bitte Plugin "Action Scheduler" installieren oder WooCommerce aktivieren.</p></div>';
            }
        }
    }

    public function schedule_recurring() {
        if (!function_exists('as_schedule_recurring_action')) { return; }
        if (! $this->has_uid()) { return; }
        if (!as_has_scheduled_action(self::RECURRING_HOOK, [], self::GROUP)) {
            as_schedule_recurring_action(time() + 60, self::INTERVAL, self::RECURRING_HOOK, [], self::GROUP);
        }
    }

    private function has_uid() : bool {
        $uid = get_option('ffami_uid', '');
        return !empty($uid);
    }

    public function check_years() : void {
        if (! $this->has_uid()) { return; }
        $rootUrl = $this->get_root_url();
        if (!$rootUrl) { return; }

        $rootPayload = $this->fetch_json($rootUrl);
        if (!$rootPayload) { return; }
        [ $rootData, $rootRaw ] = $rootPayload;
        $rootMd5   = md5($rootRaw);
        $storedRoot = get_option(self::OPTION_ROOT_MD5, '');
        if ($storedRoot !== $rootMd5) {
            update_option(self::OPTION_ROOT_MD5, $rootMd5, false);
            update_option('ffami_root_json', $rootRaw, false);
        }
        if (!isset($rootData['years']) || !is_array($rootData['years'])) { return; }

        $scheduled = 0;
        foreach ($rootData['years'] as $year => $info) {
            if (empty($info['url'])) { continue; }
            $yearUrl = FFAMI_DATA_ROOT . $info['url'];
            $yearPayload = $this->fetch_json($yearUrl);
            if (!$yearPayload) { continue; }
            [ $yearData, $yearRaw ] = $yearPayload;
            $yearMd5 = md5($yearRaw);
            $optKey = 'ffami_year_md5_' . sanitize_key((string)$year);
            $storedYearMd5 = get_option($optKey, '');
            if ($storedYearMd5 === $yearMd5) { continue; }

            $oldRaw = get_option('ffami_year_json_' . sanitize_key((string)$year), '');
            $oldData = $oldRaw ? json_decode($oldRaw, true) : [];
            $newMissions = $this->extract_missions($yearData);
            $oldMissions = $this->extract_missions($oldData);
            $diff = $this->diff_missions($oldMissions, $newMissions);

            update_option($optKey, $yearMd5, false);
            update_option('ffami_year_json_' . sanitize_key((string)$year), $yearRaw, false);

            foreach ($diff as $mission) {
                $missionUrl = $mission['url'] ?? null;
                if (!$missionUrl) { continue; }
                $missionId = $this->derive_mission_id($mission);
                $args = [ 'mission_id' => $missionId, 'mission_url' => $missionUrl ];
                if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::IMPORT_HOOK, $args, self::GROUP)) { continue; }
                if (function_exists('as_enqueue_async_action')) {
                    as_enqueue_async_action(self::IMPORT_HOOK, $args, self::GROUP);
                    $scheduled++;
                }
            }
        }
        if ($scheduled > 0) { update_option('ffami_last_scheduled', $scheduled, false); }
        update_option('ffami_last_check', current_time('mysql'), false);
    }

    public function import_single_mission($mission_id, $mission_url) : void {
        try {
            new ffami_single_mission_import($mission_id, $mission_url);
        } catch (\Throwable $e) {
            error_log('FFAMI Scheduler: Fehler beim Einzelimport: ' . $e->getMessage());
        }
    }

    private function get_root_url() : string {
        if (defined('FFAMI_FILE_MAIN')) { return constant('FFAMI_FILE_MAIN'); }
        $uid = get_option('ffami_uid', '');
        return $uid ? FFAMI_DATA_PATH . $uid : '';
    }

    private function fetch_json(string $url) : ?array {
        $response = wp_remote_get($url, [ 'timeout' => 10, 'headers' => [ 'Accept' => 'application/json' ] ]);
        if (is_wp_error($response)) { return null; }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) { return null; }
        $body = wp_remote_retrieve_body($response);
        if ($body === '') { return null; }
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) { return null; }
        return [ $data, $body ];
    }

    private function extract_missions($yearData) : array {
        if (is_array($yearData)) {
            if (isset($yearData['missions']) && is_array($yearData['missions'])) { return $yearData['missions']; }
            if (isset($yearData['data']) && is_array($yearData['data'])) { return $yearData['data']; }
            $numeric = array_keys($yearData) === range(0, count($yearData)-1);
            if ($numeric) { return $yearData; }
        }
        return [];
    }

    private function diff_missions(array $old, array $new) : array {
        $oldIndex = [];
        foreach ($old as $e) { $k = $this->mission_key($e); if ($k) { $oldIndex[$k] = md5(json_encode($e)); } }
        $changed = [];
        foreach ($new as $e) {
            $k = $this->mission_key($e); if (!$k) { continue; }
            $h = md5(json_encode($e));
            if (!isset($oldIndex[$k]) || $oldIndex[$k] !== $h) { $changed[] = $e; }
        }
        return $changed;
    }

    private function mission_key(array $entry) : ?string {
        if (!empty($entry['url'])) return (string)$entry['url'];
        if (!empty($entry['id'])) return 'id:' . $entry['id'];
        if (isset($entry['alarmDate'])) return 'ad:' . $entry['alarmDate'];
        return null;
    }

    private function derive_mission_id(array $entry) : string {
        if (isset($entry['alarmDate']) && is_numeric($entry['alarmDate'])) {
            $ts = (int)($entry['alarmDate'] / 1000);
            return gmdate('Y-m-d_H-i-s', $ts);
        }
        if (!empty($entry['id'])) { return (string)$entry['id']; }
        if (!empty($entry['url'])) { return substr(md5($entry['url']), 0, 16); }
        return uniqid('mission_', true);
    }
}
