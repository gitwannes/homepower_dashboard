<?php
# --- file: topn.php ---
/**
 * Top-N rankings + all-time records (A7).
 * Solar (default): best/worst yield from top_periods. Click -> analytics day or summary month/year.
 * Grid (metric=grid): highest net import and highest net export from daily_history / monthly_history.
 * Capacity peak (metric=peak): highest 10 Fluvius monthly peaks (A12); open month may use live P1.
 * Gas (metric=gas): highest volume periods. Water adds non-zero lowest periods (A11).
 * Net import is import minus export. Net export is export minus import.
 * The local day still in progress is left out of both day ranks.
 * A grid day click opens that month on summary, because the analytics day chart stays solar.
 * A peak month click opens that year on summary in Capacity peak mode.
 * All-time singles from top_records sit above the charts; each dated row links to analytics that day.
 * Sensorbox / self-consumption rows are omitted when absent from the table.
 * Virtual 'best-day' (A22): clear_sky_envelopes PAC profile on solar metric only.
 */
date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/site_settings.php';
require_once __DIR__ . '/pac_quality.php';
require_once __DIR__ . '/capacity_peak.php';
require_once __DIR__ . '/gas_water.php';

$hp = hp_boot();
hp_require_ready($hp);
hp_publish_pac($hp['settings']);
$dbFile = $hp['db'];

/**
 * Supported ranking metric. Any other value stays on solar yield.
 */
function topn_metric(): string
{
    $metric = isset($_GET['metric']) ? (string)$_GET['metric'] : 'solar';
    if (in_array($metric, ['grid', 'peak', 'gas', 'water'], true)) {
        return $metric;
    }
    return 'solar';
}

/**
 * Display order for all-time singles (A7 / A12). Rows missing from the DB are skipped.
 *
 * @return list<string>
 */
function topn_record_order(): array
{
    return [
        'best_day_kwh',
        'best_year_kwh',
        'peak_surge_w',
        'max_sun_hours',
        'best_self_consumption_kwh',
        'max_capacity_peak_kw',
        'best_year_avg_capacity_peak_kw',
        'max_wind_ms',
        'max_temp_c',
        'min_temp_c',
        'max_module_temp_c',
    ];
}

/**
 * Metric bucket for each record_id. wind is A11 (hidden until that mode exists).
 *
 * @return array<string, 'yield'|'peak'|'wind'|'grid'>
 */
function topn_record_modes(): array
{
    return [
        'best_day_kwh' => 'yield',
        'best_year_kwh' => 'yield',
        'peak_surge_w' => 'yield',
        'max_sun_hours' => 'yield',
        'best_self_consumption_kwh' => 'yield',
        'max_capacity_peak_kw' => 'peak',
        'best_year_avg_capacity_peak_kw' => 'peak',
        'max_wind_ms' => 'wind',
        'max_temp_c' => 'wind',
        'min_temp_c' => 'wind',
        'max_module_temp_c' => 'wind',
    ];
}

/**
 * UI metric (solar/grid/peak) -> record mode. solar maps to yield.
 */
function topn_record_mode_for_metric(string $metric): ?string
{
    if ($metric === 'solar') {
        return 'yield';
    }
    if ($metric === 'peak') {
        return 'peak';
    }
    // grid: no all-time singles yet. wind: A11.
    return null;
}

/**
 * @return list<array{record_id: string, record_value: float, record_date: string, description: string}>
 */
function topn_records(PDO $pdo, ?string $modeFilter = null): array
{
    $byId = [];
    try {
        $stmt = $pdo->query(
            'SELECT record_id, record_value, record_date, description FROM top_records'
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['record_id'] === null || $row['record_value'] === null || $row['record_date'] === null) {
                continue;
            }
            $byId[(string)$row['record_id']] = [
                'record_id' => (string)$row['record_id'],
                'record_value' => (float)$row['record_value'],
                'record_date' => (string)$row['record_date'],
                'description' => (string)($row['description'] ?? ''),
            ];
        }
    } catch (Exception $e) {
        throw $e;
    }
    // Open-month P1 may beat a stored Fluvius Capacity peak month without a rollup run.
    $months = capacity_peak_months_display($pdo);
    if (count($months) > 0) {
        $bestYm = null;
        $bestKw = null;
        foreach ($months as $ym => $kw) {
            if ($bestKw === null || $kw > $bestKw || ($kw === $bestKw && ($bestYm === null || $ym < $bestYm))) {
                $bestYm = $ym;
                $bestKw = $kw;
            }
        }
        if ($bestYm !== null && $bestKw !== null) {
            $id = 'max_capacity_peak_kw';
            $stored = $byId[$id]['record_value'] ?? null;
            if ($stored === null || $bestKw > $stored) {
                $byId[$id] = [
                    'record_id' => $id,
                    'record_value' => $bestKw,
                    'record_date' => $bestYm . '-01',
                    'description' => 'Highest Capacity peak month (kW)',
                ];
            }
        }
        $byYear = [];
        foreach ($months as $ym => $kw) {
            $y = substr($ym, 0, 4);
            if ($y === '') {
                continue;
            }
            if (!isset($byYear[$y])) {
                $byYear[$y] = [];
            }
            $byYear[$y][] = $kw;
        }
        $bestY = null;
        $bestAvg = null;
        foreach ($byYear as $y => $vals) {
            if (count($vals) === 0) {
                continue;
            }
            $avg = array_sum($vals) / count($vals);
            if ($bestAvg === null || $avg > $bestAvg || ($avg === $bestAvg && ($bestY === null || $y < $bestY))) {
                $bestY = $y;
                $bestAvg = $avg;
            }
        }
        if ($bestY !== null && $bestAvg !== null) {
            $id = 'best_year_avg_capacity_peak_kw';
            $stored = $byId[$id]['record_value'] ?? null;
            $avgR = round($bestAvg, 3);
            if ($stored === null || $avgR > $stored) {
                $byId[$id] = [
                    'record_id' => $id,
                    'record_value' => $avgR,
                    'record_date' => $bestY . '-01-01',
                    'description' => 'Year with highest average Capacity peak (kW)',
                ];
            }
        }
    }
    $modes = topn_record_modes();
    $out = [];
    foreach (topn_record_order() as $id) {
        if (!isset($byId[$id])) {
            continue;
        }
        $mode = $modes[$id] ?? null;
        if ($modeFilter !== null && $mode !== $modeFilter) {
            continue;
        }
        $out[] = $byId[$id];
    }
    return $out;
}

/**
 * @param array<int, mixed> $params
 * @return list<array{rank: int, period_key: string, net_kwh: float}>
 */
function topn_net_rows(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    $rank = 1;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['period_key'] === null || $row['net_kwh'] === null) {
            continue;
        }
        $rows[] = [
            'rank' => $rank,
            'period_key' => (string)$row['period_key'],
            'import_kwh' => round((float)$row['import_kwh'], 3),
            'export_kwh' => round((float)$row['export_kwh'], 3),
            'net_kwh' => round((float)$row['net_kwh'], 3),
        ];
        $rank++;
    }
    return $rows;
}

/**
 * Highest net import and highest net export for days, months, and years.
 * Day lists omit the Brussels calendar day still in progress.
 *
 * @return array{day: array, month: array, year: array}
 */
