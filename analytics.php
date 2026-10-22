<?php
date_default_timezone_set('Europe/Brussels');

$dbFile = __DIR__ . '/meter_history.db';

// -----------------------------------------------------------------------------
// 1. AJAX API FOR DYNAMIC CHART LOADING
// -----------------------------------------------------------------------------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    if (!file_exists($dbFile)) {
        echo json_encode(['error' => 'Database not found']);
        exit;
    }

    try {
        $pdo = new PDO("sqlite:" . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Helper: Generate a perfect 288-point empty day grid (00:00 to 23:55)
        function getEmptyDayTemplate() {
            $slots = [];
            for ($h = 0; $h < 24; $h++) {
                for ($m = 0; $m < 60; $m += 5) {
                    $slots[sprintf('%02d:%02d', $h, $m)] = 0.0;
                }
            }
            return $slots;
        }

        $action = $_GET['ajax'];

        // --- AJAX: Get single day intraday curve ---
        if ($action === 'day' && isset($_GET['date'])) {
            $date = $_GET['date']; // YYYY-MM-DD
            $slots = getEmptyDayTemplate();
            
            $stmt = $pdo->prepare("SELECT strftime('%H:%M', timestamp) as time_slot, solar_power_w 
                                   FROM history_5min 
                                   WHERE timestamp LIKE ? 
                                   ORDER BY timestamp ASC");
            $stmt->execute([$date . '%']);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (isset($slots[$row['time_slot']])) {
                    $slots[$row['time_slot']] = (float)$row['solar_power_w'];
                }
            }
            
            echo json_encode([
                'categories' => array_keys($slots),
                'series' => array_values($slots)
            ]);
            exit;
        }

        // --- AJAX: Get Two-Day Overlay ---
        if ($action === 'compare' && isset($_GET['date1']) && isset($_GET['date2'])) {
            $d1 = $_GET['date1'];
            $d2 = $_GET['date2'];
            
            $slots1 = getEmptyDayTemplate();
            $slots2 = getEmptyDayTemplate();

            // Fetch Date 1
            $stmt1 = $pdo->prepare("SELECT strftime('%H:%M', timestamp) as time_slot, solar_power_w FROM history_5min WHERE timestamp LIKE ?");
            $stmt1->execute([$d1 . '%']);
            while ($row = $stmt1->fetch(PDO::FETCH_ASSOC)) {
                if (isset($slots1[$row['time_slot']])) $slots1[$row['time_slot']] = (float)$row['solar_power_w'];
            }

            // Fetch Date 2
            $stmt2 = $pdo->prepare("SELECT strftime('%H:%M', timestamp) as time_slot, solar_power_w FROM history_5min WHERE timestamp LIKE ?");
            $stmt2->execute([$d2 . '%']);
            while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                if (isset($slots2[$row['time_slot']])) $slots2[$row['time_slot']] = (float)$row['solar_power_w'];
            }

            echo json_encode([
                'categories' => array_keys($slots1),
                'series1' => array_values($slots1),
                'series2' => array_values($slots2)
            ]);
            exit;
        }

        // --- AJAX: Yearly Trends & Baselines ---
        if ($action === 'trends') {
            $years = [];
            $totals = [];
            $expected = [];

            // 1. Get PVGIS expected yearly total (Assuming 30 degree inclination for baseline)
            $expStmt = $pdo->query("SELECT SUM(expected_kwh) as yearly_expected FROM expected_monthly WHERE inclination = 30");
            $expRow = $expStmt->fetch(PDO::FETCH_ASSOC);
            $baseline = $expRow ? (float)$expRow['yearly_expected'] : 0;

            // 2. Get actuals per year from daily rollups
            try {
                $stmt = $pdo->query("SELECT strftime('%Y', date) as yr, SUM(solar_yield_kwh) as total 
                                     FROM daily_history 
                                     GROUP BY yr ORDER BY yr ASC");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($row['yr']) {
                        $years[] = $row['yr'];
                        $totals[] = round((float)$row['total'], 1);
                        $expected[] = round($baseline, 1);
                    }
                }
            } catch (Exception $e) {
                // table might not exist yet
            }

            echo json_encode([
                'years' => $years,
                'actuals' => $totals,
                'expected' => $expected,
                'baseline' => $baseline
            ]);
            exit;
        }

        // --- AJAX: Hall of Fame (Top Days) ---
        if ($action === 'records') {
            $topDays = [];
            try {
                $stmt = $pdo->query("SELECT date, solar_yield_kwh, solar_peak_w 
                                     FROM daily_history 
                                     WHERE solar_yield_kwh > 0 
                                     ORDER BY solar_yield_kwh DESC LIMIT 10");
                $topDays = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}

            echo json_encode(['top_days' => $topDays]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
    exit;
}
// -----------------------------------------------------------------------------
// END AJAX API
// -----------------------------------------------------------------------------
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
        .tab-btn { @apply pb-3 px-1 border-b-2 font-medium text-sm transition-colors cursor-pointer; }
        .tab-btn.active { @apply border-indigo-600 text-indigo-600; }
        .tab-btn.inactive { @apply border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300; }
        .tab-content { @apply hidden; }
        .tab-content.active { @apply block animate-in fade-in duration-300; }
    </style>
</head>
<body class="p-4 md:p-8">

<div class="max-w-6xl mx-auto space-y-6">

    <!-- Header Navigation -->
    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <a href="index.php" class="w-10 h-10 rounded-2xl bg-white border border-slate-200 text-slate-600 flex items-center justify-center hover:bg-slate-50 transition-colors shadow-sm">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-black text-slate-900 tracking-tight">Historical Analytics</h1>
                    <p class="text-xs text-slate-400 mt-0.5">Explore trends and diurnal curves</p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="activity" class="w-3.5 h-3.5 text-emerald-600"></i> Live Dashboard
            </a>
        </div>
    </header>

    <!-- Tab Navigation -->
    <div class="border-b border-slate-200">
        <nav class="-mb-px flex gap-6" aria-label="Tabs">
            <button onclick="switchTab('tab-intraday', this)" class="tab-btn active">Intraday Explorer</button>
            <button onclick="switchTab('tab-compare', this)" class="tab-btn inactive">Two-Day Overlay</button>
            <button onclick="switchTab('tab-trends', this)" class="tab-btn inactive" onclick="loadTrends()">Yearly Trends</button>
            <button onclick="switchTab('tab-records', this)" class="tab-btn inactive" onclick="loadRecords()">Hall of Fame</button>
        </nav>
    </div>

    <!-- TAB 1: Intraday Explorer -->
    <div id="tab-intraday" class="tab-content active">
        <div class="card border-t-4 border-t-amber-500">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i data-lucide="sun" class="w-5 h-5 text-amber-500"></i> Daily Generation Curve
                    </h2>
                    <p class="text-xs text-slate-400">View the 5-minute profile for any day in history</p>
                </div>
                <div class="flex items-center gap-2">
                    <input type="date" id="singleDate" value="<?= date('Y-m-d', strtotime('-1 day')) ?>" class="text-sm px-3 py-1.5 border border-slate-200 rounded-lg text-slate-700 bg-slate-50 focus:ring-2 focus:ring-amber-500 outline-none">
                    <button onclick="loadIntraday()" class="bg-slate-900 hover:bg-slate-800 text-white px-4 py-1.5 rounded-lg text-sm font-semibold transition-colors">Load</button>
                </div>
            </div>
            <div id="chart-intraday" class="w-full h-[400px]"></div>
        </div>
    </div>

    <!-- TAB 2: Two-Day Overlay -->
    <div id="tab-compare" class="tab-content">
        <div class="card border-t-4 border-t-indigo-500">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i data-lucide="layers" class="w-5 h-5 text-indigo-500"></i> Two-Day Overlay
                    </h2>
                    <p class="text-xs text-slate-400">Compare generation curves across different days or years</p>
                </div>
                <div class="flex flex-wrap items-center gap-3 bg-slate-50 p-2 rounded-xl border border-slate-100">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                        <input type="date" id="compareDate1" value="<?= date('Y-m-d', strtotime('-1 day')) ?>" class="text-sm px-2 py-1 border border-slate-200 rounded text-slate-700">
                    </div>
                    <span class="text-slate-400 text-xs font-bold uppercase">VS</span>
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                        <input type="date" id="compareDate2" value="<?= date('Y-m-d', strtotime('-1 year -1 day')) ?>" class="text-sm px-2 py-1 border border-slate-200 rounded text-slate-700">
                    </div>
                    <button onclick="loadComparison()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-1.5 rounded-lg text-sm font-semibold transition-colors shadow-sm">Compare</button>
                </div>
            </div>
            <div id="chart-compare" class="w-full h-[400px]"></div>
        </div>
    </div>

    <!-- TAB 3: Yearly Trends -->
    <div id="tab-trends" class="tab-content">
        <div class="card border-t-4 border-t-emerald-500">
            <div class="flex flex-col sm:flex-row justify-between mb-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i data-lucide="trending-up" class="w-5 h-5 text-emerald-500"></i> Lifetime Yearly Generation
                    </h2>
                    <p class="text-xs text-slate-400">Actual yield vs PVGIS expected baseline</p>
                </div>
            </div>
            <div id="chart-trends" class="w-full h-[400px]"></div>
            <div id="trends-warning" class="hidden text-xs text-amber-600 bg-amber-50 border border-amber-200 p-3 rounded-xl mt-4">
                <i data-lucide="alert-circle" class="w-4 h-4 inline mr-1"></i> Data missing. Ensure you have run <code>python3 rollup.py</code> to generate the daily aggregations.
            </div>
        </div>
    </div>

    <!-- TAB 4: Hall of Fame -->
    <div id="tab-records" class="tab-content">
        <div class="card border-t-4 border-t-rose-500">
            <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2 mb-1">
                <i data-lucide="award" class="w-5 h-5 text-rose-500"></i> Hall of Fame: Top 10 Days
            </h2>
            <p class="text-xs text-slate-400 mb-6">The highest generating days in system history</p>

            <div class="overflow-x-auto rounded-xl border border-slate-100 bg-slate-50/50">
                <table class="w-full text-left border-collapse whitespace-nowrap text-sm">
                    <thead class="text-xs uppercase tracking-wider text-slate-500 border-b border-slate-200">
                        <tr>
                            <th class="py-3 px-4 font-semibold">Rank</th>
                            <th class="py-3 px-4 font-semibold">Date</th>
                            <th class="py-3 px-4 font-semibold text-right">Yield (kWh)</th>
                            <th class="py-3 px-4 font-semibold text-right">Peak Power (W)</th>
                        </tr>
                    </thead>
                    <tbody id="records-tbody" class="divide-y divide-slate-100 font-mono text-slate-700 bg-white">
                        <tr><td colspan="4" class="py-6 text-center text-slate-400 text-xs font-sans">Loading records... (Requires daily rollups)</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
    lucide.createIcons();

    // Store chart instances so we can update them safely
    let chartIntraday = null;
    let chartCompare = null;
    let chartTrends = null;

    // UI Tab Switcher
    function switchTab(tabId, btn) {
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('active', 'border-indigo-600', 'text-indigo-600');
            b.classList.add('inactive');
        });
        
        document.getElementById(tabId).classList.add('active');
        btn.classList.remove('inactive');
        btn.classList.add('active', 'border-indigo-600', 'text-indigo-600');

        // Lazy load data if appropriate tab is clicked
        if (tabId === 'tab-intraday' && !chartIntraday) loadIntraday();
        if (tabId === 'tab-compare' && !chartCompare) loadComparison();
        if (tabId === 'tab-trends' && !chartTrends) loadTrends();
        if (tabId === 'tab-records') loadRecords();
    }

    // Standard chart options
    const baseChartOptions = {
        chart: { toolbar: { show: true }, zoom: { enabled: true }, animations: { enabled: false } },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        xaxis: { tickAmount: 12, labels: { style: { colors: '#94a3b8', fontSize: '10px' } } },
        yaxis: { labels: { style: { colors: '#94a3b8', fontSize: '10px' } } },
        grid: { borderColor: '#f1f5f9', strokeDashArray: 4 }
    };

    // 1. Intraday Explorer
    async function loadIntraday() {
        const date = document.getElementById('singleDate').value;
        if(!date) return;
        
        const res = await fetch(`analytics.php?ajax=day&date=${date}`);
        const data = await res.json();

        if (chartIntraday) {
            chartIntraday.updateSeries([{ name: 'Solar Output (W)', data: data.series }]);
        } else {
            const options = { ...baseChartOptions,
                series: [{ name: 'Solar Output (W)', data: data.series }],
                chart: { ...baseChartOptions.chart, type: 'area', height: 400 },
                colors: ['#f59e0b'],
                fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 100] } },
                xaxis: { ...baseChartOptions.xaxis, categories: data.categories },
                tooltip: { x: { formatter: (val) => `Time: ${val}` }, y: { formatter: (val) => `${val} W` } }
            };
            chartIntraday = new ApexCharts(document.querySelector("#chart-intraday"), options);
            chartIntraday.render();
        }
    }

    // 2. Two-Day Overlay
    async function loadComparison() {
        const d1 = document.getElementById('compareDate1').value;
        const d2 = document.getElementById('compareDate2').value;
        if(!d1 || !d2) return;

        const res = await fetch(`analytics.php?ajax=compare&date1=${d1}&date2=${d2}`);
        const data = await res.json();

        if (chartCompare) {
            chartCompare.updateSeries([
                { name: d1, data: data.series1 },
                { name: d2, data: data.series2 }
            ]);
        } else {
            const options = { ...baseChartOptions,
                series: [
                    { name: d1, data: data.series1 },
                    { name: d2, data: data.series2 }
                ],
                chart: { ...baseChartOptions.chart, type: 'line', height: 400 },
                colors: ['#4f46e5', '#f59e0b'],
                xaxis: { ...baseChartOptions.xaxis, categories: data.categories },
                tooltip: { shared: true, intersect: false, y: { formatter: (val) => `${val} W` } }
            };
            chartCompare = new ApexCharts(document.querySelector("#chart-compare"), options);
            chartCompare.render();
        }
    }

    // 3. Yearly Trends
    async function loadTrends() {
        const res = await fetch(`analytics.php?ajax=trends`);
        const data = await res.json();

        if (data.years && data.years.length > 0) {
            document.getElementById('trends-warning').classList.add('hidden');
            if (chartTrends) {
                chartTrends.updateSeries([
                    { name: 'Actual Generation (kWh)', type: 'column', data: data.actuals },
                    { name: 'PVGIS Expected Baseline', type: 'line', data: data.expected }
                ]);
            } else {
                const options = { ...baseChartOptions,
                    series: [
                        { name: 'Actual Generation (kWh)', type: 'column', data: data.actuals },
                        { name: 'PVGIS Expected Baseline', type: 'line', data: data.expected }
                    ],
                    chart: { type: 'line', height: 400, toolbar: { show: false } },
                    stroke: { width: [0, 3], curve: 'straight', dashArray: [0, 8] },
                    colors: ['#10b981', '#cbd5e1'],
                    xaxis: { categories: data.years, labels: { style: { colors: '#64748b', fontSize: '11px', fontWeight: 'bold' } } },
                    yaxis: { title: { text: "Yield (kWh)", style: { color: '#94a3b8', fontSize: '10px' } } },
                    tooltip: { shared: true, intersect: false, y: { formatter: (val) => `${val.toLocaleString()} kWh` } }
                };
                chartTrends = new ApexCharts(document.querySelector("#chart-trends"), options);
                chartTrends.render();
            }
        } else {
            document.getElementById('trends-warning').classList.remove('hidden');
        }
    }

    // 4. Hall of Fame Records
    async function loadRecords() {
        const res = await fetch(`analytics.php?ajax=records`);
        const data = await res.json();
        const tbody = document.getElementById('records-tbody');

        if (data.top_days && data.top_days.length > 0) {
            tbody.innerHTML = '';
            data.top_days.forEach((day, index) => {
                const row = document.createElement('tr');
                row.className = 'hover:bg-slate-50/80 transition-colors group';
                row.innerHTML = `
                    <td class="py-2.5 px-4 font-bold text-slate-400 group-hover:text-rose-500">#${index + 1}</td>
                    <td class="py-2.5 px-4 text-slate-800 font-semibold">${day.date}</td>
                    <td class="py-2.5 px-4 text-right font-black text-rose-600">${parseFloat(day.solar_yield_kwh).toFixed(2)}</td>
                    <td class="py-2.5 px-4 text-right text-slate-500">${day.solar_peak_w ? parseInt(day.solar_peak_w) : '-'}</td>
                `;
                tbody.appendChild(row);
            });
        }
    }

    // Initial load for default tab
    document.addEventListener("DOMContentLoaded", () => {
        loadIntraday();
    });
</script>

</body>
</html>