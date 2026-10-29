<?php
# --- file: virtual_meters_debug.php ---
/**
 * A14 read-only troubleshooting for virtual kWh meters.
 * Unlock with admin password (timed window). No config here - use Settings.
 */
date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/site_settings.php';

/** Match Settings unlock length. */
const HP_VM_DEBUG_UNLOCK_SECONDS = 300;

$boot = hp_boot();
hp_require_ready($boot);
$pdo = $boot['pdo'];
hp_vm_ensure_schema($pdo);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$error = '';
$unlocked = false;
$until = isset($_SESSION['hp_vm_debug_until']) ? (int)$_SESSION['hp_vm_debug_until'] : 0;
if ($until > time()) {
    $unlocked = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    if ($action === 'unlock') {
        $password = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
        if (hp_admin_ok($pdo, $password)) {
            $_SESSION['hp_vm_debug_until'] = time() + HP_VM_DEBUG_UNLOCK_SECONDS;
            header('Location: virtual_meters_debug.php');
            exit;
        }
        $error = 'Admin password is wrong.';
    } elseif ($action === 'lock') {
        unset($_SESSION['hp_vm_debug_until']);
        header('Location: virtual_meters_debug.php');
        exit;
    }
}

$ctx = hp_vm_load_source_context($pdo);
$meters = $unlocked ? hp_vm_list_evaluated($pdo) : [];
$sources = $unlocked ? hp_vm_available_sources($pdo) : [];
$remain = $unlocked ? max(0, $until - time()) : 0;

/**
 * @param mixed $v
 */
