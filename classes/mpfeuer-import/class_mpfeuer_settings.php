<?php

/**
 * Einstellungen des MP-Feuer-Moduls (Option ffami_mpfeuer_settings).
 *
 * Zugangsdaten: Konstanten FFAMI_MPFEUER_USER / FFAMI_MPFEUER_PASS in wp-config.php
 * haben Vorrang; sonst Benutzer + Passwort-Hash aus der Option. Das Klartext-Passwort
 * wird nie gespeichert.
 */
class ffami_mpfeuer_settings {

    public const OPTION = 'ffami_mpfeuer_settings';

    public const DEFAULT_TOLERANCE = 600; // ±10 Minuten

    public static function all(): array {
        $s = get_option(self::OPTION, []);
        return is_array($s) ? $s : [];
    }

    public static function update(array $changes): void {
        update_option(self::OPTION, array_merge(self::all(), $changes), false);
    }

    public static function credentials_from_constants(): bool {
        return defined('FFAMI_MPFEUER_USER') && defined('FFAMI_MPFEUER_PASS') && FFAMI_MPFEUER_USER !== '' && FFAMI_MPFEUER_PASS !== '';
    }

    public static function user(): string {
        if (self::credentials_from_constants()) { return (string) FFAMI_MPFEUER_USER; }
        return (string) (self::all()['user'] ?? '');
    }

    /** Sind Zugangsdaten vollständig hinterlegt? Ohne sie nimmt der Endpunkt nichts an. */
    public static function has_credentials(): bool {
        if (self::credentials_from_constants()) { return true; }
        $s = self::all();
        return !empty($s['user']) && !empty($s['pass_hash']);
    }

    public static function check_credentials(string $user, string $pass): bool {
        if (!self::has_credentials() || $user === '' || $pass === '') { return false; }
        if (!hash_equals(self::user(), $user)) { return false; }
        if (self::credentials_from_constants()) {
            return hash_equals((string) FFAMI_MPFEUER_PASS, $pass);
        }
        return wp_check_password($pass, (string) self::all()['pass_hash']);
    }

    public static function set_password(string $pass): void {
        self::update(['pass_hash' => wp_hash_password($pass)]);
    }

    /** Erwartete sechsstellige Kennung im Pfad (Buchstaben und Ziffern); leer = jede Kennung annehmen. */
    public static function kennung(): string {
        return (string) (self::all()['kennung'] ?? '');
    }

    /** Toleranz für die Zuordnung über die Alarmzeit in Sekunden. */
    public static function tolerance(): int {
        $t = (int) (self::all()['tolerance'] ?? self::DEFAULT_TOLERANCE);
        return $t > 0 ? $t : self::DEFAULT_TOLERANCE;
    }

    /** FMS_ID → eigener Fahrzeugname. */
    public static function vehicle_map(): array {
        return (array) apply_filters('ffami_mpfeuer_vehicle_map', (array) (self::all()['vehicle_map'] ?? []));
    }

    /** Funkrufname-Präfix → Organisation. */
    public static function orgs(): array {
        $orgs = self::all()['orgs'] ?? null;
        return (array) apply_filters('ffami_mpfeuer_unit_orgs', is_array($orgs) && $orgs ? $orgs : ffami_mpfeuer_record::DEFAULT_ORGS);
    }

    /** Kennzahl → Fahrzeugtyp. */
    public static function types(): array {
        $types = self::all()['types'] ?? null;
        return (array) apply_filters('ffami_mpfeuer_unit_types', is_array($types) && $types ? $types : ffami_mpfeuer_record::DEFAULT_TYPES);
    }

    /** Textarea "Schlüssel = Wert" je Zeile → Array. */
    public static function parse_map(string $text): array {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) { continue; }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if ($k !== '' && $v !== '') { $out[sanitize_text_field($k)] = sanitize_text_field($v); }
        }
        return $out;
    }

    public static function format_map(array $map): string {
        $lines = [];
        foreach ($map as $k => $v) { $lines[] = $k . ' = ' . $v; }
        return implode("\n", $lines);
    }
}