function topn_grid_lists(PDO $pdo): array
{
    $today = date('Y-m-d');
    $dayWhere = "date <> ? AND grid_import_kwh IS NOT NULL AND grid_export_kwh IS NOT NULL";
    $dayImport = topn_net_rows(
        $pdo,
        "SELECT date AS period_key,
                ROUND(grid_import_kwh, 3) AS import_kwh,
                ROUND(grid_export_kwh, 3) AS export_kwh,
                ROUND(grid_import_kwh - grid_export_kwh, 3) AS net_kwh
         FROM daily_history
         WHERE $dayWhere
         ORDER BY (grid_import_kwh - grid_export_kwh) DESC, date ASC
         LIMIT 20",
        [$today]
    );
    $dayExport = topn_net_rows(
        $pdo,
        "SELECT date AS period_key,
                ROUND(grid_import_kwh, 3) AS import_kwh,
                ROUND(grid_export_kwh, 3) AS export_kwh,
                ROUND(grid_export_kwh - grid_import_kwh, 3) AS net_kwh
         FROM daily_history
         WHERE $dayWhere
         ORDER BY (grid_export_kwh - grid_import_kwh) DESC, date ASC
         LIMIT 20",
        [$today]
    );
    $monthWhere = "total_import_kwh IS NOT NULL AND total_export_kwh IS NOT NULL";
    $monthImport = topn_net_rows(
        $pdo,
        "SELECT year_month AS period_key,
                ROUND(total_import_kwh, 3) AS import_kwh,
                ROUND(total_export_kwh, 3) AS export_kwh,
                ROUND(total_import_kwh - total_export_kwh, 3) AS net_kwh
         FROM monthly_history
         WHERE $monthWhere
         ORDER BY (total_import_kwh - total_export_kwh) DESC, year_month ASC
         LIMIT 10",
        []
    );
    $monthExport = topn_net_rows(
        $pdo,
        "SELECT year_month AS period_key,
                ROUND(total_import_kwh, 3) AS import_kwh,
                ROUND(total_export_kwh, 3) AS export_kwh,
                ROUND(total_export_kwh - total_import_kwh, 3) AS net_kwh
         FROM monthly_history
         WHERE $monthWhere
         ORDER BY (total_export_kwh - total_import_kwh) DESC, year_month ASC
         LIMIT 10",
        []
    );
    $yearImport = topn_net_rows(
        $pdo,
        "SELECT substr(year_month, 1, 4) AS period_key,
                ROUND(SUM(total_import_kwh), 3) AS import_kwh,
                ROUND(SUM(total_export_kwh), 3) AS export_kwh,
                ROUND(SUM(total_import_kwh) - SUM(total_export_kwh), 3) AS net_kwh
         FROM monthly_history
         WHERE $monthWhere
         GROUP BY substr(year_month, 1, 4)
         ORDER BY (SUM(total_import_kwh) - SUM(total_export_kwh)) DESC, period_key ASC
         LIMIT 10",
        []
    );
    $yearExport = topn_net_rows(
        $pdo,
        "SELECT substr(year_month, 1, 4) AS period_key,
                ROUND(SUM(total_import_kwh), 3) AS import_kwh,
                ROUND(SUM(total_export_kwh), 3) AS export_kwh,
                ROUND(SUM(total_export_kwh) - SUM(total_import_kwh), 3) AS net_kwh
         FROM monthly_history
         WHERE $monthWhere
         GROUP BY substr(year_month, 1, 4)
         ORDER BY (SUM(total_export_kwh) - SUM(total_import_kwh)) DESC, period_key ASC
         LIMIT 10",
        []
    );
    return [
        'day' => ['import' => $dayImport, 'export' => $dayExport],
        'month' => ['import' => $monthImport, 'export' => $monthExport],
        'year' => ['import' => $yearImport, 'export' => $yearExport],
    ];
}

/**
 * Ranked calendar years summed from daily gas or water volume.
 *
 * @return list<array{rank: int, period_key: string, m3: float}>
 */
function topn_volume_years(PDO $pdo, string $column, string $direction, int $limit, bool $excludeZero): array
{
    if ($column !== 'gas_m3' && $column !== 'water_m3') {
        return [];
    }
    $dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
    $having = $excludeZero ? " HAVING SUM({$column}) > 0" : '';
    $queries = [
        "SELECT substr(year_month, 1, 4) AS period_key, SUM({$column}) AS m3
         FROM monthly_history
         WHERE {$column} IS NOT NULL
         GROUP BY substr(year_month, 1, 4){$having}
         ORDER BY m3 {$dir}, period_key ASC LIMIT " . (int)$limit,
        "SELECT substr(date, 1, 4) AS period_key, SUM({$column}) AS m3
         FROM daily_history
         WHERE {$column} IS NOT NULL
         GROUP BY substr(date, 1, 4){$having}
         ORDER BY m3 {$dir}, period_key ASC LIMIT " . (int)$limit,
    ];
    $resultRows = [];
    foreach ($queries as $sql) {
        try {
            $resultRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $resultRows = [];
        }
        if (count($resultRows) > 0) {
            break;
        }
    }
    $rows = [];
    $rank = 1;
    foreach ($resultRows as $row) {
        $rows[] = [
            'rank' => $rank++,
            'period_key' => (string)$row['period_key'],
            'm3' => round((float)$row['m3'], 3),
        ];
    }
    return $rows;
}

/**
 * Gas has highest-only lists; water has highest and non-zero lowest lists.
 *
 * @return array{day: array, month: array, year: array}
 */
