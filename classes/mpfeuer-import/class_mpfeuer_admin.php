<?php

/**
 * Abschnitt "MP-Feuer-Export" im Admin-Panel: Zugangsdaten, Toleranz,
 * Zuordnungstabellen, Warteschlange (manuell zuordnen/verwerfen), Prüfliste.
 */
class ffami_mpfeuer_admin {

    /** Öffentliche Adresse für den FF-Agent-Eintrag (ohne Pfad). */
    private function public_url(): string {
        $url = defined('FFAMI_MPFEUER_PUBLIC_URL') ? (string) FFAMI_MPFEUER_PUBLIC_URL : 'https://mpfeuer.ffw-steinebach-auing.de';
        return (string) apply_filters('ffami_mpfeuer_public_url', $url);
    }

    public function render(): void {
        if (!current_user_can('manage_options')) { return; }
        $this->handle_post();

        echo '<hr><h2>' . esc_html__('MP-Feuer-Export', 'ffami') . '</h2>';
        echo '<p>' . esc_html__('FF-Agent kann Einsätze zusätzlich im MP-Feuer-Format exportieren. Der Export ergänzt die Widget-Einsätze um Stichwort, Einsatzart, Kategorie, Beginn/Ende, Fahrzeuge, weitere Kräfte und Personalanzahl. Adresse und Besatzungsstärke werden nur intern gespeichert.', 'ffami') . '</p>';

        $this->render_ff_agent_hint();
        $this->render_settings_form();
        $this->render_status();
        $this->render_queue();
        $this->render_review_list();
    }

    private function handle_post(): void {
        if (isset($_POST['ffami_mpfeuer_save']) && check_admin_referer('ffami_mpfeuer_settings')) {
            $changes = [
                'tolerance'   => max(1, (int) ($_POST['mpf_tolerance'] ?? 10)) * 60,
                'kennung'     => preg_match('/^[A-Za-z0-9]{6}$/', (string) ($_POST['mpf_kennung'] ?? '')) ? (string) $_POST['mpf_kennung'] : '',
                'vehicle_map' => ffami_mpfeuer_settings::parse_map(wp_unslash((string) ($_POST['mpf_vehicle_map'] ?? ''))),
                'orgs'        => ffami_mpfeuer_settings::parse_map(wp_unslash((string) ($_POST['mpf_orgs'] ?? ''))),
                'types'       => ffami_mpfeuer_settings::parse_map(wp_unslash((string) ($_POST['mpf_types'] ?? ''))),
            ];
            if (!ffami_mpfeuer_settings::credentials_from_constants()) {
                $changes['user'] = sanitize_user(wp_unslash((string) ($_POST['mpf_user'] ?? '')), true);
            }
            ffami_mpfeuer_settings::update($changes);
            $pass = (string) wp_unslash($_POST['mpf_pass'] ?? '');
            if ($pass !== '' && !ffami_mpfeuer_settings::credentials_from_constants()) {
                ffami_mpfeuer_settings::set_password($pass);
            }
            echo '<div class="notice notice-success"><p>MP-Feuer-Einstellungen gespeichert.</p></div>';
        }
        if (isset($_POST['ffami_mpfeuer_retry']) && check_admin_referer('ffami_mpfeuer_queue')) {
            $stats = ffami_mpfeuer_matcher::retry();
            $parts = [];
            foreach ($stats as $k => $n) { $parts[] = $k . ': ' . (int) $n; }
            echo '<div class="notice notice-success"><p>Zuordnung nachgeholt. ' . esc_html($parts ? implode(', ', $parts) : 'Keine offenen Einträge.') . '</p></div>';
        }
        if (isset($_POST['ffami_mpfeuer_assign']) && check_admin_referer('ffami_mpfeuer_queue')) {
            $queue_id = (int) ($_POST['queue_id'] ?? 0);
            $mission_id = (int) ($_POST['mission_id'] ?? 0);
            if ($queue_id && $mission_id && ffami_mpfeuer_matcher::assign_manually($queue_id, $mission_id)) {
                echo '<div class="notice notice-success"><p>Eintrag dem Einsatz #' . esc_html((string) $mission_id) . ' zugeordnet.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Zuordnung fehlgeschlagen: Einsatz nicht gefunden.</p></div>';
            }
        }
        if (isset($_POST['ffami_mpfeuer_discard']) && check_admin_referer('ffami_mpfeuer_queue')) {
            $queue_id = (int) ($_POST['queue_id'] ?? 0);
            if ($queue_id) {
                ffami_mpfeuer_matcher::discard($queue_id);
                echo '<div class="notice notice-success"><p>Eintrag verworfen.</p></div>';
            }
        }
    }

