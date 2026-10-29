<?php
# --- file: explorer_chart.php ---
/**
 * Analytics Balance/Classic/Exchange explorer (A5b + A19).
 * Clock window + calendar-day Today; pan; mixed 1-min / 5-min (rule V).
 */
require_once __DIR__ . '/live_chart.php';

/**
 * Floor a unix timestamp to the local 5-minute bucket start.
 */
function hp_bucket_5min_start(int $ts): int
{
    return $ts - ($ts % 300);
}

/**
 * Format local datetime for SQL comparisons.
 */
function hp_sql_datetime(int $ts): string
{
    return date('Y-m-d H:i:s', $ts);
}

/**
 * Explorer range chips: live rolling presets with Today after 24 h (A19).
 *
 * @return array<string, array{label: string, seconds: int, kind: string}>
 */
function hp_explorer_chart_presets(): array
{
    return [
        '15m' => ['label' => '15 min', 'seconds' => 15 * 60, 'kind' => 'rolling'],
        '1h' => ['label' => '1 h', 'seconds' => 3600, 'kind' => 'rolling'],
        '2h' => ['label' => '2 h', 'seconds' => 2 * 3600, 'kind' => 'rolling'],
        '6h' => ['label' => '6 h', 'seconds' => 6 * 3600, 'kind' => 'rolling'],
        '24h' => ['label' => '24 h', 'seconds' => 24 * 3600, 'kind' => 'rolling'],
        'today' => ['label' => 'Today', 'seconds' => 0, 'kind' => 'day'],
        '7d' => ['label' => '7 d', 'seconds' => 7 * 24 * 3600, 'kind' => 'rolling'],
    ];
}

/**
 * Local calendar day bounds (DST-aware): midnight inclusive to next midnight - 1s.
 *
 * @return array{start: int, end: int, ymd: string}|null
 */
function hp_local_calendar_day_bounds(string $ymd): ?array
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) !== 1) {
        return null;
    }
    $start = strtotime($ymd . ' 00:00:00');
    if ($start === false) {
        return null;
    }
    $nextMidnight = strtotime('+1 day', $start);
    if ($nextMidnight === false || $nextMidnight <= $start) {
        return null;
    }
    return [
        'start' => $start,
        'end' => $nextMidnight - 1,
        'ymd' => $ymd,
    ];
}

/**
 * If start/end is a full local calendar day, return normalized bounds; else null.
 *
 * @return array{start: int, end: int, ymd: string}|null
 */
function hp_explorer_match_day_window(int $start, int $end): ?array
{
    $ymd = date('Y-m-d', $start);
    $bounds = hp_local_calendar_day_bounds($ymd);
    if ($bounds === null || $start !== $bounds['start']) {
        return null;
    }
    // Inclusive last second, or exclusive next midnight (clients may send either).
    if ($end === $bounds['end'] || $end === $bounds['end'] + 1) {
        return $bounds;
    }
    $legacyEnd = strtotime($ymd . ' 23:59:59');
    if ($legacyEnd !== false && $end === $legacyEnd) {
        return $bounds;
    }
    return null;
}

/**
 * Shift a calendar-day window by +/- 1 local day. Caps forward at today.
 *
 * @return array{start: int, end: int, ymd: string}|null
 */
function hp_explorer_shift_calendar_day(int $start, int $direction, ?int $now = null): ?array
{
    $now = $now ?? time();
    $todayYmd = date('Y-m-d', $now);
    $ymd = date('Y-m-d', $start);
    $base = strtotime($ymd . ' 00:00:00');
    if ($base === false) {
        return null;
    }
    $step = $direction >= 0 ? '+1 day' : '-1 day';
    $next = strtotime($step, $base);
    if ($next === false) {
        return null;
    }
    $nextYmd = date('Y-m-d', $next);
    if ($nextYmd > $todayYmd) {
        $nextYmd = $todayYmd;
    }
    return hp_local_calendar_day_bounds($nextYmd);
}

/**
 * Explorer window. Default Today (A19). Rolling presets end at now; day windows
 * keep midnight-to-midnight (empty future OK).
 *
 * @return array{start: int, end: int, preset: string, custom: bool, day_mode: bool}
 */
