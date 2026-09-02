<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Member-facing data source flag.
 * remote  (default): existing SIG_Cache / SIG_DB path. Accidental deploy is a no-op.
 * local: prefer plugin-written bars/signals after N matching weekdays, else CSV/redline.
 * shadow: write locally AND still read remote /redline/ for members.
 */
class SIG_Reads {
    const REMOTE = 'remote';
    const LOCAL  = 'local';
    const SHADOW = 'shadow';

    public static function mode() {
        $m = strtolower(trim((string) get_option('sig_data_source', self::REMOTE)));
        if ($m !== self::LOCAL && $m !== self::SHADOW) {
            return self::REMOTE;
        }
        return $m;
    }

    public static function sanitize_mode($posted) {
        $m = strtolower(trim((string) $posted));
        if ($m === self::LOCAL || $m === self::SHADOW || $m === self::REMOTE) {
            return $m;
        }
        return self::REMOTE;
    }

    /**
     * Members should read plugin-written data.
     */
    public static function prefer_local() {
        return self::mode() === self::LOCAL && SIG_Writer::ready_for_local();
    }

    /**
     * Extra tickers (not core 280) always come from the plugin store when present.
     */
    public static function use_store_for_symbol($symbol) {
        $symbol = SIG_Access::sanitize_symbol($symbol);
        if ($symbol === '') {
            return false;
        }
        if (!class_exists('SIG_Store') || !SIG_Store::has_bars($symbol)) {
            return false;
        }
        if (class_exists('SIG_Universe') && !SIG_Universe::is_core($symbol) && $symbol !== SIG_Universe::XJO) {
            return true;
        }
        if ($symbol === SIG_Universe::XJO) {
            return self::prefer_local();
        }
        return self::prefer_local();
    }
}