    private function render_ff_agent_hint(): void {
        $user = ffami_mpfeuer_settings::user();
        echo '<h3>' . esc_html__('Eintrag in FF-Agent', 'ffami') . '</h3>';
        echo '<p>' . esc_html__('FF-Agent → Admin → Externe Dienste → MP-Feuer API:', 'ffami') . '</p>';
        echo '<table class="widefat striped" style="max-width:750px"><tbody>';
        echo '<tr><th>URL (ohne Pfad)</th><td><code>' . esc_html($this->public_url()) . '</code></td></tr>';
        echo '<tr><th>Benutzername</th><td>' . ($user !== '' ? '<code>' . esc_html($user) . '</code>' : '<em>noch nicht gesetzt</em>') . '</td></tr>';
        echo '<tr><th>Passwort</th><td><em>wie hier bzw. in wp-config.php hinterlegt</em></td></tr>';
        echo '<tr><th>Abteilung</th><td><code>001</code></td></tr>';
        echo '</tbody></table>';
        echo '<p class="description">FF-Agent hängt <code>/mpfeuer/rest/&lt;Kennung&gt;/Einsatz</code> an. Direkt erreichbar unter <code>'
            . esc_html(home_url('/mpfeuer/rest/<Kennung>/Einsatz')) . '</code> bzw. <code>'
            . esc_html(rest_url(ffami_mpfeuer_endpoint::REST_NAMESPACE . '/mpfeuer/rest/<Kennung>/Einsatz')) . '</code>.</p>';
    }

    private function render_settings_form(): void {
        $s = ffami_mpfeuer_settings::all();
        $fromConst = ffami_mpfeuer_settings::credentials_from_constants();
        echo '<h3>' . esc_html__('Einstellungen', 'ffami') . '</h3>';
        echo '<form method="post">';
        wp_nonce_field('ffami_mpfeuer_settings');
        echo '<table class="form-table"><tbody>';
        if ($fromConst) {
            echo '<tr><th>Zugangsdaten</th><td>Aus <code>wp-config.php</code> (<code>FFAMI_MPFEUER_USER</code>/<code>FFAMI_MPFEUER_PASS</code>).</td></tr>';
        } else {
            echo '<tr><th><label for="mpf_user">Benutzername</label></th><td><input type="text" id="mpf_user" name="mpf_user" value="' . esc_attr((string) ($s['user'] ?? '')) . '" autocomplete="off" /></td></tr>';
            echo '<tr><th><label for="mpf_pass">Passwort</label></th><td><input type="password" id="mpf_pass" name="mpf_pass" value="" autocomplete="new-password" /> '
                . '<span class="description">' . (empty($s['pass_hash']) ? 'noch nicht gesetzt' : 'gesetzt (leer lassen = unverändert)') . '. Wird nur als Hash gespeichert; besser <code>FFAMI_MPFEUER_USER</code>/<code>FFAMI_MPFEUER_PASS</code> in <code>wp-config.php</code>.</span></td></tr>';
        }
        echo '<tr><th><label for="mpf_kennung">Kennung</label></th><td><input type="text" id="mpf_kennung" name="mpf_kennung" pattern="[A-Za-z0-9]{6}" value="' . esc_attr(ffami_mpfeuer_settings::kennung()) . '" /> <span class="description">sechsstellig aus dem Pfad (Buchstaben und Ziffern); leer = jede annehmen</span></td></tr>';
        echo '<tr><th><label for="mpf_tolerance">Toleranz (Minuten)</label></th><td><input type="number" min="1" max="120" id="mpf_tolerance" name="mpf_tolerance" value="' . esc_attr((string) intdiv(ffami_mpfeuer_settings::tolerance(), 60)) . '" /> <span class="description">Abstand Alarmzeit Widget ↔ Export</span></td></tr>';
        echo '<tr><th><label for="mpf_vehicle_map">Fahrzeug-Zuordnung</label></th><td><textarea id="mpf_vehicle_map" name="mpf_vehicle_map" rows="6" cols="60" placeholder="Steinebach 11/1 = MZF">' . esc_textarea(ffami_mpfeuer_settings::format_map((array) ($s['vehicle_map'] ?? []))) . '</textarea><br><span class="description">Je Zeile <code>FMS_ID = eigener Name</code></span></td></tr>';
        echo '<tr><th><label for="mpf_orgs">Organisationen</label></th><td><textarea id="mpf_orgs" name="mpf_orgs" rows="6" cols="60">' . esc_textarea(ffami_mpfeuer_settings::format_map(ffami_mpfeuer_settings::orgs())) . '</textarea><br><span class="description">Je Zeile <code>Funkrufname-Präfix = Kürzel</code>; <code>FW</code> = Feuerwehr (wird mit Namen gezeigt)</span></td></tr>';
        echo '<tr><th><label for="mpf_types">Fahrzeugtypen</label></th><td><textarea id="mpf_types" name="mpf_types" rows="6" cols="60">' . esc_textarea(ffami_mpfeuer_settings::format_map(ffami_mpfeuer_settings::types())) . '</textarea><br><span class="description">Je Zeile <code>Kennzahl = Typ</code></span></td></tr>';
        echo '</tbody></table>';
        echo '<input type="submit" class="button button-primary" name="ffami_mpfeuer_save" value="Speichern" />';
        echo '</form>';
    }

