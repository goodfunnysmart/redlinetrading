<?php
if (!defined('ABSPATH')) {
    exit;
}

class SIG_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register'));
    }

    public static function menu() {
        add_options_page(
            'Signal Membership',
            'Signal Membership',
            'manage_options',
            'signal-membership',
            array(__CLASS__, 'render')
        );
    }

    public static function register() {
        $opts = array(
            'sig_engine_db_host'     => 'localhost',
            'sig_engine_db_name'     => '',
            'sig_engine_db_user'     => '',
            'sig_engine_db_password' => '',
            'sig_pm_level'           => '1',
            'sig_free_level'         => '',
            'sig_cron_key'           => '',
            'sig_from_email'         => '',
            'sig_email_empty'        => 0,
        );
        foreach ($opts as $key => $default) {
            register_setting('sig_settings', $key);
        }
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>Signal Membership</h1>';
        if (isset($_GET['sig_mail'])) {
            $flag = sanitize_key(wp_unslash($_GET['sig_mail']));
            if ($flag === 'preview') {
                $me = wp_get_current_user();
                $to = ($me && $me->user_email) ? $me->user_email : 'your account address';
                echo '<div class="notice notice-success"><p>Preview handed to WordPress mail for <code>' . esc_html($to) . '</code>. Check inbox and spam.</p></div>';
            } elseif ($flag === 'fail') {
                $err = get_option('sig_mail_last_error', '');
                echo '<div class="notice notice-error"><p>WordPress did not send the preview to your account address.';
                if ($err) {
                    echo ' ' . esc_html($err);
                } else {
                    echo ' wp_mail returned false (often a missing mailbox for the From address, or PHP mail disabled).';
                }
                echo '</p></div>';
            } elseif ($flag === 'sent') {
                $n = isset($_GET['n']) ? (int) $_GET['n'] : 0;
                echo '<div class="notice notice-success"><p>Fan-out sent to ' . esc_html((string) $n) . ' Radar Member(s).</p></div>';
            } elseif ($flag === 'err') {
                echo '<div class="notice notice-error"><p>Could not send. Check that tonight\'s snapshot exists.</p></div>';
            }
        }
        echo '<p>WordPress reads the engine cache/DB. It never fetches EODHD. Free members get charts. Radar Member ($19/year) gets the dashboard and weekday email.</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('sig_settings');
        echo '<table class="form-table">';
        self::row('sig_engine_db_host', 'Engine DB host', 'text');
        self::row('sig_engine_db_name', 'Engine DB name', 'text');
        self::row('sig_engine_db_user', 'Engine DB user (SELECT only)', 'text');
        self::row('sig_engine_db_password', 'Engine DB password', 'password');
        self::row('sig_pm_level', 'Paid level ID (Radar Member)', 'text');
        self::row('sig_free_level', 'Free level ID', 'text');
        self::row('sig_cron_key', 'Cron shared secret (X-Signals-Cron-Key)', 'text');
        self::row('sig_from_email', 'From email (e.g. radar@greache.com)', 'email');
        echo '<tr><th>Empty mail</th><td>';
        echo '<label><input type="checkbox" name="sig_email_empty" value="1" ' . checked(1, (int) get_option('sig_email_empty'), false) . '> Send when there are no signals</label>';
        echo '</td></tr>';
        echo '</table>';
        submit_button();
        echo '</form>';

        echo '<hr><h2>Weekday radar email</h2>';
        echo '<p>Paid members get Dreamteam + BUY / SELL / WATCH at 6:00pm Brisbane, once tonight\'s snapshot is in. Tickers link to the membership charts. The full universe is not included.</p>';
        echo '<p>WordPress events: <code>sig_email_send</code> at 6:00pm Brisbane daily, plus <code>sig_email_tick</code> every 15 minutes as a catch-up after 6pm. Both still need WP-Cron to be woken (a page view, WP Crontrol, or a real cPanel cron hitting <code>wp-cron.php</code>).</p>';
        echo '<p>cPanel cron (recommended), every 15 minutes: <code>wget -q -O - "https://greache.com/redlinetrading/wp-cron.php?doing_wp_cron" &gt;/dev/null 2&gt;&amp;1</code></p>';
        $uid = SIG_Email::preview_user_id();
        $dest = get_userdata($uid);
        $to = ($dest && $dest->user_email) ? $dest->user_email : '';
        echo '<p>Preview goes to <code>' . esc_html($to) . '</code> from <code>' . esc_html(SIG_Email::from_address()) . '</code>.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field('sig_send_preview');
        echo '<input type="hidden" name="action" value="sig_send_preview" />';
        echo '<button class="button">Send preview to me</button>';
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
        wp_nonce_field('sig_send_fanout');
        echo '<input type="hidden" name="action" value="sig_send_fanout" />';
        echo '<button class="button button-primary">Email all Radar Members now</button>';
        echo '</form>';

        SIG_Dreamteam_Admin::notice();
        SIG_Dreamteam_Admin::render();

        echo '<hr><h2>Import radar stars</h2>';
        echo '<form method="post">';
        wp_nonce_field('sig_import_favorites');
        echo '<p><button class="button" type="submit" name="sig_import_favorites" value="1">Import favorites into my watchlist</button></p>';
        echo '</form></div>';
    }

    protected static function row($key, $label, $type) {
        $val = esc_attr(get_option($key, ''));
        echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input class="regular-text" type="' . esc_attr($type) . '" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . $val . '" autocomplete="off" />';
        echo '</td></tr>';
    }
}
