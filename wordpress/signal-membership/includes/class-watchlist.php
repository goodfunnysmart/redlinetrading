<?php
if (!defined('ABSPATH')) {
    exit;
}

class SIG_Watchlist {
    public static function init() {}

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'user_watchlist';
    }

    public static function activate() {
        global $wpdb;
        $table = self::table();
        $collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            user_id BIGINT UNSIGNED NOT NULL,
            symbol VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (user_id, symbol),
            KEY symbol (symbol)
        ) {$collate};");
    }

    public static function get($user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        $table = self::table();
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT symbol FROM {$table} WHERE user_id = %d ORDER BY symbol ASC",
            $user_id
        ));
        return $rows ? $rows : array();
    }

    public static function add($user_id, $symbol) {
        global $wpdb;
        $symbol = SIG_Access::sanitize_symbol($symbol);
        if ($symbol === '') {
            return new WP_Error('sig_symbol', 'Invalid symbol.');
        }
        $user_id = (int) $user_id;
        $is_core = class_exists('SIG_Universe') && SIG_Universe::is_core($symbol);
        if (!$is_core) {
            $existing = self::get($user_id);
            $extras = class_exists('SIG_Universe') ? SIG_Universe::extras_in($existing) : array();
            if (!in_array($symbol, $existing, true) && count($extras) >= SIG_Universe::EXTRA_CAP) {
                return new WP_Error('sig_extra_cap', 'You can add up to 30 extra tickers beyond the core 280.');
            }
            $known = SIG_Store::has_bars($symbol) || SIG_DB::symbol_exists($symbol) || SIG_Cache::symbol_exists($symbol);
            if (!$known) {
                if (!SIG_EODHD::has_key()) {
                    return new WP_Error('sig_unknown', 'Symbol is not in the core 280 and cannot be checked yet.');
                }
                if (!SIG_EODHD::symbol_exists($symbol)) {
                    return new WP_Error('sig_unknown', 'Symbol was not found at EODHD.');
                }
            }
            if (class_exists('SIG_Writer')) {
                SIG_Writer::enqueue_extra($symbol);
            }
        }
        $ok = $wpdb->replace(
            self::table(),
            array(
                'user_id'    => (int) $user_id,
                'symbol'     => $symbol,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s')
        );
        if ($ok === false) {
            return new WP_Error('sig_db', 'Could not save watchlist.');
        }
        return true;
    }

    public static function remove($user_id, $symbol) {
        global $wpdb;
        $symbol = SIG_Access::sanitize_symbol($symbol);
        $wpdb->delete(
            self::table(),
            array('user_id' => (int) $user_id, 'symbol' => $symbol),
            array('%d', '%s')
        );
        return true;
    }

    /** user_id => symbols[] for fan-out */
    public static function all_grouped() {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results("SELECT user_id, symbol FROM {$table} ORDER BY user_id, symbol", ARRAY_A);
        $out = array();
        if (!$rows) {
            return $out;
        }
        foreach ($rows as $r) {
            $uid = (int) $r['user_id'];
            if (!isset($out[$uid])) {
                $out[$uid] = array();
            }
            $out[$uid][] = $r['symbol'];
        }
        return $out;
    }
}
