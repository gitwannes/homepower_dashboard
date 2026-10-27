<?php
# --- file: topn.php ---
/**
 * Top-N rankings + all-time records (A7).
 * Solar (default): best/worst yield from top_periods. Click -> analytics day or summary month/year.
 * Grid (metric=grid): highest net import and highest net export from daily_history / monthly_history.
 * Net import is import minus export. Net export is export minus import.
 * The local day still in progress is left out of both day ranks.
 * A grid day click opens that month on summary, because the analytics day chart stays solar.
 * All-time singles from top_records sit above the charts; each dated row links to analytics that day.
 * Sensorbox / self-consumption rows are omitted when absent from the table.
 */
date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/site_settings.php';

$hp = hp_boot();
hp_require_ready($hp);
$dbFile = $hp['db'];

/**
 * solar or grid. Any other value stays on solar yield.
 */
function topn_metric(): string
{
    $metric = isset($_GET['metric']) ? (string)$_GET['metric'] : 'solar';
    return $metric === 'grid' ? 'grid' : 'solar';
}

/**
 * Display order for all-time singles (A7). Rows missing from the DB are skipped in the UI.
 *
 * @return list<string>
 */
function topn_record_order(): array
{
    return [
        'best_day_kwh',
        'peak_surge_w',
        'max_sun_hours',
        'best_self_consumption_kwh',
        'max_wind_ms',
        'max_temp_c',
        'min_temp_c',
        'max_module_temp_c',
    ];
}

/**
 * @return list<array{record_id: string, record_value: float, record_date: string, description: string}>
 */
