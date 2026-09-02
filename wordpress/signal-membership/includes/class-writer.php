<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Weekday EODHD writer. Batches of 25. Australia/Brisbane, cutoff 16:45.
 * Hooked to WP-Cron (sig_writer_tick every 15 minutes). Real host crontab
 * must hit wp-cron.php — the site already has a every-15-minutes hit for the 18:00 email.
 * NEVER invoked from member page load or REST chart/dashboard.
 *
 * Park the engine mailer To: mail@greache.com / Steve when this writer is
 * live, so Dreamteam is the only member email. Do not stop /redline/ cron
 * until David says so.
 */
class SIG_Writer {
    const BATCH = 25;
    const READY_N = 3;
    const LOG_MAX = 30;
    const TICK_BUDGET = 90;

    public static function init() {
        add_action('init', array(__CLASS__, 'schedule'), 31);
        add_action('sig_writer_tick', array(__CLASS__, 'tick'));
        add_action('sig_writer_extra_one', array(__CLASS__, 'fetch_one_extra'), 10, 1);
        add_action('admin_post_sig_writer_batch', array(__CLASS__, 'handle_manual_batch'));
        add_action('admin_post_sig_writer_compare', array(__CLASS__, 'handle_compare'));
    }

    public static function enabled() {
        return (int) get_option('sig_writer_enabled', 0) === 1;
    }

    public static function schedule() {
        if (get_option('sig_writer_cron_ver') !== '1') {
            wp_clear_scheduled_hook('sig_writer_tick');
            update_option('sig_writer_cron_ver', '1', false);
        }
        if (!wp_next_scheduled('sig_writer_tick')) {
            wp_schedule_event(time() + 180, 'sig_fifteen', 'sig_writer_tick');
        }
    }

    public static function brisbane_now() {
        return new DateTime('now', new DateTimeZone('Australia/Brisbane'));
    }

    public static function cutoff_time() {
        $now = self::brisbane_now();
        $cutoff = DateTime::createFromFormat('Y-m-d H:i:s', $now->format('Y-m-d') . ' 16:45:00', $now->getTimezone());
        if (!$cutoff) {
            return $now->getTimestamp();
        }
        if ($now < $cutoff) {
            $cutoff->modify('-1 day');
        }
        return $cutoff->getTimestamp();
    }

    public static function after_writer_window($now = null) {
        $now = $now ? $now : self::brisbane_now();
        $minutes = ((int) $now->format('G') * 60) + (int) $now->format('i');
        return $minutes >= (16 * 60 + 45);
    }

    public static function is_weekday($now = null) {
        $now = $now ? $now : self::brisbane_now();
        return ((int) $now->format('N')) <= 5;
    }

    public static function snapshot() {
        $snap = get_option('sig_local_snapshot', array());
        return is_array($snap) ? $snap : array();
    }

    public static function save_snapshot($snap) {
        update_option('sig_local_snapshot', $snap, false);
    }

    public static function empty_snapshot($now = null) {
        $now = $now ? $now : self::brisbane_now();
        return array(
            'date'      => $now->format('d M Y H:i'),
            'timestamp' => $now->getTimestamp(),
            'capital'   => number_format(100000),
            'buy'       => array(),
            'exit'      => array(),
            'watch'     => array(),
            'processed' => array(),
        );
    }

    public static function session_snapshot($now = null) {
        $now = $now ? $now : self::brisbane_now();
        $snap = self::snapshot();
        $cutoff = self::cutoff_time();
        if (empty($snap['timestamp']) || (int) $snap['timestamp'] < $cutoff) {
            $snap = self::empty_snapshot($now);
            self::save_snapshot($snap);
        }
        return $snap;
    }

    public static function log($msg, $level = 'info') {
        $msg = preg_replace('/api_token=[^&\s]+/i', 'api_token=***', (string) $msg);
        $now = self::brisbane_now();
        $rows = get_option('sig_writer_log', array());
        if (!is_array($rows)) {
            $rows = array();
        }
        $rows[] = array(
            't'     => $now->format('Y-m-d H:i:s'),
            'level' => $level,
            'msg'   => $msg,
        );
        if (count($rows) > self::LOG_MAX) {
            $rows = array_slice($rows, -self::LOG_MAX);
        }
        update_option('sig_writer_log', $rows, false);
    }