function topn_volume_lists(PDO $pdo, string $metric): array
{
    $column = $metric === 'water' ? 'water_m3' : 'gas_m3';
    $includeLowest = $metric === 'water';
    return [
        'day' => [
            // Open local day stays out of day ranks (same spirit as grid).
            'best' => gw_top_days($pdo, $column, 'DESC', 20, true, false),
            'worst' => $includeLowest ? gw_top_days($pdo, $column, 'ASC', 20, true, true) : [],
        ],
        'month' => [
            'best' => gw_top_months($pdo, $column, 'DESC', 10, false),
            'worst' => $includeLowest ? gw_top_months($pdo, $column, 'ASC', 10, true) : [],
        ],
        'year' => [
            'best' => topn_volume_years($pdo, $column, 'DESC', 10, false),
            'worst' => $includeLowest ? topn_volume_years($pdo, $column, 'ASC', 10, true) : [],
        ],
    ];
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
        $action = $_GET['ajax'];

        if ($action === 'records') {
            $modeFilter = topn_record_mode_for_metric(topn_metric());
            try {
                $records = $modeFilter === null ? [] : topn_records($pdo, $modeFilter);
            } catch (Exception $e) {
                echo json_encode([
                    'error' => 'top_records missing - run: python3 rollup.py -buildalltoprecords',
                    'detail' => $e->getMessage(),
                ]);
                exit;
            }
            echo json_encode([
                'ok' => true,
                'metric' => topn_metric(),
                'mode' => $modeFilter,
                'count' => count($records),
                'records' => $records,
            ]);
            exit;
        }

        // Virtual 'best-day': month=1..12 or 0/all/year = whole-year composite (MAX across months).
        if ($action === 'bestday' && isset($_GET['month'])) {
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
            despike_pac_series($pac, $pacDates);
            $nonzero = 0;
            // Composite-day energy: each slot is 5 min mean W -> kWh = W * (5/60) / 1000.
            $totalKwh = 0.0;
            foreach ($pac as $v) {
                if ($v > 0) {
                    $nonzero++;
                }
                $totalKwh += ((float)$v) * (5.0 / 60.0) / 1000.0;
            }
            echo json_encode([
                'month' => $wholeYear ? 0 : $month,
                'label' => $wholeYear ? 'Whole year' : $month,
                'filled' => $nonzero,
                'total_kwh' => round($totalKwh, 2),
                'categories' => $categories,
                'max_pac_w' => $pac,
                'max_rad_wm2' => $rad,
                'max_pac_date' => $pacDates,
            ]);
            exit;
        }

        if ($action === 'lists' && topn_metric() === 'grid') {
            try {
                $lists = topn_grid_lists($pdo);
            } catch (Exception $e) {
                echo json_encode([
                    'error' => 'Grid columns missing - run: python3 rollup.py -buildall',
                    'detail' => $e->getMessage(),
                ]);
                exit;
            }
            $filled = 0;
            foreach ($lists as $dirs) {
                foreach ($dirs as $list) {
                    $filled += count($list);
                }
            }
            echo json_encode(['ok' => true, 'metric' => 'grid', 'filled' => $filled, 'lists' => $lists]);
            exit;
        }

        if ($action === 'lists' && topn_metric() === 'peak') {
            $months = capacity_peak_months_display($pdo);
            $list = capacity_peak_top_months($months, 10);
            echo json_encode([
                'ok' => true,
                'metric' => 'peak',
                'filled' => count($list),
                'lists' => [
                    'month' => ['best' => $list],
                ],
                'live_peak_kw' => capacity_peak_live_kw($pdo),
                'open_month' => date('Y-m'),
            ]);
            exit;
        }

        if ($action === 'lists' && in_array(topn_metric(), ['gas', 'water'], true)) {
            $metric = topn_metric();
            $lists = topn_volume_lists($pdo, $metric);
            $filled = 0;
            foreach ($lists as $directions) {
                foreach ($directions as $list) {
                    $filled += count($list);
                }
            }
            echo json_encode([
                'ok' => true,
                'metric' => $metric,
                'unit' => 'm3',
                'filled' => $filled,
                'lists' => $lists,
            ]);
            exit;
        }

        if ($action === 'lists') {
            $out = [
                'day' => ['best' => [], 'worst' => []],
                'month' => ['best' => [], 'worst' => []],
                'year' => ['best' => [], 'worst' => []],
            ];
            try {
                $stmt = $pdo->query(
                    'SELECT grain, direction, rank, period_key, yield_kwh
                     FROM top_periods
                     ORDER BY grain ASC, direction ASC, rank ASC'
                );
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $g = $row['grain'];
                    $d = $row['direction'];
                    if (!isset($out[$g][$d])) {
                        continue;
                    }
                    $out[$g][$d][] = [
                        'rank' => (int)$row['rank'],
                        'period_key' => $row['period_key'],
                        'yield_kwh' => round((float)$row['yield_kwh'], 2),
                    ];
                }
            } catch (Exception $e) {
                echo json_encode([
                    'error' => 'top_periods missing - run: python3 rollup.py -buildall',
                    'detail' => $e->getMessage(),
                ]);
                exit;
            }
            $filled = 0;
            foreach ($out as $dirs) {
                foreach ($dirs as $list) {
                    $filled += count($list);
                }
            }
            echo json_encode(['ok' => true, 'filled' => $filled, 'lists' => $out]);
            exit;
        }

        echo json_encode(['error' => 'Unknown action']);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$gasAvailable = false;
