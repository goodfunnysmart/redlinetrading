<?php

if (!defined('ABSPATH')) {
    exit;
}

class SIG_Shortcodes {
    public static function init() {
        add_shortcode('sig_dashboard', array(__CLASS__, 'dashboard'));
        add_shortcode('sig_chart', array(__CLASS__, 'chart'));
        add_shortcode('sig_profile', array(__CLASS__, 'profile'));
        add_action('init', array('SIG_Pages', 'ensure_profile_page'), 20);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'strip_theme'), 100);
        add_filter('template_include', array(__CLASS__, 'template'), 99);
        add_filter('show_admin_bar', array(__CLASS__, 'maybe_hide_admin_bar'));
        add_filter('sig_dashboard_url', array(__CLASS__, 'url_dashboard'));
        add_filter('sig_chart_url', array(__CLASS__, 'url_chart'));
        add_filter('sig_profile_url', array(__CLASS__, 'url_profile'));
        add_action('template_redirect', array(__CLASS__, 'redirect_legacy_profile'));
    }

    public static function url_dashboard($url = '') {
        return home_url('/?pagename=dashboard');
    }

    public static function url_chart($url = '') {
        if (is_string($url) && strpos($url, 'pagename=chart') !== false) {
            return $url;
        }
        return home_url('/?pagename=chart');
    }

    public static function url_profile($url = '') {
        if (function_exists('pmpro_url')) {
            $account = pmpro_url('account');
            if ($account) {
                return $account;
            }
            $profile = pmpro_url('member_profile');
            if (!$profile) {
                $profile = pmpro_url('profile');
            }
            if ($profile) {
                return $profile;
            }
        }
        return home_url('/?page_id=12');
    }

    public static function pmpro_page_ids($keys) {
        $ids = array();
        foreach ((array) $keys as $key) {
            $id = 0;
            if (function_exists('pmpro_getOption')) {
                $id = (int) pmpro_getOption($key . '_page_id');
            }
            if (!$id) {
                $id = (int) get_option('pmpro_' . $key . '_page_id');
            }
            if ($id) {
                $ids[] = $id;
            }
        }
        global $pmpro_pages;
        if (!empty($pmpro_pages) && is_array($pmpro_pages)) {
            foreach ((array) $keys as $key) {
                if (!empty($pmpro_pages[$key])) {
                    $ids[] = (int) $pmpro_pages[$key];
                }
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    public static function pmpro_member_page_ids() {
        return self::pmpro_page_ids(array('account', 'billing', 'cancel', 'invoice', 'invoices', 'member_profile', 'profile'));
    }

    public static function pmpro_checkout_page_ids() {
        return self::pmpro_page_ids(array('checkout', 'levels', 'confirmation', 'login'));
    }

    public static function pmpro_wrap_page_ids() {
        return array_values(array_unique(array_merge(self::pmpro_member_page_ids(), self::pmpro_checkout_page_ids())));
    }

    public static function is_pmpro_page($ids) {
        $post = self::current_post();
        if (!$post || !($post instanceof WP_Post) || $post->post_type !== 'page') {
            return false;
        }
        $pid = (int) $post->ID;
        if (in_array($pid, $ids, true)) {
            return true;
        }
        $parent = (int) $post->post_parent;
        return $parent && in_array($parent, $ids, true);
    }

    public static function is_pmpro_member_page() {
        return self::is_pmpro_page(self::pmpro_member_page_ids());
    }

    public static function is_pmpro_checkout_page() {
        return self::is_pmpro_page(self::pmpro_checkout_page_ids());
    }

    public static function redirect_legacy_profile() {
        if (!self::post_has('sig_profile')) {
            return;
        }
        $dest = self::url_profile();
        if (!$dest) {
            return;
        }
        wp_safe_redirect($dest, 302);
        exit;
    }

    public static function activate() {
        $dashboard_id = self::ensure_page('dashboard', 'Dashboard', '[sig_dashboard]');
        self::ensure_page('chart', 'Chart', '[sig_chart]');
        self::ensure_page('profile', 'Profile', '[sig_profile]');
        if ($dashboard_id) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $dashboard_id);
        }
        flush_rewrite_rules();
    }

    protected static function ensure_page($slug, $title, $content) {
        $found = get_posts(array(
            'name'           => $slug,
            'post_type'      => 'page',
            'post_status'    => array('publish', 'draft', 'private'),
            'posts_per_page' => 1,
        ));
        if ($found) {
            return (int) $found[0]->ID;
        }
        $id = wp_insert_post(array(
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => get_current_user_id() ? get_current_user_id() : 1,
        ));
        return is_wp_error($id) ? 0 : (int) $id;
    }

    public static function current_post() {
        $post = get_queried_object();
        if ($post instanceof WP_Post) {
            return $post;
        }
        return get_post();
    }

    public static function post_has($tag) {
        $post = self::current_post();
        if (!$post || empty($post->post_content)) {
            return false;
        }
        return has_shortcode($post->post_content, $tag);
    }

    public static function is_app_page() {
        return self::post_has('sig_dashboard') || self::post_has('sig_chart') || self::post_has('sig_profile') || self::is_pmpro_member_page() || self::is_pmpro_checkout_page();
    }

    public static function maybe_hide_admin_bar($show) {
        if (self::is_app_page()) {
            return false;
        }
        return $show;
    }

    public static function template($template) {
        if (self::is_app_page()) {
            return SIG_PLUGIN_DIR . 'templates/app.php';
        }
        return $template;
    }

    public static function strip_theme() {
        if (!self::is_app_page()) {
            return;
        }
        global $wp_styles, $wp_scripts;
        $theme_uri = get_template_directory_uri();
        $child_uri = get_stylesheet_directory_uri();
        if ($wp_styles) {
            foreach ($wp_styles->registered as $handle => $style) {
                $src = isset($style->src) ? (string) $style->src : '';
                if ($src && (strpos($src, $theme_uri) !== false || strpos($src, $child_uri) !== false)) {
                    wp_dequeue_style($handle);
                    wp_deregister_style($handle);
                }
            }
        }
        if ($wp_scripts) {
            foreach ($wp_scripts->registered as $handle => $script) {
                $src = isset($script->src) ? (string) $script->src : '';
                if ($src && (strpos($src, $theme_uri) !== false || strpos($src, $child_uri) !== false)) {
                    wp_dequeue_script($handle);
                    wp_deregister_script($handle);
                }
            }
        }
    }

    public static function localize_payload() {
        $symbol = isset($_GET['symbol']) ? SIG_Access::sanitize_symbol(wp_unslash($_GET['symbol'])) : '';
        return array(
            'rest'        => rest_url('signals/v1/'),
            'nonce'       => wp_create_nonce('wp_rest'),
            'chartUrl'    => apply_filters('sig_chart_url', home_url('/?pagename=chart')),
            'dashUrl'     => apply_filters('sig_dashboard_url', home_url('/?pagename=dashboard')),
            'profileUrl'  => apply_filters('sig_profile_url', home_url('/?pagename=profile')),
            'symbol'      => $symbol,
            'isPaid'      => SIG_Access::current_is_paid(),
            'market'      => SIG_Cache::market_status(),
        );
    }

    public static function assets() {
        if (!self::is_app_page()) {
            return;
        }
        wp_enqueue_style(
            'sig-fonts',
            'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap',
            array(),
            null
        );
        wp_enqueue_style(
            'sig-member',
            SIG_PLUGIN_URL . 'assets/css/member.css',
            array('sig-fonts'),
            SIG_PLUGIN_VER
        );
        wp_enqueue_script(
            'sig-theme',
            SIG_PLUGIN_URL . 'assets/js/theme.js',
            array(),
            SIG_PLUGIN_VER,
            true
        );

        $payload = self::localize_payload();

        if (self::post_has('sig_dashboard')) {
            wp_enqueue_script(
                'sig-dashboard',
                SIG_PLUGIN_URL . 'assets/js/dashboard.js',
                array(),
                SIG_PLUGIN_VER,
                true
            );
            wp_localize_script('sig-dashboard', 'SIG', $payload);
        }

        if (self::post_has('sig_chart')) {
            wp_enqueue_script(
                'sig-lwc',
                'https://unpkg.com/lightweight-charts@' . SIG_LWC_VERSION . '/dist/lightweight-charts.standalone.production.js',
                array(),
                SIG_LWC_VERSION,
                true
            );
            wp_enqueue_script(
                'sig-chart-ribbon',
                SIG_PLUGIN_URL . 'assets/js/chart-ribbon.js',
                array('sig-lwc'),
                SIG_PLUGIN_VER,
                true
            );
            wp_localize_script('sig-chart-ribbon', 'SIG', $payload);
        }

        if (self::post_has('sig_profile')) {
            wp_enqueue_script(
                'sig-profile',
                SIG_PLUGIN_URL . 'assets/js/profile.js',
                array(),
                SIG_PLUGIN_VER,
                true
            );
            wp_localize_script('sig-profile', 'SIG', $payload);
        }
    }

    public static function gate_html($message, $mode = 'login') {
        $login = wp_login_url(get_permalink());
        $register = SIG_Access::register_url();
        $paid = SIG_Access::paid_checkout_url();
        $html  = '<div class="sig-card sig-gate">';
        $html .= '<h2>' . esc_html($message) . '</h2>';
        $html .= '<div class="sig-gate-actions">';
        if (!is_user_logged_in()) {
            $html .= '<a class="sig-btn" href="' . esc_url($login) . '">Log in</a>';
            $html .= '<a class="sig-btn ghost" href="' . esc_url($register) . '">Register</a>';
        }
        $html .= '<a class="sig-btn" href="' . esc_url($paid) . '">Join Radar Member · $19/yr</a>';
        $html .= '</div>';
        $html .= '<p class="sig-legal">Free accounts can use charts. Radar Member ($19 AUD per year) unlocks the dashboard, Dreamteam and weekday email. General information only, not personal financial advice.</p>';
        $html .= '</div>';
        return $html;
    }

    public static function dashboard() {
        if (!is_user_logged_in()) {
            return self::gate_html('Log in or register to continue.');
        }
        if (!SIG_Access::current_is_paid()) {
            return self::gate_html('Dashboard is for Radar Member.');
        }
        ob_start();
        include SIG_PLUGIN_DIR . 'templates/dashboard.php';
        return ob_get_clean();
    }

    public static function chart() {
        if (!is_user_logged_in()) {
            return self::gate_html('Log in or register to view charts.');
        }
        if (!SIG_Access::current_can_chart()) {
            return self::gate_html('Register for a free account to view charts.');
        }
        ob_start();
        include SIG_PLUGIN_DIR . 'templates/chart.php';
        return ob_get_clean();
    }

    public static function profile() {
        if (!is_user_logged_in()) {
            return self::gate_html('Please log in to view your profile.');
        }
        if (!SIG_Access::current_can_access()) {
            return self::gate_html('Active membership required.');
        }
        ob_start();
        include SIG_PLUGIN_DIR . 'templates/profile.php';
        return ob_get_clean();
    }
}
