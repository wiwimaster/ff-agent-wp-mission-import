<?php

class ffami_admin_panel {

    public function __construct() {
    }


    public function add_admin_menu() {
        add_menu_page(
            __('FF Agent WP Mission Import', 'ffami'),
            __('FF Agent Mission Import', 'ffami'),
            'manage_options',
            'ffami_admin_panel',
            array($this, 'render_admin_page'),
            'dashicons-admin-generic',
            6
        );
    }
    public function render_admin_page() {
        if (!current_user_can('manage_options')) { return; }

        // Verarbeitung des Formulars
        if (isset($_POST['ffami_widget_submit']) && check_admin_referer('ffami_widget_id_form')) {
            $new = sanitize_text_field($_POST['widget_id'] ?? '');
            if ($new) {
                update_option('ffami_uid', $new, false);
                // Konstante neu setzen (nur falls noch nicht definiert)
                // FFAMI_FILE_MAIN neu definieren: dazu evtl. neu initialisieren
                if (defined('FFAMI_FILE_MAIN')) {
                    // Alten Wert lassen; nächste Seite lädt vars erneut bei Plugin Boot nur bei Reload.
                }
                // Feedback
                echo '<div class="notice notice-success"><p>Widget ID gespeichert.</p></div>';
            }
        }

        $current_uid = get_option('ffami_uid', '');
        $root_url = $current_uid ? esc_html(FFAMI_DATA_PATH . $current_uid) : '';
        // render a simple admin page with content
        echo '<div class="wrap">';
    echo '<h1>' . esc_html__('FF Agent WP Mission Import', 'ffami') . '</h1>';
    echo '<p>' . esc_html__('Willkommen im FF Agent WP Mission Import Admin Panel!', 'ffami') . '</p>';
    echo '<p>' . esc_html__('Hier können Sie die Einstellungen für den Import von Einsatzdaten von FF Agent vornehmen.', 'ffami') . '</p>';
    
        //ask for Widget ID

    echo '<h2>' . esc_html__('Widget ID', 'ffami') . '</h2>';
    echo '<p>' . esc_html__('Bitte geben Sie die FF Agent Widget ID ein. Diese steuert den Import.', 'ffami') . '</p>';
        echo '<form method="post" action="">';
        wp_nonce_field('ffami_widget_id_form');
        echo '<input type="text" name="widget_id" style="width:400px" value="' . esc_attr($current_uid) . '" placeholder="059B..." required /> ';
        echo '<input type="submit" class="button button-primary" name="ffami_widget_submit" value="Speichern" />';
        echo '</form>';
        if ($current_uid) {
            echo '<p><strong>' . esc_html__('Aktuelle Root-URL:', 'ffami') . '</strong><br><code>' . $root_url . '</code></p>';
        } else {
            echo '<p><em>' . esc_html__('Noch keine Widget ID gesetzt – Import inaktiv.', 'ffami') . '</em></p>';
        }

        // Sofort-Check Button Verarbeitung (Full Scan aller Jahre manuell)
        if (isset($_POST['ffami_force_check']) && check_admin_referer('ffami_force_check_action')) {
            $forced = false;
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('ffami_check_years_full', [], 'ffami');
                $forced = true;
            } else {
                do_action('ffami_check_years_full');
                $forced = true;
            }
            if ($forced) {
                echo '<div class="notice notice-success"><p>Full Scan aller Jahre gestartet.</p></div>';
            }
        }

