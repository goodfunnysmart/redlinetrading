<?php

if (!defined('ABSPATH')) {
    exit;
}
$symbol = isset($_GET['symbol']) ? SIG_Access::sanitize_symbol(wp_unslash($_GET['symbol'])) : '';
?>
<div id="sig-chart-page">
  <div class="sig-hero">
    <div>
      <p class="sig-kicker">EMA ribbon</p>
      <h1 id="sig-chart-symbol"><?php echo $symbol ? esc_html($symbol) : 'Select a symbol'; ?></h1>
    </div>
    <?php if (SIG_Access::current_is_paid()) : ?>
    <a class="sig-btn" href="<?php echo esc_url(SIG_Access::dashboard_url()); ?>">Dashboard</a>
    <?php else : ?>
    <a class="sig-btn" href="<?php echo esc_url(SIG_Access::paid_checkout_url()); ?>">Upgrade to Radar Member</a>
    <?php endif; ?>
  </div>

  <div class="sig-chart-toolbar">
    <form class="sig-add" data-chart-pick>
      <input type="text" name="symbol" list="sig-universe" placeholder="Symbol e.g. BHP.AU" autocomplete="off" />
      <datalist id="sig-universe"></datalist>
      <button type="submit">Open</button>
    </form>
    <div class="sig-chart-mode" role="group" aria-label="Series type">
      <button type="button" class="sig-chip is-on" data-mode="line">Line</button>
      <button type="button" class="sig-chip" data-mode="candles">Candles</button>
    </div>
  </div>

  <div class="sig-legend">
    <span><i class="sig-swatch" style="background:#22c55e"></i>EMA 15</span>
    <span><i class="sig-swatch" style="background:#64748b"></i>EMA 25 / 36 / 45 / 55</span>
    <span><i class="sig-swatch" style="background:#ef4444"></i>EMA 65</span>
    <span><i class="sig-swatch" style="background:#c084fc"></i>RSI 14</span>
  </div>

  <p id="sig-chart-status" class="sig-msg"></p>
  <div class="sig-chart-wrap">
    <div id="sig-chart"></div>
  </div>

  <p class="sig-legal">This chart is for information only and is not personal financial advice. Candles, the EMA ribbon (15 / 25 / 36 / 45 / 55 / 65) and RSI 14 are information only and do not constitute a recommendation to buy, sell or hold any security.</p>
</div>
