<?php

class ffami_mission_utils {

    public static function mission_key(array $entry) : ?string {
        if (!empty($entry['detailUrl'])) return (string)$entry['detailUrl'];
        if (!empty($entry['url'])) return (string)$entry['url'];
        if (!empty($entry['id'])) return 'id:' . $entry['id'];
        if (isset($entry['alarmDate'])) return 'ad:' . $entry['alarmDate'];
        return null;
    }

    public static function derive_mission_id(array $entry) : string {
        if (!empty($entry['id'])) { return (string)$entry['id']; }
        if (isset($entry['alarmDate']) && is_numeric($entry['alarmDate'])) {
            $ts = (int)($entry['alarmDate'] / 1000);
            return gmdate('Y-m-d_H-i-s', $ts);
        }
        $u = $entry['detailUrl'] ?? ($entry['url'] ?? '');
        if ($u) { return substr(md5($u), 0, 16); }
        return uniqid('mission_', true);
    }

    public static function diff_missions(array $old, array $new) : array {
        $oldIndex = [];
        foreach ($old as $e) {
            $k = self::mission_key($e);
            if ($k) { $oldIndex[$k] = md5(json_encode($e)); }
        }
        $changed = [];
        foreach ($new as $e) {
            $k = self::mission_key($e);
            if (!$k) { continue; }
            $h = md5(json_encode($e));
            if (!isset($oldIndex[$k]) || $oldIndex[$k] !== $h) { $changed[] = $e; }
        }
        return $changed;
    }

    public static function removed_missions(array $old, array $new) : array {
        $oldKeys = [];
        foreach ($old as $e) { $k = self::mission_key($e); if ($k) { $oldKeys[$k] = $e; } }
        if (!$oldKeys) { return []; }
        $newKeys = [];
        foreach ($new as $e) { $k = self::mission_key($e); if ($k) { $newKeys[$k] = true; } }
        $removed = [];
        foreach ($oldKeys as $k=>$e) { if (!isset($newKeys[$k])) { $removed[] = $e; } }
        return $removed;
    }
}
