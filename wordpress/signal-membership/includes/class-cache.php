<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Live file/HTTP fallback when the engine MySQL is not configured.
 * Local path preferred: dirname(ABSPATH)/redline/cache/
 * Snapshot keys: buy, exit, watch, processed. Map exit -> sell.
 */
class SIG_Cache {
    protected static $snapshot = null;
    protected static $favorites = null;

    public static function init() {}

    public static function cache_dir() {
        $opt = trim((string) get_option('sig_cache_dir', ''));
        if ($opt !== '') {
            return rtrim($opt, '/') . '/';
        }
        if (defined('ABSPATH')) {
            return rtrim(dirname(ABSPATH), '/') . '/redline/cache/';
        }
        return '';
    }

    public static function snapshot_url() {
        $opt = trim((string) get_option('sig_snapshot_url', ''));
        if ($opt !== '') {
            return $opt;
        }
        return 'https://greache.com/redline/signals_snapshot.json';
    }

    public static function redline_dir() {
        $dir = rtrim(self::cache_dir(), '/');
        return dirname($dir);
    }

    public static function http_base() {
        $url = self::snapshot_url();
        $base = rtrim(dirname($url), '/');
        if ($base === '' || $base === '.') {
            return 'https://greache.com/redline';
        }
        return $base;
    }

    public static function symbol_to_filename($symbol) {
        $symbol = strtoupper(trim((string) $symbol));
        $pos = strrpos($symbol, '.');
        if ($pos === false) {
            return $symbol . '.csv';
        }
        return substr($symbol, 0, $pos) . '_' . substr($symbol, $pos + 1) . '.csv';
    }

    public static function parse_snapshot_date($date_str) {
        $date_str = trim((string) $date_str);
        if ($date_str === '') {
            return null;
        }
        $dt = date_create($date_str);
        if ($dt) {
            return $dt->format('Y-m-d');
        }
        return null;
    }

    protected static function http_get($url) {
        if (!$url) {
            return '';
        }
        $res = wp_remote_get($url, array(
            'timeout'     => 20,
            'sslverify'   => true,
            'redirection' => 3,
            'headers'     => array('Accept' => '*/*'),
        ));
        if (is_wp_error($res)) {
            return '';
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            return '';
        }
        return (string) wp_remote_retrieve_body($res);
    }

    protected static function read_local($path) {
        if (!$path || !is_readable($path)) {
            return '';
        }
        $raw = file_get_contents($path);
        return ($raw === false) ? '' : $raw;
    }

    public static function snapshot() {
        if (is_array(self::$snapshot)) {
            return self::$snapshot;
        }
        $raw = self::read_local(self::redline_dir() . '/signals_snapshot.json');
        if ($raw === '') {
            $key = 'sig_snap_http';
            $cached = get_transient($key);
            if (is_string($cached) && $cached !== '') {
                $raw = $cached;
            } else {
                $raw = self::http_get(self::snapshot_url());
                if ($raw !== '') {
                    set_transient($key, $raw, 5 * MINUTE_IN_SECONDS);
                }
            }
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::$snapshot = array();
            return self::$snapshot;
        }
        self::$snapshot = $data;
        return self::$snapshot;
    }

    public static function favorites() {
        if (is_array(self::$favorites)) {
            return self::$favorites;
        }
        $raw = self::read_local(self::redline_dir() . '/favorites.json');
        if ($raw === '') {
            $key = 'sig_fav_http';
            $cached = get_transient($key);
            if (is_string($cached) && $cached !== '') {
                $raw = $cached;
            } else {
                $raw = self::http_get(self::http_base() . '/favorites.json');
                if ($raw !== '') {
                    set_transient($key, $raw, 5 * MINUTE_IN_SECONDS);
                }
            }
        }
        $data = json_decode($raw, true);
        $out = array();
        if (is_array($data)) {
            foreach ($data as $item) {
                if (is_string($item)) {
                    $sym = SIG_Access::sanitize_symbol($item);
                } elseif (is_array($item) && isset($item['ticker'])) {
                    $sym = SIG_Access::sanitize_symbol($item['ticker']);
                } elseif (is_array($item) && isset($item['symbol'])) {
                    $sym = SIG_Access::sanitize_symbol($item['symbol']);
                } else {
                    $sym = '';
                }
                if ($sym !== '') {
                    $out[] = $sym;
                }
            }
        }
        self::$favorites = array_values(array_unique($out));
        return self::$favorites;
    }

    public static function latest_trade_date() {
        $snap = self::snapshot();
        if (isset($snap['date'])) {
            $d = self::parse_snapshot_date($snap['date']);
            if ($d) {
                return $d;
            }
        }
        return null;
    }

