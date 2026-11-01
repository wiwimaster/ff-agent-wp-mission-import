<?php

require_once __DIR__ . '/MpfeuerTestCase.php';

/** REST-Endpunkt: Auth, Content-Type, JSON, Antwort, Deduplizierung, Logging. */
class EndpointTest extends MpfeuerTestCase {

    private ffami_mpfeuer_endpoint $endpoint;

    protected function setUp(): void {
        parent::setUp();
        $this->endpoint = new ffami_mpfeuer_endpoint();
        $this->configure();
    }

    private function body(array $changes = []): string {
        return json_encode($this->export($changes), JSON_UNESCAPED_UNICODE);
    }

    public function test_route_und_rewrite_regel_registriert(): void {
        $this->endpoint->register_routes();
        $this->endpoint->add_rewrite_rule();
        $route = $GLOBALS['wp_stub']['routes']['ffami/v1/mpfeuer/rest/(?P<kennung>[A-Za-z0-9]{6})/Einsatz'] ?? null;
        $this->assertNotNull($route);
        $this->assertSame('POST', $route['methods']);
        $this->assertSame('index.php?rest_route=/ffami/v1/mpfeuer/rest/$matches[1]/Einsatz', $GLOBALS['wp_stub']['rewrite']['^mpfeuer/rest/([A-Za-z0-9]{6})/Einsatz/?$']);
    }

    public function test_ohne_zugangsdaten_401_mit_www_authenticate(): void {
        $result = $this->endpoint->check_permission($this->request($this->body(), 'application/json', null));
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(401, $result->get_error_data()['status']);

        $response = $this->endpoint->add_auth_header(new WP_REST_Response(['code' => 'x'], 401), null, $this->request(''));
        $this->assertSame('Basic realm="mpfeuer"', $response->headers['WWW-Authenticate']);
    }

    public function test_www_authenticate_nur_fuer_eigene_route(): void {
        $other = new WP_REST_Request('POST', '/wp/v2/posts');
        $response = $this->endpoint->add_auth_header(new WP_REST_Response([], 401), null, $other);
        $this->assertArrayNotHasKey('WWW-Authenticate', $response->headers);
    }

    public function test_falsches_passwort_401(): void {
        $result = $this->endpoint->check_permission($this->request($this->body(), 'application/json', 'ffagent:XyzFalsch99'));
        $this->assertSame(401, $result->get_error_data()['status']);
        $result = $this->endpoint->check_permission($this->request($this->body(), 'application/json', 'fremd:test-geheim'));
        $this->assertSame(401, $result->get_error_data()['status']);
    }

    public function test_richtige_zugangsdaten_auch_ueber_php_auth(): void {
        $this->assertTrue($this->endpoint->check_permission($this->request($this->body())));
        $_SERVER['PHP_AUTH_USER'] = 'ffagent';
        $_SERVER['PHP_AUTH_PW'] = 'test-geheim';
        $this->assertTrue($this->endpoint->check_permission($this->request($this->body(), 'application/json', null)));
    }

    public function test_ohne_hinterlegte_zugangsdaten_wird_alles_abgewiesen(): void {
        update_option(ffami_mpfeuer_settings::OPTION, []);
        $result = $this->endpoint->check_permission($this->request($this->body(), 'application/json', ':'));
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(401, $result->get_error_data()['status']);
    }

    public function test_passwort_nur_als_hash_gespeichert(): void {
        $this->assertStringNotContainsString('test-geheim', serialize($GLOBALS['wp_stub']['options']));
    }

    public function test_fremde_kennung_404_wenn_kennung_gesetzt(): void {
        ffami_mpfeuer_settings::update(['kennung' => '654321']);
        $result = $this->endpoint->check_permission($this->request($this->body()));
        $this->assertSame(404, $result->get_error_data()['status']);
    }

    public function test_sperre_nach_zu_vielen_fehlversuchen(): void {
        for ($i = 0; $i < 10; $i++) {
            $this->endpoint->check_permission($this->request('', 'application/json', 'ffagent:XyzFalsch99'));
        }
        $result = $this->endpoint->check_permission($this->request($this->body()));
        $this->assertSame(429, $result->get_error_data()['status']);
    }

    public function test_fehlversuch_zaehlt_je_request_nur_einmal(): void {
        // WordPress ruft permission_callback für den Allow-Header (rest_send_allow_header) ein zweites Mal auf
        $request = $this->request('', 'application/json', 'ffagent:XyzFalsch99');
        $first = $this->endpoint->check_permission($request);
        $second = $this->endpoint->check_permission($request);
        $this->assertSame($first, $second);
        $this->assertCount(1, ffami_mpfeuer_endpoint::get_events());
        $this->assertSame(1, get_transient('ffami_mpf_fail_' . md5('192.0.2.1')));
    }

