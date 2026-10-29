<?php
# --- file: summary.php ---
/**
 * Period summaries (week / month / year / all-years) from daily_history / monthly_history.
 * Prev/next arrows wrap within available data periods (not used on All years).
 * (Day mode removed: a single daily_history bar is not useful; use analytics intraday for one day.)
 * metric=grid: import, export, and net (yellow). metric=peak: Fluvius Capacity peak kW (A12).
 * Default metric is solar yield. Chart order: week, month, year, then All years last.
 */
date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/site_settings.php';
require_once __DIR__ . '/capacity_peak.php';

$hp = hp_boot();
hp_require_ready($hp);
$dbFile = $hp['db'];

/**
 * @param PDO $pdo
 * @return array{dates: string[], weeks: string[], months: string[], years: string[]}
 */
function summary_available_periods(PDO $pdo): array
{
    $dates = [];
    $weeks = [];
    $months = [];
    $years = [];
    try {
        $stmt = $pdo->query('SELECT date FROM daily_history ORDER BY date ASC');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $d = $row['date'];
            if (!$d) {
                continue;
            }
            $dates[] = $d;
            $ts = strtotime($d . ' 12:00:00');
            if ($ts === false) {
                continue;
            }
            $weeks[sprintf('%s-W%02d', date('o', $ts), (int)date('W', $ts))] = true;
            $months[substr($d, 0, 7)] = true;
            $years[substr($d, 0, 4)] = true;
        }
    } catch (Exception $e) {
        // table may be missing
    }
    $weekList = array_keys($weeks);
    $monthList = array_keys($months);
    $yearList = array_keys($years);
    sort($weekList);
    sort($monthList);
    sort($yearList);
    return [
        'dates' => $dates,
        'weeks' => $weekList,
        'months' => $monthList,
        'years' => $yearList,
    ];
}

/**
 * ISO week Monday..Sunday dates for label YYYY-Www (o-\WW).
 *
 * @return string[] list of YYYY-MM-DD
 */
function summary_iso_week_dates(string $weekLabel): array
{
    // Expect YYYY-Www e.g. 2020-W32
    if (!preg_match('/^(\d{4})-W(\d{2})$/', $weekLabel, $m)) {
        return [];
    }
    $year = (int)$m[1];
    $week = (int)$m[2];
    $dto = new DateTime();
    $dto->setISODate($year, $week, 1);
    $out = [];
    for ($i = 0; $i < 7; $i++) {
        $out[] = $dto->format('Y-m-d');
        $dto->modify('+1 day');
    }
    return $out;
}

/**
 * solar, grid, or peak (Capacity peak). Any other value stays on solar yield.
 */
function summary_metric(): string
{
    $metric = isset($_GET['metric']) ? (string)$_GET['metric'] : 'solar';
    if ($metric === 'grid' || $metric === 'peak') {
        return $metric;
    }
    return 'solar';
}

/**
 * All-years peak aggregation: max (default) or avg.
 */
function summary_peak_agg(): string
{
    $agg = isset($_GET['peak_agg']) ? (string)$_GET['peak_agg'] : 'max';
    return $agg === 'avg' ? 'avg' : 'max';
}

/**
 * Round one kWh register. Null stays null so a line chart can leave a gap.
 *
 * @param mixed $value
 */
function summary_kwh($value, int $digits): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    return round((float)$value, $digits);
}

/**
 * Add a register into a running total. A null addend leaves the total unchanged.
 */
function summary_add(?float $sum, ?float $add): ?float
{
    if ($add === null) {
        return $sum;
    }
    if ($sum === null) {
        return $add;
    }
    return $sum + $add;
}

/**
 * Net import for a period. Unknown on either side stays unknown.
 */
