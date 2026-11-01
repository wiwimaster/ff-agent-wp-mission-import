<?php
/**
 * Schlanke WordPress-Stubs für Unit-Tests ohne WordPress-Installation.
 *
 * Bildet nur ab, was das Plugin nutzt: Hooks, Optionen/Transients, Posts mit Meta
 * (inkl. meta_query =, IN, BETWEEN, EXISTS, NOT EXISTS), REST-Request/-Response.
 * Zustand liegt in $GLOBALS['wp_stub'] und wird je Test über wp_stub_reset() geleert.
 */

date_default_timezone_set('UTC'); // wie WordPress
ini_set('error_log', '/dev/null'); // erwartete Fehlermeldungen des Importers nicht ausgeben

define('ABSPATH', __DIR__ . '/');
define('FFAMI_DATA_ROOT', 'https://example.invalid');
define('FFAMI_TEXTDOMAIN', 'ffami');

function wp_stub_reset(): void {
    $GLOBALS['wp_stub'] = [
        'actions' => [], 'options' => [], 'transients' => [], 'posts' => [], 'meta' => [],
        'next_id' => 1, 'routes' => [], 'rewrite' => [], 'remote' => [], 'insert_fail' => false,
    ];
    $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
    unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP']);
}
wp_stub_reset();

// ---------------------------------------------------------------- Hooks
function add_action($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['wp_stub']['actions'][$hook][$prio][] = [$cb, $args]; return true; }
function add_filter($hook, $cb, $prio = 10, $args = 1) { return add_action($hook, $cb, $prio, $args); }
function do_action($hook, ...$a) {
    $list = $GLOBALS['wp_stub']['actions'][$hook] ?? [];
    ksort($list);
    foreach ($list as $cbs) { foreach ($cbs as [$cb, $n]) { call_user_func_array($cb, array_slice($a, 0, $n)); } }
}
function apply_filters($hook, $value, ...$a) {
    $list = $GLOBALS['wp_stub']['actions'][$hook] ?? [];
    ksort($list);
    foreach ($list as $cbs) { foreach ($cbs as [$cb, $n]) { $value = call_user_func_array($cb, array_slice(array_merge([$value], $a), 0, max(1, $n))); } }
    return $value;
}

// ---------------------------------------------------------------- Optionen
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['wp_stub']['options']) ? $GLOBALS['wp_stub']['options'][$k] : $d; }
function update_option($k, $v, $autoload = null) { $GLOBALS['wp_stub']['options'][$k] = $v; return true; }
function add_option($k, $v = '') { if (!array_key_exists($k, $GLOBALS['wp_stub']['options'])) { $GLOBALS['wp_stub']['options'][$k] = $v; } return true; }
function delete_option($k) { unset($GLOBALS['wp_stub']['options'][$k]); return true; }
function get_transient($k) { return $GLOBALS['wp_stub']['transients'][$k] ?? false; }
function set_transient($k, $v, $exp = 0) { $GLOBALS['wp_stub']['transients'][$k] = $v; return true; }