        // Reset & Neu-Import Verarbeitung
    if (isset($_POST['ffami_reset_all']) && check_admin_referer('ffami_reset_all_action')) {
            $deleted = 0; $attachDeleted = 0;
            // 1. Alle Mission Posts löschen
            $all_missions = get_posts(['post_type'=>'mission','numberposts'=>-1,'fields'=>'ids']);
            foreach ($all_missions as $mid) {
                // zugehörige Attachments optional mitlöschen (Gallery/Featured)
                $children = get_children(['post_parent'=>$mid,'post_type'=>'attachment','fields'=>'ids']);
                foreach ($children as $cid) { if (wp_delete_attachment($cid, true)) { $attachDeleted++; } }
                if (wp_delete_post($mid, true)) { $deleted++; }
            }
            // 2. Optionen / Caches leeren
            global $wpdb;
            $likePrefixes = [ 'ffami_year_md5_%','ffami_year_json_%','ffami_year_sample_%','ffami_year_completed_%' ];
            foreach ($likePrefixes as $pref) {
                $wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pref) );
            }
            $singleOptions = [
                'ffami_root_json','ffami_root_md5','ffami_root_sample','ffami_root_fetched_at','ffami_highest_year',
                'ffami_scan_stats','ffami_last_scheduled','ffami_last_check','ffami_pending_missions'
            ];
            foreach ($singleOptions as $opt) { delete_option($opt); }
            if (class_exists('ffami_debug_logger')) { ffami_debug_logger::clear(); }
            // 3. Root Refresh + Full Scan neu anstoßen
            // Immer synchron ausführen, damit sofort neu importiert wird
            do_action('ffami_refresh_root_years');
            do_action('ffami_check_years_full');
            // Zusätzlich asynchron nochmals absichern
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('ffami_check_years_full', [], 'ffami');
            }
            echo '<div class="notice notice-success"><p>Reset durchgeführt: '.esc_html($deleted).' Einsätze (+' . esc_html($attachDeleted) . ' Anhänge) gelöscht. Vollständiger Neu-Import gestartet.</p></div>';
        }

        // Nur Löschen (Purge) ohne Neuimport
        if (isset($_POST['ffami_purge_all']) && check_admin_referer('ffami_purge_all_action')) {
            $deleted = 0; $attachDeleted = 0;
            $all_missions = get_posts(['post_type'=>'mission','numberposts'=>-1,'fields'=>'ids']);
            foreach ($all_missions as $mid) {
                $children = get_children(['post_parent'=>$mid,'post_type'=>'attachment','fields'=>'ids']);
                foreach ($children as $cid) { if (wp_delete_attachment($cid, true)) { $attachDeleted++; } }
                if (wp_delete_post($mid, true)) { $deleted++; }
            }
            global $wpdb;
            $likePrefixes = [ 'ffami_year_md5_%','ffami_year_json_%','ffami_year_sample_%','ffami_year_completed_%' ];
            foreach ($likePrefixes as $pref) { $wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pref) ); }
            $singleOptions = [ 'ffami_root_json','ffami_root_md5','ffami_root_sample','ffami_root_fetched_at','ffami_highest_year','ffami_scan_stats','ffami_last_scheduled','ffami_last_check','ffami_pending_missions' ];
            foreach ($singleOptions as $opt) { delete_option($opt); }
            if (class_exists('ffami_debug_logger')) { ffami_debug_logger::clear(); }
            echo '<div class="notice notice-success"><p>Alle Einsatzdaten gelöscht: '.esc_html($deleted).' Einsätze (+' . esc_html($attachDeleted) . ' Anhänge). Kein Neu-Import angestoßen.</p></div>';
        }

        // Statusboard
    echo '<hr><h2>' . esc_html__('Status', 'ffami') . '</h2>';
        $last_run = get_option('ffami_last_run', '–');
        $last_count = (int)get_option('ffami_last_run_imported', 0);
        $queue_size = (int)get_option('ffami_queue_size', 0);
    echo '<p>' . sprintf(esc_html__('Letzter Cron-Lauf: %1$s (importiert: %2$d, Queue: %3$d)', 'ffami'), esc_html($last_run), esc_html($last_count), esc_html($queue_size)) . '</p>';
        $last_check = get_option('ffami_last_check', '–');
        $last_scheduled = (int)get_option('ffami_last_scheduled', 0);
    echo '<p>' . sprintf(esc_html__('Letzte Diff-Prüfung: %1$s; neu geplante Missionen: %2$d', 'ffami'), esc_html($last_check), esc_html($last_scheduled)) . '</p>';

        // Sofort-Check Formular
        echo '<form method="post" style="margin:15px 0;">';
        wp_nonce_field('ffami_force_check_action');
    echo '<input type="submit" name="ffami_force_check" class="button button-secondary" value="Full Scan jetzt (alle Jahre prüfen & planen)" />';
        echo '</form>';

    // Reset Button
    echo '<form method="post" style="margin:5px 0;" onsubmit="return confirm(\'Alle Einsätze und Import-Zwischenspeicher wirklich löschen? Dies kann nicht rückgängig gemacht werden.\');">';
    wp_nonce_field('ffami_reset_all_action');
    echo '<input type="submit" name="ffami_reset_all" class="button button-secondary" style="background:#b32d2e; color:#fff; border-color:#b32d2e;" value="Alle Einsätze neu importieren" />';
    echo '</form>';

    // Purge Button (nur löschen)
    echo '<form method="post" style="margin:5px 0;" onsubmit="return confirm(\'Alle Einsätze und Import-Zwischenspeicher LÖSCHEN ohne Neu-Import?\');">';
    wp_nonce_field('ffami_purge_all_action');
    echo '<input type="submit" name="ffami_purge_all" class="button" style="background:#771d1e; color:#fff; border-color:#771d1e;" value="Alle Einsatzdaten löschen (ohne Neu-Import)" />';
    echo '</form>';

        // Gesamte Missionsstatistik
        $mission_query = new WP_Query([
            'post_type' => 'mission',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);
        $total = $mission_query->found_posts;
    $by_year = [];
    $with_image = 0; $with_title = 0; $with_content = 0;
    $cat_totals = ['thl'=>0,'brand'=>0,'fr'=>0,'other'=>0];
        if ($total > 0) {
            foreach ($mission_query->posts as $pid) {
                $dt = get_post_field('post_date', $pid);
                $year = substr($dt, 0, 4);
        if (!isset($by_year[$year])) { $by_year[$year] = ['total'=>0,'image'=>0,'title'=>0,'content'=>0,'thl'=>0,'brand'=>0,'fr'=>0,'other'=>0]; }
                $by_year[$year]['total']++;
                if (has_post_thumbnail($pid)) { $with_image++; $by_year[$year]['image']++; }
                $raw_t = get_post_meta($pid, 'ffami_mission_title', true);
                if (trim((string)$raw_t) !== '') { $with_title++; $by_year[$year]['title']++; }
                $c = get_post_field('post_content', $pid);
                if (trim(strip_tags($c)) !== '') { $with_content++; $by_year[$year]['content']++; }
        $type = get_post_meta($pid, 'ffami_mission_type', true);
        $norm = mb_strtolower(trim((string)$type));
        if ($norm === 'technische hilfeleistung') { $cat = 'thl'; }
        elseif ($norm === 'brand') { $cat = 'brand'; }
        elseif (strpos($norm, 'first responder') !== false) { $cat = 'fr'; }
        else { $cat = 'other'; }
        $by_year[$year][$cat]++;
        $cat_totals[$cat]++;
            }
        }

    // Gesamtblock entfernt – Summen erscheinen am Tabellenende

        if (!empty($by_year)) {
            echo '<h3>' . esc_html__('Pro Jahr', 'ffami') . '</h3>';
            echo '<table class="widefat striped" style="max-width:900px">';
            echo '<thead><tr><th>' . esc_html__('Jahr', 'ffami') . '</th><th>' . esc_html__('Gesamt', 'ffami') . '</th><th>' . esc_html__('Bild', 'ffami') . '</th><th>' . esc_html__('Titel', 'ffami') . '</th><th>' . esc_html__('Beschreibung', 'ffami') . '</th><th>THL</th><th>' . esc_html__('Brand', 'ffami') . '</th><th>' . esc_html__('First Resp.', 'ffami') . '</th><th>' . esc_html__('Sonstige', 'ffami') . '</th></tr></thead><tbody>';
            krsort($by_year);
            foreach ($by_year as $year=>$stats) {
                echo '<tr>';
                echo '<td>' . esc_html($year) . '</td>';
                echo '<td>' . esc_html($stats['total']) . '</td>';
                echo '<td>' . esc_html($stats['image']) . '</td>';
                echo '<td>' . esc_html($stats['title']) . '</td>';
                echo '<td>' . esc_html($stats['content']) . '</td>';
                echo '<td>' . esc_html($stats['thl']) . '</td>';
                echo '<td>' . esc_html($stats['brand']) . '</td>';
                echo '<td>' . esc_html($stats['fr']) . '</td>';
                echo '<td>' . esc_html($stats['other']) . '</td>';
                echo '</tr>';
            }
            // Summenzeile
            echo '<tr style="font-weight:bold; background:#f5f5f5">';
            echo '<td>' . esc_html__('Summe', 'ffami') . '</td>';
            echo '<td>' . esc_html($total) . '</td>';
            echo '<td>' . esc_html($with_image) . '</td>';
            echo '<td>' . esc_html($with_title) . '</td>';
            echo '<td>' . esc_html($with_content) . '</td>';
            echo '<td>' . esc_html($cat_totals['thl']) . '</td>';
            echo '<td>' . esc_html($cat_totals['brand']) . '</td>';
            echo '<td>' . esc_html($cat_totals['fr']) . '</td>';
            echo '<td>' . esc_html($cat_totals['other']) . '</td>';
            echo '</tr>';
            echo '</tbody></table>';
        }

        // Scan Stats
        $scan_stats = get_option('ffami_scan_stats', []);
        if (!empty($scan_stats)) {
            echo '<h3>Letzter Scan (Diff-Statistik)</h3>';
            echo '<table class="widefat striped" style="max-width:750px">';
            echo '<thead><tr><th>Jahr</th><th>Gefunden</th><th>Vorher</th><th>Diff</th><th>Entfernt</th><th>Gelöscht</th></tr></thead><tbody>';
            krsort($scan_stats);
            foreach ($scan_stats as $y=>$st) {
                $removed = isset($st['removed']) ? (int)$st['removed'] : 0;
                $removed_deleted = isset($st['removed_deleted']) ? (int)$st['removed_deleted'] : 0;
                echo '<tr><td>'.esc_html($y).'</td><td>'.esc_html($st['new_count']).'</td><td>'.esc_html($st['old_count']).'</td><td>'.esc_html($st['diff']).'</td><td>'.esc_html($removed).'</td><td>'.esc_html($removed_deleted).'</td></tr>';
            }
            echo '</tbody></table>';
        }

        // Root / Year Samples
        if (ffami_debug_logger::is_verbose()) {
            echo '<h3>Rohdaten Samples</h3>';
            $root_sample = get_option('ffami_root_sample', '');
            if ($root_sample) {
                echo '<p><strong>Root Sample:</strong></p><pre style="max-height:200px; overflow:auto; background:#fff;">'.esc_html($root_sample).'</pre>';
            }
            if (!empty($scan_stats)) {
                $shown = 0;
                foreach ($scan_stats as $y=>$st) {
                    $sample = get_option('ffami_year_sample_' . sanitize_key((string)$y), '');
                    if ($sample) {
                        echo '<p><strong>Jahr '.esc_html($y).' Sample:</strong></p><pre style="max-height:200px; overflow:auto; background:#fff;">'.esc_html($sample).'</pre>';
                    }
                    if (++$shown >= 3) { break; } // max 3 Samples anzeigen
                }
            }
        }

        // Debug Log Steuerung
        echo '<hr><h2>Debug Log</h2>';
        if (isset($_POST['ffami_debug_clear']) && check_admin_referer('ffami_debug_actions')) {
            ffami_debug_logger::clear();
            echo '<div class="notice notice-success"><p>Debug Log geleert.</p></div>';
        }
        if (isset($_POST['ffami_debug_verbose_toggle']) && check_admin_referer('ffami_debug_actions')) {
            ffami_debug_logger::set_verbose(!ffami_debug_logger::is_verbose());
            echo '<div class="notice notice-success"><p>Verbose Modus umgeschaltet.</p></div>';
        }
        $verbose = ffami_debug_logger::is_verbose();
        echo '<form method="post" style="margin-bottom:10px;">';
        wp_nonce_field('ffami_debug_actions');
        echo '<input type="submit" name="ffami_debug_verbose_toggle" class="button" value="Verbose '.($verbose?'deaktivieren':'aktivieren').'" /> ';
        echo '<input type="submit" name="ffami_debug_clear" class="button" value="Log leeren" />';
        echo '</form>';
        $log = ffami_debug_logger::get_log();
        if (empty($log)) {
            echo '<p><em>Keine Log-Einträge.</em></p>';
        } else {
            echo '<div style="max-height:400px; overflow:auto; border:1px solid #ccc; background:#fff; padding:8px;">';
            echo '<table class="widefat fixed" style="font-size:12px;">';
            echo '<thead><tr><th>Zeit</th><th>Nachricht</th><th>Kontext</th></tr></thead><tbody>';
            foreach ($log as $entry) {
                $ctx = '';
                if (!empty($entry['context'])) { $ctx = '<pre style="white-space:pre-wrap;">'. esc_html(wp_json_encode($entry['context'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) .'</pre>'; }
                echo '<tr>';
                echo '<td>'.esc_html($entry['time']).'</td>';
                echo '<td>'.esc_html($entry['message']).'</td>';
                echo '<td>'.$ctx.'</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

    }
}
