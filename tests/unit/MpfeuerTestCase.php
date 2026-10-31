<?php

use PHPUnit\Framework\TestCase;

/** Gemeinsame Helfer: Stub-Reset, Beispielexport, Missionen anlegen. */
abstract class MpfeuerTestCase extends TestCase {

    /** Alarmzeit des Beispielexports: 2026-09-26 01:21:14 Europe/Berlin (CEST) = 2026-09-25 23:21:14 UTC. */
    protected const EXPORT_TS = 1790378474;

    protected function setUp(): void {
        wp_stub_reset();
    }

    /** Pseudonymisierter Beispielexport (tests/fixtures), optional abgewandelt. */
    protected function export(array $changes = []): array {
        $data = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/mpfeuer-einsatz.json'), true);
        return array_replace_recursive($data, $changes);
    }

    /** Widget-Mission wie vom Importer gespeichert (Alarmzeit als UTC-Timestamp). */
    protected function create_mission(int $alarm_ts, array $vehicles = [], int $persons = 0, int $duration = 0): int {
        $id = wp_insert_post([
            'post_type'   => 'mission',
            'post_status' => 'publish',
            'post_title'  => 'Einsatz',
            'post_date'   => wp_date('Y-m-d H:i:s', $alarm_ts),
        ]);
        update_post_meta($id, 'ffami_alarm_ts', $alarm_ts);
        update_post_meta($id, 'ffami_mission_title', 'Einsatz');
        update_post_meta($id, 'ffami_mission_type', 'Sonstiges');
        update_post_meta($id, 'ffami_mission_vehicles', $vehicles);
        update_post_meta($id, 'ffami_mission_person_count', $persons);
        update_post_meta($id, 'ffami_mission_duration_minutes', $duration);
        return $id;
    }

    protected function configure(): void {
        ffami_mpfeuer_settings::update([
            'user'        => 'ffagent',
            'vehicle_map' => ['Steinebach 11/1' => 'MZF', 'Drohne' => 'Drohne'],
        ]);
        ffami_mpfeuer_settings::set_password('test-geheim');
    }

    protected function request(?string $body, string $type = 'application/json', ?string $auth = 'ffagent:test-geheim'): WP_REST_Request {
        $r = new WP_REST_Request('POST', '/ffami/v1/mpfeuer/rest/123456/Einsatz');
        $r->set_param('kennung', '123456');
        $r->set_header('Content-Type', $type);
        if ($auth !== null) { $r->set_header('Authorization', 'Basic ' . base64_encode($auth)); }
        $r->set_body((string) $body);
        return $r;
    }
}
