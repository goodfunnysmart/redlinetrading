<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin-owned bars + signals_daily. Same shape as the engine tables.
 * History lives here, never in wp_options.
 */
class SIG_Store {
    const SCHEMA_VER = '1';

    public static function init() {
        self::maybe_install();
    }

    public static function bars_table() {
        global $wpdb;
        return $wpdb->prefix . 'sig_bars';
    }

    public static function signals_table() {
        global $wpdb;
        return $wpdb->prefix . 'sig_signals_daily';
    }

    public static function maybe_install() {
        if ((string) get_option('sig_store_ver', '') === self::SCHEMA_VER) {
            return;
        }
        self::install();
        update_option('sig_store_ver', self::SCHEMA_VER, false);
    }

    public static function install() {
        global $wpdb;
        $collate = $wpdb->get_charset_collate();
        $bars = self::bars_table();
        $sigs = self::signals_table();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$bars} (
            symbol VARCHAR(32) NOT NULL,
            trade_date DATE NOT NULL,
            open DOUBLE NULL,
            high DOUBLE NULL,
            low DOUBLE NULL,
            close DOUBLE NOT NULL,
            volume BIGINT NULL,
            PRIMARY KEY  (symbol, trade_date),
            KEY trade_date (trade_date)
        ) {$collate};");
        dbDelta("CREATE TABLE {$sigs} (
            trade_date DATE NOT NULL,
            symbol VARCHAR(32) NOT NULL,
            signal VARCHAR(16) NOT NULL DEFAULT 'none',
            close DOUBLE NULL,
            ema15 DOUBLE NULL,
            ema25 DOUBLE NULL,
            ema36 DOUBLE NULL,
            ema45 DOUBLE NULL,
            ema55 DOUBLE NULL,
            ema65 DOUBLE NULL,
            note VARCHAR(191) NULL,
            PRIMARY KEY  (trade_date, symbol),
            KEY signal (signal)
        ) {$collate};");
    }

    public static function replace_bars($symbol, $rows) {
        global $wpdb;
        $symbol = SIG_Access::sanitize_symbol($symbol);
        if ($symbol === '' || !is_array($rows) || !$rows) {
            return 0;
        }
        $table = self::bars_table();
        $n = 0;
        foreach ($rows as $r) {
            if (empty($r['trade_date']) || !isset($r['close']) || $r['close'] === null || $r['close'] === '') {
                continue;
            }
            $ok = $wpdb->replace(
                $table,
                array(
                    'symbol'     => $symbol,
                    'trade_date' => $r['trade_date'],
                    'open'       => isset($r['open']) ? $r['open'] : null,
                    'high'       => isset($r['high']) ? $r['high'] : null,
                    'low'        => isset($r['low']) ? $r['low'] : null,
                    'close'      => $r['close'],
                    'volume'     => isset($r['volume']) ? $r['volume'] : null,
                ),
                array('%s', '%s', '%f', '%f', '%f', '%f', '%d')
            );
            if ($ok !== false) {
                $n++;
            }
        }
        return $n;
    }

    public static function bars($symbol) {
        global $wpdb;
        $symbol = SIG_Access::sanitize_symbol($symbol);
        if ($symbol === '') {
            return array();
        }
        $table = self::bars_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT trade_date, open, high, low, close, volume
                 FROM {$table} WHERE symbol = %s ORDER BY trade_date ASC",
                $symbol
            ),
            ARRAY_A
        );
        return $rows ? $rows : array();
    }

    public static function has_bars($symbol) {
        global $wpdb;
        $symbol = SIG_Access::sanitize_symbol($symbol);
        if ($symbol === '') {
            return false;
        }
        $table = self::bars_table();
        $n = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE symbol = %s LIMIT 1",
            $symbol
        ));
        return (bool) $n;
    }

    public static function latest_trade_date() {
        global $wpdb;
        $table = self::signals_table();
        $d = $wpdb->get_var("SELECT MAX(trade_date) FROM {$table}");
        return $d ? $d : null;
    }

    public static function replace_signals($trade_date, $rows) {
        global $wpdb;
        $trade_date = SIG_Cache::parse_snapshot_date($trade_date);
        if (!$trade_date || !is_array($rows)) {
            return 0;
        }
        $table = self::signals_table();
        $n = 0;
        foreach ($rows as $r) {
            $sym = isset($r['symbol']) ? SIG_Access::sanitize_symbol($r['symbol']) : '';
            if ($sym === '') {
                continue;
            }
            $signal = isset($r['signal']) ? $r['signal'] : 'none';
            if (!in_array($signal, array('buy', 'sell', 'watch', 'none'), true)) {
                $signal = 'none';
            }
            $ok = $wpdb->replace(
                $table,
                array(
                    'trade_date' => $trade_date,
                    'symbol'     => $sym,
                    'signal'     => $signal,
                    'close'      => isset($r['close']) ? $r['close'] : null,
                    'ema15'      => isset($r['ema15']) ? $r['ema15'] : null,
                    'ema25'      => isset($r['ema25']) ? $r['ema25'] : null,
                    'ema36'      => isset($r['ema36']) ? $r['ema36'] : (isset($r['ema35']) ? $r['ema35'] : null),
                    'ema45'      => isset($r['ema45']) ? $r['ema45'] : null,
                    'ema55'      => isset($r['ema55']) ? $r['ema55'] : null,
                    'ema65'      => isset($r['ema65']) ? $r['ema65'] : null,
                    'note'       => isset($r['note']) ? $r['note'] : SIG_Signals::note($signal),
                ),
                array('%s', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%s')
            );
            if ($ok !== false) {
                $n++;
            }
        }
        return $n;
    }

    public static function signals_for($symbols, $trade_date) {
        global $wpdb;
        if (!$symbols || !$trade_date) {
            return array();
        }
        $symbols = array_values(array_unique(array_filter(array_map(array('SIG_Access', 'sanitize_symbol'), (array) $symbols))));
        if (!$symbols) {
            return array();
        }
        $table = self::signals_table();
        $in = implode(',', array_fill(0, count($symbols), '%s'));
        $args = $symbols;
        array_unshift($args, $trade_date);
        $sql = $wpdb->prepare(
            "SELECT trade_date, symbol, signal, close, ema15, ema25, ema36, ema45, ema55, ema65, note
             FROM {$table}
             WHERE trade_date = %s AND symbol IN ($in)
             ORDER BY FIELD(signal,'buy','sell','watch','none'), symbol ASC",
            $args
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return $rows ? $rows : array();
    }
}