    public static function logs() {
        $rows = get_option('sig_writer_log', array());
        return is_array($rows) ? $rows : array();
    }

    public static function mark_fetched() {
        $now = self::brisbane_now();
        update_option('sig_writer_last_fetch', $now->format('Y-m-d H:i:s'), false);
    }

    public static function last_fetch() {
        return (string) get_option('sig_writer_last_fetch', '');
    }

    public static function ready_n() {
        $n = (int) get_option('sig_writer_ready_n', self::READY_N);
        return $n > 0 ? $n : self::READY_N;
    }

    public static function ok_dates() {
        $d = get_option('sig_writer_ok_dates', array());
        return is_array($d) ? $d : array();
    }

    public static function record_ok_date($ymd) {
        $ymd = SIG_Cache::parse_snapshot_date($ymd);
        if (!$ymd) {
            return;
        }
        $dates = self::ok_dates();
        if (!in_array($ymd, $dates, true)) {
            $dates[] = $ymd;
        }
        $dates = array_values(array_unique($dates));
        sort($dates);
        if (count($dates) > 14) {
            $dates = array_slice($dates, -14);
        }
        update_option('sig_writer_ok_dates', $dates, false);
    }

    public static function matching_weekdays() {
        return count(self::ok_dates());
    }

    public static function ready_for_local() {
        return self::matching_weekdays() >= self::ready_n();
    }

    public static function enqueue_extra($symbol) {
        $symbol = SIG_Access::sanitize_symbol($symbol);
        if ($symbol === '' || SIG_Universe::is_core($symbol)) {
            return;
        }
        $q = get_option('sig_writer_extra_queue', array());
        if (!is_array($q)) {
            $q = array();
        }
        if (!in_array($symbol, $q, true)) {
            $q[] = $symbol;
            update_option('sig_writer_extra_queue', $q, false);
        }
        if (!wp_next_scheduled('sig_writer_extra_one', array($symbol))) {
            wp_schedule_single_event(time() + 30, 'sig_writer_extra_one', array($symbol));
        }
    }

    public static function extra_queue() {
        $q = get_option('sig_writer_extra_queue', array());
        return is_array($q) ? $q : array();
    }

    public static function dequeue_extra($symbol) {
        $q = self::extra_queue();
        $q = array_values(array_diff($q, array($symbol)));
        update_option('sig_writer_extra_queue', $q, false);
    }

    /**
     * WP-Cron weekday tick. Respects writer on/off and 16:45 cutoff.
     */
    public static function tick() {
        if (!self::enabled()) {
            return;
        }
        if (!SIG_EODHD::has_key()) {
            self::log('Writer on but API key is missing.', 'error');
            return;
        }
        $now = self::brisbane_now();
        if (!self::is_weekday($now)) {
            self::process_extras(self::BATCH);
            return;
        }
        if (!$now || !self::after_writer_window($now)) {
            self::process_extras(min(5, self::BATCH));
            return;
        }
        self::run_batch(false);
    }

    public static function fetch_one_extra($symbol) {
        if (!SIG_EODHD::has_key()) {
            return;
        }
        $symbol = SIG_Access::sanitize_symbol($symbol);
        if ($symbol === '' || SIG_Universe::is_core($symbol)) {
            return;
        }
        $ok = self::fetch_and_store($symbol, false);
        if ($ok) {
            self::dequeue_extra($symbol);
        }
    }

