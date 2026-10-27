<?php
# --- file: index.php ---
date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/site_settings.php';
require_once __DIR__ . '/live_chart.php';

$hp = hp_boot();
hp_require_ready($hp);
$dbFile = $hp['db'];
$hpSettings = $hp['settings'];
$refreshRate = 60;
$openRawModal = isset($_GET['rawreadings']);

// 1. Action Handler: Force Poll
if (isset($_GET['action']) && $_GET['action'] === 'poll') {
    $pyScript = __DIR__ . '/logger.py';
    if (!file_exists($pyScript)) {
        die("Error: logger.py not found at " . htmlspecialchars($pyScript));
    }

// Prioritize Package Center Python 3.14 before DSM system Python
// Auto-detect Python 3 binary on Synology DSM or Raspberry Pi
    $pythonCandidates = [
        '/var/packages/Python3.14/target/bin/python3',
        '/var/packages/Python3.14/target/bin/python3.14',
        '/var/packages/Python3.14/target/usr/bin/python3',
        '/usr/local/bin/python3.14',
        '/usr/local/bin/python3',
        '/usr/bin/python3',
        'python3'
    ];
    $pyBin = 'python3';
    foreach ($pythonCandidates as $candidate) {
        if (@file_exists($candidate) && @is_executable($candidate)) {
            $pyBin = $candidate;
            break;
        }
    }

    $output = [];
    $returnCode = 0;
    $cmd = "{$pyBin} " . escapeshellarg($pyScript) . " -poll -force 2>&1";
    exec($cmd, $output, $returnCode);

    if ($returnCode === 0) {
        header("Location: index.php");
        exit;
    } else {
        echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Poller Error</title><script src='https://cdn.tailwindcss.com'></script></head><body class='bg-slate-50 p-6 flex items-center justify-center min-h-screen'><div class='max-w-xl w-full bg-white p-6 rounded-2xl shadow border border-slate-200'><h2 class='text-lg font-bold text-red-600 mb-2'>Poller Execution Failed (Exit Code: $returnCode)</h2><p class='text-xs text-slate-500 mb-3'>Command: <code class='bg-slate-100 px-1 py-0.5 rounded'>" . htmlspecialchars($cmd) . "</code></p><pre class='bg-slate-900 text-slate-200 p-4 rounded-xl text-xs font-mono overflow-x-auto whitespace-pre-wrap leading-relaxed'>" . htmlspecialchars(implode("\n", $output)) . "</pre><div class='mt-4 flex justify-end'><a href='index.php' class='px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded-lg'>&larr; Back to Dashboard</a></div></div></body></html>";
        exit;
    }
}

// 2. CSV Streaming
if (isset($_GET['download'])) {
    if (!file_exists($dbFile) || !is_readable($dbFile)) die("Database not found.");

    header('Content-Type: text/csv; charset=UTF-8');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    try {
        $pdo = new PDO("sqlite:" . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($_GET['download'] === 'csv') {
            header('Content-Disposition: attachment; filename="energy_history_' . date('Ymd_His') . '.csv"');
            fputcsv($output, [
                "Timestamp",
                "Net Grid (W)",
                "Solar Power (W)",
                "Import Peak (kWh)",
                "Import Off peak (kWh)",
                "Total Import (kWh)",
                "Export Peak (kWh)",
                "Export Off peak (kWh)",
                "Total Export (kWh)",
                "Capacity Peak (W)",
                "Solar Lifetime (kWh)",
                "Solar Radiation (W/m2)",
                "Module Temp (C)",
                "Wind Speed (m/s)",
                "Gas (m3)",
                "Water (m3)"
            ], ';');

            $stmt = $pdo->query("
                SELECT timestamp, active_power, solar_power,
                       import_t1, import_t2, total_import,
                       export_t1, export_t2, total_export,
                       capacity_peak_w, solar_total_kwh,
                       solar_radiation, temp_module, wind_speed,
                       gas, water
                FROM readings
                ORDER BY id ASC
            ");

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, [
                    date('d/m/Y H:i:s', strtotime($row['timestamp'])),
                    $row['active_power'],
                    $row['solar_power'] ?? 0,
                    number_format((float)$row['import_t1'], 3, ',', ''),
                    number_format((float)$row['import_t2'], 3, ',', ''),
                    ($row['total_import'] !== null) ? number_format((float)$row['total_import'], 3, ',', '') : '',
                    number_format((float)$row['export_t1'], 3, ',', ''),
                    number_format((float)$row['export_t2'], 3, ',', ''),
                    ($row['total_export'] !== null) ? number_format((float)$row['total_export'], 3, ',', '') : '',
                    $row['capacity_peak_w'] ?? '',
                    ($row['solar_total_kwh'] !== null) ? number_format((float)$row['solar_total_kwh'], 2, ',', '') : '',
                    ($row['solar_radiation'] !== null) ? number_format((float)$row['solar_radiation'], 1, ',', '') : '',
                    ($row['temp_module'] !== null) ? number_format((float)$row['temp_module'], 1, ',', '') : '',
                    ($row['wind_speed'] !== null) ? number_format((float)$row['wind_speed'], 1, ',', '') : '',
                    ($row['gas'] !== null) ? number_format((float)$row['gas'], 3, ',', '') : '',
                    ($row['water'] !== null) ? number_format((float)$row['water'], 3, ',', '') : ''
                ], ';');
            }
        } elseif ($_GET['download'] === 'logs') {
            header('Content-Disposition: attachment; filename="system_logs_' . date('Ymd_His') . '.csv"');
            fputcsv($output, ["ID", "First Seen", "Last Seen", "Status", "Count", "Message"], ';');

            $stmt = $pdo->query("SELECT id, first_seen, last_seen, status, count, message FROM system_logs ORDER BY id ASC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, [$row['id'], $row['first_seen'], $row['last_seen'], $row['status'], $row['count'], $row['message']], ';');
            }
        }
    } catch (Exception $e) {}
    fclose($output);
    exit;
}

// Poller and database problems. Missing site config is handled by settings.php.
$fatalError = null;

// 4. Load Data from SQLite
$state = null;
$displayHistory = [];
$chartGrid = [];
$chartSolar = [];
$chartHome = [];
$chartWindow = hp_live_chart_window();
$logHistory = [];

if (!$fatalError) {
    if (file_exists($dbFile) && is_readable($dbFile)) {
        try {
            $pdo = new PDO("sqlite:" . $dbFile);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Read latest snapshot
            $stmt = $pdo->query("SELECT updated_at, data_json FROM latest_state WHERE id = 1");
            $stateRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($stateRow) {
                $state = json_decode($stateRow['data_json'], true);
                $state['updated_at'] = $stateRow['updated_at'];
                if (!empty($state['fatal_error'])) {
                    $fatalError = $state['fatal_error'];
                }
            } else {
                $fatalError = "Waiting for initial poll from logger.py...";
            }

            if (!$fatalError) {
                $stmt = $pdo->query("SELECT timestamp, active_power, solar_power, import_t1, import_t2, export_t1, export_t2, gas, water FROM readings ORDER BY id DESC LIMIT 120");
                $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rawRows as $r) {
                    $sol = $r['solar_power'] ?? 0;
                    $displayHistory[] = [
                        date('d/m/Y H:i:s', strtotime($r['timestamp'])),
                        $r['active_power'],
                        $sol,
                        $r['import_t1'], $r['import_t2'], $r['export_t1'], $r['export_t2'], $r['gas'], $r['water']
                    ];
                }

                $chartStmt = $pdo->prepare(
                    'SELECT timestamp, active_power, solar_power FROM readings WHERE timestamp >= ? AND timestamp <= ? ORDER BY timestamp ASC'
                );
                $chartStmt->execute([
                    date('Y-m-d H:i:s', $chartWindow['start']),
                    date('Y-m-d H:i:s', $chartWindow['end']),
                ]);
                $chartBuilt = hp_live_chart_series($chartStmt->fetchAll(PDO::FETCH_ASSOC));
                $chartHome = $chartBuilt['home'];
                $chartSolar = $chartBuilt['solar'];
                $chartGrid = $chartBuilt['grid'];

                $stmt = $pdo->query("SELECT id, first_seen, last_seen, status, count, message FROM system_logs ORDER BY id DESC LIMIT 50");
                $logHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

        } catch (Exception $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'no such table') !== false) {
                $fatalError = "Waiting for initial poll from logger.py...";
            } else {
                $fatalError = "Database error: " . $msg;
            }
        }
    } else {
        $fatalError = "Database file not found at " . htmlspecialchars($dbFile) . ". Run logger.py -poll to initialize.";
    }
}

// Helpers & Unpack state
$p1 = $state['p1_data'] ?? null;
$sma = $state['sma_data'] ?? null;
$webbox = $state['webbox_data'] ?? null;
$calc = $state['calculated'] ?? null;
$alerts = $state['alerts'] ?? [];
$hasSolar = !empty($state['has_solar']);
$hasSma = !empty($state['has_sma']);
$hasWebbox = !empty($state['has_webbox']);
$webboxDevices = $webbox['devices'] ?? [];
$sensorbox = $webbox['sensorbox'] ?? null;