function hp_explorer_window(?int $now = null): array
{
    $presets = hp_explorer_chart_presets();
    $minSpan = 15 * 60;
    $maxSpan = 7 * 24 * 3600;
    $now = $now ?? time();
    $todayYmd = date('Y-m-d', $now);

    $fromRaw = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
    $toRaw = isset($_GET['to']) ? trim((string)$_GET['to']) : '';
    if ($fromRaw !== '' && $toRaw !== '') {
        $from = strtotime($fromRaw);
        $to = strtotime($toRaw);
        if ($from !== false && $to !== false && $to > $from) {
            $day = hp_explorer_match_day_window($from, $to);
            if ($day !== null) {
                if ($day['ymd'] > $todayYmd) {
                    $day = hp_local_calendar_day_bounds($todayYmd);
                }
                if ($day !== null) {
                    $isToday = ($day['ymd'] === $todayYmd);
                    return [
                        'start' => $day['start'],
                        'end' => $day['end'],
                        'preset' => $isToday ? 'today' : '',
                        'custom' => !$isToday,
                        'day_mode' => true,
                    ];
                }
            }
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
                'day_mode' => false,
            ];
        }
    }

    // Top-N / deep link: ?date=YYYY-MM-DD -> full local calendar day (A19).
    $dateRaw = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) === 1) {
        if ($dateRaw > $todayYmd) {
            $dateRaw = $todayYmd;
        }
        $day = hp_local_calendar_day_bounds($dateRaw);
        if ($day !== null) {
            $isToday = ($day['ymd'] === $todayYmd);
            return [
                'start' => $day['start'],
                'end' => $day['end'],
                'preset' => $isToday ? 'today' : '',
                'custom' => !$isToday,
                'day_mode' => true,
            ];
        }
    }

    $range = isset($_GET['range']) ? (string)$_GET['range'] : 'today';
    if (!isset($presets[$range])) {
        $range = 'today';
    }
    if (($presets[$range]['kind'] ?? '') === 'day') {
        $day = hp_local_calendar_day_bounds($todayYmd);
        if ($day === null) {
            // Fallback should not happen; keep a rolling 6h safety net.
            return [
                'start' => $now - (6 * 3600),
                'end' => $now,
                'preset' => '6h',
                'custom' => false,
                'day_mode' => false,
            ];
        }
        return [
            'start' => $day['start'],
            'end' => $day['end'],
            'preset' => 'today',
            'custom' => false,
            'day_mode' => true,
        ];
    }
    return [
        'start' => $now - (int)$presets[$range]['seconds'],
        'end' => $now,
        'preset' => $range,
        'custom' => false,
        'day_mode' => false,
    ];
}

/**
 * Pan window. Day mode: +/- one local calendar day (capped at today).
 * Rolling: one full step (end capped at now).
 *
 * @param array{start: int, end: int, day_mode?: bool} $window
 * @return array{start: int, end: int, day_mode: bool}
 */
function hp_explorer_pan(array $window, int $direction, ?int $now = null): array
{
    $now = $now ?? time();
    $dayMode = !empty($window['day_mode'])
        || hp_explorer_match_day_window((int)$window['start'], (int)$window['end']) !== null;
    if ($dayMode) {
        $shifted = hp_explorer_shift_calendar_day((int)$window['start'], $direction, $now);
        if ($shifted === null) {
            return [
                'start' => (int)$window['start'],
                'end' => (int)$window['end'],
                'day_mode' => true,
            ];
        }
        return [
            'start' => $shifted['start'],
            'end' => $shifted['end'],
            'day_mode' => true,
        ];
    }
    $span = max(15 * 60, (int)$window['end'] - (int)$window['start']);
    $start = (int)$window['start'] + ($direction * $span);
    $end = (int)$window['end'] + ($direction * $span);
    if ($end > $now) {
        $end = $now;
        $start = $end - $span;
    }
    return ['start' => $start, 'end' => $end, 'day_mode' => false];
}

/**
 * @param callable|null $sanitizePac function(mixed $w, ?int $hh, mixed $rad): ?float
 * @return array{
 *   start: int,
 *   end: int,
 *   balance: array{self_sufficient: list<array{x:int,y:int|null}>, grid: list<array{x:int,y:int|null}>, surplus: list<array{x:int,y:int|null}>, production: list<array{x:int,y:int|null}>, consumption: list<array{x:int,y:int|null}>},
 *   classic: array{solar: list<array{x:int,y:int|null}>, home: list<array{x:int,y:int|null}>, grid: list<array{x:int,y:int|null}>},
 *   phases: array{l1: list<array{x:int,y:int|null}>, l2: list<array{x:int,y:int|null}>, l3: list<array{x:int,y:int|null}>},
 *   sources: list<array{x:int, source: string}>
 * }
 */
