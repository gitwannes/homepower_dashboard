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
 * Open the database and ensure tables. Settings come from app_settings only.
 *
 * mode: need_admin | need_config | ready
 *
 * @return array{mode: string, pdo: PDO, db: string, settings: array<string, string>, stored: array<string, string>}
 */
function hp_boot(): array
{
    $pdo = hp_pdo();
    hp_ensure_schema($pdo);
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
 * An unlocked empty field does not clear the stored password (use $clear for that).
 */
function hp_resolve_password(
    string $existing,
    string $posted,
    bool $touched,
    bool $wasSet,
    bool $clear = false
): string {
    if ($clear) {
        return '';
    }
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

/**
 * A15 live integrations (Fluvius is CSV-only and has no active flag).
 *
 * @return array<string, string> id => active settings key
 */
function hp_integration_active_keys(): array
{
    return [
        'p1' => 'p1_active',
        'sma' => 'sma_active',
        'sunnywebbox' => 'sunnywebbox_active',
        'sunnyportal' => 'sunnyportal_active',
    ];
}

/**
 * @return array<string, string> id => label
 */
function hp_integration_labels(): array
{
    return [
        'p1' => 'P1',
        'sma' => 'SMA',
        'sunnywebbox' => 'Sunny WebBox',
        'sunnyportal' => 'Sunny Portal',
    ];
}

/**
 * @param array<string, string> $settings
 */
function hp_integration_filled(array $settings, string $name): bool
{
    if ($name === 'p1') {
        return trim((string)($settings['p1_ip'] ?? '')) !== '';
    }
    if ($name === 'sma') {
        return trim((string)($settings['sma_ip'] ?? '')) !== '';
    }
    if ($name === 'sunnywebbox') {
        return trim((string)($settings['sunnywebbox_ip'] ?? '')) !== '';
    }
    if ($name === 'sunnyportal') {
        return trim((string)($settings['sunnyportal_user'] ?? '')) !== ''
            || trim((string)($settings['sunnyportal_password'] ?? '')) !== ''
            || trim((string)($settings['sunnyportal_plant_id'] ?? '')) !== '';
    }
    return false;
}

/**
 * Grandfather: filled + missing/empty active key => active.
 *
 * @param array<string, string> $settings merged or stored
 * @param array<string, string>|null $stored raw rows; when set, missing key uses grandfather
 */
function hp_integration_active(array $settings, string $name, ?array $stored = null): bool
{
    if (!hp_integration_filled($settings, $name)) {
        return false;
    }
    $keys = hp_integration_active_keys();
    if (!isset($keys[$name])) {
        return false;
    }
    $key = $keys[$name];
    if ($stored !== null && !array_key_exists($key, $stored)) {
        return true;
    }
    if (!array_key_exists($key, $settings)) {
        return true;
    }
    $raw = trim((string)$settings[$key]);
    if ($raw === '') {
        return true;
    }
    return $raw === '1';
}

/**
 * Chip state for Settings UI: empty | active | inactive.
 *
 * @param array<string, string> $settings
 * @param array<string, string> $stored
 */
function hp_integration_chip_state(array $settings, array $stored, string $name): string
{
    if (!hp_integration_filled($settings, $name)) {
        return 'empty';
    }
    return hp_integration_active($settings, $name, $stored) ? 'active' : 'inactive';
}

/**
 * Partial-fill errors and which integrations to probe vs force-inactive.
 *
 * @param array<string, string> $settings resolved form (passwords already resolved)
 * @return array{errors: string[], probe: string[], inactive: string[]}
 */
function hp_integration_save_plan(array $settings): array
{
    $errors = [];
    $probe = [];
    $inactive = [];

    // P1 is required by the form; always probe when address present.
    if (trim((string)($settings['p1_ip'] ?? '')) !== '') {
        $probe[] = 'p1';
    } else {
        $inactive[] = 'p1';
    }

    $smaIp = trim((string)($settings['sma_ip'] ?? ''));
    $smaUser = trim((string)($settings['sma_user'] ?? ''));
    $smaPw = (string)($settings['sma_password'] ?? '');
    $smaAny = $smaIp !== '' || $smaUser !== '' || $smaPw !== '';
    if ($smaAny) {
        if ($smaIp === '' || $smaPw === '') {
            $errors[] = 'SMA is partial: address and password are both required (or clear all SMA fields).';
        } else {
            $probe[] = 'sma';
        }
    } else {
        $inactive[] = 'sma';
    }

    $wbIp = trim((string)($settings['sunnywebbox_ip'] ?? ''));
    $wbPw = (string)($settings['sunnywebbox_password'] ?? '');
    $wbAny = $wbIp !== '' || $wbPw !== '';
    if ($wbAny) {
        if ($wbIp === '' || $wbPw === '') {
            $errors[] = 'Sunny WebBox is partial: address and password are both required (or clear both).';
        } else {
            $probe[] = 'sunnywebbox';
        }
    } else {
        $inactive[] = 'sunnywebbox';
    }

    $portalUser = trim((string)($settings['sunnyportal_user'] ?? ''));
    $portalPw = (string)($settings['sunnyportal_password'] ?? '');
    $portalPlant = trim((string)($settings['sunnyportal_plant_id'] ?? ''));
    $portalAny = $portalUser !== '' || $portalPw !== '' || $portalPlant !== '';
    if ($portalAny) {
        if ($portalUser === '' || $portalPw === '' || $portalPlant === '') {
            $errors[] = 'Sunny Portal is partial: user, password, and plant id are all required (or clear all portal fields).';
        } else {
            $probe[] = 'sunnyportal';
        }
    } else {
        $inactive[] = 'sunnyportal';
    }

    return ['errors' => $errors, 'probe' => $probe, 'inactive' => $inactive];
}

/**
 * Run integration_probes.py with JSON on stdin. Returns per-id status/message.
 *
 * @param array<string, string> $settings
 * @param string[] $probeIds
 * @return array{ok: bool, results: array<string, array{status: string, message: string}>, error: string}
 */
function hp_run_integration_probes(array $settings, array $probeIds): array
{
    if (count($probeIds) === 0) {
        return ['ok' => true, 'results' => [], 'error' => ''];
    }
    $script = __DIR__ . '/integration_probes.py';
    if (!is_file($script)) {
        return ['ok' => false, 'results' => [], 'error' => 'integration_probes.py is missing.'];
    }
    $py = hp_python_bin();
    $payload = json_encode([
        'probe' => array_values($probeIds),
        'settings' => $settings,
    ]);
    if ($payload === false) {
        return ['ok' => false, 'results' => [], 'error' => 'Could not encode probe payload.'];
    }
    $cmd = escapeshellarg($py) . ' ' . escapeshellarg($script);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, __DIR__);
    if (!is_resource($proc)) {
        return ['ok' => false, 'results' => [], 'error' => 'Could not start the probe process.'];
    }
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($stdout === false || trim($stdout) === '') {
        $hint = is_string($stderr) ? trim($stderr) : '';
        return [
            'ok' => false,
            'results' => [],
            'error' => 'Probe returned no output.' . ($hint !== '' ? ' ' . $hint : ''),
        ];
    }
    $decoded = json_decode($stdout, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'results' => [], 'error' => 'Probe returned invalid JSON.'];
    }
    if (isset($decoded['error'])) {
        return ['ok' => false, 'results' => [], 'error' => (string)$decoded['error']];
    }
    if ($code !== 0) {
        return ['ok' => false, 'results' => [], 'error' => 'Probe exited with code ' . $code . '.'];
    }
    $results = [];
    foreach ($decoded as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $results[(string)$id] = [
            'status' => isset($row['status']) ? (string)$row['status'] : 'fail',
            'message' => isset($row['message']) ? (string)$row['message'] : '',
        ];
    }
    return ['ok' => true, 'results' => $results, 'error' => ''];
}

