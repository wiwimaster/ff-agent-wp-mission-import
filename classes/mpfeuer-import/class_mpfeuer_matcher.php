<?php

/**
 * Ordnet MP-Feuer-Datensätze den Widget-Missionen zu.
 *
 * Schlüssel ist die Alarmzeit (ffami_alarm_ts an Mission und Queue-Eintrag,
 * Toleranz aus den Einstellungen). Läuft in beide Richtungen: beim Eingang
 * eines Exports und nach jedem gespeicherten Widget-Einsatz (ffami_mission_saved).
 * Mehrdeutige Treffer werden über die Fahrzeugnamen plausibilisiert, sonst
 * bleiben sie offen und erscheinen im Backend. Offene Einträge bleiben ohne
 * Frist liegen, bis der Einsatz im Widget erscheint (auch Jahre später).
 */
class ffami_mpfeuer_matcher {

    public const RESULT_MATCHED = 'zugeordnet';
    public const RESULT_OPEN = 'offen';
    public const RESULT_AMBIGUOUS = 'mehrdeutig';
    public const RESULT_DISCARDED = 'verworfen';

    /** Früherer Aufräum-Job (verwarf offene Einträge nach 90 Tagen); wird nur noch abgemeldet. */
    private const CLEANUP_HOOK = 'ffami_mpfeuer_cleanup';
    private const OPTION_BACKFILL_DONE = 'ffami_alarm_ts_backfilled';

    /** Meta-Keys an der Mission, die nur intern gespeichert und nie ausgegeben werden. */
    public const INTERNAL_META = ['ffami_mpf_address', 'ffami_mpf_crew', 'ffami_mpf_unknown'];

    /** Alle Meta-Keys, die die Zuordnung an der Mission schreibt. */
    public const MISSION_META = [
        'ffami_mpf_id', 'ffami_mpf_queue_id', 'ffami_mpf_start_ts', 'ffami_mpf_end_ts', 'ffami_mpf_stichwort',
        'ffami_mpf_einsatzart', 'ffami_mpf_kategorie', 'ffami_mpf_vehicles', 'ffami_mpf_units',
        'ffami_mpf_person_count', 'ffami_mpf_ortsteil', 'ffami_mpf_address', 'ffami_mpf_crew', 'ffami_mpf_unknown',
    ];

    public function __construct() {
        add_action('ffami_mission_saved', [$this, 'on_mission_saved'], 10, 1);
        add_action('admin_init', [$this, 'backfill_on_admin_init']);
        add_action('admin_init', [$this, 'unschedule_cleanup']);
        add_filter('is_protected_meta', [$this, 'protect_internal_meta'], 10, 2);
    }

    /** Neuer oder aktualisierter Widget-Einsatz: offene Exporte im Zeitfenster erneut zuordnen. */
    public function on_mission_saved($post_id): void {
        $post_id = (int) $post_id;
        $ts = (int) get_post_meta($post_id, 'ffami_alarm_ts', true);
        if ($ts <= 0) { return; }
        foreach (ffami_mpfeuer_queue::find_open_near($ts, ffami_mpfeuer_settings::tolerance()) as $queue_id) {
            self::match($queue_id);
        }
    }

    /**
     * Versucht, einen Queue-Eintrag zuzuordnen.
     * Bereits zugeordnete Einträge werden auf ihre Mission neu geschrieben (aktualisierter Export).
     */
    public static function match(int $queue_id): string {
        $record = ffami_mpfeuer_queue::get_record($queue_id);
        if (!$record) { return self::RESULT_OPEN; }
        $status = ffami_mpfeuer_queue::get_status($queue_id);
        if ($status === ffami_mpfeuer_queue::STATUS_DISCARDED) { return self::RESULT_DISCARDED; }
        if ($status === ffami_mpfeuer_queue::STATUS_MATCHED) {
            $mission_id = (int) get_post_meta($queue_id, 'ffami_matched_post', true);
            if ($mission_id && get_post($mission_id)) {
                self::apply($queue_id, $mission_id, $record);
                return self::RESULT_MATCHED;
            }
            ffami_mpfeuer_queue::set_status($queue_id, ffami_mpfeuer_queue::STATUS_OPEN);
        }
        if (!$record->start_ts) { return self::RESULT_OPEN; }

        $candidates = self::find_candidates($record);
        if (count($candidates) === 0) {
            ffami_mpfeuer_queue::set_candidates($queue_id, []);
            return self::RESULT_OPEN;
        }
        if (count($candidates) > 1) {
            $candidates = self::narrow_by_vehicles($record, $candidates);
        }
        if (count($candidates) === 1) {
            self::apply($queue_id, $candidates[0], $record);
            return self::RESULT_MATCHED;
        }
        ffami_mpfeuer_queue::set_candidates($queue_id, $candidates);
        return self::RESULT_AMBIGUOUS;
    }

