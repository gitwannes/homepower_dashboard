<?php
# --- file: settings.php ---
/**
 * First-run admin password and the site configuration page (A6).
 * Device passwords are never written into the HTML.
 * A9 database download and restore are on this page.
 */
date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/site_settings.php';
require_once __DIR__ . '/db_backup.php';

$boot = hp_boot();
$pdo = $boot['pdo'];
$error = '';
$notice = '';
$posted = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    if ($action === 'verify_admin') {
        header('Content-Type: application/json; charset=UTF-8');
        $password = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
        echo json_encode(['ok' => hp_admin_ok($pdo, $password)]);
        exit;
    }

    if ($action === 'create_admin') {
        $first = isset($_POST['admin_new']) ? (string)$_POST['admin_new'] : '';
        $second = isset($_POST['admin_new2']) ? (string)$_POST['admin_new2'] : '';
        if (hp_admin_hash($pdo) !== null) {
            $error = 'An admin password is already set.';
        } elseif ($first === '' || $second === '') {
            $error = 'Enter the admin password twice.';
        } elseif ($first !== $second) {
            $error = 'The two admin passwords do not match.';
        } else {
            $hash = password_hash($first, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO app_admin (id, password_hash) VALUES (1, ?)')->execute([$hash]);
            $storedNow = hp_load_stored($pdo);
            header('Location: ' . (hp_is_configured($storedNow) ? 'index.php' : 'settings.php'));
            exit;
        }
    } elseif ($action === 'change_admin') {
        $current = isset($_POST['admin_current']) ? (string)$_POST['admin_current'] : '';
        $first = isset($_POST['admin_new']) ? (string)$_POST['admin_new'] : '';
        $second = isset($_POST['admin_new2']) ? (string)$_POST['admin_new2'] : '';
        if (!hp_admin_ok($pdo, $current)) {
            $error = 'Current admin password is wrong.';
        } elseif ($first === '' || $second === '') {
            $error = 'Enter the new admin password twice.';
        } elseif ($first !== $second) {
            $error = 'The two new admin passwords do not match.';
        } else {
            $hash = password_hash($first, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE app_admin SET password_hash = ? WHERE id = 1')->execute([$hash]);
            header('Location: settings.php?admin_changed=1');
            exit;
        }
    } elseif ($action === 'save') {
        if ($boot['mode'] === 'need_admin') {
            $error = 'Set an admin password first.';
        } else {
            $adminPassword = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
            $parsed = hp_settings_from_post($boot['stored']);
            if (!hp_admin_ok($pdo, $adminPassword)) {
                $error = 'Admin password is wrong. Nothing was saved.';
                $posted = $parsed['form'];
            } elseif (count($parsed['errors']) > 0) {
                $error = implode(' ', $parsed['errors']);
                $posted = $parsed['form'];
            } else {
                $pdo->beginTransaction();
                try {
                    hp_write_settings($pdo, $parsed['settings']);
                    hp_save_monthly($pdo, $parsed['monthly']);
                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Save failed.';
                    $posted = $parsed['form'];
                }
                if ($error === '') {
                    header('Location: settings.php?saved=1');
                    exit;
                }
            }
        }
    } elseif ($action === 'readings_csv') {
        try {
            if ($boot['mode'] === 'need_admin') {
                throw new RuntimeException('Set an admin password first.');
            }
            $adminPassword = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
            if (!hp_admin_ok($pdo, $adminPassword)) {
                throw new RuntimeException('Admin password is wrong. Nothing was downloaded.');
            }
            @set_time_limit(0);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('Content-Disposition: attachment; filename="energy_history_' . date('Ymd_His') . '.csv"');
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new RuntimeException('Could not open the download stream.');
            }
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($output, [
                'Timestamp',
                'Net Grid (W)',
                'Solar Power (W)',
                'Import Peak (kWh)',
                'Import Off peak (kWh)',
                'Total Import (kWh)',
                'Export Peak (kWh)',
                'Export Off peak (kWh)',
                'Total Export (kWh)',
                'Capacity Peak (W)',
                'Solar Lifetime (kWh)',
                'Solar Radiation (W/m2)',
                'Module Temp (C)',
                'Wind Speed (m/s)',
                'Gas (m3)',
                'Water (m3)',
            ], ';');
            $stmt = $pdo->query(
                'SELECT timestamp, active_power, solar_power,
                        import_t1, import_t2, total_import,
                        export_t1, export_t2, total_export,
                        capacity_peak_w, solar_total_kwh,
                        solar_radiation, temp_module, wind_speed,
                        gas, water
                 FROM readings
                 ORDER BY id ASC'
            );
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, [
                    date('d/m/Y H:i:s', strtotime((string)$row['timestamp'])),
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
                    ($row['water'] !== null) ? number_format((float)$row['water'], 3, ',', '') : '',
                ], ';');
            }
            fclose($output);
            exit;
        } catch (Throwable $e) {
            if (headers_sent()) {
                exit;
            }
            $error = $e->getMessage();
        }
    } elseif ($action === 'backup_download') {
        $work = '';
        try {
            if ($boot['mode'] === 'need_admin') {
                throw new RuntimeException('Set an admin password first.');
            }
            $adminPassword = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
            if (!hp_admin_ok($pdo, $adminPassword)) {
                throw new RuntimeException('Admin password is wrong. Nothing was downloaded.');
            }
            if (hp_backup_encryption_key() === '') {
                throw new RuntimeException('DB_BACKUP_ENCRYPTION_KEY is missing from .env.');
            }
            @set_time_limit(0);
            @ignore_user_abort(true);
            $work = hp_backup_work_dir();
            $removeWork = static function () use ($work): void {
                hp_backup_remove_tree($work);
            };
            register_shutdown_function($removeWork);
            $encPath = $work . DIRECTORY_SEPARATOR . 'download.backup';
            $downloadName = hp_backup_build_encrypted($boot['db'], $work, $encPath);
            hp_backup_send_file($encPath, $downloadName);
            hp_backup_remove_tree($work);
            exit;
        } catch (Throwable $e) {
            if ($work !== '') {
                hp_backup_remove_tree($work);
            }
            if (headers_sent()) {
                exit;
            }
            $error = $e->getMessage();
        }
    } elseif ($action === 'backup_restore') {
        $checked = '';
        $incoming = '';
        try {
            if ($boot['mode'] === 'need_admin') {
                throw new RuntimeException('Set an admin password first.');
            }
            $adminPassword = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
            if (!hp_admin_ok($pdo, $adminPassword)) {
                throw new RuntimeException('Admin password is wrong. Nothing was restored.');
            }
            if (!isset($_POST['restore_sure']) || (string)$_POST['restore_sure'] !== 'yes') {
                throw new RuntimeException('Confirm that everything currently in the database will be destroyed.');
            }
            if (hp_backup_encryption_key() === '') {
                throw new RuntimeException('DB_BACKUP_ENCRYPTION_KEY is missing from .env.');
            }
            @set_time_limit(0);
            $upload = isset($_FILES['backup_file']) && is_array($_FILES['backup_file']) ? $_FILES['backup_file'] : [];
            $checked = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'hp-restore-' . bin2hex(random_bytes(8)) . '.db';
            hp_backup_prepare_upload($upload, hp_backup_encryption_key(), $checked);
            $incoming = $boot['db'] . '.incoming';
            if (is_file($incoming) && !@unlink($incoming)) {
                throw new RuntimeException('Could not remove an older incoming database file. Nothing was restored.');
            }
            if (!@copy($checked, $incoming)) {
                throw new RuntimeException('Could not copy the restored database beside the live file. Nothing was restored.');
            }
            @unlink($checked);
            $checked = '';
            hp_backup_checkpoint($pdo);
            $livePath = $boot['db'];
            $pdo = null;
            $boot['pdo'] = null;
            hp_backup_install($livePath, $incoming);
            header('Location: settings.php?restored=1');
            exit;
        } catch (Throwable $e) {
            if ($checked !== '' && is_file($checked)) {
                @unlink($checked);
            }
            if ($incoming !== '' && is_file($incoming)) {
                @unlink($incoming);
            }
            if ($pdo === null) {
                $boot = hp_boot();
                $pdo = $boot['pdo'];
            }
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET['saved'])) {
    $notice = 'Settings saved. Titles and Summary PVGIS curve apply on the next page load. Plant limits and monthly target_pct apply after: python3 rollup.py -buildall';
}
if (isset($_GET['admin_changed'])) {
    $notice = 'Admin password changed.';
}
if (isset($_GET['restored'])) {
    $notice = 'Database restored.';
}

