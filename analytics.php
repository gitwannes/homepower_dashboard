<?php
# --- file: analytics.php ---
/**
 * Historical exploration: intraday, two-day overlay, clear-sky envelope.
 * Period rollups live on summary.php.
 */
date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/site_settings.php';
require_once __DIR__ . '/balance_chart.php';
require_once __DIR__ . '/explorer_chart.php';

$hp = hp_boot();
hp_require_ready($hp);
hp_publish_pac($hp['settings']);
$dbFile = $hp['db'];
$chartView = hp_chart_view();
$explorerWindow = hp_explorer_window();

/**
 * @param mixed $w PAC watts
 * @param int|null $hour 0..23 from timestamp (null = skip night gate)
 * @param mixed $rad solar_radiation W/m2 (null treated as 0 when hour set)
 * @return float|null null if missing, spike, deep night, or shoulder with no sun
 */
function sanitize_pac_w($w, $hour = null, $rad = null)
{
    if ($w === null || $w === '') {
        return null;
    }
    $v = (float)$w;
    $cap = isset($GLOBALS['HP_PAC_CAP']) ? (float)$GLOBALS['HP_PAC_CAP'] : (12000.0 * 1.05);
    if ($v <= 0 || $v > $cap) {
        return null;
    }
    if ($hour !== null) {
        $h = (int)$hour;
        $nightStart = isset($GLOBALS['HP_PAC_NIGHT_START']) ? (int)$GLOBALS['HP_PAC_NIGHT_START'] : 22;
        $nightEnd = isset($GLOBALS['HP_PAC_NIGHT_END']) ? (int)$GLOBALS['HP_PAC_NIGHT_END'] : 5;
        $shoulderStart = isset($GLOBALS['HP_PAC_SHOULDER_START']) ? (int)$GLOBALS['HP_PAC_SHOULDER_START'] : 20;
        $shoulderEnd = isset($GLOBALS['HP_PAC_SHOULDER_END']) ? (int)$GLOBALS['HP_PAC_SHOULDER_END'] : 6;
        $radFloor = isset($GLOBALS['HP_PAC_RAD']) ? (float)$GLOBALS['HP_PAC_RAD'] : 5.0;
        // Deep night: never trust PAC (hour >= start OR hour < end).
        if ($h >= $nightStart || $h < $nightEnd) {
            return null;
        }
        $r = ($rad === null || $rad === '') ? 0.0 : (float)$rad;
        // Shoulder evening/morning: PAC without light.
        if ($r <= $radFloor && ($h >= $shoulderStart || $h < $shoulderEnd)) {
            return null;
        }
    }
    return $v;
}

/**
 * Median of a numeric list (empty -> 0).
 *
 * @param list<float> $vals
 */
function median_float(array $vals): float
{
    $n = count($vals);
    if ($n === 0) {
        return 0.0;
    }
    sort($vals, SORT_NUMERIC);
    $mid = (int)floor($n / 2);
    if ($n % 2 === 1) {
        return (float)$vals[$mid];
    }
    return ((float)$vals[$mid - 1] + (float)$vals[$mid]) / 2.0;
}

/**
 * Drop PAC needles vs local median (not max). Twin adjacent spikes defeat max-of-neighbors;
 * median + a few passes catches them. Mutates $pac / $pacDates in place.
 *
 * @param list<float> $pac
 * @param list<string|null> $pacDates
 */
