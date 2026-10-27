<?php
# --- file: explorer_chart.php ---
/**
 * Analytics Balance/Classic explorer (A5b).
 * Clock window + pan; mixed 1-min / 5-min per bucket (rule V).
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
 * Explorer window: same presets as live (default 6h). Allows end in the past for pan.
 *
 * @return array{start: int, end: int, preset: string, custom: bool}
 */
function hp_explorer_window(?int $now = null): array
{
    $presets = hp_live_chart_presets();
    $minSpan = 15 * 60;
    $maxSpan = 7 * 24 * 3600;
    $now = $now ?? time();

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

    // Top-N / deep link: ?date=YYYY-MM-DD -> that local calendar day (capped at now).
    $dateRaw = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) === 1) {
        $from = strtotime($dateRaw . ' 00:00:00');
        $to = strtotime($dateRaw . ' 23:59:59');
        if ($from !== false && $to !== false) {
            if ($to > $now) {
                $to = $now;
            }
            if ($to > $from) {
                return [
                    'start' => $from,
                    'end' => $to,
                    'preset' => '',
                    'custom' => true,
                ];
            }
        }
    }

    $range = isset($_GET['range']) ? (string)$_GET['range'] : '6h';
    if (!isset($presets[$range])) {
        $range = '6h';
    }
    return [
        'start' => $now - $presets[$range]['seconds'],
        'end' => $now,
        'preset' => $range,
        'custom' => false,
    ];
}

/**
 * Pan window by one full step. Direction -1 = back, +1 = forward (capped at now).
 *
 * @param array{start: int, end: int} $window
 * @return array{start: int, end: int}
 */
function hp_explorer_pan(array $window, int $direction, ?int $now = null): array
{
    $now = $now ?? time();
    $span = max(15 * 60, (int)$window['end'] - (int)$window['start']);
    $start = (int)$window['start'] + ($direction * $span);
    $end = (int)$window['end'] + ($direction * $span);
    if ($end > $now) {
        $end = $now;
        $start = $end - $span;
    }
    return ['start' => $start, 'end' => $end];
}

/**
 * @param callable|null $sanitizePac function(mixed $w, ?int $hh, mixed $rad): ?float
 * @return array{
 *   start: int,
 *   end: int,
 *   balance: array{self_sufficient: list<array{x:int,y:int|null}>, grid: list<array{x:int,y:int|null}>, surplus: list<array{x:int,y:int|null}>, production: list<array{x:int,y:int|null}>, consumption: list<array{x:int,y:int|null}>},
 *   classic: array{solar: list<array{x:int,y:int|null}>, home: list<array{x:int,y:int|null}>, grid: list<array{x:int,y:int|null}>},
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
            'SELECT timestamp, active_power, solar_power FROM readings
             WHERE timestamp >= ? AND timestamp <= ? ORDER BY timestamp ASC'
        );
        $stmt->execute([$startSql, $endSql]);
        $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $readings = [];
    }

    $five = [];
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
    } catch (Throwable $e) {
        $five = [];
    }

    /** @var array<int, list<array{ts:int, solar:?float, grid:?float}>> $byBucket */
    $byBucket = [];
    foreach ($readings as $row) {
        $ts = strtotime((string)($row['timestamp'] ?? ''));
        if ($ts === false || $ts < $start || $ts > $end) {
            continue;
        }
        $bucket = hp_bucket_5min_start($ts);
        $solarRaw = $row['solar_power'] ?? null;
        $gridRaw = $row['active_power'] ?? null;
        $byBucket[$bucket][] = [
            'ts' => $ts,
            'solar' => ($solarRaw === null || $solarRaw === '') ? null : (float)$solarRaw,
            'grid' => ($gridRaw === null || $gridRaw === '') ? null : (float)$gridRaw,
        ];
    }

    $bucketStarts = array_unique(array_merge(array_keys($byBucket), array_keys($five)));
    sort($bucketStarts, SORT_NUMERIC);

    /** @var list<array{ts:int, source:string, solar:?float, grid:?float}> $rawPoints */
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
        $rawPoints[] = [
            'ts' => $ts,
            'source' => '5min',
            'solar' => $solarW,
            'grid' => $gridNet,
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
        }

        $parts = hp_balance_parts($pt['solar'], $pt['grid']);
        if ($parts !== null && $parts['consumption'] !== null) {
            // Numeric bands only — nulls blank Apex stacked areas.
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
        'sources' => $sources,
    ];
}
