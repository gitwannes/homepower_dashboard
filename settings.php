<?php
# --- file: settings.php ---
/**
 * First-run admin password and the site configuration page (A6 / A20).
 * Device passwords are never written into the HTML.
 * A9 database download and restore are on this page.
 * A20: page starts locked; Unlock opens a timed edit window.
 * A15: Save probes filled integrations and writes *_active flags.
 * A14: virtual kWh meters (CRUD + Re-bias; index Raw Registers = A21).
 */
date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/site_settings.php';
require_once __DIR__ . '/db_backup.php';

/** How long Settings stays unlocked after a correct admin password (seconds). */
const HP_SETTINGS_UNLOCK_SECONDS = 300;

$boot = hp_boot();
$pdo = $boot['pdo'];
hp_vm_ensure_schema($pdo);
$error = '';
$notice = '';
$posted = null;

/**
 * JSON response helper for virtual-meter AJAX actions.
 *
 * @param array<string, mixed> $payload
 */
function hp_vm_json_exit(array $payload): void
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    if ($action === 'verify_admin') {
        header('Content-Type: application/json; charset=UTF-8');
        $password = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
        echo json_encode(['ok' => hp_admin_ok($pdo, $password)]);
        exit;
    }

    // A14: virtual kWh meter mutations (Unlock window client-side; admin password required).
    if (in_array($action, ['vm_create', 'vm_update', 'vm_delete', 'vm_rebias'], true)) {
        $adminPassword = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
        if ($boot['mode'] === 'need_admin') {
            hp_vm_json_exit(['ok' => false, 'error' => 'Set an admin password first.']);
        }
        if (!hp_admin_ok($pdo, $adminPassword)) {
            hp_vm_json_exit(['ok' => false, 'error' => 'Admin password is wrong.']);
        }
        if ($action === 'vm_create') {
            $result = hp_vm_create($pdo, $_POST);
            hp_vm_json_exit([
                'ok' => $result['ok'],
                'error' => $result['error'],
                'id' => $result['id'],
                'meters' => hp_vm_list_evaluated($pdo),
                'sources' => hp_vm_available_sources($pdo),
            ]);
        }
        if ($action === 'vm_update') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $result = hp_vm_update($pdo, $id, $_POST);
            hp_vm_json_exit([
                'ok' => $result['ok'],
                'error' => $result['error'],
                'meters' => hp_vm_list_evaluated($pdo),
                'sources' => hp_vm_available_sources($pdo),
            ]);
        }
        if ($action === 'vm_delete') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $result = hp_vm_delete($pdo, $id);
            hp_vm_json_exit([
                'ok' => $result['ok'],
                'error' => $result['error'],
                'meters' => hp_vm_list_evaluated($pdo),
                'sources' => hp_vm_available_sources($pdo),
            ]);
        }
        // vm_rebias
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $result = hp_vm_rebias($pdo, $id);
        hp_vm_json_exit([
            'ok' => $result['ok'],
            'error' => $result['error'],
            's0' => $result['s0'],
            'meters' => hp_vm_list_evaluated($pdo),
            'sources' => hp_vm_available_sources($pdo),
        ]);
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
        // A15 follow-up: ajax=1 returns JSON so a failed Save keeps the unlock window in the browser.
        $saveAjax = isset($_POST['ajax']) && (string)$_POST['ajax'] === '1';
        $saveRespond = function (bool $ok, string $message, string $redirect = '') use ($saveAjax): void {
            if (!$saveAjax) {
                return;
            }
            header('Content-Type: application/json; charset=UTF-8');
            $payload = ['ok' => $ok, 'error' => $message];
            if ($ok && $redirect !== '') {
                $payload['redirect'] = $redirect;
            }
            echo json_encode($payload);
            exit;
        };
        if ($boot['mode'] === 'need_admin') {
            $error = 'Set an admin password first.';
            $saveRespond(false, $error);
        } else {
            $adminPassword = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
            $parsed = hp_settings_from_post($boot['stored']);
            if (!hp_admin_ok($pdo, $adminPassword)) {
                $error = 'Admin password is wrong. Nothing was saved.';
                $posted = $parsed['form'];
                $saveRespond(false, $error);
            } elseif (count($parsed['errors']) > 0) {
                $error = implode(' ', $parsed['errors']);
                $posted = $parsed['form'];
                $saveRespond(false, $error);
            } else {
                $plan = hp_integration_save_plan($parsed['settings']);
                if (count($plan['errors']) > 0) {
                    // Atomic: no DB write on partial fill (or any plan error).
                    $error = implode(' ', $plan['errors']);
                    $posted = $parsed['form'];
                    $saveRespond(false, $error);
                } else {
                    @set_time_limit(120);
                    $probeRun = hp_run_integration_probes($parsed['settings'], $plan['probe']);
                    if (!$probeRun['ok']) {
                        $error = 'Save blocked: could not run integration probes. ' . $probeRun['error'];
                        $posted = $parsed['form'];
                        $saveRespond(false, $error);
                    } else {
                        $flagged = hp_apply_integration_probe_flags(
                            $parsed['settings'],
                            $plan['inactive'],
                            $probeRun['results']
                        );
                        $toWrite = $flagged['settings'];
                        $pdo->beginTransaction();
                        try {
                            hp_write_settings($pdo, $toWrite);
                            hp_save_monthly($pdo, $parsed['monthly']);
                            if (count($flagged['warnings']) > 0) {
                                hp_write_settings($pdo, [
                                    'settings_probe_flash' => (string)json_encode($flagged['warnings']),
                                ]);
                            } else {
                                // Clear any previous flash.
                                hp_write_settings($pdo, ['settings_probe_flash' => '']);
                            }
                            $pdo->commit();
                        } catch (Exception $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $error = 'Save failed.';
                            $posted = $parsed['form'];
                            $saveRespond(false, $error);
                        }
                        if ($error === '') {
                            $q = count($flagged['warnings']) > 0 ? 'saved=1&probe_warn=1' : 'saved=1';
                            $redir = 'settings.php?' . $q;
                            if ($saveAjax) {
                                $saveRespond(true, '', $redir);
                            }
                            header('Location: ' . $redir);
                            exit;
                        }
                    }
                }
            }
        }
    } elseif ($action === 'readings_csv') {
        try {
            if ($boot['mode'] === 'need_admin') {
                throw new RuntimeException('Set an admin password first.');
            }
            // A20: 1-min CSV has no admin password (readings export, outside the locked pane).
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
                'Phase L1 (W)',
                'Phase L2 (W)',
                'Phase L3 (W)',
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
            try {
                $stmt = $pdo->query(
                    'SELECT timestamp, active_power, solar_power,
                            power_l1_w, power_l2_w, power_l3_w,
                            import_t1, import_t2, total_import,
                            export_t1, export_t2, total_export,
                            capacity_peak_w, solar_total_kwh,
                            solar_radiation, temp_module, wind_speed,
                            gas, water
                     FROM readings
                     ORDER BY id ASC'
                );
            } catch (Throwable $e) {
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
            }
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, [
                    date('d/m/Y H:i:s', strtotime((string)$row['timestamp'])),
                    $row['active_power'],
                    $row['solar_power'] ?? 0,
                    ($row['power_l1_w'] !== null && $row['power_l1_w'] !== '') ? (int)$row['power_l1_w'] : '',
                    ($row['power_l2_w'] !== null && $row['power_l2_w'] !== '') ? (int)$row['power_l2_w'] : '',
                    ($row['power_l3_w'] !== null && $row['power_l3_w'] !== '') ? (int)$row['power_l3_w'] : '',
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
            // A20: backup download has no admin password (UI keeps the control disabled until unlock).
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

$probeWarnModal = [];
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
if (isset($_GET['probe_warn']) && isset($stored['settings_probe_flash']) && $stored['settings_probe_flash'] !== '') {
    $decodedFlash = json_decode($stored['settings_probe_flash'], true);
    if (is_array($decodedFlash)) {
        $probeWarnModal = $decodedFlash;
    }
    try {
        hp_write_settings($pdo, ['settings_probe_flash' => '']);
        unset($stored['settings_probe_flash']);
    } catch (Exception $e) {
        // Flash clear is best-effort; modal can still show once.
    }
}
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
        $smaExisting !== '',
        isset($_POST['sma_password_clear']) && $_POST['sma_password_clear'] === '1'
    );
    $wbPassword = hp_resolve_password(
        $wbExisting,
        $raw('sunnywebbox_password'),
        isset($_POST['sunnywebbox_password_touched']) && $_POST['sunnywebbox_password_touched'] === '1',
        $wbExisting !== '',
        isset($_POST['sunnywebbox_password_clear']) && $_POST['sunnywebbox_password_clear'] === '1'
    );
    $portalPassword = hp_resolve_password(
        $portalExisting,
        $raw('sunnyportal_password'),
        isset($_POST['sunnyportal_password_touched']) && $_POST['sunnyportal_password_touched'] === '1',
        $portalExisting !== '',
        isset($_POST['sunnyportal_password_clear']) && $_POST['sunnyportal_password_clear'] === '1'
    );

    $settings = [
        'p1_ip' => $p1,
        'sma_ip' => $text('sma_ip'),
        // sma_user is not on the Settings form (removed from UI). SMA WebConnect login
        // does not take a free-text username: the stored value only selects role
        // "istl"/"installer" vs default "usr" (see logger.fetch_sma /
        // integration_probes.probe_sma). Keep whatever is already in the DB for now.
        'sma_user' => isset($stored['sma_user']) ? (string)$stored['sma_user'] : '',
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

/**
 * Status chip next to an integration heading (A15).
 */
function hp_integration_chip_html(string $state): string
{
    if ($state === 'active') {
        return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-emerald-50 text-emerald-700 border border-emerald-200">Active</span>';
    }
    if ($state === 'inactive') {
        return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-rose-50 text-rose-700 border border-rose-200">Inactive</span>';
    }
    return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-slate-50 text-slate-500 border border-slate-200">Not configured</span>';
}

$vmMetersEval = hp_vm_list_evaluated($pdo);
$vmSourcesAvail = hp_vm_available_sources($pdo);
$vmSourceLabels = hp_vm_source_labels();

$chipP1 = hp_integration_chip_state($settings, $stored, 'p1');
$chipSma = hp_integration_chip_state($settings, $stored, 'sma');
$chipWb = hp_integration_chip_state($settings, $stored, 'sunnywebbox');
$chipPortal = hp_integration_chip_state($settings, $stored, 'sunnyportal');
$integrationLabels = hp_integration_labels();
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
            <button type="button" id="openAdminChange" disabled class="js-lock-ctrl inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 disabled:opacity-50 disabled:cursor-not-allowed">Change admin password</button>
        </div>
    </header>

    <section class="bg-white border border-slate-200 rounded-2xl p-6 space-y-3 shadow-sm">
        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Readings export</h2>
        <p class="text-xs text-slate-500">Download all 1-min samples in CSV (full <code>readings</code> table). No admin password.</p>
        <button type="button" id="downloadReadingsCsv" class="px-4 py-2 rounded-xl border border-slate-200 text-sm font-semibold">Download 1-min CSV</button>
    </section>

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
            <p class="text-xs text-slate-500">Save probes each filled device. Active only after a successful probe. Fail or offline still saves credentials but leaves the integration inactive.</p>
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-semibold">P1</span>
                <?= hp_integration_chip_html($chipP1) ?>
            </div>
            <label class="block text-sm font-semibold">P1 address
                <input name="p1_ip" required disabled value="<?= hp_h(hp_field($settings, 'p1_ip')) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <div class="flex items-center justify-between gap-2 pt-2">
                <span class="text-sm font-semibold">SMA inverter</span>
                <?= hp_integration_chip_html($chipSma) ?>
            </div>
            <label class="block text-sm font-semibold">SMA address
                <input name="sma_ip" disabled value="<?= hp_h(hp_field($settings, 'sma_ip')) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <div class="block text-sm font-semibold">SMA password
                <div class="mt-1 flex gap-2 flex-wrap">
                    <input type="password" name="sma_password" id="sma_password" autocomplete="new-password" disabled data-secret-set="<?= $smaSet ? '1' : '0' ?>" placeholder="<?= $smaSet ? '********' : '' ?>" class="js-lock-field flex-1 min-w-[10rem] border border-slate-200 rounded-xl px-3 py-2 font-normal">
                    <input type="hidden" name="sma_password_touched" id="sma_password_touched" value="0">
                    <input type="hidden" name="sma_password_clear" id="sma_password_clear" value="0">
                    <?php if ($smaSet): ?>
                        <button type="button" disabled class="js-edit-pw js-lock-ctrl px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold disabled:opacity-50 disabled:cursor-not-allowed" data-target="sma_password">Edit</button>
                        <button type="button" disabled class="js-clear-pw js-lock-ctrl px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold disabled:opacity-50 disabled:cursor-not-allowed" data-target="sma_password">Clear</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex items-center justify-between gap-2 pt-2">
                <span class="text-sm font-semibold">Sunny WebBox</span>
                <?= hp_integration_chip_html($chipWb) ?>
            </div>
            <label class="block text-sm font-semibold">Sunny WebBox address
                <input name="sunnywebbox_ip" disabled value="<?= hp_h(hp_field($settings, 'sunnywebbox_ip')) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <div class="block text-sm font-semibold">Sunny WebBox password
                <div class="mt-1 flex gap-2 flex-wrap">
                    <input type="password" name="sunnywebbox_password" id="sunnywebbox_password" autocomplete="new-password" disabled data-secret-set="<?= $webboxSet ? '1' : '0' ?>" placeholder="<?= $webboxSet ? '********' : '' ?>" class="js-lock-field flex-1 min-w-[10rem] border border-slate-200 rounded-xl px-3 py-2 font-normal">
                    <input type="hidden" name="sunnywebbox_password_touched" id="sunnywebbox_password_touched" value="0">
                    <input type="hidden" name="sunnywebbox_password_clear" id="sunnywebbox_password_clear" value="0">
                    <?php if ($webboxSet): ?>
                        <button type="button" disabled class="js-edit-pw js-lock-ctrl px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold disabled:opacity-50 disabled:cursor-not-allowed" data-target="sunnywebbox_password">Edit</button>
                        <button type="button" disabled class="js-clear-pw js-lock-ctrl px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold disabled:opacity-50 disabled:cursor-not-allowed" data-target="sunnywebbox_password">Clear</button>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-6 space-y-4 shadow-sm">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Sunny Portal</h2>
                <?= hp_integration_chip_html($chipPortal) ?>
            </div>
            <p class="text-xs text-slate-500">Optional. The pull reads these when active. An empty timezone uses Europe/Paris. The password is never shown.</p>
            <label class="block text-sm font-semibold">Sunny Portal user
                <input name="sunnyportal_user" disabled value="<?= hp_h(hp_field($settings, 'sunnyportal_user')) ?>" autocomplete="off" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <div class="block text-sm font-semibold">Sunny Portal password
                <div class="mt-1 flex gap-2 flex-wrap">
                    <input type="password" name="sunnyportal_password" id="sunnyportal_password" autocomplete="new-password" disabled data-secret-set="<?= $portalSet ? '1' : '0' ?>" placeholder="<?= $portalSet ? '********' : '' ?>" class="js-lock-field flex-1 min-w-[10rem] border border-slate-200 rounded-xl px-3 py-2 font-normal">
                    <input type="hidden" name="sunnyportal_password_touched" id="sunnyportal_password_touched" value="0">
                    <input type="hidden" name="sunnyportal_password_clear" id="sunnyportal_password_clear" value="0">
                    <?php if ($portalSet): ?>
                        <button type="button" disabled class="js-edit-pw js-lock-ctrl px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold disabled:opacity-50 disabled:cursor-not-allowed" data-target="sunnyportal_password">Edit</button>
                        <button type="button" disabled class="js-clear-pw js-lock-ctrl px-3 py-2 rounded-xl border border-slate-200 text-xs font-semibold disabled:opacity-50 disabled:cursor-not-allowed" data-target="sunnyportal_password">Clear</button>
                    <?php endif; ?>
                </div>
                <p class="text-xs text-slate-500 mt-1">Clear removes the stored password on the next successful Save. To drop Portal entirely, Clear the password and empty user + plant id, then Save.</p>
            </div>
            <label class="block text-sm font-semibold">Sunny Portal plant id
                <input name="sunnyportal_plant_id" disabled value="<?= hp_h(hp_field($settings, 'sunnyportal_plant_id')) ?>" autocomplete="off" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <label class="block text-sm font-semibold">Sunny Portal timezone
                <input name="sunnyportal_timezone" disabled value="<?= hp_h(hp_field($settings, 'sunnyportal_timezone')) ?>" placeholder="Europe/Paris" autocomplete="off" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-6 space-y-4 shadow-sm">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Fluvius</h2>
            <p class="text-xs text-slate-500">Optional. The CSV import keeps rows for these EANs only. There is no import button. Put the files in import_queue/fluvius/ and run python3 fluvius_import.py. Grid backfill runs on rollup.py -buildall and -daily.</p>
            <label class="block text-sm font-semibold">Electricity EAN
                <input name="fluvius_ean_electricity" inputmode="numeric" autocomplete="off" disabled value="<?= hp_h(hp_field($settings, 'fluvius_ean_electricity')) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <label class="block text-sm font-semibold">Gas EAN
                <input name="fluvius_ean_gas" inputmode="numeric" autocomplete="off" disabled value="<?= hp_h(hp_field($settings, 'fluvius_ean_gas')) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-6 space-y-4 shadow-sm">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Plant</h2>
            <p class="text-xs text-slate-500">Spike rejection uses max inverter AC × 1.05. Current cap: <span id="pacCap"><?= hp_h((string)round($capW)) ?></span> W.</p>
            <label class="block text-sm font-semibold">Max inverter AC (W)
                <input type="number" min="1" step="1" name="max_inverter_ac_w" id="max_inverter_ac_w" disabled value="<?= hp_h(hp_field($settings, 'max_inverter_ac_w')) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <p class="text-xs text-slate-500">Deep night drops PAC when the hour is &gt;= start or &lt; end. 22 and 5 means 22:00 through 05:00. Shoulder uses the same rule, and only when radiation is at or under the floor.</p>
            <div class="grid grid-cols-2 gap-3">
                <label class="block text-sm font-semibold">Deep night start
                    <input type="number" min="0" max="23" step="1" name="pac_night_start" disabled value="<?= hp_h(hp_field($settings, 'pac_night_start')) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
                </label>
                <label class="block text-sm font-semibold">Deep night end
                    <input type="number" min="0" max="23" step="1" name="pac_night_end" disabled value="<?= hp_h(hp_field($settings, 'pac_night_end')) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
                </label>
                <label class="block text-sm font-semibold">Shoulder start
                    <input type="number" min="0" max="23" step="1" name="pac_shoulder_start" disabled value="<?= hp_h(hp_field($settings, 'pac_shoulder_start')) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
                </label>
                <label class="block text-sm font-semibold">Shoulder end
                    <input type="number" min="0" max="23" step="1" name="pac_shoulder_end" disabled value="<?= hp_h(hp_field($settings, 'pac_shoulder_end')) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
                </label>
            </div>
            <label class="block text-sm font-semibold">Radiation floor (W/m²)
                <input type="number" min="0" step="0.1" name="pac_rad_floor" disabled value="<?= hp_h(hp_field($settings, 'pac_rad_floor')) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <h3 class="text-sm font-semibold pt-2">Expected generation (kWh per month)</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <?php for ($month = 1; $month <= 12; $month++): ?>
                    <label class="block text-xs font-semibold text-slate-600"><?= hp_h($monthNames[$month]) ?>
                        <input type="number" min="0" step="0.1" name="exp_kwh_<?= $month ?>" disabled value="<?= hp_h((string)$monthly[$month]) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 text-sm font-normal">
                    </label>
                <?php endfor; ?>
            </div>
            <p class="text-xs text-slate-500">Summary year / all-years PVGIS curve reads these values live (all zero = no curve). Monthly <code>target_pct</code> in the DB updates after <code>python3 rollup.py -buildall</code> or <code>-daily</code>.</p>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-6 space-y-4 shadow-sm" id="virtualMetersSection">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Virtual kWh meters</h2>
                <a id="vmDebugLink" data-href="virtual_meters_debug.php" class="js-lock-link text-xs font-semibold text-indigo-600 hover:underline pointer-events-none opacity-50 cursor-not-allowed" tabindex="-1" aria-disabled="true">Debug page</a>
            </div>
            <p class="text-xs text-slate-500">Each meter tracks a source lifetime kWh with bias and a percent of new energy after the first-save anchor (<code>S0</code>). Formula: <code>V = (S0 + C) + p * (S - S0)</code> with <code>p = percent/100</code>. Saves here use the admin password and do not run integration probes. Raw Meter Registers display is A21 (checkbox stores the flag only).</p>
            <p id="vmError" class="hidden text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-xl p-3"></p>
            <div id="vmList" class="space-y-4"></div>
            <button type="button" id="vmAddBtn" disabled class="js-lock-ctrl px-3 py-2 rounded-xl border border-slate-200 text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed">Add virtual kWh meter</button>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-6 space-y-4 shadow-sm">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Appearance</h2>
            <label class="block text-sm font-semibold">Site title
                <input name="site_title" disabled value="<?= hp_h(hp_field($settings, 'site_title')) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <label class="block text-sm font-semibold">Tagline
                <input name="site_tagline" disabled value="<?= hp_h(hp_field($settings, 'site_tagline')) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <label class="block text-sm font-semibold">Page title
                <input name="site_page_title" disabled value="<?= hp_h(hp_field($settings, 'site_page_title')) ?>" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 font-normal">
            </label>
            <p class="text-xs text-slate-500">Titles apply on the next page load.</p>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <button type="button" id="primarySettingsAction" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold">Unlock settings</button>
            <span id="unlockCountdown" class="hidden text-xs font-semibold text-slate-600 tabular-nums" aria-live="polite"></span>
        </div>
        <p id="saveInlineError" class="hidden text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-xl p-3"></p>
    </form>

    <section class="bg-white border border-slate-200 rounded-2xl p-6 space-y-3 shadow-sm">
        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Database backup</h2>
        <p class="text-xs text-slate-500">Download is an encrypted copy of the whole database. The plain backup and the encrypted file are deleted on the server after the download. Restore replaces the live database. Unlock Settings before download or restore. Restore always asks for the admin password.</p>
        <div class="flex flex-wrap gap-2">
            <button type="button" id="downloadBackup" disabled class="js-lock-ctrl px-4 py-2 rounded-xl border border-slate-200 text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed">Download full backup</button>
            <button type="button" id="openRestore" disabled class="js-lock-ctrl px-4 py-2 rounded-xl border border-rose-200 text-rose-700 text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed">Restore full backup</button>
        </div>
    </section>
    <form id="backupDownloadForm" method="post" class="hidden">
        <input type="hidden" name="action" value="backup_download">
    </form>
    <form id="readingsCsvForm" method="post" class="hidden">
        <input type="hidden" name="action" value="readings_csv">
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

    <?php if (count($probeWarnModal) > 0): ?>
    <div id="probeWarnModal" class="fixed inset-0 bg-slate-900/40 items-center justify-center p-4" style="display:flex; z-index:50">
        <div class="bg-white rounded-2xl border border-slate-200 p-6 w-full max-w-md space-y-3 shadow-lg">
            <h2 class="text-lg font-bold">Saved, but some integrations stay inactive</h2>
            <p class="text-sm text-slate-600">Credentials were saved. Fix the issues below and Save again to activate.</p>
            <ul class="space-y-2 text-sm">
                <?php foreach ($probeWarnModal as $row): ?>
                    <?php
                    $wid = isset($row['id']) ? (string)$row['id'] : '';
                    $wstatus = isset($row['status']) ? (string)$row['status'] : '';
                    $wmsg = isset($row['message']) ? (string)$row['message'] : '';
                    $wlabel = isset($integrationLabels[$wid]) ? $integrationLabels[$wid] : $wid;
                    ?>
                    <li class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900">
                        <strong><?= hp_h($wlabel) ?></strong>
                        <span class="text-xs uppercase tracking-wide opacity-80">(<?= hp_h($wstatus) ?>)</span>
                        <div class="mt-0.5 font-normal"><?= hp_h($wmsg) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="flex justify-end">
                <button type="button" id="probeWarnOk" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold">OK</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

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

    <div id="timeoutModal" class="fixed inset-0 bg-slate-900/40 items-center justify-center p-4" style="display:none">
        <div class="bg-white rounded-2xl border border-slate-200 p-6 w-full max-w-sm space-y-3 shadow-lg">
            <h2 class="text-lg font-bold">Unlock time expired</h2>
            <p class="text-sm text-slate-700">You still have unsaved changes. Save them (admin password required) or cancel and discard.</p>
            <div class="flex justify-end gap-2">
                <button type="button" id="timeoutCancel" class="px-3 py-2 rounded-xl border border-slate-200 text-sm">Cancel</button>
                <button type="button" id="timeoutSave" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold">Save</button>
            </div>
        </div>
    </div>

    <div id="vmRebiasModal" class="fixed inset-0 bg-slate-900/40 items-center justify-center p-4" style="display:none; z-index:60">
        <div class="bg-white rounded-2xl border border-slate-200 p-6 w-full max-w-sm space-y-3 shadow-lg">
            <h2 class="text-lg font-bold">Re-bias on current reading?</h2>
            <p id="vmRebiasText" class="text-sm text-slate-700">This sets S0 to the current source reading. Bias and factor stay the same. The virtual reading will jump to S0 + bias.</p>
            <div class="flex justify-end gap-2">
                <button type="button" id="vmRebiasCancel" class="px-3 py-2 rounded-xl border border-slate-200 text-sm">Cancel</button>
                <button type="button" id="vmRebiasConfirm" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold">Re-bias</button>
            </div>
        </div>
    </div>

    <script>
    window.HP_VM_BOOT = <?= json_encode([
        'meters' => $vmMetersEval,
        'sources' => $vmSourcesAvail,
        'labels' => $vmSourceLabels,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>

    <script>
    (function () {
        /**
         * Unlock window length in milliseconds.
         * Sourced from PHP HP_SETTINGS_UNLOCK_SECONDS (default 300 = 5 minutes).
         * Change that PHP constant to adjust how long Settings stays unlocked.
         */
        var HP_SETTINGS_UNLOCK_MS = <?= (int)HP_SETTINGS_UNLOCK_SECONDS ?> * 1000;

        var form = document.getElementById('settingsForm');
        var primaryBtn = document.getElementById('primarySettingsAction');
        var adminPasswordField = document.getElementById('admin_password');
        var gateModal = document.getElementById('gateModal');
        var gateForm = document.getElementById('gateForm');
        var gateTitle = document.getElementById('gateTitle');
        var gatePassword = document.getElementById('gatePassword');
        var gateError = document.getElementById('gateError');
        var timeoutModal = document.getElementById('timeoutModal');
        var restoreModal = document.getElementById('restoreModal');
        var adminModal = document.getElementById('adminModal');
        var gateCallback = null;
        var unlocked = false;
        var unlockPassword = '';
        var unlockTimer = null;
        var unlockTick = null;
        var unlockUntil = 0;
        var baseline = '';
        var secretBaseline = {};
        var saveInlineError = document.getElementById('saveInlineError');
        var unlockCountdown = document.getElementById('unlockCountdown');
        var saving = false;

        function showSaveError(message) {
            if (!saveInlineError) {
                return;
            }
            saveInlineError.textContent = message || '';
            if (message) {
                saveInlineError.classList.remove('hidden');
            } else {
                saveInlineError.classList.add('hidden');
            }
        }

        function formatUnlockRemaining(ms) {
            var totalSec = Math.max(0, Math.ceil(ms / 1000));
            var m = Math.floor(totalSec / 60);
            var s = totalSec % 60;
            return m + ':' + (s < 10 ? '0' : '') + s;
        }

        function refreshUnlockCountdown() {
            if (!unlockCountdown) {
                return;
            }
            if (!unlocked || unlockUntil <= 0) {
                unlockCountdown.classList.add('hidden');
                unlockCountdown.textContent = '';
                return;
            }
            var left = unlockUntil - Date.now();
            unlockCountdown.classList.remove('hidden');
            unlockCountdown.textContent = 'Unlock ends in ' + formatUnlockRemaining(left);
        }

        function clearUnlockTick() {
            if (unlockTick !== null) {
                clearInterval(unlockTick);
                unlockTick = null;
            }
        }

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

        function verifyAdmin(password) {
            var body = new URLSearchParams();
            body.set('action', 'verify_admin');
            body.set('admin_password', password);
            return fetch('settings.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body.toString()
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                return !!(data && data.ok);
            });
        }

        function snapshotForm() {
            var data = new FormData(form);
            var parts = [];
            data.forEach(function (value, key) {
                if (key === 'admin_password') {
                    return;
                }
                parts.push(key + '=' + String(value));
            });
            parts.sort();
            return parts.join('&');
        }

        function captureSecretBaseline() {
            secretBaseline = {};
            Array.prototype.forEach.call(document.querySelectorAll('input[data-secret-set]'), function (input) {
                secretBaseline[input.id] = {
                    set: input.getAttribute('data-secret-set') === '1',
                    touched: document.getElementById(input.id + '_touched').value,
                    value: input.value,
                    placeholder: input.placeholder,
                    disabled: true
                };
            });
        }

        function isDirty() {
            if (!unlocked) {
                return false;
            }
            return snapshotForm() !== baseline;
        }

        function setControlsEnabled(enabled) {
            Array.prototype.forEach.call(document.querySelectorAll('.js-lock-ctrl'), function (el) {
                el.disabled = !enabled;
            });
            // Virtual meter Save depends on dirty state, not unlock alone.
            if (typeof vmRefreshSaveButtons === 'function') {
                vmRefreshSaveButtons();
            }
            // Unlock-only links (e.g. virtual meters debug): strip href while locked.
            Array.prototype.forEach.call(document.querySelectorAll('.js-lock-link'), function (el) {
                var target = el.getAttribute('data-href') || '';
                if (enabled && target !== '') {
                    el.setAttribute('href', target);
                    el.classList.remove('pointer-events-none', 'opacity-50', 'cursor-not-allowed');
                    el.removeAttribute('aria-disabled');
                    el.setAttribute('tabindex', '0');
                } else {
                    el.removeAttribute('href');
                    el.classList.add('pointer-events-none', 'opacity-50', 'cursor-not-allowed');
                    el.setAttribute('aria-disabled', 'true');
                    el.setAttribute('tabindex', '-1');
                }
            });
            Array.prototype.forEach.call(document.querySelectorAll('.js-lock-field'), function (el) {
                if (el.getAttribute('data-secret-set') === '1') {
                    // Set secrets stay masked until Edit; Edit button is a js-lock-ctrl.
                    el.disabled = true;
                    return;
                }
                if (el.hasAttribute('data-secret-set') && el.getAttribute('data-secret-set') === '0') {
                    el.disabled = !enabled;
                    return;
                }
                el.disabled = !enabled;
            });
            // After unlock, a secret that was already opened for edit stays editable.
            if (enabled) {
                Array.prototype.forEach.call(document.querySelectorAll('input[data-secret-set="1"]'), function (input) {
                    var touched = document.getElementById(input.id + '_touched');
                    if (touched && touched.value === '1') {
                        input.disabled = false;
                    }
                });
            }
        }

        function refreshPrimaryButton() {
            if (!unlocked) {
                primaryBtn.style.display = '';
                primaryBtn.textContent = 'Unlock settings';
                primaryBtn.disabled = false;
                return;
            }
            if (isDirty()) {
                primaryBtn.style.display = '';
                primaryBtn.textContent = 'Save settings';
                primaryBtn.disabled = false;
                return;
            }
            primaryBtn.style.display = 'none';
        }

        function clearUnlockTimer() {
            if (unlockTimer !== null) {
                clearTimeout(unlockTimer);
                unlockTimer = null;
            }
            clearUnlockTick();
            unlockUntil = 0;
            refreshUnlockCountdown();
        }

        function lockPage() {
            clearUnlockTimer();
            unlocked = false;
            unlockPassword = '';
            adminPasswordField.value = '';
            saving = false;
            showSaveError('');
            timeoutModal.style.display = 'none';
            setControlsEnabled(false);
            // Restore secret fields to the loaded baseline (discard in-page edits).
            Object.keys(secretBaseline).forEach(function (id) {
                var input = document.getElementById(id);
                var state = secretBaseline[id];
                if (!input || !state) {
                    return;
                }
                input.value = state.value;
                input.placeholder = state.placeholder;
                input.disabled = true;
                document.getElementById(id + '_touched').value = '0';
                var clearFlag = document.getElementById(id + '_clear');
                if (clearFlag) {
                    clearFlag.value = '0';
                }
            });
            form.reset();
            // reset() restores HTML defaults; re-apply locked disabled state.
            setControlsEnabled(false);
            baseline = '';
            refreshPrimaryButton();
        }

        function startUnlockWindow(password) {
            unlocked = true;
            unlockPassword = password;
            showSaveError('');
            setControlsEnabled(true);
            baseline = snapshotForm();
            captureSecretBaseline();
            clearUnlockTimer();
            unlockUntil = Date.now() + HP_SETTINGS_UNLOCK_MS;
            unlockTimer = setTimeout(onUnlockExpired, HP_SETTINGS_UNLOCK_MS);
            unlockTick = setInterval(refreshUnlockCountdown, 1000);
            refreshUnlockCountdown();
            refreshPrimaryButton();
        }

        function onUnlockExpired() {
            unlockTimer = null;
            clearUnlockTick();
            unlockUntil = 0;
            refreshUnlockCountdown();
            if (!unlocked) {
                return;
            }
            if (!isDirty()) {
                lockPage();
                return;
            }
            // End the silent-save window; further Save must ask for the password.
            unlockPassword = '';
            timeoutModal.style.display = 'flex';
        }

        function enableFieldsForSubmit() {
            Array.prototype.forEach.call(form.querySelectorAll('input, select, textarea'), function (el) {
                if (el.type === 'hidden') {
                    return;
                }
                el.disabled = false;
            });
        }

        function restoreLockFieldStateAfterAjax() {
            // After enabling everything for FormData, put lock state back without discarding edits.
            setControlsEnabled(true);
        }

        function submitSave(password) {
            if (saving) {
                return;
            }
            saving = true;
            showSaveError('');
            primaryBtn.disabled = true;
            primaryBtn.textContent = 'Saving...';
            adminPasswordField.value = password;
            enableFieldsForSubmit();
            var body = new FormData(form);
            body.set('ajax', '1');
            fetch('settings.php', {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json().then(function (data) {
                    return { httpOk: response.ok, data: data };
                }).catch(function () {
                    throw new Error('Save returned a non-JSON response.');
                });
            }).then(function (pack) {
                var data = pack.data || {};
                if (data.ok && data.redirect) {
                    // Successful Save: lock by navigating (A20 reload locked).
                    window.location.href = data.redirect;
                    return;
                }
                saving = false;
                restoreLockFieldStateAfterAjax();
                showSaveError(data.error || 'Save failed. Nothing was written.');
                refreshPrimaryButton();
            }).catch(function (err) {
                saving = false;
                restoreLockFieldStateAfterAjax();
                showSaveError(err && err.message ? err.message : 'Save failed. Nothing was written.');
                refreshPrimaryButton();
            });
        }

        document.getElementById('gateCancel').addEventListener('click', closeGate);
        gateForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (gateCallback) {
                gateCallback(gatePassword.value);
            }
        });

        primaryBtn.addEventListener('click', function () {
            if (!unlocked) {
                openGate('Enter the admin password to unlock settings', function (password) {
                    verifyAdmin(password).then(function (ok) {
                        if (!ok) {
                            gateError.textContent = 'Wrong admin password.';
                            gateError.classList.remove('hidden');
                            return;
                        }
                        closeGate();
                        startUnlockWindow(password);
                    }).catch(function () {
                        gateError.textContent = 'Could not check the admin password.';
                        gateError.classList.remove('hidden');
                    });
                });
                return;
            }
            if (!isDirty()) {
                return;
            }
            if (unlockPassword !== '') {
                submitSave(unlockPassword);
                return;
            }
            openGate('Enter the admin password to save', function (password) {
                verifyAdmin(password).then(function (ok) {
                    if (!ok) {
                        gateError.textContent = 'Wrong admin password.';
                        gateError.classList.remove('hidden');
                        return;
                    }
                    closeGate();
                    submitSave(password);
                }).catch(function () {
                    gateError.textContent = 'Could not check the admin password.';
                    gateError.classList.remove('hidden');
                });
            });
        });

        form.addEventListener('input', refreshPrimaryButton);
        form.addEventListener('change', refreshPrimaryButton);

        document.getElementById('downloadBackup').addEventListener('click', function () {
            if (!unlocked) {
                return;
            }
            document.getElementById('backupDownloadForm').submit();
        });

        document.getElementById('downloadReadingsCsv').addEventListener('click', function () {
            document.getElementById('readingsCsvForm').submit();
        });

        document.getElementById('openRestore').addEventListener('click', function () {
            if (!unlocked) {
                return;
            }
            restoreModal.style.display = 'flex';
        });
        document.getElementById('restoreCancel').addEventListener('click', function () {
            restoreModal.style.display = 'none';
        });

        document.getElementById('timeoutCancel').addEventListener('click', function () {
            timeoutModal.style.display = 'none';
            lockPage();
        });
        document.getElementById('timeoutSave').addEventListener('click', function () {
            timeoutModal.style.display = 'none';
            openGate('Enter the admin password to save', function (password) {
                verifyAdmin(password).then(function (ok) {
                    if (!ok) {
                        gateError.textContent = 'Wrong admin password.';
                        gateError.classList.remove('hidden');
                        return;
                    }
                    closeGate();
                    submitSave(password);
                }).catch(function () {
                    gateError.textContent = 'Could not check the admin password.';
                    gateError.classList.remove('hidden');
                });
            });
        });

        Array.prototype.forEach.call(document.querySelectorAll('.js-edit-pw'), function (button) {
            button.addEventListener('click', function () {
                if (!unlocked) {
                    return;
                }
                var targetId = button.getAttribute('data-target');
                var input = document.getElementById(targetId);
                input.disabled = false;
                input.value = '';
                input.placeholder = 'New password';
                document.getElementById(targetId + '_touched').value = '1';
                var clearFlag = document.getElementById(targetId + '_clear');
                if (clearFlag) {
                    clearFlag.value = '0';
                }
                refreshPrimaryButton();
                input.focus();
            });
        });

        Array.prototype.forEach.call(document.querySelectorAll('.js-clear-pw'), function (button) {
            button.addEventListener('click', function () {
                if (!unlocked) {
                    return;
                }
                var targetId = button.getAttribute('data-target');
                var input = document.getElementById(targetId);
                input.disabled = false;
                input.value = '';
                input.placeholder = '(will clear on Save)';
                input.setAttribute('data-secret-set', '0');
                document.getElementById(targetId + '_touched').value = '1';
                var clearFlag = document.getElementById(targetId + '_clear');
                if (clearFlag) {
                    clearFlag.value = '1';
                }
                refreshPrimaryButton();
            });
        });

        document.getElementById('openAdminChange').addEventListener('click', function () {
            if (!unlocked) {
                return;
            }
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
                capNode.textContent = '-';
                return;
            }
            capNode.textContent = String(Math.round(watts * 1.05));
        }
        maxInput.addEventListener('input', refreshCap);

        var probeWarnOk = document.getElementById('probeWarnOk');
        if (probeWarnOk) {
            probeWarnOk.addEventListener('click', function () {
                var modal = document.getElementById('probeWarnModal');
                if (modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // --- A14 virtual kWh meters ---
        var vmBoot = window.HP_VM_BOOT || { meters: [], sources: [], labels: {} };
        var vmMeters = Array.isArray(vmBoot.meters) ? vmBoot.meters.slice() : [];
        var vmSources = Array.isArray(vmBoot.sources) ? vmBoot.sources.slice() : [];
        var vmLabels = vmBoot.labels || {};
        var vmListEl = document.getElementById('vmList');
        var vmErrorEl = document.getElementById('vmError');
        var vmAddBtn = document.getElementById('vmAddBtn');
        var vmRebiasModal = document.getElementById('vmRebiasModal');
        var vmRebiasPendingId = 0;
        var vmDraftSeq = 0;

        function vmShowError(message) {
            if (!vmErrorEl) {
                return;
            }
            vmErrorEl.textContent = message || '';
            if (message) {
                vmErrorEl.classList.remove('hidden');
            } else {
                vmErrorEl.classList.add('hidden');
            }
        }

        function vmFmt(n, digits) {
            if (n === null || n === undefined || !isFinite(Number(n))) {
                return '-';
            }
            return Number(n).toLocaleString('en-GB', {
                minimumFractionDigits: digits,
                maximumFractionDigits: digits
            });
        }

        function vmSourceOptionsHtml(selected, includeMissing) {
            var html = '';
            var seen = {};
            vmSources.forEach(function (s) {
                seen[s.source] = true;
                html += '<option value="' + s.source + '"' + (s.source === selected ? ' selected' : '') + '>'
                    + s.label + ' (' + vmFmt(s.value, 3) + ' kWh)</option>';
            });
            if (includeMissing && selected && !seen[selected]) {
                var lab = vmLabels[selected] || selected;
                html += '<option value="' + selected + '" selected>' + lab + ' (unavailable now)</option>';
            }
            return html;
        }

        function vmCardHtml(meter, isDraft) {
            var id = isDraft ? ('draft-' + meter._draft) : String(meter.id);
            var name = meter.name || '';
            var source = meter.source || (vmSources[0] ? vmSources[0].source : '');
            var bias = meter.bias_kwh != null ? meter.bias_kwh : 0;
            var factor = meter.factor_percent != null ? meter.factor_percent : 100;
            var showRaw = Number(meter.show_in_raw_registers) === 1;
            var sourceOk = meter.source_ok !== false;
            var sTxt = sourceOk ? vmFmt(meter.S, 3) + ' kWh' : 'unavailable';
            var vTxt = (meter.V !== null && meter.V !== undefined) ? vmFmt(meter.V, 3) + ' kWh' : '-';
            var s0Txt = isDraft ? '(set on first save)' : vmFmt(meter.S0 != null ? meter.S0 : meter.s0_kwh, 3) + ' kWh';
            var title = isDraft ? 'New virtual meter' : ('Meter #' + meter.id);
            return ''
                + '<div class="border border-slate-200 rounded-xl p-4 space-y-3 bg-slate-50/40" data-vm-id="' + id + '" data-vm-draft="' + (isDraft ? '1' : '0') + '">'
                + '<div class="flex flex-wrap items-center justify-between gap-2">'
                + '<h3 class="text-sm font-bold text-slate-800">' + title + '</h3>'
                + (isDraft ? '' : '<span class="text-[11px] text-slate-500">S0 ' + s0Txt + '</span>')
                + '</div>'
                + '<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">'
                + '<label class="block text-xs font-semibold text-slate-600">Name'
                + '<input type="text" data-vm-field="name" disabled value="' + String(name).replace(/"/g, '&quot;') + '" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 text-sm font-normal">'
                + '</label>'
                + '<label class="block text-xs font-semibold text-slate-600">Source'
                + '<select data-vm-field="source" disabled class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 text-sm font-normal">'
                + vmSourceOptionsHtml(source, !isDraft)
                + '</select></label>'
                + '<label class="block text-xs font-semibold text-slate-600">Bias C (kWh)'
                + '<input type="number" step="any" data-vm-field="bias_kwh" disabled value="' + bias + '" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 text-sm font-normal">'
                + '</label>'
                + '<label class="block text-xs font-semibold text-slate-600">Factor % (max 4 decimals)'
                + '<input type="number" step="0.0001" data-vm-field="factor_percent" disabled value="' + factor + '" class="js-lock-field mt-1 w-full border border-slate-200 rounded-xl px-3 py-2 text-sm font-normal">'
                + '</label>'
                + '</div>'
                + '<label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700">'
                + '<input type="checkbox" data-vm-field="show_in_raw_registers" disabled class="js-lock-field" ' + (showRaw ? 'checked' : '') + '>'
                + 'Show in Raw Meter Registers (flag; UI in A21)</label>'
                + '<div class="text-xs text-slate-600 space-y-0.5">'
                + '<div>Source reading S: <strong class="font-mono">' + sTxt + '</strong>'
                + (meter.source_detail ? ' <span class="text-slate-400">(' + meter.source_detail + ')</span>' : '')
                + '</div>'
                + '<div>Virtual reading V: <strong class="font-mono">' + vTxt + '</strong></div>'
                + '</div>'
                + '<div class="flex flex-wrap gap-2">'
                // Save is not js-lock-ctrl: enabled only when unlocked AND (draft or dirty).
                + '<button type="button" data-vm-act="save" disabled class="js-vm-save px-3 py-1.5 rounded-xl bg-indigo-600 text-white text-xs font-semibold disabled:opacity-50 disabled:cursor-not-allowed">Save meter</button>'
                + (isDraft
                    ? '<button type="button" data-vm-act="cancel-draft" disabled class="js-lock-ctrl px-3 py-1.5 rounded-xl border border-slate-200 text-xs font-semibold disabled:opacity-50">Cancel</button>'
                    : '<button type="button" data-vm-act="rebias" disabled class="js-lock-ctrl px-3 py-1.5 rounded-xl border border-slate-200 text-xs font-semibold disabled:opacity-50">Re-bias on current reading</button>'
                      + '<button type="button" data-vm-act="delete" disabled class="js-lock-ctrl px-3 py-1.5 rounded-xl border border-rose-200 text-rose-700 text-xs font-semibold disabled:opacity-50">Delete</button>')
                + '</div></div>';
        }

        function vmSnapshotFields(fields) {
            return [
                fields.name || '',
                fields.source || '',
                String(fields.bias_kwh != null ? fields.bias_kwh : '0'),
                String(fields.factor_percent != null ? fields.factor_percent : '100'),
                fields.show_in_raw_registers ? '1' : '0'
            ].join('\n');
        }

        function vmReadCard(card) {
            var nameEl = card.querySelector('[data-vm-field="name"]');
            var sourceEl = card.querySelector('[data-vm-field="source"]');
            var biasEl = card.querySelector('[data-vm-field="bias_kwh"]');
            var factorEl = card.querySelector('[data-vm-field="factor_percent"]');
            var showEl = card.querySelector('[data-vm-field="show_in_raw_registers"]');
            return {
                name: nameEl ? nameEl.value : '',
                source: sourceEl ? sourceEl.value : '',
                bias_kwh: biasEl ? biasEl.value : '0',
                factor_percent: factorEl ? factorEl.value : '100',
                show_in_raw_registers: showEl && showEl.checked ? '1' : ''
            };
        }

        function vmCardIsDirty(card) {
            if (card.getAttribute('data-vm-draft') === '1') {
                return true;
            }
            var baseline = card.getAttribute('data-vm-baseline') || '';
            return vmSnapshotFields(vmReadCard(card)) !== baseline;
        }

        function vmRefreshSaveButtons() {
            if (!vmListEl) {
                return;
            }
            Array.prototype.forEach.call(vmListEl.querySelectorAll('[data-vm-id]'), function (card) {
                var saveBtn = card.querySelector('.js-vm-save');
                if (!saveBtn) {
                    return;
                }
                // Drafts: Save while unlocked. Saved meters: only when dirty.
                var allow = unlocked && vmCardIsDirty(card);
                saveBtn.disabled = !allow;
            });
        }

        function vmRender() {
            if (!vmListEl) {
                return;
            }
            var html = '';
            vmMeters.forEach(function (m) {
                html += vmCardHtml(m, !!m._draft);
            });
            if (!html) {
                html = '<p class="text-xs text-slate-500">No virtual meters yet.</p>';
            }
            vmListEl.innerHTML = html;
            // Capture clean baseline for each saved card.
            Array.prototype.forEach.call(vmListEl.querySelectorAll('[data-vm-id][data-vm-draft="0"]'), function (card) {
                card.setAttribute('data-vm-baseline', vmSnapshotFields(vmReadCard(card)));
            });
            setControlsEnabled(unlocked);
            vmRefreshSaveButtons();
        }

        function vmPost(action, fields) {
            var body = new URLSearchParams();
            body.set('action', action);
            body.set('admin_password', unlockPassword || '');
            Object.keys(fields).forEach(function (key) {
                body.set(key, fields[key]);
            });
            return fetch('settings.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body.toString()
            }).then(function (response) {
                return response.json();
            });
        }

        function vmApplyServer(data) {
            if (data.sources) {
                vmSources = data.sources;
            }
            if (data.meters) {
                // Keep any unfinished drafts.
                var drafts = vmMeters.filter(function (m) { return !!m._draft; });
                vmMeters = data.meters.concat(drafts);
            }
            vmRender();
        }

        function vmWithPassword(thenFn) {
            if (unlockPassword) {
                thenFn();
                return;
            }
            openGate('Admin password', function (password) {
                verifyAdmin(password).then(function (ok) {
                    if (!ok) {
                        gateError.textContent = 'Wrong password.';
                        gateError.classList.remove('hidden');
                        return;
                    }
                    unlockPassword = password;
                    adminPasswordField.value = password;
                    closeGate();
                    thenFn();
                });
            });
        }

        if (vmAddBtn) {
            vmAddBtn.addEventListener('click', function () {
                if (!unlocked) {
                    return;
                }
                if (vmSources.length === 0) {
                    vmShowError('No source lifetime readings available yet. Wait for a logger poll.');
                    return;
                }
                vmDraftSeq += 1;
                vmMeters.push({
                    _draft: vmDraftSeq,
                    name: '',
                    source: vmSources[0].source,
                    bias_kwh: 0,
                    factor_percent: 100,
                    show_in_raw_registers: 0,
                    source_ok: true,
                    S: vmSources[0].value,
                    V: null,
                    source_detail: vmSources[0].detail
                });
                vmShowError('');
                vmRender();
            });
        }

        if (vmListEl) {
            vmListEl.addEventListener('input', function (ev) {
                if (ev.target && ev.target.closest('[data-vm-field]')) {
                    vmRefreshSaveButtons();
                }
            });
            vmListEl.addEventListener('change', function (ev) {
                if (ev.target && ev.target.closest('[data-vm-field]')) {
                    vmRefreshSaveButtons();
                }
            });
            vmListEl.addEventListener('click', function (ev) {
                var btn = ev.target.closest('[data-vm-act]');
                if (!btn || !unlocked) {
                    return;
                }
                var card = btn.closest('[data-vm-id]');
                if (!card) {
                    return;
                }
                var act = btn.getAttribute('data-vm-act');
                var idAttr = card.getAttribute('data-vm-id') || '';
                var isDraft = card.getAttribute('data-vm-draft') === '1';

                if (act === 'cancel-draft') {
                    vmMeters = vmMeters.filter(function (m) {
                        return !m._draft || ('draft-' + m._draft) !== idAttr;
                    });
                    vmRender();
                    return;
                }

                if (act === 'save') {
                    var fields = vmReadCard(card);
                    vmWithPassword(function () {
                        var action = isDraft ? 'vm_create' : 'vm_update';
                        if (!isDraft) {
                            fields.id = idAttr;
                        }
                        vmPost(action, fields).then(function (data) {
                            if (!data || !data.ok) {
                                vmShowError((data && data.error) ? data.error : 'Save failed.');
                                return;
                            }
                            vmShowError('');
                            // Drop matching draft after create.
                            if (isDraft) {
                                vmMeters = vmMeters.filter(function (m) {
                                    return !m._draft || ('draft-' + m._draft) !== idAttr;
                                });
                            }
                            vmApplyServer(data);
                        }).catch(function () {
                            vmShowError('Save failed (network).');
                        });
                    });
                    return;
                }

                if (act === 'delete') {
                    if (!window.confirm('Delete this virtual meter?')) {
                        return;
                    }
                    vmWithPassword(function () {
                        vmPost('vm_delete', { id: idAttr }).then(function (data) {
                            if (!data || !data.ok) {
                                vmShowError((data && data.error) ? data.error : 'Delete failed.');
                                return;
                            }
                            vmShowError('');
                            vmApplyServer(data);
                        }).catch(function () {
                            vmShowError('Delete failed (network).');
                        });
                    });
                    return;
                }

                if (act === 'rebias') {
                    vmRebiasPendingId = parseInt(idAttr, 10) || 0;
                    vmRebiasModal.style.display = 'flex';
                }
            });
        }

        document.getElementById('vmRebiasCancel').addEventListener('click', function () {
            vmRebiasPendingId = 0;
            vmRebiasModal.style.display = 'none';
        });
        document.getElementById('vmRebiasConfirm').addEventListener('click', function () {
            var id = vmRebiasPendingId;
            vmRebiasModal.style.display = 'none';
            if (!id) {
                return;
            }
            vmWithPassword(function () {
                vmPost('vm_rebias', { id: String(id) }).then(function (data) {
                    if (!data || !data.ok) {
                        vmShowError((data && data.error) ? data.error : 'Re-bias failed.');
                        return;
                    }
                    vmShowError('');
                    vmApplyServer(data);
                }).catch(function () {
                    vmShowError('Re-bias failed (network).');
                });
            });
        });

        vmRender();

        // Page load: locked. Successful save redirects here already locked.
        refreshPrimaryButton();
    }());
    </script>
<?php endif; ?>
</div>
</body>
</html>
