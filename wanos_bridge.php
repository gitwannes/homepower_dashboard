<?php
# --- file: wanos_bridge.php ---
/**
 * WanOS → Power display bridge (W2).
 * Machine API key + allowlisted sensor mappings. Display only (no warehouse writes).
 */
require_once __DIR__ . '/site_settings.php';

/**
 * Fixed allowlist kinds (must match WanOS core/bridge_sensors.py).
 *
 * @return array<string, array{kind: string, default_name: string, unit: string}>
 */
function hp_wanos_allowlist_meta(): array
{
    return [
        'sensor.fluid.waterlink.digital_meter' => [
            'kind' => 'water_official',
            'default_name' => 'Water-Link',
            'unit' => 'm3',
        ],
        'sensor.fluid.koud_water' => [
            'kind' => 'water_cold',
            'default_name' => 'Koud water',
            'unit' => 'L',
        ],
        'sensor.fluid.warm_water' => [
            'kind' => 'water_hot',
            'default_name' => 'Warm water',
            'unit' => 'L',
        ],
        'sensor.temp_hum.outside_temp_hum' => [
            'kind' => 'ambient',
            'default_name' => 'Ambient temperature',
            'unit' => 'C',
        ],
    ];
}

/**
 * @param PDO $pdo
 */
function hp_wanos_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS wanos_sensor_mappings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity_id TEXT NOT NULL UNIQUE,
            display_name TEXT NOT NULL,
            placement TEXT NOT NULL,
            kind TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\'))
        )'
    );
}

/**
 * @param array<string, string> $settings
 */
function hp_wanos_filled(array $settings): bool
{
    return trim((string)($settings['wanos_ip'] ?? '')) !== ''
        && trim((string)($settings['wanos_api_key'] ?? '')) !== '';
}

/**
 * @param array<string, string> $settings
 * @param array<string, string>|null $stored
 */
function hp_wanos_active(array $settings, ?array $stored = null): bool
{
    if (!hp_wanos_filled($settings)) {
        return false;
    }
    if ($stored !== null && !array_key_exists('wanos_active', $stored)) {
        return true;
    }
    if (!array_key_exists('wanos_active', $settings)) {
        return true;
    }
    $raw = trim((string)$settings['wanos_active']);
    if ($raw === '') {
        return true;
    }
    return $raw === '1';
}

/**
 * Base URL for WanOS (http://host[:port]).
 *
 * @param array<string, string> $settings
 */
function hp_wanos_base_url(array $settings): string
{
    $raw = trim((string)($settings['wanos_ip'] ?? ''));
    if ($raw === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $raw)) {
        return rtrim($raw, '/');
    }
    // WanOS nginx returns 301 HTTP -> HTTPS; default https so Bearer is not dropped on redirect.
    return 'https://' . rtrim($raw, '/');
}

/**
 * HTTP GET JSON from WanOS bridge API.
 *
 * @param array<string, string> $settings
 * @return array{ok: bool, status?: int, error?: string, data?: array<string, mixed>}
 */
function hp_wanos_request(array $settings, string $path, int $timeoutSec = 5): array
{
    $base = hp_wanos_base_url($settings);
    $key = trim((string)($settings['wanos_api_key'] ?? ''));
    if ($base === '' || $key === '') {
        return ['ok' => false, 'error' => 'WanOS address or API key is empty.'];
    }
    $url = $base . $path;
    $headers = [
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
    ];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init failed.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($errno !== 0) {
            return ['ok' => false, 'error' => 'WanOS unreachable: ' . $cerr];
        }
        $decoded = json_decode(is_string($body) ? $body : '', true);
        if ($status < 200 || $status >= 300) {
            $msg = is_array($decoded) && isset($decoded['error'])
                ? (string)$decoded['error']
                : ('HTTP ' . $status);
            return ['ok' => false, 'status' => $status, 'error' => $msg];
        }
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'WanOS returned invalid JSON.'];
        }
        return ['ok' => true, 'status' => $status, 'data' => $decoded];
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => $timeoutSec,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    if ($body === false) {
        return ['ok' => false, 'error' => 'WanOS unreachable.'];
    }
    $decoded = json_decode($body, true);
    if ($status < 200 || $status >= 300) {
        $msg = is_array($decoded) && isset($decoded['error'])
            ? (string)$decoded['error']
            : ('HTTP ' . $status);
        return ['ok' => false, 'status' => $status, 'error' => $msg];
    }
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'WanOS returned invalid JSON.'];
    }
    return ['ok' => true, 'status' => $status, 'data' => $decoded];
}

