<?php

class ffami_admin_panel {

    public function __construct() {
    }


    public function add_admin_menu() {
        add_menu_page(
            'FF Agent WP Mission Import',
            'FF Agent WP Mission Import',
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
        echo '<h1>FF Agent WP Mission Import</h1>';
        echo '<p>Willkommen im FF Agent WP Mission Import Admin Panel!</p>';
        echo '<p>Hier können Sie die Einstellungen für den Import von Einsatzdaten von FF Agent nach Wordpress vornehmen.</p>';
    
        //ask for Widget ID

        echo '<h2>Widget ID</h2>';
        echo '<p>Bitte geben Sie die FF Agent Widget ID ein. Diese steuert den Import.</p>';
        echo '<form method="post" action="">';
        wp_nonce_field('ffami_widget_id_form');
        echo '<input type="text" name="widget_id" style="width:400px" value="' . esc_attr($current_uid) . '" placeholder="059B..." required /> ';
        echo '<input type="submit" class="button button-primary" name="ffami_widget_submit" value="Speichern" />';
        echo '</form>';
        if ($current_uid) {
            echo '<p><strong>Aktuelle Root-URL:</strong><br><code>' . $root_url . '</code></p>';
        } else {
            echo '<p><em>Noch keine Widget ID gesetzt – Cron-Import inaktiv.</em></p>';
        }

        // Sofort-Check Button Verarbeitung
        if (isset($_POST['ffami_force_check']) && check_admin_referer('ffami_force_check_action')) {
            $forced = false;
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('ffami_check_years', [], 'ffami');
                $forced = true;
            } else {
                // Fallback synchron
                do_action('ffami_check_years');
                $forced = true;
            }
            if ($forced) {
                echo '<div class="notice notice-success"><p>Prüfung & Planung der geänderten Missionen gestartet.</p></div>';
            }
        }

        // Statusboard
        echo '<hr><h2>Status</h2>';
        $last_run = get_option('ffami_last_run', '–');
        $last_count = (int)get_option('ffami_last_run_imported', 0);
        $queue_size = (int)get_option('ffami_queue_size', 0);
        echo '<p>Letzter Cron-Lauf: <strong>' . esc_html($last_run) . '</strong> (importiert: ' . esc_html($last_count) . ', Queue: ' . esc_html($queue_size) . ')</p>';
        $last_check = get_option('ffami_last_check', '–');
        $last_scheduled = (int)get_option('ffami_last_scheduled', 0);
        echo '<p>Letzte Diff-Prüfung: <strong>' . esc_html($last_check) . '</strong>; neu geplante Missionen: ' . esc_html($last_scheduled) . '</p>';

        // Sofort-Check Formular
        echo '<form method="post" style="margin:15px 0;">';
        wp_nonce_field('ffami_force_check_action');
        echo '<input type="submit" name="ffami_force_check" class="button button-secondary" value="Jetzt alle aktualisieren (Diff prüfen & Missionen planen)" />';
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
        if ($total > 0) {
            foreach ($mission_query->posts as $pid) {
                $dt = get_post_field('post_date', $pid);
                $year = substr($dt, 0, 4);
                if (!isset($by_year[$year])) { $by_year[$year] = ['total'=>0,'image'=>0,'title'=>0,'content'=>0]; }
                $by_year[$year]['total']++;
                if (has_post_thumbnail($pid)) { $with_image++; $by_year[$year]['image']++; }
                $t = get_the_title($pid);
                if ($t) { $with_title++; $by_year[$year]['title']++; }
                $c = get_post_field('post_content', $pid);
                if (trim(strip_tags($c)) !== '') { $with_content++; $by_year[$year]['content']++; }
            }
        }

        echo '<h3>Gesamt</h3>';
        echo '<ul>';
        echo '<li>Missionen insgesamt: ' . esc_html($total) . '</li>';
        echo '<li>Mit Bild: ' . esc_html($with_image) . '</li>';
        echo '<li>Mit Titel: ' . esc_html($with_title) . '</li>';
        echo '<li>Mit Beschreibung: ' . esc_html($with_content) . '</li>';
        echo '</ul>';

        if (!empty($by_year)) {
            echo '<h3>Pro Jahr</h3>';
            echo '<table class="widefat striped" style="max-width:700px">';
            echo '<thead><tr><th>Jahr</th><th>Gesamt</th><th>Bild</th><th>Titel</th><th>Beschreibung</th></tr></thead><tbody>';
            krsort($by_year);
            foreach ($by_year as $year=>$stats) {
                echo '<tr>';
                echo '<td>' . esc_html($year) . '</td>';
                echo '<td>' . esc_html($stats['total']) . '</td>';
                echo '<td>' . esc_html($stats['image']) . '</td>';
                echo '<td>' . esc_html($stats['title']) . '</td>';
                echo '<td>' . esc_html($stats['content']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

    // Debug Einzel-Importe entfernt – Cron übernimmt periodischen Import.

        //get the content of FFAMI_FILE_MAIN and display it
 /*        $content = file_get_contents(FFAMI_FILE_MAIN);
        if ($content !== false) {
            echo '<h2>Inhalt von FFAMI_FILE_MAIN</h2>';
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                echo '<h2>Parsed JSON</h2>';




                echo '<h2>Parsed JSON und Inhalte der URLs</h2>';
                if (isset($data['years']) && is_array($data['years'])) {
                    foreach ($data['years'] as $year => $info) {
                        echo '<h3>Jahr: ' . htmlspecialchars($year) . '</h3>';
                        if (isset($info['url'])) {
                            $url = $info['url'];
                            // Hier könnte ein absoluter Pfad oder URL aufgebaut werden, falls benötigt.
                            $urlContent = file_get_contents(FFAMI_DATA_ROOT.$url);
                            if ($urlContent !== false) {



                                $urlData = json_decode($urlContent, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    echo '<pre>' . print_r($urlData, true) . '</pre>';
                                } else {
                                    echo '<div>Fehler beim Parsen des JSON: ' . json_last_error_msg() . '</div>';
                                }





                            } else {
                                echo '<div>Fehler beim Laden der URL: ' . htmlspecialchars($url) . '</div>';
                            }
                        } else {
                            echo '<div>Keine URL für das Jahr ' . htmlspecialchars($year) . ' vorhanden.</div>';
                        }
                    }
                } else {
                    echo '<p>Keine Jahr-Daten gefunden.</p>';
                }





            } else {
                echo '<p>Fehler beim Parsen des JSON Inhalts: ' . json_last_error_msg() . '</p>';
            }
        } else {
            echo '<p>Fehler beim Laden des Inhalts von FFAMI_FILE_MAIN.</p>';
        }
        echo '</div>'; */

    }
}