function summary_net(?float $importKwh, ?float $exportKwh): ?float
{
    if ($importKwh === null || $exportKwh === null) {
        return null;
    }
    return round($importKwh - $exportKwh, 3);
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
        $action = isset($_GET['ajax']) ? (string)$_GET['ajax'] : '';

        if ($action === 'meta') {
            $periods = summary_available_periods($pdo);
            $peakMonths = capacity_peak_months_display($pdo);
            $periods['peak_years'] = capacity_peak_years($peakMonths);
            $periods['peak_month_count'] = count($peakMonths);
            echo json_encode($periods);
            exit;
        }

        // --- Week: daily bars Mon-Sun ---
        if ($action === 'week' && isset($_GET['week'])) {
            $week = (string)$_GET['week'];
            $days = summary_iso_week_dates($week);
            if (summary_metric() === 'grid') {
                // Seven slots, including days with no row. A missing register is a gap.
                $categories = [];
                $import = [];
                $export = [];
                $importSum = null;
                $exportSum = null;
                $stmt = $pdo->prepare(
                    'SELECT grid_import_kwh, grid_export_kwh FROM daily_history WHERE date = ?'
                );
                foreach ($days as $d) {
                    $stmt->execute([$d]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $categories[] = $d;
                    $imp = $row ? summary_kwh($row['grid_import_kwh'], 3) : null;
                    $exp = $row ? summary_kwh($row['grid_export_kwh'], 3) : null;
                    $import[] = $imp;
                    $export[] = $exp;
                    $importSum = summary_add($importSum, $imp);
                    $exportSum = summary_add($exportSum, $exp);
                }
                echo json_encode([
                    'period' => $week,
                    'label' => $week . ' (Mon-Sun)',
                    'categories' => $categories,
                    'import' => $import,
                    'export' => $export,
                    'import_kwh' => $importSum === null ? null : round($importSum, 3),
                    'export_kwh' => $exportSum === null ? null : round($exportSum, 3),
                    'net_kwh' => summary_net($importSum, $exportSum),
                ]);
                exit;
            }
            $categories = [];
            $yield = [];
            $peak = [];
            $total = 0.0;
            $stmt = $pdo->prepare('SELECT date, solar_yield_kwh, solar_peak_w FROM daily_history WHERE date = ?');
            foreach ($days as $d) {
                $stmt->execute([$d]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $categories[] = $d;
                $y = $row ? (float)$row['solar_yield_kwh'] : 0.0;
                $yield[] = $y;
                $peak[] = $row && $row['solar_peak_w'] !== null ? (float)$row['solar_peak_w'] : null;
                $total += $y;
            }
            echo json_encode([
                'period' => $week,
                'label' => $week . ' (Mon-Sun)',
                'categories' => $categories,
                'yield' => $yield,
                'peak' => $peak,
                'total_kwh' => round($total, 2),
            ]);
            exit;
        }

        // --- Month: daily bars in calendar month ---
        if ($action === 'month' && isset($_GET['month'])) {
            $month = (string)$_GET['month']; // YYYY-MM
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                echo json_encode(['error' => 'Invalid month']);
                exit;
            }
            if (summary_metric() === 'grid') {
                $categories = [];
                $import = [];
                $export = [];
                $importSum = null;
                $exportSum = null;
                $gridStmt = $pdo->prepare(
                    "SELECT date, grid_import_kwh, grid_export_kwh FROM daily_history
                     WHERE date LIKE ? ORDER BY date ASC"
                );
                $gridStmt->execute([$month . '%']);
                while ($row = $gridStmt->fetch(PDO::FETCH_ASSOC)) {
                    $categories[] = $row['date'];
                    $imp = summary_kwh($row['grid_import_kwh'], 3);
                    $exp = summary_kwh($row['grid_export_kwh'], 3);
                    $import[] = $imp;
                    $export[] = $exp;
                    $importSum = summary_add($importSum, $imp);
                    $exportSum = summary_add($exportSum, $exp);
                }
                echo json_encode([
                    'period' => $month,
                    'label' => $month,
                    'categories' => $categories,
                    'import' => $import,
                    'export' => $export,
                    'import_kwh' => $importSum === null ? null : round($importSum, 3),
                    'export_kwh' => $exportSum === null ? null : round($exportSum, 3),
                    'net_kwh' => summary_net($importSum, $exportSum),
                ]);
                exit;
            }
            $stmt = $pdo->prepare(
                "SELECT date, solar_yield_kwh, solar_peak_w FROM daily_history
                 WHERE date LIKE ? ORDER BY date ASC"
            );
            $stmt->execute([$month . '%']);
            $categories = [];
            $yield = [];
            $peak = [];
            $total = 0.0;
            $maxPeak = null;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $categories[] = $row['date'];
                $y = (float)$row['solar_yield_kwh'];
                $yield[] = $y;
                $total += $y;
                $p = $row['solar_peak_w'] !== null ? (float)$row['solar_peak_w'] : null;
                $peak[] = $p;
                if ($p !== null && ($maxPeak === null || $p > $maxPeak)) {
                    $maxPeak = $p;
                }
            }
            $expected = null;
            try {
                $mNum = (int)substr($month, 5, 2);
                $exp = $pdo->prepare(
                    'SELECT expected_kwh FROM expected_monthly WHERE month = ? AND inclination = 30'
                );
                $exp->execute([$mNum]);
                $er = $exp->fetch(PDO::FETCH_ASSOC);
                if ($er) {
                    $expected = (float)$er['expected_kwh'];
                }
            } catch (Exception $e) {
            }
            echo json_encode([
                'period' => $month,
                'label' => $month,
                'categories' => $categories,
                'yield' => $yield,
                'peak' => $peak,
                'total_kwh' => round($total, 2),
                'peak_max_w' => $maxPeak,
                'expected_kwh' => $expected,
            ]);
            exit;
        }

        // --- All years: one bar per calendar year + flat PVGIS year baseline ---
        if ($action === 'all') {
            if (summary_metric() === 'peak') {
                $months = capacity_peak_months_display($pdo);
                $agg = summary_peak_agg();
                $payload = capacity_peak_all_years_payload($months, $agg);
                // Y-axis always follows Max-mode bars so Average and Max share one scale.
                $maxPayload = capacity_peak_all_years_payload($months, 'max');
                $yMax = null;
                foreach ($maxPayload['peaks'] as $pk) {
                    if ($pk === null) {
                        continue;
                    }
                    if ($yMax === null || $pk > $yMax) {
                        $yMax = $pk;
                    }
                }
                $live = capacity_peak_live_kw($pdo);
                echo json_encode([
                    'period' => 'all',
                    'label' => 'All years',
                    'metric' => 'peak',
                    'peak_agg' => $agg,
                    'categories' => $payload['categories'],
                    'peaks' => $payload['peaks'],
                    'years' => $payload['years'],
                    'y_max_kw' => $yMax,
                    'open_month' => date('Y-m'),
                    'live_peak_kw' => $live,
                    'unit' => 'kW',
                ]);
                exit;
            }
            if (summary_metric() === 'grid') {
                $categories = [];
                $import = [];
                $export = [];
                $importSum = null;
                $exportSum = null;
                $rows = [];
                try {
                    $rows = $pdo->query(
                        "SELECT substr(year_month, 1, 4) AS y,
                                SUM(total_import_kwh) AS imp,
                                SUM(total_export_kwh) AS exp
                         FROM monthly_history
                         GROUP BY y
                         ORDER BY y ASC"
                    )->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $rows = [];
                }
                if (count($rows) === 0) {
                    try {
                        $rows = $pdo->query(
                            "SELECT substr(date, 1, 4) AS y,
                                    SUM(grid_import_kwh) AS imp,
                                    SUM(grid_export_kwh) AS exp
                             FROM daily_history
                             GROUP BY y
                             ORDER BY y ASC"
                        )->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        $rows = [];
                    }
                }
                foreach ($rows as $row) {
                    if (!$row['y']) {
                        continue;
                    }
                    $imp = summary_kwh($row['imp'], 3);
                    $exp = summary_kwh($row['exp'], 3);
                    if ($imp === null && $exp === null) {
                        continue;
                    }
                    $categories[] = (string)$row['y'];
                    $import[] = $imp;
                    $export[] = $exp;
                    $importSum = summary_add($importSum, $imp);
                    $exportSum = summary_add($exportSum, $exp);
                }
                echo json_encode([
                    'period' => 'all',
                    'label' => 'All years',
                    'categories' => $categories,
                    'import' => $import,
                    'export' => $export,
                    'import_kwh' => $importSum === null ? null : round($importSum, 3),
                    'export_kwh' => $exportSum === null ? null : round($exportSum, 3),
                    'net_kwh' => summary_net($importSum, $exportSum),
                    'years' => count($categories),
                ]);
                exit;
            }
            $pvgisYear = 0.0;
            try {
                $pvgisYear = (float)$pdo->query(
                    'SELECT COALESCE(SUM(expected_kwh), 0) FROM expected_monthly WHERE inclination = 30'
                )->fetchColumn();
            } catch (Exception $e) {
            }

            $byYear = [];
            try {
                $stmt = $pdo->query(
                    "SELECT substr(year_month, 1, 4) AS y, SUM(total_solar_kwh) AS tot
                     FROM monthly_history
                     GROUP BY y
                     ORDER BY y ASC"
                );
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($row['y']) {
                        $byYear[$row['y']] = round((float)$row['tot'], 1);
                    }
                }
            } catch (Exception $e) {
            }
            if (count($byYear) === 0) {
                $stmt = $pdo->query(
                    "SELECT substr(date, 1, 4) AS y, SUM(solar_yield_kwh) AS tot
                     FROM daily_history
                     GROUP BY y
                     ORDER BY y ASC"
                );
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($row['y']) {
                        $byYear[$row['y']] = round((float)$row['tot'], 1);
                    }
                }
            }

            $categories = array_keys($byYear);
            $actuals = array_values($byYear);
            $expected = [];
            foreach ($categories as $_y) {
                $expected[] = round($pvgisYear, 1);
            }
            $lifetime = array_sum($actuals);
            echo json_encode([
                'period' => 'all',
                'label' => 'All years',
                'categories' => $categories,
                'actuals' => $actuals,
                'expected' => $expected,
                'total_kwh' => round($lifetime, 1),
                'expected_kwh_year' => round($pvgisYear, 1),
                'years' => count($categories),
            ]);
            exit;
        }

        // --- Year: monthly bars + PVGIS expected ---
        if ($action === 'year' && isset($_GET['year'])) {
            $year = (string)$_GET['year'];
            if (!preg_match('/^\d{4}$/', $year)) {
                echo json_encode(['error' => 'Invalid year']);
                exit;
            }
            if (summary_metric() === 'peak') {
                $months = capacity_peak_months_display($pdo);
                $payload = capacity_peak_year_payload($months, $year);
                $live = capacity_peak_live_kw($pdo);
                echo json_encode([
                    'period' => $year,
                    'label' => $year,
                    'metric' => 'peak',
                    'categories' => $payload['categories'],
                    'peaks' => $payload['peaks'],
                    'max_kw' => $payload['max_kw'],
                    'avg_kw' => $payload['avg_kw'],
                    'months_with_data' => $payload['count'],
                    'open_month' => date('Y-m'),
                    'live_peak_kw' => $live,
                    'unit' => 'kW',
                ]);
                exit;
            }
            if (summary_metric() === 'grid') {
                $categories = [];
                $import = [];
                $export = [];
                $importSum = null;
                $exportSum = null;
                $fromMonthly = false;
                try {
                    $stmt = $pdo->prepare(
                        "SELECT year_month, total_import_kwh, total_export_kwh
                         FROM monthly_history
                         WHERE year_month LIKE ?
                         ORDER BY year_month ASC"
                    );
                    $stmt->execute([$year . '%']);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (count($rows) > 0) {
                        $fromMonthly = true;
                        foreach ($rows as $row) {
                            $categories[] = $row['year_month'];
                            $imp = summary_kwh($row['total_import_kwh'], 3);
                            $exp = summary_kwh($row['total_export_kwh'], 3);
                            $import[] = $imp;
                            $export[] = $exp;
                            $importSum = summary_add($importSum, $imp);
                            $exportSum = summary_add($exportSum, $exp);
                        }
                    }
                } catch (Exception $e) {
                }
                if (!$fromMonthly) {
                    $stmt = $pdo->prepare(
                        "SELECT substr(date, 1, 7) AS ym,
                                SUM(grid_import_kwh) AS imp,
                                SUM(grid_export_kwh) AS exp
                         FROM daily_history
                         WHERE date LIKE ?
                         GROUP BY ym
                         ORDER BY ym ASC"
                    );
                    $stmt->execute([$year . '%']);
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $categories[] = $row['ym'];
                        $imp = summary_kwh($row['imp'], 3);
                        $exp = summary_kwh($row['exp'], 3);
                        $import[] = $imp;
                        $export[] = $exp;
                        $importSum = summary_add($importSum, $imp);
                        $exportSum = summary_add($exportSum, $exp);
                    }
                }
                echo json_encode([
                    'period' => $year,
                    'label' => $year,
                    'categories' => $categories,
                    'import' => $import,
                    'export' => $export,
                    'import_kwh' => $importSum === null ? null : round($importSum, 3),
                    'export_kwh' => $exportSum === null ? null : round($exportSum, 3),
                    'net_kwh' => summary_net($importSum, $exportSum),
                ]);
                exit;
            }
            $categories = [];
            $actuals = [];
            $expected = [];
            $expByMonth = [];
            try {
                $expStmt = $pdo->query(
                    'SELECT month, expected_kwh FROM expected_monthly WHERE inclination = 30'
                );
                while ($er = $expStmt->fetch(PDO::FETCH_ASSOC)) {
                    $expByMonth[(int)$er['month']] = (float)$er['expected_kwh'];
                }
            } catch (Exception $e) {
            }

            // Prefer monthly_history when present; else roll up daily_history
            $fromMonthly = false;
            try {
                $stmt = $pdo->prepare(
                    "SELECT year_month, total_solar_kwh, expected_kwh
                     FROM monthly_history
                     WHERE year_month LIKE ?
                     ORDER BY year_month ASC"
                );
                $stmt->execute([$year . '%']);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > 0) {
                    $fromMonthly = true;
                    foreach ($rows as $row) {
                        $ym = $row['year_month'];
                        $categories[] = $ym;
                        $actuals[] = round((float)$row['total_solar_kwh'], 1);
                        // Chart expected follows Settings expected_monthly (live),
                        // not the snapshot baked into monthly_history at -buildall.
                        $mNum = (int)substr($ym, 5, 2);
                        $expected[] = isset($expByMonth[$mNum])
                            ? round($expByMonth[$mNum], 1)
                            : 0.0;
                    }
                }
            } catch (Exception $e) {
            }

            if (!$fromMonthly) {
                for ($m = 1; $m <= 12; $m++) {
                    $ym = sprintf('%s-%02d', $year, $m);
                    $categories[] = $ym;
                    $stmt = $pdo->prepare(
                        "SELECT COALESCE(SUM(solar_yield_kwh), 0) AS total
                         FROM daily_history WHERE date LIKE ?"
                    );
                    $stmt->execute([$ym . '%']);
                    $t = (float)$stmt->fetchColumn();
                    $actuals[] = round($t, 1);
                    $expected[] = isset($expByMonth[$m]) ? round($expByMonth[$m], 1) : 0.0;
                }
            }

            $yearTotal = array_sum($actuals);
            $yearExpected = array_sum($expected);
            echo json_encode([
                'period' => $year,
                'label' => $year,
                'categories' => $categories,
                'actuals' => $actuals,
                'expected' => $expected,
                'total_kwh' => round($yearTotal, 1),
                'expected_kwh' => round($yearExpected, 1),
            ]);
            exit;
        }

        echo json_encode(['error' => 'Unknown action']);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Energy Summary | Energy Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        body { background-color: #f8fafc; font-family: system-ui, -apple-system, sans-serif; }
        .card { @apply bg-white p-6 rounded-3xl shadow-sm border border-slate-100 flex flex-col; }
        .nav-arrow {
            @apply w-10 h-10 rounded-xl bg-white border border-slate-200 text-slate-700
                   flex items-center justify-center hover:bg-slate-50 shadow-sm transition-colors cursor-pointer;
        }
        .jump-btn {
            @apply text-xs font-semibold px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white
                   text-slate-600 hover:bg-slate-50 hover:text-emerald-700 transition-colors cursor-pointer;
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
                <h1 class="text-2xl font-black text-slate-900 tracking-tight">Energy Summary</h1>
                <p id="summary-blurb" class="text-xs text-slate-400 mt-0.5">All years, week, month, and year from daily rollups</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="activity" class="w-3.5 h-3.5 text-emerald-600"></i> Live Dashboard
            </a>
            <a href="analytics.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="line-chart" class="w-3.5 h-3.5 text-indigo-600"></i> Analytics
            </a>
            <a id="nav-topn" href="topn.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="trophy" class="w-3.5 h-3.5 text-amber-500"></i> Top-N
            </a>
            <?= hp_nav_settings() ?>
        </div>
    </header>

    <div class="flex flex-wrap items-center gap-3">
        <div class="metric-switch" role="group" aria-label="Summary metric">
            <button type="button" id="metric-solar" class="metric-on" aria-pressed="true" onclick="setMetric('solar')">Solar yield</button>
            <button type="button" id="metric-grid" aria-pressed="false" onclick="setMetric('grid')">Grid import / export</button>
            <button type="button" id="metric-peak" aria-pressed="false" onclick="setMetric('peak')">Capacity peak</button>
        </div>
        <div id="bars-wrap" class="metric-switch is-hidden" role="group" aria-label="Grid bars">
            <button type="button" id="bars-all" class="metric-on" aria-pressed="true" onclick="setBars('all')">All</button>
            <button type="button" id="bars-registers" aria-pressed="false" onclick="setBars('registers')">Import / export</button>
            <button type="button" id="bars-net" aria-pressed="false" onclick="setBars('net')">Net</button>
        </div>
    </div>

    <div id="summary-warning" class="hidden text-xs text-amber-600 bg-amber-50 border border-amber-200 p-3 rounded-xl">
        No daily rollups yet. Run <code>python3 rollup.py -buildall</code> (or <code>-daily</code>) first.
    </div>

    <!-- Week (hidden in Capacity peak mode) -->
    <div id="card-week" class="card border-t-4 border-t-emerald-500">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
            <h2 class="text-lg font-bold text-slate-900">Week</h2>
            <div class="flex items-center gap-3">
                <button type="button" class="jump-btn" onclick="goToCurrent('week')" title="Jump to this ISO week">This week</button>
                <button type="button" class="nav-arrow" onclick="stepSection('week', -1)" aria-label="Previous week">
                    <i data-lucide="chevron-left" class="w-5 h-5"></i>
                </button>
                <div class="min-w-[9rem] text-center">
                    <div id="week-label" class="text-sm font-bold text-slate-900 font-mono">-</div>
                </div>
                <button type="button" class="nav-arrow" onclick="stepSection('week', 1)" aria-label="Next week">
                    <i data-lucide="chevron-right" class="w-5 h-5"></i>
                </button>
            </div>
        </div>
        <div id="week-stats" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3 text-sm"></div>
        <div id="chart-week" class="w-full h-[320px]"></div>
    </div>

    <!-- Month (hidden in Capacity peak mode) -->
    <div id="card-month" class="card border-t-4 border-t-teal-500">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
            <h2 class="text-lg font-bold text-slate-900">Month</h2>
            <div class="flex items-center gap-3">
                <button type="button" class="jump-btn" onclick="goToCurrent('month')" title="Jump to this calendar month">This month</button>
                <button type="button" class="nav-arrow" onclick="stepSection('month', -1)" aria-label="Previous month">
                    <i data-lucide="chevron-left" class="w-5 h-5"></i>
                </button>
                <div class="min-w-[9rem] text-center">
                    <div id="month-label" class="text-sm font-bold text-slate-900 font-mono">-</div>
                </div>
                <button type="button" class="nav-arrow" onclick="stepSection('month', 1)" aria-label="Next month">
                    <i data-lucide="chevron-right" class="w-5 h-5"></i>
                </button>
            </div>
        </div>
        <div id="month-stats" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3 text-sm"></div>
        <div id="chart-month" class="w-full h-[320px]"></div>
    </div>

    <!-- Year -->
    <div id="card-year" class="card border-t-4 border-t-cyan-500">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
            <h2 class="text-lg font-bold text-slate-900">Year</h2>
            <div class="flex items-center gap-3">
                <button type="button" class="jump-btn" onclick="goToCurrent('year')" title="Jump to this calendar year">This year</button>
                <button type="button" class="nav-arrow" onclick="stepSection('year', -1)" aria-label="Previous year">
                    <i data-lucide="chevron-left" class="w-5 h-5"></i>
                </button>
                <div class="min-w-[9rem] text-center">
                    <div id="year-label" class="text-sm font-bold text-slate-900 font-mono">-</div>
                </div>
                <button type="button" class="nav-arrow" onclick="stepSection('year', 1)" aria-label="Next year">
                    <i data-lucide="chevron-right" class="w-5 h-5"></i>
                </button>
            </div>
        </div>
        <div id="year-stats" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3 text-sm"></div>
        <div id="chart-year" class="w-full h-[320px]"></div>
    </div>

    <!-- All years last (every metric mode). Max/Average switch sits here in Capacity peak mode. -->
    <div id="card-all" class="card border-t-4 border-t-indigo-500">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
            <div>
                <h2 class="text-lg font-bold text-slate-900">All years</h2>
                <p id="all-blurb" class="text-xs text-slate-400">Lifetime yield per calendar year vs PVGIS annual baseline</p>
            </div>
            <div id="peak-agg-wrap" class="metric-switch is-hidden" role="group" aria-label="Capacity peak year aggregation">
                <button type="button" id="peak-agg-max" class="metric-on" aria-pressed="true" onclick="setPeakAgg('max')">Max</button>
                <button type="button" id="peak-agg-avg" aria-pressed="false" onclick="setPeakAgg('avg')">Average</button>
            </div>
        </div>
        <div id="all-stats" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3 text-sm"></div>
        <div id="chart-all" class="w-full h-[320px]"></div>
    </div>

</div>

<script>
    lucide.createIcons();

    /** @type {{weeks: string[], months: string[], years: string[], peak_years?: string[], peak_month_count?: number}} */
    let periods = { weeks: [], months: [], years: [], peak_years: [], peak_month_count: 0 };
    /** @type {{week: string|null, month: string|null, year: string|null}} */
    let current = { week: null, month: null, year: null };
    /** @type {{all: any, week: any, month: any, year: any}} */
    let charts = { all: null, week: null, month: null, year: null };
    /** @type {'solar'|'grid'|'peak'} */
    let metric = 'solar';
    /** @type {'all'|'registers'|'net'} */
    let bars = 'all';
    /** @type {'max'|'avg'} */
    let peakAgg = 'max';
    let loadGen = 0;

    const baseChartOptions = {
        chart: { toolbar: { show: true }, zoom: { enabled: false }, animations: { enabled: false } },
        dataLabels: { enabled: false },
        grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
        xaxis: { labels: { style: { colors: '#94a3b8', fontSize: '10px' } } },
        yaxis: { labels: { style: { colors: '#94a3b8', fontSize: '10px' } } }
    };

    function keysFor(mode) {
        if (mode === 'week') return (periods.weeks || []).map(String);
        if (mode === 'month') return (periods.months || []).map(String);
        if (mode === 'year' && metric === 'peak') {
            return (Array.isArray(periods.peak_years) ? periods.peak_years : []).map(String);
        }
        return (periods.years || []).map(String);
    }

    /**
     * Ensure a deep-linked year is selectable in the year nav (peak or solar/grid lists).
     *
     * @param {string} year
     */
    function ensureYearKey(year) {
        const y = String(year);
        if (!/^\d{4}$/.test(y)) return;
        if (metric === 'peak') {
            const list = Array.isArray(periods.peak_years) ? periods.peak_years.map(String) : [];
            if (!list.includes(y)) {
                list.push(y);
                list.sort();
                periods.peak_years = list;
            }
            return;
        }
        const list = Array.isArray(periods.years) ? periods.years.map(String) : [];
        if (!list.includes(y)) {
            list.push(y);
            list.sort();
            periods.years = list;
        }
    }

    /**
     * @param {string} ym YYYY-MM
     */
    function ensureMonthKey(ym) {
        const key = String(ym);
        if (!/^\d{4}-\d{2}$/.test(key)) return;
        const list = Array.isArray(periods.months) ? periods.months.map(String) : [];
        if (!list.includes(key)) {
            list.push(key);
            list.sort();
            periods.months = list;
        }
    }

    /**
     * Monday (UTC) of ISO week YYYY-Www.
     *
     * @param {number} isoYear
     * @param {number} isoWeek
     * @returns {Date}
     */
    function isoWeekStartDate(isoYear, isoWeek) {
        const d = new Date(Date.UTC(isoYear, 0, 4));
        const dayNum = d.getUTCDay() || 7;
        d.setUTCDate(d.getUTCDate() - dayNum + 1 + (isoWeek - 1) * 7);
        return d;
    }

    /**
     * @param {string} weekLabel
     * @param {string} ym YYYY-MM
     * @returns {boolean}
     */
    function weekOverlapsMonth(weekLabel, ym) {
        const m = /^(\d{4})-W(\d{2})$/.exec(String(weekLabel));
        if (!m) return false;
        const start = isoWeekStartDate(Number(m[1]), Number(m[2]));
        for (let i = 0; i < 7; i++) {
            const day = new Date(start.getTime() + i * 86400000);
            const key = day.getUTCFullYear() + '-' + String(day.getUTCMonth() + 1).padStart(2, '0');
            if (key === ym) return true;
        }
        return false;
    }

    /**
     * First ISO week of a calendar month: week containing day 1, else earliest
     * available week that overlaps the month, else nearest week at or before day 1.
     *
     * @param {string} ym YYYY-MM
     * @returns {string|null}
     */
    function firstWeekOfMonth(ym) {
        const weeks = keysFor('week');
        if (!weeks.length || !/^\d{4}-\d{2}$/.test(ym)) return null;
        const y = Number(ym.slice(0, 4));
        const mo = Number(ym.slice(5, 7));
        const want = isoWeekKey(new Date(Date.UTC(y, mo - 1, 1)));
        if (weeks.includes(want)) return want;
        const overlapping = weeks.filter((w) => weekOverlapsMonth(w, ym));
        if (overlapping.length) return overlapping[0];
        let pick = weeks[0];
        for (let i = 0; i < weeks.length; i++) {
            if (weeks[i] <= want) pick = weeks[i];
            else break;
        }
        return pick;
    }

    /**
     * Deep link ?month=YYYY-MM drives month, year, and first week of that month.
     *
     * @param {string} ym
     */
    function applyMonthDeepLink(ym) {
        const key = String(ym);
        if (!/^\d{4}-\d{2}$/.test(key)) return;
        ensureMonthKey(key);
        current.month = key;
        const year = key.slice(0, 4);
        ensureYearKey(year);
        current.year = year;
        const week = firstWeekOfMonth(key);
        if (week) current.week = week;
    }

    function stepSection(mode, delta) {
        const keys = keysFor(mode);
        if (!keys.length) return;
        let idx = keys.indexOf(current[mode]);
        if (idx < 0) idx = keys.length - 1;
        idx = (idx + delta + keys.length) % keys.length;
        current[mode] = keys[idx];
        loadSection(mode);
    }

    /** ISO week key YYYY-Www matching summary_iso_week_dates / PHP o-W. */
    function isoWeekKey(d) {
        const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
        const dayNum = date.getUTCDay() || 7;
        date.setUTCDate(date.getUTCDate() + 4 - dayNum);
        const yearStart = new Date(Date.UTC(date.getUTCFullYear(), 0, 1));
        const weekNo = Math.ceil((((date - yearStart) / 86400000) + 1) / 7);
        return date.getUTCFullYear() + '-W' + String(weekNo).padStart(2, '0');
    }

    /** Jump to current calendar period; if missing from data, nearest available <= want. */
    function goToCurrent(mode) {
        const now = new Date();
        let want;
        if (mode === 'week') {
            want = isoWeekKey(now);
        } else if (mode === 'month') {
            want = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
        } else {
            want = String(now.getFullYear());
        }
        const keys = keysFor(mode);
        if (!keys.length) return;
        if (keys.includes(want)) {
            current[mode] = want;
        } else {
            let pick = keys[0];
            for (let i = 0; i < keys.length; i++) {
                if (keys[i] <= want) pick = keys[i];
                else break;
            }
            current[mode] = pick;
        }
        loadSection(mode);
    }

    function renderStats(elId, items) {
        document.getElementById(elId).innerHTML = items.map((it) => `
            <div class="rounded-2xl border border-slate-100 bg-slate-50/80 px-3 py-2">
                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">${it.label}</div>
                <div class="text-base font-black text-slate-900 font-mono mt-0.5">${it.value}</div>
            </div>
        `).join('');
    }

    function setBarChart(mode, categories, seriesData, name) {
        const host = document.querySelector('#chart-' + mode);
        if (charts[mode]) { charts[mode].destroy(); charts[mode] = null; }
        charts[mode] = new ApexCharts(host, {
            ...baseChartOptions,
            series: [{ name: name, data: seriesData }],
            chart: { ...baseChartOptions.chart, type: 'bar', height: 320 },
            colors: ['#10b981'],
            xaxis: { ...baseChartOptions.xaxis, categories: categories },
            tooltip: { y: { formatter: (v) => `${Number(v).toFixed(2)} kWh` } }
        });
        charts[mode].render();
    }

    function setYearStyleChart(chartKey, hostSel, data, expectedName) {
        const host = document.querySelector(hostSel);
        if (charts[chartKey]) { charts[chartKey].destroy(); charts[chartKey] = null; }
        // Settings all-zero (or empty): omit PVGIS line entirely — do not draw a flat 0.
        const expected = Array.isArray(data.expected) ? data.expected : [];
        const hasExpected = expected.some((v) => Number(v) > 0);
        const series = [{ name: 'Actual (kWh)', type: 'column', data: data.actuals }];
        if (hasExpected) {
            series.push({ name: expectedName, type: 'line', data: expected });
        }
        charts[chartKey] = new ApexCharts(host, {
            ...baseChartOptions,
            series: series,
            chart: { type: 'line', height: 320, toolbar: { show: false }, animations: { enabled: false } },
            stroke: hasExpected
                ? { width: [0, 3], curve: 'straight', dashArray: [0, 8] }
                : { width: [0], curve: 'straight' },
            colors: hasExpected ? ['#10b981', '#cbd5e1'] : ['#10b981'],
            xaxis: {
                categories: data.categories,
                labels: { style: { colors: '#64748b', fontSize: '11px', fontWeight: 'bold' } }
            },
            yaxis: { title: { text: 'Yield (kWh)', style: { color: '#94a3b8', fontSize: '10px' } } },
            tooltip: {
                shared: true,
                intersect: false,
                y: { formatter: (val) => `${Number(val).toLocaleString()} kWh` }
            }
        });
        charts[chartKey].render();
    }

    function setYearChart(data) {
        setYearStyleChart('year', '#chart-year', data, 'PVGIS expected');
    }

    /**
     * @param {number|null|undefined} value
     * @returns {string}
     */
    function fmtKwh(value) {
        if (value == null || Number.isNaN(Number(value))) return '-';
        return `${Number(value).toLocaleString(undefined, { maximumFractionDigits: 3 })} kWh`;
    }

    /**
     * @param {number|null|undefined} value
     * @returns {string}
     */
    function fmtKw(value) {
        if (value == null || Number.isNaN(Number(value))) return '-';
        return `${Number(value).toLocaleString(undefined, { maximumFractionDigits: 3 })} kW`;
    }

    function metricQuery() {
        if (metric === 'grid') return '&metric=grid';
        if (metric === 'peak') {
            const agg = peakAgg === 'avg' ? '&peak_agg=avg' : '';
            return '&metric=peak' + agg;
        }
        return '';
    }

    function paintMetricSwitch() {
        const solarBtn = document.getElementById('metric-solar');
        const gridBtn = document.getElementById('metric-grid');
        const peakBtn = document.getElementById('metric-peak');
        solarBtn.classList.toggle('metric-on', metric === 'solar');
        gridBtn.classList.toggle('metric-on', metric === 'grid');
        peakBtn.classList.toggle('metric-on', metric === 'peak');
        solarBtn.setAttribute('aria-pressed', metric === 'solar' ? 'true' : 'false');
        gridBtn.setAttribute('aria-pressed', metric === 'grid' ? 'true' : 'false');
        peakBtn.setAttribute('aria-pressed', metric === 'peak' ? 'true' : 'false');
        document.getElementById('card-week').classList.toggle('is-hidden', metric === 'peak');
        document.getElementById('card-month').classList.toggle('is-hidden', metric === 'peak');
        const blurb = document.getElementById('summary-blurb');
        const allBlurb = document.getElementById('all-blurb');
        const topn = document.getElementById('nav-topn');
        if (metric === 'grid') {
            blurb.textContent = 'Import, export, and net (yellow) bars for week, month, year, and all years';
            allBlurb.textContent = 'Import, export, and net (yellow) per calendar year';
            topn.href = 'topn.php?metric=grid' + (bars === 'all' ? '' : '&bars=' + encodeURIComponent(bars));
        } else if (metric === 'peak') {
            blurb.textContent = 'Fluvius monthly Capacity peak (kW). Open month uses live P1. Year and all years only.';
            allBlurb.textContent = peakAgg === 'avg'
                ? 'Average monthly Capacity peak (kW) per calendar year'
                : 'Max monthly Capacity peak (kW) per calendar year';
            topn.href = 'topn.php?metric=peak';
        } else {
            blurb.textContent = 'Week, month, year, and all years from daily rollups';
            allBlurb.textContent = 'Lifetime yield per calendar year vs PVGIS annual baseline';
            topn.href = 'topn.php' + (bars === 'all' ? '' : '?bars=' + encodeURIComponent(bars));
        }
        paintBars();
        paintPeakAgg();
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

    function paintPeakAgg() {
        const wrap = document.getElementById('peak-agg-wrap');
        wrap.classList.toggle('is-hidden', metric !== 'peak');
        ['max', 'avg'].forEach((id) => {
            const btn = document.getElementById('peak-agg-' + id);
            const on = peakAgg === id;
            btn.classList.toggle('metric-on', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    function writeViewUrl() {
        const url = new URL(window.location.href);
        if (metric === 'grid' || metric === 'peak') url.searchParams.set('metric', metric);
        else url.searchParams.delete('metric');
        if (bars === 'all') url.searchParams.delete('bars');
        else url.searchParams.set('bars', bars);
        if (metric === 'peak' && peakAgg === 'avg') url.searchParams.set('peak_agg', 'avg');
        else url.searchParams.delete('peak_agg');
        history.replaceState(null, '', url);
    }

    /**
     * Net import for one category. Unknown on either register stays a gap.
     *
     * @param {Array<number|null>} importData
     * @param {Array<number|null>} exportData
     * @returns {Array<number|null>}
     */
    function netPoints(importData, exportData) {
        return importData.map((imp, i) => {
            const exp = exportData[i];
            if (imp == null || exp == null) return null;
            return Math.round((Number(imp) - Number(exp)) * 1000) / 1000;
        });
    }

    /**
     * One of three mutually exclusive bar sets.
     *
     * @param {Array<number|null>} importData
     * @param {Array<number|null>} exportData
     * @returns {{series: Array<{name: string, data: Array<number|null>}>, colors: string[]}}
     */
    function gridSeries(importData, exportData) {
        const all = [
            { name: 'Import (kWh)', data: importData, color: '#2563eb' },
            { name: 'Export (kWh)', data: exportData, color: '#10b981' },
            { name: 'Net (kWh)', data: netPoints(importData, exportData), color: '#eab308' }
        ];
        const picked = bars === 'registers' ? all.slice(0, 2) : (bars === 'net' ? all.slice(2) : all);
        return {
            series: picked.map((item) => ({ name: item.name, data: item.data })),
            colors: picked.map((item) => item.color)
        };
    }

    function setGridBars(chartKey, hostSel, categories, importData, exportData) {
        const host = document.querySelector(hostSel);
        if (charts[chartKey]) { charts[chartKey].destroy(); charts[chartKey] = null; }
        const picked = gridSeries(importData, exportData);
        charts[chartKey] = new ApexCharts(host, {
            ...baseChartOptions,
            series: picked.series,
            chart: { ...baseChartOptions.chart, type: 'bar', height: 320, toolbar: { show: false } },
            colors: picked.colors,
            plotOptions: { bar: { borderRadius: 3, columnWidth: '72%' } },
            xaxis: { ...baseChartOptions.xaxis, categories: categories },
            yaxis: {
                ...baseChartOptions.yaxis,
                title: { text: 'kWh', style: { color: '#94a3b8', fontSize: '10px' } }
            },
            tooltip: {
                shared: true,
                intersect: false,
                y: {
                    formatter: (val) => (val == null || Number.isNaN(Number(val)) ? '-' : fmtKwh(val))
                }
            }
        });
        charts[chartKey].render();
    }

    /**
     * Capacity peak bars (kW). Null points stay gaps.
     * Optional yMaxKw locks the Y scale (All years Max/Average share the Max-mode ceiling).
     *
     * @param {string} chartKey
     * @param {string} hostSel
     * @param {string[]} categories
     * @param {Array<number|null>} peakData
     * @param {number|null|undefined} yMaxKw
     */
    function setPeakBars(chartKey, hostSel, categories, peakData, yMaxKw) {
        const host = document.querySelector(hostSel);
        if (charts[chartKey]) { charts[chartKey].destroy(); charts[chartKey] = null; }
        /** @type {Record<string, unknown>} */
        const yaxis = {
            ...baseChartOptions.yaxis,
            min: 0,
            title: { text: 'kW', style: { color: '#94a3b8', fontSize: '10px' } }
        };
        if (yMaxKw != null && Number(yMaxKw) > 0) {
            yaxis.max = Number(yMaxKw);
        }
        charts[chartKey] = new ApexCharts(host, {
            ...baseChartOptions,
            series: [{ name: 'Capacity peak (kW)', data: peakData }],
            chart: { ...baseChartOptions.chart, type: 'bar', height: 320, toolbar: { show: false } },
            colors: ['#d97706'],
            plotOptions: { bar: { borderRadius: 3, columnWidth: '72%' } },
            xaxis: { ...baseChartOptions.xaxis, categories: categories },
            yaxis: yaxis,
            tooltip: {
                y: {
                    formatter: (val) => (val == null || Number.isNaN(Number(val)) ? '-' : fmtKw(val))
                }
            }
        });
        charts[chartKey].render();
    }

    function clearChart(chartKey) {
        if (charts[chartKey]) {
            charts[chartKey].destroy();
            charts[chartKey] = null;
        }
    }

    async function loadAllYears(gen) {
        const res = await fetch('summary.php?ajax=all' + metricQuery());
        const data = await res.json();
        if (gen !== loadGen) return;
        if (data.error || !(data.categories || []).length) {
            clearChart('all');
            if (metric === 'grid') {
                renderStats('all-stats', [{ label: 'Grid', value: 'No import / export' }]);
            } else if (metric === 'peak') {
                renderStats('all-stats', [{ label: 'Capacity peak', value: 'No Fluvius months' }]);
            }
            return;
        }
        if (metric === 'peak') {
            const aggLabel = peakAgg === 'avg' ? 'Average' : 'Max';
            const stats = [
                { label: 'Years', value: String(data.years) },
                { label: 'Aggregation', value: aggLabel }
            ];
            if (data.live_peak_kw != null) {
                stats.push({ label: 'Open month (P1)', value: fmtKw(data.live_peak_kw) });
            }
            renderStats('all-stats', stats);
            setPeakBars('all', '#chart-all', data.categories, data.peaks || [], data.y_max_kw);
            return;
        }
        if (metric === 'grid') {
            renderStats('all-stats', [
                { label: 'Import', value: fmtKwh(data.import_kwh) },
                { label: 'Export', value: fmtKwh(data.export_kwh) },
                { label: 'Net import', value: fmtKwh(data.net_kwh) },
                { label: 'Years', value: String(data.years) }
            ]);
            setGridBars('all', '#chart-all', data.categories, data.import, data.export);
            return;
        }
        const avg = data.years > 0 ? data.total_kwh / data.years : 0;
        const allStats = [
            { label: 'Lifetime', value: `${Number(data.total_kwh).toLocaleString()} kWh` },
            { label: 'Years', value: String(data.years) },
            { label: 'Avg / year', value: `${avg.toFixed(0)} kWh` }
        ];
        if (Number(data.expected_kwh_year) > 0) {
            allStats.push({
                label: 'PVGIS / year',
                value: `${Number(data.expected_kwh_year).toLocaleString()} kWh`
            });
        }
        renderStats('all-stats', allStats);
        setYearStyleChart('all', '#chart-all', data, 'PVGIS year baseline');
    }

    async function loadSection(mode, gen) {
        const key = current[mode];
        if (!key) return;
        const ticket = gen == null ? loadGen : gen;
        document.getElementById(mode + '-label').textContent = key;

        let url = '';
        if (mode === 'week') url = `summary.php?ajax=week&week=${encodeURIComponent(key)}`;
        if (mode === 'month') url = `summary.php?ajax=month&month=${encodeURIComponent(key)}`;
        if (mode === 'year') url = `summary.php?ajax=year&year=${encodeURIComponent(key)}`;
        url += metricQuery();

        const res = await fetch(url);
        const data = await res.json();
        if (ticket !== loadGen) return;
        if (data.error) return;
        document.getElementById(mode + '-label').textContent = data.label || key;

        if (metric === 'peak') {
            if (mode !== 'year') return;
            renderStats('year-stats', [
                { label: 'Max', value: fmtKw(data.max_kw) },
                { label: 'Average', value: fmtKw(data.avg_kw) },
                { label: 'Months', value: String(data.months_with_data || 0) },
                { label: 'Open month (P1)', value: data.live_peak_kw != null ? fmtKw(data.live_peak_kw) : '-' }
            ]);
            setPeakBars('year', '#chart-year', data.categories || [], data.peaks || []);
            return;
        }

        if (metric === 'grid') {
            const countLabel = mode === 'year' ? 'Months' : 'Days';
            renderStats(mode + '-stats', [
                { label: 'Import', value: fmtKwh(data.import_kwh) },
                { label: 'Export', value: fmtKwh(data.export_kwh) },
                { label: 'Net import', value: fmtKwh(data.net_kwh) },
                { label: countLabel, value: String((data.categories || []).length) }
            ]);
            setGridBars(mode, '#chart-' + mode, data.categories || [], data.import || [], data.export || []);
            return;
        }

        if (mode === 'week' || mode === 'month') {
            const stats = [
                { label: 'Total yield', value: `${Number(data.total_kwh).toFixed(2)} kWh` },
                { label: 'Days', value: String((data.categories || []).length) }
            ];
            if (mode === 'month' && data.expected_kwh != null) {
                stats.push({ label: 'PVGIS month', value: `${Number(data.expected_kwh).toFixed(1)} kWh` });
                const pct = data.expected_kwh > 0 ? (100 * data.total_kwh / data.expected_kwh) : 0;
                stats.push({ label: 'vs PVGIS', value: `${pct.toFixed(1)} %` });
            }
            if (data.peak_max_w != null) {
                stats.push({ label: 'Peak', value: `${Math.round(data.peak_max_w)} W` });
            }
            renderStats(mode + '-stats', stats);
            setBarChart(mode, data.categories, data.yield, 'Yield (kWh)');
            return;
        }

        const yearStats = [
            { label: 'Year total', value: `${Number(data.total_kwh).toLocaleString()} kWh` }
        ];
        if (Number(data.expected_kwh) > 0) {
            yearStats.push({
                label: 'PVGIS year',
                value: `${Number(data.expected_kwh).toLocaleString()} kWh`
            });
            yearStats.push({
                label: 'vs PVGIS',
                value: `${(100 * data.total_kwh / data.expected_kwh).toFixed(1)} %`
            });
        }
        yearStats.push({
            label: 'Months',
            value: String((data.categories || []).length)
        });
        renderStats('year-stats', yearStats);
        setYearChart(data);
    }

    async function reloadCharts() {
        const gen = ++loadGen;
        if (metric !== 'peak') {
            if (current.week) await loadSection('week', gen);
            if (gen !== loadGen) return;
            if (current.month) await loadSection('month', gen);
            if (gen !== loadGen) return;
        }
        if (current.year) await loadSection('year', gen);
        if (gen !== loadGen) return;
        await loadAllYears(gen);
    }

    function setMetric(next) {
        const chosen = next === 'grid' || next === 'peak' ? next : 'solar';
        if (chosen === metric) return;
        metric = chosen;
        if (metric === 'peak') {
            const py = Array.isArray(periods.peak_years) ? periods.peak_years : [];
            if (py.length) {
                const want = String(new Date().getFullYear());
                current.year = py.includes(want) ? want : py[py.length - 1];
            } else {
                current.year = null;
            }
        } else if (!current.year && periods.years.length) {
            current.year = periods.years[periods.years.length - 1];
        }
        writeViewUrl();
        paintMetricSwitch();
        reloadCharts();
    }

    function setBars(next) {
        const chosen = next === 'registers' || next === 'net' ? next : 'all';
        if (chosen === bars) return;
        bars = chosen;
        writeViewUrl();
        paintBars();
        reloadCharts();
    }

    function setPeakAgg(next) {
        const chosen = next === 'avg' ? 'avg' : 'max';
        if (chosen === peakAgg) return;
        peakAgg = chosen;
        writeViewUrl();
        paintPeakAgg();
        const allBlurb = document.getElementById('all-blurb');
        if (metric === 'peak') {
            allBlurb.textContent = peakAgg === 'avg'
                ? 'Average monthly Capacity peak (kW) per calendar year'
                : 'Max monthly Capacity peak (kW) per calendar year';
        }
        const gen = ++loadGen;
        loadAllYears(gen);
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const params0 = new URLSearchParams(window.location.search);
        const m0 = params0.get('metric');
        metric = m0 === 'grid' || m0 === 'peak' ? m0 : 'solar';
        const bars0 = params0.get('bars');
        bars = bars0 === 'registers' || bars0 === 'net' ? bars0 : 'all';
        peakAgg = params0.get('peak_agg') === 'avg' ? 'avg' : 'max';
        paintMetricSwitch();
        const res = await fetch('summary.php?ajax=meta');
        periods = await res.json();
        const hasRollups = !periods.error
            && ((periods.weeks || []).length || (periods.months || []).length || (periods.years || []).length);
        const hasPeak = (periods.peak_month_count || 0) > 0
            || (Array.isArray(periods.peak_years) && periods.peak_years.length > 0);
        if (metric === 'peak') {
            if (!hasPeak) {
                const warn = document.getElementById('summary-warning');
                warn.innerHTML = 'No Fluvius Capacity peak months yet. Import a <code>piekvermogen</code> CSV (electricity EAN on Settings).';
                warn.classList.remove('hidden');
            }
        } else if (!hasRollups) {
            document.getElementById('summary-warning').classList.remove('hidden');
            lucide.createIcons();
            return;
        }
        current.week = periods.weeks.length ? periods.weeks[periods.weeks.length - 1] : null;
        current.month = periods.months.length ? periods.months[periods.months.length - 1] : null;
        if (metric === 'peak') {
            const py = Array.isArray(periods.peak_years) ? periods.peak_years : [];
            current.year = py.length ? py[py.length - 1] : null;
        } else {
            current.year = periods.years.length ? periods.years[periods.years.length - 1] : null;
        }

        // Deep link: ?month=YYYY-MM sets week (1st of month) + month + year; ?year=YYYY sets year
        const params = new URLSearchParams(window.location.search);
        const qMonth = params.get('month');
        const qYear = params.get('year');
        if (qMonth && /^\d{4}-\d{2}$/.test(String(qMonth))) {
            applyMonthDeepLink(String(qMonth));
        } else if (qYear && /^\d{4}$/.test(String(qYear))) {
            ensureYearKey(String(qYear));
            current.year = String(qYear);
        }

        await reloadCharts();

        if (qMonth || qYear) {
            const el = document.getElementById(
                metric === 'peak' ? 'chart-year' : (qMonth ? 'chart-month' : 'chart-year')
            );
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        lucide.createIcons();
    });
</script>

</body>
</html>