    private function render_status(): void {
        echo '<h3>' . esc_html__('Status', 'ffami') . '</h3>';
        if (!ffami_mpfeuer_settings::has_credentials()) {
            echo '<p><strong>Keine Zugangsdaten gesetzt – der Endpunkt weist alle Exporte ab.</strong></p>';
        }
        echo '<p>Offen: ' . esc_html((string) ffami_mpfeuer_queue::count(ffami_mpfeuer_queue::STATUS_OPEN))
            . ' · Zugeordnet: ' . esc_html((string) ffami_mpfeuer_queue::count(ffami_mpfeuer_queue::STATUS_MATCHED))
            . ' · Verworfen: ' . esc_html((string) ffami_mpfeuer_queue::count(ffami_mpfeuer_queue::STATUS_DISCARDED)) . '</p>';
        $events = array_slice(ffami_mpfeuer_endpoint::get_events(), 0, 10);
        if ($events) {
            echo '<table class="widefat striped" style="max-width:750px"><thead><tr><th>Zeit</th><th>Export-ID</th><th>Ergebnis</th></tr></thead><tbody>';
            foreach ($events as $ev) {
                echo '<tr><td>' . esc_html(wp_date('Y-m-d H:i:s', (int) $ev['time'])) . '</td><td><code>' . esc_html((string) $ev['id']) . '</code></td><td>' . esc_html((string) $ev['result']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    private function render_queue(): void {
        echo '<h3>' . esc_html__('Warteschlange', 'ffami') . '</h3>';
        echo '<form method="post" style="margin-bottom:10px;">';
        wp_nonce_field('ffami_mpfeuer_queue');
        echo '<input type="submit" class="button" name="ffami_mpfeuer_retry" value="Zuordnung nachholen" />';
        echo '</form>';

        $ids = array_merge(
            ffami_mpfeuer_queue::list_ids(ffami_mpfeuer_queue::STATUS_OPEN, 50),
            ffami_mpfeuer_queue::list_ids(ffami_mpfeuer_queue::STATUS_MATCHED, 20)
        );
        if (!$ids) {
            echo '<p><em>Keine Einträge.</em></p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Alarm</th><th>Stichwort</th><th>Kategorie</th><th>Fahrzeuge</th><th>Status</th><th>Einsatz</th><th>Aktion</th></tr></thead><tbody>';
        foreach ($ids as $id) {
            $r = ffami_mpfeuer_queue::get_record($id);
            if (!$r) { continue; }
            $status = ffami_mpfeuer_queue::get_status($id);
            $matched = (int) get_post_meta($id, 'ffami_matched_post', true);
            $candidates = ffami_mpfeuer_queue::get_candidates($id);
            echo '<tr>';
            echo '<td>' . esc_html($r->start_ts ? wp_date('Y-m-d H:i', $r->start_ts) : '–') . '</td>';
            echo '<td>' . esc_html($r->stichwort) . '</td>';
            echo '<td>' . esc_html($r->kategorie) . '</td>';
            echo '<td>' . esc_html(implode(', ', $r->vehicle_names())) . '</td>';
            echo '<td>' . esc_html($status . ($candidates ? ' (mehrdeutig)' : '')) . '</td>';
            echo '<td>' . ($matched ? $this->mission_link($matched) : '–') . '</td>';
            echo '<td><form method="post" style="display:flex; gap:4px; flex-wrap:wrap;">';
            wp_nonce_field('ffami_mpfeuer_queue');
            echo '<input type="hidden" name="queue_id" value="' . esc_attr((string) $id) . '" />';
            if ($candidates) {
                echo '<select name="mission_id">';
                foreach ($candidates as $cid) {
                    echo '<option value="' . esc_attr((string) $cid) . '">#' . esc_html($cid . ' ' . get_the_title($cid) . ' (' . get_post_field('post_date', $cid) . ')') . '</option>';
                }
                echo '</select>';
            } else {
                echo '<input type="number" name="mission_id" placeholder="Einsatz-ID" style="width:110px" />';
            }
            echo '<input type="submit" class="button" name="ffami_mpfeuer_assign" value="Zuordnen" />';
            echo '<input type="submit" class="button" name="ffami_mpfeuer_discard" value="Verwerfen" onclick="return confirm(\'Eintrag verwerfen?\');" />';
            echo '</form></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /** Unbekannte Fahrzeuge (fehlen in der Zuordnung) und Einheiten (Typ/Organisation unbekannt). */
    private function render_review_list(): void {
        $vehicles = []; $units = [];
        $ids = array_merge(
            ffami_mpfeuer_queue::list_ids(ffami_mpfeuer_queue::STATUS_OPEN, 100),
            ffami_mpfeuer_queue::list_ids(ffami_mpfeuer_queue::STATUS_MATCHED, 100)
        );
        $map = ffami_mpfeuer_settings::vehicle_map();
        foreach ($ids as $id) {
            $r = ffami_mpfeuer_queue::get_record($id);
            if (!$r) { continue; }
            foreach ($r->vehicles as $v) {
                if ($v['fms_id'] !== '' && !isset($map[$v['fms_id']])) { $vehicles[$v['fms_id']] = ($vehicles[$v['fms_id']] ?? 0) + 1; }
            }
            foreach ($r->unknown_entries()['units'] as $u) { $units[$u] = ($units[$u] ?? 0) + 1; }
        }
        echo '<h3>' . esc_html__('Prüfliste', 'ffami') . '</h3>';
        if (!$vehicles && !$units) {
            echo '<p><em>Alles zugeordnet.</em></p>';
            return;
        }
        if ($vehicles) {
            echo '<p><strong>Fahrzeuge ohne Zuordnung</strong> (in die Fahrzeug-Zuordnung eintragen):</p><ul>';
            foreach ($vehicles as $fms => $n) { echo '<li><code>' . esc_html($fms) . '</code> (' . (int) $n . '×)</li>'; }
            echo '</ul>';
        }
        if ($units) {
            echo '<p><strong>Weitere Kräfte ohne Typ oder Organisation</strong> (werden neutral als „Weitere Einheit“/„Fahrzeug“ gezeigt):</p><ul>';
            foreach ($units as $u => $n) { echo '<li>' . esc_html($u) . ' (' . (int) $n . '×)</li>'; }
            echo '</ul>';
        }
    }

    private function mission_link(int $mission_id): string {
        $url = get_edit_post_link($mission_id);
        $title = '#' . $mission_id . ' ' . get_the_title($mission_id);
        return $url ? '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>' : esc_html($title);
    }
}