function despike_pac_series(array &$pac, array &$pacDates, int $radius = 4, float $ratio = 1.15, int $passes = 4): void
{
    $n = count($pac);
    if ($n < 3) {
        return;
    }
    for ($pass = 0; $pass < $passes; $pass++) {
        $cleaned = $pac;
        $changed = false;
        for ($i = 0; $i < $n; $i++) {
            if ($pac[$i] <= 0) {
                continue;
            }
            $lo = max(0, $i - $radius);
            $hi = min($n - 1, $i + $radius);
            $neigh = [];
            for ($j = $lo; $j <= $hi; $j++) {
                if ($j === $i) {
                    continue;
                }
                if ($pac[$j] > 0) {
                    $neigh[] = $pac[$j];
                }
            }
            if (count($neigh) < 2) {
                continue;
            }
            $ref = median_float($neigh);
            if ($ref > 0 && $pac[$i] > $ref * $ratio) {
                $cleaned[$i] = $ref;
                $pacDates[$i] = null;
                $changed = true;
            }
        }
        $pac = $cleaned;
        if (!$changed) {
            break;
        }
    }
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    if (!file_exists($dbFile)) {
        echo json_encode(['error' => 'Database not found']);
        exit;
    }

    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        function getEmptyDayTemplate() {
            $slots = [];
            for ($h = 0; $h < 24; $h++) {
                for ($m = 0; $m < 60; $m += 5) {
                    $slots[sprintf('%02d:%02d', $h, $m)] = 0.0;
                }
            }
            return $slots;
        }

        /** @return array<string, null> */
        function getEmptyDayNullTemplate(): array
        {
            $slots = [];
            for ($h = 0; $h < 24; $h++) {
                for ($m = 0; $m < 60; $m += 5) {
                    $slots[sprintf('%02d:%02d', $h, $m)] = null;
                }
            }
            return $slots;
        }

        $action = $_GET['ajax'];

        if ($action === 'range') {
            $min = null;
            $max = null;
            try {
                $row = $pdo->query(
                    "SELECT substr(MIN(timestamp), 1, 10) AS dmin, substr(MAX(timestamp), 1, 10) AS dmax
                     FROM history_5min"
                )->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $min = $row['dmin'];
                    $max = $row['dmax'];
                }
            } catch (Exception $e) {
            }
            echo json_encode(['min' => $min, 'max' => $max]);
            exit;
        }

        if ($action === 'explorer') {
            $win = hp_explorer_window();
            if (isset($_GET['pan']) && ($_GET['pan'] === '-1' || $_GET['pan'] === '1')) {
                $panned = hp_explorer_pan($win, (int)$_GET['pan']);
                $win['start'] = $panned['start'];
                $win['end'] = $panned['end'];
                $win['custom'] = true;
                $win['preset'] = '';
            }
            $payload = hp_explorer_mixed_series(
                $pdo,
                $win['start'],
                $win['end'],
                'sanitize_pac_w'
            );
            echo json_encode([
                'ok' => true,
                'start' => $win['start'],
                'end' => $win['end'],
                'preset' => $win['preset'],
                'custom' => $win['custom'],
                'balance' => $payload['balance'],
                'tip_bands' => $payload['tip_bands'],
                'classic' => $payload['classic'],
                'sources' => $payload['sources'],
            ]);
            exit;
        }

        if ($action === 'day' && isset($_GET['date'])) {
            $date = $_GET['date'];
            $view = isset($_GET['view']) && strtolower(trim((string)$_GET['view'])) === 'classic'
                ? 'classic'
                : 'balance';

            if ($view === 'classic') {
                $slots = getEmptyDayTemplate();
                $stmt = $pdo->prepare(
                    "SELECT strftime('%H:%M', timestamp) as time_slot,
                            CAST(strftime('%H', timestamp) AS INTEGER) as hh,
                            solar_power_w, solar_radiation
                     FROM history_5min
                     WHERE timestamp LIKE ?
                     ORDER BY timestamp ASC"
                );
                $stmt->execute([$date . '%']);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (isset($slots[$row['time_slot']])) {
                        $pac = sanitize_pac_w($row['solar_power_w'], (int)$row['hh'], $row['solar_radiation']);
                        $slots[$row['time_slot']] = $pac !== null ? $pac : 0.0;
                    }
                }
                echo json_encode([
                    'view' => 'classic',
                    'categories' => array_keys($slots),
                    'series' => array_values($slots),
                ]);
                exit;
            }

            $production = getEmptyDayNullTemplate();
            $consumption = getEmptyDayNullTemplate();
            $selfSufficient = getEmptyDayNullTemplate();
            $gridBand = getEmptyDayNullTemplate();
            $surplus = getEmptyDayNullTemplate();
            $stmt = $pdo->prepare(
                "SELECT strftime('%H:%M', timestamp) as time_slot,
                        CAST(strftime('%H', timestamp) AS INTEGER) as hh,
                        solar_power_w, solar_radiation, grid_power_w,
                        grid_import_kwh, grid_export_kwh
                 FROM history_5min
                 WHERE timestamp LIKE ?
                 ORDER BY timestamp ASC"
            );
            $stmt->execute([$date . '%']);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $slot = $row['time_slot'];
                if (!array_key_exists($slot, $production)) {
                    continue;
                }
                $pac = sanitize_pac_w($row['solar_power_w'], (int)$row['hh'], $row['solar_radiation']);
                if ($row['solar_power_w'] === null || $row['solar_power_w'] === '') {
                    $solarW = null;
                } elseif ($pac !== null) {
                    $solarW = $pac;
                } else {
                    // Spike / night / shoulder gate: no solar contribution.
                    $solarW = 0.0;
                }
                $gridNet = hp_grid_net_w(
                    $row['grid_power_w'],
                    $row['grid_import_kwh'],
                    $row['grid_export_kwh']
                );
                $parts = hp_balance_parts($solarW, $gridNet);
                if ($parts === null || $parts['consumption'] === null) {
                    continue;
                }
                $production[$slot] = $parts['production'];
                $consumption[$slot] = $parts['consumption'];
                $selfSufficient[$slot] = $parts['self_sufficient'];
                $gridBand[$slot] = $parts['grid'];
                $surplus[$slot] = $parts['surplus'];
            }
            $categories = array_keys($production);
            // Bridge short Fluvius gaps, then zero-fill leftovers for stacked Apex.
            $zeroFill = static function (array $vals): array {
                $bridged = hp_bridge_short_nulls($vals, 2);
                $out = [];
                foreach ($bridged as $v) {
                    $out[] = hp_chart_watt0($v === null ? null : (float)$v);
                }
                return $out;
            };
            echo json_encode([
                'view' => 'balance',
                'categories' => $categories,
                'production' => $zeroFill(array_values($production)),
                'consumption' => $zeroFill(array_values($consumption)),
                'self_sufficient' => $zeroFill(array_values($selfSufficient)),
                'grid' => $zeroFill(array_values($gridBand)),
                'surplus' => $zeroFill(array_values($surplus)),
            ]);
            exit;
        }

        if ($action === 'compare' && isset($_GET['date1']) && isset($_GET['date2'])) {
            $d1 = $_GET['date1'];
            $d2 = $_GET['date2'];
            $slots1 = getEmptyDayTemplate();
            $slots2 = getEmptyDayTemplate();
            $stmt1 = $pdo->prepare(
                "SELECT strftime('%H:%M', timestamp) as time_slot,
                        CAST(strftime('%H', timestamp) AS INTEGER) as hh,
                        solar_power_w, solar_radiation
                 FROM history_5min WHERE timestamp LIKE ?"
            );
            $stmt1->execute([$d1 . '%']);
            while ($row = $stmt1->fetch(PDO::FETCH_ASSOC)) {
                if (isset($slots1[$row['time_slot']])) {
                    $pac = sanitize_pac_w($row['solar_power_w'], (int)$row['hh'], $row['solar_radiation']);
                    $slots1[$row['time_slot']] = $pac !== null ? $pac : 0.0;
                }
            }
            $stmt2 = $pdo->prepare(
                "SELECT strftime('%H:%M', timestamp) as time_slot,
                        CAST(strftime('%H', timestamp) AS INTEGER) as hh,
                        solar_power_w, solar_radiation
                 FROM history_5min WHERE timestamp LIKE ?"
            );
            $stmt2->execute([$d2 . '%']);
            while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                if (isset($slots2[$row['time_slot']])) {
                    $pac = sanitize_pac_w($row['solar_power_w'], (int)$row['hh'], $row['solar_radiation']);
                    $slots2[$row['time_slot']] = $pac !== null ? $pac : 0.0;
                }
            }
            echo json_encode([
                'categories' => array_keys($slots1),
                'series1' => array_values($slots1),
                'series2' => array_values($slots2),
            ]);
            exit;
        }

        // month=1..12 or month=0 / all = whole-year composite (MAX across months per timepoint)
        if ($action === 'envelope' && isset($_GET['month'])) {
            $monthRaw = strtolower(trim((string)$_GET['month']));
            $wholeYear = ($monthRaw === '0' || $monthRaw === 'all' || $monthRaw === 'year');
            $month = (int)$monthRaw;
            if (!$wholeYear && ($month < 1 || $month > 12)) {
                echo json_encode(['error' => 'Invalid month']);
                exit;
            }
            $categories = [];
            $pac = [];
            $rad = [];
            $pacDates = [];
            for ($tp = 0; $tp < 288; $tp++) {
                $h = (int)floor($tp / 12);
                $mi = ($tp % 12) * 5;
                $categories[] = sprintf('%02d:%02d', $h, $mi);
                $pac[] = 0.0;
                $rad[] = 0.0;
                $pacDates[] = null;
            }
            $filled = 0;
            try {
                if ($wholeYear) {
                    $stmt = $pdo->query(
                        'SELECT timepoint, max_pac_w, max_pac_date, max_rad_wm2, month
                         FROM clear_sky_envelopes ORDER BY timepoint ASC, month ASC'
                    );
                } else {
                    $stmt = $pdo->prepare(
                        'SELECT timepoint, max_pac_w, max_pac_date, max_rad_wm2, month
                         FROM clear_sky_envelopes WHERE month = ? ORDER BY timepoint ASC'
                    );
                    $stmt->execute([$month]);
                }
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $tp = (int)$row['timepoint'];
                    if ($tp < 0 || $tp > 287) {
                        continue;
                    }
                    if ($row['max_pac_w'] !== null) {
                        $v = sanitize_pac_w($row['max_pac_w']);
                        if ($v !== null && $v >= $pac[$tp]) {
                            $pac[$tp] = $v;
                            $pacDates[$tp] = $row['max_pac_date'];
                            if ($wholeYear && !empty($row['month'])) {
                                $pacDates[$tp] = $row['max_pac_date']
                                    ? ($row['max_pac_date'] . ' (m' . (int)$row['month'] . ')')
                                    : ('m' . (int)$row['month']);
                            }
                        }
                        if ($v !== null) {
                            $filled++;
                        }
                    }
                    if ($row['max_rad_wm2'] !== null) {
                        $rv = (float)$row['max_rad_wm2'];
                        if ($rv > $rad[$tp]) {
                            $rad[$tp] = $rv;
                        }
                    }
                }
            } catch (Exception $e) {
                echo json_encode([
                    'error' => 'clear_sky_envelopes missing - run rollup.py -buildall',
                    'detail' => $e->getMessage(),
                ]);
                exit;
            }
            // Soft despike: hard cap alone leaves mid-range corrupt surges (e.g. ~8 kW needles).
            despike_pac_series($pac, $pacDates);

            // Count slots that actually have power (avoid counting zero-filled blanks as filled)
            $nonzero = 0;
            foreach ($pac as $v) {
                if ($v > 0) {
                    $nonzero++;
                }
            }
            echo json_encode([
                'month' => $wholeYear ? 0 : $month,
                'label' => $wholeYear ? 'Whole year' : $month,
                'filled' => $nonzero,
                'categories' => $categories,
                'max_pac_w' => $pac,
                'max_rad_wm2' => $rad,
                'max_pac_date' => $pacDates,
            ]);
            exit;
        }

        echo json_encode(['error' => 'Unknown action']);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$defaultDay = $yesterday;
