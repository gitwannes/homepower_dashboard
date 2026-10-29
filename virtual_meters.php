<?php
# --- file: virtual_meters.php ---
/**
 * Virtual kWh meters (A14): source lifetime + bias + percent of new energy.
 * Formula: V = (S0 + C) + (percent/100) * (S - S0)
 * P1 import/export/net from last readings row; SMA/WebBox totals from latest_state
 * (readings stores only combined solar_total_kwh).
 */

/** @var list<string> */
const HP_VM_SOURCES = [
    'p1_import',
    'p1_export',
    'p1_net',
    'sma_total',
    'webbox_total',
];

/**
 * Create virtual_kwh_meters if missing (never purged with derived warehouse).
 */
function hp_vm_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS virtual_kwh_meters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            source TEXT NOT NULL,
            bias_kwh REAL NOT NULL DEFAULT 0,
            factor_percent REAL NOT NULL DEFAULT 100,
            s0_kwh REAL NOT NULL,
            show_in_raw_registers INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
}

/**
 * @return array<string, string>
 */
function hp_vm_source_labels(): array
{
    return [
        'p1_import' => 'P1 lifetime import',
        'p1_export' => 'P1 lifetime export',
        'p1_net' => 'P1 lifetime net (import - export)',
        'sma_total' => 'SMA lifetime total',
        'webbox_total' => 'WebBox lifetime total',
    ];
}

/**
 * Last readings row + latest_state JSON for source resolution.
 *
 * @return array{
 *   reading: ?array<string, mixed>,
 *   reading_ts: ?string,
 *   state: array<string, mixed>,
 *   state_ts: ?string
 * }
 */
