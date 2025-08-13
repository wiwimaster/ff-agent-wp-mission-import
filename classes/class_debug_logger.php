<?php
class ffami_debug_logger {
    private const OPTION = 'ffami_debug_log';
    private const OPTION_VERBOSE = 'ffami_debug_verbose';
    private const MAX_ENTRIES = 400; // Ringpuffer

    public static function log(string $message, array $context = []) : void {
        $verbose = get_option(self::OPTION_VERBOSE, '0') === '1';
        if (!$verbose && !empty($context) && (stripos($message, 'error') === false)) {
            $context = []; // Kontext begrenzen
        }
        $entry = [
            'time'    => current_time('mysql'),
            'message' => $message,
            'context' => $context,
        ];
        $log = get_option(self::OPTION, []);
        if (!is_array($log)) { $log = []; }
        $log[] = $entry;
        $excess = count($log) - self::MAX_ENTRIES;
        if ($excess > 0) {
            $log = array_slice($log, $excess);
        }
        update_option(self::OPTION, $log, false);
    }

    public static function get_log() : array {
        $log = get_option(self::OPTION, []);
        return is_array($log) ? array_reverse($log) : [];
    }

    public static function clear() : void {
        update_option(self::OPTION, [], false);
    }

    public static function set_verbose(bool $on) : void {
        update_option(self::OPTION_VERBOSE, $on ? '1' : '0', false);
    }

    public static function is_verbose() : bool {
        return get_option(self::OPTION_VERBOSE, '0') === '1';
    }
}
