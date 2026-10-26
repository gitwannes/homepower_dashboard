<?php
# --- file: import_dashboard.php ---
date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/site_settings.php';

$hp = hp_boot();
hp_require_ready($hp);
$dbFile = $hp['db'];
$queueDir = __DIR__ . '/import_queue';
$processedDir =$queueDir . '/processed';

if (!file_exists($queueDir)) {
    @mkdir($queueDir, 0777, true);
}
if (!file_exists($processedDir)) {
    @mkdir($processedDir, 0777, true);
}

// 1. AJAX Event Stream Handler: Runs importer.py and pipes JSON events
if (isset($_GET['action']) &&$_GET['action'] === 'run') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');

    $pyScript = __DIR__ . '/importer.py';$pyBin = file_exists('/bin/python3') ? '/bin/python3' : 'python3';

    $proc = popen("{$pyBin} -u " . escapeshellarg($pyScript) . " 2>&1", 'r');
    while ($line = fgets($proc)) {
        echo "data: " . trim($line) . "\n\n";
        ob_flush();
        flush();
    }
    pclose($proc);
    echo "data: {\"type\": \"stream_end\"}\n\n";
    ob_flush();
    flush();
    exit;
}

// 2. Query Statistics
$stats = [
    'sensor_count' => 0, 'sensor_min' => 'N/A', 'sensor_max' => 'N/A',
    'inv_count'    => 0, 'inv_min'    => 'N/A', 'inv_max'    => 'N/A',
    'inv_devices'  => 0, 'has_expected' => false, 'db_size' => '0 MB'
];

if (file_exists($dbFile)) {
    $stats['db_size'] = round(filesize($dbFile) / (1024 * 1024), 2) . ' MB';
    try {
        $pdo = new PDO("sqlite:" . $dbFile);$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        $s =$pdo->query("SELECT COUNT(*), MIN(timestamp), MAX(timestamp) FROM sensorbox_history_5min");
        if ($s) {
            $row =$s->fetch(PDO::FETCH_NUM);
            $stats['sensor_count'] = (int)$row[0];
            $stats['sensor_min']   =$row[1] ?? 'N/A';
            $stats['sensor_max']   =$row[2] ?? 'N/A';
        }

        $i =$pdo->query("SELECT COUNT(*), MIN(timestamp), MAX(timestamp), COUNT(DISTINCT device_serial) FROM inverter_history_5min");
        if ($i) {
            $row =$i->fetch(PDO::FETCH_NUM);
            $stats['inv_count']   = (int)$row[0];
            $stats['inv_min']     =$row[1] ?? 'N/A';
            $stats['inv_max']     =$row[2] ?? 'N/A';
            $stats['inv_devices'] = (int)$row[3];
        }

        $exp =$pdo->query("SELECT COUNT(*) FROM expected_monthly");
        if ($exp && $exp->fetchColumn() >= 24) {$stats['has_expected'] = true;
        }
    } catch (Exception $e) {}
}

// 3. Scan Files (.xml, .zip, .7z)
$queueFiles = glob($queueDir . '/*.{zip,7z,xml}', GLOB_BRACE) ?: [];
$processedFiles = glob($processedDir . '/*.{zip,7z,xml}', GLOB_BRACE) ?: [];