$waterAvailable = false;
if (file_exists($dbFile)) {
    try {
        $availabilityPdo = new PDO('sqlite:' . $dbFile);
        $availabilityPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $gasAvailable = gw_gas_available($availabilityPdo);
        $waterAvailable = gw_water_available($availabilityPdo);
    } catch (Exception $e) {
        // Metric chips remain hidden when availability cannot be established.
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Top Yield Rankings | Energy Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        body { background-color: #f8fafc; font-family: system-ui, -apple-system, sans-serif; }
        .card { @apply bg-white p-6 rounded-3xl shadow-sm border border-slate-100 flex flex-col; }
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
            padding: 8px 18px;
            font: 700 13px/1.2 system-ui, sans-serif;
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
        .nav-arrow {
            @apply w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-700
                   flex items-center justify-center hover:bg-slate-50 shadow-sm transition-colors cursor-pointer;
        }
    </style>
</head>
<body class="p-4 md:p-8">

<div class="max-w-6xl mx-auto space-y-6">

    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="index.php" class="w-10 h-10 rounded-2xl bg-white border border-slate-200 text-slate-600 flex items-center justify-center hover:bg-slate-50 transition-colors shadow-sm">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <div>
                <h1 id="topn-title" class="text-2xl font-black text-slate-900 tracking-tight">Top Yield Rankings</h1>
                <p id="topn-blurb" class="text-xs text-slate-400 mt-0.5">Best and worst days (20), months (10), years (10) — click a bar to open that period</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="activity" class="w-3.5 h-3.5 text-emerald-600"></i> Live Dashboard
            </a>
            <a href="analytics.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="line-chart" class="w-3.5 h-3.5 text-indigo-600"></i> Analytics
            </a>
            <a id="nav-summary" href="summary.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="calendar-range" class="w-3.5 h-3.5 text-emerald-600"></i> Summary
            </a>
            <?= hp_nav_weather() ?>
            <?= hp_nav_settings() ?>
        </div>
    </header>

    <div class="flex flex-wrap items-center gap-3">
        <div class="metric-switch" role="group" aria-label="Ranking metric">
            <button type="button" id="metric-solar" class="metric-on" aria-pressed="true" onclick="setMetric('solar')">Solar yield</button>
            <button type="button" id="metric-grid" aria-pressed="false" onclick="setMetric('grid')">Grid import / export</button>
            <button type="button" id="metric-peak" aria-pressed="false" onclick="setMetric('peak')">Capacity peak</button>
            <?php if ($gasAvailable): ?>
                <button type="button" id="metric-gas" aria-pressed="false" onclick="setMetric('gas')">Gas</button>
            <?php endif; ?>
            <?php if ($waterAvailable): ?>
                <button type="button" id="metric-water" aria-pressed="false" onclick="setMetric('water')">Water</button>
            <?php endif; ?>
        </div>
        <div id="bars-wrap" class="metric-switch is-hidden" role="group" aria-label="Grid bars">
            <button type="button" id="bars-all" class="metric-on" aria-pressed="true" onclick="setBars('all')">All</button>
            <button type="button" id="bars-registers" aria-pressed="false" onclick="setBars('registers')">Import / export</button>
            <button type="button" id="bars-net" aria-pressed="false" onclick="setBars('net')">Net</button>
        </div>
    </div>

    <div id="topn-warning" class="hidden text-xs text-amber-600 bg-amber-50 border border-amber-200 p-3 rounded-xl">
        No ranking data yet. Run <code>python3 rollup.py -buildall</code> (or <code>-daily</code>).
    </div>

    <div id="records-card" class="card border-t-4 border-t-amber-500">
        <h2 class="text-lg font-bold text-slate-900 mb-1">All-time records</h2>
        <p id="records-blurb" class="text-xs text-slate-400 mb-3">Records for the selected metric. Yield rows open Analytics or Summary; Capacity peak rows open Summary.</p>
        <div id="records-warning" class="hidden text-xs text-amber-600 bg-amber-50 border border-amber-200 p-3 rounded-xl mb-3">
            No all-time records yet. Run <code>python3 rollup.py -buildalltoprecords</code> (or <code>-buildall</code> / <code>-daily</code>).
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-100">
                        <th class="py-2 pr-3 font-semibold">Record</th>
                        <th class="py-2 pr-3 font-semibold">Value</th>
                        <th class="py-2 font-semibold">Date</th>
                    </tr>
                </thead>
                <tbody id="records-body" class="divide-y divide-slate-100"></tbody>
            </table>
        </div>
    </div>

    <div id="bestday-card" class="card border-t-4 border-t-sky-500">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                    <i data-lucide="cloud-sun" class="w-5 h-5 text-sky-500"></i> Virtual 'best-day'
                </h2>
                <p class="text-xs text-slate-400">
                    Composite all-time max AC power at each 5-min slot (from <code>clear_sky_envelopes</code>).
                </p>
                <p id="bestday-total" class="text-sm font-semibold text-slate-700 mt-1 tabular-nums"></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="nav-arrow" onclick="stepBestDay(-1)" title="Previous" aria-label="Previous period">
                    <i data-lucide="chevron-left" class="w-5 h-5"></i>
                </button>
                <select id="bestdayMonth" class="text-sm px-3 py-1.5 border border-slate-200 rounded-lg text-slate-700 bg-slate-50 min-w-[8rem]"
                        onchange="loadBestDay()">
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
                <button type="button" class="nav-arrow" onclick="stepBestDay(1)" title="Next" aria-label="Next period">
                    <i data-lucide="chevron-right" class="w-5 h-5"></i>
                </button>
            </div>
        </div>
        <div id="chart-bestday" class="w-full h-[400px]"></div>
        <div id="bestday-warning" class="hidden text-xs text-amber-600 bg-amber-50 border border-amber-200 p-3 rounded-xl mt-4"></div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div id="card-day-best" class="card border-t-4 border-t-emerald-500">
            <h2 id="title-day-best" class="text-lg font-bold text-slate-900 mb-1">Best days</h2>
            <p id="sub-day-best" class="text-xs text-slate-400 mb-3">Top 20 by solar yield (kWh)</p>
            <div id="chart-day-best" class="w-full"></div>
        </div>
        <div id="card-day-worst" class="card border-t-4 border-t-rose-500">
            <h2 id="title-day-worst" class="text-lg font-bold text-slate-900 mb-1">Worst days</h2>
            <p id="sub-day-worst" class="text-xs text-slate-400 mb-3">Bottom 20 by solar yield (kWh); no-production days and today excluded</p>
            <div id="chart-day-worst" class="w-full"></div>
        </div>
        <div id="card-month-best" class="card border-t-4 border-t-teal-500">
            <h2 id="title-month-best" class="text-lg font-bold text-slate-900 mb-1">Best months</h2>
            <p id="sub-month-best" class="text-xs text-slate-400 mb-3">Top 10 by solar yield (kWh)</p>
            <div id="chart-month-best" class="w-full"></div>
        </div>
        <div id="card-month-worst" class="card border-t-4 border-t-orange-500">
            <h2 id="title-month-worst" class="text-lg font-bold text-slate-900 mb-1">Worst months</h2>
            <p id="sub-month-worst" class="text-xs text-slate-400 mb-3">Bottom 10 by solar yield (kWh)</p>
            <div id="chart-month-worst" class="w-full"></div>
        </div>
        <div id="card-year-best" class="card border-t-4 border-t-indigo-500">
            <h2 id="title-year-best" class="text-lg font-bold text-slate-900 mb-1">Best years</h2>
            <p id="sub-year-best" class="text-xs text-slate-400 mb-3">Top 10 by solar yield (kWh)</p>
            <div id="chart-year-best" class="w-full"></div>
        </div>
        <div id="card-year-worst" class="card border-t-4 border-t-slate-500">
            <h2 id="title-year-worst" class="text-lg font-bold text-slate-900 mb-1">Worst years</h2>
            <p id="sub-year-worst" class="text-xs text-slate-400 mb-3">Bottom 10 by solar yield (kWh)</p>
            <div id="chart-year-worst" class="w-full"></div>
        </div>
    </div>

</div>

<script>
    lucide.createIcons();

    /** @type {Record<string, any>} */
    const charts = {};
    /** @type {any} */
    let chartBestDay = null;
    /** @type {'solar'|'grid'|'peak'|'gas'|'water'} */
    let metric = 'solar';
    /** @type {'all'|'registers'|'net'} */
    let bars = 'all';

    const bestDayAxisLabels = {
        rotate: 0,
        rotateAlways: false,
        hideOverlappingLabels: true,
        showDuplicates: false,
        trim: false,
        style: { colors: '#94a3b8', fontSize: '10px' },
        formatter: function (val) {
            if (typeof val !== 'string') return '';
            return val.endsWith(':00') ? val : '';
        }
    };

    /**
     * @param {any} opts
     * @returns {string}
     */
    function bestDayTooltip(opts) {
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

    function stepBestDay(delta) {
        const sel = document.getElementById('bestdayMonth');
        if (!sel) return;
        const opts = Array.from(sel.options).map((o) => o.value);
        let idx = opts.indexOf(sel.value);
        if (idx < 0) idx = 0;
        idx = (idx + delta + opts.length) % opts.length;
        sel.value = opts[idx];
        loadBestDay();
    }

    /**
     * Solar-only Virtual 'best-day' panel (A22).
     */
    async function loadBestDay() {
        const card = document.getElementById('bestday-card');
        const warn = document.getElementById('bestday-warning');
        const host = document.querySelector('#chart-bestday');
        if (!card || !warn || !host) return;
        const totalEl = document.getElementById('bestday-total');
        if (metric !== 'solar') {
            card.classList.add('is-hidden');
            if (chartBestDay) {
                chartBestDay.destroy();
                chartBestDay = null;
            }
            if (totalEl) totalEl.textContent = '';
            return;
        }
        card.classList.remove('is-hidden');
        warn.classList.add('hidden');
        warn.textContent = '';
        const month = document.getElementById('bestdayMonth').value;
        let data;
        try {
            const res = await fetch('topn.php?ajax=bestday&month=' + encodeURIComponent(month));
            data = await res.json();
        } catch (e) {
            if (chartBestDay) { chartBestDay.destroy(); chartBestDay = null; }
            warn.textContent = 'Failed to load Virtual best-day data.';
            warn.classList.remove('hidden');
            host.innerHTML = '';
            if (totalEl) totalEl.textContent = '';
            return;
        }
        if (data.error) {
            if (chartBestDay) { chartBestDay.destroy(); chartBestDay = null; }
            warn.textContent = data.error + (data.detail ? ' (' + data.detail + ')' : '');
            warn.classList.remove('hidden');
            host.innerHTML = '';
            if (totalEl) totalEl.textContent = '';
            return;
        }
        if (!data.filled) {
            if (chartBestDay) { chartBestDay.destroy(); chartBestDay = null; }
            warn.textContent = 'No Virtual best-day data in clear_sky_envelopes. Re-run: python3 rollup.py -buildall';
            warn.classList.remove('hidden');
            host.innerHTML = '';
            if (totalEl) totalEl.textContent = '';
            return;
        }
        if (totalEl) {
            const kwh = Number(data.total_kwh);
            totalEl.textContent = Number.isFinite(kwh)
                ? ('Virtual day yield: ' + kwh.toLocaleString(undefined, {
                    minimumFractionDigits: 1,
                    maximumFractionDigits: 2
                }) + ' kWh')
                : '';
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
            xaxis: { type: 'category', categories: data.categories, labels: bestDayAxisLabels },
            yaxis: {
                title: { text: 'PAC (W)', style: { color: '#94a3b8', fontSize: '10px' } },
                decimalsInFloat: 0,
                labels: {
                    style: { colors: '#94a3b8', fontSize: '10px' },
                    formatter: function (val) {
                        const n = Number(val);
                        if (!Number.isFinite(n)) return '';
                        return Math.round(n).toLocaleString();
                    }
                }
            },
            grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
            tooltip: {
                custom: function (opts) {
                    opts.pacDates = pacDates;
                    return bestDayTooltip(opts);
                }
            }
        };
        if (chartBestDay) {
            chartBestDay.destroy();
            chartBestDay = null;
        }
        host.innerHTML = '';
        chartBestDay = new ApexCharts(host, options);
        await chartBestDay.render();
        if (chartBestDay && typeof chartBestDay.windowResize === 'function') {
            chartBestDay.windowResize();
        }
    }

    /**
     * @param {string} recordId
     * @param {number} value
     * @returns {string}
     */
    function formatRecordValue(recordId, value) {
        const n = Number(value);
        if (Number.isNaN(n)) return '-';
        if (recordId === 'peak_surge_w') {
            return `${Math.round(n).toLocaleString()} W`;
        }
        if (recordId === 'max_capacity_peak_kw' || recordId === 'best_year_avg_capacity_peak_kw') {
            return `${n.toLocaleString(undefined, { maximumFractionDigits: 3 })} kW`;
        }
        if (recordId === 'max_wind_ms') {
            return `${n.toLocaleString(undefined, { maximumFractionDigits: 1 })} m/s`;
        }
        if (recordId === 'max_temp_c' || recordId === 'min_temp_c' || recordId === 'max_module_temp_c') {
            return `${n.toLocaleString(undefined, { maximumFractionDigits: 1 })} C`;
        }
        if (recordId === 'max_sun_hours') {
            return `${n.toLocaleString(undefined, { maximumFractionDigits: 1 })} h`;
        }
        return `${n.toLocaleString(undefined, { maximumFractionDigits: 2 })} kWh`;
    }

    /**
     * Month records show YYYY-MM; year records show YYYY; day records stay YYYY-MM-DD.
     *
     * @param {string} recordId
     * @param {string} recordDate
     * @returns {string}
     */
    function formatRecordDate(recordId, recordDate) {
        const d = String(recordDate || '');
        if (recordId === 'max_capacity_peak_kw') {
            return d.length >= 7 ? d.slice(0, 7) : d;
        }
        if (recordId === 'best_year_avg_capacity_peak_kw' || recordId === 'best_year_kwh') {
            return d.length >= 4 ? d.slice(0, 4) : d;
        }
        return d;
    }

    /**
     * @param {string} recordId
     * @param {string} recordDate
     */
    function openRecord(recordId, recordDate) {
        if (recordId === 'max_capacity_peak_kw') {
            const ym = String(recordDate).slice(0, 7);
            const year = ym.slice(0, 4);
            window.location.href = 'summary.php?metric=peak&year=' + encodeURIComponent(year)
                + '&month=' + encodeURIComponent(ym);
            return;
        }
        if (recordId === 'best_year_avg_capacity_peak_kw' || recordId === 'best_year_kwh') {
            const year = String(recordDate).slice(0, 4);
            const metricPart = recordId === 'best_year_kwh' ? '' : 'metric=peak&';
            window.location.href = 'summary.php?' + metricPart + 'year=' + encodeURIComponent(year);
            return;
        }
        window.location.href = 'analytics.php?date=' + encodeURIComponent(recordDate);
    }

    /**
     * @param {Array<{record_id: string, record_value: number, record_date: string, description: string}>} rows
     */
    function renderRecords(rows) {
        const card = document.getElementById('records-card');
        const body = document.getElementById('records-body');
        const warn = document.getElementById('records-warning');
        const blurb = document.getElementById('records-blurb');
        body.innerHTML = '';
        if (metric === 'grid' || metric === 'gas' || metric === 'water') {
            card.classList.add('is-hidden');
            return;
        }
        card.classList.remove('is-hidden');
        if (metric === 'peak') {
            blurb.textContent = 'Capacity peak records from Fluvius (open month may use live P1). Click a row to open Summary.';
        } else {
            blurb.textContent = 'Yield records for Solar mode. Day rows open Analytics; year generation opens Summary. Wind / temperature records wait for A11.';
        }
        if (!rows || !rows.length) {
            warn.textContent = metric === 'peak'
                ? 'No Capacity peak records yet. Import piekvermogen CSV, then run python3 rollup.py -buildalltoprecords.'
                : 'No yield records yet. Run python3 rollup.py -buildalltoprecords (or -buildall / -daily).';
            warn.classList.remove('hidden');
            return;
        }
        warn.classList.add('hidden');
        rows.forEach((row) => {
            const tr = document.createElement('tr');
            tr.className = 'cursor-pointer hover:bg-amber-50/60 transition-colors';
            const dateLabel = formatRecordDate(row.record_id, row.record_date);
            tr.title = 'Open ' + dateLabel;
            tr.addEventListener('click', () => {
                openRecord(row.record_id, row.record_date);
            });
            const label = row.description || row.record_id;
            tr.innerHTML =
                '<td class="py-2.5 pr-3 text-slate-800 font-medium">' + label + '</td>' +
                '<td class="py-2.5 pr-3 font-mono text-slate-700">' + formatRecordValue(row.record_id, row.record_value) + '</td>' +
                '<td class="py-2.5 font-mono text-indigo-600 underline-offset-2 hover:underline">' + dateLabel + '</td>';
            body.appendChild(tr);
        });
    }

    async function loadRecords() {
        const warn = document.getElementById('records-warning');
        if (metric === 'gas' || metric === 'water') {
            document.getElementById('records-card').classList.add('is-hidden');
            return;
        }
        try {
            let q = 'topn.php?ajax=records';
            if (metric === 'grid') q += '&metric=grid';
            if (metric === 'peak') q += '&metric=peak';
            const res = await fetch(q);
            const data = await res.json();
            if (data.error) {
                document.getElementById('records-card').classList.remove('is-hidden');
                warn.textContent = data.error;
                warn.classList.remove('hidden');
                document.getElementById('records-body').innerHTML = '';
                return;
            }
            renderRecords(data.records || []);
        } catch (e) {
            document.getElementById('records-card').classList.remove('is-hidden');
            warn.textContent = 'Failed to load all-time records.';
            warn.classList.remove('hidden');
            document.getElementById('records-body').innerHTML = '';
        }
    }

    /**
     * @param {string} grain
     * @param {string} periodKey
     */
    function openPeriod(grain, periodKey) {
        if (metric === 'peak') {
            if (grain === 'month') {
                const year = String(periodKey).slice(0, 4);
                window.location.href = 'summary.php?metric=peak&year=' + encodeURIComponent(year)
                    + '&month=' + encodeURIComponent(periodKey);
            }
            return;
        }
        if (metric === 'grid') {
            const barsPart = bars === 'all' ? '' : '&bars=' + encodeURIComponent(bars);
            if (grain === 'day') {
                const month = String(periodKey).slice(0, 7);
                window.location.href = 'summary.php?metric=grid&month=' + encodeURIComponent(month) + barsPart;
                return;
            }
            if (grain === 'month') {
                window.location.href = 'summary.php?metric=grid&month=' + encodeURIComponent(periodKey) + barsPart;
                return;
            }
            if (grain === 'year') {
                window.location.href = 'summary.php?metric=grid&year=' + encodeURIComponent(periodKey) + barsPart;
            }
            return;
        }
        if (metric === 'gas' || metric === 'water') {
            const metricPart = 'metric=' + metric;
            if (grain === 'day') {
                window.location.href = 'summary.php?' + metricPart + '&month='
                    + encodeURIComponent(String(periodKey).slice(0, 7));
                return;
            }
            if (grain === 'month') {
                window.location.href = 'summary.php?' + metricPart + '&month=' + encodeURIComponent(periodKey);
                return;
            }
            if (grain === 'year') {
                window.location.href = 'summary.php?' + metricPart + '&year=' + encodeURIComponent(periodKey);
            }
            return;
        }
        if (grain === 'day') {
            window.location.href = 'analytics.php?date=' + encodeURIComponent(periodKey);
            return;
        }
        if (grain === 'month') {
            window.location.href = 'summary.php?month=' + encodeURIComponent(periodKey);
            return;
        }
        if (grain === 'year') {
            window.location.href = 'summary.php?year=' + encodeURIComponent(periodKey);
        }
    }

    /**
     * @param {Array<{period_key: string, yield_kwh?: number, net_kwh?: number, peak_kw?: number, m3?: number}>} rows
     * @returns {number[]}
     */
    function rowValues(rows) {
        return rows.map((r) => {
            if (r.peak_kw != null) return r.peak_kw;
            if (r.net_kwh != null) return r.net_kwh;
            if (r.m3 != null) return r.m3;
            return r.yield_kwh;
        });
    }

    /**
     * @param {string} hostId
     * @param {string} grain
     * @param {string} direction
     * @param {Array<{period_key: string, yield_kwh?: number, net_kwh?: number, m3?: number}>} rows
     * @param {string} color
     * @param {string} seriesName
     */
    function renderRankChart(hostId, grain, direction, rows, color, seriesName) {
        const host = document.querySelector('#' + hostId);
        if (charts[hostId]) {
            charts[hostId].destroy();
            charts[hostId] = null;
        }
        host.innerHTML = '';
        if (!rows || !rows.length) {
            host.innerHTML = '<p class="text-sm text-slate-400 py-8 text-center">No data</p>';
            return;
        }
        const categories = rows.map((r) => r.period_key);
        const data = rowValues(rows);
        const keys = rows.map((r) => r.period_key);

        charts[hostId] = new ApexCharts(host, {
            chart: {
                type: 'bar',
                height: grain === 'day' ? 360 : (grain === 'year' ? 280 : 320),
                toolbar: { show: false },
                animations: { enabled: false },
                events: {
                    dataPointSelection: function (_e, _ctx, cfg) {
                        const i = cfg.dataPointIndex;
                        if (i == null || i < 0 || !keys[i]) return;
                        openPeriod(grain, keys[i]);
                    }
                }
            },
            series: [{ name: seriesName, data: data }],
            colors: [color],
            plotOptions: {
                bar: {
                    horizontal: true,
                    borderRadius: 4,
                    barHeight: '70%'
                }
            },
            dataLabels: { enabled: false },
            xaxis: {
                categories: categories,
                labels: { style: { colors: '#94a3b8', fontSize: '10px' } }
            },
            yaxis: {
                labels: { style: { colors: '#64748b', fontSize: '11px', fontFamily: 'ui-monospace, monospace' } }
            },
            grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
            tooltip: {
                y: {
                    formatter: (v) => metric === 'peak'
                        ? `${Number(v).toLocaleString(undefined, { maximumFractionDigits: 3 })} kW`
                        : ((metric === 'gas' || metric === 'water')
                            ? `${Number(v).toLocaleString(undefined, { maximumFractionDigits: 3 })} m3`
                            : `${Number(v).toLocaleString(undefined, { maximumFractionDigits: 3 })} kWh`)
                }
            }
        });
        charts[hostId].render();
    }

    /**
     * @param {Array<{import_kwh: number, export_kwh: number, net_kwh: number}>} rows
     * @returns {{series: Array<{name: string, data: number[]}>, colors: string[]}}
     */
    function gridRankSeries(rows) {
        const all = [
            { name: 'Import (kWh)', data: rows.map((r) => r.import_kwh), color: '#2563eb' },
            { name: 'Export (kWh)', data: rows.map((r) => r.export_kwh), color: '#10b981' },
            { name: 'Net (kWh)', data: rows.map((r) => r.net_kwh), color: '#eab308' }
        ];
        const picked = bars === 'registers' ? all.slice(0, 2) : (bars === 'net' ? all.slice(2) : all);
        return {
            series: picked.map((item) => ({ name: item.name, data: item.data })),
            colors: picked.map((item) => item.color)
        };
    }

    /**
     * Ranked period with import, export, and the ranking net (yellow).
     *
     * @param {string} hostId
     * @param {string} grain
     * @param {Array<{period_key: string, import_kwh: number, export_kwh: number, net_kwh: number}>} rows
     */
    function renderGridRankChart(hostId, grain, rows) {
        const host = document.querySelector('#' + hostId);
        if (charts[hostId]) {
            charts[hostId].destroy();
            charts[hostId] = null;
        }
        host.innerHTML = '';
        if (!rows || !rows.length) {
            host.innerHTML = '<p class="text-sm text-slate-400 py-8 text-center">No data</p>';
            return;
        }
        const keys = rows.map((r) => r.period_key);
        const height = grain === 'day' ? 720 : (grain === 'year' ? 420 : 520);
        const picked = gridRankSeries(rows);
        charts[hostId] = new ApexCharts(host, {
            chart: {
                type: 'bar',
                height: height,
                toolbar: { show: false },
                animations: { enabled: false },
                events: {
                    dataPointSelection: function (_e, _ctx, cfg) {
                        const i = cfg.dataPointIndex;
                        if (i == null || i < 0 || !keys[i]) return;
                        openPeriod(grain, keys[i]);
                    }
                }
            },
            series: picked.series,
            colors: picked.colors,
            plotOptions: {
                bar: { horizontal: true, borderRadius: 3, barHeight: '78%' }
            },
            dataLabels: { enabled: false },
            xaxis: {
                categories: keys,
                labels: { style: { colors: '#94a3b8', fontSize: '10px' } }
            },
            yaxis: {
                labels: { style: { colors: '#64748b', fontSize: '11px', fontFamily: 'ui-monospace, monospace' } }
            },
            grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
            legend: { position: 'top', fontSize: '12px', markers: { radius: 2 } },
            tooltip: {
                shared: true,
                intersect: false,
                y: { formatter: (v) => (v == null || Number.isNaN(Number(v)) ? '-' : `${Number(v).toLocaleString(undefined, { maximumFractionDigits: 3 })} kWh`) }
            }
        });
        charts[hostId].render();
    }

    function paintPeakCards() {
        const peakOnly = metric === 'peak';
        const gasOnly = metric === 'gas';
        document.getElementById('card-day-best').classList.toggle('is-hidden', peakOnly);
        document.getElementById('card-day-worst').classList.toggle('is-hidden', peakOnly || gasOnly);
        document.getElementById('card-month-worst').classList.toggle('is-hidden', peakOnly || gasOnly);
        document.getElementById('card-year-best').classList.toggle('is-hidden', peakOnly);
        document.getElementById('card-year-worst').classList.toggle('is-hidden', peakOnly || gasOnly);
        document.getElementById('card-month-best').classList.toggle('lg:col-span-2', peakOnly);
    }

    function paintCopy() {
        const solarBtn = document.getElementById('metric-solar');
        const gridBtn = document.getElementById('metric-grid');
        const peakBtn = document.getElementById('metric-peak');
        const gasBtn = document.getElementById('metric-gas');
        const waterBtn = document.getElementById('metric-water');
        solarBtn.classList.toggle('metric-on', metric === 'solar');
        gridBtn.classList.toggle('metric-on', metric === 'grid');
        peakBtn.classList.toggle('metric-on', metric === 'peak');
        if (gasBtn) gasBtn.classList.toggle('metric-on', metric === 'gas');
        if (waterBtn) waterBtn.classList.toggle('metric-on', metric === 'water');
        solarBtn.setAttribute('aria-pressed', metric === 'solar' ? 'true' : 'false');
        gridBtn.setAttribute('aria-pressed', metric === 'grid' ? 'true' : 'false');
        peakBtn.setAttribute('aria-pressed', metric === 'peak' ? 'true' : 'false');
        if (gasBtn) gasBtn.setAttribute('aria-pressed', metric === 'gas' ? 'true' : 'false');
        if (waterBtn) waterBtn.setAttribute('aria-pressed', metric === 'water' ? 'true' : 'false');
        paintBars();
        paintPeakCards();
        const summary = document.getElementById('nav-summary');
        const barsPart = bars === 'all' ? '' : '&bars=' + encodeURIComponent(bars);
        if (metric === 'gas' || metric === 'water') {
            const label = metric === 'gas' ? 'Gas' : 'Water';
            const only = metric === 'gas' ? 'Highest' : 'Highest and lowest';
            document.getElementById('topn-title').textContent = label + ' volume rankings';
            document.getElementById('topn-blurb').textContent = only + ' volume periods in m3. Click a bar to open Summary.';
            summary.href = 'summary.php?metric=' + metric;
            document.getElementById('title-day-best').textContent = 'Highest ' + label.toLowerCase() + ' days';
            document.getElementById('sub-day-best').textContent = 'Top 20 by volume (m3)';
            document.getElementById('title-day-worst').textContent = 'Lowest water days';
            document.getElementById('sub-day-worst').textContent = 'Bottom 20 non-zero days (m3); today excluded';
            document.getElementById('title-month-best').textContent = 'Highest ' + label.toLowerCase() + ' months';
            document.getElementById('sub-month-best').textContent = 'Top 10 by volume (m3)';
            document.getElementById('title-month-worst').textContent = 'Lowest water months';
            document.getElementById('sub-month-worst').textContent = 'Bottom 10 non-zero months (m3)';
            document.getElementById('title-year-best').textContent = 'Highest ' + label.toLowerCase() + ' years';
            document.getElementById('sub-year-best').textContent = 'Top 10 by summed volume (m3)';
            document.getElementById('title-year-worst').textContent = 'Lowest water years';
            document.getElementById('sub-year-worst').textContent = 'Bottom 10 non-zero years (m3)';
            return;
        }
        if (metric === 'peak') {
            document.getElementById('topn-title').textContent = 'Capacity peak rankings';
            document.getElementById('topn-blurb').textContent = 'Highest 10 monthly Fluvius Capacity peaks (kW). Open month uses live P1 when available. Click a bar to open that year on Summary.';
            summary.href = 'summary.php?metric=peak';
            document.getElementById('title-month-best').textContent = 'Highest Capacity peak months';
            document.getElementById('sub-month-best').textContent = 'Top 10 by monthly peak (kW). Open month may show live P1.';
            return;
        }
        if (metric === 'grid') {
            document.getElementById('topn-title').textContent = 'Grid net rankings';
            document.getElementById('topn-blurb').textContent = 'Highest net import and net export - days (20), months (10), years (10). Today is excluded. Click a bar to open that period.';
            summary.href = 'summary.php?metric=grid' + barsPart;
            document.getElementById('title-day-best').textContent = 'Highest net import days';
            document.getElementById('sub-day-best').textContent = 'Top 20 by import - export. Bars: import, export, net (yellow). Today excluded.';
            document.getElementById('title-day-worst').textContent = 'Highest net export days';
            document.getElementById('sub-day-worst').textContent = 'Top 20 by export - import. Bars: import, export, net (yellow). Today excluded.';
            document.getElementById('title-month-best').textContent = 'Highest net import months';
            document.getElementById('sub-month-best').textContent = 'Top 10 by import - export. Bars: import, export, net (yellow).';
            document.getElementById('title-month-worst').textContent = 'Highest net export months';
            document.getElementById('sub-month-worst').textContent = 'Top 10 by export - import. Bars: import, export, net (yellow).';
            document.getElementById('title-year-best').textContent = 'Highest net import years';
            document.getElementById('sub-year-best').textContent = 'Top 10 by import - export. Bars: import, export, net (yellow).';
            document.getElementById('title-year-worst').textContent = 'Highest net export years';
            document.getElementById('sub-year-worst').textContent = 'Top 10 by export - import. Bars: import, export, net (yellow).';
            return;
        }
        document.getElementById('topn-title').textContent = 'Top Yield Rankings';
        document.getElementById('topn-blurb').textContent = 'Best and worst days (20), months (10), years (10) - click a bar to open that period';
        summary.href = 'summary.php' + (bars === 'all' ? '' : '?bars=' + encodeURIComponent(bars));
        document.getElementById('title-day-best').textContent = 'Best days';
        document.getElementById('sub-day-best').textContent = 'Top 20 by solar yield (kWh)';
        document.getElementById('title-day-worst').textContent = 'Worst days';
        document.getElementById('sub-day-worst').textContent = 'Bottom 20 by solar yield (kWh); no-production days and today excluded';
        document.getElementById('title-month-best').textContent = 'Best months';
        document.getElementById('sub-month-best').textContent = 'Top 10 by solar yield (kWh)';
        document.getElementById('title-month-worst').textContent = 'Worst months';
        document.getElementById('sub-month-worst').textContent = 'Bottom 10 by solar yield (kWh)';
        document.getElementById('title-year-best').textContent = 'Best years';
        document.getElementById('sub-year-best').textContent = 'Top 10 by solar yield (kWh)';
        document.getElementById('title-year-worst').textContent = 'Worst years';
        document.getElementById('sub-year-worst').textContent = 'Bottom 10 by solar yield (kWh)';
    }

    async function loadRankings() {
        const warn = document.getElementById('topn-warning');
        warn.classList.add('hidden');
        paintCopy();
        let data;
        let q = 'topn.php?ajax=lists';
        if (metric === 'grid') q = 'topn.php?ajax=lists&metric=grid';
        if (metric === 'peak') q = 'topn.php?ajax=lists&metric=peak';
        if (metric === 'gas' || metric === 'water') q = 'topn.php?ajax=lists&metric=' + metric;
        try {
            const res = await fetch(q);
            data = await res.json();
        } catch (e) {
            warn.textContent = 'Failed to load rankings.';
            warn.classList.remove('hidden');
            return;
        }
        if (data.error || !data.filled) {
            warn.textContent = data.error
                || (metric === 'grid'
                    ? 'No grid import / export on the rollups yet.'
                    : (metric === 'peak'
                        ? 'No Fluvius Capacity peak months yet. Import a piekvermogen CSV (electricity EAN on Settings).'
                        : ((metric === 'gas' || metric === 'water')
                            ? 'No ' + metric + ' volume history yet.'
                            : 'No ranking data yet. Run python3 rollup.py -buildall (or -daily).')));
            warn.classList.remove('hidden');
            if (metric === 'peak') {
                renderRankChart('chart-month-best', 'month', 'best', [], '#d97706', 'Capacity peak (kW)');
                return;
            }
            if (metric === 'gas' || metric === 'water') {
                const color = metric === 'gas' ? '#d97706' : '#06b6d4';
                const name = (metric === 'gas' ? 'Gas' : 'Water') + ' (m3)';
                renderRankChart('chart-day-best', 'day', 'best', [], color, name);
                renderRankChart('chart-month-best', 'month', 'best', [], color, name);
                renderRankChart('chart-year-best', 'year', 'best', [], color, name);
                if (metric === 'water') {
                    renderRankChart('chart-day-worst', 'day', 'worst', [], color, name);
                    renderRankChart('chart-month-worst', 'month', 'worst', [], color, name);
                    renderRankChart('chart-year-worst', 'year', 'worst', [], color, name);
                }
                return;
            }
            renderRankChart('chart-day-best', 'day', 'best', [], '#10b981', 'Yield (kWh)');
            renderRankChart('chart-day-worst', 'day', 'worst', [], '#f43f5e', 'Yield (kWh)');
            renderRankChart('chart-month-best', 'month', 'best', [], '#14b8a6', 'Yield (kWh)');
            renderRankChart('chart-month-worst', 'month', 'worst', [], '#f97316', 'Yield (kWh)');
            renderRankChart('chart-year-best', 'year', 'best', [], '#6366f1', 'Yield (kWh)');
            renderRankChart('chart-year-worst', 'year', 'worst', [], '#64748b', 'Yield (kWh)');
            return;
        }
        const L = data.lists;
        if (metric === 'peak') {
            renderRankChart('chart-month-best', 'month', 'best', L.month.best, '#d97706', 'Capacity peak (kW)');
            return;
        }
        if (metric === 'grid') {
            renderGridRankChart('chart-day-best', 'day', L.day.import);
            renderGridRankChart('chart-day-worst', 'day', L.day.export);
            renderGridRankChart('chart-month-best', 'month', L.month.import);
            renderGridRankChart('chart-month-worst', 'month', L.month.export);
            renderGridRankChart('chart-year-best', 'year', L.year.import);
            renderGridRankChart('chart-year-worst', 'year', L.year.export);
            return;
        }
        if (metric === 'gas' || metric === 'water') {
            const color = metric === 'gas' ? '#d97706' : '#06b6d4';
            const name = (metric === 'gas' ? 'Gas' : 'Water') + ' (m3)';
            renderRankChart('chart-day-best', 'day', 'best', L.day.best, color, name);
            renderRankChart('chart-month-best', 'month', 'best', L.month.best, color, name);
            renderRankChart('chart-year-best', 'year', 'best', L.year.best, color, name);
            if (metric === 'water') {
                renderRankChart('chart-day-worst', 'day', 'worst', L.day.worst, color, name);
                renderRankChart('chart-month-worst', 'month', 'worst', L.month.worst, color, name);
                renderRankChart('chart-year-worst', 'year', 'worst', L.year.worst, color, name);
            }
            return;
        }
        renderRankChart('chart-day-best', 'day', 'best', L.day.best, '#10b981', 'Yield (kWh)');
        renderRankChart('chart-day-worst', 'day', 'worst', L.day.worst, '#f43f5e', 'Yield (kWh)');
        renderRankChart('chart-month-best', 'month', 'best', L.month.best, '#14b8a6', 'Yield (kWh)');
        renderRankChart('chart-month-worst', 'month', 'worst', L.month.worst, '#f97316', 'Yield (kWh)');
        renderRankChart('chart-year-best', 'year', 'best', L.year.best, '#6366f1', 'Yield (kWh)');
        renderRankChart('chart-year-worst', 'year', 'worst', L.year.worst, '#64748b', 'Yield (kWh)');
    }

    function paintBars() {
        const wrap = document.getElementById('bars-wrap');
        wrap.classList.toggle('is-hidden', metric !== 'grid');
        ['all', 'registers', 'net'].forEach((id) => {
            const btn = document.getElementById('bars-' + id);
            const on = bars === id;
            btn.classList.toggle('metric-on', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    function writeViewUrl() {
        const url = new URL(window.location.href);
        if (metric !== 'solar') url.searchParams.set('metric', metric);
        else url.searchParams.delete('metric');
        if (bars === 'all') url.searchParams.delete('bars');
        else url.searchParams.set('bars', bars);
        history.replaceState(null, '', url);
    }

    function setMetric(next) {
        const chosen = ['grid', 'peak', 'gas', 'water'].includes(next) ? next : 'solar';
        if (chosen === metric) return;
        metric = chosen;
        writeViewUrl();
        loadRecords();
        loadRankings();
        loadBestDay();
    }

    function setBars(next) {
        const chosen = next === 'registers' || next === 'net' ? next : 'all';
        if (chosen === bars) return;
        bars = chosen;
        writeViewUrl();
        loadRankings();
    }

    document.addEventListener('DOMContentLoaded', () => {
        const params0 = new URLSearchParams(window.location.search);
        const m0 = params0.get('metric');
        metric = ['grid', 'peak', 'gas', 'water'].includes(m0) ? m0 : 'solar';
        const bars0 = params0.get('bars');
        bars = bars0 === 'registers' || bars0 === 'net' ? bars0 : 'all';
        loadRecords();
        loadRankings();
        loadBestDay();
        lucide.createIcons();
    });
</script>

</body>
</html>