    public function test_sperre_greift_erst_nach_zehn_requests_mit_doppelaufruf(): void {
        for ($i = 0; $i < 9; $i++) {
            $request = $this->request('', 'application/json', 'ffagent:XyzFalsch99');
            $this->endpoint->check_permission($request);
            $this->endpoint->check_permission($request);
        }
        $this->assertTrue($this->endpoint->check_permission($this->request($this->body())));
    }

    public function test_sperre_gilt_je_absender_hinter_proxy(): void {
        $_SERVER['REMOTE_ADDR'] = '172.18.0.4'; // Caddy im Docker-Netz
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.66';
        for ($i = 0; $i < 10; $i++) {
            $this->endpoint->check_permission($this->request('', 'application/json', 'ffagent:XyzFalsch99'));
        }
        $this->assertSame(429, $this->endpoint->check_permission($this->request($this->body()))->get_error_data()['status']);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '149.233.2.43'; // FF-Agent bleibt durchgelassen
        $this->assertTrue($this->endpoint->check_permission($this->request($this->body())));
        $this->assertSame('203.0.113.66', ffami_mpfeuer_endpoint::get_events()[0]['ip']); // Log zeigt den Absender, nicht Caddy
    }

    public function test_client_ip_ohne_vertrauten_proxy_bleibt_remote_addr(): void {
        $this->assertSame('203.0.113.5', ffami_mpfeuer_endpoint::client_ip(['REMOTE_ADDR' => '203.0.113.5']));
        // Header eines direkten Absenders sind frei erfunden und zählen nicht
        $this->assertSame('203.0.113.5', ffami_mpfeuer_endpoint::client_ip([
            'REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.2',
        ]));
    }

    public function test_client_ip_hinter_vertrautem_proxy_aus_x_forwarded_for(): void {
        $this->assertSame('149.233.2.43', ffami_mpfeuer_endpoint::client_ip(['REMOTE_ADDR' => '172.18.0.4', 'HTTP_X_FORWARDED_FOR' => '149.233.2.43']));
        $this->assertSame('2001:db8::7', ffami_mpfeuer_endpoint::client_ip(['REMOTE_ADDR' => '::1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::7']));
        // ohne Header bleibt die Proxy-Adresse
        $this->assertSame('172.21.0.1', ffami_mpfeuer_endpoint::client_ip(['REMOTE_ADDR' => '172.21.0.1']));
    }

    public function test_client_ip_nimmt_letzten_nicht_vertrauten_eintrag_von_rechts(): void {
        // links gefälscht, dann echter Absender, dann interne Proxies
        $this->assertSame('203.0.113.9', ffami_mpfeuer_endpoint::client_ip([
            'REMOTE_ADDR' => '172.18.0.4', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.9 , 10.0.0.3,192.168.1.2',
        ]));
        // Müll in der Kette wird übersprungen
        $this->assertSame('203.0.113.9', ffami_mpfeuer_endpoint::client_ip([
            'REMOTE_ADDR' => '172.18.0.4', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, unbekannt',
        ]));
        // ganze Kette vertraut: linkester Eintrag
        $this->assertSame('10.0.0.8', ffami_mpfeuer_endpoint::client_ip(['REMOTE_ADDR' => '172.18.0.4', 'HTTP_X_FORWARDED_FOR' => '10.0.0.8, 10.0.0.3']));
    }

    public function test_cf_connecting_ip_nur_wenn_die_kette_vertraut_ist(): void {
        // Cloudflare-Edge nicht vertraut (Standard): Edge-Adresse ist der Absender, CF-Header zählt nicht
        $this->assertSame('172.70.47.94', ffami_mpfeuer_endpoint::client_ip([
            'REMOTE_ADDR' => '172.18.0.4', 'HTTP_X_FORWARDED_FOR' => '172.70.47.94', 'HTTP_CF_CONNECTING_IP' => '198.51.100.2',
        ]));
        // alles bis zur Kante vertraut: CF-Connecting-IP
        $this->assertSame('198.51.100.2', ffami_mpfeuer_endpoint::client_ip([
            'REMOTE_ADDR' => '172.18.0.4', 'HTTP_CF_CONNECTING_IP' => '198.51.100.2',
        ]));
        $this->assertSame('172.18.0.4', ffami_mpfeuer_endpoint::client_ip([
            'REMOTE_ADDR' => '172.18.0.4', 'HTTP_CF_CONNECTING_IP' => 'kaputt',
        ]));
    }