function hp_vm_load_source_context(PDO $pdo): array
{
    $reading = null;
    $readingTs = null;
    try {
        $stmt = $pdo->query(
            'SELECT timestamp, total_import, total_export, solar_total_kwh
             FROM readings
             ORDER BY timestamp DESC
             LIMIT 1'
        );
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (is_array($row)) {
            $reading = $row;
            $readingTs = isset($row['timestamp']) ? (string)$row['timestamp'] : null;
        }
    } catch (Throwable $e) {
        $reading = null;
    }

    $state = [];
    $stateTs = null;
    try {
        $stmt = $pdo->query('SELECT updated_at, data_json FROM latest_state WHERE id = 1');
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (is_array($row) && isset($row['data_json']) && is_string($row['data_json'])) {
            $decoded = json_decode($row['data_json'], true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
            $stateTs = isset($row['updated_at']) ? (string)$row['updated_at'] : null;
        }
    } catch (Throwable $e) {
        $state = [];
    }

    return [
        'reading' => $reading,
        'reading_ts' => $readingTs,
        'state' => $state,
        'state_ts' => $stateTs,
    ];
}

/**
 * @param array<string, mixed> $ctx from hp_vm_load_source_context
 * @return array{ok: bool, value: ?float, label: string, detail: string}
 */
function hp_vm_resolve_source(string $source, array $ctx): array
{
    $labels = hp_vm_source_labels();
    $label = isset($labels[$source]) ? $labels[$source] : $source;
    $reading = $ctx['reading'];
    $state = $ctx['state'];

    if ($source === 'p1_import') {
        if (!is_array($reading) || $reading['total_import'] === null || $reading['total_import'] === '') {
            return ['ok' => false, 'value' => null, 'label' => $label, 'detail' => 'No total_import on last readings row'];
        }
        return [
            'ok' => true,
            'value' => (float)$reading['total_import'],
            'label' => $label,
            'detail' => 'readings.total_import @ ' . (string)($ctx['reading_ts'] ?? '?'),
        ];
    }

    if ($source === 'p1_export') {
        if (!is_array($reading) || $reading['total_export'] === null || $reading['total_export'] === '') {
            return ['ok' => false, 'value' => null, 'label' => $label, 'detail' => 'No total_export on last readings row'];
        }
        return [
            'ok' => true,
            'value' => (float)$reading['total_export'],
            'label' => $label,
            'detail' => 'readings.total_export @ ' . (string)($ctx['reading_ts'] ?? '?'),
        ];
    }

    if ($source === 'p1_net') {
        if (
            !is_array($reading)
            || $reading['total_import'] === null || $reading['total_import'] === ''
            || $reading['total_export'] === null || $reading['total_export'] === ''
        ) {
            return ['ok' => false, 'value' => null, 'label' => $label, 'detail' => 'Need both import and export on last readings row'];
        }
        $imp = (float)$reading['total_import'];
        $exp = (float)$reading['total_export'];
        return [
            'ok' => true,
            'value' => $imp - $exp,
            'label' => $label,
            'detail' => 'readings import-export @ ' . (string)($ctx['reading_ts'] ?? '?'),
        ];
    }

    if ($source === 'sma_total') {
        $sma = isset($state['sma_data']) && is_array($state['sma_data']) ? $state['sma_data'] : null;
        if ($sma === null || !array_key_exists('total_kwh', $sma) || $sma['total_kwh'] === null || $sma['total_kwh'] === '') {
            return ['ok' => false, 'value' => null, 'label' => $label, 'detail' => 'No sma_data.total_kwh in latest_state'];
        }
        return [
            'ok' => true,
            'value' => (float)$sma['total_kwh'],
            'label' => $label,
            'detail' => 'latest_state.sma_data.total_kwh @ ' . (string)($ctx['state_ts'] ?? '?'),
        ];
    }

    if ($source === 'webbox_total') {
        $wb = isset($state['webbox_data']) && is_array($state['webbox_data']) ? $state['webbox_data'] : null;
        if ($wb === null || !array_key_exists('total_kwh', $wb) || $wb['total_kwh'] === null || $wb['total_kwh'] === '') {
            return ['ok' => false, 'value' => null, 'label' => $label, 'detail' => 'No webbox_data.total_kwh in latest_state'];
        }
        return [
            'ok' => true,
            'value' => (float)$wb['total_kwh'],
            'label' => $label,
            'detail' => 'latest_state.webbox_data.total_kwh @ ' . (string)($ctx['state_ts'] ?? '?'),
        ];
    }

    return ['ok' => false, 'value' => null, 'label' => $label, 'detail' => 'Unknown source'];
}

/**
 * Sources that currently resolve (for Settings picker).
 *
 * @return list<array{source: string, label: string, value: float, detail: string}>
 */
function hp_vm_available_sources(PDO $pdo): array
{
    $ctx = hp_vm_load_source_context($pdo);
    $out = [];
    foreach (HP_VM_SOURCES as $source) {
        $resolved = hp_vm_resolve_source($source, $ctx);
        if ($resolved['ok'] && $resolved['value'] !== null) {
            $out[] = [
                'source' => $source,
                'label' => $resolved['label'],
                'value' => $resolved['value'],
                'detail' => $resolved['detail'],
            ];
        }
    }
    return $out;
}

/**
 * V(t) = (S0 + C) + p * (S - S0) with p = percent/100.
 */
function hp_vm_virtual_kwh(float $s, float $s0, float $biasKwh, float $factorPercent): float
{
    $p = $factorPercent / 100.0;
    return ($s0 + $biasKwh) + $p * ($s - $s0);
}

/**
 * @return list<array<string, mixed>>
 */
function hp_vm_list(PDO $pdo): array
{
    hp_vm_ensure_schema($pdo);
    $stmt = $pdo->query(
        'SELECT id, name, source, bias_kwh, factor_percent, s0_kwh, show_in_raw_registers, created_at, updated_at
         FROM virtual_kwh_meters
         ORDER BY id ASC'
    );
    if ($stmt === false) {
        return [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/**
 * Meters flagged for index Raw Meter Registers (A21 consumer).
 *
 * @return list<array<string, mixed>>
 */
function hp_vm_list_raw_registers(PDO $pdo): array
{
    hp_vm_ensure_schema($pdo);
    $stmt = $pdo->query(
        'SELECT id, name, source, bias_kwh, factor_percent, s0_kwh, show_in_raw_registers, created_at, updated_at
         FROM virtual_kwh_meters
         WHERE show_in_raw_registers = 1
         ORDER BY id ASC'
    );
    if ($stmt === false) {
        return [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/**
 * Enrich a stored meter row with live S / V / calc dump.
 *
 * @param array<string, mixed> $meter
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function hp_vm_evaluate(array $meter, array $ctx): array
{
    $source = (string)($meter['source'] ?? '');
    $s0 = (float)($meter['s0_kwh'] ?? 0);
    $c = (float)($meter['bias_kwh'] ?? 0);
    $pct = (float)($meter['factor_percent'] ?? 100);
    $resolved = hp_vm_resolve_source($source, $ctx);
    $s = $resolved['ok'] ? $resolved['value'] : null;
    $v = null;
    $delta = null;
    $p = $pct / 100.0;
    if ($s !== null) {
        $delta = $s - $s0;
        $v = hp_vm_virtual_kwh($s, $s0, $c, $pct);
    }

    return array_merge($meter, [
        'source_ok' => $resolved['ok'],
        'source_label' => $resolved['label'],
        'source_detail' => $resolved['detail'],
        'S' => $s,
        'S0' => $s0,
        'C' => $c,
        'factor_percent' => $pct,
        'p' => $p,
        'delta_S' => $delta,
        'V' => $v,
        'formula' => 'V = (S0 + C) + p * (S - S0); p = percent/100',
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function hp_vm_list_evaluated(PDO $pdo): array
{
    $ctx = hp_vm_load_source_context($pdo);
    $out = [];
    foreach (hp_vm_list($pdo) as $meter) {
        $out[] = hp_vm_evaluate($meter, $ctx);
    }
    return $out;
}

/**
 * Parse and validate Settings / AJAX meter fields.
 *
 * @param array<string, mixed> $input
 * @return array{ok: bool, error: string, data: array<string, mixed>}
 */
function hp_vm_parse_fields(array $input, bool $requireS0Source, PDO $pdo): array
{
    $name = isset($input['name']) ? trim((string)$input['name']) : '';
    $source = isset($input['source']) ? trim((string)$input['source']) : '';
    $biasRaw = isset($input['bias_kwh']) ? trim((string)$input['bias_kwh']) : '0';
    $factorRaw = isset($input['factor_percent']) ? trim((string)$input['factor_percent']) : '100';
    $showRaw = !empty($input['show_in_raw_registers']);

    if ($name === '') {
        return ['ok' => false, 'error' => 'Name is required.', 'data' => []];
    }
    if (!in_array($source, HP_VM_SOURCES, true)) {
        return ['ok' => false, 'error' => 'Invalid source.', 'data' => []];
    }
    if ($biasRaw === '' || !is_numeric($biasRaw)) {
        return ['ok' => false, 'error' => 'Bias must be a number (kWh).', 'data' => []];
    }
    if ($factorRaw === '' || !is_numeric($factorRaw)) {
        return ['ok' => false, 'error' => 'Factor percent must be a number.', 'data' => []];
    }
    $bias = (float)$biasRaw;
    $factor = (float)$factorRaw;
    // Max 4 decimal places on the percent as entered.
    $factorRounded = round($factor, 4);
    if (abs($factor - $factorRounded) > 1e-9) {
        return ['ok' => false, 'error' => 'Factor percent allows at most 4 decimal places.', 'data' => []];
    }

    $data = [
        'name' => $name,
        'source' => $source,
        'bias_kwh' => $bias,
        'factor_percent' => $factorRounded,
        'show_in_raw_registers' => $showRaw ? 1 : 0,
    ];

    if ($requireS0Source) {
        $ctx = hp_vm_load_source_context($pdo);
        $resolved = hp_vm_resolve_source($source, $ctx);
        if (!$resolved['ok'] || $resolved['value'] === null) {
            return [
                'ok' => false,
                'error' => 'Source is not available: ' . $resolved['detail'],
                'data' => [],
            ];
        }
        $data['s0_kwh'] = $resolved['value'];
    }

    return ['ok' => true, 'error' => '', 'data' => $data];
}

/**
 * Insert a new meter; freezes S0 from current source.
 *
 * @param array<string, mixed> $input
 * @return array{ok: bool, error: string, id: int}
 */
function hp_vm_create(PDO $pdo, array $input): array
{
    hp_vm_ensure_schema($pdo);
    $parsed = hp_vm_parse_fields($input, true, $pdo);
    if (!$parsed['ok']) {
        return ['ok' => false, 'error' => $parsed['error'], 'id' => 0];
    }
    $d = $parsed['data'];
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO virtual_kwh_meters
         (name, source, bias_kwh, factor_percent, s0_kwh, show_in_raw_registers, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $d['name'],
        $d['source'],
        $d['bias_kwh'],
        $d['factor_percent'],
        $d['s0_kwh'],
        $d['show_in_raw_registers'],
        $now,
        $now,
    ]);
    return ['ok' => true, 'error' => '', 'id' => (int)$pdo->lastInsertId()];
}

/**
 * Update name/source/bias/factor/flag; does not change S0.
 *
 * @param array<string, mixed> $input
 * @return array{ok: bool, error: string}
 */
function hp_vm_update(PDO $pdo, int $id, array $input): array
{
    hp_vm_ensure_schema($pdo);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Invalid meter id.'];
    }
    $check = $pdo->prepare('SELECT id FROM virtual_kwh_meters WHERE id = ?');
    $check->execute([$id]);
    if (!$check->fetch(PDO::FETCH_ASSOC)) {
        return ['ok' => false, 'error' => 'Meter not found.'];
    }
    $parsed = hp_vm_parse_fields($input, false, $pdo);
    if (!$parsed['ok']) {
        return ['ok' => false, 'error' => $parsed['error']];
    }
    // Source must still be a known key; availability not required for edit.
    $d = $parsed['data'];
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'UPDATE virtual_kwh_meters
         SET name = ?, source = ?, bias_kwh = ?, factor_percent = ?, show_in_raw_registers = ?, updated_at = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $d['name'],
        $d['source'],
        $d['bias_kwh'],
        $d['factor_percent'],
        $d['show_in_raw_registers'],
        $now,
        $id,
    ]);
    return ['ok' => true, 'error' => ''];
}

/**
 * @return array{ok: bool, error: string}
 */
function hp_vm_delete(PDO $pdo, int $id): array
{
    hp_vm_ensure_schema($pdo);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Invalid meter id.'];
    }
    $stmt = $pdo->prepare('DELETE FROM virtual_kwh_meters WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() < 1) {
        return ['ok' => false, 'error' => 'Meter not found.'];
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * Re-bias: set S0 to current source reading; C and factor unchanged.
 *
 * @return array{ok: bool, error: string, s0: ?float}
 */
function hp_vm_rebias(PDO $pdo, int $id): array
{
    hp_vm_ensure_schema($pdo);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Invalid meter id.', 's0' => null];
    }
    $stmt = $pdo->prepare(
        'SELECT id, source FROM virtual_kwh_meters WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'Meter not found.', 's0' => null];
    }
    $ctx = hp_vm_load_source_context($pdo);
    $resolved = hp_vm_resolve_source((string)$row['source'], $ctx);
    if (!$resolved['ok'] || $resolved['value'] === null) {
        return ['ok' => false, 'error' => 'Source not available: ' . $resolved['detail'], 's0' => null];
    }
    $now = date('Y-m-d H:i:s');
    $upd = $pdo->prepare(
        'UPDATE virtual_kwh_meters SET s0_kwh = ?, updated_at = ? WHERE id = ?'
    );
    $upd->execute([$resolved['value'], $now, $id]);
    return ['ok' => true, 'error' => '', 's0' => $resolved['value']];
}
