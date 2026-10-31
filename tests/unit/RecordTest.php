<?php

require_once __DIR__ . '/MpfeuerTestCase.php';

/** Parser und Datenregeln (§2 der Spec). */
class RecordTest extends MpfeuerTestCase {

    private function record(array $changes = []): ffami_mpfeuer_record {
        return ffami_mpfeuer_record::from_export($this->export($changes), ['Steinebach 11/1' => 'MZF']);
    }

    public function test_erlaubte_felder_werden_uebernommen(): void {
        $r = $this->record();
        $this->assertSame('00000000-0000-4000-8000-000000000001', $r->id);
        $this->assertSame('INF SONSTIGES', $r->stichwort);
        $this->assertSame('#INF#Anforderung Rettungshunde', $r->einsatzart);
        $this->assertSame('Sonstiges', $r->kategorie);
        $this->assertSame(4, $r->person_count);
        $this->assertSame('Musterweg 1', $r->address['strasse']);
        $this->assertSame('Anforderung Rettungshunde', ffami_mpfeuer_record::clean_einsatzart($r->einsatzart));
    }

    public function test_verbotene_felder_fehlen_im_gespeicherten_datensatz(): void {
        $stored = json_encode($this->record()->to_array(), JSON_UNESCAPED_UNICODE);
        foreach (['Vorname', 'Nachname', 'Leitstelle', 'A 9.9 999999', 'SCHLEIFE', 'Freitext', 'Alarmgeber',
                  'Leitstellen_Nr', 'Tagebuch', 'Einsatzleiter', 'Aufgabe', 'MML_', 'Zeit_AUS', 'Zeit_EIN'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $stored, $forbidden . ' darf nicht gespeichert werden');
        }
        // Ausrückzeiten der Fahrzeuge (01:29:28) dürfen in keiner Form auftauchen
        $this->assertStringNotContainsString('01:29', $stored);
        $this->assertSame(['id', 'start_ts', 'end_ts', 'stichwort', 'einsatzart', 'kategorie', 'address', 'vehicles', 'units', 'person_count'], array_keys($this->record()->to_array()));
    }

    public function test_personal_nur_als_anzahl_auch_mit_rollen(): void {
        $data = $this->export();
        $data['Einsatz']['Personal'][0]['Aufgabe'] = 'Gruppenführer';
        $data['Einsatz']['Allgemein']['Einsatzleiter'] = 'Vorname1 Nachname1';
        $r = ffami_mpfeuer_record::from_export($data);
        $stored = json_encode($r->to_array(), JSON_UNESCAPED_UNICODE);
        $this->assertSame(4, $r->person_count);
        $this->assertStringNotContainsString('Gruppenführer', $stored);
        $this->assertStringNotContainsString('Nachname1', $stored);
    }

    public function test_zeitzonenumrechnung_sommerzeit(): void {
        $r = $this->record();
        $this->assertSame(self::EXPORT_TS, $r->start_ts);
        $this->assertSame('2026-09-25 23:21:14', gmdate('Y-m-d H:i:s', $r->start_ts));
        $this->assertSame('2026-09-26 01:53:51', gmdate('Y-m-d H:i:s', $r->end_ts));
        $this->assertSame(153, $r->duration_minutes());
    }

    public function test_zeitzonenumrechnung_winterzeit_und_ungueltig(): void {
        $this->assertSame('2026-01-15 11:00:00', gmdate('Y-m-d H:i:s', ffami_mpfeuer_record::parse_time('2026-01-15T12:00:00')));
        $this->assertNull(ffami_mpfeuer_record::parse_time('gestern'));
        $this->assertNull(ffami_mpfeuer_record::parse_time(null));
    }

    public function test_fahrzeuge_ueber_zuordnungstabelle_und_besatzung_intern(): void {
        $data = $this->export();
        $data['Einsatz']['Fahrzeuge'][1]['Besatzung'] = 6;
        $r = ffami_mpfeuer_record::from_export($data, ['Steinebach 11/1' => 'MZF']);
        $this->assertSame(['Drohne', 'MZF'], $r->vehicle_names());
        $this->assertSame(6, $r->vehicles[1]['crew']);
        $this->assertTrue($r->vehicles[1]['known']);
        $this->assertFalse($r->vehicles[0]['known']);
        $this->assertSame(['Drohne'], $r->unknown_entries()['vehicles']);
    }

    public function test_weitere_kraefte_feuerwehr_mit_name_andere_nur_typ(): void {
        $c = fn($l) => ffami_mpfeuer_record::classify_unit($l)['label'];
        $this->assertSame('Florian Oberpfaffenhofen 11/1', $c('Florian Oberpfaffenhofen 11/1'));
        $this->assertSame('Rettungshunde-Fahrzeug (MHD)', $c('Johannes Dachau 51/59/1'));
        $this->assertSame('ELW (MHD)', $c('Johannes Fürstenfeldbruck 11/10/1'));
        $this->assertSame('Rettungshunde-Fahrzeug (ASB)', $c('Sama München 51/59/1'));
        $this->assertSame('Rettungshunde-Fahrzeug (BRK)', $c('Rotkreuz München 51/59/1'));
        $this->assertSame('RTW (BRK)', $c('Rotkreuz Musterort 71/1'));
        $this->assertSame('NEF (MHD)', $c('Johannes Musterort 76/2'));
        $this->assertSame('Fahrzeug (JUH)', $c('Akkon Oberbayern 3/7'));
        $this->assertSame('Rettungshundestaffel (MHD)', $c('SEG Rettungshunde Dachau MHD'));
        $this->assertSame('Rettungshundestaffel (ASB)', $c('Sama München Rettungshunde Mantrailer'));
        $this->assertSame('Weitere Einheit', $c('Tetra RD_LL'));
    }

    public function test_weitere_kraefte_ohne_standort_in_der_anzeige(): void {
        $labels = implode(' | ', $this->record()->unit_labels());
        foreach (['Dachau', 'Fürstenfeldbruck', 'Landsberg', 'Starnberg', 'Tetra', 'SEG'] as $place) {
            $this->assertStringNotContainsString($place, $labels);
        }
        $this->assertStringContainsString('Florian Aschheim Rettungshunde Mantrailer', $labels);
        $this->assertStringContainsString('3× Rettungshunde-Fahrzeug (JUH)', $labels);
    }

    public function test_ungueltige_exporte_werden_abgewiesen(): void {
        $this->expectException(InvalidArgumentException::class);
        ffami_mpfeuer_record::from_export(['_version' => '2016.4', 'Einsatz' => ['_id' => 'keine-uuid']]);
    }

    public function test_roundtrip_ueber_array(): void {
        $r = $this->record();
        $this->assertEquals($r, ffami_mpfeuer_record::from_array(json_decode(json_encode($r->to_array()), true)));
    }
}