    public function test_ip_bereiche(): void {
        $this->assertTrue(ffami_mpfeuer_endpoint::ip_in_ranges('172.31.255.1', ['172.16.0.0/12']));
        $this->assertFalse(ffami_mpfeuer_endpoint::ip_in_ranges('172.32.0.1', ['172.16.0.0/12']));
        $this->assertFalse(ffami_mpfeuer_endpoint::ip_in_ranges('172.70.47.94', ffami_mpfeuer_endpoint::trusted_proxies()));
        $this->assertTrue(ffami_mpfeuer_endpoint::ip_in_ranges('fd12::1', ['fc00::/7']));
        $this->assertFalse(ffami_mpfeuer_endpoint::ip_in_ranges('10.0.0.1', ['fc00::/7']));
        $this->assertTrue(ffami_mpfeuer_endpoint::ip_in_ranges('192.0.2.7', ['192.0.2.7']));
        $this->assertFalse(ffami_mpfeuer_endpoint::ip_in_ranges('', ['0.0.0.0/0']));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function test_vertraute_proxies_per_konstante(): void {
        define('FFAMI_TRUSTED_PROXIES', '172.18.0.0/16, 173.245.48.0/20 172.64.0.0/13');
        $this->assertSame(['172.18.0.0/16', '173.245.48.0/20', '172.64.0.0/13'], ffami_mpfeuer_endpoint::trusted_proxies());
        // Cloudflare-Edge vertraut → CF-Connecting-IP gilt
        $this->assertSame('198.51.100.2', ffami_mpfeuer_endpoint::client_ip([
            'REMOTE_ADDR' => '172.18.0.4', 'HTTP_X_FORWARDED_FOR' => '172.70.47.94', 'HTTP_CF_CONNECTING_IP' => '198.51.100.2',
        ]));
        // 10.x ist jetzt nicht mehr vertraut
        $this->assertSame('10.0.0.3', ffami_mpfeuer_endpoint::client_ip(['REMOTE_ADDR' => '10.0.0.3', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']));
    }

    public function test_falscher_content_type_415(): void {
        $this->assertSame(415, $this->endpoint->handle($this->request($this->body(), 'text/plain'))->get_status());
    }

    public function test_ungueltiges_json_400(): void {
        $this->assertSame(400, $this->endpoint->handle($this->request('{"Einsatz": '))->get_status());
        $this->assertSame(400, $this->endpoint->handle($this->request('{"_version":"2016.4"}'))->get_status());
    }

    public function test_erfolg_200_leeres_objekt_und_queue_eintrag(): void {
        $response = $this->endpoint->handle($this->request($this->body(), 'application/json; charset=UTF-8'));
        $this->assertSame(200, $response->get_status());
        $this->assertSame('{}', json_encode($response->get_data()));
        $queue_id = ffami_mpfeuer_queue::find_by_export_id('00000000-0000-4000-8000-000000000001');
        $this->assertGreaterThan(0, $queue_id);
        $this->assertSame(ffami_mpfeuer_queue::STATUS_OPEN, ffami_mpfeuer_queue::get_status($queue_id));
        $this->assertSame((string) self::EXPORT_TS, get_post_meta($queue_id, 'ffami_alarm_ts', true));
        $this->assertSame('private', get_post($queue_id)->post_status);
    }

    public function test_dedupliziert_ueber_id(): void {
        $this->endpoint->handle($this->request($this->body()));
        $this->endpoint->handle($this->request($this->body(['Einsatz' => ['Allgemein' => ['Alarmstichwort' => 'INF GEÄNDERT']]])));
        $ids = get_posts(['post_type' => ffami_mpfeuer_queue::POST_TYPE, 'posts_per_page' => -1, 'fields' => 'ids']);
        $this->assertCount(1, $ids);
        $this->assertSame('INF GEÄNDERT', ffami_mpfeuer_queue::get_record($ids[0])->stichwort);
    }

    public function test_rohdaten_landen_weder_im_log_noch_in_der_ablage(): void {
        $this->endpoint->check_permission($this->request('', 'application/json', 'ffagent:XyzFalsch99'));
        $this->endpoint->handle($this->request($this->body()));
        $everything = serialize($GLOBALS['wp_stub']['options']) . serialize($GLOBALS['wp_stub']['posts']) . serialize($GLOBALS['wp_stub']['meta']);
        foreach (['Vorname', 'Nachname', 'A 9.9 999999', 'SCHLEIFE', 'Freitext', 'XyzFalsch99', 'test-geheim'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $everything, $forbidden);
        }
        $events = ffami_mpfeuer_endpoint::get_events();
        $this->assertSame(['time', 'ip', 'id', 'result'], array_keys($events[0]));
        $this->assertSame('00000000-0000-4000-8000-000000000001', $events[0]['id']);
        $this->assertSame('angenommen, offen', $events[0]['result']);
    }

    public function test_anwendungspasswoerter_fuer_eigene_route_aus(): void {
        $_SERVER['REQUEST_URI'] = '/mpfeuer/rest/123456/Einsatz';
        $this->assertFalse($this->endpoint->skip_application_passwords(true));
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
        $this->assertTrue($this->endpoint->skip_application_passwords(true));
    }
}
