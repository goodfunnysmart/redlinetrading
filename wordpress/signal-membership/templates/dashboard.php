<?php

if (!defined('ABSPATH')) {
    exit;
}
?>

<div id="sig-dashboard">
  <div class="sig-hero">
    <div>
      <p class="sig-kicker">Member radar</p>
      <h1>Dreamteam signals</h1>
    </div>
    <div class="sig-asof" data-asof>As of —</div>
  </div>

  <div class="sig-stats">
    <button type="button" class="sig-stat is-on" data-filter="dreamteam"><div class="n" data-stat="dreamteam">—</div><div class="l">Dreamteam</div></button>
    <button type="button" class="sig-stat is-buy" data-filter="buy"><div class="n" data-stat="buy">—</div><div class="l">Buy</div></button>
    <button type="button" class="sig-stat is-sell" data-filter="sell"><div class="n" data-stat="sell">—</div><div class="l">Sell</div></button>
    <button type="button" class="sig-stat is-watch" data-filter="watch"><div class="n" data-stat="watch">—</div><div class="l">Watch</div></button>
    <button type="button" class="sig-stat is-all" data-filter="all"><div class="n" data-stat="all">—</div><div class="l">ALL</div></button>
  </div>

  <div class="sig-toolbar">
    <div class="sig-sizing">
      <label class="sig-cap-label">Capital AUD
        <input type="number" min="1000" max="100000000" step="1000" data-capital value="100000" />
      </label>
      <span class="sig-size-meta">Risk <strong data-risk>$1,000</strong></span>
      <span class="sig-size-meta">Max <strong data-max>$25,000</strong></span>
    </div>
    <form class="sig-add" data-add-form>
      <input type="text" name="symbol" list="sig-universe" placeholder="Add to Dreamteam e.g. BHP.AU" autocomplete="off" />
      <datalist id="sig-universe"></datalist>
      <button type="submit">Add</button>
    </form>
    <label class="sig-filter-wrap"><span class="sig-filter-label">Filter</span>
      <input type="search" data-q placeholder="BHP, buy…" autocomplete="off" />
    </label>
    <div class="sig-packs">
      <button type="button" class="sig-pack" data-pack="BHP.AU,CBA.AU,NAB.AU,WBC.AU,ANZ.AU,RIO.AU,CSL.AU,WES.AU">ASX core</button>
      <button type="button" class="sig-pack" data-pack="VAS.AU,VHY.AU,GHHF.AU">ETFs</button>
      <button type="button" class="sig-pack" data-pack="AAPL.US,TSLA.US,GOOG.US,MSFT.US,BTC-USD.CC">US + BTC</button>
    </div>
  </div>

  <p class="sig-error" data-error hidden></p>

  <div class="sig-card">
    <div class="sig-sortbar" data-sortbar>
      <span>Sort</span>
      <button type="button" class="sig-sort-btn" data-sort="symbol">Symbol</button>
      <button type="button" class="sig-sort-btn" data-sort="signal">Signal</button>
      <button type="button" class="sig-sort-btn" data-sort="ret_1d">1D %</button>
      <button type="button" class="sig-sort-btn" data-sort="ret_6m">6M %</button>
      <button type="button" class="sig-sort-btn" data-sort="close">Price</button>
    </div>
    <div class="sig-empty" data-empty>Loading…</div>
    <table class="sig-table">
      <thead>
        <tr>
          <th><button type="button" class="sig-th" data-sort="symbol">Symbol</button></th>
          <th><button type="button" class="sig-th" data-sort="signal">Signal</button></th>
          <th><button type="button" class="sig-th" data-sort="ret_1d">1D %</button></th>
          <th><button type="button" class="sig-th" data-sort="ret_6m">6M %</button></th>
          <th><button type="button" class="sig-th" data-sort="close">Price</button></th>
          <th><button type="button" class="sig-th" data-sort="shares">Shares</button></th>
          <th><button type="button" class="sig-th" data-sort="value">Value</button></th>
          <th>Chart</th>
          <th>Remove</th>
        </tr>
      </thead>
      <tbody data-rows></tbody>
    </table>
  </div>

  <p class="sig-legal">This information is general in nature and is not personal financial advice. It does not take into account your objectives, financial situation or needs. Past performance is not a reliable indicator of future results. Consider whether these signals are appropriate for you and seek licensed advice if needed.</p>
</div>
