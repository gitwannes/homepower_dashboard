<?php
# --- file: site_settings.php ---
/**
 * Site settings in meter_history.db (A6).
 * Key names and the x1.05 spike rule match site_config.py.
 * DB_BACKUP_ENCRYPTION_KEY is never read or stored here.
 */

/**
 * Database path. Matches index.php. HP_DB_FILE is a local test override only.
 */
function hp_db_path(): string
{
    $override = getenv('HP_DB_FILE');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    $isSynology = file_exists('/etc/synoinfo.conf') || file_exists('/etc.defaults/synoinfo.conf');
    if (!$isSynology) {
        if (file_exists('/var/log/p1/meter_history.db') || (is_dir('/var/log/p1') && is_readable('/var/log/p1'))) {
            return '/var/log/p1/meter_history.db';
        }
        if (file_exists('/dev/shm/meter_history.db') || (is_dir('/dev/shm') && is_readable('/dev/shm'))) {
            return '/dev/shm/meter_history.db';
        }
    }
    return __DIR__ . '/meter_history.db';
}

/**
 * .env key => app_settings key. Encryption key is not in this map.
 *
 * @return array<string, string>
 */
function hp_env_map(): array
{
    return [
        'P1_IP' => 'p1_ip',
        'SMA_IP' => 'sma_ip',
        'SMA_USER' => 'sma_user',
        'SMA_PASSWORD' => 'sma_password',
        'SUNNYWEBBOX_IP' => 'sunnywebbox_ip',
        'SUNNYWEBBOX_PASSWORD' => 'sunnywebbox_password',
        'SITE_TITLE' => 'site_title',
        'SITE_TAGLINE' => 'site_tagline',
        'SITE_PAGE_TITLE' => 'site_page_title',
    ];
}

/**
 * Sunny Portal .env names => app_settings key.
 * SUNNYPORTAL_* wins. ENNEXOS_* is the previous name of the same four values.
 *
 * @return array<int, array{0: array<int, string>, 1: string}>
 */
function hp_sunnyportal_env(): array
{
    return [
        [['SUNNYPORTAL_USER', 'ENNEXOS_USER'], 'sunnyportal_user'],
        [['SUNNYPORTAL_PASSWORD', 'ENNEXOS_PASSWORD'], 'sunnyportal_password'],
        [['SUNNYPORTAL_PLANT_ID', 'ENNEXOS_PLANT_ID'], 'sunnyportal_plant_id'],
        [['SUNNYPORTAL_TIMEZONE', 'ENNEXOS_TIMEZONE'], 'sunnyportal_timezone'],
    ];
}

/**
 * Display defaults. Stored rows override these.
 *
 * @return array<string, string>
 */
function hp_setting_defaults(): array
{
    return [
        'p1_ip' => '',
        'sma_ip' => '',
        'sma_user' => '',
        'sma_password' => '',
        'sunnywebbox_ip' => '',
        'sunnywebbox_password' => '',
        'site_title' => 'Energy Dashboard',
        'site_tagline' => 'Home Energy Hub',
        'site_page_title' => 'Energy Dashboard',
        'sunnyportal_user' => '',
        'sunnyportal_password' => '',
        'sunnyportal_plant_id' => '',
        'sunnyportal_timezone' => '',
        'max_inverter_ac_w' => '12000',
        'pac_night_start' => '22',
        'pac_night_end' => '5',
        'pac_shoulder_start' => '20',
        'pac_shoulder_end' => '6',
        'pac_rad_floor' => '5',
        'fluvius_ean_electricity' => '',
        'fluvius_ean_gas' => '',
    ];
}

/**
 * PVGIS seed for inclination 30. kWh is the form default; irr is inserted only
 * when a month row is missing. Saves never overwrite expected_irr.
 *
 * @return array<int, array{kwh: float, irr: float}>
 */
