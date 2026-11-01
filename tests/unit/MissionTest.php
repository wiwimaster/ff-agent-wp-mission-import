<?php

require_once __DIR__ . '/MpfeuerTestCase.php';

/** Anzeige-Vorrang in from_post und Widget-Import (ffami_mission_saved, Aufräumpunkte 2/3). */
class MissionTest extends MpfeuerTestCase {

    private const URL = '/hpWidget/TEST/mission/00000000-0000-4000-8000-0000000000aa';

    private function widget(array $changes = []): array {
        return array_merge([
            'title'       => 'RD Personensuche',
            'type'        => 'OTHER',
            'detail'      => '<p>Berichtstext</p>',
            'alarmDate'   => (self::EXPORT_TS + 46) * 1000, // Widget-Alarm 01:22 Ortszeit
            'duration'    => '2 h 30 min',
            'location'    => 'Musterort',
            'personCount' => 3,
            'vehicles'    => [['title' => 'MZF']],
            'images'      => [],
        ], $changes);
    }

    private function stub_widget(array $changes = []): void {
        $GLOBALS['wp_stub']['remote'][FFAMI_DATA_ROOT . self::URL] = ['code' => 200, 'body' => json_encode($this->widget($changes))];
    }

    private function import(): void {
        new ffami_single_mission_import('2026-09-25_23-22-00', self::URL);
    }

    public function test_from_post_nimmt_mpfeuer_werte_mit_vorrang(): void {
        $this->configure();
        new ffami_mpfeuer_matcher();
        $mission = $this->create_mission(self::EXPORT_TS, ['MZF'], 3, 150);
        (new ffami_mpfeuer_endpoint())->handle($this->request(json_encode($this->export(), JSON_UNESCAPED_UNICODE)));

        $m = ffami_mission::from_post($mission);
        $this->assertSame('INF SONSTIGES', $m->stichwort);
        $this->assertSame('Anforderung Rettungshunde', $m->einsatzart);
        $this->assertSame('Sonstiges', $m->kategorie);
        $this->assertSame(self::EXPORT_TS, $m->start_ts);
        $this->assertSame(self::EXPORT_TS + 9157, $m->end_ts);
        $this->assertSame(153, $m->duration_minutes);          // präziser als "2 h 30 min"
        $this->assertSame('2026-09-26 01:21:14', $m->datetime);
        $this->assertSame(['Drohne', 'MZF'], $m->vehicles);     // umfangreicher als ['MZF']
        $this->assertSame(4, $m->person_count);                 // 4 > 3
        $this->assertContains('Florian Oberpfaffenhofen 11/1', $m->units);
    }

    public function test_from_post_behaelt_widget_werte_wenn_umfangreicher(): void {
        $mission = $this->create_mission(self::EXPORT_TS, ['HLF', 'MZF', 'TSF'], 9, 150);
        update_post_meta($mission, 'ffami_mpf_id', '00000000-0000-4000-8000-000000000001');
        update_post_meta($mission, 'ffami_mpf_vehicles', ['MZF']);
        update_post_meta($mission, 'ffami_mpf_person_count', 4);
        $m = ffami_mission::from_post($mission);
        $this->assertSame(['HLF', 'MZF', 'TSF'], $m->vehicles);
        $this->assertSame(9, $m->person_count);
        $this->assertSame(150, $m->duration_minutes); // ohne Beginn/Ende bleibt der Widget-Wert
    }

    public function test_adresse_und_besatzung_nicht_im_oeffentlichen_objekt(): void {
        $this->configure();
        new ffami_mpfeuer_matcher();
        $mission = $this->create_mission(self::EXPORT_TS);
        $data = $this->export();
        $data['Einsatz']['Fahrzeuge'][1]['Besatzung'] = 6;
        (new ffami_mpfeuer_endpoint())->handle($this->request(json_encode($data, JSON_UNESCAPED_UNICODE)));
        $this->assertSame(['MZF' => 6], get_post_meta($mission, 'ffami_mpf_crew', true)); // intern gespeichert

        $public = serialize(ffami_mission::from_post($mission));
        foreach (['Musterweg', '99999', 'Musterstadt', 'crew', 'Besatzung', 'address'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $public, $forbidden);
        }
        $matcher = new ffami_mpfeuer_matcher();
        $this->assertTrue($matcher->protect_internal_meta(false, 'ffami_mpf_address'));
        $this->assertTrue($matcher->protect_internal_meta(false, 'ffami_mpf_crew'));
        $this->assertFalse($matcher->protect_internal_meta(false, 'ffami_mpf_stichwort'));
    }

