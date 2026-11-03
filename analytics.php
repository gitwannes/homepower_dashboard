<?php
# --- file: analytics.php ---
/**
 * Historical exploration: intraday power explorer + two-day overlay.
 * Period rollups live on summary.php. Virtual 'best-day' lives on topn.php (A22).
 */
date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/site_settings.php';
require_once __DIR__ . '/pac_quality.php';
require_once __DIR__ . '/balance_chart.php';
require_once __DIR__ . '/explorer_chart.php';
require_once __DIR__ . '/gas_water.php';

$hp = hp_boot();
hp_require_ready($hp);
hp_publish_pac($hp['settings']);
$dbFile = $hp['db'];
$chartView = hp_chart_view();
$explorerWindow = hp_explorer_window();

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
            // Earliest/latest local day across history_5min and readings (A19).
            $min = null;
            $max = null;
            $candidates = [];
            foreach (['history_5min', 'readings'] as $table) {
                try {
                    $row = $pdo->query(
                        "SELECT substr(MIN(timestamp), 1, 10) AS dmin, substr(MAX(timestamp), 1, 10) AS dmax
                         FROM {$table}"
                    )->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        if (!empty($row['dmin'])) {
                            $candidates['min'][] = (string)$row['dmin'];
                        }
                        if (!empty($row['dmax'])) {
                            $candidates['max'][] = (string)$row['dmax'];
                        }
                    }
                } catch (Exception $e) {
                }
            }
            if (!empty($candidates['min'])) {
                sort($candidates['min']);
                $min = $candidates['min'][0];
            }
            if (!empty($candidates['max'])) {
                rsort($candidates['max']);
                $max = $candidates['max'][0];
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
                $win['day_mode'] = !empty($panned['day_mode']);
                $todayYmd = date('Y-m-d');
                $panYmd = date('Y-m-d', (int)$win['start']);
                if (!empty($win['day_mode']) && $panYmd === $todayYmd) {
                    $win['custom'] = false;
                    $win['preset'] = 'today';
                } else {
                    $win['custom'] = true;
                    $win['preset'] = '';
                }
            }
            $payload = hp_explorer_mixed_series(
                $pdo,
                $win['start'],
                $win['end'],
                'sanitize_pac_w'
            );
            // A11: gas/water strip shares this exact window (no separate range chips).
            $waterOk = gw_water_available($pdo);
            $utility = [
                'water_available' => $waterOk,
                'gas' => gw_utility_window_curve($pdo, 'gas', (int)$win['start'], (int)$win['end']),
                'water' => $waterOk
                    ? gw_utility_window_curve($pdo, 'water', (int)$win['start'], (int)$win['end'])
                    : ['source' => 'none', 'points' => [], 'unit' => 'm3/h'],
            ];
            echo json_encode([
                'ok' => true,
                'start' => $win['start'],
                'end' => $win['end'],
                'preset' => $win['preset'],
                'custom' => $win['custom'],
                'day_mode' => !empty($win['day_mode']),
                'balance' => $payload['balance'],
                'tip_bands' => $payload['tip_bands'],
                'classic' => $payload['classic'],
                'phases' => $payload['phases'] ?? ['l1' => [], 'l2' => [], 'l3' => []],
                'has_phases' => hp_phases_series_has_data($payload['phases'] ?? []),
                'sources' => $payload['sources'],
                'utility' => $utility,
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

        echo json_encode(['error' => 'Unknown action']);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$twoDaysAgo = date('Y-m-d', strtotime('-2 day'));
$defaultDay = $yesterday;
// Deep link from topn.php: ?date=YYYY-MM-DD
if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])) {
    $defaultDay = (string)$_GET['date'];
}
// Overlay defaults to two finished days (today still incomplete).
$defaultCompare1 = $yesterday;
$defaultCompare2 = $twoDaysAgo;
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
        .nav-arrow:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
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
                    <p class="text-xs text-slate-400 mt-0.5">Balance explorer and day compare</p>
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
            <?= hp_nav_weather() ?>
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
                            } elseif ($chartView === 'phases') {
                                echo 'Phase power';
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
                        } elseif ($chartView === 'phases') {
                            echo 'Mixed 1-min / 5-min signed W per phase (L1/L2/L3). Gaps where phase samples are missing.';
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
                        <button type="button" id="day-view-phases" class="is-hidden <?= $chartView === 'phases' ? 'metric-on' : '' ?>" aria-pressed="<?= $chartView === 'phases' ? 'true' : 'false' ?>" onclick="setDayChartView('phases')">Phases</button>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Chart range">
                <?php foreach (hp_explorer_chart_presets() as $presetId => $preset): ?>
                    <?php $presetOn = !$explorerWindow['custom'] && $explorerWindow['preset'] === $presetId; ?>
                    <button type="button"
                            class="px-2.5 py-1 rounded-full text-[11px] font-semibold border explorer-preset <?= $presetOn ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200' ?>"
                            data-range="<?= htmlspecialchars($presetId, ENT_QUOTES, 'UTF-8') ?>"
                            onclick="setExplorerPreset('<?= htmlspecialchars($presetId, ENT_QUOTES, 'UTF-8') ?>')"><?= htmlspecialchars($preset['label'], ENT_QUOTES, 'UTF-8') ?></button>
                <?php endforeach; ?>
                <button type="button" id="explorerPanBack" class="nav-arrow" onclick="panExplorer(-1)" title="Previous day / pan back" aria-label="Pan back">
                    <i data-lucide="chevron-left" class="w-5 h-5"></i>
                </button>
                <button type="button" id="explorerPanFwd" class="nav-arrow" onclick="panExplorer(1)" title="Next day / pan forward" aria-label="Pan forward">
                    <i data-lucide="chevron-right" class="w-5 h-5"></i>
                </button>
                <button type="button" class="jump-btn" onclick="resetExplorerView()" title="Today 00:00-23:59">Reset view</button>
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
            <div id="explorerPhasesLegend" class="flex flex-wrap items-center gap-3 text-xs <?= $chartView === 'phases' ? '' : 'is-hidden' ?>">
                <span class="inline-flex items-center gap-1.5 text-slate-500 font-medium"><span class="w-2.5 h-2.5 rounded-full bg-slate-400/50"></span> Sum</span>
                <span class="inline-flex items-center gap-1.5 text-indigo-600 font-medium"><span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span> L1</span>
                <span class="inline-flex items-center gap-1.5 text-amber-600 font-medium"><span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span> L2</span>
                <span class="inline-flex items-center gap-1.5 text-rose-600 font-medium"><span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span> L3</span>
            </div>
        </div>
        <div id="chart-intraday" class="w-full h-[400px]"></div>
    </div>

    <!-- A11: gas / water rate under the power explorer; same from/to window -->
    <div id="utility-explorer-card" class="card border-t-4 border-t-amber-500">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-2">
            <div>
                <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                    <i data-lucide="flame" class="w-5 h-5 text-amber-500" id="utilityExplorerIcon"></i>
                    <span id="utilityExplorerTitle">Gas rate</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">
                    Same window as the chart above. Instantaneous m³/h (P1), or Fluvius hour volumes for gas when P1 is missing.
                </p>
            </div>
            <div class="metric-switch" role="group" aria-label="Utility meter">
                <button type="button" id="utility-metric-gas" class="metric-on" aria-pressed="true" onclick="setUtilityMetric('gas')">Gas</button>
                <button type="button" id="utility-metric-water" class="is-hidden" aria-pressed="false" onclick="setUtilityMetric('water')">Water</button>
            </div>
        </div>
        <div id="utility-source-label" class="text-[11px] text-slate-400 font-mono mb-1"></div>
        <div id="chart-utility" class="w-full h-[220px]"></div>
    </div>

    <div class="card border-t-4 border-t-indigo-500">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                    <i data-lucide="layers" class="w-5 h-5 text-indigo-500"></i> Two-Day Overlay
                </h2>
                <p class="text-xs text-slate-400">Defaults: yesterday vs 2 days ago - type or use the calendar icon</p>
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

</div>

<script>
    lucide.createIcons();

    const TODAY_ISO = <?= json_encode($today) ?>;

    let chartIntraday = null;
    let chartCompare = null;
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
            rows += '<div class="apexcharts-tooltip-series-group" style="display:flex;align-items:center;gap:6px;padding:2px 0">'
                + '<span style="width:8px;height:8px;border-radius:50%;background:' + color + ';flex-shrink:0"></span>'
                + '<span style="font-size:12px;color:#0f172a"><strong>' + name + ':</strong> '
                + Math.round(Number(raw)) + ' W</span></div>';
        }
        return '<div class="apexcharts-tooltip-title" style="font-family:inherit;font-size:12px;margin-bottom:4px">'
            + (time ? ('Time: ' + time) : '')
            + '</div>' + rows;
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
    let explorerDayMode = <?= !empty($explorerWindow['day_mode']) ? 'true' : 'false' ?>;
    let explorerPayload = null;
    /** @type {'gas'|'water'} */
    let utilityMetric = 'gas';
    let utilityChart = null;
    /** @type {{min: string|null, max: string|null}} */
    let explorerDayBounds = { min: null, max: null };

    function chartQueryTime(sec) {
        const d = new Date(sec * 1000);
        const p = (n) => String(n).padStart(2, '0');
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
            + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    /** Local YYYY-MM-DD for a unix second. */
    function localYmdFromSec(sec) {
        const d = new Date(sec * 1000);
        const p = (n) => String(n).padStart(2, '0');
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    }

    /**
     * Local calendar-day bounds (DST-aware via Date): midnight .. next midnight - 1s.
     * @returns {{start: number, end: number, ymd: string}}
     */
    function localCalendarDayBounds(ymd) {
        const parts = String(ymd).split('-');
        const y = Number(parts[0]);
        const m = Number(parts[1]);
        const day = Number(parts[2]);
        const startMs = new Date(y, m - 1, day, 0, 0, 0, 0).getTime();
        const endMs = new Date(y, m - 1, day + 1, 0, 0, 0, 0).getTime() - 1000;
        return {
            start: Math.floor(startMs / 1000),
            end: Math.floor(endMs / 1000),
            ymd: ymd
        };
    }

    function shiftLocalYmd(ymd, direction) {
        const parts = String(ymd).split('-');
        const y = Number(parts[0]);
        const m = Number(parts[1]);
        const day = Number(parts[2]);
        const d = new Date(y, m - 1, day + (direction >= 0 ? 1 : -1), 12, 0, 0, 0);
        return localYmdFromSec(Math.floor(d.getTime() / 1000));
    }

    function updateExplorerPanButtons() {
        const back = document.getElementById('explorerPanBack');
        const fwd = document.getElementById('explorerPanFwd');
        if (!back || !fwd) return;
        if (!explorerDayMode) {
            back.disabled = false;
            fwd.disabled = false;
            back.title = 'Pan back one window';
            fwd.title = 'Pan forward one window';
            return;
        }
        const ymd = localYmdFromSec(explorerStart);
        const atFirst = !!(explorerDayBounds.min && ymd <= explorerDayBounds.min);
        const atToday = ymd >= TODAY_ISO;
        back.disabled = atFirst;
        fwd.disabled = atToday;
        back.title = atFirst ? 'First day in database' : 'Previous calendar day';
        fwd.title = atToday ? 'Already on today' : 'Next calendar day';
    }

    function syncExplorerUrl() {
        const url = new URL(window.location.href);
        url.searchParams.delete('date');
        if (dayChartView === 'classic' || dayChartView === 'balance' || dayChartView === 'phases') {
            url.searchParams.set('view', dayChartView);
        } else {
            url.searchParams.delete('view');
        }
        if (explorerDayMode) {
            url.searchParams.delete('from');
            url.searchParams.delete('to');
            url.searchParams.delete('range');
            const ymd = localYmdFromSec(explorerStart);
            if (!explorerCustom && explorerPreset === 'today') {
                // Default Today: omit range/date.
            } else {
                url.searchParams.set('date', ymd);
            }
        } else if (explorerCustom) {
            url.searchParams.delete('range');
            url.searchParams.set('from', chartQueryTime(explorerStart));
            url.searchParams.set('to', chartQueryTime(explorerEnd));
        } else {
            url.searchParams.delete('from');
            url.searchParams.delete('to');
            if (explorerPreset && explorerPreset !== 'today') {
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
        updateExplorerPanButtons();
    }

    function explorerHasPhaseData(payload) {
        if (payload && payload.has_phases === true) {
            return true;
        }
        const ph = payload && payload.phases ? payload.phases : null;
        if (!ph) {
            return false;
        }
        const keys = ['l1', 'l2', 'l3'];
        for (let k = 0; k < keys.length; k++) {
            const arr = ph[keys[k]] || [];
            for (let i = 0; i < arr.length; i++) {
                const p = arr[i];
                if (p && p.y !== null && p.y !== undefined && Number.isFinite(Number(p.y))) {
                    return true;
                }
            }
        }
        return false;
    }

    function updateDayViewChrome() {
        const balBtn = document.getElementById('day-view-balance');
        const clsBtn = document.getElementById('day-view-classic');
        const exBtn = document.getElementById('day-view-exchange');
        const phBtn = document.getElementById('day-view-phases');
        const hasPh = explorerHasPhaseData(explorerPayload);
        if (phBtn) {
            phBtn.classList.toggle('is-hidden', !hasPh);
            phBtn.classList.toggle('metric-on', dayChartView === 'phases');
            phBtn.setAttribute('aria-pressed', dayChartView === 'phases' ? 'true' : 'false');
        }
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
            else if (dayChartView === 'phases') title.textContent = 'Phase power';
            else title.textContent = 'Energy Balance';
        }
        if (blurb) {
            if (dayChartView === 'exchange') {
                blurb.textContent = 'Mixed 1-min / 5-min signed net. Above 0 = from grid, below 0 = surplus to grid.';
            } else if (dayChartView === 'classic') {
                blurb.textContent = 'Mixed 1-min / 5-min solar / home profile for the selected window.';
            } else if (dayChartView === 'phases') {
                blurb.textContent = 'Mixed 1-min / 5-min signed W per phase (L1/L2/L3). Gaps where phase samples are missing.';
            } else {
                blurb.textContent = 'Mixed 1-min / 5-min. Bands: self-sufficient / grid / surplus. Production and consumption in the tooltip.';
            }
        }
        const balLeg = document.getElementById('explorerBalanceLegend');
        const exLeg = document.getElementById('explorerExchangeLegend');
        const phLeg = document.getElementById('explorerPhasesLegend');
        if (balLeg) balLeg.classList.toggle('is-hidden', dayChartView !== 'balance');
        if (exLeg) exLeg.classList.toggle('is-hidden', dayChartView !== 'exchange');
        if (phLeg) phLeg.classList.toggle('is-hidden', dayChartView !== 'phases');
    }

    function setDayChartView(view) {
        let next = 'exchange';
        if (view === 'classic') next = 'classic';
        else if (view === 'balance') next = 'balance';
        else if (view === 'exchange') next = 'exchange';
        else if (view === 'phases' && explorerHasPhaseData(explorerPayload)) next = 'phases';
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
        if (rangeId === 'today') {
            const day = localCalendarDayBounds(TODAY_ISO);
            explorerStart = day.start;
            explorerEnd = day.end;
            explorerDayMode = true;
            loadExplorer();
            return;
        }
        explorerDayMode = false;
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

    /** Default explorer view: Today (local midnight to midnight). */
    function resetExplorerView() {
        setExplorerPreset('today');
    }

    function panExplorer(direction) {
        if (explorerDayMode) {
            const ymd = localYmdFromSec(explorerStart);
            if (direction > 0 && ymd >= TODAY_ISO) {
                return;
            }
            if (direction < 0 && explorerDayBounds.min && ymd <= explorerDayBounds.min) {
                return;
            }
            let nextYmd = shiftLocalYmd(ymd, direction);
            if (nextYmd > TODAY_ISO) {
                nextYmd = TODAY_ISO;
            }
            if (explorerDayBounds.min && nextYmd < explorerDayBounds.min) {
                nextYmd = explorerDayBounds.min;
            }
            const day = localCalendarDayBounds(nextYmd);
            explorerStart = day.start;
            explorerEnd = day.end;
            explorerDayMode = true;
            if (nextYmd === TODAY_ISO) {
                explorerCustom = false;
                explorerPreset = 'today';
            } else {
                explorerCustom = true;
                explorerPreset = '';
            }
            loadExplorer();
            return;
        }
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
        explorerDayMode = false;
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
                    height: 400,
                    stacked: false,
                    animations: { enabled: false },
                    toolbar: { show: true },
                    zoom: { enabled: true },
                    parentHeightOffset: 0,
                    redrawOnParentResize: true,
                    redrawOnWindowResize: true
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
                    height: 400,
                    stacked: true,
                    animations: { enabled: false },
                    toolbar: { show: true },
                    zoom: { enabled: true },
                    parentHeightOffset: 0,
                    redrawOnParentResize: true,
                    redrawOnWindowResize: true
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
        } else if (dayChartView === 'phases') {
            const ph = data.phases || {};
            if (!explorerHasPhaseData(data)) {
                const hostEmpty = document.querySelector('#chart-intraday');
                if (hostEmpty) {
                    hostEmpty.innerHTML = '<p class="text-sm text-slate-400 p-6">No per-phase samples in this window.</p>';
                }
                syncExplorerUrl();
                return;
            }
            // Omit null gap markers - same Apex blanking pitfall as Exchange / live Phases.
            function finiteXy(points) {
                const out = [];
                if (!Array.isArray(points)) {
                    return out;
                }
                for (let i = 0; i < points.length; i++) {
                    const p = points[i];
                    if (!p || p.x === null || p.x === undefined) {
                        continue;
                    }
                    if (p.y === null || p.y === undefined || !Number.isFinite(Number(p.y))) {
                        continue;
                    }
                    out.push({ x: p.x, y: Number(p.y) });
                }
                return out;
            }
            const l1 = finiteXy(ph.l1);
            const l2 = finiteXy(ph.l2);
            const l3 = finiteXy(ph.l3);
            const sumMap = {};
            function addSum(arr, key) {
                for (let i = 0; i < arr.length; i++) {
                    const p = arr[i];
                    const x = String(p.x);
                    if (!sumMap[x]) {
                        sumMap[x] = {};
                    }
                    sumMap[x][key] = p.y;
                }
            }
            addSum(l1, 'l1');
            addSum(l2, 'l2');
            addSum(l3, 'l3');
            const sumXs = Object.keys(sumMap).map(Number).sort(function (a, b) { return a - b; });
            const sumPts = [];
            for (let i = 0; i < sumXs.length; i++) {
                const m = sumMap[String(sumXs[i])];
                if (
                    Number.isFinite(Number(m.l1))
                    && Number.isFinite(Number(m.l2))
                    && Number.isFinite(Number(m.l3))
                ) {
                    sumPts.push({
                        x: sumXs[i],
                        y: Number(m.l1) + Number(m.l2) + Number(m.l3)
                    });
                }
            }
            options = {
                series: [
                    { name: 'Sum', type: 'area', data: sumPts },
                    { name: 'L1', type: 'line', data: l1 },
                    { name: 'L2', type: 'line', data: l2 },
                    { name: 'L3', type: 'line', data: l3 }
                ],
                chart: {
                    type: 'line',
                    height: 400,
                    stacked: false,
                    animations: { enabled: false },
                    toolbar: { show: true },
                    zoom: { enabled: true },
                    parentHeightOffset: 0,
                    redrawOnParentResize: true,
                    redrawOnWindowResize: true
                },
                colors: ['#94a3b8', '#6366f1', '#f59e0b', '#f43f5e'],
                dataLabels: { enabled: false },
                stroke: { curve: 'straight', width: [1, 2, 2, 2] },
                fill: { type: 'solid', opacity: [0.18, 1, 1, 1] },
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
                    custom: function ({ dataPointIndex, w }) {
                        const i = dataPointIndex;
                        const fmt = function (v) {
                            if (v === null || v === undefined || !Number.isFinite(Number(v))) return '-';
                            return Math.round(Number(v)) + ' W';
                        };
                        const when = w.globals.seriesX[0] && w.globals.seriesX[0][i] != null
                            ? new Date(w.globals.seriesX[0][i]).toLocaleString()
                            : '';
                        const ySum = w.globals.series[0] ? w.globals.series[0][i] : null;
                        const y1 = w.globals.series[1] ? w.globals.series[1][i] : null;
                        const y2 = w.globals.series[2] ? w.globals.series[2][i] : null;
                        const y3 = w.globals.series[3] ? w.globals.series[3][i] : null;
                        const rows = [
                            ['Sum', ySum, '#94a3b8'],
                            ['L1', y1, '#6366f1'],
                            ['L2', y2, '#f59e0b'],
                            ['L3', y3, '#f43f5e']
                        ];
                        let body = '';
                        for (let r = 0; r < rows.length; r++) {
                            body += '<div style="display:flex;align-items:center;gap:6px;padding:2px 0">'
                                + '<span style="width:8px;height:8px;border-radius:50%;background:' + rows[r][2] + '"></span>'
                                + '<span>' + rows[r][0] + ': <strong>' + fmt(rows[r][1]) + '</strong></span></div>';
                        }
                        return '<div style="padding:8px 10px;font-size:12px">'
                            + '<div style="margin-bottom:4px;font-weight:600">' + when + '</div>'
                            + body + '</div>';
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
                    height: 400,
                    stacked: false,
                    animations: { enabled: false },
                    toolbar: { show: true },
                    zoom: { enabled: true },
                    parentHeightOffset: 0,
                    redrawOnParentResize: true,
                    redrawOnWindowResize: true
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
            // Await + resize: concurrent Apex mounts on this page often paint blank
            // until a later pan/reload; pan worked because it re-rendered alone.
            return chartIntraday.render().then(function () {
                if (chartIntraday && typeof chartIntraday.windowResize === 'function') {
                    chartIntraday.windowResize();
                }
                syncExplorerUrl();
            });
        } catch (err) {
            console.error('explorer chart render failed', err);
            host.innerHTML = '<p class="text-sm text-rose-600 p-6">Chart failed to render. See console.</p>';
            syncExplorerUrl();
            return Promise.resolve();
        }
    }

    async function loadExplorer() {
        const params = new URLSearchParams();
        params.set('ajax', 'explorer');
        params.set('from', chartQueryTime(explorerStart));
        params.set('to', chartQueryTime(explorerEnd));
        if (dayChartView === 'classic' || dayChartView === 'balance' || dayChartView === 'phases') {
            params.set('view', dayChartView);
        }
        try {
            const res = await fetch('analytics.php?' + params.toString());
            const data = await res.json();
            if (data.error) {
                console.error('explorer ajax error', data.error);
                showExplorerError('Explorer error: ' + String(data.error));
                return;
            }
            explorerStart = data.start;
            explorerEnd = data.end;
            if (typeof data.day_mode === 'boolean') {
                explorerDayMode = data.day_mode;
            }
            if (typeof data.custom === 'boolean') {
                explorerCustom = data.custom;
            }
            if (typeof data.preset === 'string') {
                explorerPreset = data.preset;
            }
            explorerPayload = data;
            if (dayChartView === 'phases' && !explorerHasPhaseData(data)) {
                dayChartView = 'exchange';
            }
            updateDayViewChrome();
            await renderExplorerChart();
            renderUtilityExplorer();
        } catch (err) {
            console.error('explorer load failed', err);
            showExplorerError('Failed to load explorer data.');
        }
    }

    /**
     * Soft-fail: replace explorer host with a message; never leave a destroyed blank chart.
     *
     * @param {string} message
     */
    function showExplorerError(message) {
        if (chartIntraday) {
            chartIntraday.destroy();
            chartIntraday = null;
        }
        const host = document.querySelector('#chart-intraday');
        if (host) {
            host.innerHTML = '<p class="text-sm text-rose-600 p-6">' + message + '</p>';
        }
    }

    /**
     * Gas/water rate under the power explorer - same from/to as Exchange (A11).
     */
    function setUtilityMetric(next) {
        const chosen = next === 'water' ? 'water' : 'gas';
        const util = explorerPayload && explorerPayload.utility ? explorerPayload.utility : null;
        if (chosen === 'water' && util && util.water_available === false) {
            return;
        }
        if (chosen === utilityMetric) {
            return;
        }
        utilityMetric = chosen;
        renderUtilityExplorer();
    }

    function renderUtilityExplorer() {
        const util = explorerPayload && explorerPayload.utility ? explorerPayload.utility : null;
        const waterBtn = document.getElementById('utility-metric-water');
        const gasBtn = document.getElementById('utility-metric-gas');
        const title = document.getElementById('utilityExplorerTitle');
        const icon = document.getElementById('utilityExplorerIcon');
        const srcLabel = document.getElementById('utility-source-label');
        const host = document.querySelector('#chart-utility');
        const waterOk = !!(util && util.water_available);
        if (waterBtn) {
            waterBtn.classList.toggle('is-hidden', !waterOk);
        }
        if (!waterOk && utilityMetric === 'water') {
            utilityMetric = 'gas';
        }
        if (gasBtn) {
            gasBtn.classList.toggle('metric-on', utilityMetric === 'gas');
            gasBtn.setAttribute('aria-pressed', utilityMetric === 'gas' ? 'true' : 'false');
        }
        if (waterBtn) {
            waterBtn.classList.toggle('metric-on', utilityMetric === 'water');
            waterBtn.setAttribute('aria-pressed', utilityMetric === 'water' ? 'true' : 'false');
        }
        const isGas = utilityMetric === 'gas';
        if (title) {
            title.textContent = isGas ? 'Gas rate' : 'Water rate';
        }
        if (icon) {
            icon.setAttribute('data-lucide', isGas ? 'flame' : 'droplet');
            icon.classList.toggle('text-amber-500', isGas);
            icon.classList.toggle('text-cyan-500', !isGas);
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }
        const card = document.getElementById('utility-explorer-card');
        if (card) {
            card.classList.toggle('border-t-amber-500', isGas);
            card.classList.toggle('border-t-cyan-500', !isGas);
        }
        const seriesWrap = util ? (isGas ? util.gas : util.water) : null;
        const points = seriesWrap && Array.isArray(seriesWrap.points) ? seriesWrap.points : [];
        const source = seriesWrap && seriesWrap.source ? seriesWrap.source : 'none';
        if (srcLabel) {
            const srcMap = {
                p1_1min: 'Source: P1 1-min',
                p1_5min: 'Source: P1 5-min',
                fluvius_hour: 'Source: Fluvius hour',
                none: 'No data in this window'
            };
            srcLabel.textContent = srcMap[source] || source;
        }
        if (!host) {
            return;
        }
        if (utilityChart) {
            utilityChart.destroy();
            utilityChart = null;
        }
        host.innerHTML = '';
        if (!points.length || source === 'none') {
            host.innerHTML = '<p class="text-sm text-slate-400 p-4">No '
                + (isGas ? 'gas' : 'water') + ' samples in this window.</p>';
            return;
        }
        const color = isGas ? '#d97706' : '#06b6d4';
        const name = isGas ? 'Gas (m3/h)' : 'Water (m3/h)';
        utilityChart = new ApexCharts(host, {
            series: [{ name: name, data: points }],
            chart: {
                type: 'area',
                height: 220,
                toolbar: { show: false },
                animations: { enabled: false },
                zoom: { enabled: false }
            },
            colors: [color],
            dataLabels: { enabled: false },
            stroke: { curve: 'stepline', width: 2 },
            fill: { type: 'solid', opacity: 0.25 },
            xaxis: {
                type: 'datetime',
                min: explorerStart * 1000,
                max: explorerEnd * 1000,
                labels: { datetimeUTC: false, format: 'HH:mm', style: { fontSize: '11px', colors: '#94a3b8' } }
            },
            yaxis: {
                min: 0,
                title: { text: 'm3/h', style: { color: '#94a3b8', fontSize: '10px' } },
                labels: { formatter: (v) => Number(v).toFixed(2) }
            },
            grid: { borderColor: '#f1f5f9' },
            tooltip: {
                x: { format: 'dd MMM HH:mm' },
                y: { formatter: (v) => (v == null || Number.isNaN(Number(v)) ? '-' : (Number(v).toFixed(3) + ' m3/h')) }
            },
            legend: { show: false }
        });
        utilityChart.render();
    }

    let compareLoadTimer = null;

    async function loadComparison() {
        const host = document.querySelector('#chart-compare');
        const d1 = document.getElementById('compareText1').value.trim();
        const d2 = document.getElementById('compareText2').value.trim();
        if (!isValidIsoDate(d1) || !isValidIsoDate(d2)) {
            showCompareMessage('<p class="text-sm text-slate-400 p-6">Pick two valid dates (YYYY-MM-DD).</p>');
            return;
        }

        try {
            const res = await fetch(
                `analytics.php?ajax=compare&date1=${encodeURIComponent(d1)}&date2=${encodeURIComponent(d2)}`
            );
            const data = await res.json();
            if (data.error) {
                showCompareMessage('<p class="text-sm text-rose-600 p-6">Compare error: '
                    + String(data.error) + '</p>');
                return;
            }
            if (!Array.isArray(data.categories) || !data.categories.length) {
                showCompareMessage('<p class="text-sm text-slate-400 p-6">No compare slots returned.</p>');
                return;
            }

            const options = {
                ...baseChartOptions,
                series: [
                    { name: d1, data: data.series1 },
                    { name: d2, data: data.series2 }
                ],
                chart: {
                    ...baseChartOptions.chart,
                    type: 'line',
                    height: 400,
                    redrawOnParentResize: true,
                    redrawOnWindowResize: true
                },
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
            if (host) {
                host.innerHTML = '';
            }
            chartCompare = new ApexCharts(host, options);
            await chartCompare.render();
            if (chartCompare && typeof chartCompare.windowResize === 'function') {
                chartCompare.windowResize();
            }
        } catch (err) {
            console.error('compare load failed', err);
            showCompareMessage('<p class="text-sm text-rose-600 p-6">Failed to load two-day overlay.</p>');
        }
    }

    /**
     * Soft-fail for compare: destroy then show HTML (never leave a blank destroyed chart).
     *
     * @param {string} html
     */
    function showCompareMessage(html) {
        if (chartCompare) {
            chartCompare.destroy();
            chartCompare = null;
        }
        const host = document.querySelector('#chart-compare');
        if (host) {
            host.innerHTML = html;
        }
    }

    function scheduleCompareLoad() {
        if (compareLoadTimer) clearTimeout(compareLoadTimer);
        compareLoadTimer = setTimeout(() => { loadComparison(); }, 200);
    }

    /**
     * Yield two animation frames so Apex finishes layout before the next mount (A22 blank-chart harden).
     *
     * @returns {Promise<void>}
     */
    function yieldForChartPaint() {
        return new Promise((resolve) => {
            requestAnimationFrame(() => {
                requestAnimationFrame(() => resolve());
            });
        });
    }

    document.addEventListener('DOMContentLoaded', async () => {
        try {
            const rr = await fetch('analytics.php?ajax=range');
            dayRange = await rr.json();
            explorerDayBounds = {
                min: dayRange.min || null,
                max: dayRange.max || null
            };
        } catch (e) {
            dayRange = { min: null, max: null };
            explorerDayBounds = { min: null, max: null };
        }

        bindDateField('compareText1', 'comparePicker1', () => scheduleCompareLoad());
        bindDateField('compareText2', 'comparePicker2', () => scheduleCompareLoad());

        updateDayViewChrome();
        updateExplorerPanButtons();
        lucide.createIcons();
        // A22: one Apex mount at a time. Envelope moved to Top-N; yield between explorer and compare.
        await loadExplorer();
        await yieldForChartPaint();
        await loadComparison();
    });
</script>

</body>
</html>
