<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="sig-profile" class="sig-profile">
  <div class="sig-hero">
    <div>
      <p class="sig-kicker">Account</p>
      <h1>Profile</h1>
    </div>
  </div>

  <p class="sig-error" data-error hidden></p>
  <p class="sig-ok" data-ok hidden></p>

  <form class="sig-card sig-form" data-profile-form>
    <div class="sig-form-row">
      <label for="sig-display-name">Display name</label>
      <input id="sig-display-name" type="text" name="display_name" autocomplete="name" required />
    </div>
    <div class="sig-form-row">
      <label for="sig-email">Email</label>
      <input id="sig-email" type="email" name="email" autocomplete="email" required />
    </div>
    <div class="sig-form-row">
      <label for="sig-phone">Phone</label>
      <input id="sig-phone" type="text" name="phone" maxlength="32" autocomplete="tel" />
    </div>
    <div class="sig-form-row">
      <label for="sig-capital">Default Trading Capital</label>
      <input id="sig-capital" type="number" name="capital" min="1000" max="100000000" step="1000" />
    </div>
    <div class="sig-form-actions">
      <button type="submit" class="sig-btn">Save profile</button>
    </div>
  </form>

  <p class="sig-legal">This information is general in nature and is not personal financial advice. Position sizing uses 1% risk and a 25% maximum position of your trading capital.</p>
</div>