// ---------------------------------------------------------------- Hilfsfunktionen
function __($t, $d = null) { return $t; }
function esc_html__($t, $d = null) { return $t; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function is_admin() { return false; }
function wp_slash($v) { return is_string($v) ? addslashes($v) : $v; }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function wp_hash_password($p) { return password_hash($p, PASSWORD_DEFAULT); }
function wp_check_password($p, $h) { return password_verify($p, $h); }
function wp_kses($c, $allowed) { return $c; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_user($s, $strict = false) { return trim((string) $s); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $s)), '-'); }
function wp_parse_args($a, $d) { return array_merge($d, (array) $a); }
function wp_timezone() { return new DateTimeZone('Europe/Berlin'); }
function wp_date($format, $ts = null) { return (new DateTimeImmutable('@' . ($ts ?? time())))->setTimezone(wp_timezone())->format($format); }
function current_time($type) { return wp_date('Y-m-d H:i:s'); }
function get_gmt_from_date($local) { return (new DateTimeImmutable($local, wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
function home_url($p = '') { return 'https://example.invalid' . $p; }
function rest_url($p = '') { return 'https://example.invalid/wp-json/' . $p; }
function has_post_thumbnail($id) { return false; }
function register_post_type($t, $args) { $GLOBALS['wp_stub']['post_types'][$t] = $args; }
function register_rest_route($ns, $route, $args) { $GLOBALS['wp_stub']['routes'][$ns . $route] = $args; }
function add_rewrite_rule($re, $q, $pos) { $GLOBALS['wp_stub']['rewrite'][$re] = $q; }
function flush_rewrite_rules($hard = true) {}
function wp_next_scheduled($h) { return false; }
function wp_clear_scheduled_hook($h) {}
function is_wp_error($v) { return $v instanceof WP_Error; }

// ---------------------------------------------------------------- HTTP (Widget-Detail-JSON)
function wp_remote_get($url, $args = []) { return $GLOBALS['wp_stub']['remote'][$url] ?? new WP_Error('http', 'not stubbed'); }
function wp_remote_retrieve_response_code($r) { return $r['code'] ?? 0; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }

// ---------------------------------------------------------------- Posts & Meta
class WP_Post {
    public int $ID = 0;
    public string $post_type = 'post';
    public string $post_status = 'publish';
    public string $post_title = '';
    public string $post_content = '';
    public string $post_name = '';
    public string $post_date = '';
    public string $post_date_gmt = '';
}

function wp_insert_post($arr, $wp_error = false) {
    if ($GLOBALS['wp_stub']['insert_fail']) { return $wp_error ? new WP_Error('db_insert_error', 'Stub-Fehler') : 0; }
    $arr = wp_unslash($arr);
    $id = (int) ($arr['ID'] ?? 0);
    $post = $id && isset($GLOBALS['wp_stub']['posts'][$id]) ? $GLOBALS['wp_stub']['posts'][$id] : new WP_Post();
    if (!$id) { $id = $GLOBALS['wp_stub']['next_id']++; }
    $post->ID = $id;
    foreach (['post_type', 'post_status', 'post_title', 'post_name'] as $f) { if (isset($arr[$f])) { $post->$f = (string) $arr[$f]; } }
    if (isset($arr['post_content'])) { $post->post_content = wp_unslash((string) $arr['post_content']); }
    if (!empty($arr['post_date'])) {
        $post->post_date = $arr['post_date'];
        $post->post_date_gmt = get_gmt_from_date($arr['post_date']);
    } elseif ($post->post_date === '') {
        $post->post_date = current_time('mysql');
        $post->post_date_gmt = gmdate('Y-m-d H:i:s');
    }
    $GLOBALS['wp_stub']['posts'][$id] = $post;
    return $id;
}
function get_post($id) { return $GLOBALS['wp_stub']['posts'][(int) $id] ?? null; }
function get_post_field($f, $id) { $p = get_post($id); return $p ? $p->$f : ''; }
function get_the_title($id) { return get_post_field('post_title', $id); }
function get_edit_post_link($id) { return ''; }
function get_post_meta($id, $key, $single = false) {
    $has = isset($GLOBALS['wp_stub']['meta'][(int) $id]) && array_key_exists($key, $GLOBALS['wp_stub']['meta'][(int) $id]);
    if (!$has) { return $single ? '' : []; }
    $v = $GLOBALS['wp_stub']['meta'][(int) $id][$key];
    return $single ? $v : [$v];
}
function update_post_meta($id, $key, $value) {
    // WordPress speichert Skalare als String
    $GLOBALS['wp_stub']['meta'][(int) $id][$key] = is_scalar($value) ? (string) $value : $value;
    return true;
}
function delete_post_meta($id, $key) { unset($GLOBALS['wp_stub']['meta'][(int) $id][$key]); return true; }

function wp_stub_meta_matches(int $id, array $clause): bool {
    $key = $clause['key'];
    $exists = isset($GLOBALS['wp_stub']['meta'][$id]) && array_key_exists($key, $GLOBALS['wp_stub']['meta'][$id]);
    $compare = strtoupper($clause['compare'] ?? '=');
    if ($compare === 'NOT EXISTS') { return !$exists; }
    if (!$exists) { return false; }
    if ($compare === 'EXISTS') { return true; }
    $v = $GLOBALS['wp_stub']['meta'][$id][$key];
    $numeric = strtoupper($clause['type'] ?? '') === 'NUMERIC';
    switch ($compare) {
        case '=': return (string) $v === (string) $clause['value'];
        case 'IN': return in_array((string) $v, array_map('strval', (array) $clause['value']), true);
        case 'BETWEEN':
            [$a, $b] = $clause['value'];
            return $numeric ? ((float) $v >= (float) $a && (float) $v <= (float) $b) : ($v >= $a && $v <= $b);
    }
    throw new RuntimeException('meta_query compare nicht im Stub: ' . $compare);
}

function get_posts($args = []) {
    $type = $args['post_type'] ?? 'post';
    $out = [];
    foreach ($GLOBALS['wp_stub']['posts'] as $id => $p) {
        if ($p->post_type !== $type) { continue; }
        $ok = true;
        foreach ((array) ($args['meta_query'] ?? []) as $k => $clause) {
            if ($k === 'relation') { continue; }
            if (!wp_stub_meta_matches($id, $clause)) { $ok = false; break; }
        }
        if ($ok) { $out[] = $id; }
    }
    if (strtoupper($args['order'] ?? 'DESC') === 'DESC') { rsort($out); } else { sort($out); }
    $limit = (int) ($args['posts_per_page'] ?? ($args['numberposts'] ?? 5));
    if ($limit > 0) { $out = array_slice($out, 0, $limit); }
    if (($args['fields'] ?? '') === 'ids') { return $out; }
    return array_map('get_post', $out);
}

class WP_Query {
    public array $posts = [];
    public int $found_posts = 0;
    public int $max_num_pages = 0;
    public function __construct($args = []) {
        $this->posts = get_posts($args);
        $this->found_posts = count($this->posts);
        $this->max_num_pages = $this->found_posts ? 1 : 0;
    }
    public function have_posts() { return !empty($this->posts); }
}

// ---------------------------------------------------------------- Fehler & REST
class WP_Error {
    public function __construct(public $code = '', public $message = '', public $data = []) {}
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}

class WP_REST_Request {
    private array $headers = [];
    private array $params = [];
    private string $body = '';
    private string $route = '';
    public function __construct($method = 'POST', $route = '') { $this->route = $route; }
    public function set_header($k, $v) { $this->headers[strtolower(str_replace('-', '_', $k))] = $v; }
    public function get_header($k) { return $this->headers[strtolower(str_replace('-', '_', $k))] ?? null; }
    public function set_param($k, $v) { $this->params[$k] = $v; }
    public function get_param($k) { return $this->params[$k] ?? null; }
    public function set_body($b) { $this->body = $b; }
    public function get_body() { return $this->body; }
    public function get_route() { return $this->route; }
}

class WP_REST_Response {
    public array $headers = [];
    public function __construct(public $data = null, public $status = 200) {}
    public function get_status() { return $this->status; }
    public function get_data() { return $this->data; }
    public function header($k, $v) { $this->headers[$k] = $v; }
}

require_once dirname(__DIR__) . '/classes/class_autoloader.php';
