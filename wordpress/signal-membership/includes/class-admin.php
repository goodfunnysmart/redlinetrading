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
        register_setting('sig_settings', 'sig_data_source', array(
            'sanitize_callback' => array('SIG_Reads', 'sanitize_mode'),
            'default'           => 'remote',
        ));
        register_setting('sig_settings', 'sig_writer_enabled', array(
            'sanitize_callback' => 'intval',
            'default'           => 0,
        ));
        register_setting('sig_settings', 'sig_eodhd_api_key_enc', array(
            'sanitize_callback' => array('SIG_EODHD', 'sanitize_posted_key'),
            'default'           => '',
        ));
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
        if (isset($_GET['sig_writer'])) {
            $w = sanitize_key(wp_unslash($_GET['sig_writer']));
            if ($w === 'batch') {
                echo '<div class="notice notice-success"><p>Writer batch ran. Check last-fetch and the log below.</p></div>';
            } elseif ($w === 'compare') {
                echo '<div class="notice notice-success"><p>Compared local snapshot counts to live /redline/.</p></div>';
            } elseif ($w === 'fail') {
                echo '<div class="notice notice-error"><p>Writer batch did not run. Turn the writer on and save an API key first.</p></div>';
            }
        }

        echo '<p>WordPress can fetch EODHD and write bars, but member pages and chart/dashboard REST never call EODHD. Data source defaults to <strong>remote</strong> so a deploy does not change what members see until you switch it.</p>';
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

        echo '<h2>EODHD writer (Stage 2)</h2>';
        echo '<table class="form-table">';
        $mode = SIG_Reads::mode();
        echo '<tr><th><label for="sig_data_source">Data source</label></th><td>';
        echo '<select name="sig_data_source" id="sig_data_source">';
        foreach (array(
            'remote' => 'remote — members still read /redline/ (default, safe)',
            'local'  => 'local — prefer plugin-written data after 3 matching weekdays',
            'shadow' => 'shadow — write locally, members still read /redline/; compare counts in admin',
        ) as $val => $label) {
            echo '<option value="' . esc_attr($val) . '" ' . selected($mode, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Leave on remote until you have checked a few weekday runs. local keeps CSV /redline/ fallback until the writer has 3 matching weekdays.</p>';
        echo '</td></tr>';

        echo '<tr><th>Writer</th><td>';
        echo '<input type="hidden" name="sig_writer_enabled" value="0" />';
        echo '<label><input type="checkbox" name="sig_writer_enabled" value="1" ' . checked(1, SIG_Writer::enabled() ? 1 : 0, false) . '> Fetch EODHD on WP-Cron (weekdays after 16:45 Brisbane, batches of 25)</label>';
        echo '</td></tr>';

        echo '<tr><th><label for="sig_eodhd_api_key_enc">EODHD API key</label></th><td>';
        if (SIG_EODHD::key_is_constant()) {
            echo '<input class="regular-text" type="password" value="********" disabled="disabled" autocomplete="off" />';
            echo '<p class="description">Using <code>SIG_EODHD_API_KEY</code> from wp-config.php. The key is never shown.</p>';
            echo '<input type="hidden" name="sig_eodhd_api_key_enc" value="" />';
        } else {
            $ph = SIG_EODHD::key_saved() ? '********' : '';
            echo '<input class="regular-text" type="password" id="sig_eodhd_api_key_enc" name="sig_eodhd_api_key_enc" value="" placeholder="' . esc_attr($ph) . '" autocomplete="new-password" />';
            echo '<p class="description">Saved encrypted. Leave blank to keep the current key. Never appears in REST, HTML, git, or chart payloads. You can also set <code>SIG_EODHD_API_KEY</code> in wp-config.php.</p>';
        }
        echo '</td></tr>';
        echo '</table>';
        submit_button();
        echo '</form>';

        self::render_writer_status();

        echo '<hr><h2>Weekday radar email</h2>';
        echo '<p>Paid members get Dreamteam + BUY / SELL / WATCH at 6:00pm Brisbane, once tonight\'s snapshot is in. Tickers link to the membership charts. The full universe is not included. Snapshot and those BUY/SELL/WATCH lists stay on the core 280.</p>';
        echo '<p>WordPress events: <code>sig_email_send</code> at 6:00pm Brisbane daily, plus <code>sig_email_tick</code> every 15 minutes as a catch-up after 6pm. Writer event: <code>sig_writer_tick</code> on the same 15-minute schedule (weekdays after 16:45 Brisbane). Both still need WP-Cron to be woken by a real cPanel cron hitting <code>wp-cron.php</code>.</p>';
        echo '<p>cPanel cron (already in use for the 18:00 email), every 15 minutes: <code>wget -q -O - "https://greache.com/redlinetrading/wp-cron.php?doing_wp_cron" &gt;/dev/null 2&gt;&amp;1</code></p>';
        echo '<p>Optional extra weekday line around 17:15 if you want a dedicated kick: <code>15 17 * * 1-5 wget -q -O - "https://greache.com/redlinetrading/wp-cron.php?doing_wp_cron" &gt;/dev/null 2&gt;&amp;1</code> — not required if */15 is already running.</p>';
        echo '<div class="notice notice-warning inline"><p><strong>Do not double-send.</strong> Plugin Dreamteam is the only member email. Park the engine mailer To: <code>mail@greache.com</code> / Steve (comment it out or stop that cron) when you are ready — do not leave both firing. Leave the live /redline/ price cron running until you explicitly say to stop it. This screen does not edit the host.</p></div>';
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

    protected static function render_writer_status() {
        echo '<h2>Writer status</h2>';
        $core_n = class_exists('SIG_Universe') ? count(SIG_Universe::core()) : 0;
        echo '<p>Core universe: <strong>' . esc_html((string) $core_n) . '</strong> (268 .AU + 12 site extras). XJO.INDX is fetched for the badge and is not part of processed. Per member: up to 30 extras not already in that 280, via the existing Add field. Extras are not added to snapshot processed.</p>';
        $last = SIG_Writer::last_fetch();
        echo '<p>Last fetch (Brisbane): <code>' . esc_html($last !== '' ? $last : 'never') . '</code></p>';
        echo '<p>Matching weekdays stored: <strong>' . esc_html((string) SIG_Writer::matching_weekdays()) . '</strong> / ' . esc_html((string) SIG_Writer::ready_n()) . ' needed before local reads prefer the plugin DB.</p>';
        $snap = SIG_Writer::snapshot();
        $counts = SIG_Writer::snapshot_counts($snap);
        echo '<p>Local snapshot: buy ' . esc_html((string) $counts['buy'])
            . ' · exit ' . esc_html((string) $counts['exit'])
            . ' · watch ' . esc_html((string) $counts['watch'])
            . ' · processed ' . esc_html((string) $counts['processed'])
            . ' / ' . esc_html((string) $core_n) . '</p>';

        $cmp = SIG_Writer::shadow_compare();
        if ($cmp) {
            $lm = isset($cmp['local']) ? $cmp['local'] : array();
            $rm = isset($cmp['remote']) ? $cmp['remote'] : array();
            echo '<p>Last shadow compare (' . esc_html(isset($cmp['at']) ? $cmp['at'] : '') . '): ';
            echo !empty($cmp['match']) ? 'counts match' : 'counts differ';
            echo ' — local buy/exit/watch ';
            echo esc_html((isset($lm['buy']) ? $lm['buy'] : '0') . '/' . (isset($lm['exit']) ? $lm['exit'] : '0') . '/' . (isset($lm['watch']) ? $lm['watch'] : '0'));
            echo ' vs remote ';
            echo esc_html((isset($rm['buy']) ? $rm['buy'] : '0') . '/' . (isset($rm['exit']) ? $rm['exit'] : '0') . '/' . (isset($rm['watch']) ? $rm['watch'] : '0'));
            echo '. A mismatch does not take the site down.</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field('sig_writer_batch');
        echo '<input type="hidden" name="action" value="sig_writer_batch" />';
        echo '<button class="button">Run one writer batch now</button>';
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
        wp_nonce_field('sig_writer_compare');
        echo '<input type="hidden" name="action" value="sig_writer_compare" />';
        echo '<button class="button">Compare to live /redline/</button>';
        echo '</form>';

        $logs = array_reverse(SIG_Writer::logs());
        echo '<h3>Writer log</h3>';
        if (!$logs) {
            echo '<p>No writer log lines yet.</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:960px;"><thead><tr><th>When (Brisbane)</th><th>Level</th><th>Message</th></tr></thead><tbody>';
            foreach ($logs as $row) {
                echo '<tr><td>' . esc_html(isset($row['t']) ? $row['t'] : '') . '</td>';
                echo '<td>' . esc_html(isset($row['level']) ? $row['level'] : '') . '</td>';
                echo '<td>' . esc_html(isset($row['msg']) ? $row['msg'] : '') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    protected static function row($key, $label, $type) {
        $val = esc_attr(get_option($key, ''));
        echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input class="regular-text" type="' . esc_attr($type) . '" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . $val . '" autocomplete="off" />';
        echo '</td></tr>';
    }
}
