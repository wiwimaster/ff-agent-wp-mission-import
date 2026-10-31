<?php

require_once __DIR__ . '/MpfeuerTestCase.php';

/** Zuordnung über die Alarmzeit, beide Reihenfolgen, Mehrdeutigkeit, manuelle Aktionen. */
class MatcherTest extends MpfeuerTestCase {

    private ffami_mpfeuer_endpoint $endpoint;

    protected function setUp(): void {
        parent::setUp();
        $this->endpoint = new ffami_mpfeuer_endpoint();
        new ffami_mpfeuer_matcher(); // hängt sich an ffami_mission_saved
        $this->configure();
    }

    private function receive(array $changes = []): int {
        $response = $this->endpoint->handle($this->request(json_encode($this->export($changes), JSON_UNESCAPED_UNICODE)));
        $this->assertSame(200, $response->get_status());
        return ffami_mpfeuer_queue::find_by_export_id('00000000-0000-4000-8000-000000000001');
    }

    public function test_widget_vor_export_wird_beim_eingang_zugeordnet(): void {
        $mission = $this->create_mission(self::EXPORT_TS + 60, ['MZF'], 3, 150);
        $queue_id = $this->receive();

        $this->assertSame(ffami_mpfeuer_queue::STATUS_MATCHED, ffami_mpfeuer_queue::get_status($queue_id));
        $this->assertSame((string) $mission, get_post_meta($queue_id, 'ffami_matched_post', true));
        $this->assertSame('00000000-0000-4000-8000-000000000001', get_post_meta($mission, 'ffami_mpf_id', true));
        $this->assertSame('INF SONSTIGES', get_post_meta($mission, 'ffami_mpf_stichwort', true));
        $this->assertSame(['Drohne', 'MZF'], get_post_meta($mission, 'ffami_mpf_vehicles', true));
        $this->assertSame('Musterweg 1', get_post_meta($mission, 'ffami_mpf_address', true)['strasse']);
        $this->assertSame('angenommen, zugeordnet', ffami_mpfeuer_endpoint::get_events()[0]['result']);
    }

    public function test_export_vor_widget_wird_nach_widget_import_zugeordnet(): void {
        $queue_id = $this->receive();
        $this->assertSame(ffami_mpfeuer_queue::STATUS_OPEN, ffami_mpfeuer_queue::get_status($queue_id));

        $mission = $this->create_mission(self::EXPORT_TS - 120);
        do_action('ffami_mission_saved', $mission, null, true);

        $this->assertSame(ffami_mpfeuer_queue::STATUS_MATCHED, ffami_mpfeuer_queue::get_status($queue_id));
        $this->assertSame('INF SONSTIGES', get_post_meta($mission, 'ffami_mpf_stichwort', true));
    }

    public function test_ohne_treffer_bleibt_offen(): void {
        $mission = $this->create_mission(self::EXPORT_TS + 30 * 60); // außerhalb ±10 min
        $queue_id = $this->receive();
        $this->assertSame(ffami_mpfeuer_queue::STATUS_OPEN, ffami_mpfeuer_queue::get_status($queue_id));
        do_action('ffami_mission_saved', $mission, null, false);
        $this->assertSame(ffami_mpfeuer_queue::STATUS_OPEN, ffami_mpfeuer_queue::get_status($queue_id));
        $this->assertSame('', get_post_meta($mission, 'ffami_mpf_id', true));
    }

    public function test_toleranz_ist_einstellbar(): void {
        ffami_mpfeuer_settings::update(['tolerance' => 3600]);
        $mission = $this->create_mission(self::EXPORT_TS + 30 * 60);
        $queue_id = $this->receive();
        $this->assertSame(ffami_mpfeuer_queue::STATUS_MATCHED, ffami_mpfeuer_queue::get_status($queue_id));
        $this->assertSame((string) $mission, get_post_meta($queue_id, 'ffami_matched_post', true));
    }

    public function test_mehrdeutig_bleibt_offen_mit_kandidaten(): void {
        $a = $this->create_mission(self::EXPORT_TS - 60, ['HLF']);
        $b = $this->create_mission(self::EXPORT_TS + 60, ['TSF']);
        $queue_id = $this->receive();
        $this->assertSame(ffami_mpfeuer_queue::STATUS_OPEN, ffami_mpfeuer_queue::get_status($queue_id));
        $this->assertEqualsCanonicalizing([$a, $b], ffami_mpfeuer_queue::get_candidates($queue_id));
        $this->assertSame('angenommen, mehrdeutig', ffami_mpfeuer_endpoint::get_events()[0]['result']);
    }

