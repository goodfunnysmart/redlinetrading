<?php
if (!defined('ABSPATH')) {
    exit;
}

class SIG_Access {
    public static function init() {
        add_filter('login_redirect', array(__CLASS__, 'login_redirect'), 20, 3);
        add_filter('registration_redirect', array(__CLASS__, 'registration_redirect'));
    }

    public static function paid_level_id() {
        $level = get_option('sig_pm_level', '1');
        return ($level === '' || $level === null) ? 1 : (int) $level;
    }

    public static function free_level_id() {
        return (int) get_option('sig_free_level', 0);
    }

    public static function level_id() {
        return self::paid_level_id();
    }

    public static function cron_key() {
        if (defined('SIG_CRON_KEY') && SIG_CRON_KEY !== '') {
            return SIG_CRON_KEY;
        }
        $key = (string) get_option('sig_cron_key', '');
        if ($key === '') {
            $key = wp_generate_password(32, false, false);
            update_option('sig_cron_key', $key, false);
        }
        return $key;
    }

    public static function paid_checkout_url() {
        $level = self::paid_level_id();
        if (function_exists('pmpro_url')) {
            $url = pmpro_url('checkout', '?level=' . $level);
            if ($url) {
                return $url;
            }
        }
        return home_url('/?page_id=15&level=' . $level);
    }

    public static function free_checkout_url() {
        $level = self::free_level_id();
        if ($level && function_exists('pmpro_url')) {
            $url = pmpro_url('checkout', '?level=' . $level);
            if ($url) {
                return $url;
            }
        }
        return wp_registration_url();
    }

    public static function login_url($redirect = '') {
        if (function_exists('pmpro_url')) {
            $url = pmpro_url('login');
            if ($url) {
                if ($redirect) {
                    $url = add_query_arg('redirect_to', $redirect, $url);
                }
                return $url;
            }
        }
        return $redirect ? wp_login_url($redirect) : wp_login_url();
    }

    public static function register_url() {
        $free = self::free_level_id();
        if ($free && function_exists('pmpro_url')) {
            $url = pmpro_url('checkout', '?level=' . $free);
            if ($url) {
                return $url;
            }
            return home_url('/?page_id=15&level=' . $free);
        }
        return wp_registration_url();
    }

    public static function chart_url($symbol = '') {
        $url = home_url('/?pagename=chart');
        $symbol = self::sanitize_symbol($symbol);
        if ($symbol !== '') {
            $url .= '&symbol=' . rawurlencode($symbol);
        }
        return apply_filters('sig_chart_url', $url);
    }

    public static function dashboard_url() {
        return apply_filters('sig_dashboard_url', home_url('/?pagename=dashboard'));
    }

    public static function user_is_paid($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        if (function_exists('pmpro_hasMembershipLevel')) {
            return (bool) pmpro_hasMembershipLevel(self::paid_level_id(), $user_id);
        }
        return true;
    }

    public static function user_can_chart($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        if (!function_exists('pmpro_hasMembershipLevel')) {
            return true;
        }
        $paid = self::paid_level_id();
        $free = self::free_level_id();
        $levels = array($paid);
        if ($free) {
            $levels[] = $free;
        }
        return (bool) pmpro_hasMembershipLevel($levels, $user_id);
    }

    public static function user_can_access($user_id) {
        $allowed = self::user_is_paid($user_id);
        return (bool) apply_filters('sig_user_can_access', $allowed, $user_id);
    }

    public static function current_is_paid() {
        return self::user_is_paid(get_current_user_id());
    }

    public static function current_can_chart() {
        return self::user_can_chart(get_current_user_id());
    }

    public static function current_can_access() {
        return self::user_can_access(get_current_user_id());
    }

    public static function rest_member_permission() {
        if (!is_user_logged_in()) {
            return new WP_Error('sig_login', 'Please log in.', array('status' => 401));
        }
        if (!self::current_can_access()) {
            return new WP_Error('sig_membership', 'Radar Member required.', array('status' => 403));
        }
        return true;
    }

    public static function rest_chart_permission() {
        if (!is_user_logged_in()) {
            return new WP_Error('sig_login', 'Please log in.', array('status' => 401));
        }
        if (!self::current_can_chart()) {
            return new WP_Error('sig_membership', 'Please register for free chart access.', array('status' => 403));
        }
        return true;
    }

    public static function rest_cron_permission($request) {
        $expected = self::cron_key();
        if ($expected === '') {
            return new WP_Error('sig_cron_unset', 'Cron key is not configured.', array('status' => 503));
        }
        $got = $request->get_header('x-signals-cron-key');
        if (!is_string($got) || !hash_equals($expected, $got)) {
            return new WP_Error('sig_cron_auth', 'Invalid cron key.', array('status' => 401));
        }
        return true;
    }

    public static function login_redirect($redirect, $requested, $user) {
        if (!($user instanceof WP_User)) {
            return $redirect;
        }
        if (self::user_is_paid($user->ID)) {
            return self::dashboard_url();
        }
        if (self::user_can_chart($user->ID)) {
            return self::chart_url();
        }
        return $redirect;
    }

    public static function registration_redirect($redirect) {
        return self::chart_url();
    }

    public static function sanitize_symbol($symbol) {
        $symbol = strtoupper(trim((string) $symbol));
        if ($symbol === '' || strlen($symbol) > 32) {
            return '';
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,24}(\.[A-Z]{1,6})?$/', $symbol)) {
            return '';
        }
        return $symbol;
    }
}