// Dynamic Water & Gas presence check
$hasWater = false;
$rawWater = null;
$hasGas = false;
$rawGas = null;

if ($p1 && isset($p1['external']) && is_array($p1['external'])) {
    foreach ($p1['external'] as $ext) {
        if ($ext['type'] === 'gas_meter') {
            $hasGas = true;
            $rawGas = (float)$ext['value'];
        }
        if ($ext['type'] === 'water_meter') {
            $hasWater = true;
            $rawWater = (float)$ext['value'];
        }
    }
}

function num($val, $decimals = 2) {
    if (is_string($val)) $val = str_replace(',', '.', $val);
    return is_numeric($val) ? number_format((float)$val, $decimals, ',', '.') : '0,00';
}
function formatRawParts($val, $decimals = 3) {
    $str = number_format((float)$val, $decimals, ',', '.');
    if ($decimals > 0) {
        $parts = explode(',', $str);
        return ['int' => $parts[0], 'dec' => ',' . $parts[1]];
    }
    return ['int' => $str, 'dec' => ''];
}
function formatDsmrTime($ts) {
    if (!$ts) return "N/A";
    $ts_str = (string)$ts;
    if (strlen($ts_str) < 12) return $ts;
    return substr($ts_str, 4, 2) . '/' . substr($ts_str, 2, 2) . '/20' . substr($ts_str, 0, 2) . ' ' . substr($ts_str, 6, 2) . ':' . substr($ts_str, 8, 2) . ':' . substr($ts_str, 10, 2);
}

// Badge Freshness
$p1Age = isset($state['updated_at']) ? (time() - strtotime($state['updated_at'])) : 9999;
$p1Status = ($p1Age <= 180) ? 'Online' : 'Offline';

$badgeClass = ($p1Status === 'Online') ? "bg-emerald-50 text-emerald-700 border-emerald-200" : "bg-rose-50 text-rose-700 border-rose-200";
$badgeDot = ($p1Status === 'Online') ? "bg-emerald-500" : "bg-rose-500";
$badgeText = ($state && isset($state['updated_at'])) ? "Last poll: " . date('H:i:s', strtotime($state['updated_at'])) : "Last poll: N/A";