    /**
     * @param bool $force ignore weekday/cutoff (admin manual).
     */
    public static function run_batch($force = false) {
        if (!self::enabled() && !$force) {
            return array('ok' => false, 'reason' => 'writer off');
        }
        if (!SIG_EODHD::has_key()) {
            self::log('No API key; batch skipped.', 'error');
            return array('ok' => false, 'reason' => 'no key');
        }
        @set_time_limit(self::TICK_BUDGET + 30);
        $started = time();
        $now = self::brisbane_now();
        $snap = self::session_snapshot($now);

        $xjo = SIG_Universe::XJO;
        $xjo_file_stale = true;
        if (class_exists('SIG_Store') && SIG_Store::has_bars($xjo)) {
            $xjo_file_stale = empty($snap['processed']);
        }
        if ($xjo_file_stale || !SIG_Store::has_bars($xjo)) {
            self::fetch_and_store($xjo, false);
        }

        $core = SIG_Universe::core();
        $processed = isset($snap['processed']) && is_array($snap['processed']) ? $snap['processed'] : array();
        $todo = array();
        $done_map = array_fill_keys($processed, true);
        foreach ($core as $sym) {
            if (!isset($done_map[$sym])) {
                $todo[] = $sym;
            }
        }

        $did = 0;
        $errors = 0;
        $chunk = array_slice($todo, 0, self::BATCH);
        foreach ($chunk as $sym) {
            if ((time() - $started) > self::TICK_BUDGET) {
                break;
            }
            $row = self::fetch_and_store($sym, true);
            $snap = self::snapshot();
            if (empty($snap['processed']) || !is_array($snap['processed'])) {
                $snap['processed'] = array();
            }
            if (!in_array($sym, $snap['processed'], true)) {
                $snap['processed'][] = $sym;
            }
            if (is_array($row) && !empty($row['signal']) && $row['signal'] !== 'none') {
                $bucket = ($row['signal'] === 'sell') ? 'exit' : $row['signal'];
                if ($bucket === 'buy' || $bucket === 'exit' || $bucket === 'watch') {
                    $snap[$bucket][] = array(
                        'ticker' => $sym,
                        'price'  => $row['price_fmt'],
                        'shares' => number_format((int) $row['shares']),
                        'value'  => number_format((int) $row['value']),
                    );
                }
            }
            $snap['date'] = $now->format('d M Y H:i');
            $snap['timestamp'] = $now->getTimestamp();
            self::save_snapshot($snap);
            $did++;
            if ($row === false) {
                $errors++;
            }
        }

        self::mark_fetched();

        $remaining = count($core) - count($snap['processed']);
        if ($remaining < 0) {
            $remaining = 0;
        }
        if ($remaining === 0 && count($snap['processed']) >= count($core)) {
            $finalized_ts = (int) get_option('sig_writer_finalized_ts', 0);
            if ($finalized_ts < self::cutoff_time()) {
                self::finalize_session($snap);
            }
        }

        $extra_did = 0;
        if ((time() - $started) < self::TICK_BUDGET) {
            $left = self::BATCH - $did;
            if ($left < 3) {
                $left = 5;
            }
            $extra_did = self::process_extras($left, $started);
        }

        self::log(sprintf(
            'Batch: %d core, %d extras, %d errors, %d remaining core.',
            $did,
            $extra_did,
            $errors,
            $remaining
        ));

        return array(
            'ok'        => true,
            'core'      => $did,
            'extras'    => $extra_did,
            'errors'    => $errors,
            'remaining' => $remaining,
        );
    }

    protected static function finalize_session($snap) {
        $rows = array();
        $trade_date = null;
        $buy = array();
        $exit = array();
        $watch = array();
        foreach (SIG_Universe::core() as $sym) {
            $bars = SIG_Store::bars($sym);
            $built = self::signal_row_from_bars($sym, $bars);
            if (!$built || empty($built['trade_date'])) {
                continue;
            }
            $trade_date = $built['trade_date'];
            $rows[] = $built;
            if (empty($built['signal']) || $built['signal'] === 'none') {
                continue;
            }
            $item = array(
                'ticker' => $sym,
                'price'  => $built['price_fmt'],
                'shares' => number_format((int) $built['shares']),
                'value'  => number_format((int) $built['value']),
            );
            if ($built['signal'] === 'buy') {
                $buy[] = $item;
            } elseif ($built['signal'] === 'sell') {
                $exit[] = $item;
            } elseif ($built['signal'] === 'watch') {
                $watch[] = $item;
            }
        }
        $now = self::brisbane_now();
        if (!is_array($snap)) {
            $snap = self::snapshot();
        }
        $snap['buy'] = $buy;
        $snap['exit'] = $exit;
        $snap['watch'] = $watch;
        $snap['date'] = $now->format('d M Y H:i');
        $snap['timestamp'] = $now->getTimestamp();
        self::save_snapshot($snap);
        if ($trade_date && $rows) {
            SIG_Store::replace_signals($trade_date, $rows);
            self::record_ok_date($trade_date);
            update_option('sig_writer_finalized_ts', time(), false);
            self::log('Snapshot complete for ' . $trade_date . ' (' . count($rows) . ' core rows).');
        }
        if (class_exists('SIG_Reads') && SIG_Reads::mode() === 'shadow') {
            self::compare_to_remote($snap);
        }
    }