function hp_vm_debug_fmt($v, int $digits = 4): string
{
    if ($v === null || $v === '') {
        return '-';
    }
    if (!is_numeric($v)) {
        return (string)$v;
    }
    return number_format((float)$v, $digits, '.', '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Virtual meters debug</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen">
<div class="max-w-5xl mx-auto p-4 md:p-8 space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Virtual kWh meters - debug</h1>
            <p class="text-sm text-slate-500 mt-1">Read-only. Configure meters on <a class="text-indigo-600 font-semibold hover:underline" href="settings.php#virtualMetersSection">Settings</a>.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="settings.php" class="px-3 py-2 rounded-xl border border-slate-200 text-sm font-semibold bg-white">Settings</a>
            <a href="index.php" class="px-3 py-2 rounded-xl border border-slate-200 text-sm font-semibold bg-white">Dashboard</a>
        </div>
    </header>

    <?php if ($error !== ''): ?>
        <p class="text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-xl p-3"><?= hp_h($error) ?></p>
    <?php endif; ?>

    <?php if (!$unlocked): ?>
        <section class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm max-w-md space-y-3">
            <h2 class="text-lg font-bold">Unlock debug view</h2>
            <p class="text-sm text-slate-600">Admin password required. Unlock lasts <?= (int)HP_VM_DEBUG_UNLOCK_SECONDS ?> seconds (same idea as Settings).</p>
            <form method="post" class="space-y-3" autocomplete="off">
                <input type="hidden" name="action" value="unlock">
                <label class="block text-sm font-semibold">Admin password
                    <input type="password" name="admin_password" required autocomplete="current-password" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
                </label>
                <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold">Unlock</button>
            </form>
        </section>
    <?php else: ?>
        <div class="flex flex-wrap items-center gap-3 text-xs text-slate-600">
            <span class="font-semibold tabular-nums">Unlocked - about <?= (int)$remain ?>s left</span>
            <form method="post" class="inline">
                <input type="hidden" name="action" value="lock">
                <button type="submit" class="px-3 py-1.5 rounded-xl border border-slate-200 bg-white text-xs font-semibold">Lock now</button>
            </form>
            <a href="virtual_meters_debug.php" class="px-3 py-1.5 rounded-xl border border-slate-200 bg-white text-xs font-semibold">Refresh</a>
        </div>

        <section class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm space-y-3">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Source context</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                <div><dt class="text-xs text-slate-500">Last readings timestamp</dt><dd class="font-mono"><?= hp_h((string)($ctx['reading_ts'] ?? '-')) ?></dd></div>
                <div><dt class="text-xs text-slate-500">latest_state updated_at</dt><dd class="font-mono"><?= hp_h((string)($ctx['state_ts'] ?? '-')) ?></dd></div>
            </dl>
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 pt-2">Available sources now</h3>
            <?php if (count($sources) === 0): ?>
                <p class="text-sm text-slate-500">None - wait for a logger poll.</p>
            <?php else: ?>
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="text-left text-xs uppercase text-slate-400 border-b border-slate-100">
                            <th class="py-2">Source</th>
                            <th class="py-2 text-right">S (kWh)</th>
                            <th class="py-2">Detail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($sources as $s): ?>
                            <tr>
                                <td class="py-2 font-semibold"><?= hp_h($s['label']) ?><div class="text-[11px] text-slate-400 font-mono"><?= hp_h($s['source']) ?></div></td>
                                <td class="py-2 text-right font-mono"><?= hp_h(hp_vm_debug_fmt($s['value'], 4)) ?></td>
                                <td class="py-2 text-xs text-slate-500"><?= hp_h($s['detail']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm space-y-4">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Configured meters</h2>
            <p class="text-xs text-slate-500 font-mono">V = (S0 + C) + p * (S - S0); p = factor_percent / 100</p>
            <?php if (count($meters) === 0): ?>
                <p class="text-sm text-slate-500">No virtual meters configured.</p>
            <?php else: ?>
                <?php foreach ($meters as $m): ?>
                    <article class="border border-slate-200 rounded-xl p-4 space-y-2 bg-slate-50/50">
                        <h3 class="font-bold text-slate-900">#<?= (int)$m['id'] ?> - <?= hp_h((string)$m['name']) ?></h3>
                        <dl class="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
                            <div><dt class="text-[11px] text-slate-500">source</dt><dd class="font-mono"><?= hp_h((string)$m['source']) ?></dd></div>
                            <div><dt class="text-[11px] text-slate-500">source ok</dt><dd><?= !empty($m['source_ok']) ? 'yes' : 'no' ?></dd></div>
                            <div><dt class="text-[11px] text-slate-500">raw registers flag</dt><dd><?= (int)($m['show_in_raw_registers'] ?? 0) === 1 ? '1' : '0' ?></dd></div>
                            <div><dt class="text-[11px] text-slate-500">updated_at</dt><dd class="font-mono text-xs"><?= hp_h((string)($m['updated_at'] ?? '')) ?></dd></div>
                            <div><dt class="text-[11px] text-slate-500">S</dt><dd class="font-mono"><?= hp_h(hp_vm_debug_fmt($m['S'] ?? null)) ?></dd></div>
                            <div><dt class="text-[11px] text-slate-500">S0</dt><dd class="font-mono"><?= hp_h(hp_vm_debug_fmt($m['S0'] ?? null)) ?></dd></div>
                            <div><dt class="text-[11px] text-slate-500">C (bias)</dt><dd class="font-mono"><?= hp_h(hp_vm_debug_fmt($m['C'] ?? null)) ?></dd></div>
                            <div><dt class="text-[11px] text-slate-500">factor %</dt><dd class="font-mono"><?= hp_h(hp_vm_debug_fmt($m['factor_percent'] ?? null)) ?></dd></div>
                            <div><dt class="text-[11px] text-slate-500">p</dt><dd class="font-mono"><?= hp_h(hp_vm_debug_fmt($m['p'] ?? null, 8)) ?></dd></div>
                            <div><dt class="text-[11px] text-slate-500">S - S0</dt><dd class="font-mono"><?= hp_h(hp_vm_debug_fmt($m['delta_S'] ?? null)) ?></dd></div>
                            <div><dt class="text-[11px] text-slate-500">V</dt><dd class="font-mono font-bold"><?= hp_h(hp_vm_debug_fmt($m['V'] ?? null)) ?></dd></div>
                            <div><dt class="text-[11px] text-slate-500">created_at</dt><dd class="font-mono text-xs"><?= hp_h((string)($m['created_at'] ?? '')) ?></dd></div>
                        </dl>
                        <p class="text-xs text-slate-500"><?= hp_h((string)($m['source_detail'] ?? '')) ?></p>
                        <p class="text-[11px] font-mono text-slate-400"><?= hp_h((string)($m['formula'] ?? '')) ?></p>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
</body>
</html>