function hp_month_seed(): array
{
    return [
        1 => ['kwh' => 23.1, 'irr' => 27.9],
        2 => ['kwh' => 44.5, 'irr' => 54.5],
        3 => ['kwh' => 63.5, 'irr' => 79.5],
        4 => ['kwh' => 91.4, 'irr' => 118.0],
        5 => ['kwh' => 110.0, 'irr' => 145.0],
        6 => ['kwh' => 102.0, 'irr' => 138.0],
        7 => ['kwh' => 112.0, 'irr' => 152.0],
        8 => ['kwh' => 102.0, 'irr' => 137.0],
        9 => ['kwh' => 73.4, 'irr' => 96.7],
        10 => ['kwh' => 53.5, 'irr' => 68.3],
        11 => ['kwh' => 29.9, 'irr' => 36.7],
        12 => ['kwh' => 18.1, 'irr' => 21.9],
    ];
}

function hp_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function hp_pdo(): PDO
{
    $pdo = new PDO('sqlite:' . hp_db_path());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 10000');
    return $pdo;
}

function hp_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_admin (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            password_hash TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS expected_monthly (
            id INTEGER PRIMARY KEY,
            month INTEGER NOT NULL,
            inclination INTEGER NOT NULL,
            expected_kwh REAL NOT NULL,
            expected_irr REAL NOT NULL,
            UNIQUE(month, inclination)
        )'
    );
}

/**
 * @return array<string, string>
 */
function hp_read_env(): array
{
    $candidates = [
        __DIR__ . '/.env',
        dirname(__DIR__) . '/.env',
    ];
    $envPath = null;
    foreach ($candidates as $candidate) {
        if (file_exists($candidate) && is_readable($candidate)) {
            $envPath = $candidate;
            break;
        }
    }
    if ($envPath === null) {
        return [];
    }
    $config = [];
    $lines = file($envPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        list($key, $value) = explode('=', $line, 2);
        $config[trim($key)] = trim(trim($value), "'\"");
    }
    return $config;
}

/**
 * Copy Sunny Portal keys when that settings row is still absent.
 * SUNNYPORTAL_* wins over the previous ENNEXOS_* name. A saved row, including
 * an empty one, is left alone. Does not copy DB_BACKUP_ENCRYPTION_KEY.
 *
 * @param array<string, string>|null $env
 */
function hp_import_sunnyportal_if_missing(PDO $pdo, ?array $env = null): bool
{
    hp_ensure_schema($pdo);
    $source = $env === null ? hp_read_env() : $env;
    $stored = hp_load_stored($pdo);
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $inserted = false;
        $stmt = $pdo->prepare('INSERT INTO app_settings (key, value) VALUES (?, ?)');
        foreach (hp_sunnyportal_env() as $pair) {
            $envKeys = $pair[0];
            $settingKey = $pair[1];
            if (array_key_exists($settingKey, $stored)) {
                continue;
            }
            $value = '';
            foreach ($envKeys as $envKey) {
                if (isset($source[$envKey]) && trim((string)$source[$envKey]) !== '') {
                    $value = (string)$source[$envKey];
                    break;
                }
            }
            if ($value === '') {
                continue;
            }
            $stmt->execute([$settingKey, $value]);
            $inserted = true;
        }
        $pdo->exec('COMMIT');
        return $inserted;
    } catch (Exception $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Exception $ignored) {
        }
        throw $e;
    }
}

/**
 * Copy device and title keys once when app_settings is empty.
 * Does not rewrite .env. Does not copy DB_BACKUP_ENCRYPTION_KEY.
 * Sunny Portal keys are filled afterwards when those rows are still absent.
 *
 * @param array<string, string>|null $env
 */
