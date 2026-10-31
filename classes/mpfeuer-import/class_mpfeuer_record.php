<?php

/**
 * Bereinigter MP-Feuer-Datensatz.
 *
 * Wendet beim Einlesen die Datenregeln an (Whitelist): Alarmgeber, Leitstellen-Nr,
 * Fahrzeug-/Ausrückzeiten, Tagebuch, Einsatzleiter, Personennamen, Rollen und
 * Freitexte werden verworfen und landen nirgends. Übrig bleiben nur die Felder,
 * die gespeichert werden dürfen (Adresse und Besatzung davon nur intern).
 */
class ffami_mpfeuer_record {

    /** Zeitzone der Exportzeiten (Ortszeit ohne Zonenangabe, verifiziert 2026-10-31). */
    public const EXPORT_TIMEZONE = 'Europe/Berlin';

    /** Standard-Zuordnung Funkrufname-Präfix → Organisation (bayerische Funkrufnamen). */
    public const DEFAULT_ORGS = [
        'Florian'   => 'FW',
        'Rotkreuz'  => 'BRK',
        'Akkon'     => 'JUH',
        'Johannes'  => 'MHD',
        'Sama'      => 'ASB',
        'Heros'     => 'THW',
        'Christoph' => 'RTH',
        'Polizei'   => 'Polizei',
    ];

    /** Standard-Zuordnung Kennzahl → Fahrzeugtyp. */
    public const DEFAULT_TYPES = [
        '10' => 'ELW',
        '40' => 'LF',
        '59' => 'Rettungshunde-Fahrzeug',
        '71' => 'RTW',
        '72' => 'KTW',
        '76' => 'NEF',
    ];

    /** Organisationskürzel, die auch ohne Funkrufname-Präfix im Text erkannt werden. */
    private const ORG_TOKENS = ['BRK', 'JUH', 'MHD', 'ASB', 'THW', 'DLRG', 'RTH'];

    /** Schlagworte für Einheiten ohne Kennzahl (Freitext-Bezeichnungen). */
    private const KEYWORD_TYPES = [
        'rettungshunde' => 'Rettungshundestaffel',
        'drohne'        => 'Drohne',
        'uav'           => 'Drohne',
    ];

    public string $id = '';

    public ?int $start_ts = null;

    public ?int $end_ts = null;

    public string $stichwort = '';

    public string $einsatzart = '';

    public string $kategorie = '';

    /** Intern: Straße, PLZ, Ort, Ortsteil, Objekt. Wird nie öffentlich ausgegeben. */
    public array $address = [];

    /** Liste [fms_id, name, crew, known] – crew nur intern. */
    public array $vehicles = [];

    /** Liste [label, org, type, fire, known] – label ist die öffentliche Darstellung. */
    public array $units = [];

    public int $person_count = 0;