    public static function universe() {
        $snap = self::snapshot();
        $out = array();
        if (!empty($snap['processed']) && is_array($snap['processed'])) {
            foreach ($snap['processed'] as $item) {
                $sym = is_string($item) ? SIG_Access::sanitize_symbol($item) : '';
                if ($sym !== '') {
                    $out[] = $sym;
                }
            }
        }
        return array_values(array_unique($out));
    }

    protected static function signal_index($snap) {
        $map = array();
        $groups = array(
            'buy'   => 'buy',
            'exit'  => 'sell',
            'sell'  => 'sell',
            'watch' => 'watch',
        );
        foreach ($groups as $key => $signal) {
            if (empty($snap[$key]) || !is_array($snap[$key])) {
                continue;
            }
            foreach ($snap[$key] as $item) {
                $sym = '';
                $close = null;
                if (is_string($item)) {
                    $sym = SIG_Access::sanitize_symbol($item);
                } elseif (is_array($item)) {
                    if (isset($item['ticker'])) {
                        $sym = SIG_Access::sanitize_symbol($item['ticker']);
                    } elseif (isset($item['symbol'])) {
                        $sym = SIG_Access::sanitize_symbol($item['symbol']);
                    }
                    if (isset($item['price']) && $item['price'] !== '') {
                        $close = (float) str_replace(',', '', (string) $item['price']);
                    }
                }
                if ($sym === '') {
                    continue;
                }
                $map[$sym] = array(
                    'signal' => $signal,
                    'close'  => $close,
                );
            }
        }
        return $map;
    }

    public static function signals_for($symbols, $trade_date) {
        if (!$symbols) {
            return array();
        }
        $snap = self::snapshot();
        $date = $trade_date ? $trade_date : self::latest_trade_date();
        $idx = self::signal_index($snap);
        $processed = array_fill_keys(self::universe(), true);
        $out = array();
        foreach ($symbols as $raw) {
            $sym = SIG_Access::sanitize_symbol($raw);
            if ($sym === '') {
                continue;
            }
            $hit = isset($idx[$sym]) ? $idx[$sym] : null;
            $in_universe = isset($processed[$sym]);
            if (!$hit && !$in_universe) {
                continue;
            }
            $signal = $hit ? $hit['signal'] : 'none';
            $close = ($hit && $hit['close'] !== null) ? $hit['close'] : null;
            $out[] = array(
                'trade_date' => $date,
                'symbol'     => $sym,
                'signal'     => $signal,
                'close'      => $close,
                'ema15'      => null,
                'ema25'      => null,
                'ema36'      => null,
                'ema45'      => null,
                'ema55'      => null,
                'ema65'      => null,
                'note'       => SIG_Signals::note($signal),
            );
        }
        usort($out, array(__CLASS__, 'sort_signal_rows'));
        return $out;
    }

    public static function sort_signal_rows($a, $b) {
        $order = array('buy' => 0, 'sell' => 1, 'watch' => 2, 'none' => 3);
        $oa = isset($order[$a['signal']]) ? $order[$a['signal']] : 9;
        $ob = isset($order[$b['signal']]) ? $order[$b['signal']] : 9;
        if ($oa !== $ob) {
            return ($oa < $ob) ? -1 : 1;
        }
        return strcmp($a['symbol'], $b['symbol']);
    }

    public static function signal_map() {
        return self::signal_index(self::snapshot());
    }

    public static function universe_counts() {
        $universe = self::universe();
        $idx = self::signal_map();
        $c = array(
            'buy'   => 0,
            'sell'  => 0,
            'watch' => 0,
            'all'   => count($universe),
        );
        foreach ($universe as $sym) {
            $sig = isset($idx[$sym]) ? $idx[$sym]['signal'] : 'none';
            if ($sig === 'buy' || $sig === 'sell' || $sig === 'watch') {
                $c[$sig]++;
            }
        }
        return $c;
    }

    public static function symbols_for_view($view, $watchlist) {
        $view = strtolower(trim((string) $view));
        if ($view === '' || $view === 'dreamteam' || $view === 'watchlist') {
            return $watchlist ? array_values($watchlist) : array();
        }
        $universe = self::universe();
        if ($view === 'all') {
            return $universe;
        }
        if ($view === 'buy' || $view === 'sell' || $view === 'watch') {
            $idx = self::signal_map();
            $out = array();
            foreach ($universe as $sym) {
                $sig = isset($idx[$sym]) ? $idx[$sym]['signal'] : 'none';
                if ($sig === $view) {
                    $out[] = $sym;
                }
            }
            return $out;
        }
        return $watchlist ? array_values($watchlist) : array();
    }

