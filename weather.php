<?php
# --- file: weather.php ---
/**
 * Weather explorer and rollup records (A11).
 *
 * Intraday samples come from history_5min + live readings. Week/month cards
 * read daily_history extremes; year cards read monthly_history (A23).
 * Open day/month may merge live readings. All-time records from top_records.
 */
date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/site_settings.php';
require_once __DIR__ . '/explorer_chart.php';

$hp = hp_boot();
hp_require_ready($hp);
$dbFile = $hp['db'];
$explorerWindow = hp_explorer_window();

/**
 * Current local ISO week label (YYYY-Www), same shape as summary.php.
 *
 * @param DateTimeInterface|null $when
 */
function weather_iso_week_label($when = null): string
{
    if ($when instanceof DateTimeInterface) {
        $dto = new DateTime($when->format('Y-m-d H:i:s'));
    } else {
        $dto = new DateTime('now');
    }
    return $dto->format('o') . '-W' . $dto->format('W');
}

/**
 * Monday..Sunday YYYY-MM-DD for an ISO week label YYYY-Www.
 *
 * @return array{0:?string,1:?string} [from, to]
 */
function weather_iso_week_span(string $weekLabel): array
{
    if (!preg_match('/^(\d{4})-W(\d{2})$/', $weekLabel, $m)) {
        return [null, null];
    }
    $dto = new DateTime();
    $dto->setISODate((int)$m[1], (int)$m[2], 1);
    $from = $dto->format('Y-m-d');
    $dto->modify('+6 days');
    return [$from, $dto->format('Y-m-d')];
}

/**
 * First/last day of a calendar month YYYY-MM.
 *
 * @return array{0:?string,1:?string} [from, to]
 */
function weather_month_span(string $yearMonth): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
        return [null, null];
    }
    try {
        $first = new DateTime($yearMonth . '-01');
        $last = (clone $first)->modify('last day of this month');
        return [$first->format('Y-m-d'), $last->format('Y-m-d')];
    } catch (Throwable $e) {
        return [null, null];
    }
}

/**
 * Shift an ISO week label by N weeks.
 */
function weather_shift_week_label(string $weekLabel, int $delta): string
{
    [$from] = weather_iso_week_span($weekLabel);
    if ($from === null) {
        return weather_iso_week_label();
    }
    $dto = new DateTime($from . ' 12:00:00');
    $dto->modify(($delta >= 0 ? '+' : '') . ($delta * 7) . ' days');
    return weather_iso_week_label($dto);
}

/**
 * Shift a YYYY-MM label by N months.
 */
function weather_shift_month_label(string $yearMonth, int $delta): string
{
    if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
        return date('Y-m');
    }
    $dto = new DateTime($yearMonth . '-01 12:00:00');
    $dto->modify(($delta >= 0 ? '+' : '') . (int)$delta . ' months');
    return $dto->format('Y-m');
}

/**
 * Shift a calendar year YYYY by N years.
 */
function weather_shift_year_label(string $year, int $delta): string
{
    if (!preg_match('/^\d{4}$/', $year)) {
        return date('Y');
    }
    return (string)((int)$year + (int)$delta);
}

/**
 * Read the four weather records in their stable display order.
 *
 * @return list<array{record_id:string, record_value:float, record_date:string, description:string}>
 */
