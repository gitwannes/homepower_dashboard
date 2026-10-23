<?php
# --- file: summary.php ---
/**
 * Period summaries (all-years / ISO week / month / year) from daily_history / monthly_history.
 * Prev/next arrows wrap within available data periods (not used on All years).
 * (Day mode removed: a single daily_history bar is not useful; use analytics intraday for one day.)
 */
date_default_timezone_set('Europe/Brussels');

$dbFile = __DIR__ . '/meter_history.db';

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
            echo json_encode($periods);
            exit;
        }

        // --- Week: daily bars Mon-Sun ---
        if ($action === 'week' && isset($_GET['week'])) {
            $week = (string)$_GET['week'];
            $days = summary_iso_week_dates($week);
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
                        $mNum = (int)substr($ym, 5, 2);
                        $expVal = $row['expected_kwh'] !== null
                            ? (float)$row['expected_kwh']
                            : (isset($expByMonth[$mNum]) ? $expByMonth[$mNum] : 0.0);
                        $expected[] = round($expVal, 1);
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
                <p class="text-xs text-slate-400 mt-0.5">All years, week, month, and year from daily rollups</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="activity" class="w-3.5 h-3.5 text-emerald-600"></i> Live Dashboard
            </a>
            <a href="analytics.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="line-chart" class="w-3.5 h-3.5 text-indigo-600"></i> Analytics
            </a>
            <a href="topn.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="trophy" class="w-3.5 h-3.5 text-amber-500"></i> Top-N
            </a>
        </div>
    </header>

    <div id="summary-warning" class="hidden text-xs text-amber-600 bg-amber-50 border border-amber-200 p-3 rounded-xl">
        No daily rollups yet. Run <code>python3 rollup.py -buildall</code> (or <code>-daily</code>) first.
    </div>

    <!-- All years (multi-year overview; was on old analytics yearly tab, dropped in A3 split) -->
    <div class="card border-t-4 border-t-indigo-500">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
            <div>
                <h2 class="text-lg font-bold text-slate-900">All years</h2>
                <p class="text-xs text-slate-400">Lifetime yield per calendar year vs PVGIS annual baseline</p>
            </div>
        </div>
        <div id="all-stats" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3 text-sm"></div>
        <div id="chart-all" class="w-full h-[320px]"></div>
    </div>

    <!-- Week -->
    <div class="card border-t-4 border-t-emerald-500">
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

    <!-- Month -->
    <div class="card border-t-4 border-t-teal-500">
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
    <div class="card border-t-4 border-t-cyan-500">
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

</div>

<script>
    lucide.createIcons();

    /** @type {{weeks: string[], months: string[], years: string[]}} */
    let periods = { weeks: [], months: [], years: [] };
    /** @type {{week: string|null, month: string|null, year: string|null}} */
    let current = { week: null, month: null, year: null };
    /** @type {{all: any, week: any, month: any, year: any}} */
    let charts = { all: null, week: null, month: null, year: null };

    const baseChartOptions = {
        chart: { toolbar: { show: true }, zoom: { enabled: false }, animations: { enabled: false } },
        dataLabels: { enabled: false },
        grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
        xaxis: { labels: { style: { colors: '#94a3b8', fontSize: '10px' } } },
        yaxis: { labels: { style: { colors: '#94a3b8', fontSize: '10px' } } }
    };

    function keysFor(mode) {
        if (mode === 'week') return periods.weeks;
        if (mode === 'month') return periods.months;
        return periods.years;
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
        charts[chartKey] = new ApexCharts(host, {
            ...baseChartOptions,
            series: [
                { name: 'Actual (kWh)', type: 'column', data: data.actuals },
                { name: expectedName, type: 'line', data: data.expected }
            ],
            chart: { type: 'line', height: 320, toolbar: { show: false }, animations: { enabled: false } },
            stroke: { width: [0, 3], curve: 'straight', dashArray: [0, 8] },
            colors: ['#10b981', '#cbd5e1'],
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

    async function loadAllYears() {
        const res = await fetch('summary.php?ajax=all');
        const data = await res.json();
        if (data.error || !(data.categories || []).length) return;
        const avg = data.years > 0 ? data.total_kwh / data.years : 0;
        renderStats('all-stats', [
            { label: 'Lifetime', value: `${Number(data.total_kwh).toLocaleString()} kWh` },
            { label: 'Years', value: String(data.years) },
            { label: 'Avg / year', value: `${avg.toFixed(0)} kWh` },
            {
                label: 'PVGIS / year',
                value: data.expected_kwh_year > 0
                    ? `${Number(data.expected_kwh_year).toLocaleString()} kWh`
                    : '-'
            }
        ]);
        setYearStyleChart('all', '#chart-all', data, 'PVGIS year baseline');
    }

    async function loadSection(mode) {
        const key = current[mode];
        if (!key) return;
        document.getElementById(mode + '-label').textContent = key;

        let url = '';
        if (mode === 'week') url = `summary.php?ajax=week&week=${encodeURIComponent(key)}`;
        if (mode === 'month') url = `summary.php?ajax=month&month=${encodeURIComponent(key)}`;
        if (mode === 'year') url = `summary.php?ajax=year&year=${encodeURIComponent(key)}`;

        const res = await fetch(url);
        const data = await res.json();
        if (data.error) return;
        document.getElementById(mode + '-label').textContent = data.label || key;

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

        renderStats('year-stats', [
            { label: 'Year total', value: `${Number(data.total_kwh).toLocaleString()} kWh` },
            { label: 'PVGIS year', value: `${Number(data.expected_kwh).toLocaleString()} kWh` },
            {
                label: 'vs PVGIS',
                value: data.expected_kwh > 0
                    ? `${(100 * data.total_kwh / data.expected_kwh).toFixed(1)} %`
                    : '-'
            },
            { label: 'Months', value: String((data.categories || []).length) }
        ]);
        setYearChart(data);
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const res = await fetch('summary.php?ajax=meta');
        periods = await res.json();
        if (periods.error || (!periods.weeks.length && !periods.months.length && !periods.years.length)) {
            document.getElementById('summary-warning').classList.remove('hidden');
            lucide.createIcons();
            return;
        }
        current.week = periods.weeks.length ? periods.weeks[periods.weeks.length - 1] : null;
        current.month = periods.months.length ? periods.months[periods.months.length - 1] : null;
        current.year = periods.years.length ? periods.years[periods.years.length - 1] : null;

        // Deep link from topn.php: ?month=YYYY-MM or ?year=YYYY
        const params = new URLSearchParams(window.location.search);
        const qMonth = params.get('month');
        const qYear = params.get('year');
        if (qMonth && periods.months.includes(qMonth)) {
            current.month = qMonth;
        }
        if (qYear && periods.years.includes(qYear)) {
            current.year = qYear;
        }

        await loadAllYears();
        if (current.week) await loadSection('week');
        if (current.month) await loadSection('month');
        if (current.year) await loadSection('year');

        if (qMonth || qYear) {
            const el = document.getElementById(qMonth ? 'chart-month' : 'chart-year');
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        lucide.createIcons();
    });
</script>

</body>
</html>