function hp_explorer_mixed_series(PDO $pdo, int $start, int $end, $sanitizePac = null): array
{
    $startSql = hp_sql_datetime($start);
    $endSql = hp_sql_datetime($end);

    $readings = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT timestamp, active_power, solar_power, power_l1_w, power_l2_w, power_l3_w
             FROM readings
             WHERE timestamp >= ? AND timestamp <= ? ORDER BY timestamp ASC'
        );
        $stmt->execute([$startSql, $endSql]);
        $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Pre-A16 DBs lack phase columns - fall back without them.
        try {
            $stmt = $pdo->prepare(
                'SELECT timestamp, active_power, solar_power FROM readings
                 WHERE timestamp >= ? AND timestamp <= ? ORDER BY timestamp ASC'
            );
            $stmt->execute([$startSql, $endSql]);
            $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) {
            $readings = [];
        }
    }

    $five = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT timestamp, solar_power_w, solar_radiation, grid_power_w,
                    grid_import_kwh, grid_export_kwh,
                    power_l1_w, power_l2_w, power_l3_w
             FROM history_5min
             WHERE timestamp >= ? AND timestamp <= ?
             ORDER BY timestamp ASC'
        );
        $stmt->execute([$startSql, $endSql]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ts = strtotime((string)$row['timestamp']);
            if ($ts === false) {
                continue;
            }
            $five[hp_bucket_5min_start($ts)] = $row;
        }
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare(
                'SELECT timestamp, solar_power_w, solar_radiation, grid_power_w,
                        grid_import_kwh, grid_export_kwh
                 FROM history_5min
                 WHERE timestamp >= ? AND timestamp <= ?
                 ORDER BY timestamp ASC'
            );
            $stmt->execute([$startSql, $endSql]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ts = strtotime((string)$row['timestamp']);
                if ($ts === false) {
                    continue;
                }
                $five[hp_bucket_5min_start($ts)] = $row;
            }
        } catch (Throwable $e2) {
            $five = [];
        }
    }

    /** @var array<int, list<array{ts:int, solar:?float, grid:?float, l1:?float, l2:?float, l3:?float}>> $byBucket */
    $byBucket = [];
    foreach ($readings as $row) {
        $ts = strtotime((string)($row['timestamp'] ?? ''));
        if ($ts === false || $ts < $start || $ts > $end) {
            continue;
        }
        $bucket = hp_bucket_5min_start($ts);
        $solarRaw = $row['solar_power'] ?? null;
        $gridRaw = $row['active_power'] ?? null;
        $l1Raw = $row['power_l1_w'] ?? null;
        $l2Raw = $row['power_l2_w'] ?? null;
        $l3Raw = $row['power_l3_w'] ?? null;
        $byBucket[$bucket][] = [
            'ts' => $ts,
            'solar' => ($solarRaw === null || $solarRaw === '') ? null : (float)$solarRaw,
            'grid' => ($gridRaw === null || $gridRaw === '') ? null : (float)$gridRaw,
            'l1' => ($l1Raw === null || $l1Raw === '') ? null : (float)$l1Raw,
            'l2' => ($l2Raw === null || $l2Raw === '') ? null : (float)$l2Raw,
            'l3' => ($l3Raw === null || $l3Raw === '') ? null : (float)$l3Raw,
        ];
    }

    $bucketStarts = array_unique(array_merge(array_keys($byBucket), array_keys($five)));
    sort($bucketStarts, SORT_NUMERIC);

    /** @var list<array{ts:int, source:string, solar:?float, grid:?float, l1:?float, l2:?float, l3:?float}> $rawPoints */
    $rawPoints = [];
    foreach ($bucketStarts as $bucket) {
        if ($bucket + 299 < $start || $bucket > $end) {
            continue;
        }
        $visible = [];
        if (isset($byBucket[$bucket])) {
            foreach ($byBucket[$bucket] as $sample) {
                if ($sample['ts'] >= $start && $sample['ts'] <= $end) {
                    $visible[] = $sample;
                }
            }
        }
        if (count($visible) >= 2) {
            foreach ($visible as $sample) {
                $rawPoints[] = [
                    'ts' => $sample['ts'],
                    'source' => '1min',
                    'solar' => $sample['solar'],
                    'grid' => $sample['grid'],
                    'l1' => $sample['l1'],
                    'l2' => $sample['l2'],
                    'l3' => $sample['l3'],
                ];
            }
            continue;
        }
        if (!isset($five[$bucket])) {
            continue;
        }
        $row = $five[$bucket];
        $ts = strtotime((string)$row['timestamp']);
        if ($ts === false || $ts < $start || $ts > $end) {
            continue;
        }
        $hh = (int)date('G', $ts);
        $solarW = null;
        if ($sanitizePac !== null) {
            $pac = $sanitizePac($row['solar_power_w'], $hh, $row['solar_radiation']);
            if ($row['solar_power_w'] === null || $row['solar_power_w'] === '') {
                $solarW = null;
            } elseif ($pac !== null) {
                $solarW = $pac;
            } else {
                $solarW = 0.0;
            }
        } else {
            $solarRaw = $row['solar_power_w'];
            $solarW = ($solarRaw === null || $solarRaw === '') ? null : max(0.0, (float)$solarRaw);
        }
        $gridNet = hp_grid_net_w(
            $row['grid_power_w'],
            $row['grid_import_kwh'],
            $row['grid_export_kwh']
        );
        $l1Raw = $row['power_l1_w'] ?? null;
        $l2Raw = $row['power_l2_w'] ?? null;
        $l3Raw = $row['power_l3_w'] ?? null;
        $rawPoints[] = [
            'ts' => $ts,
            'source' => '5min',
            'solar' => $solarW,
            'grid' => $gridNet,
            'l1' => ($l1Raw === null || $l1Raw === '') ? null : (float)$l1Raw,
            'l2' => ($l2Raw === null || $l2Raw === '') ? null : (float)$l2Raw,
            'l3' => ($l3Raw === null || $l3Raw === '') ? null : (float)$l3Raw,
        ];
    }

    usort($rawPoints, static function (array $a, array $b): int {
        return $a['ts'] <=> $b['ts'];
    });

    // Fluvius grid is 1-in-3 on history_5min (quarter starts only). Linear-bridge
    // up to 2 nulls between known grid samples so Classic Home / Balance / grid
    // draw on the intermediate solar-filled 5-min slots (day-graph rule).
    // 1-min runs stay as sampled; do not bridge across a source change.
    $nPts = count($rawPoints);
    $seg = 0;
    while ($seg < $nPts) {
        if ($rawPoints[$seg]['source'] !== '5min') {
            $seg++;
            continue;
        }
        $segEnd = $seg;
        while ($segEnd < $nPts && $rawPoints[$segEnd]['source'] === '5min') {
            $segEnd++;
        }
        $grids = [];
        for ($j = $seg; $j < $segEnd; $j++) {
            $grids[] = $rawPoints[$j]['grid'];
        }
        $bridged = hp_bridge_short_nulls($grids, 2);
        for ($j = $seg; $j < $segEnd; $j++) {
            $rawPoints[$j]['grid'] = $bridged[$j - $seg];
        }
        $seg = $segEnd;
    }

    $balanceSelf = [];
    $balanceGrid = [];
    $balanceSurplus = [];
    $balanceProd = [];
    $balanceCons = [];
    $tipSelf = [];
    $tipGrid = [];
    $tipSurplus = [];
    $classicSolar = [];
    $classicHome = [];
    $classicGrid = [];
    $phaseL1 = [];
    $phaseL2 = [];
    $phaseL3 = [];
    $sources = [];

    $prevTs = null;
    $prevSource = null;
    foreach ($rawPoints as $pt) {
        $ts = $pt['ts'];
        $source = $pt['source'];
        $ms = $ts * 1000;

        // Classic lines may use null gap markers. Balance stacked areas must not
        // (Apex blanked the chart when stacked series contained nulls).
        $needBreak = false;
        if ($prevTs !== null) {
            if ($prevSource !== $source) {
                $needBreak = true;
            } elseif ($source === '1min' && ($ts - $prevTs) > 180) {
                $needBreak = true;
            } elseif ($source === '5min') {
                $missing = (int)(($ts - $prevTs) / 300) - 1;
                if ($missing > 2) {
                    $needBreak = true;
                }
            }
        }
        if ($needBreak) {
            $breakAt = ($prevTs * 1000) + 1000;
            $nullPt = ['x' => $breakAt, 'y' => null];
            $classicSolar[] = $nullPt;
            $classicHome[] = $nullPt;
            $classicGrid[] = $nullPt;
            $phaseL1[] = $nullPt;
            $phaseL2[] = $nullPt;
            $phaseL3[] = $nullPt;
        }

        $parts = hp_balance_parts($pt['solar'], $pt['grid']);
        if ($parts !== null && $parts['consumption'] !== null) {
            // Numeric bands only - nulls blank Apex stacked areas.
            $balanceSelf[] = ['x' => $ms, 'y' => hp_chart_watt0($parts['self_sufficient'])];
            $balanceGrid[] = ['x' => $ms, 'y' => hp_chart_watt0($parts['grid'])];
            $balanceSurplus[] = ['x' => $ms, 'y' => hp_chart_watt0($parts['surplus'])];
            $balanceProd[] = ['x' => $ms, 'y' => hp_chart_watt0($parts['production'])];
            $balanceCons[] = ['x' => $ms, 'y' => hp_chart_watt0($parts['consumption'])];
            $tipSelf[] = ['x' => $ms, 'y' => hp_chart_watt0($parts['self_sufficient'])];
            $tipGrid[] = ['x' => $ms, 'y' => hp_chart_watt0($parts['grid'])];
            $tipSurplus[] = ['x' => $ms, 'y' => hp_chart_watt0($parts['surplus'])];
            $sources[] = ['x' => $ms, 'source' => $source];
        }

        $solarY = $pt['solar'] !== null ? hp_chart_watt(max(0.0, (float)$pt['solar'])) : null;
        $gridY = $pt['grid'] !== null ? hp_chart_watt((float)$pt['grid']) : null;
        // Home = solar + signed grid net. Grid may be Fluvius-bridged above;
        // still require non-null grid (never coerce missing to 0).
        $homeY = null;
        if ($pt['grid'] !== null) {
            $homeY = hp_chart_watt(max(0.0, (float)($pt['solar'] ?? 0.0) + (float)$pt['grid']));
        }
        $classicSolar[] = ['x' => $ms, 'y' => $solarY];
        $classicHome[] = ['x' => $ms, 'y' => $homeY];
        $classicGrid[] = ['x' => $ms, 'y' => $gridY];

        // A18: all three present or null gap (do not invent / bridge phases).
        $pl1 = $pt['l1'] ?? null;
        $pl2 = $pt['l2'] ?? null;
        $pl3 = $pt['l3'] ?? null;
        if ($pl1 !== null && $pl2 !== null && $pl3 !== null) {
            $phaseL1[] = ['x' => $ms, 'y' => hp_chart_watt((float)$pl1)];
            $phaseL2[] = ['x' => $ms, 'y' => hp_chart_watt((float)$pl2)];
            $phaseL3[] = ['x' => $ms, 'y' => hp_chart_watt((float)$pl3)];
        } else {
            $phaseL1[] = ['x' => $ms, 'y' => null];
            $phaseL2[] = ['x' => $ms, 'y' => null];
            $phaseL3[] = ['x' => $ms, 'y' => null];
        }

        $prevTs = $ts;
        $prevSource = $source;
    }

    return [
        'start' => $start,
        'end' => $end,
        // Painted series: band thicknesses for stacked areas.
        'balance' => [
            'self_sufficient' => $balanceSelf,
            'grid' => $balanceGrid,
            'surplus' => $balanceSurplus,
            'production' => $balanceProd,
            'consumption' => $balanceCons,
        ],
        'tip_bands' => [
            'self_sufficient' => $tipSelf,
            'grid' => $tipGrid,
            'surplus' => $tipSurplus,
            'production' => $balanceProd,
            'consumption' => $balanceCons,
        ],
        'classic' => [
            'solar' => $classicSolar,
            'home' => $classicHome,
            'grid' => $classicGrid,
        ],
        'phases' => [
            'l1' => $phaseL1,
            'l2' => $phaseL2,
            'l3' => $phaseL3,
        ],
        'sources' => $sources,
    ];
}