    public static function process_extras($limit = 25, $started = 0) {
        if (!self::enabled() || !SIG_EODHD::has_key()) {
            return 0;
        }
        if (!$started) {
            $started = time();
        }
        $wanted = array_values(array_unique(array_merge(self::extra_queue(), SIG_Universe::all_member_extras())));
        $n = 0;
        foreach ($wanted as $sym) {
            if ($n >= $limit || (time() - $started) > self::TICK_BUDGET) {
                break;
            }
            if (self::fetch_and_store($sym, false)) {
                self::dequeue_extra($sym);
            }
            $n++;
        }
        return $n;
    }

    /**
     * Fetch 400 days, write bars (+ optional CSV). Classify only for core snapshot.
     * @return array|true|false
     */
    public static function fetch_and_store($symbol, $classify) {
        $symbol = SIG_Access::sanitize_symbol($symbol);
        if ($symbol === '') {
            return false;
        }
        $csv = SIG_EODHD::fetch_csv($symbol);
        if (is_wp_error($csv)) {
            self::log($csv->get_error_message(), 'error');
            return false;
        }
        $rows = SIG_Cache::parse_csv($csv);
        if (!$rows) {
            self::log('Parsed 0 bars for ' . $symbol, 'error');
            return false;
        }
        SIG_Store::replace_bars($symbol, $rows);
        self::mark_fetched();
        if (!$classify) {
            return true;
        }
        return self::signal_row_from_bars($symbol, $rows);
    }

    public static function signal_row_from_bars($symbol, $bars) {
        require_once dirname(__FILE__) . '/class-ema.php';
        if (!$bars || count($bars) < 100) {
            return array(
                'symbol'     => $symbol,
                'signal'     => 'none',
                'close'      => null,
                'ema15'      => null,
                'ema25'      => null,
                'ema36'      => null,
                'ema45'      => null,
                'ema55'      => null,
                'ema65'      => null,
                'note'       => '',
                'trade_date' => !empty($bars) ? $bars[count($bars) - 1]['trade_date'] : null,
                'price_fmt'  => '',
                'shares'     => 0,
                'value'      => 0,
            );
        }
        $closes = array();
        foreach ($bars as $r) {
            if (!isset($r['close']) || $r['close'] === null || $r['close'] === '') {
                continue;
            }
            $closes[] = (float) $r['close'];
        }
        $n = count($closes);
        if ($n < 100) {
            return false;
        }
        $ribbon = SIG_Ema::ribbon($closes);
        $last = $n - 1;
        $prev = $n - 2;
        $latest = $closes[$last];
        $yest = $closes[$prev];
        $ema15 = (isset($ribbon[15][$last]) && $ribbon[15][$last] !== null) ? (float) $ribbon[15][$last] : null;
        $ema25 = (isset($ribbon[25][$last]) && $ribbon[25][$last] !== null) ? (float) $ribbon[25][$last] : null;
        $ema35 = (isset($ribbon[35][$last]) && $ribbon[35][$last] !== null) ? (float) $ribbon[35][$last] : null;
        $ema45 = (isset($ribbon[45][$last]) && $ribbon[45][$last] !== null) ? (float) $ribbon[45][$last] : null;
        $ema55 = (isset($ribbon[55][$last]) && $ribbon[55][$last] !== null) ? (float) $ribbon[55][$last] : null;
        $ema65 = (isset($ribbon[65][$last]) && $ribbon[65][$last] !== null) ? (float) $ribbon[65][$last] : null;
        $signal = 'none';
        if ($ema15 !== null && $ema65 !== null) {
            $signal = SIG_Signals::classify($latest, $yest, $ema15, $ema65);
        }
        $capital = 100000;
        $shares = 0;
        $value = 0;
        if ($ema65 !== null && $latest > $ema65) {
            $risk = $capital * 0.01;
            $shares = (int) floor($risk / ($latest - $ema65));
            if (($shares * $latest) > ($capital * 0.25)) {
                $shares = (int) floor(($capital * 0.25) / $latest);
            }
            $value = (int) (round(($shares * $latest) / 10) * 10);
        }
        $decimals = ($latest < 1.0) ? 3 : 2;
        return array(
            'symbol'     => $symbol,
            'signal'     => $signal,
            'close'      => $latest,
            'ema15'      => $ema15,
            'ema25'      => $ema25,
            'ema36'      => $ema35,
            'ema45'      => $ema45,
            'ema55'      => $ema55,
            'ema65'      => $ema65,
            'note'       => SIG_Signals::note($signal),
            'trade_date' => $bars[$last]['trade_date'],
            'price_fmt'  => number_format($latest, $decimals),
            'shares'     => $shares,
            'value'      => $value,
        );
    }

