<?php

/**
 * REST-Endpunkt für den MP-Feuer-Export aus FF-Agent.
 *
 * FF-Agent schickt POST /mpfeuer/rest/<Kennung>/Einsatz (JSON, HTTP Basic Auth)
 * und erwartet nur einen 2xx-Status. Route: /wp-json/ffami/v1/mpfeuer/rest/<Kennung>/Einsatz,
 * zusätzlich per Rewrite-Regel ohne /wp-json/ erreichbar.
 *
 * Rohdaten werden nie geloggt oder gespeichert; nur das Ereignis (Zeit, _id, Ergebnis).
 */
class ffami_mpfeuer_endpoint {

    public const REST_NAMESPACE = 'ffami/v1';
    public const ROUTE = '/mpfeuer/rest/(?P<kennung>[A-Za-z0-9]{6})/Einsatz';

    private const REWRITE_VERSION = '1';
    private const OPTION_REWRITE = 'ffami_mpfeuer_rewrite';
    private const OPTION_EVENTS = 'ffami_mpfeuer_events';
    private const MAX_EVENTS = 30;
    private const MAX_FAILS = 10;     // Fehlversuche je IP …
    private const FAIL_WINDOW = 600;  // … in 10 Minuten
    private const MAX_BODY = 1048576; // 1 MB

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('init', [$this, 'add_rewrite_rule']);
        add_filter('rest_post_dispatch', [$this, 'add_auth_header'], 10, 3);
        // Basic Auth dieser Route gehört uns, nicht den WP-Anwendungspasswörtern
        add_filter('application_password_is_api_request', [$this, 'skip_application_passwords']);
    }

    public function register_routes(): void {
        register_rest_route(self::REST_NAMESPACE, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /** /mpfeuer/rest/<Kennung>/Einsatz → REST-Route, damit FF-Agent ohne /wp-json/ ankommt. */
    public function add_rewrite_rule(): void {
        add_rewrite_rule(
            '^mpfeuer/rest/([A-Za-z0-9]{6})/Einsatz/?$',
            'index.php?rest_route=/' . self::REST_NAMESPACE . '/mpfeuer/rest/$matches[1]/Einsatz',
            'top'
        );
        if (get_option(self::OPTION_REWRITE) !== self::REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option(self::OPTION_REWRITE, self::REWRITE_VERSION, false);
        }
    }

    /** Prüft HTTP Basic Auth und optional die Kennung im Pfad. */
    public function check_permission($request) {
        $ip = $this->client_ip();
        if ($this->fail_count($ip) >= self::MAX_FAILS) {
            self::log_event('', 'gesperrt (429)');
            return new WP_Error('ffami_mpfeuer_rate_limited', 'Zu viele Fehlversuche', ['status' => 429]);
        }
        [$user, $pass] = self::read_basic_auth($request);
        if ($user === '' && $pass === '') {
            self::log_event('', 'abgewiesen (401, ohne Zugangsdaten)');
            return new WP_Error('ffami_mpfeuer_unauthorized', 'Authentifizierung erforderlich', ['status' => 401]);
        }
        if (!ffami_mpfeuer_settings::check_credentials($user, $pass)) {
            $this->add_fail($ip);
            self::log_event('', 'abgewiesen (401, falsche Zugangsdaten)');
            return new WP_Error('ffami_mpfeuer_unauthorized', 'Authentifizierung fehlgeschlagen', ['status' => 401]);
        }
        $kennung = ffami_mpfeuer_settings::kennung();
        if ($kennung !== '' && (string) $request->get_param('kennung') !== $kennung) {
            self::log_event('', 'abgewiesen (404, fremde Kennung)');
            return new WP_Error('ffami_mpfeuer_unknown', 'Unbekannte Kennung', ['status' => 404]);
        }
        return true;
    }

    public function handle($request) {
        $type = strtolower((string) $request->get_header('content_type'));
        if (strpos($type, 'application/json') !== 0) {
            self::log_event('', 'abgewiesen (415, Content-Type)');
            return new WP_REST_Response(['error' => 'Content-Type application/json erwartet'], 415);
        }
        $body = (string) $request->get_body();
        if (strlen($body) > self::MAX_BODY) {
            self::log_event('', 'abgewiesen (413, zu groß)');
            return new WP_REST_Response(['error' => 'Body zu groß'], 413);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            self::log_event('', 'abgewiesen (400, kein JSON)');
            return new WP_REST_Response(['error' => 'Ungültiges JSON'], 400);
        }
        try {
            $record = ffami_mpfeuer_record::from_export($data, ffami_mpfeuer_settings::vehicle_map(), ffami_mpfeuer_settings::orgs(), ffami_mpfeuer_settings::types());
        } catch (InvalidArgumentException $e) {
            self::log_event('', 'abgewiesen (400, ' . $e->getMessage() . ')');
            return new WP_REST_Response(['error' => 'Ungültiger Export'], 400);
        }
        unset($data, $body); // Rohdaten nicht weiter vorhalten

        try {
            [$queue_id, $is_new] = ffami_mpfeuer_queue::store($record);
        } catch (RuntimeException $e) {
            self::log_event($record->id, 'Fehler beim Speichern (500)');
            return new WP_REST_Response(['error' => 'Speichern fehlgeschlagen'], 500);
        }
        $result = ffami_mpfeuer_matcher::match($queue_id);
        self::log_event($record->id, ($is_new ? 'angenommen' : 'aktualisiert') . ', ' . $result);
        do_action('ffami_mpfeuer_received', $queue_id, $record, $result);

        // FF-Agent erwartet 200 mit leerem Objekt
        return new WP_REST_Response(new stdClass(), 200);
    }

    /** 401 mit WWW-Authenticate, damit Clients Basic Auth anbieten. */
    public function add_auth_header($response, $server, $request) {
        if ($response instanceof WP_REST_Response && $response->get_status() === 401 && self::is_own_route((string) $request->get_route())) {
            $response->header('WWW-Authenticate', 'Basic realm="mpfeuer"');
        }
        return $response;
    }

    public function skip_application_passwords($is_api_request) {
        return self::is_own_request() ? false : $is_api_request;
    }

    public static function is_own_route(string $route): bool {
        return (bool) preg_match('#^/' . preg_quote(self::REST_NAMESPACE, '#') . '/mpfeuer/rest/#', $route);
    }

    /** Zielt die laufende Anfrage auf unseren Endpunkt (über rest_route oder Pfad)? */
    private static function is_own_request(): bool {
        $route = $GLOBALS['wp']->query_vars['rest_route'] ?? ($_GET['rest_route'] ?? '');
        if (is_string($route) && $route !== '' && self::is_own_route($route)) { return true; }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        return (bool) preg_match('#/mpfeuer/rest/[A-Za-z0-9]{6}/Einsatz#', $uri);
    }

    /** Benutzer/Passwort aus Authorization-Header bzw. PHP_AUTH_* (je nach Server-API). */
    public static function read_basic_auth($request): array {
        $header = (string) $request->get_header('authorization');
        if ($header === '') {
            $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        }
        if (stripos($header, 'basic ') === 0) {
            $decoded = base64_decode(trim(substr($header, 6)), true);
            if (is_string($decoded) && strpos($decoded, ':') !== false) {
                return explode(':', $decoded, 2);
            }
            return ['', ''];
        }
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            return [(string) $_SERVER['PHP_AUTH_USER'], (string) ($_SERVER['PHP_AUTH_PW'] ?? '')];
        }
        return ['', ''];
    }

    /** Ereignis festhalten: nur Zeit, _id und Ergebnis – nie Inhalte oder Zugangsdaten. */
    public static function log_event(string $export_id, string $result): void {
        $events = get_option(self::OPTION_EVENTS, []);
        if (!is_array($events)) { $events = []; }
        $events[] = ['time' => time(), 'id' => $export_id, 'result' => $result];
        if (count($events) > self::MAX_EVENTS) { $events = array_slice($events, -self::MAX_EVENTS); }
        update_option(self::OPTION_EVENTS, $events, false);
        if (class_exists('ffami_debug_logger')) {
            ffami_debug_logger::log('MP-Feuer-Export ' . ($export_id !== '' ? $export_id . ' ' : '') . $result);
        }
    }

    public static function get_events(): array {
        $events = get_option(self::OPTION_EVENTS, []);
        return is_array($events) ? array_reverse($events) : [];
    }

    private function client_ip(): string {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private function fail_count(string $ip): int {
        return (int) get_transient('ffami_mpf_fail_' . md5($ip));
    }

    private function add_fail(string $ip): void {
        set_transient('ffami_mpf_fail_' . md5($ip), $this->fail_count($ip) + 1, self::FAIL_WINDOW);
    }
}