// Presentation strings from settings (generic defaults when a title was left blank).
$siteTitle = trim($hpSettings['site_title']) !== '' ? $hpSettings['site_title'] : 'Energy Dashboard';
$siteTagline = trim($hpSettings['site_tagline']) !== '' ? $hpSettings['site_tagline'] : 'Home Energy Hub';
$pageTitle = trim($hpSettings['site_page_title']) !== '' ? $hpSettings['site_page_title'] : $siteTitle;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        body { background-color: #f8fafc; font-family: system-ui, -apple-system, sans-serif; }
        .card { @apply bg-white p-5 rounded-2xl shadow-sm border border-slate-100 flex flex-col; }
        .card-title { @apply text-slate-500 text-xs font-bold uppercase tracking-wider mb-4 flex items-center gap-2; }
        .table-scroll::-webkit-scrollbar { width: 8px; height: 8px; }
        .table-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    </style>
</head>
<body class="p-4 md:p-8">

<?php if ($fatalError): ?>
    <!-- FATAL ERROR ONLY: Foundation Failure Screen -->
    <div class="flex items-center justify-center min-h-[85vh]">
        <div class="max-w-xl w-full bg-white p-8 rounded-3xl shadow-sm border border-slate-200 text-center space-y-4">
            <div class="w-14 h-14 bg-rose-50 text-rose-600 rounded-2xl flex items-center justify-center mx-auto">
                <i data-lucide="alert-octagon" class="w-8 h-8"></i>
            </div>
            <h2 class="text-xl font-bold text-slate-900">P1 System Unavailable</h2>
            <div class="text-sm text-slate-700 bg-rose-50/70 border border-rose-200 p-4 rounded-2xl font-mono text-left whitespace-pre-wrap"><?= htmlspecialchars($fatalError) ?></div>
            <p class="text-xs text-slate-400">P1 smart meter data is required to operate the energy dashboard.</p>

            <div class="pt-2 flex items-center justify-center gap-3 flex-wrap">
                <!-- Button to trigger logger.py -->
                <a href="index.php?action=poll" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded-xl shadow-sm transition-all cursor-pointer">
                    <i data-lucide="play" class="w-3.5 h-3.5"></i> Run Poller Now (logger.py)
                </a>
                <!-- Button to reload page -->
                <a href="index.php" class="inline-flex items-center gap-2 px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-xl transition-all cursor-pointer">
                    <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> Reload Page
                </a>
            </div>
        </div>
    </div>
    <script>lucide.createIcons();</script>
</body>
</html>
<?php exit; endif; ?>

<!-- HEALTHY DASHBOARD -->
<div class="max-w-7xl mx-auto space-y-6">

    <!-- Header -->
    <header class="flex flex-col xl:flex-row xl:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl md:text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                <i data-lucide="zap" class="w-8 h-8 text-indigo-600"></i>
                <?= htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8') ?>
            </h1>
            <p class="text-slate-500 text-sm mt-0.5">
                <?= htmlspecialchars($siteTagline, ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

        <div class="flex items-center flex-wrap gap-3">
            <a href="analytics.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="line-chart" class="w-3.5 h-3.5 text-indigo-600"></i> Analytics
            </a>
            <a href="summary.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="calendar-range" class="w-3.5 h-3.5 text-emerald-600"></i> Summary
            </a>
            <a href="topn.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="trophy" class="w-3.5 h-3.5 text-amber-500"></i> Top-N
            </a>
            <?= hp_nav_settings() ?>
            <button type="button" onclick="openRawModal()" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all cursor-pointer">
                <i data-lucide="table-properties" class="w-3.5 h-3.5 text-blue-600"></i> Raw readings
            </button>
            <a href="?action=poll" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-blue-50 hover:text-blue-600 shadow-sm transition-all">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-slate-500"></i> Poll now
            </a>
            <div class="flex items-center gap-2.5 bg-white border border-slate-200 px-3.5 py-1.5 rounded-full shadow-sm text-sm">
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" id="autoRefreshToggle" class="sr-only peer" checked>
                    <div class="w-8 h-4 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
                <span class="text-xs font-semibold text-slate-600 select-none">Auto-refresh</span>
                <span id="countdownBadge" class="inline-flex items-center justify-center min-w-[28px] px-1.5 py-0.5 rounded text-xs font-mono font-bold bg-blue-50 text-blue-600 border border-blue-200"><?= (int)$refreshRate ?>s</span>
            </div>

            <!-- Header Badge -->
            <div class="flex items-center gap-2 text-xs border px-3 py-1.5 rounded-full font-semibold shadow-sm cursor-help <?= $badgeClass ?>" title="Page loaded at <?= date('H:i:s') ?>">
                <span class="relative flex h-2.5 w-2.5">
                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full <?= $badgeDot ?> opacity-75"></span>
                  <span class="relative inline-flex rounded-full h-2.5 w-2.5 <?= $badgeDot ?>"></span>
                </span>
                <?= $badgeText ?>
            </div>
        </div>
    </header>

    <!-- Top Alert Banners (Inverter Auth Warnings) -->
    <?php if (!empty($alerts)): ?>
        <div class="space-y-2">
            <?php foreach ($alerts as $alt): ?>
                <div class="bg-rose-50 border-l-4 border-rose-500 p-3.5 rounded-r-xl shadow-sm text-rose-800 flex items-center justify-between text-xs sm:text-sm font-semibold">
                    <div class="flex items-center gap-2.5">
                        <i data-lucide="alert-triangle" class="w-4 h-4 text-rose-600 flex-shrink-0"></i>
                        <span><?= htmlspecialchars($alt) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($hasSolar): ?>
    <!-- WITH SOLAR: Three-Pillar Hero -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- 1. Solar Generation -->
        <div class="card border-t-4 border-t-amber-400 justify-between">
            <h2 class="card-title"><i data-lucide="sun" class="w-4 h-4 text-amber-500"></i> Solar Generation</h2>
            <div class="flex flex-col items-center justify-center py-4">
                <div class="text-5xl font-black tracking-tight text-amber-500">
                    <?= num($calc['total_solar_w'], 0) ?> <span class="text-2xl font-normal text-slate-400">W</span>
                </div>
                <div class="mt-2 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700">
                    <i data-lucide="sun" class="w-3.5 h-3.5"></i> Active Generation
                </div>
            </div>
            <div class="pt-3 border-t border-slate-100 flex justify-between text-xs text-slate-500">
                <span>Today's Yield:</span>
                <strong class="text-slate-800"><?= num($calc['today_solar_kwh'], 2) ?> kWh</strong>
            </div>
        </div>

        <!-- 2. Home Consumption -->
        <div class="card border-t-4 border-t-indigo-500 justify-between">
            <h2 class="card-title"><i data-lucide="home" class="w-4 h-4 text-indigo-500"></i> Home Consumption</h2>
            <div class="flex flex-col items-center justify-center py-4">
                <div class="text-5xl font-black tracking-tight text-indigo-600">
                    <?= num($calc['home_consumption_w'], 0) ?> <span class="text-2xl font-normal text-slate-400">W</span>
                </div>
                <?php
                    $selfConsump = min($calc['total_solar_w'], $calc['home_consumption_w']);
                    $selfPct = $calc['home_consumption_w'] > 0 ? ($selfConsump / $calc['home_consumption_w']) * 100 : 0;
                ?>
                <div class="mt-2 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700">
                    <i data-lucide="zap" class="w-3.5 h-3.5"></i>
                    <?= num($selfPct, 0) ?>% Solar powered
                </div>
            </div>
            <div class="pt-3 border-t border-slate-100 flex justify-between text-xs text-slate-500">
                <span>Direct self-consumption:</span>
                <strong class="text-slate-800"><?= num($selfConsump, 0) ?> W</strong>
            </div>
        </div>

        <!-- 3. Net Grid Flow -->
        <div class="card border-t-4 border-t-blue-500 justify-between">
            <h2 class="card-title"><i data-lucide="arrow-down-up" class="w-4 h-4 text-blue-500"></i> Net Grid Flow</h2>
            <div class="flex flex-col items-center justify-center py-4">
                <?php $isExporting = $p1['active_power_w'] < 0; ?>
                <div class="text-5xl font-black tracking-tight <?= $isExporting ? 'text-emerald-600' : 'text-blue-600' ?>">
                    <?= num(abs($p1['active_power_w']), 0) ?> <span class="text-2xl font-normal text-slate-400">W</span>
                </div>
                <div class="mt-2 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?= $isExporting ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700' ?>">
                    <i data-lucide="<?= $isExporting ? 'arrow-down-left' : 'arrow-up-right' ?>" class="w-3.5 h-3.5"></i>
                    <?= $isExporting ? 'Injecting surplus' : 'Importing from grid' ?>
                </div>
            </div>
            <div class="pt-3 border-t border-slate-100 flex justify-between text-xs text-slate-500">
                <span>Active Tariff:</span>
                <strong class="text-slate-800"><?= $p1['active_tariff'] == 1 ? 'Peak' : 'Off-peak' ?></strong>
            </div>
        </div>
    </div>

    <!-- Row 2 (With Solar): Capacity & Phases -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="card border-t-4 border-t-amber-600 justify-between">
            <h2 class="card-title"><i data-lucide="shield-alert" class="w-4 h-4 text-amber-600"></i> Capacity Peak (Flanders)</h2>
            <div class="relative flex items-center justify-center -my-3">
                <div id="capacityGaugeChart" class="w-full"></div>
            </div>
            <div class="pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400">
                <span class="flex items-center gap-1"><i data-lucide="calendar" class="w-3.5 h-3.5"></i> Peak set on:</span>
                <span><?= formatDsmrTime($p1['montly_power_peak_timestamp']) ?></span>
            </div>
        </div>

        <div class="card border-t-4 border-t-indigo-400 justify-between md:col-span-2">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <h2 class="card-title !mb-0"><i data-lucide="sliders" class="w-4 h-4 text-indigo-500"></i> Phase Load Balance</h2>
                    <span class="text-[10px] uppercase font-bold text-slate-400">Export ← | → Import</span>
                </div>
                <div class="space-y-3.5 mt-3">
                    <?php
                    $phases = [
                        ['id' => 'L1', 'w' => $p1['active_power_l1_w'], 'v' => $p1['active_voltage_l1_v'], 'a' => $p1['active_current_l1_a']],
                        ['id' => 'L2', 'w' => $p1['active_power_l2_w'], 'v' => $p1['active_voltage_l2_v'], 'a' => $p1['active_current_l2_a']],
                        ['id' => 'L3', 'w' => $p1['active_power_l3_w'], 'v' => $p1['active_voltage_l3_v'], 'a' => $p1['active_current_l3_a']],
                    ];
                    $maxPhaseW = max(3000, abs($phases[0]['w']), abs($phases[1]['w']), abs($phases[2]['w']));
                    foreach ($phases as $p):
                        $isNeg = $p['w'] < 0;
                        $pct = min(50, (abs($p['w']) / $maxPhaseW) * 50);
                    ?>
                    <div>
                        <div class="flex justify-between text-xs font-semibold mb-1">
                            <span class="text-slate-700"><?= $p['id'] ?> <span class="text-slate-400 font-normal">(<?= num($p['v'], 0) ?>V · <?= num($p['a'], 1) ?>A)</span></span>
                            <span class="<?= $isNeg ? 'text-emerald-600' : 'text-slate-900' ?>"><?= num($p['w'], 0) ?> W</span>
                        </div>
                        <div class="relative w-full h-3 bg-slate-100 rounded-full overflow-hidden flex">
                            <div class="absolute left-1/2 top-0 bottom-0 w-[2px] bg-slate-300 z-10 -ml-[1px]"></div>
                            <div class="w-1/2 h-full flex justify-end">
                                <?php if($isNeg): ?><div class="h-full bg-emerald-500 rounded-l-full transition-all" style="width: <?= $pct * 2 ?>%;"></div><?php endif; ?>
                            </div>
                            <div class="w-1/2 h-full flex justify-start">
                                <?php if(!$isNeg): ?><div class="h-full bg-blue-500 rounded-r-full transition-all" style="width: <?= $pct * 2 ?>%;"></div><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Total Current on Left, Total Power aligned under Watts on Right -->
            <div class="pt-3 mt-3 border-t border-slate-100 flex justify-between items-center text-xs">
                <span class="text-slate-500">
                    Total Current: <strong class="text-slate-800 font-mono"><?= num($p1['active_current_a'], 2) ?> A</strong>
                </span>
                <span class="text-slate-500">
                    Total Power:
                    <strong class="font-mono text-sm <?= ($p1['active_power_w'] < 0) ? 'text-emerald-600' : 'text-slate-900' ?>">
                        <?= num($p1['active_power_w'], 0) ?> W
                    </strong>
                </span>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- P1-ONLY SITE: Original Top Row -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div class="card border-t-4 border-t-blue-500 justify-between">
            <h2 class="card-title"><i data-lucide="activity" class="w-4 h-4 text-blue-500"></i> Real-Time Load</h2>
            <div class="flex flex-col items-center justify-center py-4">
                <?php $isExporting = $p1['active_power_w'] < 0; ?>
                <div class="text-5xl font-black tracking-tight <?= $isExporting ? 'text-emerald-600' : 'text-blue-600' ?>">
                    <?= num(abs($p1['active_power_w']), 0) ?> <span class="text-2xl font-normal text-slate-400">W</span>
                </div>
                <div class="mt-2 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?= $isExporting ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700' ?>">
                    <i data-lucide="<?= $isExporting ? 'arrow-down-left' : 'arrow-up-right' ?>" class="w-3.5 h-3.5"></i>
                    <?= $isExporting ? 'Injecting surplus' : 'Importing from grid' ?>
                </div>
            </div>
            <div class="pt-3 border-t border-slate-100 grid grid-cols-2 gap-4 text-center">
                <div>
                    <span class="text-[11px] text-slate-400 font-bold uppercase">Current Tariff</span>
                    <p class="font-bold text-slate-800 mt-0.5"><?= $p1['active_tariff'] == 1 ? 'Peak' : 'Off-peak' ?></p>
                </div>
                <div>
                    <span class="text-[11px] text-slate-400 font-bold uppercase">Grid Voltage Avg</span>
                    <p class="font-bold text-slate-800 mt-0.5"><?= num(($p1['active_voltage_l1_v'] + $p1['active_voltage_l2_v'] + $p1['active_voltage_l3_v']) / 3, 1) ?> V</p>
                </div>
            </div>
        </div>

        <div class="card border-t-4 border-t-amber-600 justify-between">
            <h2 class="card-title"><i data-lucide="shield-alert" class="w-4 h-4 text-amber-600"></i> Capacity Peak (Flanders)</h2>
            <div class="relative flex items-center justify-center -my-3">
                <div id="capacityGaugeChart" class="w-full"></div>
            </div>
            <div class="pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400">
                <span class="flex items-center gap-1"><i data-lucide="calendar" class="w-3.5 h-3.5"></i> Peak set on:</span>
                <span><?= formatDsmrTime($p1['montly_power_peak_timestamp']) ?></span>
            </div>
        </div>

        <div class="card border-t-4 border-t-indigo-400 justify-between md:col-span-2 lg:col-span-1">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <h2 class="card-title !mb-0"><i data-lucide="sliders" class="w-4 h-4 text-indigo-500"></i> Phase Load Balance</h2>
                    <span class="text-[10px] uppercase font-bold text-slate-400">Export ← | → Import</span>
                </div>
                <div class="space-y-3.5 mt-3">
                    <?php
                    $phases = [
                        ['id' => 'L1', 'w' => $p1['active_power_l1_w'], 'v' => $p1['active_voltage_l1_v'], 'a' => $p1['active_current_l1_a']],
                        ['id' => 'L2', 'w' => $p1['active_power_l2_w'], 'v' => $p1['active_voltage_l2_v'], 'a' => $p1['active_current_l2_a']],
                        ['id' => 'L3', 'w' => $p1['active_power_l3_w'], 'v' => $p1['active_voltage_l3_v'], 'a' => $p1['active_current_l3_a']],
                    ];
                    $maxPhaseW = max(3000, abs($phases[0]['w']), abs($phases[1]['w']), abs($phases[2]['w']));
                    foreach ($phases as $p):
                        $isNeg = $p['w'] < 0;
                        $pct = min(50, (abs($p['w']) / $maxPhaseW) * 50);
                    ?>
                    <div>
                        <div class="flex justify-between text-xs font-semibold mb-1">
                            <span class="text-slate-700"><?= $p['id'] ?> <span class="text-slate-400 font-normal">(<?= num($p['v'], 0) ?>V · <?= num($p['a'], 1) ?>A)</span></span>
                            <span class="<?= $isNeg ? 'text-emerald-600' : 'text-slate-900' ?>"><?= num($p['w'], 0) ?> W</span>
                        </div>
                        <div class="relative w-full h-3 bg-slate-100 rounded-full overflow-hidden flex">
                            <div class="absolute left-1/2 top-0 bottom-0 w-[2px] bg-slate-300 z-10 -ml-[1px]"></div>
                            <div class="w-1/2 h-full flex justify-end">
                                <?php if($isNeg): ?><div class="h-full bg-emerald-500 rounded-l-full transition-all" style="width: <?= $pct * 2 ?>%;"></div><?php endif; ?>
                            </div>
                            <div class="w-1/2 h-full flex justify-start">
                                <?php if(!$isNeg): ?><div class="h-full bg-blue-500 rounded-r-full transition-all" style="width: <?= $pct * 2 ?>%;"></div><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Total Current on Left, Total Power aligned under Watts on Right -->
            <div class="pt-3 mt-3 border-t border-slate-100 flex justify-between items-center text-xs">
                <span class="text-slate-500">
                    Total Current: <strong class="text-slate-800 font-mono"><?= num($p1['active_current_a'], 2) ?> A</strong>
                </span>
                <span class="text-slate-500">
                    Total Power:
                    <strong class="font-mono text-sm <?= ($p1['active_power_w'] < 0) ? 'text-emerald-600' : 'text-slate-900' ?>">
                        <?= num($p1['active_power_w'], 0) ?> W
                    </strong>
                </span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Grid Totals & Utility Meters Row -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

        <!-- Grid Totals Card (Properly separated Import & Export blocks with independent totals) -->
        <div class="card border-t-4 border-t-purple-500 justify-between">
            <div>
                <h2 class="card-title"><i data-lucide="arrow-down-up" class="w-4 h-4 text-purple-500"></i> Grid Totals</h2>

                <!-- Import Block -->
                <div class="mb-4">
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Import (Consumption)</div>
                    <div class="flex justify-between items-center py-1 border-b border-slate-50 text-xs">
                        <span class="text-slate-600 font-medium">Peak</span>
                        <span class="font-bold text-slate-900"><?= num($p1['total_power_import_t1_kwh']) ?> <span class="text-[10px] text-slate-400 font-normal">kWh</span></span>
                    </div>
                    <div class="flex justify-between items-center py-1 border-b border-slate-50 text-xs">
                        <span class="text-slate-600 font-medium">Off peak</span>
                        <span class="font-bold text-slate-900"><?= num($p1['total_power_import_t2_kwh']) ?> <span class="text-[10px] text-slate-400 font-normal">kWh</span></span>
                    </div>
                    <div class="flex justify-between items-center py-1.5 text-xs bg-slate-50/70 px-2 rounded mt-1">
                        <span class="text-slate-700 font-bold">Total Import</span>
                        <span class="font-bold text-slate-900"><?= num($p1['total_power_import_kwh']) ?> <span class="text-[10px] text-slate-400 font-normal">kWh</span></span>
                    </div>
                </div>

                <!-- Export Block -->
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Export (Injection)</div>
                    <div class="flex justify-between items-center py-1 border-b border-slate-50 text-xs">
                        <span class="text-slate-600 font-medium">Peak</span>
                        <span class="font-bold text-emerald-600"><?= num($p1['total_power_export_t1_kwh']) ?> <span class="text-[10px] text-slate-400 font-normal">kWh</span></span>
                    </div>
                    <div class="flex justify-between items-center py-1 border-b border-slate-50 text-xs">
                        <span class="text-slate-600 font-medium">Off peak</span>
                        <span class="font-bold text-emerald-600"><?= num($p1['total_power_export_t2_kwh']) ?> <span class="text-[10px] text-slate-400 font-normal">kWh</span></span>
                    </div>
                    <div class="flex justify-between items-center py-1.5 text-xs bg-emerald-50/40 px-2 rounded mt-1">
                        <span class="text-emerald-700 font-bold">Total Export</span>
                        <span class="font-bold text-emerald-600"><?= num($p1['total_power_export_kwh']) ?> <span class="text-[10px] text-slate-400 font-normal">kWh</span></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-t-4 border-t-blue-400">
            <h2 class="card-title"><i data-lucide="pie-chart" class="w-4 h-4 text-blue-500"></i> Import Split</h2>
            <div id="importDonutChart" class="flex-grow flex items-center justify-center"></div>
            <div class="pt-3 border-t border-slate-100 grid grid-cols-2 text-center text-xs">
                <div class="border-r border-slate-100 pr-1">
                    <span class="text-slate-400">Peak</span>
                    <p class="font-bold text-slate-800 text-[11px]"><?= num($p1['total_power_import_t1_kwh'], 1) ?> kWh</p>
                </div>
                <div class="pl-1">
                    <span class="text-slate-400">Off peak</span>
                    <p class="font-bold text-slate-800 text-[11px]"><?= num($p1['total_power_import_t2_kwh'], 1) ?> kWh</p>
                </div>
            </div>
        </div>

        <div class="card border-t-4 border-t-emerald-400">
            <h2 class="card-title"><i data-lucide="pie-chart" class="w-4 h-4 text-emerald-500"></i> Export Split</h2>
            <div id="exportDonutChart" class="flex-grow flex items-center justify-center"></div>
            <div class="pt-3 border-t border-slate-100 grid grid-cols-2 text-center text-xs">
                <div class="border-r border-slate-100 pr-1">
                    <span class="text-slate-400">Peak</span>
                    <p class="font-bold text-emerald-600 text-[11px]"><?= num($p1['total_power_export_t1_kwh'], 1) ?> kWh</p>
                </div>
                <div class="pl-1">
                    <span class="text-slate-400">Off peak</span>
                    <p class="font-bold text-emerald-600 text-[11px]"><?= num($p1['total_power_export_t2_kwh'], 1) ?> kWh</p>
                </div>
            </div>
        </div>

        <div class="card border-t-4 border-t-cyan-500 justify-between">
            <h2 class="card-title"><i data-lucide="droplets" class="w-4 h-4 text-cyan-500"></i> Utility Meters</h2>
            <div class="space-y-3 flex-grow flex flex-col justify-center">
                <?php if (isset($p1['external']) && is_array($p1['external'])): ?>
                    <?php foreach ($p1['external'] as $ext): ?>
                        <div class="bg-slate-50 p-3 rounded-xl border border-slate-100 flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <?php if($ext['type'] === 'gas_meter'): ?>
                                    <div class="w-8 h-8 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center"><i data-lucide="flame" class="w-4 h-4"></i></div>
                                    <div><div class="text-xs font-bold text-slate-800">Gas</div><div class="text-[9px] text-slate-400"><?= formatDsmrTime($ext['timestamp']) ?></div></div>
                                <?php elseif($ext['type'] === 'water_meter'): ?>
                                    <div class="w-8 h-8 rounded-lg bg-cyan-100 text-cyan-600 flex items-center justify-center"><i data-lucide="droplet" class="w-4 h-4"></i></div>
                                    <div><div class="text-xs font-bold text-slate-800">Water</div><div class="text-[9px] text-slate-400"><?= formatDsmrTime($ext['timestamp']) ?></div></div>
                                <?php endif; ?>
                            </div>
                            <div class="text-right">
                                <span class="font-extrabold text-sm text-slate-900"><?= num($ext['value'], 3) ?></span>
                                <span class="text-[10px] text-slate-400 font-medium ml-0.5"><?= htmlspecialchars($ext['unit']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="pt-3 border-t border-slate-100 text-[11px] text-slate-400 text-center">External MBus telemetry</div>
        </div>
    </div>

    <!-- History Chart -->
    <div class="card border-t-4 border-t-slate-800">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-2">
            <div>
                <h2 class="card-title !mb-0">
                    <i data-lucide="trending-up" class="w-4 h-4 text-slate-700"></i>
                    <?= $hasSolar ? "Solar & Consumption Profile" : "Power History" ?>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">
                    <?= $hasSolar ? "Real-time area correlation (Amber area above Indigo line = 100% self-sufficient + export)" : "Real-time net grid profile with zero-line crossing" ?>
                </p>
            </div>
            <div class="flex items-center gap-3 text-xs">
                <?php if ($hasSolar): ?>
                    <span class="inline-flex items-center gap-1.5 text-indigo-600 font-medium"><span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span> Home Load (W)</span>
                    <span class="inline-flex items-center gap-1.5 text-amber-500 font-medium"><span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span> Solar Output (W)</span>
                <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 text-blue-600 font-medium"><span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span> Grid Import (> 0 W)</span>
                    <span class="inline-flex items-center gap-1.5 text-emerald-600 font-medium"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Solar Export (< 0 W)</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-1.5 mb-2" role="group" aria-label="Chart range">
            <?php foreach (hp_live_chart_presets() as $presetId => $preset): ?>
                <?php $presetOn = !$chartWindow['custom'] && $chartWindow['preset'] === $presetId; ?>
                <a href="?range=<?= htmlspecialchars($presetId, ENT_QUOTES, 'UTF-8') ?>" class="px-2.5 py-1 rounded-full text-[11px] font-semibold border <?= $presetOn ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200' ?>"><?= htmlspecialchars($preset['label'], ENT_QUOTES, 'UTF-8') ?></a>
            <?php endforeach; ?>
            <?php if ($chartWindow['custom']): ?>
                <span class="text-[11px] text-slate-400">Custom range</span>
            <?php endif; ?>
        </div>
        <div id="historyTimelineChart" class="w-full h-[280px]"></div>
    </div>

    <!-- Telemetry Table + CSV Export -->
    <div class="card border border-slate-200">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="card-title !mb-0"><i data-lucide="table" class="w-4 h-4 text-slate-600"></i> Detailed Telemetry Log</h2>
                <span class="text-xs text-slate-400">Stored in SQLite by background worker</span>
            </div>
            <a href="?download=csv" class="inline-flex items-center justify-center gap-2 bg-slate-800 hover:bg-slate-900 text-white px-4 py-2 rounded-lg text-xs font-semibold transition-all shadow-sm">
                <i data-lucide="download" class="w-3.5 h-3.5"></i> Download CSV
            </a>
        </div>

        <div class="table-scroll overflow-x-auto overflow-y-auto max-h-[320px] rounded-xl border border-slate-100 bg-white">
            <table class="w-full text-left border-collapse whitespace-nowrap">
                <thead class="sticky top-0 bg-slate-50 border-b border-slate-200 z-10 text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="py-2.5 px-3.5 font-bold">Timestamp</th>
                        <th class="py-2.5 px-3.5 font-bold text-right">Net Grid (W)</th>
                        <?php if ($hasSolar): ?><th class="py-2.5 px-3.5 font-bold text-right text-amber-600">Solar (W)</th><?php endif; ?>
                        <th class="py-2.5 px-3.5 font-bold text-right">Import Peak</th>
                        <th class="py-2.5 px-3.5 font-bold text-right">Import Off</th>
                        <th class="py-2.5 px-3.5 font-bold text-right">Export Peak</th>
                        <th class="py-2.5 px-3.5 font-bold text-right">Export Off</th>
                        <th class="py-2.5 px-3.5 font-bold text-right">Gas</th>
                        <?php if ($hasWater): ?><th class="py-2.5 px-3.5 font-bold text-right">Water</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody class="text-xs text-slate-600 divide-y divide-slate-100 font-mono">
                    <?php if (!empty($displayHistory)): ?>
                        <?php foreach ($displayHistory as $index => $row): ?>
                            <tr class="hover:bg-slate-50 transition-colors <?= $index === 0 ? 'bg-blue-50/20' : '' ?>">
                                <td class="py-2 px-3.5 text-slate-500 font-sans"><?= htmlspecialchars($row[0]) ?></td>
                                <td class="py-2 px-3.5 text-right font-bold <?= ($row[1] < 0) ? 'text-emerald-600' : 'text-blue-600' ?>">
                                    <?= num($row[1], 0) ?>
                                </td>
                                <?php if ($hasSolar): ?><td class="py-2 px-3.5 text-right font-bold text-amber-500"><?= num($row[2], 0) ?></td><?php endif; ?>
                                <td class="py-2 px-3.5 text-right"><?= num($row[3]) ?></td>
                                <td class="py-2 px-3.5 text-right"><?= num($row[4]) ?></td>
                                <td class="py-2 px-3.5 text-right <?= ($row[5] > 0) ? 'text-emerald-600 font-medium' : '' ?>"><?= num($row[5]) ?></td>
                                <td class="py-2 px-3.5 text-right <?= ($row[6] > 0) ? 'text-emerald-600 font-medium' : '' ?>"><?= num($row[6]) ?></td>
                                <td class="py-2 px-3.5 text-right"><?= ($row[7] !== null) ? num($row[7], 3) : '-' ?></td>
                                <?php if ($hasWater): ?>
                                <td class="py-2 px-3.5 text-right"><?= ($row[8] !== null) ? num($row[8], 3) : '-' ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="<?= 7 + ($hasSolar ? 1 : 0) + ($hasWater ? 1 : 0) ?>" class="text-center py-6 text-slate-400 font-sans italic">Waiting for logger.py to record data...</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- NEW SECTION: Sunny WebBox Connected Devices & SensorBox -->
    <?php if ($hasWebbox && (!empty($webboxDevices) || !empty($sensorbox))): ?>
    <div class="grid grid-cols-1 <?= !empty($sensorbox) ? 'lg:grid-cols-3' : '' ?> gap-6">

        <?php if (!empty($sensorbox)): ?>
        <!-- SensorBox Environmental Station Card -->
        <div class="card border-t-4 border-t-cyan-500 justify-between">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h2 class="card-title !mb-0">
                        <i data-lucide="cloud-sun" class="w-4 h-4 text-cyan-500"></i>
                        Sunny SensorBox
                    </h2>
                    <span class="text-[10px] font-mono bg-cyan-50 text-cyan-700 border border-cyan-200 px-2 py-0.5 rounded-full font-bold">
                        <?= htmlspecialchars($sensorbox['key']) ?>
                    </span>
                </div>

                <div class="grid grid-cols-3 gap-3 my-3">
                    <!-- Solar Radiation -->
                    <div class="bg-slate-50 p-3 rounded-xl border border-slate-100 flex flex-col justify-between">
                        <span class="text-[10px] uppercase font-bold text-slate-400 flex items-center gap-1">
                            <i data-lucide="sun" class="w-3 h-3 text-amber-500"></i> Radiation
                        </span>
                        <div class="mt-2">
                            <span class="text-xl md:text-2xl font-black text-slate-900 font-mono"><?= num($sensorbox['radiation'] ?? 0, 0) ?></span>
                            <span class="text-[10px] text-slate-400 font-normal">W/m²</span>
                        </div>
                    </div>

                    <!-- Module Temperature -->
                    <div class="bg-slate-50 p-3 rounded-xl border border-slate-100 flex flex-col justify-between">
                        <span class="text-[10px] uppercase font-bold text-slate-400 flex items-center gap-1">
                            <i data-lucide="thermometer" class="w-3 h-3 text-rose-500"></i> Module
                        </span>
                        <div class="mt-2">
                            <span class="text-xl md:text-2xl font-black text-slate-900 font-mono"><?= num($sensorbox['temp_module'] ?? 0, 1) ?></span>
                            <span class="text-[10px] text-slate-400 font-normal">°C</span>
                        </div>
                    </div>

                    <!-- Wind Speed -->
                    <div class="bg-slate-50 p-3 rounded-xl border border-slate-100 flex flex-col justify-between">
                        <span class="text-[10px] uppercase font-bold text-slate-400 flex items-center gap-1">
                            <i data-lucide="wind" class="w-3 h-3 text-sky-500"></i> Wind
                        </span>
                        <div class="mt-2">
                            <span class="text-xl md:text-2xl font-black text-slate-900 font-mono"><?= num($sensorbox['wind_speed'] ?? 0, 1) ?></span>
                            <span class="text-[10px] text-slate-400 font-normal">m/s</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="pt-2.5 border-t border-slate-100 flex justify-between items-center text-[11px] text-slate-400">
                <span>PV Module Sensor Telemetry</span>
                <span class="text-emerald-600 font-semibold flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Online
                </span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Connected WebBox Devices Table Card -->
        <div class="card border-t-4 border-t-slate-700 justify-between <?= !empty($sensorbox) ? 'lg:col-span-2' : '' ?>">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h2 class="card-title !mb-0">
                        <i data-lucide="cpu" class="w-4 h-4 text-slate-600"></i>
                        Sunny WebBox Devices
                    </h2>
                    <span class="text-xs text-slate-400 font-medium">
                        <?= count($webboxDevices) ?> device<?= count($webboxDevices) > 1 ? 's' : '' ?> on bus
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="text-[10px] uppercase tracking-wider text-slate-400 border-b border-slate-100">
                                <th class="pb-2 font-semibold">Device</th>
                                <th class="pb-2 font-semibold">Type</th>
                                <th class="pb-2 text-right font-semibold">Power</th>
                                <th class="pb-2 text-right font-semibold">Today</th>
                                <th class="pb-2 text-right font-semibold">Total Yield</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-mono">
                            <?php foreach ($webboxDevices as $dev): ?>
                                <tr class="hover:bg-slate-50/60 transition-colors">
                                    <td class="py-2.5 font-bold text-slate-800">
                                        <?= htmlspecialchars($dev['name'] ?? $dev['key']) ?>
                                        <div class="text-[10px] font-normal text-slate-400"><?= htmlspecialchars($dev['key']) ?></div>
                                    </td>
                                    <td class="py-2.5">
                                        <?php if ($dev['type'] === 'sensor'): ?>
                                            <span class="bg-cyan-50 text-cyan-700 border border-cyan-200 px-1.5 py-0.5 rounded text-[10px] font-bold">Sensor</span>
                                        <?php else: ?>
                                            <span class="bg-amber-50 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded text-[10px] font-bold">Inverter</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 text-right font-bold <?= ($dev['type'] === 'inverter') ? 'text-amber-500' : 'text-slate-400' ?>">
                                        <?= ($dev['type'] === 'inverter') ? num($dev['power_w'] ?? 0, 0) . ' W' : '-' ?>
                                    </td>
                                    <td class="py-2.5 text-right text-slate-700">
                                        <?= ($dev['type'] === 'inverter' && isset($dev['today_kwh'])) ? num($dev['today_kwh'], 2) . ' kWh' : '-' ?>
                                    </td>
                                    <td class="py-2.5 text-right text-slate-700">
                                        <?php if ($dev['type'] === 'inverter' && isset($dev['total_kwh'])): ?>
                                            <?= num($dev['total_kwh'] / 1000, 3) ?> MWh
                                        <?php elseif (!empty($dev['summary'])): ?>
                                            <span class="text-[11px] text-slate-500 font-sans"><?= htmlspecialchars($dev['summary']) ?></span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="pt-2.5 border-t border-slate-100 flex justify-between items-center text-[11px] text-slate-400">
                <span>WebBox RS485 / Bluetooth Bus</span>
                <span>Gateway IP: <strong class="text-slate-700"><?= htmlspecialchars($webbox['ip'] ?? 'WebBox') ?></strong></span>
            </div>
        </div>

    </div>
    <?php endif; ?>

    <!-- System Info Footer -->
    <div class="card bg-slate-50/70 border-0 shadow-none !py-3">
        <div class="flex flex-wrap items-center justify-between gap-4 text-xs text-slate-500">
            <!-- Left Side: Devices & DB status -->
            <div class="flex items-center gap-6 flex-wrap">
                <span class="flex items-center gap-1.5">
                    <i data-lucide="wifi" class="w-3.5 h-3.5 text-slate-400"></i> P1 <strong><?= htmlspecialchars($p1['wifi_ssid']) ?></strong> (<?= htmlspecialchars($p1['wifi_strength']) ?>%)
                </span>

                <span class="flex items-center gap-1.5">
                    <i data-lucide="cpu" class="w-3.5 h-3.5 text-slate-400"></i>
                    P1: <strong><?= htmlspecialchars($p1['meter_model']) ?></strong>
                    <span class="text-[11px] <?= ($p1Status === 'Online') ? 'text-emerald-600 font-semibold' : 'text-rose-600 font-semibold' ?>">(<?= $p1Status ?>)</span>
                </span>

                <?php if ($hasSma): ?>
                <span class="flex items-center gap-1.5">
                    <i data-lucide="sun" class="w-3.5 h-3.5 text-amber-500"></i>
                    SMA: <strong><?= htmlspecialchars($sma['device_id'] ?? 'Inverter') ?></strong>
                    <span class="text-[11px] <?= ($sma['status'] === 'Online') ? 'text-emerald-600 font-semibold' : 'text-slate-400' ?>">(<?= htmlspecialchars($sma['status']) ?>)</span>
                </span>
                <?php endif; ?>

                <?php if ($hasWebbox): ?>
                <span class="flex items-center gap-1.5">
                    <i data-lucide="sun-medium" class="w-3.5 h-3.5 text-amber-500"></i>
                    WebBox: <strong><?= htmlspecialchars($webbox['name'] ?? 'Sunny WebBox') ?></strong>
                    <span class="text-[11px] <?= ($webbox['status'] === 'Online') ? 'text-emerald-600 font-semibold' : 'text-slate-400' ?>">(<?= htmlspecialchars($webbox['status']) ?>)</span>
                </span>
                <?php endif; ?>

                <button type="button" onclick="openLogsModal()" class="flex items-center gap-1.5 hover:text-blue-600 transition-colors cursor-pointer group text-left">
                    <i data-lucide="database" class="w-3.5 h-3.5 text-slate-400 group-hover:text-blue-600"></i>
                    <span>DB:</span>
                    <code class="text-[10px] bg-slate-200/60 group-hover:bg-blue-100 group-hover:text-blue-700 px-1.5 py-0.5 rounded font-mono transition-colors"><?= htmlspecialchars($dbFile) ?></code>
                </button>
            </div>

            <!-- Right Side: Hardware Diagnostics Link (powertests.php) -->
            <div class="flex items-center">
                <a href="powertests.php" title="Run Power Hardware Diagnostics (powertests)" class="inline-flex items-center justify-center p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-slate-200/60 transition-colors cursor-pointer">
                    <i data-lucide="wrench" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- 1. Raw Readings Modal -->
<?php if ($p1): ?>
<div id="rawReadingsModal" class="<?= $openRawModal ? '' : 'hidden' ?> fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-100 max-w-xl w-full p-6 md:p-8 animate-in fade-in zoom-in-95 duration-150 flex flex-col max-h-[92vh]">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-4 flex-shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                    <i data-lucide="table-properties" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-slate-900">Raw Meter Registers</h3>
                    <p class="text-xs text-slate-400">Direct lifetime totals from digital meter & inverters</p>
                </div>
            </div>
        </div>

        <div class="overflow-y-auto flex-grow table-scroll pr-1">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="text-xs uppercase tracking-wider text-slate-400 border-b border-slate-100">
                        <th class="pb-2 font-semibold">Register / Metric</th>
                        <th class="pb-2 text-right font-semibold">Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                    <?php
                        $pImpTot = formatRawParts($p1['total_power_import_kwh'] ?? 0, 3);
                        $pPeak   = formatRawParts($p1['total_power_import_t1_kwh'] ?? 0, 3);
                        $pOff    = formatRawParts($p1['total_power_import_t2_kwh'] ?? 0, 3);

                        $pExpTot = formatRawParts($p1['total_power_export_kwh'] ?? 0, 3);
                        $pExpT1  = formatRawParts($p1['total_power_export_t1_kwh'] ?? 0, 3);
                        $pExpT2  = formatRawParts($p1['total_power_export_t2_kwh'] ?? 0, 3);

                        $pCap    = formatRawParts($p1['montly_power_peak_w'] ?? 0, 0);

                        if ($hasSolar) {
                            $pSolDay = formatRawParts($calc['today_solar_kwh'] ?? 0, 2);
                            $pSolTot = formatRawParts($calc['total_solar_kwh'] ?? 0, 1);
                        }
                        $pGas    = formatRawParts($rawGas, 3);
                        $pWater  = formatRawParts($rawWater, 3);
                    ?>

                    <!-- Section: Grid Import -->
                    <tr class="bg-slate-50/60">
                        <td colspan="2" class="py-1.5 px-2 text-[11px] font-bold uppercase tracking-wider text-slate-500">Grid Import (Afname)</td>
                    </tr>
                    <tr>
                        <td class="py-2.5 pl-2 text-slate-800 font-semibold">Total Import <span class="text-xs text-slate-400 font-normal">(1.8.0)</span></td>
                        <td class="py-2.5 text-right font-mono flex items-baseline justify-end">
                            <span class="text-2xl md:text-3xl font-bold text-slate-900"><?= $pImpTot['int'] ?></span>
                            <span class="text-2xl md:text-3xl font-bold text-slate-400"><?= $pImpTot['dec'] ?></span>
                            <span class="w-12 text-left pl-2 text-sm text-slate-400">kWh</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-2 pl-4 text-slate-600 font-medium">Peak Import T1 <span class="text-xs text-slate-400 font-normal">(1.8.1)</span></td>
                        <td class="py-2 text-right font-mono flex items-baseline justify-end">
                            <span class="text-xl md:text-2xl font-bold text-slate-800"><?= $pPeak['int'] ?></span>
                            <span class="text-xl md:text-2xl font-bold text-slate-400"><?= $pPeak['dec'] ?></span>
                            <span class="w-12 text-left pl-2 text-sm text-slate-400">kWh</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-2 pl-4 text-slate-600 font-medium">Off-peak Import T2 <span class="text-xs text-slate-400 font-normal">(1.8.2)</span></td>
                        <td class="py-2 text-right font-mono flex items-baseline justify-end">
                            <span class="text-xl md:text-2xl font-bold text-slate-800"><?= $pOff['int'] ?></span>
                            <span class="text-xl md:text-2xl font-bold text-slate-400"><?= $pOff['dec'] ?></span>
                            <span class="w-12 text-left pl-2 text-sm text-slate-400">kWh</span>
                        </td>
                    </tr>

                    <!-- Section: Grid Export -->
                    <tr class="bg-emerald-50/40">
                        <td colspan="2" class="py-1.5 px-2 text-[11px] font-bold uppercase tracking-wider text-emerald-800">Grid Export (Injectie)</td>
                    </tr>
                    <tr>
                        <td class="py-2.5 pl-2 text-slate-800 font-semibold">Total Export <span class="text-xs text-slate-400 font-normal">(2.8.0)</span></td>
                        <td class="py-2.5 text-right font-mono flex items-baseline justify-end">
                            <span class="text-2xl md:text-3xl font-bold text-emerald-700"><?= $pExpTot['int'] ?></span>
                            <span class="text-2xl md:text-3xl font-bold text-emerald-400"><?= $pExpTot['dec'] ?></span>
                            <span class="w-12 text-left pl-2 text-sm text-slate-400">kWh</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-2 pl-4 text-slate-600 font-medium">Peak Export T1 <span class="text-xs text-slate-400 font-normal">(2.8.1)</span></td>
                        <td class="py-2 text-right font-mono flex items-baseline justify-end">
                            <span class="text-xl md:text-2xl font-bold text-emerald-600"><?= $pExpT1['int'] ?></span>
                            <span class="text-xl md:text-2xl font-bold text-emerald-400"><?= $pExpT1['dec'] ?></span>
                            <span class="w-12 text-left pl-2 text-sm text-slate-400">kWh</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-2 pl-4 text-slate-600 font-medium">Off-peak Export T2 <span class="text-xs text-slate-400 font-normal">(2.8.2)</span></td>
                        <td class="py-2 text-right font-mono flex items-baseline justify-end">
                            <span class="text-xl md:text-2xl font-bold text-emerald-600"><?= $pExpT2['int'] ?></span>
                            <span class="text-xl md:text-2xl font-bold text-emerald-400"><?= $pExpT2['dec'] ?></span>
                            <span class="w-12 text-left pl-2 text-sm text-slate-400">kWh</span>
                        </td>
                    </tr>

                    <!-- Section: Peak Demand -->
                    <tr class="bg-slate-50/60">
                        <td colspan="2" class="py-1.5 px-2 text-[11px] font-bold uppercase tracking-wider text-slate-500">Capaciteitstarief</td>
                    </tr>
                    <tr>
                        <td class="py-2.5 pl-2 text-slate-800 font-semibold">Capacity Peak</td>
                        <td class="py-2.5 text-right font-mono flex items-baseline justify-end">
                            <span class="text-2xl md:text-3xl font-bold text-amber-600"><?= $pCap['int'] ?></span>
                            <span class="text-2xl md:text-3xl font-bold text-slate-400 invisible select-none">,000</span>
                            <span class="w-12 text-left pl-2 text-sm text-slate-400">W</span>
                        </td>
                    </tr>

                    <!-- Section: Solar (if active) -->
                    <?php if ($hasSolar): ?>
                    <tr class="bg-amber-50/40">
                        <td colspan="2" class="py-1.5 px-2 text-[11px] font-bold uppercase tracking-wider text-amber-800">Solar Generation</td>
                    </tr>
                    <tr>
                        <td class="py-3.5 text-slate-700 font-medium text-base">Solar Generated Today</td>
                        <td class="py-3.5 text-right font-mono">
                            <div class="flex items-baseline justify-end">
                                <span class="text-2xl md:text-3xl font-bold text-emerald-600"><?= $pSolDay['int'] ?></span>
                                <span class="text-2xl md:text-3xl font-bold text-emerald-400"><?= $pSolDay['dec'] ?></span>
                                <span class="text-2xl md:text-3xl font-bold text-slate-400 invisible select-none">0</span>
                                <span class="w-12 text-left pl-2 text-sm text-slate-400">kWh</span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-3.5 text-slate-700 font-medium text-base">Solar Lifetime Yield</td>
                        <td class="py-3.5 text-right font-mono">
                            <div class="flex items-baseline justify-end">
                                <span class="text-2xl md:text-3xl font-bold text-emerald-600"><?= $pSolTot['int'] ?></span>
                                <span class="text-2xl md:text-3xl font-bold text-emerald-400"><?= $pSolTot['dec'] ?></span>
                                <span class="text-2xl md:text-3xl font-bold text-slate-400 invisible select-none">00</span>
                                <span class="w-12 text-left pl-2 text-sm text-slate-400">kWh</span>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <!-- Section: Utilities -->
                    <tr class="bg-slate-50/60">
                        <td colspan="2" class="py-1.5 px-2 text-[11px] font-bold uppercase tracking-wider text-slate-500">Utilities</td>
                    </tr>
                    <tr>
                        <td class="py-2 pl-2 text-slate-700 font-medium">Natural Gas</td>
                        <td class="py-2 text-right font-mono flex items-baseline justify-end">
                            <span class="text-xl md:text-2xl font-bold text-slate-900"><?= $pGas['int'] ?></span>
                            <span class="text-xl md:text-2xl font-bold text-slate-400"><?= $pGas['dec'] ?></span>
                            <span class="w-12 text-left pl-2 text-sm text-slate-400">m³</span>
                        </td>
                    </tr>
                    <?php if ($hasWater): ?>
                    <tr>
                        <td class="py-2 pl-2 text-slate-700 font-medium">Tap Water</td>
                        <td class="py-2 text-right font-mono flex items-baseline justify-end">
                            <span class="text-xl md:text-2xl font-bold text-slate-900"><?= $pWater['int'] ?></span>
                            <span class="text-xl md:text-2xl font-bold text-slate-400"><?= $pWater['dec'] ?></span>
                            <span class="w-12 text-left pl-2 text-sm text-slate-400">m³</span>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-4 pt-3 border-t border-slate-100 flex justify-end flex-shrink-0">
            <button type="button" onclick="closeRawModal()" class="w-full sm:w-auto px-8 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm rounded-xl shadow-md transition-all cursor-pointer">OK</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 2. System Logs Modal -->
<div id="systemLogsModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-100 max-w-4xl w-full p-6 md:p-8 animate-in fade-in zoom-in-95 duration-150 flex flex-col max-h-[90vh]">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100 mb-4 flex-shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                    <i data-lucide="database" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-slate-900">System Logs & Event Streaks</h3>
                    <p class="text-xs text-slate-400">Showing last 50 entries from <code class="bg-slate-100 px-1 py-0.5 rounded text-[11px]"><?= htmlspecialchars(basename($dbFile)) ?></code></p>
                </div>
            </div>
            <a href="?download=logs" class="inline-flex items-center justify-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold bg-slate-800 hover:bg-slate-900 text-white shadow-sm transition-all flex-shrink-0">
                <i data-lucide="download" class="w-3.5 h-3.5"></i> Download Full Log (CSV)
            </a>
        </div>

        <div class="table-scroll overflow-x-auto overflow-y-auto flex-grow rounded-2xl border border-slate-100 bg-white">
            <table class="w-full text-left border-collapse whitespace-nowrap text-xs">
                <thead class="sticky top-0 bg-slate-50 border-b border-slate-200 z-10 uppercase tracking-wider text-[11px] text-slate-500">
                    <tr>
                        <th class="py-2.5 px-3 font-semibold">Status</th>
                        <th class="py-2.5 px-3 font-semibold text-center">Count</th>
                        <th class="py-2.5 px-3 font-semibold">First Seen</th>
                        <th class="py-2.5 px-3 font-semibold">Last Seen</th>
                        <th class="py-2.5 px-3 font-semibold">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-mono text-slate-700">
                    <?php if (!empty($logHistory)): ?>
                        <?php foreach ($logHistory as $log): ?>
                            <?php $isSuccess = ($log['status'] === 'SUCCESS'); ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="py-2.5 px-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold tracking-wide <?= $isSuccess ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' ?>">
                                        <?= htmlspecialchars($log['status']) ?>
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 text-center font-bold text-slate-800">x<?= htmlspecialchars($log['count']) ?></td>
                                <td class="py-2.5 px-3 text-slate-500 font-sans"><?= htmlspecialchars($log['first_seen']) ?></td>
                                <td class="py-2.5 px-3 text-slate-500 font-sans"><?= htmlspecialchars($log['last_seen']) ?></td>
                                <td class="py-2.5 px-3 font-sans text-slate-900 font-medium"><?= htmlspecialchars($log['message']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-8 text-slate-400 font-sans italic">No entries yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-4 pt-4 border-t border-slate-100 flex justify-end flex-shrink-0">
            <button type="button" onclick="closeLogsModal()" class="w-full sm:w-auto px-8 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm rounded-xl shadow-md transition-all cursor-pointer">OK</button>
        </div>
    </div>
</div>

<script>
    lucide.createIcons();

    function openRawModal() {
        const modal = document.getElementById('rawReadingsModal');
        if (modal) modal.classList.remove('hidden');
    }
    function closeRawModal() {
        const modal = document.getElementById('rawReadingsModal');
        if (modal) modal.classList.add('hidden');
        if (window.location.search.includes('rawreadings')) window.location.href = window.location.pathname;
    }
    function openLogsModal() {
        const modal = document.getElementById('systemLogsModal');
        if (modal) modal.classList.remove('hidden');
    }
    function closeLogsModal() {
        const modal = document.getElementById('systemLogsModal');
        if (modal) modal.classList.add('hidden');
    }

    <?php if($p1): ?>
    // Capacity Peak
    const currentAvgW = <?= (int)$p1['active_power_average_w'] ?>;
    const monthlyPeakW = <?= max(1, (int)$p1['montly_power_peak_w']) ?>;
    const peakPercentage = Math.min(100, Math.round((currentAvgW / monthlyPeakW) * 100));

    new ApexCharts(document.querySelector("#capacityGaugeChart"), {
        series: [peakPercentage],
        labels: ['Running 15-min Avg'],
        chart: { type: 'radialBar', height: 210, offsetTop: -10 },
        plotOptions: {
            radialBar: {
                startAngle: -120, endAngle: 120, hollow: { size: '65%' }, track: { background: '#f1f5f9', strokeWidth: '100%' },
                dataLabels: {
                    name: { show: true, color: '#64748b', fontSize: '11px', offsetY: -10 },
                    value: {
                        show: true, formatter: () => `${currentAvgW} W`, color: currentAvgW > (monthlyPeakW * 0.9) ? '#ef4444' : '#0f172a',
                        fontSize: '22px', fontWeight: 800, offsetY: -2
                    }
                }
            }
        },
        fill: { colors: [currentAvgW > (monthlyPeakW * 0.9) ? '#ef4444' : (currentAvgW > (monthlyPeakW * 0.7) ? '#f59e0b' : '#3b82f6')] },
        stroke: { lineCap: 'round' },
        subtitle: { text: `Monthly Peak: ${monthlyPeakW} W`, align: 'center', style: { fontSize: '12px', fontWeight: 'bold', color: '#94a3b8' } }
    }).render();

    // Timeline chart. Straight segments so a null gap is not curved across.
    const hasSolar = <?= json_encode($hasSolar) ?>;
    const chartStartMs = <?= (int)$chartWindow['start'] * 1000 ?>;
    const chartEndMs = <?= (int)$chartWindow['end'] * 1000 ?>;
    const minZoomMs = 15 * 60 * 1000;
    const maxZoomMs = 7 * 24 * 60 * 60 * 1000;

    let chartSeries = [];
    let chartColors = [];
    let chartAnnotations = {};

    if (hasSolar) {
        chartSeries = [
            { name: 'Home Consumption', data: <?= json_encode($chartHome) ?> },
            { name: 'Solar Output', data: <?= json_encode($chartSolar) ?> }
        ];
        chartColors = ['#4f46e5', '#f59e0b'];
    } else {
        chartSeries = [
            { name: 'Net Grid Power', data: <?= json_encode($chartGrid) ?> }
        ];
        chartColors = ['#2563eb'];
        chartAnnotations = {
            yaxis: [{
                y: 0,
                borderColor: '#10b981',
                borderWidth: 1.5,
                label: {
                    borderColor: '#10b981',
                    style: { color: '#fff', background: '#10b981', fontSize: '10px' },
                    text: '0 W (Grid Neutral)'
                }
            }]
        };
    }

    function chartQueryTime(ms) {
        const d = new Date(ms);
        const p = (n) => String(n).padStart(2, '0');
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
            + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    function rememberChartZoom(minMs, maxMs) {
        let min = minMs;
        let max = maxMs;
        if (max - min < minZoomMs) {
            min = max - minZoomMs;
        }
        if (max - min > maxZoomMs) {
            min = max - maxZoomMs;
        }
        if (Math.abs(min - chartStartMs) < 1500 && Math.abs(max - chartEndMs) < 1500) {
            return;
        }
        const params = new URLSearchParams(window.location.search);
        params.delete('range');
        params.set('from', chartQueryTime(min));
        params.set('to', chartQueryTime(max));
        window.location.search = params.toString();
    }

    const historyChart = new ApexCharts(document.querySelector("#historyTimelineChart"), {
        series: chartSeries,
        chart: {
            type: 'area',
            height: 250,
            animations: { enabled: false },
            toolbar: {
                show: true,
                tools: { download: false, selection: true, zoom: true, zoomin: false, zoomout: false, pan: false, reset: true }
            },
            zoom: { enabled: true, type: 'x', autoScaleYaxis: false },
            events: {
                beforeZoom: function (_ctx, range) {
                    if (!range || !range.xaxis) {
                        return range;
                    }
                    const span = range.xaxis.max - range.xaxis.min;
                    if (span < minZoomMs) {
                        return { xaxis: { min: range.xaxis.max - minZoomMs, max: range.xaxis.max } };
                    }
                    if (span > maxZoomMs) {
                        return { xaxis: { min: range.xaxis.max - maxZoomMs, max: range.xaxis.max } };
                    }
                    return range;
                },
                zoomed: function (_ctx, range) {
                    if (!range || !range.xaxis) {
                        return;
                    }
                    rememberChartZoom(range.xaxis.min, range.xaxis.max);
                }
            }
        },
        stroke: { curve: 'straight', width: hasSolar ? [2, 2] : 2 },
        colors: chartColors,
        fill: {
            type: 'gradient',
            gradient: { shadeIntensity: 1, opacityFrom: 0.5, opacityTo: 0.05, stops: [0, 90, 100] }
        },
        annotations: chartAnnotations,
        dataLabels: { enabled: false },
        markers: { size: 0 },
        xaxis: {
            type: 'datetime',
            min: chartStartMs,
            max: chartEndMs,
            labels: { datetimeUTC: false, style: { fontSize: '10px', colors: '#94a3b8' } },
            tickAmount: 8
        },
        yaxis: { labels: { formatter: (val) => (val === null || val === undefined || !Number.isFinite(Number(val))) ? '' : `${Number(val).toFixed(0)} W`, style: { fontSize: '10px', colors: '#94a3b8' } } },
        tooltip: {
            shared: true,
            intersect: false,
            x: { format: 'dd MMM HH:mm' },
            y: { formatter: (val) => (val === null || val === undefined) ? '' : `${val} W` }
        },
        grid: { borderColor: '#f1f5f9' }
    });
    historyChart.render();
    <?php endif; ?>

    // Auto-refresh logic
    const REFRESH_INTERVAL = <?= (int)$refreshRate ?>;
    let countdown = REFRESH_INTERVAL;
    const toggle = document.getElementById('autoRefreshToggle');
    const badge = document.getElementById('countdownBadge');

    if (toggle && badge) {
        const savedState = localStorage.getItem('autoRefreshEnabled');
        toggle.checked = savedState !== null ? savedState === 'true' : true;

        function updateBadge() {
            badge.textContent = `${countdown}s`;
            badge.className = toggle.checked
                ? "inline-flex items-center justify-center min-w-[28px] px-1.5 py-0.5 rounded text-xs font-mono font-bold bg-blue-50 text-blue-600 border border-blue-200"
                : "inline-flex items-center justify-center min-w-[28px] px-1.5 py-0.5 rounded text-xs font-mono font-bold bg-slate-100 text-slate-400 border border-slate-200";
            if (!toggle.checked) badge.textContent = "off";
        }
        updateBadge();

        toggle.addEventListener('change', () => {
            localStorage.setItem('autoRefreshEnabled', toggle.checked);
            countdown = REFRESH_INTERVAL;
            updateBadge();
        });

        setInterval(() => {
            if (!toggle.checked) return;
            const rawM = document.getElementById('rawReadingsModal');
            const logM = document.getElementById('systemLogsModal');
            if ((rawM && !rawM.classList.contains('hidden')) || (logM && !logM.classList.contains('hidden'))) return;

            countdown--;
            if (countdown <= 0) window.location.reload();
            else updateBadge();
        }, 1000);
    }
</script>

</body>
</html>