$stored = hp_load_stored($pdo);
$settings = $posted !== null ? $posted : hp_merge_settings($stored);
$monthly = hp_monthly_kwh($pdo);
if ($posted !== null && isset($posted['monthly']) && is_array($posted['monthly'])) {
    $monthly = $posted['monthly'];
}
$smaSet = isset($stored['sma_password']) && $stored['sma_password'] !== '';
$webboxSet = isset($stored['sunnywebbox_password']) && $stored['sunnywebbox_password'] !== '';
$portalSet = isset($stored['sunnyportal_password']) && $stored['sunnyportal_password'] !== '';
$nameplate = hp_positive_float(isset($settings['max_inverter_ac_w']) ? (string)$settings['max_inverter_ac_w'] : '', 12000.0);
$capW = $nameplate * 1.05;
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

/**
 * Read the settings form. Passwords are not returned for redisplay.
 *
 * @param array<string, string> $stored
 * @return array{errors: string[], settings: array<string, string>, monthly: array<int, float>, form: array<string, mixed>}
 */
function hp_settings_from_post(array $stored): array
{
    $errors = [];
    $text = function (string $key): string {
        return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
    };
    $raw = function (string $key): string {
        return isset($_POST[$key]) ? (string)$_POST[$key] : '';
    };

    $p1 = $text('p1_ip');
    if ($p1 === '') {
        $errors[] = 'P1 address is required.';
    }

    $maxRaw = $text('max_inverter_ac_w');
    if (!is_numeric($maxRaw) || (float)$maxRaw <= 0) {
        $errors[] = 'Max inverter AC must be a number greater than 0.';
        $maxRaw = '12000';
    }

    $hours = [
        'pac_night_start' => 'Deep night start',
        'pac_night_end' => 'Deep night end',
        'pac_shoulder_start' => 'Shoulder start',
        'pac_shoulder_end' => 'Shoulder end',
    ];
    $hourValues = [];
    foreach ($hours as $key => $label) {
        $rawHour = $text($key);
        if (!preg_match('/^(?:0|[1-9]|1[0-9]|2[0-3])$/', $rawHour)) {
            $errors[] = $label . ' must be an hour from 0 to 23.';
            $hourValues[$key] = hp_setting_defaults()[$key];
        } else {
            $hourValues[$key] = $rawHour;
        }
    }

    $radRaw = $text('pac_rad_floor');
    if (!is_numeric($radRaw) || (float)$radRaw < 0) {
        $errors[] = 'Radiation floor must be a number that is 0 or greater.';
        $radRaw = '5';
    }

    $portalZone = $text('sunnyportal_timezone');
    if ($portalZone !== '' && !in_array($portalZone, timezone_identifiers_list(), true)) {
        $errors[] = 'Sunny Portal timezone must be a timezone name such as Europe/Paris, or left empty.';
    }

    $monthly = [];
    for ($month = 1; $month <= 12; $month++) {
        $rawKwh = $text('exp_kwh_' . $month);
        if (!is_numeric($rawKwh) || (float)$rawKwh < 0) {
            $errors[] = 'Expected generation for month ' . $month . ' must be a number that is 0 or greater.';
            $monthly[$month] = hp_month_seed()[$month]['kwh'];
        } else {
            $monthly[$month] = (float)$rawKwh;
        }
    }

    $smaExisting = isset($stored['sma_password']) ? $stored['sma_password'] : '';
    $wbExisting = isset($stored['sunnywebbox_password']) ? $stored['sunnywebbox_password'] : '';
    $portalExisting = isset($stored['sunnyportal_password']) ? $stored['sunnyportal_password'] : '';
    $smaPassword = hp_resolve_password(
        $smaExisting,
        $raw('sma_password'),
        isset($_POST['sma_password_touched']) && $_POST['sma_password_touched'] === '1',
        $smaExisting !== ''
    );
    $wbPassword = hp_resolve_password(
        $wbExisting,
        $raw('sunnywebbox_password'),
        isset($_POST['sunnywebbox_password_touched']) && $_POST['sunnywebbox_password_touched'] === '1',
        $wbExisting !== ''
    );
    $portalPassword = hp_resolve_password(
        $portalExisting,
        $raw('sunnyportal_password'),
        isset($_POST['sunnyportal_password_touched']) && $_POST['sunnyportal_password_touched'] === '1',
        $portalExisting !== ''
    );

    $settings = [
        'p1_ip' => $p1,
        'sma_ip' => $text('sma_ip'),
        'sma_user' => $text('sma_user'),
        'sma_password' => $smaPassword,
        'sunnywebbox_ip' => $text('sunnywebbox_ip'),
        'sunnywebbox_password' => $wbPassword,
        'sunnyportal_user' => $text('sunnyportal_user'),
        'sunnyportal_password' => $portalPassword,
        'sunnyportal_plant_id' => $text('sunnyportal_plant_id'),
        'sunnyportal_timezone' => $portalZone,
        'site_title' => $text('site_title'),
        'site_tagline' => $text('site_tagline'),
        'site_page_title' => $text('site_page_title'),
        'max_inverter_ac_w' => $maxRaw,
        'pac_night_start' => $hourValues['pac_night_start'],
        'pac_night_end' => $hourValues['pac_night_end'],
        'pac_shoulder_start' => $hourValues['pac_shoulder_start'],
        'pac_shoulder_end' => $hourValues['pac_shoulder_end'],
        'pac_rad_floor' => $radRaw,
        'fluvius_ean_electricity' => (string)preg_replace('/\D/', '', $text('fluvius_ean_electricity')),
        'fluvius_ean_gas' => (string)preg_replace('/\D/', '', $text('fluvius_ean_gas')),
        'config_saved' => '1',
    ];

    $form = $settings;
    $form['monthly'] = $monthly;
    return [
        'errors' => $errors,
        'settings' => $settings,
        'monthly' => $monthly,
        'form' => $form,
    ];
}