function weather_records(PDO $pdo): array
{
    $ids = ['max_wind_ms', 'max_temp_c', 'min_temp_c', 'max_module_temp_c'];
    $byId = [];
    try {
        $stmt = $pdo->query(
            "SELECT record_id, record_value, record_date, description
             FROM top_records
             WHERE record_id IN ('max_wind_ms', 'max_temp_c', 'min_temp_c', 'max_module_temp_c')"
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['record_id'] === null || $row['record_value'] === null || $row['record_date'] === null) {
                continue;
            }
            $byId[(string)$row['record_id']] = [
                'record_id' => (string)$row['record_id'],
                'record_value' => (float)$row['record_value'],
                'record_date' => (string)$row['record_date'],
                'description' => (string)($row['description'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $out[] = $byId[$id];
        }
    }
    return $out;
}

/**
 * Merge one calendar day's extremes (max unless ambient_min_c).
 *
 * @param array<string, array<string, ?float>> $byDate
 * @param array<string, ?float> $values
 */
function weather_period_merge_day(array &$byDate, string $date, array $values): void
{
    if (!isset($byDate[$date])) {
        $byDate[$date] = [
            'wind_ms' => null,
            'gust_ms' => null,
            'ambient_max_c' => null,
            'ambient_min_c' => null,
            'module_max_c' => null,
            'radiation_wm2' => null,
        ];
    }
    foreach ($values as $key => $val) {
        if ($val === null) {
            continue;
        }
        $cur = $byDate[$date][$key] ?? null;
        if ($key === 'ambient_min_c') {
            $byDate[$date][$key] = ($cur === null) ? $val : min($cur, $val);
        } else {
            $byDate[$date][$key] = ($cur === null) ? $val : max($cur, $val);
        }
    }
}

/**
 * Map one daily_history / monthly_history weather row into period chart fields.
 *
 * @param array<string, mixed> $row
 * @return array{wind_ms:?float, gust_ms:?float, ambient_max_c:?float, ambient_min_c:?float, module_max_c:?float, radiation_wm2:?float}
 */
function weather_extreme_fields_from_row(array $row): array
{
    return [
        'wind_ms' => weather_nullable_float($row['wind_speed_max_ms'] ?? null),
        'gust_ms' => weather_nullable_float($row['wind_gust_max_ms'] ?? null),
        'ambient_max_c' => weather_nullable_float($row['temp_ambient_max'] ?? null),
        'ambient_min_c' => weather_nullable_float($row['temp_ambient_min'] ?? null),
        'module_max_c' => weather_nullable_float($row['temp_module_max'] ?? null),
        'radiation_wm2' => weather_nullable_float($row['solar_radiation_max_wm2'] ?? null),
    ];
}

/**
 * Daily weather extremes for week/month cards (A23: daily_history).
 * Open local calendar day may still be incomplete on daily_history - merge
 * live readings extremes for that day only.
 *
 * @return list<array{date:string, wind_ms:?float, gust_ms:?float, ambient_max_c:?float, ambient_min_c:?float, module_max_c:?float, radiation_wm2:?float}>
 */
function weather_period_rows(PDO $pdo, string $from, string $to): array
{
    /** @var array<string, array<string, ?float>> $byDate */
    $byDate = [];

    try {
        $stmt = $pdo->prepare(
            'SELECT date, wind_speed_max_ms, wind_gust_max_ms,
                    temp_ambient_max, temp_ambient_min,
                    temp_module_max, solar_radiation_max_wm2
             FROM daily_history
             WHERE date >= ? AND date <= ?
             ORDER BY date ASC'
        );
        $stmt->execute([$from, $to]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            weather_period_merge_day($byDate, (string)$row['date'], weather_extreme_fields_from_row($row));
        }
    } catch (Throwable $e) {
        // daily_history / new columns missing until rollup ensure + rebuild.
    }

    // Open day: daily_history may lag the live SensorBox pulse.
    $today = date('Y-m-d');
    if ($today >= $from && $today <= $to) {
        try {
            $live = $pdo->prepare(
                "SELECT MAX(wind_speed) AS wind_ms,
                        MAX(temp_module) AS module_max_c,
                        MAX(solar_radiation) AS radiation_wm2
                 FROM readings
                 WHERE timestamp >= ? AND timestamp <= ?
                   AND (wind_speed IS NOT NULL OR temp_module IS NOT NULL OR solar_radiation IS NOT NULL)"
            );
            $live->execute([$today . ' 00:00:00', $today . ' 23:59:59']);
            $row = $live->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                weather_period_merge_day($byDate, $today, [
                    'wind_ms' => weather_nullable_float($row['wind_ms'] ?? null),
                    'module_max_c' => weather_nullable_float($row['module_max_c'] ?? null),
                    'radiation_wm2' => weather_nullable_float($row['radiation_wm2'] ?? null),
                ]);
            }
        } catch (Throwable $e) {
            // readings missing.
        }
    }

    ksort($byDate);
    $rows = [];
    foreach ($byDate as $date => $vals) {
        $rows[] = array_merge(['date' => $date], $vals);
    }
    return $rows;
}

/**
 * Monthly extremes for a calendar year (one bar per month) from monthly_history (A23).
 *
 * @return list<array{date:string, wind_ms:?float, gust_ms:?float, ambient_max_c:?float, ambient_min_c:?float, module_max_c:?float, radiation_wm2:?float}>
 */
function weather_year_rows(PDO $pdo, string $year): array
{
    if (!preg_match('/^\d{4}$/', $year)) {
        return [];
    }
    /** @var array<string, array<string, ?float>> $byMonth */
    $byMonth = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT year_month, wind_speed_max_ms, wind_gust_max_ms,
                    temp_ambient_max, temp_ambient_min,
                    temp_module_max, solar_radiation_max_wm2
             FROM monthly_history
             WHERE year_month >= ? AND year_month <= ?'
        );
        $stmt->execute([$year . '-01', $year . '-12']);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ym = (string)$row['year_month'];
            weather_period_merge_day($byMonth, $ym, weather_extreme_fields_from_row($row));
        }
    } catch (Throwable $e) {
        // monthly weather cols missing until rollup ensure + rebuild.
    }

    // Open month: merge today's live extremes into the current YYYY-MM bucket.
    $today = date('Y-m-d');
    $openYm = date('Y-m');
    if (substr($today, 0, 4) === $year) {
        try {
            $live = $pdo->prepare(
                "SELECT MAX(wind_speed) AS wind_ms,
                        MAX(temp_module) AS module_max_c,
                        MAX(solar_radiation) AS radiation_wm2
                 FROM readings
                 WHERE timestamp >= ? AND timestamp <= ?
                   AND (wind_speed IS NOT NULL OR temp_module IS NOT NULL OR solar_radiation IS NOT NULL)"
            );
            $live->execute([$today . ' 00:00:00', $today . ' 23:59:59']);
            $row = $live->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                weather_period_merge_day($byMonth, $openYm, [
                    'wind_ms' => weather_nullable_float($row['wind_ms'] ?? null),
                    'module_max_c' => weather_nullable_float($row['module_max_c'] ?? null),
                    'radiation_wm2' => weather_nullable_float($row['radiation_wm2'] ?? null),
                ]);
            }
        } catch (Throwable $e) {
            // readings missing.
        }
    }

    $rows = [];
    for ($m = 1; $m <= 12; $m++) {
        $ym = sprintf('%s-%02d', $year, $m);
        $vals = $byMonth[$ym] ?? [
            'wind_ms' => null,
            'gust_ms' => null,
            'ambient_max_c' => null,
            'ambient_min_c' => null,
            'module_max_c' => null,
            'radiation_wm2' => null,
        ];
        $rows[] = array_merge(['date' => $ym], $vals);
    }
    return $rows;
}

/**
 * Keep missing weather readings null; never turn them into zero.
 *
 * @param mixed $value
 */
function weather_nullable_float($value): ?float
{
    return ($value === null || $value === '') ? null : round((float)$value, 2);
}

/**
 * Which explorer channels have any real sample (same idea as water_meter gate).
 * Gust / ambient are archive-only; live SensorBox does not write them.
 *
 * @return array{wind_ms: bool, gust_ms: bool, ambient_c: bool, module_c: bool, radiation_wm2: bool}
 */
