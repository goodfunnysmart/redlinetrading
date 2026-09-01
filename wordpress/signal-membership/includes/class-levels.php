<?php
if (!defined('ABSPATH')) {
    exit;
}

class SIG_Levels {
    public static function init() {
        add_action('init', array(__CLASS__, 'ensure'), 15);
        add_action('user_register', array(__CLASS__, 'assign_free'), 20);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'pmpro_membership_levels';
    }

    public static function ensure() {
        global $wpdb;
        $table = self::table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        $paid_id = 1;
        $paid = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $paid_id));
        if ($paid) {
            $wpdb->update(
                $table,
                array(
                    'name'            => 'Radar Member',
                    'description'     => 'Full dashboard, Dreamteam, and the weekday radar email. $19 AUD per year.',
                    'initial_payment' => 19,
                    'billing_amount'  => 19,
                    'cycle_number'    => 1,
                    'cycle_period'    => 'Year',
                    'allow_signups'   => 1,
                ),
                array('id' => $paid_id)
            );
        }
        update_option('sig_pm_level', $paid_id);

        $free_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE name = %s", 'Free'));
        if (!$free_id) {
            $wpdb->insert(
                $table,
                array(
                    'name'              => 'Free',
                    'description'       => 'Interactive EMA ribbon charts. Dashboard and nightly email are Radar Member only.',
                    'confirmation'      => '',
                    'initial_payment'   => 0,
                    'billing_amount'    => 0,
                    'cycle_number'      => 0,
                    'cycle_period'      => '0',
                    'billing_limit'     => 0,
                    'trial_amount'      => 0,
                    'trial_limit'       => 0,
                    'expiration_number' => 0,
                    'expiration_period' => '0',
                    'allow_signups'     => 1,
                )
            );
            $free_id = (int) $wpdb->insert_id;
        } else {
            $wpdb->update(
                $table,
                array(
                    'description'     => 'Interactive EMA ribbon charts. Dashboard and nightly email are Radar Member only.',
                    'initial_payment' => 0,
                    'billing_amount'  => 0,
                    'allow_signups'   => 1,
                ),
                array('id' => $free_id)
            );
        }
        if ($free_id) {
            update_option('sig_free_level', $free_id);
        }

        if (!(string) get_option('sig_cron_key', '')) {
            update_option('sig_cron_key', wp_generate_password(32, false, false), false);
        }
        if (!(string) get_option('sig_from_email', '')) {
            update_option('sig_from_email', 'radar@greache.com');
        }
    }

    public static function assign_free($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !function_exists('pmpro_changeMembershipLevel')) {
            return;
        }
        if (function_exists('pmpro_hasMembershipLevel') && pmpro_hasMembershipLevel(null, $user_id)) {
            return;
        }
        $free = SIG_Access::free_level_id();
        if ($free) {
            pmpro_changeMembershipLevel($free, $user_id);
        }
    }
}