/**
 * @param array<string, mixed> $settings
 */
function hp_field(array $settings, string $key): string
{
    return isset($settings[$key]) ? (string)$settings[$key] : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-slate-50 text-slate-900 p-4 md:p-8">
<div class="max-w-3xl mx-auto space-y-6">

<?php if ($boot['mode'] === 'need_admin'): ?>
    <header>
        <h1 class="text-2xl font-black tracking-tight">Create admin password</h1>
        <p class="text-sm text-slate-500 mt-1">This dashboard has no admin password yet. Set one before anything else. Enter it twice.</p>
    </header>
    <?php if ($error !== ''): ?>
        <p class="text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-xl p-3"><?= hp_h($error) ?></p>
    <?php endif; ?>
    <form method="post" class="bg-white border border-slate-200 rounded-2xl p-6 space-y-4 shadow-sm" autocomplete="off">
        <input type="hidden" name="action" value="create_admin">
        <label class="block text-sm font-semibold">Admin password
            <input type="password" name="admin_new" required autocomplete="new-password" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2">
        </label>
        <label class="block text-sm font-semibold">Admin password again
            <input type="password" name="admin_new2" required autocomplete="new-password" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2">
        </label>
        <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold">Save admin password</button>
    </form>
<?php else: ?>

    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black tracking-tight">Settings</h1>
            <p class="text-sm text-slate-500 mt-1">Site configuration. Device passwords stay masked.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <?php if ($boot['mode'] === 'ready'): ?>
                <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200">Live Dashboard</a>
            <?php endif; ?>
            <button type="button" id="openAdminChange" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200">Change admin password</button>
        </div>
    </header>

    <?php if ($boot['mode'] === 'need_config'): ?>
        <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl p-3">Save a P1 address before the dashboard opens.</p>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <p class="text-sm text-emerald-800 bg-emerald-50 border border-emerald-200 rounded-xl p-3"><?= hp_h($notice) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-xl p-3"><?= hp_h($error) ?></p>
    <?php endif; ?>

    <form id="settingsForm" method="post" class="space-y-6" autocomplete="off">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="admin_password" id="admin_password" value="">

        <section class="bg-white border border-slate-200 rounded-2xl p-6 space-y-4 shadow-sm">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Devices</h2>
            <label class="block text-sm font-semibold">P1 address
                <input name="p1_ip" required value="<?= hp_h(hp_field($settings, 'p1_ip')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <label class="block text-sm font-semibold">SMA address
                <input name="sma_ip" value="<?= hp_h(hp_field($settings, 'sma_ip')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <label class="block text-sm font-semibold">SMA user
                <input name="sma_user" value="<?= hp_h(hp_field($settings, 'sma_user')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <div class="block text-sm font-semibold">SMA password
                <div class="mt-1 flex gap-2">
                    <input type="password" name="sma_password" id="sma_password" autocomplete="new-password" <?= $smaSet ? 'disabled' : '' ?> placeholder="<?= $smaSet ? '********' : '' ?>" class="flex-1 border border-slate-200 rounded-xl px-3 py-2 font-normal">
                    <input type="hidden" name="sma_password_touched" id="sma_password_touched" value="0">
                    <?php if ($smaSet): ?>
                        <button type="button" class="js-edit-pw px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold" data-target="sma_password">Edit</button>
                    <?php endif; ?>
                </div>
            </div>
            <label class="block text-sm font-semibold">Sunny WebBox address
                <input name="sunnywebbox_ip" value="<?= hp_h(hp_field($settings, 'sunnywebbox_ip')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <div class="block text-sm font-semibold">Sunny WebBox password
                <div class="mt-1 flex gap-2">
                    <input type="password" name="sunnywebbox_password" id="sunnywebbox_password" autocomplete="new-password" <?= $webboxSet ? 'disabled' : '' ?> placeholder="<?= $webboxSet ? '********' : '' ?>" class="flex-1 border border-slate-200 rounded-xl px-3 py-2 font-normal">
                    <input type="hidden" name="sunnywebbox_password_touched" id="sunnywebbox_password_touched" value="0">
                    <?php if ($webboxSet): ?>
                        <button type="button" class="js-edit-pw px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold" data-target="sunnywebbox_password">Edit</button>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-6 space-y-4 shadow-sm">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Sunny Portal</h2>
            <p class="text-xs text-slate-500">Optional. The one-time pull reads these. An empty timezone uses Europe/Paris. The password is never shown.</p>
            <label class="block text-sm font-semibold">Sunny Portal user
                <input name="sunnyportal_user" value="<?= hp_h(hp_field($settings, 'sunnyportal_user')) ?>" autocomplete="off" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <div class="block text-sm font-semibold">Sunny Portal password
                <div class="mt-1 flex gap-2">
                    <input type="password" name="sunnyportal_password" id="sunnyportal_password" autocomplete="new-password" <?= $portalSet ? 'disabled' : '' ?> placeholder="<?= $portalSet ? '********' : '' ?>" class="flex-1 border border-slate-200 rounded-xl px-3 py-2 font-normal">
                    <input type="hidden" name="sunnyportal_password_touched" id="sunnyportal_password_touched" value="0">
                    <?php if ($portalSet): ?>
                        <button type="button" class="js-edit-pw px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold" data-target="sunnyportal_password">Edit</button>
                    <?php endif; ?>
                </div>
            </div>
            <label class="block text-sm font-semibold">Sunny Portal plant id
                <input name="sunnyportal_plant_id" value="<?= hp_h(hp_field($settings, 'sunnyportal_plant_id')) ?>" autocomplete="off" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <label class="block text-sm font-semibold">Sunny Portal timezone
                <input name="sunnyportal_timezone" value="<?= hp_h(hp_field($settings, 'sunnyportal_timezone')) ?>" placeholder="Europe/Paris" autocomplete="off" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-6 space-y-4 shadow-sm">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Fluvius</h2>
            <p class="text-xs text-slate-500">Optional. The CSV import keeps rows for these EANs only. There is no import button. Put the files in import_queue/fluvius/ and run python3 fluvius_import.py. Grid backfill runs on rollup.py -buildall and -daily.</p>
            <label class="block text-sm font-semibold">Electricity EAN
                <input name="fluvius_ean_electricity" inputmode="numeric" autocomplete="off" value="<?= hp_h(hp_field($settings, 'fluvius_ean_electricity')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <label class="block text-sm font-semibold">Gas EAN
                <input name="fluvius_ean_gas" inputmode="numeric" autocomplete="off" value="<?= hp_h(hp_field($settings, 'fluvius_ean_gas')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-6 space-y-4 shadow-sm">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Plant</h2>
            <p class="text-xs text-slate-500">Spike rejection uses max inverter AC × 1.05. Current cap: <span id="pacCap"><?= hp_h((string)round($capW)) ?></span> W.</p>
            <label class="block text-sm font-semibold">Max inverter AC (W)
                <input type="number" min="1" step="1" name="max_inverter_ac_w" id="max_inverter_ac_w" value="<?= hp_h(hp_field($settings, 'max_inverter_ac_w')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <p class="text-xs text-slate-500">Deep night drops PAC when the hour is &gt;= start or &lt; end. 22 and 5 means 22:00 through 05:00. Shoulder uses the same rule, and only when radiation is at or under the floor.</p>
            <div class="grid grid-cols-2 gap-3">
                <label class="block text-sm font-semibold">Deep night start
                    <input type="number" min="0" max="23" step="1" name="pac_night_start" value="<?= hp_h(hp_field($settings, 'pac_night_start')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
                </label>
                <label class="block text-sm font-semibold">Deep night end
                    <input type="number" min="0" max="23" step="1" name="pac_night_end" value="<?= hp_h(hp_field($settings, 'pac_night_end')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
                </label>
                <label class="block text-sm font-semibold">Shoulder start
                    <input type="number" min="0" max="23" step="1" name="pac_shoulder_start" value="<?= hp_h(hp_field($settings, 'pac_shoulder_start')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
                </label>
                <label class="block text-sm font-semibold">Shoulder end
                    <input type="number" min="0" max="23" step="1" name="pac_shoulder_end" value="<?= hp_h(hp_field($settings, 'pac_shoulder_end')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
                </label>
            </div>
            <label class="block text-sm font-semibold">Radiation floor (W/m²)
                <input type="number" min="0" step="0.1" name="pac_rad_floor" value="<?= hp_h(hp_field($settings, 'pac_rad_floor')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <h3 class="text-sm font-semibold pt-2">Expected generation (kWh per month)</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <?php for ($month = 1; $month <= 12; $month++): ?>
                    <label class="block text-xs font-semibold text-slate-600"><?= hp_h($monthNames[$month]) ?>
                        <input type="number" min="0" step="0.1" name="exp_kwh_<?= $month ?>" value="<?= hp_h((string)$monthly[$month]) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 text-sm font-normal">
                    </label>
                <?php endfor; ?>
            </div>
            <p class="text-xs text-slate-500">Summary year / all-years PVGIS curve reads these values live (all zero = no curve). Monthly <code>target_pct</code> in the DB updates after <code>python3 rollup.py -buildall</code> or <code>-daily</code>.</p>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-6 space-y-4 shadow-sm">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Appearance</h2>
            <label class="block text-sm font-semibold">Site title
                <input name="site_title" value="<?= hp_h(hp_field($settings, 'site_title')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <label class="block text-sm font-semibold">Tagline
                <input name="site_tagline" value="<?= hp_h(hp_field($settings, 'site_tagline')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <label class="block text-sm font-semibold">Page title
                <input name="site_page_title" value="<?= hp_h(hp_field($settings, 'site_page_title')) ?>" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <p class="text-xs text-slate-500">Titles apply on the next page load.</p>
        </section>

        <button type="button" id="saveSettings" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold">Save settings</button>
    </form>

    <section class="bg-white border border-slate-200 rounded-2xl p-6 space-y-3 shadow-sm">
        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Database backup</h2>
        <p class="text-xs text-slate-500">Download is an encrypted copy of the whole database. The plain backup and the encrypted file are deleted on the server after the download. Restore replaces the live database.</p>
        <div class="flex flex-wrap gap-2">
            <button type="button" id="downloadBackup" class="px-4 py-2 rounded-xl border border-slate-200 text-sm font-semibold">Download full backup</button>
            <button type="button" id="openRestore" class="px-4 py-2 rounded-xl border border-rose-200 text-rose-700 text-sm font-semibold">Restore full backup</button>
        </div>
        <div class="border-t border-slate-100 pt-3 space-y-2">
            <p class="text-xs text-slate-500">Download all 1-min samples in CSV (full <code>readings</code> table).</p>
            <button type="button" id="downloadReadingsCsv" class="px-4 py-2 rounded-xl border border-slate-200 text-sm font-semibold">Download 1-min CSV</button>
        </div>
    </section>
    <form id="backupDownloadForm" method="post" class="hidden">
        <input type="hidden" name="action" value="backup_download">
        <input type="hidden" name="admin_password" id="backupDownloadPassword" value="">
    </form>
    <form id="readingsCsvForm" method="post" class="hidden">
        <input type="hidden" name="action" value="readings_csv">
        <input type="hidden" name="admin_password" id="readingsCsvPassword" value="">
    </form>

    <div id="gateModal" class="fixed inset-0 bg-slate-900/40 items-center justify-center p-4" style="display:none">
        <form id="gateForm" class="bg-white rounded-2xl border border-slate-200 p-6 w-full max-w-sm space-y-3 shadow-lg" autocomplete="off">
            <h2 id="gateTitle" class="text-lg font-bold">Admin password</h2>
            <p id="gateError" class="hidden text-sm text-rose-700"></p>
            <label class="block text-sm font-semibold">Admin password
                <input type="password" id="gatePassword" autocomplete="current-password" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2">
            </label>
            <div class="flex justify-end gap-2">
                <button type="button" id="gateCancel" class="px-3 py-2 rounded-xl border border-slate-200 text-sm">Cancel</button>
                <button type="submit" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold">Enter</button>
            </div>
        </form>
    </div>

    <div id="adminModal" class="fixed inset-0 bg-slate-900/40 items-center justify-center p-4" style="display:none">
        <form method="post" class="bg-white rounded-2xl border border-slate-200 p-6 w-full max-w-sm space-y-3 shadow-lg" autocomplete="off">
            <input type="hidden" name="action" value="change_admin">
            <h2 class="text-lg font-bold">Change admin password</h2>
            <label class="block text-sm font-semibold">Current password
                <input type="password" name="admin_current" required autocomplete="current-password" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2">
            </label>
            <label class="block text-sm font-semibold">New password
                <input type="password" name="admin_new" required autocomplete="new-password" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2">
            </label>
            <label class="block text-sm font-semibold">New password again
                <input type="password" name="admin_new2" required autocomplete="new-password" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2">
            </label>
            <div class="flex justify-end gap-2">
                <button type="button" id="adminCancel" class="px-3 py-2 rounded-xl border border-slate-200 text-sm">Cancel</button>
                <button type="submit" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold">Enter</button>
            </div>
        </form>
    </div>

    <div id="restoreModal" class="fixed inset-0 bg-slate-900/40 items-center justify-center p-4" style="display:none">
        <form method="post" enctype="multipart/form-data" class="bg-white rounded-2xl border border-slate-200 p-6 w-full max-w-md space-y-3 shadow-lg" autocomplete="off">
            <input type="hidden" name="action" value="backup_restore">
            <h2 class="text-lg font-bold">Restore the database?</h2>
            <p class="text-sm text-slate-700">Restore replaces the live database. Everything currently in the database will be destroyed. Are you sure?</p>
            <label class="flex items-start gap-2 text-sm font-semibold">
                <input type="checkbox" name="restore_sure" value="yes" required class="mt-1">
                <span>Yes, I am sure. Everything currently in the database will be destroyed.</span>
            </label>
            <label class="block text-sm font-semibold">Encrypted backup
                <input type="file" name="backup_file" accept=".backup,application/octet-stream" required class="mt-1 w-full text-sm font-normal">
            </label>
            <label class="block text-sm font-semibold">Admin password
                <input type="password" name="admin_password" required autocomplete="current-password" class="mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <div class="flex justify-end gap-2">
                <button type="button" id="restoreCancel" class="px-3 py-2 rounded-xl border border-slate-200 text-sm">Cancel</button>
                <button type="submit" class="px-3 py-2 rounded-xl bg-rose-600 text-white text-sm font-semibold">Restore</button>
            </div>
        </form>
    </div>

    <script>
    (function () {
        var gateModal = document.getElementById('gateModal');
        var gateForm = document.getElementById('gateForm');
        var gateTitle = document.getElementById('gateTitle');
        var gatePassword = document.getElementById('gatePassword');
        var gateError = document.getElementById('gateError');
        var gateCallback = null;

        function openGate(title, callback) {
            gateCallback = callback;
            gateTitle.textContent = title;
            gatePassword.value = '';
            gateError.classList.add('hidden');
            gateError.textContent = '';
            gateModal.style.display = 'flex';
            gatePassword.focus();
        }

        function closeGate() {
            gateModal.style.display = 'none';
            gateCallback = null;
            gatePassword.value = '';
        }

        document.getElementById('gateCancel').addEventListener('click', closeGate);
        gateForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (gateCallback) {
                gateCallback(gatePassword.value);
            }
        });

        document.getElementById('saveSettings').addEventListener('click', function () {
            openGate('Enter the admin password to save', function (password) {
                document.getElementById('admin_password').value = password;
                closeGate();
                document.getElementById('settingsForm').submit();
            });
        });

        document.getElementById('downloadBackup').addEventListener('click', function () {
            openGate('Enter the admin password to download the backup', function (password) {
                document.getElementById('backupDownloadPassword').value = password;
                closeGate();
                document.getElementById('backupDownloadForm').submit();
            });
        });

        document.getElementById('downloadReadingsCsv').addEventListener('click', function () {
            openGate('Enter the admin password to download the 1-min CSV', function (password) {
                document.getElementById('readingsCsvPassword').value = password;
                closeGate();
                document.getElementById('readingsCsvForm').submit();
            });
        });

        var restoreModal = document.getElementById('restoreModal');
        document.getElementById('openRestore').addEventListener('click', function () {
            restoreModal.style.display = 'flex';
        });
        document.getElementById('restoreCancel').addEventListener('click', function () {
            restoreModal.style.display = 'none';
        });

        Array.prototype.forEach.call(document.querySelectorAll('.js-edit-pw'), function (button) {
            button.addEventListener('click', function () {
                var targetId = button.getAttribute('data-target');
                openGate('Enter the admin password to edit this field', function (password) {
                    var body = new URLSearchParams();
                    body.set('action', 'verify_admin');
                    body.set('admin_password', password);
                    fetch('settings.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: body.toString()
                    }).then(function (response) {
                        return response.json();
                    }).then(function (data) {
                        if (!data.ok) {
                            gateError.textContent = 'Wrong admin password.';
                            gateError.classList.remove('hidden');
                            return;
                        }
                        var input = document.getElementById(targetId);
                        input.disabled = false;
                        input.value = '';
                        input.placeholder = 'New password';
                        document.getElementById(targetId + '_touched').value = '1';
                        closeGate();
                        input.focus();
                    }).catch(function () {
                        gateError.textContent = 'Could not check the admin password.';
                        gateError.classList.remove('hidden');
                    });
                });
            });
        });

        var adminModal = document.getElementById('adminModal');
        document.getElementById('openAdminChange').addEventListener('click', function () {
            adminModal.style.display = 'flex';
        });
        document.getElementById('adminCancel').addEventListener('click', function () {
            adminModal.style.display = 'none';
        });

        var maxInput = document.getElementById('max_inverter_ac_w');
        var capNode = document.getElementById('pacCap');
        function refreshCap() {
            var watts = parseFloat(maxInput.value);
            if (!isFinite(watts) || watts <= 0) {
                capNode.textContent = '—';
                return;
            }
            capNode.textContent = String(Math.round(watts * 1.05));
        }
        maxInput.addEventListener('input', refreshCap);
    }());
    </script>
<?php endif; ?>
</div>
</body>
</html>