    public function test_mehrdeutig_ueber_fahrzeugnamen_aufgeloest(): void {
        $this->create_mission(self::EXPORT_TS - 60, ['HLF']);
        $b = $this->create_mission(self::EXPORT_TS + 60, ['mzf']);
        $queue_id = $this->receive();
        $this->assertSame(ffami_mpfeuer_queue::STATUS_MATCHED, ffami_mpfeuer_queue::get_status($queue_id));
        $this->assertSame((string) $b, get_post_meta($queue_id, 'ffami_matched_post', true));
    }

    public function test_mission_eines_anderen_exports_ist_kein_kandidat(): void {
        $mission = $this->create_mission(self::EXPORT_TS);
        update_post_meta($mission, 'ffami_mpf_id', '00000000-0000-4000-8000-0000000000ff');
        $queue_id = $this->receive();
        $this->assertSame(ffami_mpfeuer_queue::STATUS_OPEN, ffami_mpfeuer_queue::get_status($queue_id));
    }

    public function test_manuell_zuordnen_und_verwerfen(): void {
        $a = $this->create_mission(self::EXPORT_TS - 60, ['HLF']);
        $b = $this->create_mission(self::EXPORT_TS + 60, ['TSF']);
        $queue_id = $this->receive();

        $this->assertTrue(ffami_mpfeuer_matcher::assign_manually($queue_id, $b));
        $this->assertSame(ffami_mpfeuer_queue::STATUS_MATCHED, ffami_mpfeuer_queue::get_status($queue_id));
        $this->assertSame([], ffami_mpfeuer_queue::get_candidates($queue_id));

        // Umhängen auf a entfernt die Felder an b
        $this->assertTrue(ffami_mpfeuer_matcher::assign_manually($queue_id, $a));
        $this->assertSame('', get_post_meta($b, 'ffami_mpf_id', true));
        $this->assertSame('00000000-0000-4000-8000-000000000001', get_post_meta($a, 'ffami_mpf_id', true));

        ffami_mpfeuer_matcher::discard($queue_id);
        $this->assertSame(ffami_mpfeuer_queue::STATUS_DISCARDED, ffami_mpfeuer_queue::get_status($queue_id));
        $this->assertSame('', get_post_meta($a, 'ffami_mpf_id', true));
        $this->assertSame('', get_post_meta($a, 'ffami_mpf_address', true));

        // Verworfenes wird auch bei neuem Widget-Import nicht wieder zugeordnet
        do_action('ffami_mission_saved', $a, null, true);
        $this->assertSame(ffami_mpfeuer_queue::STATUS_DISCARDED, ffami_mpfeuer_queue::get_status($queue_id));
        $this->assertFalse(ffami_mpfeuer_matcher::assign_manually($queue_id, 99999));
    }

    public function test_erneuter_export_aktualisiert_zugeordnete_mission(): void {
        $mission = $this->create_mission(self::EXPORT_TS);
        $this->receive();
        $this->receive(['Einsatz' => ['Allgemein' => ['Alarmstichwort' => 'THL 1']]]);
        $this->assertSame('THL 1', get_post_meta($mission, 'ffami_mpf_stichwort', true));
    }

    public function test_geaenderte_fahrzeugzuordnung_wird_nachgezogen(): void {
        $mission = $this->create_mission(self::EXPORT_TS);
        $this->receive();
        ffami_mpfeuer_settings::update(['vehicle_map' => ['Steinebach 11/1' => 'MZF', 'Drohne' => 'UAV Steinebach']]);
        ffami_mpfeuer_matcher::retry();
        $this->assertSame(['MZF', 'UAV Steinebach'], get_post_meta($mission, 'ffami_mpf_vehicles', true));
    }

    public function test_aufraeumer_verwirft_alte_offene_eintraege(): void {
        $queue_id = $this->receive();
        get_post($queue_id)->post_date_gmt = gmdate('Y-m-d H:i:s', time() - 91 * 86400);
        (new ffami_mpfeuer_matcher())->cleanup();
        $this->assertSame(ffami_mpfeuer_queue::STATUS_DISCARDED, ffami_mpfeuer_queue::get_status($queue_id));
    }

    public function test_alarm_ts_nachruesten(): void {
        $id = wp_insert_post(['post_type' => 'mission', 'post_date' => '2026-09-26 01:21:00']);
        $this->assertSame(1, ffami_mpfeuer_matcher::backfill_alarm_ts());
        $this->assertSame((string) (self::EXPORT_TS - 14), get_post_meta($id, 'ffami_alarm_ts', true));
        $this->assertSame('1', get_option('ffami_alarm_ts_backfilled'));
        $this->assertSame(0, ffami_mpfeuer_matcher::backfill_alarm_ts());
    }
}