    public static function snapshot_counts($snap = null) {
        $snap = is_array($snap) ? $snap : self::snapshot();
        $c = array(
            'buy'       => 0,
            'exit'      => 0,
            'watch'     => 0,
            'processed' => 0,
        );
        foreach (array('buy', 'exit', 'watch') as $k) {
            $c[$k] = (!empty($snap[$k]) && is_array($snap[$k])) ? count($snap[$k]) : 0;
        }
        $c['processed'] = (!empty($snap['processed']) && is_array($snap['processed'])) ? count($snap['processed']) : 0;
        return $c;
    }

    public static function compare_to_remote($local = null) {
        $local = is_array($local) ? $local : self::snapshot();
        $remote = SIG_Cache::http_snapshot();
        $lc = self::snapshot_counts($local);
        $rc = self::snapshot_counts($remote);
        $match = ($lc['buy'] === $rc['buy'] && $lc['exit'] === $rc['exit'] && $lc['watch'] === $rc['watch']);
        $cmp = array(
            'at'      => self::brisbane_now()->format('Y-m-d H:i:s'),
            'match'   => $match,
            'local'   => $lc,
            'remote'  => $rc,
            'core'    => count(SIG_Universe::core()),
        );
        update_option('sig_shadow_compare', $cmp, false);
        self::log(
            sprintf(
                'Shadow compare: local buy/exit/watch %d/%d/%d vs remote %d/%d/%d (%s).',
                $lc['buy'],
                $lc['exit'],
                $lc['watch'],
                $rc['buy'],
                $rc['exit'],
                $rc['watch'],
                $match ? 'match' : 'mismatch'
            ),
            $match ? 'info' : 'warn'
        );
        return $cmp;
    }

    public static function shadow_compare() {
        $c = get_option('sig_shadow_compare', array());
        return is_array($c) ? $c : array();
    }

    public static function handle_manual_batch() {
        if (!current_user_can('manage_options')) {
            wp_die('Nope');
        }
        check_admin_referer('sig_writer_batch');
        $result = self::run_batch(true);
        $url = admin_url('options-general.php?page=signal-membership');
        wp_safe_redirect(add_query_arg('sig_writer', !empty($result['ok']) ? 'batch' : 'fail', $url));
        exit;
    }

    public static function handle_compare() {
        if (!current_user_can('manage_options')) {
            wp_die('Nope');
        }
        check_admin_referer('sig_writer_compare');
        self::compare_to_remote();
        $url = admin_url('options-general.php?page=signal-membership');
        wp_safe_redirect(add_query_arg('sig_writer', 'compare', $url));
        exit;
    }
}