// Deep link from topn.php: ?date=YYYY-MM-DD
if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])) {
    $defaultDay = (string)$_GET['date'];
}
$defaultCompare1 = $today;
$defaultCompare2 = $yesterday;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historical Analytics | Energy Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        body { background-color: #f8fafc; font-family: system-ui, -apple-system, sans-serif; }
        .card { @apply bg-white p-6 rounded-3xl shadow-sm border border-slate-100 flex flex-col; }
        .jump-btn {
            @apply text-xs font-semibold px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white
                   text-slate-600 hover:bg-slate-50 hover:text-indigo-600 transition-colors cursor-pointer;
        }
        .nav-arrow {
            @apply w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-700
                   flex items-center justify-center hover:bg-slate-50 shadow-sm transition-colors cursor-pointer;
        }
        .date-wrap {
            @apply inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 pl-2 pr-1 py-0.5;
        }
        .date-wrap input[type="text"] {
            @apply text-sm px-1 py-1 bg-transparent border-0 outline-none text-slate-700 font-mono w-[9.5rem];
        }
        .date-icon-btn {
            @apply w-8 h-8 rounded-md text-slate-500 hover:text-indigo-600 hover:bg-white
                   flex items-center justify-center transition-colors cursor-pointer;
        }
        .date-native {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }
        .metric-switch {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px;
            border: 2px solid #0f172a;
            border-radius: 999px;
            background: #e2e8f0;
        }
        .metric-switch button {
            appearance: none;
            -webkit-appearance: none;
            margin: 0;
            border: 0;
            border-radius: 999px;
            padding: 6px 14px;
            font: 700 12px/1.2 system-ui, sans-serif;
            color: #334155;
            background: transparent;
            cursor: pointer;
        }
        .metric-switch button.metric-on {
            background: #0f172a;
            color: #ffffff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.25);
        }
        .is-hidden { display: none !important; }
    </style>
</head>
<body class="p-4 md:p-8">

<div class="max-w-6xl mx-auto space-y-6">

    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <a href="index.php" class="w-10 h-10 rounded-2xl bg-white border border-slate-200 text-slate-600 flex items-center justify-center hover:bg-slate-50 transition-colors shadow-sm">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-black text-slate-900 tracking-tight">Historical Analytics</h1>
                    <p class="text-xs text-slate-400 mt-0.5">Balance explorer, day compare, clear-sky envelope</p>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="activity" class="w-3.5 h-3.5 text-emerald-600"></i> Live Dashboard
            </a>
            <a href="summary.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="calendar-range" class="w-3.5 h-3.5 text-emerald-600"></i> Summary
            </a>
            <a href="topn.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="trophy" class="w-3.5 h-3.5 text-amber-500"></i> Top-N
            </a>
            <?= hp_nav_settings() ?>
        </div>
    </header>

    <div class="card border-t-4 border-t-amber-500">
        <div class="flex flex-col gap-3 mb-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 id="dayChartHeading" class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i data-lucide="activity" class="w-5 h-5 text-amber-500"></i>
                        <span id="dayChartTitleText"><?php
                            if ($chartView === 'exchange') {
                                echo 'Grid exchange';
                            } elseif ($chartView === 'classic') {
                                echo 'Power History';
                            } else {
                                echo 'Energy Balance';
                            }
                        ?></span>
                    </h2>
                    <p id="dayChartBlurb" class="text-xs text-slate-400">
                        <?php
                        if ($chartView === 'exchange') {
                            echo 'Mixed 1-min / 5-min signed net. Above 0 = from grid, below 0 = surplus to grid.';
                        } elseif ($chartView === 'classic') {
                            echo 'Mixed 1-min / 5-min solar / home profile for the selected window.';
                        } else {
                            echo 'Mixed 1-min / 5-min. Bands: self-sufficient / grid / surplus. Production and consumption in the tooltip.';
                        }
                        ?>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="metric-switch" role="group" aria-label="Chart view">
                        <button type="button" id="day-view-exchange" class="<?= $chartView === 'exchange' ? 'metric-on' : '' ?>" aria-pressed="<?= $chartView === 'exchange' ? 'true' : 'false' ?>" onclick="setDayChartView('exchange')">Exchange</button>
                        <button type="button" id="day-view-balance" class="<?= $chartView === 'balance' ? 'metric-on' : '' ?>" aria-pressed="<?= $chartView === 'balance' ? 'true' : 'false' ?>" onclick="setDayChartView('balance')">Balance</button>
                        <button type="button" id="day-view-classic" class="<?= $chartView === 'classic' ? 'metric-on' : '' ?>" aria-pressed="<?= $chartView === 'classic' ? 'true' : 'false' ?>" onclick="setDayChartView('classic')">Classic</button>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Chart range">
                <?php foreach (hp_live_chart_presets() as $presetId => $preset): ?>
                    <?php $presetOn = !$explorerWindow['custom'] && $explorerWindow['preset'] === $presetId; ?>
                    <button type="button"
                            class="px-2.5 py-1 rounded-full text-[11px] font-semibold border explorer-preset <?= $presetOn ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200' ?>"
                            data-range="<?= htmlspecialchars($presetId, ENT_QUOTES, 'UTF-8') ?>"
                            onclick="setExplorerPreset('<?= htmlspecialchars($presetId, ENT_QUOTES, 'UTF-8') ?>')"><?= htmlspecialchars($preset['label'], ENT_QUOTES, 'UTF-8') ?></button>
                <?php endforeach; ?>
                <button type="button" class="nav-arrow" onclick="panExplorer(-1)" title="Pan back one window" aria-label="Pan back">
                    <i data-lucide="chevron-left" class="w-5 h-5"></i>
                </button>
                <button type="button" class="nav-arrow" onclick="panExplorer(1)" title="Pan forward one window" aria-label="Pan forward">
                    <i data-lucide="chevron-right" class="w-5 h-5"></i>
                </button>
                <button type="button" class="jump-btn" onclick="resetExplorerView()" title="Last 6 hours ending now">Reset view</button>
                <span id="explorerWindowLabel" class="text-[11px] text-slate-500 font-mono ml-1"></span>
            </div>
            <div id="explorerBalanceLegend" class="flex flex-wrap items-center gap-3 text-xs <?= $chartView === 'balance' ? '' : 'is-hidden' ?>">
                <span class="inline-flex items-center gap-1.5 text-blue-600 font-medium"><span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span> Self-sufficient</span>
                <span class="inline-flex items-center gap-1.5 text-violet-600 font-medium"><span class="w-2.5 h-2.5 rounded-full bg-violet-600"></span> Grid</span>
                <span class="inline-flex items-center gap-1.5 text-emerald-600 font-medium"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Surplus</span>
                <span class="inline-flex items-center gap-1.5 text-slate-600 font-medium" title="Self-sufficient + Grid (tooltip)">
                    <span class="w-2.5 h-2.5 rounded-full inline-block" style="background:linear-gradient(90deg,#3b82f6 50%,#7c3aed 50%)"></span> Consumption
                </span>
                <span class="inline-flex items-center gap-1.5 text-slate-600 font-medium" title="Self-sufficient + Surplus (tooltip)">
                    <span class="w-2.5 h-2.5 rounded-full inline-block" style="background:linear-gradient(90deg,#3b82f6 50%,#10b981 50%)"></span> Production
                </span>
            </div>
            <div id="explorerExchangeLegend" class="flex flex-wrap items-center gap-3 text-xs <?= $chartView === 'exchange' ? '' : 'is-hidden' ?>">
                <span class="inline-flex items-center gap-1.5 text-violet-600 font-medium"><span class="w-2.5 h-2.5 rounded-full bg-violet-600"></span> Grid</span>
                <span class="inline-flex items-center gap-1.5 text-emerald-600 font-medium"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Surplus</span>
            </div>
        </div>
        <div id="chart-intraday" class="w-full h-[400px]"></div>
    </div>

    <div class="card border-t-4 border-t-indigo-500">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                    <i data-lucide="layers" class="w-5 h-5 text-indigo-500"></i> Two-Day Overlay
                </h2>
                <p class="text-xs text-slate-400">Defaults: today vs yesterday - type or use the calendar icon</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 bg-slate-50 p-2 rounded-xl border border-slate-100">
                <div class="flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                    <div class="date-wrap relative">
                        <input type="text" id="compareText1" inputmode="numeric" placeholder="YYYY-MM-DD" maxlength="10"
                               value="<?= htmlspecialchars($defaultCompare1) ?>" autocomplete="off" spellcheck="false">
                        <input type="date" id="comparePicker1" class="date-native" value="<?= htmlspecialchars($defaultCompare1) ?>" tabindex="-1" aria-hidden="true">
                        <button type="button" class="date-icon-btn" title="Open calendar" aria-label="Open calendar"
                                onclick="openDatePicker('comparePicker1')">
                            <i data-lucide="calendar" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
                <span class="text-slate-400 text-xs font-bold uppercase">VS</span>
                <div class="flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                    <div class="date-wrap relative">
                        <input type="text" id="compareText2" inputmode="numeric" placeholder="YYYY-MM-DD" maxlength="10"
                               value="<?= htmlspecialchars($defaultCompare2) ?>" autocomplete="off" spellcheck="false">
                        <input type="date" id="comparePicker2" class="date-native" value="<?= htmlspecialchars($defaultCompare2) ?>" tabindex="-1" aria-hidden="true">
                        <button type="button" class="date-icon-btn" title="Open calendar" aria-label="Open calendar"
                                onclick="openDatePicker('comparePicker2')">
                            <i data-lucide="calendar" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div id="chart-compare" class="w-full h-[400px]"></div>
    </div>

    <div class="card border-t-4 border-t-sky-500">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                    <i data-lucide="cloud-sun" class="w-5 h-5 text-sky-500"></i> Clear-sky Envelope
                </h2>
                <p class="text-xs text-slate-400">
                    All-time max AC power at each 5-min slot
                    (<code>clear_sky_envelopes</code>).
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="nav-arrow" onclick="stepEnvelope(-1)" title="Previous" aria-label="Previous period">
                    <i data-lucide="chevron-left" class="w-5 h-5"></i>
                </button>
                <select id="envelopeMonth" class="text-sm px-3 py-1.5 border border-slate-200 rounded-lg text-slate-700 bg-slate-50 min-w-[8rem]"
                        onchange="loadEnvelope()">
                    <option value="0">Whole year</option>
                    <?php
                    $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                                   7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
                    $curM = (int)date('n');
                    foreach ($monthNames as $num => $name) {
                        $sel = ($num === $curM) ? ' selected' : '';
                        echo "<option value=\"{$num}\"{$sel}>{$name}</option>\n";
                    }
                    ?>
                </select>
                <button type="button" class="nav-arrow" onclick="stepEnvelope(1)" title="Next" aria-label="Next period">
                    <i data-lucide="chevron-right" class="w-5 h-5"></i>
                </button>
            </div>
        </div>
        <div id="chart-envelope" class="w-full h-[400px]"></div>
        <div id="envelope-warning" class="hidden text-xs text-amber-600 bg-amber-50 border border-amber-200 p-3 rounded-xl mt-4"></div>
    </div>

</div>

<script>
    lucide.createIcons();

    const TODAY_ISO = <?= json_encode($today) ?>;

    let chartIntraday = null;
    let chartCompare = null;
    let chartEnvelope = null;
    /** @type {{min: string|null, max: string|null}} */
    let dayRange = { min: null, max: null };

    /**
     * Dense 288-point time axes: label whole hours only.
     * Resolve numeric index -> category (Apex sometimes passes the index).
     */
    function hourOnlyLabel(val, opts) {
        let s = val;
        if (typeof val === 'number') {
            const cats = (opts && opts.w && opts.w.config && opts.w.config.xaxis && opts.w.config.xaxis.categories) || [];
            s = cats[val] != null ? cats[val] : '';
        }
        if (typeof s !== 'string') return '';
        return s.endsWith(':00') ? s : '';
    }

    const timeAxisLabels = {
        rotate: 0,
        rotateAlways: false,
        hideOverlappingLabels: true,
        showDuplicates: false,
        trim: false,
        style: { colors: '#94a3b8', fontSize: '10px' },
        formatter: hourOnlyLabel
    };

    /**
     * Custom tooltip: Apex reuses blanked axis labels for the title when hourOnlyLabel
     * returns '' for non-:00 ticks. Always read HH:MM from config.categories.
     */
    function pacTooltip(opts) {
        const cats = (opts.w && opts.w.config && opts.w.config.xaxis && opts.w.config.xaxis.categories) || [];
        const i = opts.dataPointIndex;
        const time = (i != null && cats[i] != null) ? String(cats[i]) : '';
        const series = opts.w.config.series || [];
        const colors = opts.w.globals.colors || [];
        let rows = '';
        for (let s = 0; s < series.length; s++) {
            const raw = opts.series[s][i];
            if (raw == null) continue;
            const name = series[s].name || '';
            const color = colors[s] || '#64748b';
            let extra = '';
            if (opts.pacDates && opts.pacDates[i]) {
                extra = ' (on ' + opts.pacDates[i] + ')';
            }
            rows += '<div class="apexcharts-tooltip-series-group" style="display:flex;align-items:center;gap:6px;padding:2px 0">'
                + '<span style="width:8px;height:8px;border-radius:50%;background:' + color + ';flex-shrink:0"></span>'
                + '<span style="font-size:12px;color:#0f172a"><strong>' + name + ':</strong> '
                + Math.round(Number(raw)) + ' W' + extra + '</span></div>';
        }
        return '<div class="apexcharts-tooltip-title" style="font-family:inherit;font-size:12px;margin-bottom:4px">'
            + (time ? ('Time: ' + time) : '')
            + '</div>' + rows;
    }

    function stepEnvelope(delta) {
        const sel = document.getElementById('envelopeMonth');
        const opts = Array.from(sel.options).map((o) => o.value);
        let idx = opts.indexOf(sel.value);
        if (idx < 0) idx = 0;
        idx = (idx + delta + opts.length) % opts.length;
        sel.value = opts[idx];
        loadEnvelope();
    }

    const baseChartOptions = {
        chart: { toolbar: { show: true }, zoom: { enabled: true }, animations: { enabled: false } },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        xaxis: { type: 'category', labels: timeAxisLabels },
        yaxis: { labels: { style: { colors: '#94a3b8', fontSize: '10px' } } },
        grid: { borderColor: '#f1f5f9', strokeDashArray: 4 }
    };

    function isValidIsoDate(s) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return false;
        return !Number.isNaN(Date.parse(s + 'T12:00:00'));
    }

    function openDatePicker(pickerId) {
        const el = document.getElementById(pickerId);
        if (!el) return;
        try {
            if (typeof el.showPicker === 'function') {
                el.showPicker();
                return;
            }
        } catch (e) {
        }
        el.focus();
        el.click();
    }

    function bindDateField(textId, pickerId, onValid) {
        const text = document.getElementById(textId);
        const picker = document.getElementById(pickerId);
        picker.addEventListener('change', () => {
            text.value = picker.value;
            if (isValidIsoDate(picker.value)) onValid(picker.value);
        });
        ['input', 'change', 'blur'].forEach((ev) => {
            text.addEventListener(ev, () => {
                const v = text.value.trim();
                if (isValidIsoDate(v)) {
                    picker.value = v;
                    onValid(v);
                }
            });
        });
    }

    function addDaysIso(iso, delta) {
        const t = new Date(iso + 'T12:00:00');
        t.setDate(t.getDate() + delta);
        const y = t.getFullYear();
        const m = String(t.getMonth() + 1).padStart(2, '0');
        const d = String(t.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    let dayChartView = <?= json_encode($chartView) ?>;
    let explorerStart = <?= (int)$explorerWindow['start'] ?>;
    let explorerEnd = <?= (int)$explorerWindow['end'] ?>;
    let explorerPreset = <?= json_encode($explorerWindow['preset']) ?>;
    let explorerCustom = <?= $explorerWindow['custom'] ? 'true' : 'false' ?>;
    let explorerPayload = null;

    function chartQueryTime(sec) {
        const d = new Date(sec * 1000);
        const p = (n) => String(n).padStart(2, '0');
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
            + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    function syncExplorerUrl() {
        const url = new URL(window.location.href);
        url.searchParams.delete('date');
        if (dayChartView === 'classic' || dayChartView === 'balance') {
            url.searchParams.set('view', dayChartView);
        } else {
            url.searchParams.delete('view');
        }
        if (explorerCustom) {
            url.searchParams.delete('range');
            url.searchParams.set('from', chartQueryTime(explorerStart));
            url.searchParams.set('to', chartQueryTime(explorerEnd));
        } else {
            url.searchParams.delete('from');
            url.searchParams.delete('to');
            if (explorerPreset && explorerPreset !== '6h') {
                url.searchParams.set('range', explorerPreset);
            } else {
                url.searchParams.delete('range');
            }
        }
        window.history.replaceState({}, '', url.pathname + url.search);
        document.querySelectorAll('.explorer-preset').forEach((btn) => {
            const on = !explorerCustom && btn.getAttribute('data-range') === explorerPreset;
            btn.classList.toggle('bg-slate-800', on);
            btn.classList.toggle('text-white', on);
            btn.classList.toggle('border-slate-800', on);
            btn.classList.toggle('bg-white', !on);
            btn.classList.toggle('text-slate-600', !on);
            btn.classList.toggle('border-slate-200', !on);
        });
        const label = document.getElementById('explorerWindowLabel');
        if (label) {
            label.textContent = new Date(explorerStart * 1000).toLocaleString()
                + ' -> ' + new Date(explorerEnd * 1000).toLocaleString();
        }
    }

    function updateDayViewChrome() {
        const balBtn = document.getElementById('day-view-balance');
        const clsBtn = document.getElementById('day-view-classic');
        const exBtn = document.getElementById('day-view-exchange');
        if (balBtn && clsBtn && exBtn) {
            balBtn.classList.toggle('metric-on', dayChartView === 'balance');
            clsBtn.classList.toggle('metric-on', dayChartView === 'classic');
            exBtn.classList.toggle('metric-on', dayChartView === 'exchange');
            balBtn.setAttribute('aria-pressed', dayChartView === 'balance' ? 'true' : 'false');
            clsBtn.setAttribute('aria-pressed', dayChartView === 'classic' ? 'true' : 'false');
            exBtn.setAttribute('aria-pressed', dayChartView === 'exchange' ? 'true' : 'false');
        }
        const title = document.getElementById('dayChartTitleText');
        const blurb = document.getElementById('dayChartBlurb');
        if (title) {
            if (dayChartView === 'exchange') title.textContent = 'Grid exchange';
            else if (dayChartView === 'classic') title.textContent = 'Power History';
            else title.textContent = 'Energy Balance';
        }
        if (blurb) {
            if (dayChartView === 'exchange') {
                blurb.textContent = 'Mixed 1-min / 5-min signed net. Above 0 = from grid, below 0 = surplus to grid.';
            } else if (dayChartView === 'classic') {
                blurb.textContent = 'Mixed 1-min / 5-min solar / home profile for the selected window.';
            } else {
                blurb.textContent = 'Mixed 1-min / 5-min. Bands: self-sufficient / grid / surplus. Production and consumption in the tooltip.';
            }
        }
        const balLeg = document.getElementById('explorerBalanceLegend');
        const exLeg = document.getElementById('explorerExchangeLegend');
        if (balLeg) balLeg.classList.toggle('is-hidden', dayChartView !== 'balance');
        if (exLeg) exLeg.classList.toggle('is-hidden', dayChartView !== 'exchange');
    }

    function setDayChartView(view) {
        let next = 'exchange';
        if (view === 'classic') next = 'classic';
        else if (view === 'balance') next = 'balance';
        else if (view === 'exchange') next = 'exchange';
        if (next === dayChartView) {
            return;
        }
        dayChartView = next;
        updateDayViewChrome();
        syncExplorerUrl();
        renderExplorerChart();
    }

    /**
     * Split signed net into Grid (>=0) and Surplus (<=0).
     * Null / non-finite points are omitted (not kept as gap markers): Apex area
     * charts often render blank when a bipolar series is peppered with nulls,
     * which happens on solar-filled history days where many 5-min slots have
     * solar but no grid. Balance already skips those slots; Exchange must too.
     */
    function splitSignedNet(points) {
        const grid = [];
        const surplus = [];
        if (!Array.isArray(points)) {
            return { grid: grid, surplus: surplus };
        }
        for (let i = 0; i < points.length; i++) {
            const p = points[i];
            if (!p || p.x === null || p.x === undefined) continue;
            if (p.y === null || p.y === undefined || !Number.isFinite(Number(p.y))) {
                continue;
            }
            const y = Number(p.y);
            grid.push({ x: p.x, y: y > 0 ? y : 0 });
            surplus.push({ x: p.x, y: y < 0 ? y : 0 });
        }
        return { grid: grid, surplus: surplus };
    }

    /**
     * Signed grid net for Exchange. Prefer classic.grid; if every point is null
     * (legacy payloads), derive import - export from Balance band thicknesses.
     */
    function exchangeNetPoints(classicGrid, bal) {
        const raw = Array.isArray(classicGrid) ? classicGrid : [];
        let finite = 0;
        for (let i = 0; i < raw.length; i++) {
            const p = raw[i];
            if (p && p.y !== null && p.y !== undefined && Number.isFinite(Number(p.y))) {
                finite++;
                break;
            }
        }
        if (finite > 0) {
            return raw;
        }
        const gBand = (bal && bal.grid) ? bal.grid : [];
        const sBand = (bal && bal.surplus) ? bal.surplus : [];
        const out = [];
        const n = Math.min(gBand.length, sBand.length);
        for (let i = 0; i < n; i++) {
            const g = gBand[i];
            const s = sBand[i];
            if (!g || !s || g.x !== s.x) continue;
            if (!Number.isFinite(Number(g.y)) || !Number.isFinite(Number(s.y))) continue;
            out.push({ x: g.x, y: Number(g.y) - Number(s.y) });
        }
        return out;
    }

    function setExplorerPreset(rangeId) {
        explorerPreset = rangeId;
        explorerCustom = false;
        const seconds = {
            '15m': 15 * 60,
            '1h': 3600,
            '2h': 2 * 3600,
            '6h': 6 * 3600,
            '24h': 24 * 3600,
            '7d': 7 * 24 * 3600
        }[rangeId] || (6 * 3600);
        explorerEnd = Math.floor(Date.now() / 1000);
        explorerStart = explorerEnd - seconds;
        loadExplorer();
    }

    /** Default explorer view: last 6 hours ending now. */
    function resetExplorerView() {
        setExplorerPreset('6h');
    }

    function panExplorer(direction) {
        const span = Math.max(15 * 60, explorerEnd - explorerStart);
        explorerStart += direction * span;
        explorerEnd += direction * span;
        const now = Math.floor(Date.now() / 1000);
        if (explorerEnd > now) {
            explorerEnd = now;
            explorerStart = explorerEnd - span;
        }
        explorerCustom = true;
        explorerPreset = '';
        loadExplorer();
    }

    function yPoint(arr, i) {
        if (!arr || !arr[i]) return null;
        return arr[i].y;
    }

    function renderExplorerChart() {
        if (!explorerPayload) return;
        const data = explorerPayload;
        const bal = data.balance || {};
        const classic = data.classic || {};
        if (
            dayChartView === 'balance'
            && !(bal.self_sufficient && bal.self_sufficient.length)
        ) {
            const hostEmpty = document.querySelector('#chart-intraday');
            if (hostEmpty) {
                hostEmpty.innerHTML = '<p class="text-sm text-slate-400 p-6">No balance samples in this window.</p>';
            }
            syncExplorerUrl();
            return;
        }
        let options;
        const xaxisCommon = {
            type: 'datetime',
            min: explorerStart * 1000,
            max: explorerEnd * 1000,
            labels: {
                datetimeUTC: false,
                format: 'HH:mm',
                style: { fontSize: '11px', colors: '#94a3b8' }
            }
        };
        const yaxisCommon = {
            labels: {
                formatter: function (val) {
                    if (val === null || val === undefined || !Number.isFinite(Number(val))) return '';
                    return Number(val).toFixed(0) + ' W';
                },
                style: { fontSize: '10px', colors: '#94a3b8' }
            }
        };
        const yaxisNonNeg = Object.assign({ min: 0 }, yaxisCommon);
        const gridPad = { borderColor: '#f1f5f9', strokeDashArray: 4, padding: { left: 10, right: 16, bottom: 12 } };

        /** Apex stacked areas go blank on null y - coerce to 0. */
        function stackSafeXy(points) {
            if (!Array.isArray(points)) return [];
            const out = [];
            for (let i = 0; i < points.length; i++) {
                const p = points[i];
                if (!p || p.x === null || p.x === undefined) continue;
                const y = (p.y === null || p.y === undefined || !Number.isFinite(Number(p.y)))
                    ? 0
                    : Number(p.y);
                out.push({ x: p.x, y: y });
            }
            return out;
        }

        if (dayChartView === 'exchange') {
            const netPts = exchangeNetPoints(classic.grid, bal);
            const split = splitSignedNet(netPts);
            if (!split.grid.length && !split.surplus.length) {
                const hostEmpty = document.querySelector('#chart-intraday');
                if (hostEmpty) {
                    hostEmpty.innerHTML = '<p class="text-sm text-slate-400 p-6">No grid exchange samples in this window.</p>';
                }
                syncExplorerUrl();
                return;
            }
            options = {
                series: [
                    { name: 'Grid', data: split.grid },
                    { name: 'Surplus', data: split.surplus }
                ],
                chart: {
                    type: 'area',
                    height: 420,
                    stacked: false,
                    animations: { enabled: false },
                    toolbar: { show: true },
                    zoom: { enabled: true },
                    parentHeightOffset: 12
                },
                colors: ['#7c3aed', '#10b981'],
                dataLabels: { enabled: false },
                stroke: { curve: 'smooth', width: 0 },
                fill: { type: 'solid', opacity: 0.55 },
                xaxis: xaxisCommon,
                yaxis: yaxisCommon,
                annotations: {
                    yaxis: [{ y: 0, borderColor: '#94a3b8', borderWidth: 1 }]
                },
                grid: gridPad,
                legend: { show: false },
                tooltip: {
                    shared: true,
                    intersect: false,
                    x: { format: 'HH:mm' },
                    y: {
                        formatter: function (val) {
                            if (val === null || val === undefined) return '';
                            const n = Number(val);
                            if (!Number.isFinite(n) || n === 0) return '0 W';
                            return Math.round(n) + ' W';
                        }
                    }
                }
            };
        } else if (dayChartView === 'balance') {
            const tip = data.tip_bands || {};
            const src = data.sources || [];
            const selfPts = stackSafeXy(bal.self_sufficient);
            const gridPts = stackSafeXy(bal.grid);
            const surpPts = stackSafeXy(bal.surplus);
            const tipSelf = stackSafeXy(tip.self_sufficient);
            const tipGrid = stackSafeXy(tip.grid);
            const tipSurp = stackSafeXy(tip.surplus);
            const tipCons = stackSafeXy(tip.consumption);
            const tipProd = stackSafeXy(tip.production);
            options = {
                series: [
                    { name: 'Self-sufficient', data: selfPts },
                    { name: 'Grid', data: gridPts },
                    { name: 'Surplus', data: surpPts }
                ],
                chart: {
                    type: 'area',
                    height: 420,
                    stacked: true,
                    animations: { enabled: false },
                    toolbar: { show: true },
                    zoom: { enabled: true },
                    parentHeightOffset: 12
                },
                colors: ['#3b82f6', '#7c3aed', '#10b981'],
                dataLabels: { enabled: false },
                // HW-like silk edges on stacked bands (Classic stays straight).
                stroke: { curve: 'smooth', width: 0 },
                fill: { type: 'solid', opacity: 0.75 },
                xaxis: xaxisCommon,
                yaxis: yaxisNonNeg,
                grid: gridPad,
                legend: { show: false },
                tooltip: {
                    shared: true,
                    intersect: false,
                    custom: function ({ dataPointIndex, w }) {
                        const i = dataPointIndex;
                        const fmt = function (v) {
                            if (v === null || v === undefined) return '-';
                            return Math.round(Number(v)) + ' W';
                        };
                        const when = w.globals.seriesX[0] && w.globals.seriesX[0][i] != null
                            ? new Date(w.globals.seriesX[0][i]).toLocaleString()
                            : '';
                        const source = (src[i] && src[i].source) ? src[i].source : '';
                        const rows = [
                            ['Self-sufficient', yPoint(tipSelf, i), '#3b82f6'],
                            ['Grid', yPoint(tipGrid, i), '#7c3aed'],
                            ['Surplus', yPoint(tipSurp, i), '#10b981'],
                            ['Consumption', yPoint(tipCons, i), 'linear-gradient(90deg,#3b82f6 50%,#7c3aed 50%)'],
                            ['Production', yPoint(tipProd, i), 'linear-gradient(90deg,#3b82f6 50%,#10b981 50%)']
                        ];
                        let body = '';
                        for (let r = 0; r < rows.length; r++) {
                            body += '<div style="display:flex;align-items:center;gap:6px;padding:2px 0">'
                                + '<span style="width:8px;height:8px;border-radius:50%;background:' + rows[r][2] + '"></span>'
                                + '<span>' + rows[r][0] + ': <strong>' + fmt(rows[r][1]) + '</strong></span></div>';
                        }
                        return '<div style="padding:8px 10px;font-size:12px">'
                            + '<div style="margin-bottom:4px;font-weight:600">' + when
                            + (source ? ' <span style="color:#64748b">(' + source + ')</span>' : '')
                            + '</div>' + body + '</div>';
                    }
                }
            };
        } else {
            options = {
                series: [
                    { name: 'Home Consumption', data: classic.home || [] },
                    { name: 'Solar Output', data: classic.solar || [] }
                ],
                chart: {
                    type: 'line',
                    height: 420,
                    stacked: false,
                    animations: { enabled: false },
                    toolbar: { show: true },
                    zoom: { enabled: true },
                    parentHeightOffset: 12
                },
                colors: ['#4f46e5', '#f59e0b'],
                dataLabels: { enabled: false },
                stroke: { curve: 'straight', width: 2 },
                xaxis: xaxisCommon,
                yaxis: yaxisNonNeg,
                grid: gridPad,
                legend: { show: true, position: 'top' },
                tooltip: {
                    shared: true,
                    intersect: false,
                    x: { format: 'HH:mm' },
                    y: { formatter: (val) => (val === null || val === undefined) ? '' : `${val} W` }
                }
            };
        }
        if (chartIntraday) {
            chartIntraday.destroy();
            chartIntraday = null;
        }
        const host = document.querySelector('#chart-intraday');
        host.innerHTML = '';
        try {
            chartIntraday = new ApexCharts(host, options);
            chartIntraday.render();
        } catch (err) {
            console.error('explorer chart render failed', err);
            host.innerHTML = '<p class="text-sm text-rose-600 p-6">Chart failed to render. See console.</p>';
        }
        syncExplorerUrl();
    }

    async function loadExplorer() {
        const params = new URLSearchParams();
        params.set('ajax', 'explorer');
        params.set('from', chartQueryTime(explorerStart));
        params.set('to', chartQueryTime(explorerEnd));
        if (dayChartView === 'classic' || dayChartView === 'balance') {
            params.set('view', dayChartView);
        }
        try {
            const res = await fetch('analytics.php?' + params.toString());
            const data = await res.json();
            if (data.error) {
                console.error('explorer ajax error', data.error);
                return;
            }
            explorerStart = data.start;
            explorerEnd = data.end;
            explorerPayload = data;
            renderExplorerChart();
        } catch (err) {
            console.error('explorer load failed', err);
            const host = document.querySelector('#chart-intraday');
            if (host) {
                host.innerHTML = '<p class="text-sm text-rose-600 p-6">Failed to load explorer data.</p>';
            }
        }
    }

    let compareLoadTimer = null;

    async function loadComparison() {
        const host = document.querySelector('#chart-compare');
        const d1 = document.getElementById('compareText1').value.trim();
        const d2 = document.getElementById('compareText2').value.trim();
        if (!isValidIsoDate(d1) || !isValidIsoDate(d2)) return;

        const res = await fetch(
            `analytics.php?ajax=compare&date1=${encodeURIComponent(d1)}&date2=${encodeURIComponent(d2)}`
        );
        const data = await res.json();
        if (data.error) return;

        const options = {
            ...baseChartOptions,
            series: [
                { name: d1, data: data.series1 },
                { name: d2, data: data.series2 }
            ],
            chart: { ...baseChartOptions.chart, type: 'line', height: 400 },
            colors: ['#4f46e5', '#f59e0b'],
            xaxis: { type: 'category', categories: data.categories, labels: timeAxisLabels },
            tooltip: {
                custom: function (opts) {
                    return pacTooltip(opts);
                }
            }
        };
        if (chartCompare) {
            chartCompare.destroy();
            chartCompare = null;
        }
        host.innerHTML = '';
        chartCompare = new ApexCharts(host, options);
        chartCompare.render();
    }

    function scheduleCompareLoad() {
        if (compareLoadTimer) clearTimeout(compareLoadTimer);
        compareLoadTimer = setTimeout(() => { loadComparison(); }, 200);
    }

    async function loadEnvelope() {
        const month = document.getElementById('envelopeMonth').value;
        const warn = document.getElementById('envelope-warning');
        const host = document.querySelector('#chart-envelope');
        warn.classList.add('hidden');
        warn.textContent = '';

        let data;
        try {
            const res = await fetch(`analytics.php?ajax=envelope&month=${encodeURIComponent(month)}`);
            data = await res.json();
        } catch (e) {
            warn.textContent = 'Failed to load envelope data.';
            warn.classList.remove('hidden');
            return;
        }

        if (data.error) {
            warn.textContent = data.error + (data.detail ? ' (' + data.detail + ')' : '');
            warn.classList.remove('hidden');
            if (chartEnvelope) { chartEnvelope.destroy(); chartEnvelope = null; }
            return;
        }
        if (!data.filled) {
            warn.textContent = 'No envelope data for this period in clear_sky_envelopes. Re-run: python3 rollup.py -buildall';
            warn.classList.remove('hidden');
            if (chartEnvelope) { chartEnvelope.destroy(); chartEnvelope = null; }
            return;
        }

        const pacDates = data.max_pac_date || [];
        const seriesName = (data.month === 0) ? 'Whole-year max PAC (W)' : 'Max PAC (W)';
        const options = {
            chart: {
                type: 'area',
                height: 400,
                toolbar: { show: true },
                animations: { enabled: false },
                redrawOnParentResize: true,
                redrawOnWindowResize: true
            },
            series: [{ name: seriesName, data: data.max_pac_w }],
            colors: ['#0ea5e9'],
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2 },
            fill: {
                type: 'gradient',
                gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 100] }
            },
            xaxis: { type: 'category', categories: data.categories, labels: timeAxisLabels },
            yaxis: {
                title: { text: 'PAC (W)', style: { color: '#94a3b8', fontSize: '10px' } },
                labels: { style: { colors: '#94a3b8', fontSize: '10px' } }
            },
            grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
            tooltip: {
                custom: function (opts) {
                    opts.pacDates = pacDates;
                    return pacTooltip(opts);
                }
            }
        };
        if (chartEnvelope) {
            chartEnvelope.destroy();
            chartEnvelope = null;
        }
        host.innerHTML = '';
        chartEnvelope = new ApexCharts(host, options);
        await chartEnvelope.render();
    }

    document.addEventListener('DOMContentLoaded', async () => {
        try {
            const rr = await fetch('analytics.php?ajax=range');
            dayRange = await rr.json();
        } catch (e) {
            dayRange = { min: null, max: null };
        }

        bindDateField('compareText1', 'comparePicker1', () => scheduleCompareLoad());
        bindDateField('compareText2', 'comparePicker2', () => scheduleCompareLoad());

        updateDayViewChrome();
        loadExplorer();
        loadComparison();
        loadEnvelope();
        lucide.createIcons();
    });
</script>

</body>
</html>