function hp_import_env_if_empty(PDO $pdo, ?array $env = null): bool
{
    hp_ensure_schema($pdo);
    $source = $env === null ? hp_read_env() : $env;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM app_settings')->fetchColumn();
        if ($count > 0) {
            $pdo->exec('COMMIT');
            $inserted = false;
        } else {
            $inserted = false;
            $stmt = $pdo->prepare('INSERT INTO app_settings (key, value) VALUES (?, ?)');
            foreach (hp_env_map() as $envKey => $settingKey) {
                if (!array_key_exists($envKey, $source)) {
                    continue;
                }
                $stmt->execute([$settingKey, (string)$source[$envKey]]);
                $inserted = true;
            }
            if ($inserted) {
                $stmt->execute(['env_imported', '1']);
            }
            $pdo->exec('COMMIT');
        }
    } catch (Exception $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Exception $ignored) {
        }
        throw $e;
    }
    hp_import_sunnyportal_if_missing($pdo, $source);
    return $inserted;
}

/**
 * @return array<string, string>
 */
function hp_load_stored(PDO $pdo): array
{
    hp_ensure_schema($pdo);
    $stored = [];
    $stmt = $pdo->query('SELECT key, value FROM app_settings');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stored[(string)$row['key']] = $row['value'] === null ? '' : (string)$row['value'];
    }
    return $stored;
}

/**
 * @param array<string, string> $stored
 * @return array<string, string>
 */
function hp_merge_settings(array $stored): array
{
    $merged = hp_setting_defaults();
    foreach ($stored as $key => $value) {
        $merged[$key] = $value;
    }
    return $merged;
}

function hp_admin_hash(PDO $pdo): ?string
{
    hp_ensure_schema($pdo);
    $stmt = $pdo->query('SELECT password_hash FROM app_admin WHERE id = 1');
    $hash = $stmt->fetchColumn();
    if ($hash === false || $hash === null || $hash === '') {
        return null;
    }
    return (string)$hash;
}

function hp_admin_ok(PDO $pdo, string $password): bool
{
    $hash = hp_admin_hash($pdo);
    if ($hash === null || $password === '') {
        return false;
    }
    return password_verify($password, $hash);
}

/**
 * @param array<string, string> $stored
 */
function hp_is_configured(array $stored): bool
{
    return isset($stored['p1_ip']) && trim($stored['p1_ip']) !== '';
}

/**
 * Open the database, ensure tables, import .env once.
 *
 * mode: need_admin | need_config | ready
 *
 * @return array{mode: string, pdo: PDO, db: string, settings: array<string, string>, stored: array<string, string>}
 */
function hp_boot(): array
{
    $pdo = hp_pdo();
    hp_ensure_schema($pdo);
    hp_import_env_if_empty($pdo);
    $stored = hp_load_stored($pdo);
    $settings = hp_merge_settings($stored);
    if (hp_admin_hash($pdo) === null) {
        $mode = 'need_admin';
    } elseif (!hp_is_configured($stored)) {
        $mode = 'need_config';
    } else {
        $mode = 'ready';
    }
    return [
        'mode' => $mode,
        'pdo' => $pdo,
        'db' => hp_db_path(),
        'settings' => $settings,
        'stored' => $stored,
    ];
}

/**
 * Send every page except the settings screen to settings.php until setup is done.
 *
 * @param array{mode: string} $boot
 */
function hp_require_ready(array $boot): void
{
    if ($boot['mode'] === 'ready') {
        return;
    }
    header('Location: settings.php');
    exit;
}

/**
 * @param array<string, string> $settings
 * @return array{cap: float, rad: float, night_start: int, night_end: int, shoulder_start: int, shoulder_end: int}
 */
function hp_pac_limits(array $settings): array
{
    $nameplate = hp_positive_float(isset($settings['max_inverter_ac_w']) ? $settings['max_inverter_ac_w'] : '', 12000.0);
    $radRaw = isset($settings['pac_rad_floor']) ? $settings['pac_rad_floor'] : '5';
    $rad = is_numeric($radRaw) ? (float)$radRaw : 5.0;
    if ($rad < 0) {
        $rad = 5.0;
    }
    return [
        'cap' => $nameplate * 1.05,
        'rad' => $rad,
        'night_start' => hp_bounded_int(isset($settings['pac_night_start']) ? $settings['pac_night_start'] : '', 22, 0, 23),
        'night_end' => hp_bounded_int(isset($settings['pac_night_end']) ? $settings['pac_night_end'] : '', 5, 0, 23),
        'shoulder_start' => hp_bounded_int(isset($settings['pac_shoulder_start']) ? $settings['pac_shoulder_start'] : '', 20, 0, 23),
        'shoulder_end' => hp_bounded_int(isset($settings['pac_shoulder_end']) ? $settings['pac_shoulder_end'] : '', 6, 0, 23),
    ];
}

