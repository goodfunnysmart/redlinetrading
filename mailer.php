<?php
// ==========================================================
// Redline ASX Radar - Mailer v2.7 (Sub-$1 Precision Engine)
// ==========================================================

set_time_limit(0);
date_default_timezone_set('Australia/Brisbane');

$baseDir = __DIR__;
$snapshotFile = $baseDir . '/signals_snapshot.json';
$favFile = $baseDir . '/favorites.json';
$dynamicFile = $baseDir . '/symbols_dynamic.php';
$customFile  = $baseDir . '/symbols_custom.php';
$cacheDir = $baseDir . '/cache';
$to = "mail@greache.com,stevedrinkall@gmail.com";

if (!file_exists($snapshotFile)) die("No snapshot found.");

// 1. LOAD DATA & LISTS
$dynamicStocks = file_exists($dynamicFile) ? include $dynamicFile : [];
$customStocks  = file_exists($customFile) ? include $customFile : [];
if(!is_array($dynamicStocks)) $dynamicStocks = [];
if(!is_array($customStocks)) $customStocks = [];
$totalStocks = count(array_unique(array_merge($dynamicStocks, $customStocks)));

$favorites = file_exists($favFile) ? json_decode(file_get_contents($favFile), true) : [];
$data = json_decode(file_get_contents($snapshotFile), true);

// Portfolio Settings
$defaultCapital = 100000;
$volMultiplier = 10;
$maxPositionValue = $defaultCapital * 0.25; // $25,000 Cap

if (!empty($favorites)) {
    sort($favorites);
}

if (count($data['processed']) < $totalStocks) {
    die("Scan incomplete (" . count($data['processed']) . "/$totalStocks). Email aborted.");
}

// EMA & CSV HELPERS
function mailer_calculateEMA($prices, $period) {
    if (count($prices) < $period) return 0;
    $multiplier = 2 / ($period + 1);
    $ema = $prices[0];
    for ($i = 1; $i < count($prices); $i++) {
        $ema = ($prices[$i] - $ema) * $multiplier + $ema;
    }
    return $ema;
}

// Function to get 6M and Flag data for sections that need it
function getStockMetadata($stock, $cacheDir, $defaultCapital, $volMultiplier) {
    $safeTicker = str_replace('.', '_', $stock);
    $csvPath = $cacheDir . '/' . $safeTicker . '.csv';
    $out = ['sixMo' => 'N/A', 'color' => '', 'flags' => '', 'latestClose' => 0, 'ema65' => 0];

    if (!file_exists($csvPath)) return $out;
    $lines = file($csvPath);
    if (count($lines) < 100) return $out;

    $rows = array_map('str_getcsv', $lines);
    array_shift($rows); // Remove header
    $prices = array_column($rows, 4);
    $out['latestClose'] = (float)end($prices);

    // 6 Month Growth
    if (count($prices) >= 126) {
        $priceSixMoAgo = (float)$prices[count($prices)-126];
        $growth = (($out['latestClose'] - $priceSixMoAgo) / $priceSixMoAgo) * 100;
        $out['color'] = ($growth >= 0) ? "text-green" : "text-red";
        $out['sixMo'] = number_format($growth, 1) . "%";
    }

    // Flags (Alerts)
    $ema65 = mailer_calculateEMA($prices, 65);
    if ($out['latestClose'] < $ema65) $out['flags'] .= "<span class='flag'>📉</span>";
    
    $volSlice = array_slice($rows, -20);
    $avgDollarVol = array_sum(array_map(function($r){ return (float)$r[4] * (float)$r[6]; }, $volSlice)) / 20;
    if ($avgDollarVol < ($defaultCapital * $volMultiplier)) $out['flags'] .= "<span class='flag'>💧</span>";

    return $out;
}

