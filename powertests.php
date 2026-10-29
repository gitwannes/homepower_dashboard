<?php
# --- file: powertests.php ---
/**
 * Power Hardware Diagnostics Runner
 * Executes powertests.py and streams live hardware tests to the browser.
 */
date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/site_settings.php';
$hp = hp_boot();
hp_require_ready($hp);

$scriptPath = __DIR__ . '/powertests.py';

// Newest Package Center / versioned Python (hp_python_bin in site_settings.php).
$pythonBin = hp_python_bin();

// Query Python runtime version
$pythonVersion = 'Unknown';
$pyVerOutput = [];
@exec("{$pythonBin} --version 2>&1", $pyVerOutput);
if (!empty($pyVerOutput[0])) {
    $pythonVersion = trim($pyVerOutput[0]); // e.g. "Python 3.11.2" or "Python 3.14.0"
}

$preflightError = null;
if (!function_exists('exec')) {
    $preflightError = "PHP 'exec()' function is disabled in your PHP profile. Enable it in Web Station.";
} elseif (!file_exists($scriptPath)) {
    $preflightError = "Diagnostic script not found: " . htmlspecialchars($scriptPath);
}

$output = [];
$returnCode = null;
$executionTime = null;

if (!$preflightError) {
    $startTime = microtime(true);
    $cmd = "{$pythonBin} " . escapeshellarg($scriptPath) . " 2>&1";
    exec($cmd, $output, $returnCode);
    $executionTime = round(microtime(true) - $startTime, 2);
}

$isSuccess = ($returnCode === 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Power Hardware Diagnostics</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { background-color: #f8fafc; font-family: system-ui, -apple-system, sans-serif; }
    </style>
</head>
<body class="p-4 md:p-8 flex items-center justify-center min-h-screen">

<div class="max-w-4xl w-full space-y-6">

    <!-- Header Card -->
    <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3.5">
            <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center flex-shrink-0">
                <i data-lucide="wrench" class="w-6 h-6"></i>
            </div>
            <div>
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">Power Hardware Diagnostics</h1>
                <p class="text-xs text-slate-500">Live probing tool for P1 Digital Meter, SMA Inverters & WebBox</p>
            </div>
        </div>

        <div class="flex items-center gap-2.5">
            <a href="powertests.php" class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-semibold shadow-sm transition-all cursor-pointer">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                Re-run Tests
            </a>
            <a href="index.php" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold transition-all">
                &larr; Dashboard
            </a>
            <?= hp_nav_settings() ?>
        </div>
    </div>

    <!-- Execution Status & Runtime Environment Badges -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- 1. Execution Status -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
            <span class="text-[11px] font-bold uppercase text-slate-400">Execution Status</span>
            <div class="mt-1 flex items-center gap-1.5 font-bold text-sm">
                <?php if ($preflightError): ?>
                    <span class="inline-flex items-center gap-1 text-rose-600">
                        <i data-lucide="x-circle" class="w-4 h-4"></i> Error
                    </span>
                <?php elseif ($isSuccess): ?>
                    <span class="inline-flex items-center gap-1 text-emerald-600">
                        <i data-lucide="check-circle" class="w-4 h-4"></i> Clean Run
                    </span>
                <?php else: ?>
                    <span class="inline-flex items-center gap-1 text-rose-600">
                        <i data-lucide="alert-triangle" class="w-4 h-4"></i> Warnings (<?= $returnCode ?>)
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- 2. PHP Environment -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
            <span class="text-[11px] font-bold uppercase text-slate-400">PHP Version</span>
            <div class="mt-1 flex items-baseline gap-1.5">
                <span class="font-bold text-sm text-slate-800 font-mono">PHP <?= PHP_VERSION ?></span>
                <span class="text-[10px] text-slate-400 font-sans">(<?= php_sapi_name() ?>)</span>
            </div>
        </div>

        <!-- 3. Python Environment -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
            <span class="text-[11px] font-bold uppercase text-slate-400">Python Version</span>
            <div class="mt-1 flex items-baseline gap-1.5 truncate" title="Binary: <?= htmlspecialchars($pythonBin) ?>">
                <span class="font-bold text-sm text-slate-800 font-mono"><?= htmlspecialchars($pythonVersion) ?></span>
            </div>
        </div>

        <!-- 4. Round-trip Latency -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
            <span class="text-[11px] font-bold uppercase text-slate-400">Execution Latency</span>
            <div class="mt-1 font-mono text-sm font-bold text-slate-800">
                <?= $executionTime !== null ? "{$executionTime}s" : "-" ?>
            </div>
        </div>
    </div>

    <!-- Console Output Window -->
    <div class="bg-slate-900 rounded-3xl shadow-xl overflow-hidden border border-slate-800">
        <!-- Terminal Header -->
        <div class="bg-slate-950 px-5 py-3.5 border-b border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-full bg-rose-500/80 inline-block"></span>
                <span class="w-3 h-3 rounded-full bg-amber-500/80 inline-block"></span>
                <span class="w-3 h-3 rounded-full bg-emerald-500/80 inline-block"></span>
                <span class="text-xs font-mono text-slate-400 ml-2">powertests.py stdout stream</span>
            </div>
            <span class="text-[11px] text-slate-500 font-mono">exit: <?= $returnCode ?? 'error' ?></span>
        </div>

        <!-- Terminal Output -->
        <div class="p-6 font-mono text-xs md:text-sm leading-relaxed overflow-x-auto text-slate-200 max-h-[520px] overflow-y-auto">
            <?php if ($preflightError): ?>
                <div class="text-rose-400 font-semibold">[ERROR] <?= htmlspecialchars($preflightError) ?></div>
            <?php elseif (empty($output)): ?>
                <div class="text-slate-500 italic">No output returned by script.</div>
            <?php else: ?>
                <?php foreach ($output as $line): ?>
                    <?php
                        $class = "text-slate-300";
                        if (strpos($line, '[ OK ]') !== false || strpos($line, '[ ONLINE ]') !== false) {
                            $class = "text-emerald-400 font-semibold";
                        } elseif (strpos($line, '[FAIL]') !== false || strpos($line, 'Error') !== false || strpos($line, '[ AUTH FAILED ]') !== false) {
                            $class = "text-rose-400 font-semibold";
                        } elseif (strpos($line, '[WARN]') !== false || strpos($line, '[ OFFLINE ]') !== false) {
                            $class = "text-amber-400";
                        } elseif (strpos($line, '[ INFO ]') !== false || strpos($line, '[ SKIPPED ]') !== false) {
                            $class = "text-sky-400";
                        } elseif (strpos($line, '===') !== false || strpos($line, '---') !== false) {
                            $class = "text-slate-600";
                        }
                    ?>
                    <div class="<?= $class ?> whitespace-pre"><?= htmlspecialchars($line) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
    lucide.createIcons();
</script>

</body>
</html>