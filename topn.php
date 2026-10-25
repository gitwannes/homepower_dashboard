<?php
# --- file: topn.php ---
/**
 * Top-N / worst-N solar yield rankings (A4).
 * Data from top_periods (filled by rollup -buildall / -daily).
 * Click a bar -> analytics day or summary month/year.
 */
date_default_timezone_set('Europe/Brussels');

$dbFile = __DIR__ . '/meter_history.db';

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
                <h1 class="text-2xl font-black text-slate-900 tracking-tight">Top Yield Rankings</h1>
                <p class="text-xs text-slate-400 mt-0.5">Best and worst days (20), months (10), years (10) — click a bar to open that period</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="activity" class="w-3.5 h-3.5 text-emerald-600"></i> Live Dashboard
            </a>
            <a href="analytics.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="line-chart" class="w-3.5 h-3.5 text-indigo-600"></i> Analytics
            </a>
            <a href="summary.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="calendar-range" class="w-3.5 h-3.5 text-emerald-600"></i> Summary
            </a>
        </div>
    </header>

    <div id="topn-warning" class="hidden text-xs text-amber-600 bg-amber-50 border border-amber-200 p-3 rounded-xl">
        No ranking data yet. Run <code>python3 rollup.py -buildall</code> (or <code>-daily</code>).
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="card border-t-4 border-t-emerald-500">
            <h2 class="text-lg font-bold text-slate-900 mb-1">Best days</h2>
            <p class="text-xs text-slate-400 mb-3">Top 20 by solar yield (kWh)</p>
            <div id="chart-day-best" class="w-full h-[360px]"></div>
        </div>
        <div class="card border-t-4 border-t-rose-500">
            <h2 class="text-lg font-bold text-slate-900 mb-1">Worst days</h2>
            <p class="text-xs text-slate-400 mb-3">Bottom 20 by solar yield (kWh); no-production days and today excluded</p>
            <div id="chart-day-worst" class="w-full h-[360px]"></div>
        </div>
        <div class="card border-t-4 border-t-teal-500">
            <h2 class="text-lg font-bold text-slate-900 mb-1">Best months</h2>
            <p class="text-xs text-slate-400 mb-3">Top 10 by solar yield (kWh)</p>
            <div id="chart-month-best" class="w-full h-[320px]"></div>
        </div>
        <div class="card border-t-4 border-t-orange-500">
            <h2 class="text-lg font-bold text-slate-900 mb-1">Worst months</h2>
            <p class="text-xs text-slate-400 mb-3">Bottom 10 by solar yield (kWh)</p>
            <div id="chart-month-worst" class="w-full h-[320px]"></div>
        </div>
        <div class="card border-t-4 border-t-indigo-500">
            <h2 class="text-lg font-bold text-slate-900 mb-1">Best years</h2>
            <p class="text-xs text-slate-400 mb-3">Top 10 by solar yield (kWh)</p>
            <div id="chart-year-best" class="w-full h-[280px]"></div>
        </div>
        <div class="card border-t-4 border-t-slate-500">
            <h2 class="text-lg font-bold text-slate-900 mb-1">Worst years</h2>
            <p class="text-xs text-slate-400 mb-3">Bottom 10 by solar yield (kWh)</p>
            <div id="chart-year-worst" class="w-full h-[280px]"></div>
        </div>
    </div>

</div>

<script>
    lucide.createIcons();

    /** @type {Record<string, any>} */
    const charts = {};

    /**
     * @param {string} grain
     * @param {string} periodKey
     */
    function openPeriod(grain, periodKey) {
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
     * @param {string} hostId
     * @param {string} grain
     * @param {string} direction
     * @param {Array<{period_key: string, yield_kwh: number}>} rows
     * @param {string} color
     */
    function renderRankChart(hostId, grain, direction, rows, color) {
        const host = document.querySelector('#' + hostId);
        if (charts[hostId]) {
            charts[hostId].destroy();
            charts[hostId] = null;
        }
        if (!rows || !rows.length) {
            host.innerHTML = '<p class="text-sm text-slate-400 py-8 text-center">No data</p>';
            return;
        }
        const categories = rows.map((r) => r.period_key);
        const data = rows.map((r) => r.yield_kwh);
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
            series: [{ name: 'Yield (kWh)', data: data }],
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
                y: { formatter: (v) => `${Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 })} kWh` }
            }
        });
        charts[hostId].render();
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const warn = document.getElementById('topn-warning');
        let data;
        try {
            const res = await fetch('topn.php?ajax=lists');
            data = await res.json();
        } catch (e) {
            warn.textContent = 'Failed to load rankings.';
            warn.classList.remove('hidden');
            return;
        }
        if (data.error || !data.filled) {
            warn.textContent = data.error
                || 'No ranking data yet. Run python3 rollup.py -buildall (or -daily).';
            warn.classList.remove('hidden');
            return;
        }
        const L = data.lists;
        renderRankChart('chart-day-best', 'day', 'best', L.day.best, '#10b981');
        renderRankChart('chart-day-worst', 'day', 'worst', L.day.worst, '#f43f5e');
        renderRankChart('chart-month-best', 'month', 'best', L.month.best, '#14b8a6');
        renderRankChart('chart-month-worst', 'month', 'worst', L.month.worst, '#f97316');
        renderRankChart('chart-year-best', 'year', 'best', L.year.best, '#6366f1');
        renderRankChart('chart-year-worst', 'year', 'worst', L.year.worst, '#64748b');
        lucide.createIcons();
    });
</script>

</body>
</html>