    /**
     * Liest einen MP-Feuer-Export (dekodiertes JSON) ein.
     *
     * @param array $data          Body des Exports (mit Schlüssel 'Einsatz')
     * @param array $vehicle_map   FMS_ID → eigener Anzeigename
     * @param array $orgs          Funkrufname-Präfix → Organisation
     * @param array $types         Kennzahl → Fahrzeugtyp
     * @throws InvalidArgumentException wenn Struktur oder _id fehlen
     */
    public static function from_export(array $data, array $vehicle_map = [], array $orgs = self::DEFAULT_ORGS, array $types = self::DEFAULT_TYPES): self {
        $e = $data['Einsatz'] ?? null;
        if (!is_array($e)) {
            throw new InvalidArgumentException('Einsatz fehlt');
        }
        $id = self::text($e['_id'] ?? '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            throw new InvalidArgumentException('_id fehlt oder ungültig');
        }
        $a = is_array($e['Allgemein'] ?? null) ? $e['Allgemein'] : [];

        $r = new self();
        $r->id = strtolower($id);
        $r->start_ts = self::parse_time($a['Zeit_Von'] ?? null);
        $r->end_ts = self::parse_time($a['Zeit_Bis'] ?? null);
        $r->stichwort = self::text($a['Alarmstichwort'] ?? '');
        $r->einsatzart = self::text($a['Einsatz_Art'] ?? '');
        $r->kategorie = self::text($a['Kategorie'] ?? '');

        $o = is_array($a['Einsatzort'] ?? null) ? $a['Einsatzort'] : [];
        $r->address = [
            'strasse'  => self::text($o['Strasse'] ?? ''),
            'plz'      => self::text($o['PLZ'] ?? ''),
            'ort'      => self::text($o['Ort'] ?? ''),
            'ortsteil' => self::text($o['Ortsteil'] ?? ''),
            'objekt'   => self::text($o['Objekt_Name'] ?? ''),
        ];

        foreach ((array)($e['Fahrzeuge'] ?? []) as $v) {
            if (!is_array($v)) { continue; }
            $fms = self::text($v['FMS_ID'] ?? '');
            $label = self::text($v['Bezeichnung'] ?? '');
            if ($fms === '' && $label === '') { continue; }
            $known = $fms !== '' && isset($vehicle_map[$fms]) && trim((string)$vehicle_map[$fms]) !== '';
            $r->vehicles[] = [
                'fms_id' => $fms,
                'name'   => $known ? trim((string)$vehicle_map[$fms]) : ($label !== '' ? $label : $fms),
                'crew'   => isset($v['Besatzung']) && is_numeric($v['Besatzung']) ? (int)$v['Besatzung'] : null,
                'known'  => $known,
            ];
        }

        foreach ((array)($e['Sonstige_EK'] ?? []) as $u) {
            if (!is_array($u)) { continue; }
            $label = self::text($u['Bezeichnung'] ?? '');
            if ($label === '') { continue; }
            $r->units[] = self::classify_unit($label, $orgs, $types);
        }

        // Personal: nur die Anzahl, keine Namen, Rollen oder Zeiten
        $r->person_count = count(array_filter((array)($e['Personal'] ?? []), 'is_array'));

        return $r;
    }

    /**
     * Ordnet eine fremde Einheit ein. Feuerwehren behalten ihren Namen,
     * alle anderen werden auf Typ und Organisation reduziert (kein Standort).
     */
    public static function classify_unit(string $label, array $orgs = self::DEFAULT_ORGS, array $types = self::DEFAULT_TYPES): array {
        $words = preg_split('/\s+/', trim($label));
        $first = $words[0] ?? '';
        $org = '';
        foreach ($orgs as $prefix => $short) {
            if (strcasecmp((string)$prefix, $first) === 0) { $org = (string)$short; break; }
        }
        if ($org === 'FW') {
            return ['label' => $label, 'org' => 'FW', 'type' => '', 'fire' => true, 'known' => true];
        }
        if ($org === '') {
            foreach (self::ORG_TOKENS as $token) {
                if (preg_match('/(^|[^\p{L}])' . preg_quote($token, '/') . '([^\p{L}]|$)/u', $label)) { $org = $token; break; }
            }
        }

        $type = '';
        // Kennzahl: "71/1" → 71, "51/59/1" → 59 (Einheit/Typ/Nr)
        if (preg_match('/(\d+)\/(\d+)(?:\/(\d+))?\s*$/', $label, $m)) {
            $code = isset($m[3]) && $m[3] !== '' ? $m[2] : $m[1];
            $type = isset($types[$code]) ? (string)$types[$code] : '';
        }
        if ($type === '') {
            $lower = mb_strtolower($label);
            foreach (self::KEYWORD_TYPES as $kw => $kwType) {
                if (preg_match('/(^|[^\p{L}])' . preg_quote($kw, '/') . '/u', $lower)) { $type = $kwType; break; }
            }
        }

        $known = $org !== '' && $type !== '';
        if ($type === '' && $org === '') {
            $public = 'Weitere Einheit';
        } elseif ($type === '') {
            $public = 'Fahrzeug (' . $org . ')';
        } elseif ($org === '') {
            $public = $type;
        } else {
            $public = $type . ' (' . $org . ')';
        }
        // Original-Bezeichnung nur für die Prüfliste unbekannter Einheiten (intern) behalten
        return ['label' => $public, 'org' => $org, 'type' => $type, 'fire' => false, 'known' => $known, 'source' => $known ? '' : $label];
    }

