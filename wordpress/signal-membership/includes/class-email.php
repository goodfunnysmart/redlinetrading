<?php
if (!defined('ABSPATH')) {
    exit;
}

class SIG_Email {
    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action('init', array(__CLASS__, 'schedule'), 30);
        add_action('sig_email_tick', array(__CLASS__, 'maybe_fanout'));
        add_action('sig_email_send', array(__CLASS__, 'maybe_fanout'));
        add_action('admin_post_sig_send_preview', array(__CLASS__, 'handle_preview'));
        add_action('admin_post_sig_send_fanout', array(__CLASS__, 'handle_manual_fanout'));
        add_action('wp_mail_failed', array(__CLASS__, 'mail_failed'));
        add_action('phpmailer_init', array(__CLASS__, 'phpmailer_init'));
        self::maybe_fix_from();
    }

    public static function maybe_fix_from() {
        $from = (string) get_option('sig_from_email');
        if ($from === 'radar@greache.com') {
            return;
        }
        update_option('sig_from_email', 'radar@greache.com');
    }

    public static function from_address() {
        $from = get_option('sig_from_email');
        if ($from && is_email($from)) {
            return $from;
        }
        return 'radar@greache.com';
    }

    public static function mail_failed($wp_error) {
        $msg = is_wp_error($wp_error) ? $wp_error->get_error_message() : 'wp_mail failed';
        update_option('sig_mail_last_error', $msg, false);
    }

    protected static $sending = false;

    public static function phpmailer_init($phpmailer) {
        if (!self::$sending) {
            return;
        }
        $from = self::from_address();
        if (!$from) {
            return;
        }
        try {
            $phpmailer->setFrom($from, 'Redline Radar', false);
        } catch (Exception $e) {
        }
        $phpmailer->Sender = $from;
    }

    public static function cron_schedules($schedules) {
        $schedules['sig_fifteen'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => 'Every 15 minutes (Redline Radar)',
        );
        return $schedules;
    }

    public static function next_send_timestamp() {
        $tz = new DateTimeZone('Australia/Brisbane');
        $now = new DateTime('now', $tz);
        $target = DateTime::createFromFormat('Y-m-d H:i:s', $now->format('Y-m-d') . ' 18:00:00', $tz);
        if (!$target) {
            return time() + HOUR_IN_SECONDS;
        }
        if ($now >= $target) {
            $target->modify('+1 day');
        }
        return $target->getTimestamp();
    }

    public static function schedule() {
        if (get_option('sig_cron_ver') !== '3') {
            wp_clear_scheduled_hook('sig_email_tick');
            wp_clear_scheduled_hook('sig_email_send');
            update_option('sig_cron_ver', '3', false);
        }
        if (!wp_next_scheduled('sig_email_tick')) {
            wp_schedule_event(time() + 120, 'sig_fifteen', 'sig_email_tick');
        }
        if (!wp_next_scheduled('sig_email_send')) {
            wp_schedule_event(self::next_send_timestamp(), 'daily', 'sig_email_send');
        }
    }

    public static function send_empty() {
        return (bool) get_option('sig_email_empty', false);
    }

    public static function paid_user_ids() {
        global $wpdb;
        $ids = array();
        $level = SIG_Access::paid_level_id();
        $table = $wpdb->prefix . 'pmpro_memberships_users';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $got = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$table} WHERE membership_id = %d AND status = 'active'",
                $level
            ));
            if ($got) {
                $ids = array_map('intval', $got);
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    public static function chart_href($symbol) {
        return SIG_Access::chart_url($symbol);
    }

    public static function size_position($capital, $price, $ema65) {
        $capital = (float) $capital;
        if ($capital < 1000) {
            $capital = 100000;
        }
        $price = (float) $price;
        $ema65 = (float) $ema65;
        $risk = $capital * 0.01;
        $max_pos = $capital * 0.25;
        $shares = 0;
        if ($price > $ema65 && ($price - $ema65) > 0) {
            $shares = (int) floor($risk / ($price - $ema65));
        }
        if ($shares > 0 && ($shares * $price) > $max_pos) {
            $shares = (int) floor($max_pos / $price);
        }
        $value = (int) (round(($shares * $price) / 10) * 10);
        return array($shares, $value);
    }

    public static function snapshot_symbols($kind) {
        $snap = SIG_Cache::snapshot();
        if (!is_array($snap)) {
            return array();
        }
        $key = $kind;
        if ($kind === 'sell' && empty($snap['sell']) && !empty($snap['exit'])) {
            $key = 'exit';
        }
        if (empty($snap[$key]) || !is_array($snap[$key])) {
            return array();
        }
        $out = array();
        foreach ($snap[$key] as $item) {
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
        return array_values(array_unique($out));
    }

    public static function decorate_rows($symbols, $capital) {
        $symbols = array_values(array_filter(array_map(array('SIG_Access', 'sanitize_symbol'), (array) $symbols)));
        if (!$symbols) {
            return array();
        }
        $idx = SIG_Cache::signal_map();
        $quotes = SIG_Cache::quotes_for($symbols);
        $rows = array();
        foreach ($symbols as $sym) {
            $q = isset($quotes[$sym]) ? $quotes[$sym] : array();
            $sig = isset($idx[$sym]) ? $idx[$sym]['signal'] : 'none';
            $price = null;
            if (isset($q['close']) && $q['close'] !== '' && $q['close'] !== null) {
                $price = (float) $q['close'];
            } elseif (isset($idx[$sym]['close']) && $idx[$sym]['close'] !== null) {
                $price = (float) $idx[$sym]['close'];
            }
            $ema65 = isset($q['ema65']) ? (float) $q['ema65'] : 0;
            $ret = isset($q['ret_6m']) ? $q['ret_6m'] : null;
            list($shares, $value) = self::size_position($capital, $price, $ema65);
            $rows[] = array(
                'symbol' => $sym,
                'signal' => $sig,
                'close'  => $price,
                'ema65'  => $ema65,
                'ret_6m' => $ret,
                'shares' => $shares,
                'value'  => $value,
                'href'   => self::chart_href($sym),
            );
        }
        return $rows;
    }

    public static function capital_for($user_id) {
        $c = get_user_meta((int) $user_id, 'sig_capital', true);
        if ($c === '' || $c === false || $c === null) {
            return 100000;
        }
        return (float) $c;
    }

    public static function payload_for_user($user_id) {
        $date = SIG_Cache::latest_trade_date();
        if (!$date) {
            $date = SIG_DB::latest_trade_date();
        }
        $capital = self::capital_for($user_id);
        $dream = SIG_Watchlist::get($user_id);
        return array(
            'date'      => $date,
            'capital'   => $capital,
            'dreamteam' => self::decorate_rows($dream, $capital),
            'buys'      => self::decorate_rows(self::snapshot_symbols('buy'), $capital),
            'sells'     => self::decorate_rows(self::snapshot_symbols('sell'), $capital),
            'watches'   => self::decorate_rows(self::snapshot_symbols('watch'), $capital),
        );
    }

    public static function maybe_fanout() {
        $tz = new DateTimeZone('Australia/Brisbane');
        $now = new DateTime('now', $tz);
        $dow = (int) $now->format('N');
        if ($dow > 5) {
            return;
        }
        $minutes = ((int) $now->format('G') * 60) + (int) $now->format('i');
        if ($minutes < (18 * 60)) {
            return;
        }
        $date = SIG_Cache::latest_trade_date();
        if (!$date) {
            return;
        }
        $today = $now->format('Y-m-d');
        if ($date !== $today) {
            return;
        }
        if (get_option('sig_email_sent_for') === $date) {
            return;
        }
        $result = self::fanout_internal(false);
        if (is_wp_error($result)) {
            return;
        }
        if (!empty($result['ok'])) {
            update_option('sig_email_sent_for', $date, false);
        }
    }

    public static function fanout($request = null) {
        $result = self::fanout_internal(false);
        if (is_wp_error($result)) {
            return $result;
        }
        if (!empty($result['as_of'])) {
            update_option('sig_email_sent_for', $result['as_of'], false);
        }
        return rest_ensure_response($result);
    }

    public static function fanout_internal($preview_only = false, $only_user_id = 0) {
        $date = SIG_Cache::latest_trade_date();
        if (!$date) {
            $date = SIG_DB::latest_trade_date();
        }
        if (!$date) {
            return new WP_Error('sig_no_signals', 'No snapshot date; refusing to mail.', array('status' => 409));
        }
        $user_ids = $only_user_id ? array((int) $only_user_id) : self::paid_user_ids();
        $sent = 0;
        $skipped = 0;
        foreach ($user_ids as $user_id) {
            if (!SIG_Access::user_is_paid($user_id) && !user_can($user_id, 'manage_options')) {
                $skipped++;
                continue;
            }
            $user = get_userdata($user_id);
            if (!$user || !is_email($user->user_email) || self::undeliverable($user->user_email)) {
                $skipped++;
                continue;
            }
            $payload = self::payload_for_user($user_id);
            $n = count($payload['dreamteam']) + count($payload['buys']) + count($payload['sells']) + count($payload['watches']);
            if ($n === 0 && !self::send_empty()) {
                $skipped++;
                continue;
            }
            $html = self::render($user, $payload);
            $subject = sprintf('Redline Radar · %s', date_i18n('j M Y', strtotime($payload['date'])));
            if ($preview_only) {
                return array('ok' => true, 'html' => $html, 'subject' => $subject, 'as_of' => $payload['date']);
            }
            $from = self::from_address();
            if (self::send_html($user->user_email, $subject, $html, $from)) {
                $sent++;
            } else {
                $skipped++;
            }
        }
        return array(
            'ok'      => true,
            'as_of'   => $date,
            'sent'    => $sent,
            'skipped' => $skipped,
        );
    }

    public static function render($user, $payload) {
        $dashboard = SIG_Access::dashboard_url();
        $chart_home = SIG_Access::chart_url();
        ob_start();
        $name = $user && !empty($user->display_name) ? $user->display_name : 'there';
        $date = $payload['date'];
        $dreamteam = $payload['dreamteam'];
        $buys = $payload['buys'];
        $sells = $payload['sells'];
        $watches = $payload['watches'];
        include SIG_PLUGIN_DIR . 'templates/email-signals.php';
        return ob_get_clean();
    }



    public static function undeliverable($email) {
        $email = strtolower(trim((string) $email));
        $dead = array('code@greache.com');
        return in_array($email, $dead, true);
    }

    public static function preview_user_id() {
        $uid = get_current_user_id();
        $me = get_userdata($uid);
        if ($me && is_email($me->user_email) && !self::undeliverable($me->user_email)) {
            return $uid;
        }
        $admin_email = get_option('admin_email');
        $admin = ($admin_email && is_email($admin_email)) ? get_user_by('email', $admin_email) : null;
        return $admin ? (int) $admin->ID : $uid;
    }

    public static function send_html($to, $subject, $html, $from = '') {
        if (!$from) {
            $from = self::from_address();
        }
        if (self::undeliverable($to)) {
            update_option('sig_mail_last_error', 'Skipped undeliverable address', false);
            return false;
        }
        $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\nFrom: Redline Radar <" . $from . ">\r\n";
        $ok = @mail($to, $subject, quoted_printable_encode($html), $headers);
        if (!$ok) {
            update_option('sig_mail_last_error', 'PHP mail() returned false', false);
        } else {
            delete_option('sig_mail_last_error');
        }
        return (bool) $ok;
    }

    public static function handle_preview() {
        if (!current_user_can('manage_options')) {
            wp_die('Nope');
        }
        check_admin_referer('sig_send_preview');
        $uid = self::preview_user_id();
        $result = self::fanout_internal(false, $uid);
        $url = admin_url('options-general.php?page=signal-membership');
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('sig_mail', 'err', $url));
            exit;
        }
        $sent = isset($result['sent']) ? (int) $result['sent'] : 0;
        $flag = ($sent > 0) ? 'preview' : 'fail';
        wp_safe_redirect(add_query_arg('sig_mail', $flag, $url));
        exit;
    }

    public static function handle_manual_fanout() {
        if (!current_user_can('manage_options')) {
            wp_die('Nope');
        }
        check_admin_referer('sig_send_fanout');
        $result = self::fanout_internal(false);
        $url = admin_url('options-general.php?page=signal-membership');
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('sig_mail', 'err', $url));
            exit;
        }
        wp_safe_redirect(add_query_arg(array(
            'sig_mail' => 'sent',
            'n' => isset($result['sent']) ? (int) $result['sent'] : 0,
        ), $url));
        exit;
    }
}