function topn_records(PDO $pdo): array
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
    $out = [];
    foreach (topn_record_order() as $id) {
        if (isset($byId[$id])) {
            $out[] = $byId[$id];
        }
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
            try {
                $records = topn_records($pdo);
            } catch (Exception $e) {
                echo json_encode([
                    'error' => 'top_records missing - run: python3 rollup.py -buildalltoprecords',
                    'detail' => $e->getMessage(),
                ]);
                exit;
            }
            echo json_encode(['ok' => true, 'count' => count($records), 'records' => $records]);
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
            <?= hp_nav_settings() ?>
        </div>
    </header>

    <div class="flex flex-wrap items-center gap-3">
        <div class="metric-switch" role="group" aria-label="Ranking metric">
            <button type="button" id="metric-solar" class="metric-on" aria-pressed="true" onclick="setMetric('solar')">Solar yield</button>
            <button type="button" id="metric-grid" aria-pressed="false" onclick="setMetric('grid')">Grid import / export</button>
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
        <p class="text-xs text-slate-400 mb-3">Click a row to open that day in Analytics. Wind, ambient, module, and self-consumption appear only when data exists.</p>
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

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="card border-t-4 border-t-emerald-500">
            <h2 id="title-day-best" class="text-lg font-bold text-slate-900 mb-1">Best days</h2>
            <p id="sub-day-best" class="text-xs text-slate-400 mb-3">Top 20 by solar yield (kWh)</p>
            <div id="chart-day-best" class="w-full"></div>
        </div>
        <div class="card border-t-4 border-t-rose-500">
            <h2 id="title-day-worst" class="text-lg font-bold text-slate-900 mb-1">Worst days</h2>
            <p id="sub-day-worst" class="text-xs text-slate-400 mb-3">Bottom 20 by solar yield (kWh); no-production days and today excluded</p>
            <div id="chart-day-worst" class="w-full"></div>
        </div>
        <div class="card border-t-4 border-t-teal-500">
            <h2 id="title-month-best" class="text-lg font-bold text-slate-900 mb-1">Best months</h2>
            <p id="sub-month-best" class="text-xs text-slate-400 mb-3">Top 10 by solar yield (kWh)</p>
            <div id="chart-month-best" class="w-full"></div>
        </div>
        <div class="card border-t-4 border-t-orange-500">
            <h2 id="title-month-worst" class="text-lg font-bold text-slate-900 mb-1">Worst months</h2>
            <p id="sub-month-worst" class="text-xs text-slate-400 mb-3">Bottom 10 by solar yield (kWh)</p>
            <div id="chart-month-worst" class="w-full"></div>
        </div>
        <div class="card border-t-4 border-t-indigo-500">
            <h2 id="title-year-best" class="text-lg font-bold text-slate-900 mb-1">Best years</h2>
            <p id="sub-year-best" class="text-xs text-slate-400 mb-3">Top 10 by solar yield (kWh)</p>
            <div id="chart-year-best" class="w-full"></div>
        </div>
        <div class="card border-t-4 border-t-slate-500">
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
    /** @type {'solar'|'grid'} */
    let metric = 'solar';
    /** @type {'all'|'registers'|'net'} */
    let bars = 'all';

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
     * @param {Array<{record_id: string, record_value: number, record_date: string, description: string}>} rows
     */
    function renderRecords(rows) {
        const body = document.getElementById('records-body');
        const warn = document.getElementById('records-warning');
        body.innerHTML = '';
        if (!rows || !rows.length) {
            warn.classList.remove('hidden');
            return;
        }
        warn.classList.add('hidden');
        rows.forEach((row) => {
            const tr = document.createElement('tr');
            tr.className = 'cursor-pointer hover:bg-amber-50/60 transition-colors';
            tr.title = 'Open ' + row.record_date + ' in Analytics';
            tr.addEventListener('click', () => {
                window.location.href = 'analytics.php?date=' + encodeURIComponent(row.record_date);
            });
            const label = row.description || row.record_id;
            tr.innerHTML =
                '<td class="py-2.5 pr-3 text-slate-800 font-medium">' + label + '</td>' +
                '<td class="py-2.5 pr-3 font-mono text-slate-700">' + formatRecordValue(row.record_id, row.record_value) + '</td>' +
                '<td class="py-2.5 font-mono text-indigo-600 underline-offset-2 hover:underline">' + row.record_date + '</td>';
            body.appendChild(tr);
        });
    }

    async function loadRecords() {
        const warn = document.getElementById('records-warning');
        try {
            const res = await fetch('topn.php?ajax=records');
            const data = await res.json();
            if (data.error) {
                warn.textContent = data.error;
                warn.classList.remove('hidden');
                document.getElementById('records-body').innerHTML = '';
                return;
            }
            renderRecords(data.records || []);
        } catch (e) {
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
     * @param {Array<{period_key: string, yield_kwh?: number, net_kwh?: number}>} rows
     * @returns {number[]}
     */
    function rowValues(rows) {
        return rows.map((r) => (r.net_kwh != null ? r.net_kwh : r.yield_kwh));
    }

    /**
     * @param {string} hostId
     * @param {string} grain
     * @param {string} direction
     * @param {Array<{period_key: string, yield_kwh?: number, net_kwh?: number}>} rows
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
                y: { formatter: (v) => `${Number(v).toLocaleString(undefined, { maximumFractionDigits: 3 })} kWh` }
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

    function paintCopy() {
        const solarBtn = document.getElementById('metric-solar');
        const gridBtn = document.getElementById('metric-grid');
        solarBtn.classList.toggle('metric-on', metric === 'solar');
        gridBtn.classList.toggle('metric-on', metric === 'grid');
        solarBtn.setAttribute('aria-pressed', metric === 'solar' ? 'true' : 'false');
        gridBtn.setAttribute('aria-pressed', metric === 'grid' ? 'true' : 'false');
        paintBars();
        const summary = document.getElementById('nav-summary');
        const barsPart = bars === 'all' ? '' : '&bars=' + encodeURIComponent(bars);
        if (metric === 'grid') {
            document.getElementById('topn-title').textContent = 'Grid net rankings';
            document.getElementById('topn-blurb').textContent = 'Highest net import and net export — days (20), months (10), years (10). Today is excluded. Click a bar to open that period.';
            summary.href = 'summary.php?metric=grid' + barsPart;
            document.getElementById('title-day-best').textContent = 'Highest net import days';
            document.getElementById('sub-day-best').textContent = 'Top 20 by import − export. Bars: import, export, net (yellow). Today excluded.';
            document.getElementById('title-day-worst').textContent = 'Highest net export days';
            document.getElementById('sub-day-worst').textContent = 'Top 20 by export − import. Bars: import, export, net (yellow). Today excluded.';
            document.getElementById('title-month-best').textContent = 'Highest net import months';
            document.getElementById('sub-month-best').textContent = 'Top 10 by import − export. Bars: import, export, net (yellow).';
            document.getElementById('title-month-worst').textContent = 'Highest net export months';
            document.getElementById('sub-month-worst').textContent = 'Top 10 by export − import. Bars: import, export, net (yellow).';
            document.getElementById('title-year-best').textContent = 'Highest net import years';
            document.getElementById('sub-year-best').textContent = 'Top 10 by import − export. Bars: import, export, net (yellow).';
            document.getElementById('title-year-worst').textContent = 'Highest net export years';
            document.getElementById('sub-year-worst').textContent = 'Top 10 by export − import. Bars: import, export, net (yellow).';
            return;
        }
        document.getElementById('topn-title').textContent = 'Top Yield Rankings';
        document.getElementById('topn-blurb').textContent = 'Best and worst days (20), months (10), years (10) — click a bar to open that period';
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
        const q = metric === 'grid' ? 'topn.php?ajax=lists&metric=grid' : 'topn.php?ajax=lists';
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
                    : 'No ranking data yet. Run python3 rollup.py -buildall (or -daily).');
            warn.classList.remove('hidden');
            renderRankChart('chart-day-best', 'day', 'best', [], '#10b981', 'Yield (kWh)');
            renderRankChart('chart-day-worst', 'day', 'worst', [], '#f43f5e', 'Yield (kWh)');
            renderRankChart('chart-month-best', 'month', 'best', [], '#14b8a6', 'Yield (kWh)');
            renderRankChart('chart-month-worst', 'month', 'worst', [], '#f97316', 'Yield (kWh)');
            renderRankChart('chart-year-best', 'year', 'best', [], '#6366f1', 'Yield (kWh)');
            renderRankChart('chart-year-worst', 'year', 'worst', [], '#64748b', 'Yield (kWh)');
            return;
        }
        const L = data.lists;
        if (metric === 'grid') {
            renderGridRankChart('chart-day-best', 'day', L.day.import);
            renderGridRankChart('chart-day-worst', 'day', L.day.export);
            renderGridRankChart('chart-month-best', 'month', L.month.import);
            renderGridRankChart('chart-month-worst', 'month', L.month.export);
            renderGridRankChart('chart-year-best', 'year', L.year.import);
            renderGridRankChart('chart-year-worst', 'year', L.year.export);
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
        if (metric === 'grid') url.searchParams.set('metric', 'grid');
        else url.searchParams.delete('metric');
        if (bars === 'all') url.searchParams.delete('bars');
        else url.searchParams.set('bars', bars);
        history.replaceState(null, '', url);
    }

    function setMetric(next) {
        const chosen = next === 'grid' ? 'grid' : 'solar';
        if (chosen === metric) return;
        metric = chosen;
        writeViewUrl();
        loadRankings();
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
        metric = params0.get('metric') === 'grid' ? 'grid' : 'solar';
        const bars0 = params0.get('bars');
        bars = bars0 === 'registers' || bars0 === 'net' ? bars0 : 'all';
        loadRecords();
        loadRankings();
        lucide.createIcons();
    });
</script>

</body>
</html>
