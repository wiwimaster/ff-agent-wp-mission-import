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
        // render a simple admin page with content
        echo '<div class="wrap">';
        echo '<h1>FF Agent WP Mission Import</h1>';
        echo '<p>Willkommen im FF Agent WP Mission Import Admin Panel!</p>';
        echo '<p>Hier können Sie die Einstellungen für den Import von Einsatzdaten von FF Agent nach Wordpress vornehmen.</p>';
    
        //ask for Widget ID

        echo '<h2>Widget ID</h2>';
        echo '<p>Bitte geben Sie die Widget ID ein, um den Import zu starten.</p>';
        echo '<form method="post" action="">';
        echo '<input type="text" name="widget_id" placeholder="Widget ID" required>';
        echo '<input type="submit" name="start_import" value="Import starten">';
        echo '</form>';

        //$mission_import = new ffami_single_mission_import('2025-01-21_20-40-00', '/hpWidget/059B55E0-4FFA-4584-8D40-585DC657C7FB/mission/fc001b25-0f52-4429-952d-38b1350df10d');
        $mission_import = new ffami_single_mission_import('2025-01-19_20-38-00', '/hpWidget/059B55E0-4FFA-4584-8D40-585DC657C7FB/mission/62a855aa-a62c-4a3f-b76b-f2af5817b7f5');
        $mission_import = new ffami_single_mission_import('2025-08-12_07-18-00', '/hpWidget/059B55E0-4FFA-4584-8D40-585DC657C7FB/mission/cc4e857a-747d-454e-b3f7-8c922660c22f');

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
