<?php
if (!defined('ABSPATH')) {
    exit;
}
$fmt_price = function ($n) {
    if ($n === null || $n === '') {
        return '—';
    }
    $n = (float) $n;
    $d = ($n < 1) ? 3 : 2;
    return '$' . number_format($n, $d);
};
$fmt_pct = function ($n) {
    if ($n === null || $n === '') {
        return '—';
    }
    $n = (float) $n;
    $s = number_format($n, 1) . '%';
    if ($n > 0) {
        $s = '+' . $s;
    }
    return $s;
};
$pct_color = function ($n) {
    if ($n === null || $n === '') {
        return '#94a3b8';
    }
    return ((float) $n >= 0) ? '#4ade80' : '#f87171';
};
$pct_span = function ($n) use ($fmt_pct, $pct_color) {
    $txt = $fmt_pct($n);
    if ($txt === '—') {
        return '';
    }
    return ' <span style="color:' . $pct_color($n) . ';font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap;">' . esc_html($txt) . '</span>';
};
$badge = function ($sig, $under = false) {
    $sig = strtolower((string) $sig);
    $under_html = $under
        ? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#9f1239;color:#fecaca;font-size:10px;font-weight:700;letter-spacing:.06em;">UNDER</span>'
        : '';
    if ($sig === 'buy') {
        $main = '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#22c55e;color:#052e16;font-size:10px;font-weight:700;letter-spacing:.06em;">BUY</span>';
        return $under ? ($main . ' ' . $under_html) : $main;
    }
    if ($sig === 'sell') {
        $main = '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;letter-spacing:.06em;">SELL</span>';
        return $under ? ($main . ' ' . $under_html) : $main;
    }
    if ($sig === 'watch') {
        $main = '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#f59e0b;color:#422006;font-size:10px;font-weight:700;letter-spacing:.06em;">WATCH</span>';
        return $under ? ($main . ' ' . $under_html) : $main;
    }
    if ($under) {
        return $under_html;
    }
    return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#334155;color:#cbd5e1;font-size:10px;font-weight:700;letter-spacing:.06em;">HOLD</span>';
};
$row_html = function ($r) use ($fmt_price, $fmt_pct, $pct_color, $pct_span, $badge) {
    $ret_1d = isset($r['ret_1d']) ? $r['ret_1d'] : null;
    $ret_6m = isset($r['ret_6m']) ? $r['ret_6m'] : null;
    $href = isset($r['href']) ? $r['href'] : '#';
    $sym = esc_html($r['symbol']);
    $shares = isset($r['shares']) ? number_format((int) $r['shares']) : '0';
    $value = isset($r['value']) ? '$' . number_format((int) $r['value']) : '—';
    $under = !empty($r['under_redline']);
    $td = 'padding:10px 8px;border-bottom:1px solid #1e293b;';
    return '<tr>'
        . '<td style="' . $td . 'font-family:Arial,sans-serif;">'
        . '<a href="' . esc_url($href) . '" style="color:#7dd3fc;font-weight:700;text-decoration:none;">' . $sym . '</a>'
        . '</td>'
        . '<td style="' . $td . '">' . $badge(isset($r['signal']) ? $r['signal'] : '', $under) . '</td>'
        . '<td style="' . $td . 'color:' . $pct_color($ret_1d) . ';font-variant-numeric:tabular-nums;">' . esc_html($fmt_pct($ret_1d)) . '</td>'
        . '<td style="' . $td . 'color:' . $pct_color($ret_6m) . ';font-variant-numeric:tabular-nums;">' . esc_html($fmt_pct($ret_6m)) . '</td>'
        . '<td style="' . $td . 'color:#e2e8f0;font-variant-numeric:tabular-nums;">' . esc_html($fmt_price(isset($r['close']) ? $r['close'] : null)) . '</td>'
        . '<td style="' . $td . 'color:#94a3b8;text-align:right;font-variant-numeric:tabular-nums;">' . esc_html($shares) . '</td>'
        . '<td style="' . $td . 'color:#e2e8f0;text-align:right;font-variant-numeric:tabular-nums;">' . esc_html($value) . '</td>'
        . '</tr>';
};
$section = function ($title, $color, $rows, $empty) use ($row_html) {
    $th = 'padding:8px;color:#64748b;font-size:10px;letter-spacing:.08em;text-transform:uppercase;border-bottom:1px solid #1e293b;';
    $head = '<tr>'
        . '<th align="left" style="' . $th . '">Stock</th>'
        . '<th align="left" style="' . $th . '">Signal</th>'
        . '<th align="left" style="' . $th . '">1D</th>'
        . '<th align="left" style="' . $th . '">6M</th>'
        . '<th align="left" style="' . $th . '">Price</th>'
        . '<th align="right" style="' . $th . '">Shares</th>'
        . '<th align="right" style="' . $th . '">Value</th>'
        . '</tr>';
    $body = '';
    if ($rows) {
        foreach ($rows as $r) {
            $body .= $row_html($r);
        }
    } else {
        $body = '<tr><td colspan="7" style="padding:12px 8px;color:#64748b;font-style:italic;">' . esc_html($empty) . '</td></tr>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px;border:1px solid #1e293b;border-radius:12px;overflow:hidden;background:#0f172a;">'
        . '<tr><td style="padding:12px 14px;background:' . $color . ';color:#0b1220;font-family:Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">' . esc_html($title) . '</td></tr>'
        . '<tr><td style="padding:0 8px 8px;"><table width="100%" cellpadding="0" cellspacing="0">' . $head . $body . '</table></td></tr>'
        . '</table>';
};
if (!isset($capital_label)) {
    $capital_label = '$100,000';
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Redline Radar</title>
</head>
<body style="margin:0;padding:0;background:#020617;color:#e2e8f0;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#020617;padding:24px 12px;">
  <tr>
    <td align="center">
      <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#0b1220;border:1px solid #1e293b;border-radius:16px;overflow:hidden;">
        <tr>
          <td style="padding:28px 28px 18px;background:linear-gradient(135deg,#0b1220,#0f172a);border-bottom:1px solid #1e293b;">
            <div style="font-family:Arial,sans-serif;font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:#38bdf8;">Redline Radar</div>
            <div style="font-family:Arial,sans-serif;font-size:26px;font-weight:700;color:#f8fafc;margin-top:6px;">Tonight's scan</div>
            <div style="font-family:Arial,sans-serif;font-size:14px;color:#94a3b8;margin-top:6px;">Hi <?php echo esc_html($name); ?> · <?php echo esc_html(date_i18n('l j F Y', strtotime($date))); ?></div>
          </td>
        </tr>
        <tr>
          <td style="padding:24px 28px 8px;font-family:Arial,sans-serif;">
            <p style="margin:0 0 18px;color:#cbd5e1;font-size:14px;line-height:1.55;">Your Dreamteam first, then today's market BUY, SELL and WATCH. Position sizes below are sizing off <?php echo esc_html($capital_label); ?>. Tap a ticker to open the chart on Redline. This is not the full universe.</p>
            <?php
            echo $section('Dreamteam', '#38bdf8', $dreamteam, 'No symbols in your Dreamteam yet. Add some from the dashboard.');
            echo $section('Buy', '#22c55e', $buys, 'No buy signals today.');
            echo $section('Sell', '#ef4444', $sells, 'No sell signals today.');
            echo $section('Watch', '#f59e0b', $watches, 'No watch signals today.');
            ?>
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 18px;">
              <tr>
                <td style="border-radius:10px;background:#38bdf8;">
                  <a href="<?php echo esc_url($dashboard); ?>" style="display:inline-block;padding:12px 18px;color:#0b1220;font-family:Arial,sans-serif;font-size:13px;font-weight:700;text-decoration:none;">Open dashboard</a>
                </td>
                <td width="10"></td>
                <td style="border-radius:10px;border:1px solid #334155;background:#0f172a;">
                  <a href="<?php echo esc_url($chart_home); ?>" style="display:inline-block;padding:12px 18px;color:#e2e8f0;font-family:Arial,sans-serif;font-size:13px;font-weight:700;text-decoration:none;">Open charts</a>
                </td>
              </tr>
            </table>
            <p style="margin:0 0 12px;color:#64748b;font-size:11px;line-height:1.5;">General information only. Not personal financial advice and not a recommendation to buy, sell or hold any security. Position sizes assume 1% risk to EMA65, capped at 25% of your trading capital.</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