    /**
     * close / ema65 / 6m return for symbols.
     * Transient sig_quotes_v1_{Brisbane Y-m-d} for 6 hours.
     * Bars prefer local cache_dir CSVs.
     */
    public static function quotes_for($symbols) {
        require_once dirname(__FILE__) . '/class-ema.php';

        $wanted = array();
        foreach ((array) $symbols as $raw) {
            $sym = SIG_Access::sanitize_symbol($raw);
            if ($sym !== '') {
                $wanted[$sym] = true;
            }
        }
        $wanted = array_keys($wanted);
        if (!$wanted) {
            return array();
        }

        $tz = new DateTimeZone('Australia/Brisbane');
        $day = (new DateTime('now', $tz))->format('Y-m-d');
        $cache_key = 'sig_quotes_v3_' . $day;
        $map = get_transient($cache_key);
        if (!is_array($map)) {
            $map = array();
        }

        $missing = array();
        $out = array();
        foreach ($wanted as $sym) {
            if (isset($map[$sym]) && is_array($map[$sym])) {
                $out[$sym] = $map[$sym];
            } else {
                $missing[] = $sym;
            }
        }

        foreach ($missing as $sym) {
            $quote = self::quote_from_bars($sym);
            $map[$sym] = $quote;
            $out[$sym] = $quote;
        }

        if ($missing) {
            set_transient($cache_key, $map, 6 * HOUR_IN_SECONDS);
        }
        return $out;
    }

    protected static function quote_from_bars($symbol) {
        $empty = array('close' => null, 'ema65' => null, 'ret_6m' => null, 'ret_1d' => null);
        $bars = self::bars($symbol);
        if (!$bars) {
            return $empty;
        }
        $closes = array();
        foreach ($bars as $r) {
            if (!isset($r['close']) || $r['close'] === null || $r['close'] === '') {
                continue;
            }
            $closes[] = (float) $r['close'];
        }
        $n = count($closes);
        if ($n < 1) {
            return $empty;
        }
        $close = $closes[$n - 1];
        $ribbon = SIG_Ema::ribbon($closes);
        $ema65 = null;
        if (!empty($ribbon[65]) && isset($ribbon[65][$n - 1]) && $ribbon[65][$n - 1] !== null) {
            $ema65 = (float) $ribbon[65][$n - 1];
        }
        $ret_6m = null;
        $last_date = isset($bars[$n - 1]['trade_date']) ? $bars[$n - 1]['trade_date'] : '';
        $anchor = $last_date ? date_create($last_date) : false;
        if ($anchor) {
            $anchor->modify('-180 days');
            $want = $anchor->format('Y-m-d');
            $prev = null;
            foreach ($bars as $r) {
                if (isset($r['trade_date']) && $r['trade_date'] <= $want && isset($r['close']) && $r['close']) {
                    $prev = (float) $r['close'];
                }
            }
            if ($prev) {
                $ret_6m = (($close / $prev) - 1.0) * 100.0;
            }
        }
        $ret_1d = null;
        if ($n >= 2 && $closes[$n - 2] != 0.0) {
            $ret_1d = (($close / $closes[$n - 2]) - 1.0) * 100.0;
        }
        return array(
            'close'  => $close,
            'ema65'  => $ema65,
            'ret_6m' => $ret_6m,
            'ret_1d' => $ret_1d,
        );
    }

    public static function bars($symbol) {
        $symbol = SIG_Access::sanitize_symbol($symbol);
        if ($symbol === '') {
            return array();
        }
        $file = self::symbol_to_filename($symbol);
        $raw = self::read_local(rtrim(self::cache_dir(), '/') . '/' . $file);
        if ($raw === '') {
            $tkey = 'sig_csv_' . md5($symbol);
            $cached = get_transient($tkey);
            if (is_string($cached) && $cached !== '') {
                $raw = $cached;
            } else {
                $raw = self::http_get(self::http_base() . '/cache/' . $file);
                if ($raw !== '') {
                    set_transient($tkey, $raw, HOUR_IN_SECONDS);
                }
            }
        }
        if ($raw === '') {
            return array();
        }
        return self::parse_csv($raw);
    }

    public static function symbol_exists($symbol) {
        $symbol = SIG_Access::sanitize_symbol($symbol);
        if ($symbol === '') {
            return false;
        }
        $universe = self::universe();
        if (in_array($symbol, $universe, true)) {
            return true;
        }
        $local = rtrim(self::cache_dir(), '/') . '/' . self::symbol_to_filename($symbol);
        if (is_readable($local)) {
            return true;
        }
        $bars = self::bars($symbol);
        return !empty($bars);
    }

