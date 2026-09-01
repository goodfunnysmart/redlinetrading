<?php
if (!defined('ABSPATH')) {
    exit;
}

class SIG_Pmpro_Fields {
    public static function init() {
        add_action('init', array(__CLASS__, 'register_fields'), 20);
        add_action('user_register', array(__CLASS__, 'seed_defaults'));
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
                'Trading capital drives dashboard position sizing (1% risk to EMA65, max 25% of capital).'
            );
        }
        $capital = new PMPro_Field(
            'sig_capital',
            'text',
            array(
                'label'           => 'Default Trading Capital',
                'size'            => 20,
                'profile'         => true,
                'required'        => false,
                'memberslistcsv'  => true,
                'hint'            => 'Default 100000. Used for share sizing on the dashboard.',
            )
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