    /** Missionen im Zeitfenster, die nicht schon einem anderen Export gehören. */
    public static function find_candidates(ffami_mpfeuer_record $record): array {
        $tol = ffami_mpfeuer_settings::tolerance();
        $ids = get_posts([
            'post_type'      => 'mission',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key' => 'ffami_alarm_ts', 'value' => [$record->start_ts - $tol, $record->start_ts + $tol], 'compare' => 'BETWEEN', 'type' => 'NUMERIC'],
            ],
        ]);
        $out = [];
        foreach ($ids as $id) {
            $owner = (string) get_post_meta((int) $id, 'ffami_mpf_id', true);
            if ($owner !== '' && $owner !== $record->id) { continue; }
            $out[] = (int) $id;
        }
        return $out;
    }

    /** Mehrdeutig: Kandidaten behalten, deren Widget-Fahrzeuge sich mit dem Export überschneiden. */
    private static function narrow_by_vehicles(ffami_mpfeuer_record $record, array $candidates): array {
        $names = array_map('mb_strtolower', $record->vehicle_names());
        if (!$names) { return $candidates; }
        $hits = [];
        foreach ($candidates as $id) {
            $widget = array_map(fn($v) => mb_strtolower((string) $v), (array) get_post_meta($id, 'ffami_mission_vehicles', true));
            if (array_intersect($names, $widget)) { $hits[] = $id; }
        }
        return $hits ?: $candidates;
    }

    /** Felder an die Mission schreiben und Queue-Eintrag als zugeordnet markieren. */
    public static function apply(int $queue_id, int $mission_id, ?ffami_mpfeuer_record $record = null): void {
        $record = $record ?? ffami_mpfeuer_queue::get_record($queue_id);
        if (!$record) { return; }
        $record->remap_vehicles(ffami_mpfeuer_settings::vehicle_map());
        update_post_meta($mission_id, 'ffami_mpf_id', $record->id);
        update_post_meta($mission_id, 'ffami_mpf_queue_id', $queue_id);
        update_post_meta($mission_id, 'ffami_mpf_start_ts', (int) $record->start_ts);
        update_post_meta($mission_id, 'ffami_mpf_end_ts', (int) $record->end_ts);
        update_post_meta($mission_id, 'ffami_mpf_stichwort', $record->stichwort);
        update_post_meta($mission_id, 'ffami_mpf_einsatzart', $record->einsatzart);
        update_post_meta($mission_id, 'ffami_mpf_kategorie', $record->kategorie);
        update_post_meta($mission_id, 'ffami_mpf_vehicles', $record->vehicle_names());
        update_post_meta($mission_id, 'ffami_mpf_units', $record->unit_labels());
        update_post_meta($mission_id, 'ffami_mpf_person_count', $record->person_count);
        update_post_meta($mission_id, 'ffami_mpf_ortsteil', (string) ($record->address['ortsteil'] ?? ''));
        // intern: Adresse und Besatzungsstärke
        update_post_meta($mission_id, 'ffami_mpf_address', $record->address);
        $crew = [];
        foreach ($record->vehicles as $v) { if ($v['crew'] !== null) { $crew[$v['name']] = (int) $v['crew']; } }
        update_post_meta($mission_id, 'ffami_mpf_crew', $crew);
        update_post_meta($mission_id, 'ffami_mpf_unknown', $record->unknown_entries());
        ffami_mpfeuer_queue::set_status($queue_id, ffami_mpfeuer_queue::STATUS_MATCHED, $mission_id);
    }

    /** Zuordnung lösen: Metas an der Mission entfernen. */
    public static function detach(int $queue_id): void {
        $mission_id = (int) get_post_meta($queue_id, 'ffami_matched_post', true);
        if ($mission_id && (int) get_post_meta($mission_id, 'ffami_mpf_queue_id', true) === $queue_id) {
            foreach (self::MISSION_META as $key) { delete_post_meta($mission_id, $key); }
        }
    }

    /** Manuell zuordnen (Backend). Löst eine vorherige Zuordnung zu einer anderen Mission. */
    public static function assign_manually(int $queue_id, int $mission_id): bool {
        $post = get_post($mission_id);
        if (!$post || $post->post_type !== 'mission') { return false; }
        $owner = (string) get_post_meta($mission_id, 'ffami_mpf_queue_id', true);
        if ($owner !== '' && (int) $owner !== $queue_id) {
            // Mission gehörte einem anderen Export: dieser wird wieder offen
            ffami_mpfeuer_queue::set_status((int) $owner, ffami_mpfeuer_queue::STATUS_OPEN);
        }
        self::detach($queue_id);
        self::apply($queue_id, $mission_id);
        return true;
    }

    public static function discard(int $queue_id): void {
        self::detach($queue_id);
        ffami_mpfeuer_queue::set_status($queue_id, ffami_mpfeuer_queue::STATUS_DISCARDED);
    }

    /** "Zuordnung nachholen": offene Einträge erneut versuchen, zugeordnete neu schreiben (z.B. nach geänderter Fahrzeug-Zuordnung). */
    public static function retry(): array {
        $stats = [];
        $ids = array_merge(
            ffami_mpfeuer_queue::list_ids(ffami_mpfeuer_queue::STATUS_OPEN, -1),
            ffami_mpfeuer_queue::list_ids(ffami_mpfeuer_queue::STATUS_MATCHED, -1)
        );
        foreach ($ids as $id) {
            $r = self::match($id);
            $stats[$r] = ($stats[$r] ?? 0) + 1;
        }
        return $stats;
    }

    /** Alten Aufräum-Job aus Action Scheduler entfernen. */
    public function unschedule_cleanup(): void {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_unschedule_all_actions')) { return; }
        if (as_has_scheduled_action(self::CLEANUP_HOOK, [], 'ffami')) {
            as_unschedule_all_actions(self::CLEANUP_HOOK, [], 'ffami');
        }
    }

    /** Hook-Hülle ohne Parameter: WordPress reicht bei admin_init einen Leerstring als erstes Argument durch. */
    public function backfill_on_admin_init(): void {
        self::backfill_alarm_ts();
    }

    /**
     * Einmalige Nachrüstung: ffami_alarm_ts für bestehende Missionen aus post_date_gmt.
     * Läuft in Blöcken, bis keine Mission ohne Wert mehr übrig ist.
     */
    public static function backfill_alarm_ts(int $batch = 200): int {
        if (get_option(self::OPTION_BACKFILL_DONE, '0') === '1') { return 0; }
        $ids = get_posts([
            'post_type'      => 'mission',
            'post_status'    => 'any',
            'posts_per_page' => $batch,
            'fields'         => 'ids',
            'meta_query'     => [['key' => 'ffami_alarm_ts', 'compare' => 'NOT EXISTS']],
        ]);
        $done = 0;
        foreach ($ids as $id) {
            $post = get_post((int) $id);
            if (!$post) { continue; }
            $ts = strtotime($post->post_date_gmt . ' UTC');
            update_post_meta((int) $id, 'ffami_alarm_ts', $ts > 0 ? $ts : 0);
            $done++;
        }
        if (count($ids) < $batch) { update_option(self::OPTION_BACKFILL_DONE, '1', false); }
        return $done;
    }

    /** Interne Metas aus der Custom-Fields-Box heraushalten. */
    public function protect_internal_meta($protected, $meta_key) {
        return in_array($meta_key, self::INTERNAL_META, true) ? true : $protected;
    }
}
