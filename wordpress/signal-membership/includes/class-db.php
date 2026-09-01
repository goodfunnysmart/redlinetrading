<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Second $wpdb pointing at the engine `signals` database. SELECT only.
 * Credentials: wp-config constants override Settings.
 */
class SIG_DB {
    /** @var wpdb|null */
    protected static $engine = null;

    public static function init() {}

    public static function setting($key, $default = '') {
        $const = 'SIG_ENGINE_DB_' . strtoupper($key);
        if (defined($const) && constant($const) !== '') {
            return constant($const);
        }
        return get_option('sig_engine_db_' . $key, $default);
    }

    /** @return wpdb|WP_Error */
    public static function engine() {
        if (self::$engine instanceof wpdb) {
            return self::$engine;
        }
        $host = self::setting('host', 'localhost');
        $name = self::setting('name', '');
        $user = self::setting('user', '');
        $pass = self::setting('password', '');
        if ($name === '' || $user === '') {
            return new WP_Error('sig_no_engine_db', 'Engine database is not configured.');
        }
        $db = new wpdb($user, $pass, $name, $host);
        if (!empty($db->error)) {
            return new WP_Error('sig_engine_connect', 'Could not connect to engine database.');
        }
        $db->hide_errors();
        self::$engine = $db;
        return self::$engine;
    }

    public static function latest_trade_date() {
        $db = self::engine();
        if (is_wp_error($db)) {
            return SIG_Cache::latest_trade_date();
        }
        $d = $db->get_var('SELECT MAX(trade_date) FROM signals_daily');
        return $d ? $d : SIG_Cache::latest_trade_date();
    }

    public static function signals_for($symbols, $trade_date) {
        $db = self::engine();
        if (is_wp_error($db) || !$trade_date) {
            return SIG_Cache::signals_for($symbols, $trade_date);
        }
        if (!$symbols) {
            return array();
        }
        $symbols = array_values(array_unique(array_map('strval', $symbols)));
        $in = implode(',', array_fill(0, count($symbols), '%s'));
        $args = $symbols;
        array_unshift($args, $trade_date);
        $sql = $db->prepare(
            "SELECT trade_date, symbol, signal, close, ema15, ema25, ema36, ema45, ema55, ema65, note
             FROM signals_daily
             WHERE trade_date = %s AND symbol IN ($in)
             ORDER BY FIELD(signal,'buy','sell','watch','none'), symbol ASC",
            $args
        );
        $rows = $db->get_results($sql, ARRAY_A);
        return $rows ? $rows : array();
    }

    public static function bars($symbol) {
        $db = self::engine();
        if (is_wp_error($db)) {
            $rows = SIG_Cache::bars($symbol);
            return $rows ? $rows : new WP_Error('sig_empty', 'No bars for that symbol.', array('status' => 404));
        }
        $sql = $db->prepare(
            'SELECT trade_date, open, high, low, close, volume
             FROM bars WHERE symbol = %s ORDER BY trade_date ASC',
            $symbol
        );
        $rows = $db->get_results($sql, ARRAY_A);
        return $rows ? $rows : array();
    }

    public static function symbol_exists($symbol) {
        $db = self::engine();
        if (is_wp_error($db)) {
            return SIG_Cache::symbol_exists($symbol);
        }
        $n = $db->get_var($db->prepare('SELECT 1 FROM bars WHERE symbol = %s LIMIT 1', $symbol));
        return (bool) $n;
    }
}
