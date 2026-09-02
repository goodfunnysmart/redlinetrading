<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin tool: add symbols to any member's Dreamteam.
 */
class SIG_Dreamteam_Admin {
    public static function init() {
        add_action('admin_post_sig_dreamteam_bulk', array(__CLASS__, 'handle'));
    }

    public static function handle() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('sig_dreamteam_bulk');

        $user_id = isset($_POST['sig_dt_user']) ? (int) $_POST['sig_dt_user'] : 0;
        $raw = isset($_POST['sig_dt_symbols']) ? (string) wp_unslash($_POST['sig_dt_symbols']) : '';
        $added = 0;
        $skipped = 0;
        $unknown = array();

        if ($user_id > 0 && $raw !== '') {
            $existing = SIG_Watchlist::get($user_id);
            $parts = preg_split('/[\s,;]+/', $raw);
            foreach ((array) $parts as $part) {
                $sym = SIG_Access::sanitize_symbol($part);
                if ($sym === '') {
                    continue;
                }
                if (in_array($sym, $existing, true)) {
                    $skipped++;
                    continue;
                }
                $ok = SIG_Watchlist::add($user_id, $sym);
                if ($ok === true) {
                    $added++;
                    $existing[] = $sym;
                } else {
                    $unknown[] = $sym;
                }
            }
        }

        $url = add_query_arg(
            array(
                'page'        => 'signal-membership',
                'tab'         => 'advanced',
                'sig_dt_add'  => $added,
                'sig_dt_skip' => $skipped,
                'sig_dt_bad'  => rawurlencode(implode(',', $unknown)),
                'sig_dt_uid'  => $user_id,
            ),
            admin_url('options-general.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    public static function notice() {
        if (!isset($_GET['sig_dt_add'])) {
            return;
        }
        $added = (int) $_GET['sig_dt_add'];
        $skipped = isset($_GET['sig_dt_skip']) ? (int) $_GET['sig_dt_skip'] : 0;
        $bad = isset($_GET['sig_dt_bad']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['sig_dt_bad']))) : '';
        echo '<div class="notice notice-success is-dismissible"><p>';
        echo esc_html(sprintf('Dreamteam updated: %d added, %d already there.', $added, $skipped));
        if ($bad !== '') {
            echo ' ' . esc_html('Not in the engine universe: ' . $bad);
        }
        echo '</p></div>';
    }

    public static function render() {
        echo '<hr><h2>Dreamteam for a member</h2>';
        echo '<p>Adds symbols to the chosen user without removing anything. One per line or comma separated, engine format such as <code>BHP.AU</code>.</p>';

        $uid = isset($_GET['sig_dt_uid']) ? (int) $_GET['sig_dt_uid'] : 0;
        $users = get_users(array('number' => 200, 'orderby' => 'user_login'));

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('sig_dreamteam_bulk');
        echo '<input type="hidden" name="action" value="sig_dreamteam_bulk" />';
        echo '<p><label for="sig_dt_user"><strong>Member</strong></label><br />';
        echo '<select name="sig_dt_user" id="sig_dt_user">';
        foreach ($users as $u) {
            $count = count(SIG_Watchlist::get($u->ID));
            $label = $u->user_login . ' (' . $u->user_email . ') - ' . $count . ' on Dreamteam';
            echo '<option value="' . esc_attr($u->ID) . '" ' . selected($uid, $u->ID, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p>';
        echo '<p><label for="sig_dt_symbols"><strong>Symbols to add</strong></label><br />';
        echo '<textarea id="sig_dt_symbols" name="sig_dt_symbols" rows="6" cols="60" placeholder="BHP.AU, RIO.AU"></textarea></p>';
        echo '<p><button class="button button-primary">Add to Dreamteam</button></p>';
        echo '</form>';
    }
}