function weather_channel_available(PDO $pdo): array
{
    $out = [
        'wind_ms' => false,
        'gust_ms' => false,
        'ambient_c' => false,
        'module_c' => false,
        'radiation_wm2' => false,
    ];
    $checks = [
        'wind_ms' => [
            "SELECT 1 FROM readings WHERE wind_speed IS NOT NULL LIMIT 1",
            "SELECT 1 FROM history_5min WHERE wind_speed IS NOT NULL LIMIT 1",
        ],
        // Archive-only fields. Gate on history_5min in the last 7 local days
        // (Today / 7d explorer span). sensorbox_history_5min alone is wrong:
        // be90 has ~1.7M archive rows while live weeks have null gust/ambient,
        // which lit toggles and fed all-null Apex series that blanked week/month.
        'gust_ms' => [
            "SELECT 1 FROM history_5min
             WHERE wind_gust IS NOT NULL
               AND timestamp >= datetime('now', 'localtime', '-7 days')
             LIMIT 1",
        ],
        'ambient_c' => [
            "SELECT 1 FROM history_5min
             WHERE temp_ambient IS NOT NULL
               AND timestamp >= datetime('now', 'localtime', '-7 days')
             LIMIT 1",
        ],
        'module_c' => [
            "SELECT 1 FROM readings WHERE temp_module IS NOT NULL LIMIT 1",
            "SELECT 1 FROM history_5min WHERE temp_module IS NOT NULL LIMIT 1",
        ],
        'radiation_wm2' => [
            "SELECT 1 FROM readings WHERE solar_radiation IS NOT NULL LIMIT 1",
            "SELECT 1 FROM history_5min WHERE solar_radiation IS NOT NULL LIMIT 1",
        ],
    ];
    foreach ($checks as $key => $sqls) {
        foreach ($sqls as $sql) {
            try {
                if ($pdo->query($sql)->fetchColumn() !== false) {
                    $out[$key] = true;
                    break;
                }
            } catch (Throwable $e) {
                // Table/column missing - try next source.
            }
        }
    }
    return $out;
}

/**
 * Merge one timestamp into the sample map. Non-null values overwrite.
 *
 * @param array<int, array<string, ?float>> $byMs
 * @param array<string, ?float> $values
 */
function weather_sample_merge(array &$byMs, int $ms, array $values): void
{
    if (!isset($byMs[$ms])) {
        $byMs[$ms] = [
            'wind_ms' => null,
            'gust_ms' => null,
            'ambient_c' => null,
            'module_c' => null,
            'radiation_wm2' => null,
        ];
    }
    foreach ($values as $key => $val) {
        if ($val !== null) {
            $byMs[$ms][$key] = $val;
        }
    }
}

/**
 * Build chart series from history_5min + live readings, merged and time-sorted.
 * Appending readings after history caused Apex to draw diagonals (last 5-min
 * point connected back to the first 1-min point). Gust/ambient = archive only.
 *
 * @return array<string, list<array{0:int, 1:?float}>>
 */
function weather_explorer_series(PDO $pdo, int $start, int $end): array
{
    $series = [
        'wind_ms' => [],
        'gust_ms' => [],
        'ambient_c' => [],
        'module_c' => [],
        'radiation_wm2' => [],
    ];
    /** @var array<int, array<string, ?float>> $byMs */
    $byMs = [];
    $startSql = hp_sql_datetime($start);
    $endSql = hp_sql_datetime($end);

    $stmt = $pdo->prepare(
        'SELECT timestamp, wind_speed, wind_gust, temp_ambient, temp_module, solar_radiation
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
        weather_sample_merge($byMs, $ts * 1000, [
            'wind_ms' => weather_nullable_float($row['wind_speed'] ?? null),
            'gust_ms' => weather_nullable_float($row['wind_gust'] ?? null),
            'ambient_c' => weather_nullable_float($row['temp_ambient'] ?? null),
            'module_c' => weather_nullable_float($row['temp_module'] ?? null),
            'radiation_wm2' => weather_nullable_float($row['solar_radiation'] ?? null),
        ]);
    }

    // Live 1-min SensorBox fields overwrite / fill wind, module, radiation.
    try {
        $live = $pdo->prepare(
            'SELECT timestamp, wind_speed, temp_module, solar_radiation
             FROM readings
             WHERE timestamp >= ? AND timestamp <= ?
               AND (wind_speed IS NOT NULL OR temp_module IS NOT NULL OR solar_radiation IS NOT NULL)
             ORDER BY timestamp ASC'
        );
        $live->execute([$startSql, $endSql]);
        while ($row = $live->fetch(PDO::FETCH_ASSOC)) {
            $ts = strtotime((string)$row['timestamp']);
            if ($ts === false) {
                continue;
            }
            weather_sample_merge($byMs, $ts * 1000, [
                'wind_ms' => weather_nullable_float($row['wind_speed'] ?? null),
                'module_c' => weather_nullable_float($row['temp_module'] ?? null),
                'radiation_wm2' => weather_nullable_float($row['solar_radiation'] ?? null),
            ]);
        }
    } catch (Throwable $e) {
        // readings missing - keep history_5min only.
    }

    ksort($byMs, SORT_NUMERIC);
    $previousTs = null;
    foreach ($byMs as $ms => $values) {
        $ts = (int)floor($ms / 1000);
        // Mixed 1-min + 5-min: break after >3 minutes with no sample (same spirit as live pulse).
        if ($previousTs !== null && ($ts - $previousTs) > 180) {
            $breakMs = ($previousTs + 60) * 1000;
            foreach (array_keys($series) as $key) {
                $series[$key][] = [$breakMs, null];
            }
        }
        foreach (array_keys($series) as $key) {
            $series[$key][] = [$ms, $values[$key] ?? null];
        }
        $previousTs = $ts;
    }
    return $series;
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $ajax = (string)$_GET['ajax'];
    if (!file_exists($dbFile)) {
        http_response_code(503);
        echo json_encode(['error' => 'Database not found']);
        exit;
    }
    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($ajax === 'explorer') {
            $window = hp_explorer_window();
            echo json_encode([
                'start' => $window['start'],
                'end' => $window['end'],
                'day_mode' => $window['day_mode'],
                'series' => weather_explorer_series($pdo, $window['start'], $window['end']),
            ]);
            exit;
        }
        if ($ajax === 'period') {
            $mode = isset($_GET['mode']) ? (string)$_GET['mode'] : '';
            $delta = isset($_GET['delta']) ? (int)$_GET['delta'] : 0;
            $from = null;
            $to = null;
            $period = '';
            $label = '';
            $currentWeek = weather_iso_week_label();
            $currentMonth = date('Y-m');
            if ($mode === 'week') {
                $period = isset($_GET['week']) ? (string)$_GET['week'] : $currentWeek;
                if ($delta !== 0) {
                    $period = weather_shift_week_label($period, $delta);
                }
                if ($period > $currentWeek) {
                    $period = $currentWeek;
                }
                [$from, $to] = weather_iso_week_span($period);
                $label = $period . ' (Mon-Sun)';
            } elseif ($mode === 'month') {
                $period = isset($_GET['month']) ? (string)$_GET['month'] : $currentMonth;
                if ($delta !== 0) {
                    $period = weather_shift_month_label($period, $delta);
                }
                if ($period > $currentMonth) {
                    $period = $currentMonth;
                }
                [$from, $to] = weather_month_span($period);
                $label = $period;
                $rows = ($from !== null && $to !== null) ? weather_period_rows($pdo, $from, $to) : [];
                $atCurrent = $period >= $currentMonth;
            } elseif ($mode === 'year') {
                $currentYear = date('Y');
                $period = isset($_GET['year']) ? (string)$_GET['year'] : $currentYear;
                if ($delta !== 0) {
                    $period = weather_shift_year_label($period, $delta);
                }
                if ($period > $currentYear) {
                    $period = $currentYear;
                }
                $from = $period . '-01-01';
                $to = $period . '-12-31';
                $label = $period;
                $rows = weather_year_rows($pdo, $period);
                $atCurrent = $period >= $currentYear;
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Unsupported period mode']);
                exit;
            }
            if ($mode === 'week') {
                $rows = ($from !== null && $to !== null) ? weather_period_rows($pdo, $from, $to) : [];
                $atCurrent = $period >= $currentWeek;
            }
            if ($from === null || $to === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid period']);
                exit;
            }
            echo json_encode([
                'mode' => $mode,
                'period' => $period,
                'label' => $label,
                'from' => $from,
                'to' => $to,
                'at_current' => $atCurrent,
                'rows' => $rows,
            ]);
            exit;
        }
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported weather action']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Weather data could not be loaded']);
    }
    exit;
}