$queueBytes = array_sum(array_map('filesize',$queueFiles));
$queueSizeMb = round($queueBytes / (1024 * 1024), 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebBox 17-Year Historical Importer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { background-color: #f8fafc; font-family: system-ui, -apple-system, sans-serif; }
        .card { @apply bg-white p-6 rounded-3xl shadow-sm border border-slate-100 flex flex-col justify-between; }
        .console-log { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        .table-scroll::-webkit-scrollbar { width: 6px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
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
                    <h1 class="text-2xl font-black text-slate-900 tracking-tight">Sunny WebBox Historical Importer</h1>
                    <p class="text-xs text-slate-400 mt-0.5">High-speed 17-year 5-minute raw telemetry ingestion pipeline</p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <?= hp_nav_settings() ?>
            <span class="text-xs font-mono bg-slate-100 text-slate-700 px-3 py-1.5 rounded-full border border-slate-200">
                DB Size: <strong class="text-slate-900"><?= $stats['db_size'] ?></strong>
            </span>
        </div>
    </header>

    <!-- Database Historical Overview -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        <div class="card border-t-4 border-t-cyan-500">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
                        <i data-lucide="cloud-sun" class="w-4 h-4 text-cyan-500"></i> SensorBox 5-Min
                    </span>
                    <span class="text-xs font-mono font-bold px-2 py-0.5 rounded-full bg-cyan-50 text-cyan-700 border border-cyan-200">
                        <?= number_format($stats['sensor_count'], 0, ',', '.') ?> rows
                    </span>
                </div>
                <div class="mt-3 space-y-1 text-xs text-slate-600">
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-400">Earliest:</span>
                        <strong class="font-mono text-slate-800"><?= htmlspecialchars($stats['sensor_min']) ?></strong>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-400">Latest:</span>
                        <strong class="font-mono text-slate-800"><?= htmlspecialchars($stats['sensor_max']) ?></strong>
                    </div>
                </div>
            </div>
            <p class="text-[11px] text-slate-400 mt-3">Solar radiation, module temperature, wind speed, and gusts.</p>
        </div>

        <div class="card border-t-4 border-t-amber-500">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
                        <i data-lucide="zap" class="w-4 h-4 text-amber-500"></i> Inverter 5-Min
                    </span>
                    <span class="text-xs font-mono font-bold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                        <?= number_format($stats['inv_count'], 0, ',', '.') ?> rows
                    </span>
                </div>
                <div class="mt-3 space-y-1 text-xs text-slate-600">
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-400">Serials:</span>
                        <strong class="font-mono text-slate-800"><?= $stats['inv_devices'] ?> device(s) logged</strong>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-400">Latest:</span>
                        <strong class="font-mono text-slate-800"><?= htmlspecialchars($stats['inv_max']) ?></strong>
                    </div>
                </div>
            </div>
            <p class="text-[11px] text-slate-400 mt-3">AC power, Pac surge peaks, DC strings, and E-Total.</p>
        </div>

        <div class="card border-t-4 border-t-emerald-500">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
                        <i data-lucide="target" class="w-4 h-4 text-emerald-500"></i> Monthly Baseline
                    </span>
                    <span class="text-xs font-mono font-bold px-2 py-0.5 rounded-full <?= $stats['has_expected'] ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-500' ?>">
                        <?= $stats['has_expected'] ? 'Ready' : 'Pending' ?>
                    </span>
                </div>
                <div class="mt-3 space-y-1 text-xs text-slate-600">
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-400">Baseline Targets:</span>
                        <strong class="font-mono text-slate-800">24 targets (15° & 30°)</strong>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-400">Target Purpose:</span>
                        <span class="text-slate-700">Degradation & yield checks</span>
                    </div>
                </div>
            </div>
            <p class="text-[11px] text-slate-400 mt-3">PVGIS reference benchmarks loaded and ready for rollups.</p>
        </div>

    </div>

    <!-- Live Execution & Metrics Scoreboard -->
    <div class="card border border-slate-200">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100 mb-4">
            <div>
                <h2 class="text-base font-bold text-slate-900 flex items-center gap-2">
                    <i data-lucide="folder-archive" class="w-5 h-5 text-indigo-600"></i> Local Folder Staging Queue
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">
                    Drop <code>.zip</code>, <code>.7z</code>, or raw <code>.xml</code> files into <code class="bg-slate-100 text-slate-800 px-1.5 py-0.5 rounded font-mono text-[11px]">import_queue/</code>.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-slate-500 font-medium">
                    Queue: <strong class="text-slate-900"><?= count($queueFiles) ?> items</strong> (<?=$queueSizeMb ?> MB)
                </span>
                <button id="startBtn" onclick="runImport()" <?= empty($queueFiles) ? 'disabled' : '' ?> class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white text-xs font-semibold rounded-xl shadow-sm transition-all cursor-pointer">
                    <i data-lucide="play" class="w-3.5 h-3.5"></i> Start Batch Import
                </button>
                <button id="reloadBtn" onclick="window.location.reload()" class="hidden inline-flex items-center gap-2 px-4 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold rounded-xl shadow-sm transition-all cursor-pointer">
                    <i data-lucide="rotate-cw" class="w-3.5 h-3.5"></i> Refresh Stats
                </button>
            </div>
        </div>

        <!-- NEW: Dedicated Progress Bar & Status Tracker -->
        <div id="progressSection" class="hidden bg-slate-50 border border-slate-200/80 rounded-2xl p-4 mb-4 space-y-2">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between text-xs gap-1">
                <div class="flex items-center gap-2">
                    <span class="relative flex h-2.5 w-2.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-indigo-600"></span>
                    </span>
                    <span id="progressFileText" class="font-bold text-slate-900 font-mono text-sm">Processing: 0 / 0 (0.0%)</span>
                </div>
                <div class="flex items-center gap-2 text-slate-500 font-mono text-[11px] truncate">
                    <span>Current file:</span>
                    <span id="progressFileName" class="text-slate-800 font-semibold truncate max-w-sm">Initializing...</span>
                </div>
            </div>
            <!-- Progress Bar -->
            <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
                <div id="progressBar" class="bg-indigo-600 h-full rounded-full transition-all duration-100 ease-out" style="width: 0%"></div>
            </div>
        </div>

        <!-- Live Scoreboard -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <div class="bg-slate-50 p-3 rounded-2xl border border-slate-100">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">SensorBox Rows Added</span>
                <div id="liveSensorIns" class="text-xl font-black text-cyan-600 font-mono mt-0.5">0</div>
            </div>
            <div class="bg-slate-50 p-3 rounded-2xl border border-slate-100">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Inverter Rows Added</span>
                <div id="liveInvIns" class="text-xl font-black text-amber-500 font-mono mt-0.5">0</div>
            </div>
            <div class="bg-slate-50 p-3 rounded-2xl border border-slate-100">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Duplicates Skipped</span>
                <div id="liveDupes" class="text-xl font-black text-slate-600 font-mono mt-0.5">0</div>
            </div>
            <div class="bg-slate-50 p-3 rounded-2xl border border-slate-100">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Errors Encountered</span>
                <div id="liveErrors" class="text-xl font-black text-rose-500 font-mono mt-0.5">0</div>
            </div>
        </div>

        <!-- Terminal Console -->
        <div id="consoleWrapper" class="bg-slate-950 rounded-2xl p-4 border border-slate-800 text-slate-300 console-log text-xs h-64 overflow-y-auto table-scroll">
            <pre id="consoleOutput" class="whitespace-pre-wrap leading-relaxed select-text">Ready. Place archives or raw XML files in import_queue/ and click 'Start Batch Import'...</pre>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between text-xs text-slate-400 pt-2 border-t border-slate-100">
            <span>Processed archive storage: <code>import_queue/processed/</code> (<?= count($processedFiles) ?> items completed)</span>
            <span class="text-emerald-600 font-semibold flex items-center gap-1">
                <i data-lucide="shield-check" class="w-3.5 h-3.5"></i> Duplicate rejection active (INSERT OR IGNORE)
            </span>
        </div>
    </div>

</div>

<script>
    lucide.createIcons();

    let sInsCount = 0;
    let iInsCount = 0;
    let dupCount = 0;
    let errCount = 0;
    const consoleLines = [];
    const MAX_CONSOLE_LINES = 150; // Keeps browser memory fast when parsing tens of thousands of files

    function runImport() {
        const btn = document.getElementById('startBtn');
        const reloadBtn = document.getElementById('reloadBtn');
        const consoleEl = document.getElementById('consoleOutput');
        const progressSection = document.getElementById('progressSection');
        const progressBar = document.getElementById('progressBar');
        const progressFileText = document.getElementById('progressFileText');
        const progressFileName = document.getElementById('progressFileName');

        btn.disabled = true;
        btn.classList.add('opacity-50', 'cursor-not-allowed');
        consoleEl.textContent = "Connecting to Python worker...\n";
        progressSection.classList.remove('hidden');

        const evtSource = new EventSource("import_dashboard.php?action=run");

        function appendLog(line) {
            consoleLines.push(line);
            if (consoleLines.length > MAX_CONSOLE_LINES) {
                consoleLines.shift();
            }
            consoleEl.textContent = consoleLines.join("\n");
            const wrapper = document.getElementById('consoleWrapper');
            wrapper.scrollTop = wrapper.scrollHeight;
        }

        evtSource.onmessage = function(e) {
            try {
                const data = JSON.parse(e.data);

                if (data.type === "start") {
                    progressFileText.textContent = `Processing: 0 / ${data.total_files.toLocaleString()} (0.0%)`;
                    progressBar.style.width = '0%';
                    appendLog(`[${data.timestamp}] ${data.message}`);
                } else if (data.type === "info") {
                    appendLog(`[${data.timestamp}] ${data.message}`);
                } else if (data.type === "progress") {
                    sInsCount += data.s_ins;
                    iInsCount += data.i_ins;
                    dupCount += (data.s_dup + data.i_dup);

                    // Update Scoreboard Cards
                    document.getElementById('liveSensorIns').textContent = sInsCount.toLocaleString();
                    document.getElementById('liveInvIns').textContent = iInsCount.toLocaleString();
                    document.getElementById('liveDupes').textContent = dupCount.toLocaleString();

                    // Update Visual Progress Bar & Status Text
                    const pct = ((data.index / data.total) * 100).toFixed(1);
                    progressBar.style.width = pct + '%';
                    progressFileText.textContent = `Processing: ${data.index.toLocaleString()} / ${data.total.toLocaleString()} (${pct}%)`;
                    progressFileName.textContent = data.file;

                    appendLog(`[${data.timestamp}] [${data.index}/${data.total}] ${data.file} -> Added: ${data.s_ins} sensor, ${data.i_ins} inv | Dupes: ${data.s_dup + data.i_dup}`);
                } else if (data.type === "error") {
                    errCount++;
                    document.getElementById('liveErrors').textContent = errCount.toLocaleString();
                    appendLog(`[${data.timestamp}] [${data.index}/${data.total}] ${data.file} FAILED: ${data.error}`);
                } else if (data.type === "complete") {
                    progressBar.style.width = '100%';
                    progressFileText.textContent = `Completed all ${data.tot_sensor_inserted !== undefined ? 'files' : ''}! (100%)`;
                    progressFileName.textContent = "Done.";

                    appendLog(`\n============================================================`);
                    appendLog(`[${data.timestamp}] IMPORT COMPLETE in ${data.duration}s!`);
                    appendLog(`SensorBox Rows Added:  ${data.tot_sensor_inserted.toLocaleString()}`);
                    appendLog(`SensorBox Duplicates:  ${data.tot_sensor_duplicates.toLocaleString()}`);
                    appendLog(`Inverter Rows Added:   ${data.tot_inverter_inserted.toLocaleString()}`);
                    appendLog(`Inverter Duplicates:   ${data.tot_inverter_duplicates.toLocaleString()}`);
                    appendLog(`Errors Encountered:    ${data.tot_errors}`);
                    appendLog(`All processed files moved to import_queue/processed/`);
                } else if (data.type === "stream_end") {
                    evtSource.close();
                    btn.classList.add('hidden');
                    reloadBtn.classList.remove('hidden');
                }
            } catch (err) {}
        };

        evtSource.onerror = function() {
            evtSource.close();
            btn.classList.add('hidden');
            reloadBtn.classList.remove('hidden');
            appendLog(`\n[INFO] Background process stream closed.`);
        };
    }
</script>

</body>
</html>