/**
 * Apply probe / clear plan onto settings active flags.
 *
 * @param array<string, string> $settings
 * @param string[] $forceInactive
 * @param array<string, array{status: string, message: string}> $probeResults
 * @return array{settings: array<string, string>, warnings: array<int, array{id: string, status: string, message: string}>}
 */
function hp_apply_integration_probe_flags(array $settings, array $forceInactive, array $probeResults): array
{
    $keys = hp_integration_active_keys();
    $warnings = [];
    foreach ($forceInactive as $id) {
        if (isset($keys[$id])) {
            $settings[$keys[$id]] = '0';
        }
    }
    foreach ($probeResults as $id => $row) {
        if (!isset($keys[$id])) {
            continue;
        }
        $status = $row['status'];
        if ($status === 'ok') {
            $settings[$keys[$id]] = '1';
        } else {
            $settings[$keys[$id]] = '0';
            if ($status !== 'skipped') {
                $warnings[] = [
                    'id' => (string)$id,
                    'status' => $status,
                    'message' => $row['message'],
                ];
            }
        }
    }
    return ['settings' => $settings, 'warnings' => $warnings];
}

/**
 * Newest usable Python 3 for Synology / Pi host scripts.
 * Prefers Package Center 3.14, then versioned binaries (3.14 .. 3.9), then bare python3.
 * DS723 typically resolves to 3.14; Sofie to /usr/local/bin/python3.9.
 * Keep candidate order in sync with run_python.sh (Task Scheduler wrapper).
 */
function hp_python_bin(): string
{
    $candidates = [
        '/var/packages/Python3.14/target/bin/python3.14',
        '/var/packages/Python3.14/target/bin/python3',
        '/var/packages/Python3.14/target/usr/bin/python3',
        '/usr/local/bin/python3.14',
        '/usr/bin/python3.14',
        '/usr/local/bin/python3.13',
        '/usr/bin/python3.13',
        '/usr/local/bin/python3.12',
        '/usr/bin/python3.12',
        '/usr/local/bin/python3.11',
        '/usr/bin/python3.11',
        '/usr/local/bin/python3.10',
        '/usr/bin/python3.10',
        '/usr/local/bin/python3.9',
        '/usr/bin/python3.9',
        '/usr/local/bin/python3',
        '/usr/bin/python3',
        'python3',
    ];
    foreach ($candidates as $candidate) {
        if (@file_exists($candidate) && @is_executable($candidate)) {
            return $candidate;
        }
    }
    return 'python3';
}
