<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * EODHD client. Called only from the writer / watchlist-add validation.
 * Never from member page load or REST chart/dashboard GETs.
 * Key: wp-config SIG_EODHD_API_KEY, or encrypted option. Never echoed.
 */
class SIG_EODHD {
    const OPTION = 'sig_eodhd_api_key_enc';
    const CIPHER_PREFIX = 'sig1:';

    public static function sanitize_posted_key($posted) {
        $posted = trim((string) $posted);
        if ($posted === '' || preg_match('/^\*+$/', $posted) || $posted === '********') {
            return (string) get_option(self::OPTION, '');
        }
        if (strpos($posted, self::CIPHER_PREFIX) === 0) {
            return $posted;
        }
        return self::encrypt($posted);
    }

    public static function has_key() {
        $k = self::api_key();
        return ($k !== '');
    }

    public static function key_is_constant() {
        return defined('SIG_EODHD_API_KEY') && SIG_EODHD_API_KEY !== '';
    }

    public static function key_saved() {
        if (self::key_is_constant()) {
            return true;
        }
        return ((string) get_option(self::OPTION, '') !== '');
    }

    /**
     * @return string empty if unset. Never log or print this.
     */
    public static function api_key() {
        if (defined('SIG_EODHD_API_KEY') && SIG_EODHD_API_KEY !== '') {
            return (string) SIG_EODHD_API_KEY;
        }
        $enc = (string) get_option(self::OPTION, '');
        if ($enc === '') {
            return '';
        }
        $plain = self::decrypt($enc);
        return is_string($plain) ? $plain : '';
    }

    protected static function secret() {
        $a = defined('AUTH_KEY') ? AUTH_KEY : 'sig';
        $b = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'eodhd';
        return hash('sha256', $a . '|' . $b . '|sig-eodhd', true);
    }

    public static function encrypt($plain) {
        $plain = (string) $plain;
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(16);
        $raw = openssl_encrypt($plain, 'AES-256-CBC', self::secret(), OPENSSL_RAW_DATA, $iv);
        if ($raw === false) {
            return '';
        }
        return self::CIPHER_PREFIX . base64_encode($iv . $raw);
    }

    public static function decrypt($blob) {
        $blob = (string) $blob;
        if ($blob === '') {
            return '';
        }
        if (strpos($blob, self::CIPHER_PREFIX) === 0) {
            $blob = substr($blob, strlen(self::CIPHER_PREFIX));
        }
        $bin = base64_decode($blob, true);
        if ($bin === false || strlen($bin) < 17) {
            return '';
        }
        $iv = substr($bin, 0, 16);
        $raw = substr($bin, 16);
        $plain = openssl_decrypt($raw, 'AES-256-CBC', self::secret(), OPENSSL_RAW_DATA, $iv);
        return ($plain === false) ? '' : $plain;
    }

    public static function from_date() {
        $tz = new DateTimeZone('Australia/Brisbane');
        $d = new DateTime('now', $tz);
        $d->modify('-400 days');
        return $d->format('Y-m-d');
    }

    /**
     * Fetch EOD CSV. Returns raw CSV string or WP_Error.
     * Caller must not log the URL (contains token).
     */
    public static function fetch_csv($ticker, $from = '') {
        $ticker = SIG_Access::sanitize_symbol($ticker);
        if ($ticker === '') {
            return new WP_Error('sig_eodhd_ticker', 'Invalid ticker.');
        }
        $token = self::api_key();
        if ($token === '') {
            return new WP_Error('sig_eodhd_key', 'EODHD API key is not set.');
        }
        if ($from === '') {
            $from = self::from_date();
        }
        $url = 'https://eodhd.com/api/eod/' . rawurlencode($ticker)
            . '?api_token=' . rawurlencode($token)
            . '&fmt=csv&from=' . rawurlencode($from);
        $res = wp_remote_get($url, array(
            'timeout'     => 25,
            'sslverify'   => true,
            'redirection' => 3,
            'headers'     => array('Accept' => 'text/csv,*/*'),
        ));
        if (is_wp_error($res)) {
            return new WP_Error('sig_eodhd_http', 'EODHD request failed for ' . $ticker);
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = (string) wp_remote_retrieve_body($res);
        if ($code !== 200) {
            return new WP_Error('sig_eodhd_http', 'EODHD HTTP ' . $code . ' for ' . $ticker);
        }
        if ($body === '' || stripos($body, 'Date') === false) {
            return new WP_Error('sig_eodhd_empty', 'No EOD rows for ' . $ticker);
        }
        if (stripos($body, 'Unauthorized') !== false || stripos($body, 'Invalid API') !== false) {
            return new WP_Error('sig_eodhd_auth', 'EODHD rejected the API key.');
        }
        return $body;
    }

    /**
     * Cheap existence check (short window). Used on watchlist add only.
     */
    public static function symbol_exists($ticker) {
        $tz = new DateTimeZone('Australia/Brisbane');
        $from = (new DateTime('now', $tz))->modify('-40 days')->format('Y-m-d');
        $csv = self::fetch_csv($ticker, $from);
        if (is_wp_error($csv)) {
            return false;
        }
        $rows = SIG_Cache::parse_csv($csv);
        return !empty($rows);
    }
}
