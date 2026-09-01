<?php
if (!defined('ABSPATH')) {
    exit;
}

class SIG_Pmpro_Fields {
    public static function init() {
        add_action('init', array(__CLASS__, 'register_fields'), 20);
        add_action('user_register', array(__CLASS__, 'seed_defaults'));
        add_filter('the_title', array(__CLASS__, 'free_checkout_title'), 20, 2);
        add_filter('document_title_parts', array(__CLASS__, 'free_document_title'));
        add_filter('pmpro_level_cost_text', array(__CLASS__, 'free_cost_text'), 20, 2);
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_filter('pmpro_include_payment_information_fields', array(__CLASS__, 'skip_empty_check_box'));
        register_meta('user', 'sig_capital', array(
            'type'              => 'number',
            'single'            => true,
            'sanitize_callback' => array(__CLASS__, 'sanitize_capital'),
            'show_in_rest'      => false,
            'auth_callback'     => array(__CLASS__, 'meta_auth'),
        ));
        register_meta('user', 'sig_phone', array(
            'type'              => 'string',
            'single'            => true,
            'sanitize_callback' => array(__CLASS__, 'sanitize_phone'),
            'show_in_rest'      => false,
            'auth_callback'     => array(__CLASS__, 'meta_auth'),
        ));
    }

    public static function checkout_level_id() {
        if (isset($_REQUEST['level'])) {
            return (int) $_REQUEST['level'];
        }
        if (isset($_REQUEST['pmpro_level'])) {
            return (int) $_REQUEST['pmpro_level'];
        }
        global $pmpro_level;
        if (!empty($pmpro_level) && !empty($pmpro_level->id)) {
            return (int) $pmpro_level->id;
        }
        return 0;
    }

    public static function is_free_checkout() {
        $free = SIG_Access::free_level_id();
        return $free > 0 && self::checkout_level_id() === $free;
    }

    public static function skip_empty_check_box($include) {
        if (function_exists('pmpro_getGateway') && pmpro_getGateway() === 'check') {
            return false;
        }
        return $include;
    }

    public static function is_paid_checkout() {
        $paid = SIG_Access::paid_level_id();
        return $paid > 0 && self::checkout_level_id() === $paid;
    }

    public static function body_class($classes) {
        if (self::is_free_checkout()) {
            $classes[] = 'sig-checkout-free';
        }
        if (self::is_paid_checkout()) {
            $classes[] = 'sig-checkout-paid';
        }
        return $classes;
    }

    public static function free_checkout_title($title, $post_id = 0) {
        if (is_admin() || !self::is_free_checkout()) {
            return $title;
        }
        if (is_string($title) && stripos($title, 'Membership Checkout') !== false) {
            return 'Create a free chart login';
        }
        return $title;
    }

    public static function free_document_title($parts) {
        if (!self::is_free_checkout() || !is_array($parts)) {
            return $parts;
        }
        if (!empty($parts['title']) && stripos($parts['title'], 'Membership Checkout') !== false) {
            $parts['title'] = 'Create a free chart login';
        }
        return $parts;
    }

    public static function free_cost_text($cost, $level = null) {
        if (!self::is_free_checkout()) {
            return $cost;
        }
        return '';
    }

    public static function meta_auth($allowed, $meta_key, $object_id, $user_id, $cap, $caps) {
        if (!$user_id) {
            return false;
        }
        if ((int) $user_id === (int) $object_id) {
            return true;
        }
        return user_can($user_id, 'edit_users');
    }

    public static function sanitize_capital($value) {
        if (is_string($value)) {
            $value = str_replace(array('$', ',', ' '), '', $value);
        }
        $v = (float) $value;
        if ($v < 1000 || $v > 100000000) {
            return 100000.0;
        }
        return $v;
    }

    public static function sanitize_phone($value) {
        $phone = sanitize_text_field((string) $value);
        if (strlen($phone) > 32) {
            $phone = substr($phone, 0, 32);
        }
        return $phone;
    }

    public static function seed_defaults($user_id) {
        $existing = get_user_meta((int) $user_id, 'sig_capital', true);
        if ($existing === '' || $existing === false || $existing === null) {
            update_user_meta((int) $user_id, 'sig_capital', 100000);
        }
    }

    public static function register_fields() {
        if (!class_exists('PMPro_Field') || !function_exists('pmpro_add_user_field')) {
            return;
        }
        if (function_exists('pmpro_add_field_group')) {
            pmpro_add_field_group(
                'radar',
                'Additional Information',
                ''
            );
        }
        $paid = SIG_Access::paid_level_id();
        $capital_args = array(
            'label'          => 'Default Trading Capital',
            'size'           => 20,
            'profile'        => true,
            'required'       => false,
            'memberslistcsv' => true,
            'hint'           => 'Trading capital drives dashboard position sizing (1% risk to EMA65, max 25% of capital). Default 100000.',
        );
        if ($paid) {
            $capital_args['levels'] = array($paid);
        }
        $capital = new PMPro_Field(
            'sig_capital',
            'text',
            $capital_args
        );
        $phone = new PMPro_Field(
            'sig_phone',
            'text',
            array(
                'label'          => 'Phone',
                'size'           => 20,
                'profile'        => true,
                'required'       => false,
                'memberslistcsv' => true,
            )
        );
        pmpro_add_user_field('radar', $capital);
        pmpro_add_user_field('radar', $phone);
    }
}
