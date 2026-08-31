<?php
// ==========================================================
// Redline Radar - Version 2.7 (Sub-$1 Precision Update)
// ==========================================================

set_time_limit(0); 
date_default_timezone_set('Australia/Brisbane');

$is_cli = (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']));
$baseDir = __DIR__;
$apiKey = "API-KEY";

// --- DUAL LIST LOGIC ---
$dynamicFile = $baseDir . '/symbols_dynamic.php'; 
$customFile  = $baseDir . '/symbols_custom.php';  

$dynamicStocks = file_exists($dynamicFile) ? include $dynamicFile : [];
$customStocks  = file_exists($customFile) ? include $customFile : [];

if(!is_array($dynamicStocks)) $dynamicStocks = [];
if(!is_array($customStocks)) $customStocks = [];

$stocks = array_unique(array_merge($dynamicStocks, $customStocks));

// --- Variables ---
$defaultCapital = 100000; $volMultiplier = 10; $batchSize = 25; 
$cacheDir = $baseDir . '/cache'; $snapshotFile = $baseDir . '/signals_snapshot.json';
$favFile = $baseDir . '/favorites.json';
$favorites = file_exists($favFile) ? json_decode(file_get_contents($favFile), true) : [];

if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

$brisbaneNow = new DateTime("now", new DateTimeZone('Australia/Brisbane'));
$currentTime = $brisbaneNow->getTimestamp();
$cutoff = new DateTime("today 16:45:00", new DateTimeZone('Australia/Brisbane'));
if ($brisbaneNow < $cutoff) { $cutoff->modify('-1 day'); }
$cutoffTime = $cutoff->getTimestamp();

// AJAX Star Toggle
if (isset($_GET['toggle_fav'])) {
    $t = $_GET['toggle_fav'];
    if (in_array($t, $favorites)) { $favorites = array_values(array_diff($favorites, [$t])); } 
    else { $favorites[] = $t; }
    file_put_contents($favFile, json_encode($favorites));
    header('Content-Type: application/json'); echo json_encode(['status' => 'success']); exit;
}

// Snapshot Management
if (file_exists($snapshotFile)) {
    $snapshot = json_decode(file_get_contents($snapshotFile), true);
    if (!isset($snapshot['timestamp']) || $snapshot['timestamp'] < $cutoffTime) {
        $snapshot = ['date' => $brisbaneNow->format('d M Y H:i'), 'timestamp' => $currentTime, 'capital' => number_format($defaultCapital), 'buy' => [], 'exit' => [], 'watch' => [], 'processed' => []];
    }
} else {
    $snapshot = ['date' => $brisbaneNow->format('d M Y H:i'), 'timestamp' => $currentTime, 'capital' => number_format($defaultCapital), 'buy' => [], 'exit' => [], 'watch' => [], 'processed' => []];
}

// Helpers
function performFetch($ticker, $apiKey, $cacheDir) {
    $safeTicker = str_replace('.', '_', $ticker);
    $targetFile = $cacheDir . '/' . $safeTicker . '.csv';
    $url = "https://eodhd.com/api/eod/{$ticker}?api_token={$apiKey}&fmt=csv&from=" . date('Y-m-d', strtotime('-400 days'));
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $data = curl_exec($ch); curl_close($ch);
    if ($data && strpos($data, 'Date') !== false) { file_put_contents($targetFile, $data); return $data; } 
    elseif (file_exists($targetFile)) { return file_get_contents($targetFile); }
    return false;
}

function calculateEMA($data, $period) {
    $prices = array_filter(array_column($data, 'Close'), 'is_numeric');
    if (count($prices) < $period) throw new Exception('Low Data');
    $ema = []; $multiplier = 2 / ($period + 1);
    $ema[] = (float)array_shift($prices);
    foreach ($prices as $price) { $ema[] = ($price - end($ema)) * $multiplier + end($ema); }
    return $ema;
}

function processCsv($csv) {
    if (empty($csv)) return [];
    $lines = explode("\n", trim($csv)); if (count($lines) < 2) return [];
    $headers = str_getcsv(array_shift($lines));
    $data = array_map(function($line) use ($headers) { $row = str_getcsv($line); return (count($headers) == count($row)) ? array_combine($headers, $row) : null; }, $lines);
    return array_values(array_filter($data));
}

// Market Status (XJO)
$xjoFile = $cacheDir . '/XJO_INDX.csv';
if (!file_exists($xjoFile) || filemtime($xjoFile) < $cutoffTime) { performFetch('XJO.INDX', $apiKey, $cacheDir); }

// Worker Engine
$todoList = []; foreach ($stocks as $s) { if (!in_array($s, $snapshot['processed'])) { $todoList[] = $s; } }
if (count($todoList) > 0) {
    $workload = ($is_cli) ? $todoList : array_slice($todoList, 0, $batchSize);
    foreach ($workload as $stock) {
        $csvData = performFetch($stock, $apiKey, $cacheDir);
        try {
            $data = processCsv($csvData);
            if (count($data) >= 100) {
                $lastIdx = count($data) - 1; $latestClose = (float)$data[$lastIdx]['Close']; $yesterdayClose = (float)$data[$lastIdx - 1]['Close'];
                $ema15_arr = calculateEMA($data, 15); $ema65_arr = calculateEMA($data, 65);
                $latestEma15 = end($ema15_arr); $latestEma65 = end($ema65_arr);
                
                $rowType = "all";
                if ($latestClose > $latestEma15 && $yesterdayClose <= $latestEma15 && $yesterdayClose > $latestEma65) $rowType = "buy";
                elseif ($latestClose < $latestEma65 && $yesterdayClose >= $latestEma65) $rowType = "exit";
                elseif ($latestClose <= $latestEma15 && $latestClose > $latestEma65 && $yesterdayClose > $latestEma15) $rowType = "watch";
                
                if ($rowType !== "all") {
                    $riskVal = $defaultCapital * 0.01;
                    $phpShares = ($latestClose > $latestEma65) ? floor($riskVal / ($latestClose - $latestEma65)) : 0;
                    if (($phpShares * $latestClose) > ($defaultCapital * 0.25)) $phpShares = floor(($defaultCapital * 0.25) / $latestClose);
                    $phpValue = round(($phpShares * $latestClose) / 10) * 10;
                    
                    // Format output for engine snapshots dynamically
                    $displayDecimals = ($latestClose < 1.0) ? 3 : 2;
                    $snapshot[$rowType][] = ['ticker' => $stock, 'price' => number_format($latestClose, $displayDecimals), 'shares' => number_format($phpShares), 'value' => number_format($phpValue)];
                }
            }
        } catch (Exception $e) {}
        $snapshot['processed'][] = $stock;
        if (count($snapshot['processed']) % 10 == 0) file_put_contents($snapshotFile, json_encode($snapshot));
    }
    file_put_contents($snapshotFile, json_encode($snapshot));
    if (!$is_cli) {
        $remaining = count($todoList) - count($workload); $percent = round(((count($stocks) - $remaining) / count($stocks)) * 100);
        echo "<html><head><meta http-equiv='refresh' content='1'></head><body style='font-family:sans-serif; background:#f1f5f9; text-align:center; padding-top:100px;'><div style='max-width:450px; margin:auto; background:white; padding:30px; border-radius:12px; box-shadow:0 4px 10px rgba(0,0,0,0.1);'><h2>Refreshing Data...</h2><div style='background:#e2e8f0; height:20px; margin:20px 0; border-radius:10px;'><div style='background:#2563eb; width:$percent%; height:100%; border-radius:10px;'></div></div><p>Remaining: <b>$remaining stocks</b></p></div></body></html>"; exit;
    }
}

// Background Status Setup
$marketStatusClass = 'neutral-bg'; $xjoStatusText = "INDEX STALE"; 
if (file_exists($xjoFile)) {
    try { $xjoData = processCsv(file_get_contents($xjoFile)); if (count($xjoData) > 65) { $xjoEma65Arr = calculateEMA($xjoData, 65); $latestXjoClose = (float)end($xjoData)['Close']; $latestXjoEma65 = end($xjoEma65Arr);
    $marketStatusClass = ($latestXjoClose > $latestXjoEma65) ? 'market-bullish' : 'market-bearish'; $xjoStatusText = ($latestXjoClose > $latestXjoEma65) ? "BULLISH" : "CAUTION";
    if (filemtime($xjoFile) < $cutoffTime) { $xjoStatusText .= " (PRE-CLOSE)"; } } } catch (Exception $e) {}
}

if ($is_cli) { echo "Cron Complete.\n"; exit; }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Radar v2.7</title><style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 20px; transition: background 0.4s ease; }
body.market-bullish { background-image: url("green.png"); background-size: 100% auto; background-repeat: repeat-y; border-top: 6px solid #22c55e; } 
body.market-bearish { background-image: url("red.png"); background-size: 100% auto; background-repeat: repeat-y; border-top: 6px solid #ef4444; }
.container { max-width: 1450px; margin: 0 auto; background: rgba(255, 255, 255, 0.95); padding: 20px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
.header-bar { display: flex; align-items: center; justify-content: space-between; background: rgba(241, 245, 249, 0.85); padding: 10px 15px; border-radius: 8px; margin-bottom: 20px; border-left: 5px solid #334155; flex-wrap: wrap; gap: 10px; }
.market-badge { padding: 4px 12px; border-radius: 20px; font-weight: bold; font-size: 0.85em; text-transform: uppercase; text-align: center; }
.market-bullish .market-badge { background: #dcfce7; color: #166534; border: 1px solid #22c55e; }
.market-bearish .market-badge { background: #fee2e2; color: #991b1b; border: 1px solid #ef4444; }
.capital-input { padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; width: 110px; font-size: 14px; }
.stats-summary { font-size: 14px; color: #334155; }
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 10px; flex-wrap: wrap; }
.tabs { display: flex; gap: 5px; flex-wrap: wrap; }
.tabs button { padding: 8px 14px; border: 2px solid transparent; cursor: pointer; border-radius: 6px; font-weight: 600; background: #e2e8f0; font-size: 14px; }
.tabs button.active { border-color: #334155; }
.btn-buy { background: #dcfce7 !important; color: #166534 !important; }
.btn-exit { background: #fee2e2 !important; color: #991b1b !important; }
.btn-focus { background: #e0f2fe !important; color: #0369a1 !important; }
#stockSearch { padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px; width: 100%; max-width: 240px; }

tr.light-green td { background-color: #dcfce7 !important; } 
tr.light-red td { background-color: #fee2e2 !important; } 
tr.light-orange td { background-color: #fef3c7 !important; }

table { width:100%; border-collapse:collapse; margin-top: 10px; }
th, td { padding: 12px 10px; text-align:center; border:1px solid #eee; font-size: 14px; }
th { background:#f8fafc; cursor:pointer; color: #666; text-transform: uppercase; position: relative; user-select: none; }
th:hover { background: #edf2f7; }
th:after { content: ' \21C5'; font-size: 0.8em; opacity: 0.3; }
.ticker-link { font-weight:bold; color:#2563eb; text-decoration: none; }
.fav-star { cursor: pointer; font-size: 1.2em; color: #cbd5e1; transition: color 0.2s; margin-right: 8px; vertical-align: middle; }
.fav-star.active { color: #f59e0b; }
.flag-icon { font-size: 1.1em; display: inline-block; margin: 0 2px; }

/* RECONSTRUCTED MOBILE SLIDE-DOWN DRAWER SYSTEM */
.mobile-drawer-row { display: none; background: #f8fafc; }
.mobile-drawer-container { padding: 12px 20px; display: flex; flex-direction: column; gap: 8px; font-size: 13px; text-align: left; background: #fdfdfd; border-bottom: 2px solid #e2e8f0; }
.drawer-item { display: flex; justify-content: space-between; border-bottom: 1px dashed #e2e8f0; padding-bottom: 4px; }
.drawer-item label { font-weight: 600; color: #64748b; }

@media screen and (max-width: 768px) {
    th:nth-child(5), td:nth-child(5), /* Shares */
    th:nth-child(6), td:nth-child(6)  /* Value */ { display: none; }
    
    tbody tr[data-price] { cursor: pointer; }
    tbody tr[data-price]:active { background-color: #f1f5f9; }
}
</style></head><body class="<?php echo $marketStatusClass; ?>"><div class="container">
<div class="header-bar"><div class="market-badge"><?php echo $xjoStatusText; ?></div><div class="cap-controls"><label>Cap $</label><input type="number" id="tradingCapital" class="capital-input" value="<?php echo $defaultCapital; ?>" onchange="recalculateAll()"></div><div class="stats-summary">Risk: <b id="riskLabel"></b> | Max: <b id="maxLabel"></b></div></div>
<div class="toolbar"><div class="tabs"><button class="btn-all active" onclick="filterRows('all', this)">All</button><button class="btn-buy" onclick="filterRows('buy', this)">Buy</button><button class="btn-exit" onclick="filterRows('exit', this)">Exit</button><button class="btn-watch" onclick="filterRows('watch', this)">Watch</button><button class="btn-focus" onclick="filterRows('focus', this)">★ Focus</button></div><input type="text" id="stockSearch" placeholder="Search..." onkeyup="searchTable()"></div>
<table id="radarTable"><thead><tr>
<th onclick="sortTable(0)">Stock</th>
<th>!</th>
<th onclick="sortTable(2)">6M %</th>
<th onclick="sortTable(3)">Price</th>
<th onclick="sortTable(4)">Shares</th>
<th onclick="sortTable(5)">Value</th>
</tr></thead><tbody>
<?php
foreach ($stocks as $stock) {
    $safeTicker = str_replace('.', '_', $stock); $csvPath = $cacheDir . '/' . $safeTicker . '.csv';
    if (!file_exists($csvPath)) continue;
    $csv = file_get_contents($csvPath);
    try {
        $data = processCsv($csv); if (count($data) < 100) throw new Exception("Low Data");
        $lastIdx = count($data) - 1; $latestClose = (float)$data[$lastIdx]['Close']; $yesterdayClose = (float)$data[$lastIdx - 1]['Close'];
        $ema15_arr = calculateEMA($data, 15); $ema65_arr = calculateEMA($data, 65);
        $latestEma15 = end($ema15_arr); $latestEma65 = end($ema65_arr);
        $yesterdayEma15 = $ema15_arr[count($ema15_arr)-2];
        $yesterdayEma65 = $ema65_arr[count($ema65_arr)-2];

        $volSlice = array_slice($data, -20);
        $avgDollarVol = array_sum(array_map(function($d){ return (float)$d['Close'] * (float)$d['Volume']; }, $volSlice)) / 20;

        $sixMoIdx = count($data) - 126; $sixMoDisplay = "N/A";
        if ($sixMoIdx >= 0) { $priceSixMoAgo = (float)$data[$sixMoIdx]['Close']; $sixMoGrowth = (($latestClose - $priceSixMoAgo) / $priceSixMoAgo) * 100; $sixMoDisplay = number_format($sixMoGrowth, 1) . '%'; }
        
        $rowType = "all";
        if ($latestClose > $latestEma15 && $yesterdayClose <= $latestEma15 && $yesterdayClose > $latestEma65) $rowType = "buy";
        elseif ($latestClose < $latestEma65 && $yesterdayClose >= $latestEma65) $rowType = "exit";
        elseif ($latestClose <= $latestEma15 && $latestClose > $latestEma65 && $yesterdayClose > $latestEma15) $rowType = "watch";
        
        $rowClass = ($rowType == "buy") ? "light-green" : (($rowType == "exit") ? "light-red" : (($rowType == "watch") ? "light-orange" : ""));
        $isFav = in_array($stock, $favorites) ? 'active' : ''; $favData = in_array($stock, $favorites) ? 'true' : 'false';
        $parts = explode('.', $stock); $tvTicker = $parts[0]; $tvExch = (isset($parts[1]) && $parts[1] === 'US') ? '' : 'ASX%3A';
        $chartUrl = "https://www.tradingview.com/chart/QL9IAyDB/?symbol=" . $tvExch . $tvTicker;
        
        $isBelow65 = ($latestClose < $latestEma65) ? "inline-block" : "none";

        // FIXED: Conditional precision setting for micro-cap assets trading below $1.00
        $precision = ($latestClose < 1.0) ? 3 : 2;

        // Main Stock Data Row
        echo "<tr class='$rowClass' data-type='$rowType' data-fav='$favData' data-price='$latestClose' data-ema65='$latestEma65' data-avgvol='$avgDollarVol' onclick='toggleDrawer(this)'>
                <td style='text-align:left; padding-left:20px;'>
                    <span class='fav-star $isFav' onclick='toggleFav(\"$stock\", event)'>★</span>
                    <a href='$chartUrl' target='_blank' class='ticker-link' onclick='event.stopPropagation();'>$stock</a>
                </td>
                <td style='width:60px;'>
                    <span class='flag-icon liq-flag' title='Low Liquidity'>💧</span>
                    <span class='flag-icon' style='display:$isBelow65' title='Price below 65 EMA'>📉</span>
                </td>
                <td class='growth-cell'>$sixMoDisplay</td>
                <td>\$".number_format($latestClose, $precision)."</td>
                <td class='share-cell'>-</td>
                <td class='value-cell'>-</td>
              </tr>";
              
        // Hidden Slide-down Mobile Drawer Row (Now only contains Shares & Value)
        echo "<tr class='mobile-drawer-row'><td colspan='4'>
                <div class='mobile-drawer-container'>
                    <div class='drawer-item'><label>Rec. Shares:</label><span class='m-share-cell'>-</span></div>
                    <div class='drawer-item'><label>Total Value:</label><span class='m-value-cell'>-</span></div>
                </div>
              </td></tr>";
    } catch (Exception $e) {}
}
?>
</tbody></table></div><script>
const volMultiplier = <?php echo $volMultiplier; ?>;
function recalculateAll() {
    const capital = parseFloat(document.getElementById("tradingCapital").value); const risk = capital * 0.01; const maxPos = capital * 0.25;
    const liqThreshold = capital * volMultiplier;
    document.getElementById("riskLabel").innerText = "$" + Math.round(risk).toLocaleString();
    document.getElementById("maxLabel").innerText = "$" + Math.round(maxPos).toLocaleString();
    document.querySelectorAll("#radarTable tbody tr[data-price]").forEach(row => {
        const price = parseFloat(row.dataset.price); const ema65 = parseFloat(row.dataset.ema65);
        const avgVol = parseFloat(row.dataset.avgvol);
        const liqFlag = row.querySelector(".liq-flag");
        if(liqFlag) liqFlag.style.display = (avgVol < liqThreshold) ? "inline-block" : "none";
        
        let shares = (price > ema65) ? Math.floor(risk / (price - ema65)) : 0; if ((shares * price) > maxPos) shares = Math.floor(maxPos / price);
        
        // Populate standard layout cells
        row.querySelector(".share-cell").innerText = shares.toLocaleString();
        row.querySelector(".value-cell").innerText = "$" + (Math.round((shares * price) / 10) * 10).toLocaleString();
        
        // Populate mobile mirror items inside the adjacent drawer row
        const nextRow = row.nextElementSibling;
        if(nextRow && nextRow.classList.contains("mobile-drawer-row")) {
            nextRow.querySelector(".m-share-cell").innerText = shares.toLocaleString();
            nextRow.querySelector(".m-value-cell").innerText = "$" + (Math.round((shares * price) / 10) * 10).toLocaleString();
        }
    });
}
function toggleDrawer(row) {
    if (window.innerWidth > 768) return; 
    const drawer = row.nextElementSibling;
    if (drawer && drawer.classList.contains("mobile-drawer-row")) {
        const isVisible = drawer.style.display === "table-row";
        document.querySelectorAll(".mobile-drawer-row").forEach(d => d.style.display = "none");
        drawer.style.display = isVisible ? "none" : "table-row";
    }
}
function toggleFav(ticker, e) {
    e.stopPropagation(); fetch("?toggle_fav=" + ticker).then(res => res.json()).then(() => { const star = e.target; star.classList.toggle("active"); star.closest("tr").dataset.fav = star.classList.contains("active") ? "true" : "false"; });
}
function filterRows(type, btn) {
    document.querySelectorAll(".tabs button").forEach(b => b.classList.remove("active")); btn.classList.add("active");
    const search = document.getElementById("stockSearch").value.toUpperCase();
    document.querySelectorAll("#radarTable tbody tr[data-type]").forEach(row => {
        const matchesType = (type === "all") || (type === "focus" && row.dataset.fav === "true") || (row.dataset.type === type);
        const matchesSearch = row.cells[0].innerText.toUpperCase().includes(search);
        
        const drawer = row.nextElementSibling;
        if (matchesType && matchesSearch) {
            row.style.display = "table-row";
        } else {
            row.style.display = "none";
            if(drawer && drawer.classList.contains("mobile-drawer-row")) drawer.style.display = "none";
        }
    });
}
function searchTable() { 
    const activeTab = document.querySelector(".tabs button.active").innerText.toLowerCase().replace("★ ", "");
    filterRows(activeTab, document.querySelector(".tabs button.active")); 
}

let sortDir = 1;
function sortTable(n) {
    const tbody = document.querySelector("#radarTable tbody");
    const rows = Array.from(tbody.querySelectorAll("tr[data-price]"));
    sortDir *= -1;
    
    rows.sort((a, b) => {
        let aVal = a.cells[n].innerText.replace(/[^\d.-]/g, '');
        let bVal = b.cells[n].innerText.replace(/[^\d.-]/g, '');
        if (n === 0) { return a.cells[n].innerText.localeCompare(b.cells[n].innerText) * sortDir; }
        return (parseFloat(aVal) - parseFloat(bVal)) * sortDir;
    });
    
    rows.forEach(row => {
        tbody.appendChild(row);
        if(row.nextElementSibling && row.nextElementSibling.classList.contains("mobile-drawer-row")) {
            tbody.appendChild(row.nextElementSibling);
        }
    });
}
window.onload = recalculateAll;
</script></body></html>