$records = [];
$weekRows = [];
$monthRows = [];
$yearRows = [];
$weekPeriod = weather_iso_week_label();
$monthPeriod = date('Y-m');
$yearPeriod = date('Y');
$weekLabel = $weekPeriod . ' (Mon-Sun)';
$monthLabel = $monthPeriod;
$yearLabel = $yearPeriod;
$channelAvailable = [
    'wind_ms' => false,
    'gust_ms' => false,
    'ambient_c' => false,
    'module_c' => false,
    'radiation_wm2' => false,
];
$periodAvailable = false;
if (file_exists($dbFile)) {
    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $channelAvailable = weather_channel_available($pdo);
        $records = weather_records($pdo);
        // Hide record rows whose channel never exists (e.g. ambient extremes).
        $recordChannel = [
            'max_wind_ms' => 'gust_ms',
            'max_temp_c' => 'ambient_c',
            'min_temp_c' => 'ambient_c',
            'max_module_temp_c' => 'module_c',
        ];
        $records = array_values(array_filter(
            $records,
            static function (array $row) use ($channelAvailable, $recordChannel): bool {
                $ch = $recordChannel[$row['record_id']] ?? null;
                return $ch === null || !empty($channelAvailable[$ch]);
            }
        ));
        // Show week/month cards whenever a live weather channel exists.
        $periodAvailable = !empty($channelAvailable['wind_ms'])
            || !empty($channelAvailable['module_c'])
            || !empty($channelAvailable['radiation_wm2'])
            || !empty($channelAvailable['gust_ms'])
            || !empty($channelAvailable['ambient_c']);
        if ($periodAvailable) {
            [$weekFrom, $weekTo] = weather_iso_week_span($weekPeriod);
            [$monthFrom, $monthTo] = weather_month_span($monthPeriod);
            if ($weekFrom !== null && $weekTo !== null) {
                $weekRows = weather_period_rows($pdo, $weekFrom, $weekTo);
            }
            if ($monthFrom !== null && $monthTo !== null) {
                $monthRows = weather_period_rows($pdo, $monthFrom, $monthTo);
            }
            $yearRows = weather_year_rows($pdo, $yearPeriod);
        }
    } catch (Throwable $e) {
        // Empty cards below explain that no weather rollups are available.
    }
}
$defaultActive = [];
foreach (['wind_ms', 'module_c', 'radiation_wm2', 'ambient_c', 'gust_ms'] as $ch) {
    if (!empty($channelAvailable[$ch])) {
        $defaultActive[] = $ch;
    }
    if (count($defaultActive) >= 2) {
        break;
    }
}
if (count($defaultActive) === 0 && !empty($channelAvailable['radiation_wm2'])) {
    $defaultActive[] = 'radiation_wm2';
}

