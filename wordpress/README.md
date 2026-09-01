# WordPress (Redline Radar)

Live site: https://greache.com/redlinetrading

`signal-membership` is the Radar membership plugin (Dreamteam dashboard, charts, PMPro $19/year, weekday 6pm Brisbane email). It reads the nightly scan DB produced by the root `redline.php` mailer. It does not call EODHD.

Current plugin version: 1.4.5

Deploy: zip the `signal-membership` folder and upload it in WP Admin → Plugins. Do not commit WordPress core, `wp-config.php`, or Paid Memberships Pro.

Root of this repo stays the original scan/mailer (`redline.php`, `mailer.php`, symbols).
