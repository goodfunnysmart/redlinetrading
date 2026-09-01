<?php
if (!defined('ABSPATH')) {
    exit;
}

class SIG_REST {
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register'));
    }

    public static function register() {
        $ns = 'signals/v1';

        register_rest_route($ns, '/me/signals', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'me_signals'),
            'permission_callback' => array('SIG_Access', 'rest_member_permission'),
        ));
        register_rest_route($ns, '/me/profile', array(
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'profile_get'),
                'permission_callback' => array('SIG_Access', 'rest_member_permission'),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'profile_post'),
                'permission_callback' => array('SIG_Access', 'rest_member_permission'),
            ),
        ));
        register_rest_route($ns, '/watchlist', array(
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'watchlist_get'),
                'permission_callback' => array('SIG_Access', 'rest_member_permission'),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'watchlist_post'),
                'permission_callback' => array('SIG_Access', 'rest_member_permission'),
            ),
        ));
        register_rest_route($ns, '/watchlist/(?P<symbol>[A-Za-z0-9._-]+)', array(
            'methods'             => 'DELETE',
            'callback'            => array(__CLASS__, 'watchlist_delete'),
            'permission_callback' => array('SIG_Access', 'rest_member_permission'),
        ));
        register_rest_route($ns, '/bars/(?P<symbol>[A-Za-z0-9._-]+)', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'bars'),
            'permission_callback' => array('SIG_Access', 'rest_chart_permission'),
        ));
        register_rest_route($ns, '/universe', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'universe'),
            'permission_callback' => array('SIG_Access', 'rest_chart_permission'),
        ));
        register_rest_route($ns, '/fanout', array(
            'methods'             => 'POST',
            'callback'            => array('SIG_Email', 'fanout'),
            'permission_callback' => array('SIG_Access', 'rest_cron_permission'),
        ));
    }

    protected static function rate_limit($bucket, $max = 60) {
        $uid = get_current_user_id();
        $key = 'sig_rl_' . $bucket . '_' . $uid;
        $n = (int) get_transient($key);
        if ($n >= $max) {
            return new WP_Error('sig_rate', 'Too many requests.', array('status' => 429));
        }
        set_transient($key, $n + 1, MINUTE_IN_SECONDS);
        return true;
    }

    public static function me_signals($request = null) {
        $rl = self::rate_limit('signals', 60);
        if (is_wp_error($rl)) {
            return $rl;
        }
        $view = 'dreamteam';
        if ($request instanceof WP_REST_Request) {
            $view = strtolower(trim((string) $request->get_param('view')));
        }
        $allowed = array('dreamteam', 'buy', 'sell', 'watch', 'all');
        if (!in_array($view, $allowed, true)) {
            $view = 'dreamteam';
        }

        $uid = get_current_user_id();
        $watchlist = SIG_Watchlist::get($uid);
        $date = SIG_DB::latest_trade_date();
        $symbols = SIG_Cache::symbols_for_view($view, $watchlist);

        $db_rows = SIG_DB::signals_for($symbols, $date);
        $db_by = array();
        if (is_array($db_rows)) {
            foreach ($db_rows as $r) {
                if (isset($r['symbol'])) {
                    $db_by[$r['symbol']] = $r;
                }
            }
        }
        $cache_rows = SIG_Cache::signals_for($symbols, $date);
        $cache_by = array();
        foreach ($cache_rows as $r) {
            $cache_by[$r['symbol']] = $r;
        }
        $quotes = SIG_Cache::quotes_for($symbols);

        $out = array();
        foreach ($symbols as $sym) {
            $row = isset($cache_by[$sym]) ? $cache_by[$sym] : (isset($db_by[$sym]) ? $db_by[$sym] : array(
                'symbol' => $sym,
                'signal' => 'none',
                'close'  => null,
                'ema65'  => null,
                'note'   => '',
            ));
            $q = isset($quotes[$sym]) ? $quotes[$sym] : array();
            $close = null;
            if (isset($q['close']) && $q['close'] !== null) {
                $close = $q['close'];
            } elseif (isset($row['close']) && $row['close'] !== null && $row['close'] !== '') {
                $close = (float) $row['close'];
            }
            $ema65 = null;
            if (isset($q['ema65']) && $q['ema65'] !== null) {
                $ema65 = $q['ema65'];
            } elseif (isset($row['ema65']) && $row['ema65'] !== null && $row['ema65'] !== '') {
                $ema65 = (float) $row['ema65'];
            }
            $signal = isset($row['signal']) ? $row['signal'] : 'none';
            $note = '';
            if (isset($row['note']) && $row['note'] !== '') {
                $note = $row['note'];
            } else {
                $note = SIG_Signals::note($signal);
            }
            $out[] = array(
                'symbol'        => $sym,
                'signal'        => $signal,
                'close'         => $close,
                'ema65'         => $ema65,
                'ret_6m'        => isset($q['ret_6m']) ? $q['ret_6m'] : null,
                'ret_1d'        => isset($q['ret_1d']) ? $q['ret_1d'] : null,
                'under_redline' => SIG_Signals::is_under_redline($close, $ema65),
                'note'          => $note,
            );
        }
        usort($out, array('SIG_Cache', 'sort_signal_rows'));

        $counts = SIG_Cache::universe_counts();
        $counts['dreamteam'] = count($watchlist);

        return rest_ensure_response(array(
            'as_of'     => $date,
            'watchlist' => $watchlist,
            'view'      => $view,
            'counts'    => $counts,
            'market'    => SIG_Cache::market_status(),
            'signals'   => $out,
        ));
    }

    public static function watchlist_get() {
        return rest_ensure_response(array(
            'watchlist' => SIG_Watchlist::get(get_current_user_id()),
        ));
    }

    public static function watchlist_post($request) {
        $rl = self::rate_limit('wl_write', 20);
        if (is_wp_error($rl)) {
            return $rl;
        }
        $symbol = SIG_Access::sanitize_symbol($request->get_param('symbol'));
        $ok = SIG_Watchlist::add(get_current_user_id(), $symbol);
        if (is_wp_error($ok)) {
            $ok->add_data(array('status' => 400));
            return $ok;
        }
        return self::watchlist_get();
    }

    public static function watchlist_delete($request) {
        $symbol = SIG_Access::sanitize_symbol($request->get_param('symbol'));
        SIG_Watchlist::remove(get_current_user_id(), $symbol);
        return self::watchlist_get();
    }

    public static function universe() {
        return rest_ensure_response(array(
            'symbols' => SIG_Cache::universe(),
        ));
    }

    public static function bars($request) {
        $rl = self::rate_limit('bars', 30);
        if (is_wp_error($rl)) {
            return $rl;
        }
        $symbol = SIG_Access::sanitize_symbol($request->get_param('symbol'));
        if ($symbol === '') {
            return new WP_Error('sig_symbol', 'Invalid symbol.', array('status' => 400));
        }

        $tz = new DateTimeZone('Australia/Brisbane');
        $day = (new DateTime('now', $tz))->format('Y-m-d');
        $mtime = 0;
        $csv = rtrim(SIG_Cache::cache_dir(), '/') . '/' . SIG_Cache::symbol_to_filename($symbol);
        if (is_readable($csv)) {
            $mtime = (int) filemtime($csv);
        }
        $cache_key = 'sig_bars_v2_' . md5($symbol . '|' . $day . '|' . $mtime);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return rest_ensure_response($cached);
        }

        $rows = SIG_DB::bars($symbol);
        if (is_wp_error($rows)) {
            return $rows;
        }
        if (!$rows) {
            return new WP_Error('sig_empty', 'No bars for that symbol.', array('status' => 404));
        }

        $engine = SIG_PLUGIN_DIR . '../../../engine/lib/Ema.php';
        // Plugin lives in wp-content/plugins/; engine may be elsewhere. Bundle a copy of EMA in the plugin.
        require_once SIG_PLUGIN_DIR . 'includes/class-ema.php';

        $closes = array();
        foreach ($rows as $r) {
            $closes[] = (float) $r['close'];
        }
        $ribbon = SIG_Ema::ribbon($closes);
        $candles = array();
        $emas = array(
            15 => array(), 25 => array(), 36 => array(),
            45 => array(), 55 => array(), 65 => array(),
        );
        foreach ($rows as $i => $r) {
            $candles[] = array(
                'time'   => $r['trade_date'],
                'open'   => (float) $r['open'],
                'high'   => (float) $r['high'],
                'low'    => (float) $r['low'],
                'close'  => (float) $r['close'],
                'volume' => isset($r['volume']) ? (int) $r['volume'] : 0,
            );
            foreach ($emas as $p => $ignore) {
                if ($ribbon[$p] && $ribbon[$p][$i] !== null) {
                    $emas[$p][] = array('time' => $r['trade_date'], 'value' => (float) $ribbon[$p][$i]);
                }
            }
        }

        $payload = array(
            'symbol'  => $symbol,
            'candles' => $candles,
            'ema'     => $emas,
        );
        set_transient($cache_key, $payload, DAY_IN_SECONDS);
        return rest_ensure_response($payload);
    }

    public static function profile_payload($user_id = 0) {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('sig_user', 'User not found.', array('status' => 404));
        }
        $capital = get_user_meta($user_id, 'sig_capital', true);
        if ($capital === '' || $capital === false || $capital === null) {
            $capital = 100000;
        }
        return array(
            'display_name' => $user->display_name,
            'email'        => $user->user_email,
            'phone'        => (string) get_user_meta($user_id, 'sig_phone', true),
            'capital'      => (float) $capital,
        );
    }

    public static function profile_get() {
        $payload = self::profile_payload();
        if (is_wp_error($payload)) {
            return $payload;
        }
        return rest_ensure_response($payload);
    }

    public static function profile_post($request) {
        $rl = self::rate_limit('profile', 20);
        if (is_wp_error($rl)) {
            return $rl;
        }
        $uid = get_current_user_id();
        $params = $request->get_json_params();
        if (!is_array($params) || !$params) {
            $params = $request->get_params();
        }
        if (!is_array($params)) {
            $params = array();
        }

        $update = array('ID' => $uid);
        if (array_key_exists('display_name', $params)) {
            $name = sanitize_text_field($params['display_name']);
            if ($name !== '') {
                $update['display_name'] = $name;
            }
        }
        if (array_key_exists('email', $params)) {
            $email = sanitize_email($params['email']);
            if ($email === '' || !is_email($email)) {
                return new WP_Error('sig_email', 'Invalid email.', array('status' => 400));
            }
            $update['user_email'] = $email;
        }
        if (count($update) > 1) {
            $ok = wp_update_user($update);
            if (is_wp_error($ok)) {
                $ok->add_data(array('status' => 400));
                return $ok;
            }
        }
        if (array_key_exists('phone', $params)) {
            $phone = sanitize_text_field($params['phone']);
            if (strlen($phone) > 32) {
                $phone = substr($phone, 0, 32);
            }
            update_user_meta($uid, 'sig_phone', $phone);
        }
        if (array_key_exists('capital', $params)) {
            $capital = (float) $params['capital'];
            if ($capital < 1000 || $capital > 100000000) {
                return new WP_Error('sig_capital', 'Capital must be between 1000 and 100000000.', array('status' => 400));
            }
            update_user_meta($uid, 'sig_capital', $capital);
        }
        return self::profile_get();
    }
}