$recordLabels = [
    'max_wind_ms' => 'Maximum wind gust',
    'max_temp_c' => 'Highest ambient temperature',
    'min_temp_c' => 'Lowest ambient temperature',
    'max_module_temp_c' => 'Highest module temperature',
];
$recordUnits = [
    'max_wind_ms' => 'm/s',
    'max_temp_c' => 'C',
    'min_temp_c' => 'C',
    'max_module_temp_c' => 'C',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weather | Energy Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        body { background-color: #f8fafc; font-family: system-ui, -apple-system, sans-serif; }
        .card { @apply bg-white p-6 rounded-3xl shadow-sm border border-slate-100 flex flex-col; }
        .nav-arrow {
            @apply w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-700
                   flex items-center justify-center hover:bg-slate-50 shadow-sm transition-colors cursor-pointer;
        }
        .nav-arrow:disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
        .jump-btn {
            @apply text-xs font-semibold px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white
                   text-slate-600 hover:bg-slate-50 hover:text-sky-700 transition-colors cursor-pointer;
        }
        .metric-switch {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
            padding: 4px;
            border: 2px solid #0f172a;
            border-radius: 999px;
            background: #e2e8f0;
        }
        .metric-switch button {
            appearance: none;
            -webkit-appearance: none;
            margin: 0;
            border: 0;
            border-radius: 999px;
            padding: 6px 14px;
            font: 700 12px/1.2 system-ui, sans-serif;
            color: #334155;
            background: transparent;
            cursor: pointer;
        }
        .metric-switch button.metric-on {
            background: #0f172a;
            color: #ffffff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.25);
        }
    </style>
</head>
<body class="p-4 md:p-8">
<div class="max-w-6xl mx-auto space-y-6">
    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="index.php" class="w-10 h-10 rounded-2xl bg-white border border-slate-200 text-slate-600 flex items-center justify-center hover:bg-slate-50 transition-colors shadow-sm">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-black text-slate-900 tracking-tight">Weather</h1>
                <p class="text-xs text-slate-400 mt-0.5">Wind, temperature, and solar radiation history</p>
            </div>
        </div>
        <nav class="flex flex-wrap items-center gap-2" aria-label="Dashboard">
            <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="activity" class="w-3.5 h-3.5 text-emerald-600"></i> Live Dashboard
            </a>
            <a href="analytics.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="line-chart" class="w-3.5 h-3.5 text-indigo-600"></i> Analytics
            </a>
            <a href="summary.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="calendar-range" class="w-3.5 h-3.5 text-emerald-600"></i> Summary
            </a>
            <a href="topn.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="trophy" class="w-3.5 h-3.5 text-amber-500"></i> Top-N
            </a>
            <?= hp_nav_weather(true) ?>
            <?= hp_nav_settings() ?>
        </nav>
    </header>

    <section class="card border-t-4 border-t-sky-500">
        <h2 class="text-lg font-bold text-slate-900 mb-1">All-time weather records</h2>
        <p class="text-xs text-slate-400 mb-3">Click a dated row to explore that day.</p>
        <?php if (count($records) === 0): ?>
            <div class="text-xs text-amber-600 bg-amber-50 border border-amber-200 p-3 rounded-xl">
                No weather records yet. Run <code>python3 rollup.py -buildalltoprecords</code> (or <code>-buildall</code> / <code>-daily</code>).
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-400 border-b border-slate-100">
                            <th class="py-2 pr-3 font-semibold">Record</th>
                            <th class="py-2 pr-3 font-semibold">Value</th>
                            <th class="py-2 font-semibold">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($records as $record): ?>
                        <tr class="hover:bg-sky-50/60 transition-colors">
                            <td class="py-3 pr-3 font-semibold text-slate-700"><?= htmlspecialchars($recordLabels[$record['record_id']] ?? $record['description'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="py-3 pr-3 tabular-nums text-slate-700"><?= htmlspecialchars(number_format($record['record_value'], 1) . ' ' . ($recordUnits[$record['record_id']] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="py-3"><a class="font-mono text-sky-700 hover:underline" href="weather.php?date=<?= urlencode(substr($record['record_date'], 0, 10)) ?>"><?= htmlspecialchars(substr($record['record_date'], 0, 10), ENT_QUOTES, 'UTF-8') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card border-t-4 border-t-cyan-500">
        <div class="flex flex-col gap-3 mb-4">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i data-lucide="cloud-sun" class="w-5 h-5 text-sky-500"></i> Weather explorer
                    </h2>
                    <p class="text-xs text-slate-400">Five-minute weather samples; missing readings remain gaps.</p>
                </div>
                <div id="seriesSwitch" class="metric-switch" role="group" aria-label="Weather series">
                    <?php
                    $seriesButtons = [
                        'wind_ms' => 'Wind',
                        'gust_ms' => 'Gust',
                        'ambient_c' => 'Ambient',
                        'module_c' => 'Module',
                        'radiation_wm2' => 'Radiation',
                    ];
                    foreach ($seriesButtons as $sid => $slabel):
                        if (empty($channelAvailable[$sid])) {
                            continue;
                        }
                        $on = in_array($sid, $defaultActive, true);
                        ?>
                    <button type="button"
                            class="<?= $on ? 'metric-on' : '' ?>"
                            aria-pressed="<?= $on ? 'true' : 'false' ?>"
                            data-series="<?= htmlspecialchars($sid, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($slabel, ENT_QUOTES, 'UTF-8') ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Chart range">
                <?php foreach (['today', '24h', '7d'] as $presetId): ?>
                    <?php $preset = hp_explorer_chart_presets()[$presetId]; ?>
                    <?php $presetOn = !$explorerWindow['custom'] && $explorerWindow['preset'] === $presetId; ?>
                    <button type="button"
                            class="px-2.5 py-1 rounded-full text-[11px] font-semibold border explorer-preset <?= $presetOn ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200' ?>"
                            data-range="<?= htmlspecialchars($presetId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($preset['label'], ENT_QUOTES, 'UTF-8') ?></button>
                <?php endforeach; ?>
                <button type="button" id="panBack" class="nav-arrow" title="Previous day" aria-label="Previous day"><i data-lucide="chevron-left" class="w-5 h-5"></i></button>
                <button type="button" id="panForward" class="nav-arrow" title="Next day" aria-label="Next day"><i data-lucide="chevron-right" class="w-5 h-5"></i></button>
                <button type="button" id="resetView" class="jump-btn">Reset view</button>
                <span id="windowLabel" class="text-[11px] text-slate-500 font-mono ml-1"></span>
            </div>
        </div>
        <div id="explorerWarning" class="hidden text-xs text-amber-600 bg-amber-50 border border-amber-200 p-3 rounded-xl mb-3"></div>
        <div id="weatherExplorer" class="w-full h-[390px]"></div>
    </section>

    <?php if ($periodAvailable): ?>
    <div class="space-y-6">
        <section class="card border-t-4 border-t-indigo-500">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
                <h2 class="text-lg font-bold text-slate-900">Week</h2>
                <div class="flex items-center gap-3">
                    <button type="button" id="weekJump" class="jump-btn" title="Jump to this ISO week">This week</button>
                    <button type="button" id="weekBack" class="nav-arrow" aria-label="Previous week">
                        <i data-lucide="chevron-left" class="w-5 h-5"></i>
                    </button>
                    <div class="min-w-[9rem] text-center">
                        <div id="weekLabel" class="text-sm font-bold text-slate-900 font-mono"><?= htmlspecialchars($weekLabel, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <button type="button" id="weekForward" class="nav-arrow" aria-label="Next week">
                        <i data-lucide="chevron-right" class="w-5 h-5"></i>
                    </button>
                </div>
            </div>
            <p class="text-xs text-slate-400 mb-3">Daily weather extremes (Mon-Sun ISO week).</p>
            <div id="weekChart" class="w-full h-[330px]"></div>
        </section>
        <section class="card border-t-4 border-t-violet-500">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
                <h2 class="text-lg font-bold text-slate-900">Month</h2>
                <div class="flex items-center gap-3">
                    <button type="button" id="monthJump" class="jump-btn" title="Jump to this calendar month">This month</button>
                    <button type="button" id="monthBack" class="nav-arrow" aria-label="Previous month">
                        <i data-lucide="chevron-left" class="w-5 h-5"></i>
                    </button>
                    <div class="min-w-[9rem] text-center">
                        <div id="monthLabel" class="text-sm font-bold text-slate-900 font-mono"><?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <button type="button" id="monthForward" class="nav-arrow" aria-label="Next month">
                        <i data-lucide="chevron-right" class="w-5 h-5"></i>
                    </button>
                </div>
            </div>
            <p class="text-xs text-slate-400 mb-3">Daily weather extremes (calendar month).</p>
            <div id="monthChart" class="w-full h-[330px]"></div>
        </section>
        <section class="card border-t-4 border-t-cyan-500">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
                <h2 class="text-lg font-bold text-slate-900">Year</h2>
                <div class="flex items-center gap-3">
                    <button type="button" id="yearJump" class="jump-btn" title="Jump to this calendar year">This year</button>
                    <button type="button" id="yearBack" class="nav-arrow" aria-label="Previous year">
                        <i data-lucide="chevron-left" class="w-5 h-5"></i>
                    </button>
                    <div class="min-w-[9rem] text-center">
                        <div id="yearLabel" class="text-sm font-bold text-slate-900 font-mono"><?= htmlspecialchars($yearLabel, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <button type="button" id="yearForward" class="nav-arrow" aria-label="Next year">
                        <i data-lucide="chevron-right" class="w-5 h-5"></i>
                    </button>
                </div>
            </div>
            <p class="text-xs text-slate-400 mb-3">Monthly weather extremes (one bar per calendar month).</p>
            <div id="yearChart" class="w-full h-[330px]"></div>
        </section>
    </div>
    <?php endif; ?>
</div>

<script>
    // The server owns local/DST window calculation; the browser only sends
    // explicit bounds while panning a full calendar day.
    const initialWindow = <?= json_encode($explorerWindow, JSON_UNESCAPED_SLASHES) ?>;
    const channelAvailable = <?= json_encode($channelAvailable, JSON_UNESCAPED_SLASHES) ?>;
    const periodAvailable = <?= $periodAvailable ? 'true' : 'false' ?>;
    const currentIsoWeek = <?= json_encode(weather_iso_week_label(), JSON_UNESCAPED_SLASHES) ?>;
    const currentYearMonth = <?= json_encode(date('Y-m'), JSON_UNESCAPED_SLASHES) ?>;
    const currentYear = <?= json_encode(date('Y'), JSON_UNESCAPED_SLASHES) ?>;
    let periodState = {
        week: <?= json_encode($weekPeriod, JSON_UNESCAPED_SLASHES) ?>,
        month: <?= json_encode($monthPeriod, JSON_UNESCAPED_SLASHES) ?>,
        year: <?= json_encode($yearPeriod, JSON_UNESCAPED_SLASHES) ?>
    };
    /** @type {{week: object|null, month: object|null, year: object|null}} */
    let periodCharts = { week: null, month: null, year: null };
    // scale: wind | temp | rad - never share radiation with m/s (flattens wind).
    const seriesMeta = {
        wind_ms: { name: 'Wind', color: '#0284c7', unit: 'm/s', scale: 'wind' },
        gust_ms: { name: 'Gust', color: '#7c3aed', unit: 'm/s', scale: 'wind' },
        ambient_c: { name: 'Ambient', color: '#f97316', unit: 'C', scale: 'temp' },
        module_c: { name: 'Module', color: '#ef4444', unit: 'C', scale: 'temp' },
        radiation_wm2: { name: 'Radiation', color: '#eab308', unit: 'W/m2', scale: 'rad' }
    };
    let active = new Set(<?= json_encode(array_values($defaultActive), JSON_UNESCAPED_SLASHES) ?>);
    let currentWindow = { ...initialWindow };
    let explorerChart = null;

    /** @param {number} seconds @returns {string} */
    function sqlDateTime(seconds) {
        const d = new Date(seconds * 1000);
        const pad = (v) => String(v).padStart(2, '0');
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' '
            + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }

    /** @returns {void} */
    function updateWindowControls() {
        const start = new Date(currentWindow.start * 1000);
        const end = new Date(currentWindow.end * 1000);
        document.getElementById('windowLabel').textContent =
            start.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) + ' - '
            + end.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
        document.getElementById('panBack').disabled = !currentWindow.day_mode;
        const today = new Date();
        document.getElementById('panForward').disabled = !currentWindow.day_mode
            || start.toDateString() === today.toDateString();
    }

    /**
     * ApexCharts: one y-axis entry per series; shared scales use the same seriesName
     * as the first series in that scale group (wind / temp / rad).
     *
     * @param {string[]} enabled
     * @returns {{series: object[], yaxis: object[], colors: string[]}}
     */
    function buildScaledSeries(enabled) {
        const scaleTitle = { wind: 'm/s', temp: 'Temperature (C)', rad: 'W/m2' };
        const scaleOpposite = { wind: false, temp: true, rad: true };
        const leadName = {};
        const series = [];
        const yaxis = [];
        const colors = [];
        enabled.forEach((key) => {
            const meta = seriesMeta[key];
            const scale = meta.scale;
            if (!leadName[scale]) {
                leadName[scale] = meta.name;
                yaxis.push({
                    seriesName: meta.name,
                    opposite: scaleOpposite[scale],
                    show: true,
                    title: { text: scaleTitle[scale] },
                    labels: { formatter: (v) => Number(v).toFixed(1) }
                });
            } else {
                yaxis.push({
                    seriesName: leadName[scale],
                    show: false
                });
            }
            series.push({ name: meta.name, data: [] });
            colors.push(meta.color);
        });
        return { series, yaxis, colors, leadName };
    }

    /** @param {object} payload @returns {void} */
    function renderExplorer(payload) {
        const enabled = Array.from(active);
        const built = buildScaledSeries(enabled);
        built.series.forEach((s, i) => {
            s.data = payload.series[enabled[i]] || [];
        });
        const options = {
            chart: { type: 'line', height: 390, toolbar: { show: true }, animations: { enabled: false }, zoom: { enabled: true } },
            series: built.series,
            colors: built.colors,
            stroke: { width: 2, curve: 'straight' },
            markers: { size: 0 },
            dataLabels: { enabled: false },
            xaxis: { type: 'datetime', min: payload.start * 1000, max: payload.end * 1000 },
            yaxis: built.yaxis,
            noData: { text: 'No weather samples in this window' },
            legend: { show: true, position: 'top' },
            tooltip: {
                shared: true,
                x: { format: 'dd MMM yyyy HH:mm' },
                y: { formatter: (v, ctx) => v === null ? '-' : Number(v).toFixed(1) + ' ' + seriesMeta[enabled[ctx.seriesIndex]].unit }
            }
        };
        if (explorerChart) {
            explorerChart.destroy();
        }
        explorerChart = new ApexCharts(document.querySelector('#weatherExplorer'), options);
        explorerChart.render();
    }

    /** @returns {Promise<void>} */
    async function loadExplorer() {
        const warning = document.getElementById('explorerWarning');
        warning.classList.add('hidden');
        const query = new URLSearchParams({
            ajax: 'explorer',
            from: sqlDateTime(currentWindow.start),
            to: sqlDateTime(currentWindow.end)
        });
        try {
            const response = await fetch('weather.php?' + query.toString(), { cache: 'no-store' });
            const payload = await response.json();
            if (!response.ok || payload.error) {
                throw new Error(payload.error || 'Weather request failed');
            }
            currentWindow.start = payload.start;
            currentWindow.end = payload.end;
            currentWindow.day_mode = payload.day_mode;
            updateWindowControls();
            renderExplorer(payload);
        } catch (error) {
            warning.textContent = error instanceof Error ? error.message : 'Weather data could not be loaded';
            warning.classList.remove('hidden');
        }
    }

    /** @param {string} range @returns {void} */
    function setPreset(range) {
        window.location.href = 'weather.php?range=' + encodeURIComponent(range);
    }

    /** @param {number} direction @returns {void} */
    function panDay(direction) {
        if (!currentWindow.day_mode) {
            return;
        }
        const start = new Date(currentWindow.start * 1000);
        start.setDate(start.getDate() + direction);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        if (start > today) {
            return;
        }
        start.setHours(0, 0, 0, 0);
        const end = new Date(start);
        end.setDate(end.getDate() + 1);
        currentWindow = {
            start: Math.floor(start.getTime() / 1000),
            end: Math.floor(end.getTime() / 1000) - 1,
            day_mode: true
        };
        loadExplorer();
    }

    /**
     * Daily/monthly bars on separate scales. mode is week|month|year.
     * @param {'week'|'month'|'year'} mode
     * @param {Array<object>} rows
     * @returns {void}
     */
    function renderPeriodChart(mode, rows) {
        const host = document.querySelector('#' + mode + 'Chart');
        if (!host) {
            return;
        }
        if (periodCharts[mode]) {
            periodCharts[mode].destroy();
            periodCharts[mode] = null;
        }
        host.innerHTML = '';
        if (!Array.isArray(rows) || rows.length === 0) {
            host.innerHTML = '<p class="text-sm text-slate-400 p-4">No weather samples in this period.</p>';
            return;
        }
        // Drop series with no points in this span. All-null series break Apex multi-axis bars.
        const definitions = [
            { name: 'Wind max', key: 'wind_ms', color: '#0284c7', unit: 'm/s', scale: 'wind', need: 'wind_ms' },
            { name: 'Gust', key: 'gust_ms', color: '#7c3aed', unit: 'm/s', scale: 'wind', need: 'gust_ms' },
            { name: 'Ambient max', key: 'ambient_max_c', color: '#f97316', unit: 'C', scale: 'temp', need: 'ambient_c' },
            { name: 'Ambient min', key: 'ambient_min_c', color: '#0ea5e9', unit: 'C', scale: 'temp', need: 'ambient_c' },
            { name: 'Module max', key: 'module_max_c', color: '#ef4444', unit: 'C', scale: 'temp', need: 'module_c' },
            { name: 'Radiation max', key: 'radiation_wm2', color: '#eab308', unit: 'W/m2', scale: 'rad', need: 'radiation_wm2' }
        ].filter((def) => {
            if (!channelAvailable[def.need]) {
                return false;
            }
            return rows.some((row) => row[def.key] !== null && row[def.key] !== undefined);
        });
        if (!definitions.length) {
            host.innerHTML = '<p class="text-sm text-slate-400 p-4">No plottable weather extremes in this range.</p>';
            return;
        }
        const scaleTitle = { wind: 'm/s', temp: 'C', rad: 'W/m2' };
        const scaleOpposite = { wind: false, temp: true, rad: true };
        const lead = {};
        const yaxis = [];
        const series = [];
        const colors = [];
        definitions.forEach((def) => {
            if (!lead[def.scale]) {
                lead[def.scale] = def.name;
                yaxis.push({
                    seriesName: def.name,
                    opposite: scaleOpposite[def.scale],
                    show: true,
                    title: { text: scaleTitle[def.scale] },
                    labels: { formatter: (v) => Number(v).toFixed(1) }
                });
            } else {
                yaxis.push({ seriesName: lead[def.scale], show: false });
            }
            series.push({ name: def.name, data: rows.map((row) => row[def.key]) });
            colors.push(def.color);
        });
        periodCharts[mode] = new ApexCharts(host, {
            chart: { type: 'bar', height: 330, toolbar: { show: false }, animations: { enabled: false } },
            series,
            colors,
            plotOptions: { bar: { columnWidth: '72%', borderRadius: 2 } },
            dataLabels: { enabled: false },
            // Week/month dates are YYYY-MM-DD; year rows are YYYY-MM. slice(5) -> MM-DD or MM.
            xaxis: { categories: rows.map((row) => String(row.date).slice(5)), labels: { rotate: -45 } },
            yaxis,
            legend: { position: 'top' },
            // Bar charts default intersect:true; Apex throws if shared is also true.
            tooltip: {
                shared: true,
                intersect: false,
                y: { formatter: (v, ctx) => v === null ? '-' : Number(v).toFixed(1) + ' ' + definitions[ctx.seriesIndex].unit }
            }
        });
        periodCharts[mode].render();
    }

    /** @param {'week'|'month'|'year'} mode @param {boolean} atCurrent @returns {void} */
    function updatePeriodNav(mode, atCurrent) {
        const forward = document.getElementById(mode + 'Forward');
        if (!forward) {
            return;
        }
        forward.disabled = !!atCurrent;
    }

    /**
     * @param {'week'|'month'|'year'} mode
     * @param {number} [delta]
     * @returns {Promise<void>}
     */
    async function loadPeriod(mode, delta) {
        const query = new URLSearchParams({ ajax: 'period', mode });
        if (mode === 'week') {
            query.set('week', periodState.week);
        } else if (mode === 'month') {
            query.set('month', periodState.month);
        } else {
            query.set('year', periodState.year);
        }
        if (delta) {
            query.set('delta', String(delta));
        }
        const labelEl = document.getElementById(mode + 'Label');
        try {
            const response = await fetch('weather.php?' + query.toString(), { cache: 'no-store' });
            const payload = await response.json();
            if (!response.ok || payload.error) {
                throw new Error(payload.error || 'Period request failed');
            }
            periodState[mode] = payload.period;
            if (labelEl) {
                labelEl.textContent = payload.label || payload.period;
            }
            updatePeriodNav(mode, payload.at_current === true);
            renderPeriodChart(mode, payload.rows || []);
        } catch (error) {
            if (labelEl) {
                labelEl.textContent = periodState[mode];
            }
            const host = document.querySelector('#' + mode + 'Chart');
            if (host) {
                host.innerHTML = '<p class="text-sm text-rose-600 p-4">'
                    + (error instanceof Error ? error.message : 'Period data could not be loaded')
                    + '</p>';
            }
        }
    }

    /** @param {'week'|'month'|'year'} mode @param {number} delta @returns {void} */
    function stepPeriod(mode, delta) {
        loadPeriod(mode, delta);
    }

    /** @param {'week'|'month'|'year'} mode @returns {void} */
    function jumpPeriod(mode) {
        if (mode === 'week') {
            periodState.week = currentIsoWeek;
        } else if (mode === 'month') {
            periodState.month = currentYearMonth;
        } else {
            periodState.year = currentYear;
        }
        loadPeriod(mode, 0);
    }

    document.querySelectorAll('.explorer-preset').forEach((button) => {
        button.addEventListener('click', () => setPreset(button.dataset.range || 'today'));
    });
    document.getElementById('panBack').addEventListener('click', () => panDay(-1));
    document.getElementById('panForward').addEventListener('click', () => panDay(1));
    document.getElementById('resetView').addEventListener('click', () => setPreset('today'));
    document.querySelectorAll('#seriesSwitch button').forEach((button) => {
        button.addEventListener('click', () => {
            const key = button.dataset.series;
            if (!key) {
                return;
            }
            if (active.has(key)) {
                if (active.size === 1) {
                    return;
                }
                active.delete(key);
                button.classList.remove('metric-on');
                button.setAttribute('aria-pressed', 'false');
            } else {
                active.add(key);
                button.classList.add('metric-on');
                button.setAttribute('aria-pressed', 'true');
            }
            loadExplorer();
        });
    });

    if (periodAvailable) {
        document.getElementById('weekBack').addEventListener('click', () => stepPeriod('week', -1));
        document.getElementById('weekForward').addEventListener('click', () => stepPeriod('week', 1));
        document.getElementById('weekJump').addEventListener('click', () => jumpPeriod('week'));
        document.getElementById('monthBack').addEventListener('click', () => stepPeriod('month', -1));
        document.getElementById('monthForward').addEventListener('click', () => stepPeriod('month', 1));
        document.getElementById('monthJump').addEventListener('click', () => jumpPeriod('month'));
        document.getElementById('yearBack').addEventListener('click', () => stepPeriod('year', -1));
        document.getElementById('yearForward').addEventListener('click', () => stepPeriod('year', 1));
        document.getElementById('yearJump').addEventListener('click', () => jumpPeriod('year'));
    }

    lucide.createIcons();
    updateWindowControls();
    if (active.size === 0) {
        document.getElementById('explorerWarning').textContent =
            'No SensorBox weather channels found yet (wind / module / radiation).';
        document.getElementById('explorerWarning').classList.remove('hidden');
    } else {
        loadExplorer();
    }
    if (periodAvailable) {
        renderPeriodChart('week', <?= json_encode($weekRows, JSON_UNESCAPED_SLASHES) ?>);
        renderPeriodChart('month', <?= json_encode($monthRows, JSON_UNESCAPED_SLASHES) ?>);
        renderPeriodChart('year', <?= json_encode($yearRows, JSON_UNESCAPED_SLASHES) ?>);
        updatePeriodNav('week', periodState.week >= currentIsoWeek);
        updatePeriodNav('month', periodState.month >= currentYearMonth);
        updatePeriodNav('year', periodState.year >= currentYear);
    }
</script>
</body>
</html>
