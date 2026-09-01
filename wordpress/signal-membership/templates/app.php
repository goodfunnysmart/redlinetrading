<?php

if (!defined('ABSPATH')) {
    exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?> class="sig-html">
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html(wp_get_document_title()); ?></title>
<script>
(function(){try{var t=localStorage.getItem('sig-theme');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();
</script>
<?php wp_head(); ?>
</head>
<?php
$sig_market = class_exists('SIG_Cache') ? SIG_Cache::market_status() : array('status' => 'unknown', 'label' => '');
$sig_body = array('sig-app');
if (!empty($sig_market['status']) && $sig_market['status'] === 'bullish') {
    $sig_body[] = 'sig-market-bullish';
} elseif (!empty($sig_market['status']) && $sig_market['status'] === 'bearish') {
    $sig_body[] = 'sig-market-bearish';
}
$sig_market_label = (!empty($sig_market['label']) && !empty($sig_market['status']) && $sig_market['status'] !== 'unknown')
    ? (string) $sig_market['label']
    : '';
?>
<body <?php body_class($sig_body); ?>>
<header class="sig-topbar<?php echo is_user_logged_in() ? '' : ' sig-topbar-guest'; ?>">
  <a class="sig-brand" href="<?php echo esc_url(SIG_Access::current_is_paid() ? SIG_Access::dashboard_url() : SIG_Access::chart_url()); ?>">
    <span class="sig-brand-mark" aria-hidden="true"></span>
    Redline
  </a>
  <nav class="sig-nav">
    <?php if (SIG_Access::current_is_paid()) : ?>
    <a class="<?php echo SIG_Shortcodes::post_has('sig_dashboard') ? 'is-active' : ''; ?>" href="<?php echo esc_url(SIG_Access::dashboard_url()); ?>">Dashboard</a>
    <?php endif; ?>
    <a class="<?php echo SIG_Shortcodes::post_has('sig_chart') ? 'is-active' : ''; ?>" href="<?php echo esc_url(SIG_Access::chart_url()); ?>">Chart</a>
    <?php if (is_user_logged_in()) : ?>
    <a class="<?php echo (SIG_Shortcodes::post_has('sig_profile') || SIG_Shortcodes::is_pmpro_member_page()) ? 'is-active' : ''; ?>" href="<?php echo esc_url(apply_filters('sig_profile_url', home_url('/?page_id=12'))); ?>">Profile</a>
    <?php endif; ?>
  </nav>
  <div class="sig-user">
    <span class="sig-market-badge" data-market-badge<?php echo $sig_market_label === '' ? ' hidden' : ''; ?>><?php echo esc_html($sig_market_label); ?></span>
    <button type="button" class="sig-theme-toggle" id="sig-theme-toggle" aria-label="Switch to light" title="Switch to light">
      <span class="sig-theme-icon" aria-hidden="true">
        <svg class="sig-icon-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
        <svg class="sig-icon-moon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M21 14.5A8.5 8.5 0 1110.5 3 7 7 0 0021 14.5z"/></svg>
      </span>
    </button>
    <?php if (is_user_logged_in()) :
        $u = wp_get_current_user(); ?>
      <a class="sig-user-name" href="<?php echo esc_url(apply_filters('sig_profile_url', home_url('/?page_id=12'))); ?>"><?php echo esc_html($u->display_name); ?></a>
      <?php if (!SIG_Access::current_is_paid()) : ?>
      <a class="sig-btn" href="<?php echo esc_url(SIG_Access::paid_checkout_url()); ?>">Upgrade $19/yr</a>
      <?php endif; ?>
      <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Log out</a>
    <?php else : ?>
      <a class="sig-login-link" href="<?php echo esc_url(wp_login_url(SIG_Access::chart_url())); ?>">Log in</a>
      <a class="sig-btn" href="<?php echo esc_url(SIG_Access::paid_checkout_url()); ?>">Join $19/yr</a>
    <?php endif; ?>
  </div>
</header>
<main class="sig-main">
<?php
if (have_posts()) {
    while (have_posts()) {
        the_post();
        the_content();
    }
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>