/**
 * @param array<string, string> $settings
 * @return array{ok: bool, error?: string}
 */
function hp_wanos_probe(array $settings): array
{
    $res = hp_wanos_request($settings, '/api/bridge/probe');
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'] ?? 'Probe failed.'];
    }
    $data = $res['data'] ?? [];
    if (empty($data['ok'])) {
        return ['ok' => false, 'error' => 'Unexpected probe payload.'];
    }
    return ['ok' => true];
}

/**
 * @param array<string, string> $settings
 * @return array{ok: bool, error?: string, sensors?: list<array<string, mixed>>}
 */
function hp_wanos_fetch_sensors(array $settings): array
{
    $res = hp_wanos_request($settings, '/api/bridge/sensors');
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'] ?? 'Fetch failed.'];
    }
    $data = $res['data'] ?? [];
    $sensors = $data['sensors'] ?? [];
    if (!is_array($sensors)) {
        return ['ok' => false, 'error' => 'Unexpected sensors payload.'];
    }
    return ['ok' => true, 'sensors' => $sensors];
}

/**
 * @return list<array<string, mixed>>
 */
function hp_wanos_list_mappings(PDO $pdo): array
{
    hp_wanos_ensure_schema($pdo);
    $stmt = $pdo->query(
        'SELECT id, entity_id, display_name, placement, kind FROM wanos_sensor_mappings ORDER BY id ASC'
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return is_array($rows) ? $rows : [];
}

/**
 * P1 water present and P1 integration active -> block official Tap Water from WanOS.
 *
 * @param array<string, string> $settings
 * @param array<string, string>|null $stored
 */
function hp_wanos_p1_blocks_official_water(PDO $pdo, array $settings, ?array $stored = null): bool
{
    if (!hp_integration_active($settings, 'p1', $stored)) {
        return false;
    }
    require_once __DIR__ . '/gas_water.php';
    return gw_has_live_water($pdo);
}

/**
 * @param array<string, mixed> $post
 * @return array{ok: bool, error?: string, id?: int}
 */
function hp_wanos_mapping_create(PDO $pdo, array $post, array $settings, ?array $stored = null): array
{
    hp_wanos_ensure_schema($pdo);
    $entityId = trim((string)($post['entity_id'] ?? ''));
    $placement = trim((string)($post['placement'] ?? ''));
    $displayName = trim((string)($post['display_name'] ?? ''));
    $meta = hp_wanos_allowlist_meta();
    if (!isset($meta[$entityId])) {
        return ['ok' => false, 'error' => 'Entity is not on the WanOS allowlist.'];
    }
    if (!in_array($placement, ['raw', 'main', 'both'], true)) {
        return ['ok' => false, 'error' => 'Placement must be raw, main, or both.'];
    }
    $kind = $meta[$entityId]['kind'];
    if ($kind === 'water_official' && hp_wanos_p1_blocks_official_water($pdo, $settings, $stored)) {
        return ['ok' => false, 'error' => 'P1 water is active; official Tap Water from WanOS is not available.'];
    }
    if ($displayName === '') {
        $displayName = $meta[$entityId]['default_name'];
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO wanos_sensor_mappings (entity_id, display_name, placement, kind)
             VALUES (:e, :n, :p, :k)'
        );
        $stmt->execute([
            ':e' => $entityId,
            ':n' => $displayName,
            ':p' => $placement,
            ':k' => $kind,
        ]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not add sensor (maybe already mapped).'];
    }
}

/**
 * @param array<string, mixed> $post
 * @return array{ok: bool, error?: string}
 */
function hp_wanos_mapping_update(PDO $pdo, int $id, array $post): array
{
    hp_wanos_ensure_schema($pdo);
    if ($id < 1) {
        return ['ok' => false, 'error' => 'Invalid mapping id.'];
    }
    $displayName = trim((string)($post['display_name'] ?? ''));
    $placement = trim((string)($post['placement'] ?? ''));
    if ($displayName === '') {
        return ['ok' => false, 'error' => 'Display name is required.'];
    }
    if (!in_array($placement, ['raw', 'main', 'both'], true)) {
        return ['ok' => false, 'error' => 'Placement must be raw, main, or both.'];
    }
    $stmt = $pdo->prepare(
        'UPDATE wanos_sensor_mappings SET display_name = :n, placement = :p WHERE id = :id'
    );
    $stmt->execute([':n' => $displayName, ':p' => $placement, ':id' => $id]);
    if ($stmt->rowCount() < 1) {
        return ['ok' => false, 'error' => 'Mapping not found.'];
    }
    return ['ok' => true];
}

/**
 * @return array{ok: bool, error?: string}
 */
function hp_wanos_mapping_delete(PDO $pdo, int $id): array
{
    hp_wanos_ensure_schema($pdo);
    if ($id < 1) {
        return ['ok' => false, 'error' => 'Invalid mapping id.'];
    }
    $stmt = $pdo->prepare('DELETE FROM wanos_sensor_mappings WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return ['ok' => true];
}

/**
 * Join mappings with live WanOS values. Empty list if bridge down / inactive.
 *
 * @param array<string, string> $settings
 * @param array<string, string>|null $stored
 * @return array{live: bool, error?: string, rows: list<array<string, mixed>>}
 */
function hp_wanos_resolved_rows(PDO $pdo, array $settings, ?array $stored = null): array
{
    hp_wanos_ensure_schema($pdo);
    $mappings = hp_wanos_list_mappings($pdo);
    if ($mappings === []) {
        return ['live' => true, 'rows' => []];
    }
    if (!hp_wanos_active($settings, $stored)) {
        return ['live' => false, 'error' => 'WanOS bridge inactive.', 'rows' => []];
    }
    $fetch = hp_wanos_fetch_sensors($settings);
    if (!$fetch['ok']) {
        return ['live' => false, 'error' => $fetch['error'] ?? 'Fetch failed.', 'rows' => []];
    }
    $byId = [];
    foreach ($fetch['sensors'] ?? [] as $s) {
        if (!is_array($s)) {
            continue;
        }
        $eid = (string)($s['entity_id'] ?? '');
        if ($eid !== '') {
            $byId[$eid] = $s;
        }
    }
    $blockOfficial = hp_wanos_p1_blocks_official_water($pdo, $settings, $stored);
    $meta = hp_wanos_allowlist_meta();
    $rows = [];
    foreach ($mappings as $m) {
        $eid = (string)$m['entity_id'];
        $kind = (string)$m['kind'];
        if ($kind === 'water_official' && $blockOfficial) {
            continue;
        }
        $live = $byId[$eid] ?? null;
        $unit = $meta[$eid]['unit'] ?? (string)($live['unit'] ?? '');
        $value = null;
        if (is_array($live) && array_key_exists('value', $live) && $live['value'] !== null && is_numeric($live['value'])) {
            $value = (float)$live['value'];
        }
        if ($value === null) {
            continue;
        }
        $updatedUnix = null;
        if (is_array($live) && isset($live['updated_unix']) && is_numeric($live['updated_unix'])) {
            $u = (int)$live['updated_unix'];
            if ($u > 0) {
                $updatedUnix = $u;
            }
        }
        $placement = (string)$m['placement'];
        $rows[] = [
            'id' => (int)$m['id'],
            'entity_id' => $eid,
            'display_name' => (string)$m['display_name'],
            'placement' => $placement,
            'kind' => $kind,
            'unit' => $unit,
            'value' => $value,
            'updated_unix' => $updatedUnix,
            'on_raw' => ($placement === 'raw' || $placement === 'both'),
            'on_main' => ($placement === 'main' || $placement === 'both'),
        ];
    }
    return ['live' => true, 'rows' => $rows];
}
