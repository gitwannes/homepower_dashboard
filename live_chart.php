<?php
# --- file: live_chart.php ---
/**
 * Live dashboard time window (A8) and classic/balance series (A5).
 * Presets end at now. A dragged range is from/to and is kept as given.
 * A step longer than 3 minutes is a gap. A 3-minute step stays connected.
 */
require_once __DIR__ . '/balance_chart.php';

/**
 * Preset id => label and length in seconds.
 *
 * @return array<string, array{label: string, seconds: int}>
 */
function hp_live_chart_presets(): array
{
    return [
        '15m' => ['label' => '15 min', 'seconds' => 15 * 60],
        '1h' => ['label' => '1 h', 'seconds' => 3600],
        '2h' => ['label' => '2 h', 'seconds' => 2 * 3600],
        '6h' => ['label' => '6 h', 'seconds' => 6 * 3600],
        '24h' => ['label' => '24 h', 'seconds' => 24 * 3600],
        '7d' => ['label' => '7 d', 'seconds' => 7 * 24 * 3600],
    ];
}

/**
 * Clock window for the live chart. Default is the last 2 hours, ending now.
 *
 * @return array{start: int, end: int, preset: string, custom: bool}
 */
function hp_live_chart_window(): array
{
    $presets = hp_live_chart_presets();
    $minSpan = 15 * 60;
    $maxSpan = 7 * 24 * 3600;
    $now = time();

    $fromRaw = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
    $toRaw = isset($_GET['to']) ? trim((string)$_GET['to']) : '';
    if ($fromRaw !== '' && $toRaw !== '') {
        $from = strtotime($fromRaw);
        $to = strtotime($toRaw);
        if ($from !== false && $to !== false && $to > $from) {
            if ($to > $now) {
                $to = $now;
            }
            $span = $to - $from;
            if ($span < $minSpan) {
                $from = $to - $minSpan;
            } elseif ($span > $maxSpan) {
                $from = $to - $maxSpan;
            }
            return [
                'start' => $from,
                'end' => $to,
                'preset' => '',
                'custom' => true,
            ];
        }
    }

    $range = isset($_GET['range']) ? (string)$_GET['range'] : '2h';
    if (!isset($presets[$range])) {
        $range = '2h';
    }
    return [
        'start' => $now - $presets[$range]['seconds'],
        'end' => $now,
        'preset' => $range,
        'custom' => false,
    ];
}

/**
 * Apex datetime points for home, solar, and grid.
 * Insert a null when the next sample is more than 3 minutes later.
 *
 * @param array<int, array<string, mixed>> $rows Oldest first.
 * @return array{home: array<int, array{x: int, y: int|null}>, solar: array<int, array{x: int, y: int|null}>, grid: array<int, array{x: int, y: int|null}>}
 */
function hp_live_chart_series(array $rows): array
{
    $home = [];
    $solar = [];
    $grid = [];
    $prev = null;
    foreach ($rows as $row) {
        $ts = strtotime((string)($row['timestamp'] ?? ''));
        if ($ts === false) {
            continue;
        }
        if ($prev !== null && ($ts - $prev) > 180) {
            $breakAt = ($prev * 1000) + 1000;
            $home[] = ['x' => $breakAt, 'y' => null];
            $solar[] = ['x' => $breakAt, 'y' => null];
            $grid[] = ['x' => $breakAt, 'y' => null];
        }
        $ms = $ts * 1000;
        $gridW = (int)($row['active_power'] ?? 0);
        $solarW = (int)($row['solar_power'] ?? 0);
        $home[] = ['x' => $ms, 'y' => max(0, $solarW + $gridW)];
        $solar[] = ['x' => $ms, 'y' => $solarW];
        $grid[] = ['x' => $ms, 'y' => $gridW];
        $prev = $ts;
    }
    return ['home' => $home, 'solar' => $solar, 'grid' => $grid];
}

