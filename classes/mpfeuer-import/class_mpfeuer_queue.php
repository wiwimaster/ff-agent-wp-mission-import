<?php

/**
 * Warteschlange für MP-Feuer-Datensätze.
 *
 * Eigener, nicht öffentlicher Post-Typ ffami_mpfeuer: bereinigtes JSON in
 * post_content, Metas ffami_mpfeuer_id (Export-_id), ffami_alarm_ts (UTC),
 * ffami_mpfeuer_status (offen/zugeordnet/verworfen), ffami_matched_post.
 */
class ffami_mpfeuer_queue {

    public const POST_TYPE = 'ffami_mpfeuer';

    public const STATUS_OPEN = 'offen';
    public const STATUS_MATCHED = 'zugeordnet';
    public const STATUS_DISCARDED = 'verworfen';

    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
    }

    public function register_post_type(): void {
        register_post_type(self::POST_TYPE, [
            'label'               => 'MP-Feuer-Eingänge',
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => false,
            'show_in_nav_menus'   => false,
            'show_in_rest'        => false,
            'has_archive'         => false,
            'query_var'           => false,
            'rewrite'             => false,
            'supports'            => ['title'],
        ]);
    }

    /**
     * Speichert einen Datensatz; dedupliziert über die Export-_id.
     * Ein erneuter Export derselben _id aktualisiert den Eintrag (Status bleibt).
     *
     * @return array{0:int,1:bool} [Queue-Post-ID, neu angelegt?]
     */
    public static function store(ffami_mpfeuer_record $record): array {
        $existing = self::find_by_export_id($record->id);
        $title = trim('MP-Feuer ' . ($record->stichwort !== '' ? $record->stichwort : $record->kategorie));
        if ($record->start_ts) { $title .= ' ' . wp_date('Y-m-d H:i', $record->start_ts); }
        // HEX-Flags: keine <, >, & im Inhalt, damit kses/Filter das JSON nicht verändern
        $json = wp_json_encode($record->to_array(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $arr = [
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'private',
            'post_title'   => $title,
            'post_content' => wp_slash((string) $json),
        ];
        if ($existing) { $arr['ID'] = $existing; }
        $id = wp_insert_post($arr, true);
        if (is_wp_error($id) || !$id) {
            throw new RuntimeException('Queue-Eintrag konnte nicht gespeichert werden');
        }
        $id = (int) $id;
        update_post_meta($id, 'ffami_mpfeuer_id', $record->id);
        update_post_meta($id, 'ffami_alarm_ts', (int) $record->start_ts);
        if (!$existing) {
            update_post_meta($id, 'ffami_mpfeuer_status', self::STATUS_OPEN);
        }
        return [$id, !$existing];
    }

    public static function find_by_export_id(string $export_id): int {
        $ids = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [['key' => 'ffami_mpfeuer_id', 'value' => strtolower($export_id), 'compare' => '=']],
        ]);
        return $ids ? (int) $ids[0] : 0;
    }

    public static function get_record(int $queue_id): ?ffami_mpfeuer_record {
        $post = get_post($queue_id);
        if (!$post || $post->post_type !== self::POST_TYPE) { return null; }
        $data = json_decode((string) $post->post_content, true);
        return is_array($data) ? ffami_mpfeuer_record::from_array($data) : null;
    }

    public static function get_status(int $queue_id): string {
        return (string) get_post_meta($queue_id, 'ffami_mpfeuer_status', true);
    }

    public static function set_status(int $queue_id, string $status, int $mission_id = 0): void {
        update_post_meta($queue_id, 'ffami_mpfeuer_status', $status);
        if ($mission_id) {
            update_post_meta($queue_id, 'ffami_matched_post', $mission_id);
        } else {
            delete_post_meta($queue_id, 'ffami_matched_post');
        }
        if ($status !== self::STATUS_OPEN) {
            delete_post_meta($queue_id, 'ffami_mpfeuer_candidates');
        }
    }

    /** Kandidaten einer mehrdeutigen Zuordnung merken (Anzeige im Backend). */
    public static function set_candidates(int $queue_id, array $mission_ids): void {
        if ($mission_ids) {
            update_post_meta($queue_id, 'ffami_mpfeuer_candidates', array_map('intval', $mission_ids));
        } else {
            delete_post_meta($queue_id, 'ffami_mpfeuer_candidates');
        }
    }

    public static function get_candidates(int $queue_id): array {
        return array_map('intval', (array) (get_post_meta($queue_id, 'ffami_mpfeuer_candidates', true) ?: []));
    }

    /** Einträge nach Status (neueste zuerst). */
    public static function list_ids(string $status, int $limit = 50): array {
        return array_map('intval', get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'meta_query'     => [['key' => 'ffami_mpfeuer_status', 'value' => $status, 'compare' => '=']],
        ]));
    }

    /** Offene Einträge, deren Alarmzeit im Fenster ts ± tolerance liegt. */
    public static function find_open_near(int $ts, int $tolerance): array {
        return array_map('intval', get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key' => 'ffami_mpfeuer_status', 'value' => self::STATUS_OPEN, 'compare' => '='],
                ['key' => 'ffami_alarm_ts', 'value' => [$ts - $tolerance, $ts + $tolerance], 'compare' => 'BETWEEN', 'type' => 'NUMERIC'],
            ],
        ]));
    }

    public static function count(string $status): int {
        return count(self::list_ids($status, -1));
    }
}