    /** "JJJJ-MM-TTTHH:MM:SS" (Ortszeit Europe/Berlin) → Unix-Timestamp. */
    public static function parse_time($value): ?int {
        if (!is_string($value) || $value === '') { return null; }
        $tz = new DateTimeZone(self::EXPORT_TIMEZONE);
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $value, $tz);
        if (!$dt) {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $tz);
        }
        return $dt ? $dt->getTimestamp() : null;
    }

    /** Fahrzeugnamen mit der aktuellen Zuordnungstabelle neu setzen (nach Pflege im Backend). */
    public function remap_vehicles(array $vehicle_map): void {
        foreach ($this->vehicles as &$v) {
            $fms = (string) $v['fms_id'];
            if ($fms !== '' && isset($vehicle_map[$fms]) && trim((string) $vehicle_map[$fms]) !== '') {
                $v['name'] = trim((string) $vehicle_map[$fms]);
                $v['known'] = true;
            }
        }
        unset($v);
    }

    /** Öffentliche Fahrzeugnamen (eigene Bezeichnungen). */
    public function vehicle_names(): array {
        $names = array_values(array_unique(array_map(fn($v) => (string)$v['name'], $this->vehicles)));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    /** Öffentliche Darstellung der weiteren Kräfte, gleiche Typen zusammengefasst ("2× RTW (BRK)"). */
    public function unit_labels(): array {
        $counts = [];
        foreach ($this->units as $u) {
            $l = (string)$u['label'];
            $counts[$l] = ($counts[$l] ?? 0) + 1;
        }
        $out = [];
        foreach ($counts as $l => $n) { $out[] = $n > 1 ? $n . '× ' . $l : $l; }
        return $out;
    }

    /** Unbekannte Fahrzeuge und Einheiten für die Prüfliste im Backend. */
    public function unknown_entries(): array {
        $out = ['vehicles' => [], 'units' => []];
        foreach ($this->vehicles as $v) { if (!$v['known'] && $v['fms_id'] !== '') { $out['vehicles'][] = $v['fms_id']; } }
        foreach ($this->units as $u) { if (!$u['known'] && empty($u['fire'])) { $out['units'][] = (string)($u['source'] ?? $u['label']); } }
        return $out;
    }

    /** Dauer in Minuten aus Beginn/Ende (0 wenn unbekannt). */
    public function duration_minutes(): int {
        if ($this->start_ts === null || $this->end_ts === null || $this->end_ts <= $this->start_ts) { return 0; }
        return (int) round(($this->end_ts - $this->start_ts) / 60);
    }

    /** Für die Ablage in der Warteschlange. */
    public function to_array(): array {
        return [
            'id'           => $this->id,
            'start_ts'     => $this->start_ts,
            'end_ts'       => $this->end_ts,
            'stichwort'    => $this->stichwort,
            'einsatzart'   => $this->einsatzart,
            'kategorie'    => $this->kategorie,
            'address'      => $this->address,
            'vehicles'     => $this->vehicles,
            'units'        => $this->units,
            'person_count' => $this->person_count,
        ];
    }

    public static function from_array(array $a): self {
        $r = new self();
        $r->id = (string)($a['id'] ?? '');
        $r->start_ts = isset($a['start_ts']) ? (int)$a['start_ts'] : null;
        $r->end_ts = isset($a['end_ts']) ? (int)$a['end_ts'] : null;
        $r->stichwort = (string)($a['stichwort'] ?? '');
        $r->einsatzart = (string)($a['einsatzart'] ?? '');
        $r->kategorie = (string)($a['kategorie'] ?? '');
        $r->address = (array)($a['address'] ?? []);
        $r->vehicles = (array)($a['vehicles'] ?? []);
        $r->units = (array)($a['units'] ?? []);
        $r->person_count = (int)($a['person_count'] ?? 0);
        return $r;
    }

    /** Einsatzart ohne Leitstellen-Präfix ("#INF#Anforderung …" → "Anforderung …"). */
    public static function clean_einsatzart(string $value): string {
        return trim((string)preg_replace('/^#[^#]*#/', '', $value));
    }

    private static function text($value): string {
        if (!is_scalar($value)) { return ''; }
        return trim(preg_replace('/\s+/u', ' ', (string)$value) ?? '');
    }
}