    public function test_widget_import_schreibt_alarm_ts_und_feuert_action(): void {
        $fired = [];
        add_action('ffami_mission_saved', function ($id, $m, $changed) use (&$fired) { $fired[] = [$id, $changed]; }, 10, 3);
        $this->stub_widget();
        $this->import();

        $id = array_key_last($GLOBALS['wp_stub']['posts']);
        $this->assertSame((string) (self::EXPORT_TS + 46), get_post_meta($id, 'ffami_alarm_ts', true));
        $this->assertSame('2026-09-26-01-22_rettungsdienst-personensuche', get_post($id)->post_name); // 24-h-Format, Abkürzung aufgelöst
        $this->assertSame([[$id, true]], $fired);

        // unverändert: Action trotzdem, Alarmzeit wird nachgezogen
        delete_post_meta($id, 'ffami_alarm_ts');
        $this->import();
        $this->assertSame([[$id, true], [$id, false]], $fired);
        $this->assertSame((string) (self::EXPORT_TS + 46), get_post_meta($id, 'ffami_alarm_ts', true));
    }

    public function test_widget_import_ueberschreibt_geschaetzte_alarmzeit(): void {
        $this->stub_widget();
        $this->import();
        $id = array_key_last($GLOBALS['wp_stub']['posts']);

        // unverändert, Nachrüstung hatte post_date_gmt (14 h daneben) eingetragen
        update_post_meta($id, 'ffami_alarm_ts', self::EXPORT_TS + 14 * 3600);
        $this->import();
        $this->assertSame((string) (self::EXPORT_TS + 46), get_post_meta($id, 'ffami_alarm_ts', true));

        // geändert: ebenso
        update_post_meta($id, 'ffami_alarm_ts', self::EXPORT_TS - 19 * 3600);
        $this->stub_widget(['detail' => '<p>Geändert</p>']);
        $this->import();
        $this->assertSame((string) (self::EXPORT_TS + 46), get_post_meta($id, 'ffami_alarm_ts', true));
    }

    public function test_widget_ohne_alarmzeit_laesst_wert_stehen(): void {
        $m = new ffami_mission();
        $m->post_id = wp_insert_post(['post_type' => 'mission']);
        update_post_meta($m->post_id, 'ffami_alarm_ts', 1234);
        $m->store_alarm_ts();
        $this->assertSame('1234', get_post_meta($m->post_id, 'ffami_alarm_ts', true));
    }

    public function test_korrigierte_alarmzeit_ordnet_offenen_export_zu(): void {
        $this->configure();
        new ffami_mpfeuer_matcher();
        $this->stub_widget();
        $this->import();
        $id = array_key_last($GLOBALS['wp_stub']['posts']);
        update_post_meta($id, 'ffami_alarm_ts', self::EXPORT_TS + 14 * 3600);
        (new ffami_mpfeuer_endpoint())->handle($this->request(json_encode($this->export(), JSON_UNESCAPED_UNICODE)));
        $queue_id = ffami_mpfeuer_queue::find_by_export_id('00000000-0000-4000-8000-000000000001');
        $this->assertSame(ffami_mpfeuer_queue::STATUS_OPEN, ffami_mpfeuer_queue::get_status($queue_id));

        $this->import(); // Jahresimport läuft erneut, Beitrag unverändert
        $this->assertSame(ffami_mpfeuer_queue::STATUS_MATCHED, ffami_mpfeuer_queue::get_status($queue_id));
    }

    public function test_widget_ohne_fahrzeuge(): void {
        $data = $this->widget();
        unset($data['vehicles']);
        $m = new ffami_mission();
        $m->import_mission_data($data);
        $this->assertSame([], $m->vehicles);
    }

    public function test_update_behaelt_slug(): void {
        $this->stub_widget();
        $this->import();
        $id = array_key_last($GLOBALS['wp_stub']['posts']);
        get_post($id)->post_name = 'alter-slug';
        $this->stub_widget(['detail' => '<p>Geändert</p>']);
        $this->import();
        $this->assertSame('alter-slug', get_post($id)->post_name);
        $this->assertSame('<p>Geändert</p>', get_post($id)->post_content);
    }

    public function test_speicherfehler_wirft_exception_statt_bildimport(): void {
        $this->stub_widget(['images' => [['url' => 'https://example.invalid/bild']]]);
        $GLOBALS['wp_stub']['insert_fail'] = true;
        $this->expectException(RuntimeException::class);
        $this->import();
    }

    public function test_reihenfolge_export_vor_widget_ueber_echten_import(): void {
        $this->configure();
        new ffami_mpfeuer_matcher();
        (new ffami_mpfeuer_endpoint())->handle($this->request(json_encode($this->export(), JSON_UNESCAPED_UNICODE)));
        $queue_id = ffami_mpfeuer_queue::find_by_export_id('00000000-0000-4000-8000-000000000001');
        $this->assertSame('offen', ffami_mpfeuer_queue::get_status($queue_id));

        $this->stub_widget();
        $this->import();
        $mission = (int) get_post_meta($queue_id, 'ffami_matched_post', true);
        $this->assertSame('zugeordnet', ffami_mpfeuer_queue::get_status($queue_id));
        $this->assertSame('mission', get_post($mission)->post_type);
        $this->assertSame(153, ffami_mission::from_post($mission)->duration_minutes);
    }
}