/**
 * Apex datetime points for balance bands from 1-minute readings.
 * Same 3-minute gap rule as classic.
 *
 * @param array<int, array<string, mixed>> $rows Oldest first.
 * @return array{
 *   production: array<int, array{x: int, y: int|null}>,
 *   consumption: array<int, array{x: int, y: int|null}>,
 *   self_sufficient: array<int, array{x: int, y: int|null}>,
 *   grid: array<int, array{x: int, y: int|null}>,
 *   surplus: array<int, array{x: int, y: int|null}>
 * }
 */
function hp_live_chart_balance_series(array $rows): array
{
    $production = [];
    $consumption = [];
    $selfSufficient = [];
    $grid = [];
    $surplus = [];
    $prev = null;
    foreach ($rows as $row) {
        $ts = strtotime((string)($row['timestamp'] ?? ''));
        if ($ts === false) {
            continue;
        }
        // No null gap markers: Apex stacked areas go blank when series contain nulls.
        $ms = $ts * 1000;
        $solarRaw = $row['solar_power'] ?? null;
        $gridRaw = $row['active_power'] ?? null;
        $solarW = ($solarRaw === null || $solarRaw === '') ? null : (float)$solarRaw;
        $gridNetW = ($gridRaw === null || $gridRaw === '') ? null : (float)$gridRaw;
        $parts = hp_balance_parts($solarW, $gridNetW);
        // Need grid net for balance bands; skip solar-only rows (null bands blank Apex stacks).
        if ($parts === null || $parts['consumption'] === null) {
            $prev = $ts;
            continue;
        }
        $production[] = ['x' => $ms, 'y' => hp_chart_watt0($parts['production'])];
        $consumption[] = ['x' => $ms, 'y' => hp_chart_watt0($parts['consumption'])];
        $selfSufficient[] = ['x' => $ms, 'y' => hp_chart_watt0($parts['self_sufficient'])];
        $grid[] = ['x' => $ms, 'y' => hp_chart_watt0($parts['grid'])];
        $surplus[] = ['x' => $ms, 'y' => hp_chart_watt0($parts['surplus'])];
        $prev = $ts;
    }
    return [
        'production' => $production,
        'consumption' => $consumption,
        'self_sufficient' => $selfSufficient,
        'grid' => $grid,
        'surplus' => $surplus,
    ];
}

/**
 * Apex datetime points for L1/L2/L3 (A18). Same 3-minute gap rule as classic.
 * Incomplete phase trio at a sample is a null gap (no zero-fill).
 *
 * @param array<int, array<string, mixed>> $rows Oldest first.
 * @return array{
 *   l1: array<int, array{x: int, y: int|null}>,
 *   l2: array<int, array{x: int, y: int|null}>,
 *   l3: array<int, array{x: int, y: int|null}>
 * }
 */
function hp_live_chart_phases_series(array $rows): array
{
    $l1 = [];
    $l2 = [];
    $l3 = [];
    $prev = null;
    foreach ($rows as $row) {
        $ts = strtotime((string)($row['timestamp'] ?? ''));
        if ($ts === false) {
            continue;
        }
        if ($prev !== null && ($ts - $prev) > 180) {
            $breakAt = ($prev * 1000) + 1000;
            $l1[] = ['x' => $breakAt, 'y' => null];
            $l2[] = ['x' => $breakAt, 'y' => null];
            $l3[] = ['x' => $breakAt, 'y' => null];
        }
        $ms = $ts * 1000;
        $a = $row['power_l1_w'] ?? null;
        $b = $row['power_l2_w'] ?? null;
        $c = $row['power_l3_w'] ?? null;
        if ($a === null || $a === '' || $b === null || $b === '' || $c === null || $c === '') {
            $l1[] = ['x' => $ms, 'y' => null];
            $l2[] = ['x' => $ms, 'y' => null];
            $l3[] = ['x' => $ms, 'y' => null];
        } else {
            $l1[] = ['x' => $ms, 'y' => (int)$a];
            $l2[] = ['x' => $ms, 'y' => (int)$b];
            $l3[] = ['x' => $ms, 'y' => (int)$c];
        }
        $prev = $ts;
    }
    return ['l1' => $l1, 'l2' => $l2, 'l3' => $l3];
}
