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
        $route = $GLOBALS['wp_stub']['routes']['ffami/v1/mpfeuer/rest/(?P<kennung>[0-9]{6})/Einsatz'] ?? null;
        $this->assertNotNull($route);
        $this->assertSame('POST', $route['methods']);
        $this->assertSame('index.php?rest_route=/ffami/v1/mpfeuer/rest/$matches[1]/Einsatz', $GLOBALS['wp_stub']['rewrite']['^mpfeuer/rest/([0-9]{6})/Einsatz/?$']);
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
        $this->assertSame(['time', 'id', 'result'], array_keys($events[0]));
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
