<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prefetched EODHD exchange-symbol-list. Members search this table only.
 * Writer/admin refresh the list. Never call EODHD from page load or REST search.
 *
 * Endpoint (writer only; never log the URL — it contains api_token):
 *   https://eodhd.com/api/exchange-symbol-list/{EXCHANGE}?api_token=&fmt=json
 * Exchanges: AU (ASX), US (composite), LSE, TO (Toronto), T (Tokyo / JP), CC (crypto).
 * Stored symbol is Code.Exchange as EODHD EOD uses (NVDA.US, BHP.AU, 7203.T).
 */
class SIG_Symbol_Catalog {
    const SCHEMA_VER = '1';
    const META = 'sig_eodhd_symbols_meta';
    const STALE_DAYS = 6;

    public static function init() {
        self::maybe_install();
        add_action('admin_post_sig_symbols_refresh', array(__CLASS__, 'handle_refresh'));
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'sig_eodhd_symbols';
    }

    public static function maybe_install() {
        if ((string) get_option('sig_eodhd_symbols_ver', '') === self::SCHEMA_VER) {
            return;
        }
        self::install();
        update_option('sig_eodhd_symbols_ver', self::SCHEMA_VER, false);
    }

    public static function install() {
        global $wpdb;
        $table = self::table();
        $collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            symbol VARCHAR(32) NOT NULL,
            code VARCHAR(24) NOT NULL,
            name VARCHAR(191) NOT NULL DEFAULT '',
            exchange VARCHAR(8) NOT NULL,
            type VARCHAR(64) NULL,
            PRIMARY KEY  (symbol),
            KEY code (code),
            KEY name (name),
            KEY exchange (exchange)
        ) {$collate};");
    }

    public static function exchanges() {
        // T = Tokyo (EODHD’s JP board). LSE not Yahoo .L.
        return array('AU', 'US', 'LSE', 'TO', 'T', 'CC');
    }

    public static function meta() {
        $m = get_option(self::META, array());
        return is_array($m) ? $m : array();
    }

    public static function count() {
        global $wpdb;
        self::maybe_install();
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table());
    }

    public static function status() {
        $m = self::meta();
        return array(
            'count'      => isset($m['count']) ? (int) $m['count'] : self::count(),
            'at'         => isset($m['at']) ? (string) $m['at'] : '',
            'exchanges'  => isset($m['exchanges']) && is_array($m['exchanges']) ? $m['exchanges'] : array(),
            'error'      => isset($m['error']) ? (string) $m['error'] : '',
        );
    }

    /**
     * LIKE search on code + name + symbol. No EODHD.
     */
    public static function search($q, $limit = 20) {
        self::maybe_install();
        $q = strtoupper(trim((string) $q));
        if (strlen($q) < 2 || strlen($q) > 40) {
            return array();
        }
        if (substr($q, -3) === '.AX') {
            $q = substr($q, 0, -3) . '.AU';
        }
        $limit = max(1, min(30, (int) $limit));
        global $wpdb;
        $table = self::table();
        $like = '%' . $wpdb->esc_like($q) . '%';
        $prefix = $wpdb->esc_like($q) . '%';
        $sql = $wpdb->prepare(
            "SELECT symbol, name FROM {$table}
             WHERE symbol LIKE %s OR code LIKE %s OR name LIKE %s
             ORDER BY
               CASE
                 WHEN symbol = %s OR code = %s THEN 0
                 WHEN symbol LIKE %s OR code LIKE %s THEN 1
                 ELSE 2
               END,
               name ASC
             LIMIT %d",
            $like,
            $like,
            $like,
            $q,
            $q,
            $prefix,
            $prefix,
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!$rows) {
            return array();
        }
        $out = array();
        foreach ($rows as $r) {
            $sym = isset($r['symbol']) ? (string) $r['symbol'] : '';
            if ($sym === '') {
                continue;
            }
            $out[] = array(
                'symbol' => $sym,
                'name'   => isset($r['name']) ? (string) $r['name'] : '',
            );
        }
        return $out;
    }

    public static function maybe_refresh($force = false) {
        self::maybe_install();
        if (!class_exists('SIG_EODHD') || !SIG_EODHD::has_key()) {
            return array('ok' => false, 'reason' => 'no key');
        }
        $n = self::count();
        $m = self::meta();
        $ts = isset($m['ts']) ? (int) $m['ts'] : 0;
        $stale = ($ts <= 0) || ($ts < (time() - (self::STALE_DAYS * DAY_IN_SECONDS)));
        if (!$force && $n > 0 && !$stale) {
            return array('ok' => true, 'skipped' => true, 'count' => $n);
        }
        return self::refresh();
    }

    public static function refresh() {
        self::maybe_install();
        if (!class_exists('SIG_EODHD') || !SIG_EODHD::has_key()) {
            return array('ok' => false, 'reason' => 'no key');
        }
        @ignore_user_abort(true);
        @set_time_limit(0);
        $counts = array();
        $errors = array();
        foreach (self::exchanges() as $ex) {
            $rows = SIG_EODHD::fetch_exchange_symbol_list($ex);
            if (is_wp_error($rows)) {
                $errors[$ex] = $rows->get_error_message();
                continue;
            }
            $n = self::replace_exchange($ex, $rows);
            $counts[$ex] = $n;
        }
        $total = self::count();
        $now = class_exists('SIG_Writer') ? SIG_Writer::brisbane_now() : new DateTime('now', new DateTimeZone('Australia/Brisbane'));
        $err = $errors ? implode('; ', $errors) : '';
        update_option(self::META, array(
            'at'         => $now->format('Y-m-d H:i:s'),
            'ts'         => $now->getTimestamp(),
            'count'      => $total,
            'exchanges'  => $counts,
            'error'      => $err,
        ), false);
        $ok = ($total > 0);
        return array(
            'ok'         => $ok,
            'count'      => $total,
            'exchanges'  => $counts,
            'reason'     => $ok ? '' : ($err !== '' ? $err : 'empty'),
            'error'      => $err,
        );
    }

    protected static function replace_exchange($exchange, $rows) {
        global $wpdb;
        $exchange = strtoupper((string) $exchange);
        $table = self::table();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE exchange = %s", $exchange));
        if (!$rows || !is_array($rows)) {
            return 0;
        }
        $chunk = array();
        $n = 0;
        foreach ($rows as $r) {
            $chunk[] = $r;
            if (count($chunk) >= 200) {
                $n += self::insert_chunk($chunk);
                $chunk = array();
            }
        }
        if ($chunk) {
            $n += self::insert_chunk($chunk);
        }
        return $n;
    }

    protected static function insert_chunk($rows) {
        global $wpdb;
        $table = self::table();
        $ph = array();
        $vals = array();
        foreach ($rows as $r) {
            $ph[] = '(%s,%s,%s,%s,%s)';
            $vals[] = $r['symbol'];
            $vals[] = $r['code'];
            $vals[] = $r['name'];
            $vals[] = $r['exchange'];
            $vals[] = $r['type'];
        }
        if (!$ph) {
            return 0;
        }
        $sql = "INSERT INTO {$table} (symbol, code, name, exchange, type) VALUES " . implode(',', $ph);
        $wpdb->query($wpdb->prepare($sql, $vals));
        return count($rows);
    }

    public static function handle_refresh() {
        if (!current_user_can('manage_options')) {
            wp_die('Nope');
        }
        check_admin_referer('sig_symbols_refresh');
        $result = self::maybe_refresh(true);
        $url = admin_url('options-general.php?page=signal-membership');
        wp_safe_redirect(add_query_arg('sig_writer', !empty($result['ok']) ? 'symbols' : 'symbols_fail', $url));
        exit;
    }
}