// 3. COMPILE MESSAGE
$message = "<html><head><style>
    body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f4f7f6; color: #333; padding: 20px; }
    .container { max-width: 750px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
    .header { background: #334155; color: #fff; padding: 25px; text-align: center; }
    .content { padding: 20px; }
    h3 { padding: 12px; margin: 20px 0 10px 0; border-radius: 6px; font-size: 14px; text-transform: uppercase; }
    .bg-focus { background: #e0f2fe; color: #0369a1; border-left: 4px solid #0369a1; }
    .bg-buy { background: #dcfce7; color: #166534; border-left: 4px solid #22c55e; }
    .bg-exit { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
    .bg-watch { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    th { text-align: left; font-size: 10px; color: #64748b; padding: 8px; border-bottom: 1px solid #edf2f7; text-transform: uppercase; }
    td { padding: 10px 8px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .ticker-link { font-weight: bold; color: #2563eb; text-decoration: none; }
    .badge { font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: bold; margin-left: 8px; text-transform: uppercase; display: inline-block; }
    .badge-buy { background: #22c55e; color: #fff; }
    .badge-exit { background: #ef4444; color: #fff; }
    .badge-watch { background: #f59e0b; color: #fff; }
    .flag { font-size: 14px; margin-right: 2px; }
    .text-red { color: #b91c1c; } .text-green { color: #15803d; }
</style></head><body><div class='container'><div class='header'><h1>Redline Radar</h1><p>Market Report: " . $data['date'] . "</p></div><div class='content'>";

// --- SECTION 1: FOCUS WATCHLIST ---
$message .= "<h3 class='bg-focus'>★ FOCUS WATCHLIST</h3>";
$message .= "<table><thead><tr><th>Stock</th><th style='width:35px;'>!</th><th>6M %</th><th>Price</th><th>Shares</th><th>Value</th></tr></thead><tbody>";

if (empty($favorites)) {
    $message .= "<tr><td colspan='6' style='color:#999; font-style:italic;'>No stocks starred.</td></tr>";
} else {
    foreach ($favorites as $stock) {
        $meta = getStockMetadata($stock, $cacheDir, $defaultCapital, $volMultiplier);
        if ($meta['latestClose'] == 0) continue;

        $safeTicker = str_replace('.', '_', $stock);
        $csvPath = $cacheDir . '/' . $safeTicker . '.csv';
        $lines = file($csvPath);
        $rows = array_map('str_getcsv', $lines);
        array_shift($rows);
        $prices = array_column($rows, 4);

        $latestClose = (float)end($prices);
        $yesterdayClose = (float)$prices[count($prices)-2];
        $ema15 = mailer_calculateEMA($prices, 15);
        $ema65 = mailer_calculateEMA($prices, 65);

        $statusBadge = "";
        if ($latestClose > $ema15 && $yesterdayClose <= $ema15 && $yesterdayClose > $ema65) $statusBadge = "<span class='badge badge-buy'>BUY</span>";
        elseif ($latestClose < $ema65 && $yesterdayClose >= $ema65) $statusBadge = "<span class='badge badge-exit'>SELL</span>";
        elseif ($latestClose <= $ema15 && $latestClose > $ema65) $statusBadge = "<span class='badge badge-watch'>WATCH</span>";

        $parts = explode('.', $stock); $tvTicker = $parts[0]; $tvExch = (isset($parts[1]) && $parts[1] === 'US') ? '' : 'ASX%3A';
        $link = "https://www.tradingview.com/chart/QL9IAyDB/?symbol=" . $tvExch . $tvTicker;

        $riskVal = $defaultCapital * 0.01;
        $shares = ($latestClose > $ema65) ? floor($riskVal / ($latestClose - $ema65)) : 0;
        if (($shares * $latestClose) > $maxPositionValue) $shares = floor($maxPositionValue / $latestClose);
        $value = round(($shares * $latestClose) / 10) * 10;

        // FIXED: Focus Section dynamic precision setting
        $precision = ($latestClose < 1.0) ? 3 : 2;

        $message .= "<tr>
            <td><a href='$link' class='ticker-link'>$stock</a>$statusBadge</td>
            <td>{$meta['flags']}</td>
            <td class='{$meta['color']}'>{$meta['sixMo']}</td>
            <td>\$".number_format($latestClose, $precision)."</td>
            <td>".number_format($shares)."</td>
            <td>\$".number_format($value)."</td>
        </tr>";
    }
}
$message .= "</tbody></table>";

// --- SECTION 2: BROAD MARKET SIGNALS ---
$categories = [
    'BUY SIGNALS' => ['buy', 'bg-buy'],
    'EXIT SIGNALS' => ['exit', 'bg-exit'],
    'WATCHLIST' => ['watch', 'bg-watch']
];

foreach ($categories as $title => $m) {
    $key = $m[0];
    $message .= "<h3 class='{$m[1]}'>$title</h3>";
    if (empty($data[$key])) {
        $message .= "<p style='padding:10px; font-style:italic; color:#999;'>No active signals.</p>";
    } else {
        $message .= "<table><thead><tr><th>Stock</th><th style='width:35px;'>!</th><th>6M %</th><th>Price</th><th>Shares</th><th>Value</th></tr></thead><tbody>";
        foreach ($data[$key] as $s) {
            $meta = getStockMetadata($s['ticker'], $cacheDir, $defaultCapital, $volMultiplier);
            
            $focusPrefix = in_array($s['ticker'], $favorites) ? "★ " : "";
            $parts = explode('.', $s['ticker']); $tvTicker = $parts[0]; $tvExch = (isset($parts[1]) && $parts[1] === 'US') ? '' : 'ASX%3A';
            $link = "https://www.tradingview.com/chart/QL9IAyDB/?symbol=" . $tvExch . $tvTicker;
            
            $price = (float)str_replace(',', '', $s['price']);
            $shares = (int)str_replace(',', '', $s['shares']);
            if (($shares * $price) > $maxPositionValue) {
                $shares = floor($maxPositionValue / $price);
            }
            $finalVal = round(($shares * $price) / 10) * 10;

            // FIXED: Broad Signals Section dynamic precision setting
            $precision = ($price < 1.0) ? 3 : 2;

            $message .= "<tr>
                <td>{$focusPrefix}<a href='$link' class='ticker-link'>{$s['ticker']}</a></td>
                <td>{$meta['flags']}</td>
                <td class='{$meta['color']}'>{$meta['sixMo']}</td>
                <td>\$".number_format($price, $precision)."</td>
                <td>".number_format($shares)."</td>
                <td>\$".number_format($finalVal)."</td>
            </tr>";
        }
        $message .= "</tbody></table>";
    }
}

$message .= "</div><div style='text-align:center; padding:20px; color:#94a3b8; font-size:11px;'>Automated report &copy; Greache Trading System</div></div></body></html>";

$headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: Redline Radar <radar@greache.com>\r\n";
mail($to, "🚀 Redline Radar Report - " . date('d M Y'), $message, $headers);
echo "Email sent.";
?>