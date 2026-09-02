<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings action: import favorites.json (local then HTTP) into the current admin watchlist.
 */
class SIG_Import_Favorites {
    public static function init() {
        add_action('admin_init', array(__CLASS__, 'maybe_import'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
    }

    public static function maybe_import() {
        if (empty($_POST['sig_import_favorites'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        check_admin_referer('sig_import_favorites');
        $result = self::import_for_user(get_current_user_id());
        $url = add_query_arg(
            array(
                'page'         => 'signal-membership',
                'tab'          => 'advanced',
                'sig_imported' => (int) $result['added'],
                'sig_skipped'  => (int) $result['skipped'],
            ),
            admin_url('options-general.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    public static function import_for_user($user_id) {
        $favs = SIG_Cache::favorites();
        $added = 0;
        $skipped = 0;
        foreach ($favs as $sym) {
            $ok = SIG_Watchlist::add($user_id, $sym);
            if ($ok === true) {
                $added++;
            } else {
                $skipped++;
            }
        }
        return array('added' => $added, 'skipped' => $skipped, 'total' => count($favs));
    }

    public static function notice() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'signal-membership') {
            return;
        }
        if (!isset($_GET['sig_imported'])) {
            return;
        }
        $added = (int) $_GET['sig_imported'];
        $skipped = isset($_GET['sig_skipped']) ? (int) $_GET['sig_skipped'] : 0;
        echo '<div class="notice notice-success is-dismissible"><p>';
        echo esc_html(sprintf(
            'Imported %d symbol(s) into your watchlist. Skipped %d.',
            $added,
            $skipped
        ));
        echo '</p></div>';
    }

    public static function render_button() {
        echo '<hr /><h2>Import favorites</h2>';
        echo '<p>Reads <code>favorites.json</code> from the local redline directory, then HTTP. Adds each symbol to <strong>your</strong> watchlist.</p>';
        echo '<form method="post">';
        wp_nonce_field('sig_import_favorites');
        submit_button('Import favorites.json into my watchlist', 'secondary', 'sig_import_favorites');
        echo '</form>';
    }
}