function hp_positive_float(string $raw, float $default): float
{
    if (!is_numeric($raw)) {
        return $default;
    }
    $value = (float)$raw;
    if ($value <= 0) {
        return $default;
    }
    return $value;
}

function hp_bounded_int(string $raw, int $default, int $low, int $high): int
{
    if (!is_numeric($raw)) {
        return $default;
    }
    $value = (int)round((float)$raw);
    if ($value < $low || $value > $high) {
        return $default;
    }
    return $value;
}

/**
 * Publish chart gates for analytics.php sanitize_pac_w.
 *
 * @param array<string, string> $settings
 */
function hp_publish_pac(array $settings): void
{
    $pac = hp_pac_limits($settings);
    $GLOBALS['HP_PAC_CAP'] = $pac['cap'];
    $GLOBALS['HP_PAC_RAD'] = $pac['rad'];
    $GLOBALS['HP_PAC_NIGHT_START'] = $pac['night_start'];
    $GLOBALS['HP_PAC_NIGHT_END'] = $pac['night_end'];
    $GLOBALS['HP_PAC_SHOULDER_START'] = $pac['shoulder_start'];
    $GLOBALS['HP_PAC_SHOULDER_END'] = $pac['shoulder_end'];
}

/**
 * @return array<int, float>
 */
function hp_monthly_kwh(PDO $pdo): array
{
    $seed = hp_month_seed();
    $out = [];
    for ($month = 1; $month <= 12; $month++) {
        $out[$month] = $seed[$month]['kwh'];
    }
    $stmt = $pdo->query('SELECT month, expected_kwh FROM expected_monthly WHERE inclination = 30');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $month = (int)$row['month'];
        if ($month >= 1 && $month <= 12) {
            $out[$month] = (float)$row['expected_kwh'];
        }
    }
    return $out;
}

/**
 * Update expected_kwh only. Insert a missing month with the seed irradiance.
 *
 * @param array<int, float> $kwhByMonth
 */
function hp_save_monthly(PDO $pdo, array $kwhByMonth): void
{
    $seed = hp_month_seed();
    $select = $pdo->prepare('SELECT 1 FROM expected_monthly WHERE month = ? AND inclination = 30');
    $update = $pdo->prepare('UPDATE expected_monthly SET expected_kwh = ? WHERE month = ? AND inclination = 30');
    $insert = $pdo->prepare(
        'INSERT INTO expected_monthly (month, inclination, expected_kwh, expected_irr) VALUES (?, 30, ?, ?)'
    );
    for ($month = 1; $month <= 12; $month++) {
        $kwh = $kwhByMonth[$month];
        $select->execute([$month]);
        if ($select->fetchColumn() !== false) {
            $update->execute([$kwh, $month]);
            continue;
        }
        $insert->execute([$month, $kwh, $seed[$month]['irr']]);
    }
}

/**
 * @param array<string, string> $values
 */
function hp_write_settings(PDO $pdo, array $values): void
{
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO app_settings (key, value) VALUES (?, ?)');
    foreach ($values as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

/**
 * Stored device password, unless the operator unlocked the field and typed a new one.
 * An unlocked empty field does not clear the stored password.
 */
function hp_resolve_password(string $existing, string $posted, bool $touched, bool $wasSet): string
{
    if (!$wasSet) {
        return $posted;
    }
    if (!$touched || $posted === '') {
        return $existing;
    }
    return $posted;
}

function hp_nav_settings(): string
{
    return '<a href="settings.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">'
        . '<i data-lucide="settings" class="w-3.5 h-3.5 text-slate-500"></i> Settings</a>';
}