    public static function parse_csv($raw) {
        $lines = preg_split("/\r\n|\n|\r/", (string) $raw);
        if (!$lines) {
            return array();
        }
        $header = str_getcsv(array_shift($lines));
        if (!$header) {
            return array();
        }
        $map = array();
        foreach ($header as $i => $name) {
            $map[strtolower(trim($name))] = $i;
        }
        if (!isset($map['date']) || !isset($map['close'])) {
            return array();
        }
        $rows = array();
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            if (!$cols || count($cols) < 2) {
                continue;
            }
            $date = trim($cols[$map['date']]);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $close = self::num(isset($cols[$map['close']]) ? $cols[$map['close']] : null);
            if ($close === null) {
                continue;
            }
            $rows[] = array(
                'trade_date' => $date,
                'open'       => self::num(isset($map['open']) && isset($cols[$map['open']]) ? $cols[$map['open']] : null),
                'high'       => self::num(isset($map['high']) && isset($cols[$map['high']]) ? $cols[$map['high']] : null),
                'low'        => self::num(isset($map['low']) && isset($cols[$map['low']]) ? $cols[$map['low']] : null),
                'close'      => $close,
                'volume'     => self::intv(isset($map['volume']) && isset($cols[$map['volume']]) ? $cols[$map['volume']] : null),
            );
        }
        usort($rows, function ($a, $b) {
            if ($a['trade_date'] === $b['trade_date']) {
                return 0;
            }
            return ($a['trade_date'] < $b['trade_date']) ? -1 : 1;
        });
        return $rows;
    }

    protected static function num($v) {
        if ($v === null || $v === '') {
            return null;
        }
        $v = str_replace(',', '', (string) $v);
        if (!is_numeric($v)) {
            return null;
        }
        return (float) $v;
    }

    protected static function intv($v) {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        return (int) $v;
    }

    /**
     * ASX market vs 65-day EMA, same meaning as original redline.php
     * $marketStatusClass from XJO.INDX close vs EMA65 (green.png / red.png).
     * Prefers quotes cache (CSV at redline/cache/XJO_INDX.csv, transient sig_quotes_v3_*),
     * then engine DB bars. Does not call EODHD.
     *
     * @return array{symbol:string,status:string,label:string,above:?bool,close:?float,ema65:?float}
     */
    public static function market_status() {
        static $once = null;
        if (is_array($once)) {
            return $once;
        }
        $symbol = 'XJO.INDX';
        $close = null;
        $ema65 = null;
        $got = self::quotes_for(array($symbol));
        if (isset($got[$symbol]) && is_array($got[$symbol])) {
            if (isset($got[$symbol]['close']) && $got[$symbol]['close'] !== null && $got[$symbol]['close'] !== '') {
                $close = (float) $got[$symbol]['close'];
            }
            if (isset($got[$symbol]['ema65']) && $got[$symbol]['ema65'] !== null && $got[$symbol]['ema65'] !== '') {
                $ema65 = (float) $got[$symbol]['ema65'];
            }
        }
        if (($close === null || $ema65 === null) && class_exists('SIG_DB')) {
            $bars = SIG_DB::bars($symbol);
            if (!is_wp_error($bars) && $bars) {
                $closes = array();
                foreach ($bars as $r) {
                    if (isset($r['close']) && $r['close'] !== null && $r['close'] !== '') {
                        $closes[] = (float) $r['close'];
                    }
                }
                $n = count($closes);
                if ($n >= 65) {
                    require_once dirname(__FILE__) . '/class-ema.php';
                    $ribbon = SIG_Ema::ribbon($closes);
                    $close = $closes[$n - 1];
                    if (!empty($ribbon[65]) && isset($ribbon[65][$n - 1]) && $ribbon[65][$n - 1] !== null) {
                        $ema65 = (float) $ribbon[65][$n - 1];
                    }
                }
            }
        }
        $status = 'unknown';
        $label = 'ASX vs EMA65 unavailable';
        $above = null;
        if ($close !== null && $ema65 !== null) {
            $above = ($close > $ema65);
            $status = $above ? 'bullish' : 'bearish';
            $label = $above ? 'ASX above EMA65' : 'ASX below EMA65';
        }
        $once = array(
            'symbol' => $symbol,
            'status' => $status,
            'label'  => $label,
            'above'  => $above,
            'close'  => $close,
            'ema65'  => $ema65,
        );
        return $once;
    }
}
