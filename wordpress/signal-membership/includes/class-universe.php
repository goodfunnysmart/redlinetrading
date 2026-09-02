<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core 280 + per-member extras (max 30, not already in core).
 * Snapshot / processed / Dreamteam BUY-SELL-WATCH email stay on core 280.
 */
class SIG_Universe {
    const EXTRA_CAP = 30;
    const XJO = 'XJO.INDX';

    protected static $core = null;
    protected static $core_map = null;

    public static function core() {
        if (is_array(self::$core)) {
            return self::$core;
        }
        $opt = get_option('sig_core_symbols', array());
        if (is_array($opt) && count($opt) > 0) {
            $list = $opt;
        } else {
            $list = self::file_default();
        }
        self::$core = self::normalize_list($list);
        return self::$core;
    }

    public static function file_default() {
        require_once dirname(__FILE__) . '/symbols-core.php';
        return function_exists('sig_core_symbols') ? sig_core_symbols() : array();
    }

    public static function normalize_list($list) {
        $out = array();
        foreach ((array) $list as $raw) {
            $sym = SIG_Access::sanitize_symbol($raw);
            if ($sym !== '' && $sym !== self::XJO) {
                $out[] = $sym;
            }
        }
        return array_values(array_unique($out));
    }

    public static function sanitize_option($raw) {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $text = str_replace(array("\r\n", "\r"), "\n", (string) $raw);
            $parts = preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($parts)) {
                $parts = array();
            }
        }
        // Empty save: persist empty so core() falls back to the PHP file (do not wipe to zero).
        return self::normalize_list($parts);
    }

    public static function flush() {
        self::$core = null;
        self::$core_map = null;
    }

    public static function core_map() {
        if (is_array(self::$core_map)) {
            return self::$core_map;
        }
        self::$core_map = array_fill_keys(self::core(), true);
        return self::$core_map;
    }

    public static function is_core($symbol) {
        $symbol = SIG_Access::sanitize_symbol($symbol);
        if ($symbol === '') {
            return false;
        }
        return isset(self::core_map()[$symbol]);
    }

    public static function is_xjo($symbol) {
        return SIG_Access::sanitize_symbol($symbol) === self::XJO;
    }

    public static function extras_in($symbols) {
        $out = array();
        foreach ((array) $symbols as $raw) {
            $sym = SIG_Access::sanitize_symbol($raw);
            if ($sym !== '' && !self::is_core($sym) && $sym !== self::XJO) {
                $out[] = $sym;
            }
        }
        return array_values(array_unique($out));
    }

    public static function extras_for_user($user_id) {
        return self::extras_in(SIG_Watchlist::get((int) $user_id));
    }

    public static function all_member_extras() {
        global $wpdb;
        $table = SIG_Watchlist::table();
        $rows = $wpdb->get_col("SELECT DISTINCT symbol FROM {$table}");
        return self::extras_in($rows ? $rows : array());
    }
}
