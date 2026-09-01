<?php
if (!defined('ABSPATH')) {
    exit;
}

class SIG_Pages {
    public static function activate() {
        SIG_Watchlist::activate();
        $dash = self::ensure_page('dashboard', 'Dashboard', '[sig_dashboard]');
        self::ensure_page('chart', 'Chart', '[sig_chart]');
        self::ensure_page('profile', 'Profile', '[sig_profile]');
        if ($dash) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $dash);
        }
        flush_rewrite_rules(false);
    }

    public static function ensure_page($slug, $title, $content) {
        $existing = get_page_by_path($slug);
        if ($existing) {
            return (int) $existing->ID;
        }
        $id = wp_insert_post(array(
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => $content,
        ));
        return $id ? (int) $id : 0;
    }

    public static function ensure_profile_page() {
        self::ensure_page('profile', 'Profile', '[sig_profile]');
    }
}
