<?php
# --- file: gas_water.php ---
/**
 * Gas / water period helpers (A11).
 * Unit: m3. Fluvius gas stays in fluvius_interval; P1 counters on readings / history_5min.
 * daily_history.gas_m3 / water_m3 and monthly_history.* are filled by rollup.py.
 */

/**
 * True when daily_history has at least one non-null water_m3 (P1 water present historically).
 */
function gw_has_water_history(PDO $pdo): bool
{
    try {
        $v = $pdo->query(
            'SELECT 1 FROM daily_history WHERE water_m3 IS NOT NULL LIMIT 1'
        )->fetchColumn();
        return $v !== false && $v !== null;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * True when the latest P1 snapshot lists an M-Bus water_meter (same signal as powertests).
 * Day usage of 0 is still valid when the meter is present; do not use readings.water > 0.
 * Historical phantom water=0.0 rows are ignored for the summary/top-n chip.
 */
function gw_has_live_water(PDO $pdo): bool
{
    try {
        $row = $pdo->query(
            'SELECT data_json FROM latest_state WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row || !isset($row['data_json'])) {
            return false;
        }
        $state = json_decode((string)$row['data_json'], true);
        if (!is_array($state)) {
            return false;
        }
        // Prefer explicit external list (powertests Sub-Meters).
        $external = [];
        if (isset($state['p1_data']['external']) && is_array($state['p1_data']['external'])) {
            $external = $state['p1_data']['external'];
        } elseif (isset($state['p1']['external']) && is_array($state['p1']['external'])) {
            $external = $state['p1']['external'];
        }
        foreach ($external as $ext) {
            if (!is_array($ext)) {
                continue;
            }
            if (($ext['type'] ?? '') === 'water_meter') {
                return true;
            }
        }
        // Logger also sets has_water when a water_meter value was parsed this poll.
        if (!empty($state['has_water'])) {
            return true;
        }
        return false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Show Water on summary / top-n only when live P1 reports a water_meter.
 * Quiet days (0 m3) stay visible once the meter is present. water-link is future.
 */
function gw_water_available(PDO $pdo): bool
{
    return gw_has_live_water($pdo);
}

/**
 * True when gas history or Fluvius gas EAN data exists.
 */
function gw_gas_available(PDO $pdo): bool
{
    try {
        $v = $pdo->query(
            'SELECT 1 FROM daily_history WHERE gas_m3 IS NOT NULL LIMIT 1'
        )->fetchColumn();
        if ($v !== false && $v !== null) {
            return true;
        }
    } catch (Exception $e) {
    }
    try {
        $stmt = $pdo->query(
            "SELECT 1 FROM readings WHERE gas IS NOT NULL
             ORDER BY timestamp DESC LIMIT 1"
        );
        if ($stmt->fetchColumn() !== false) {
            return true;
        }
    } catch (Exception $e) {
    }
    try {
        $ean = '';
        $row = $pdo->query(
            "SELECT value FROM app_settings WHERE key = 'fluvius_ean_gas' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['value'])) {
            $ean = preg_replace('/\D+/', '', (string)$row['value']);
        }
        if ($ean === '') {
            return false;
        }
        $stmt = $pdo->prepare(
            "SELECT 1 FROM fluvius_interval
             WHERE ean = ? AND register = 'afname' AND unit = 'm3' LIMIT 1"
        );
        $stmt->execute([$ean]);
        return $stmt->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Daily m3 series for a list of YYYY-MM-DD dates (null when missing).
 *
 * @param list<string> $dates
 * @return array{categories: list<string>, values: list<?float>, total: ?float}
 */
function gw_daily_series(PDO $pdo, array $dates, string $column): array
{
    if ($column !== 'gas_m3' && $column !== 'water_m3') {
        throw new InvalidArgumentException('column');
    }
    $byDate = [];
    if (count($dates) > 0) {
        $placeholders = implode(',', array_fill(0, count($dates), '?'));
        try {
            $stmt = $pdo->prepare(
                "SELECT date, {$column} AS v FROM daily_history
                 WHERE date IN ({$placeholders})"
            );
            $stmt->execute(array_values($dates));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $byDate[(string)$row['date']] = $row['v'] !== null ? (float)$row['v'] : null;
            }
        } catch (Exception $e) {
            $byDate = [];
        }
    }
    $categories = [];
    $values = [];
    $total = null;
    foreach ($dates as $d) {
        $categories[] = $d;
        $v = $byDate[$d] ?? null;
        $values[] = $v;
        if ($v !== null) {
            $total = ($total === null) ? $v : ($total + $v);
        }
    }
    return [
        'categories' => $categories,
        'values' => $values,
        'total' => $total === null ? null : round($total, 3),
    ];
}

/**
 * Month bars for one calendar year from monthly_history (fallback daily sum).
 *
 * @return array{categories: list<string>, values: list<?float>, total: ?float}
 */
function gw_year_series(PDO $pdo, string $year, string $column): array
{
    if ($column !== 'gas_m3' && $column !== 'water_m3') {
        throw new InvalidArgumentException('column');
    }
    $categories = [];
    $values = [];
    $total = null;
    $fromMonthly = false;
    try {
        $stmt = $pdo->prepare(
            "SELECT year_month, {$column} AS v FROM monthly_history
             WHERE year_month LIKE ? ORDER BY year_month ASC"
        );
        $stmt->execute([$year . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 0) {
            $fromMonthly = true;
            foreach ($rows as $row) {
                $categories[] = (string)$row['year_month'];
                $v = $row['v'] !== null ? round((float)$row['v'], 3) : null;
                $values[] = $v;
                if ($v !== null) {
                    $total = ($total === null) ? $v : ($total + $v);
                }
            }
        }
    } catch (Exception $e) {
    }
    if (!$fromMonthly) {
        try {
            $stmt = $pdo->prepare(
                "SELECT substr(date, 1, 7) AS ym, SUM({$column}) AS v
                 FROM daily_history WHERE date LIKE ?
                 GROUP BY ym ORDER BY ym ASC"
            );
            $stmt->execute([$year . '%']);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $categories[] = (string)$row['ym'];
                $v = $row['v'] !== null ? round((float)$row['v'], 3) : null;
                $values[] = $v;
                if ($v !== null) {
                    $total = ($total === null) ? $v : ($total + $v);
                }
            }
        } catch (Exception $e) {
        }
    }
    return [
        'categories' => $categories,
        'values' => $values,
        'total' => $total === null ? null : round($total, 3),
    ];
}

/**
 * One bar per calendar year.
 *
 * @return array{categories: list<string>, values: list<?float>, total: ?float}
 */
function gw_all_years_series(PDO $pdo, string $column): array
{
    if ($column !== 'gas_m3' && $column !== 'water_m3') {
        throw new InvalidArgumentException('column');
    }
    $categories = [];
    $values = [];
    $total = null;
    $rows = [];
    try {
        $rows = $pdo->query(
            "SELECT substr(year_month, 1, 4) AS y, SUM({$column}) AS v
             FROM monthly_history
             GROUP BY y ORDER BY y ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
    }
    if (count($rows) === 0) {
        try {
            $rows = $pdo->query(
                "SELECT substr(date, 1, 4) AS y, SUM({$column}) AS v
                 FROM daily_history
                 GROUP BY y ORDER BY y ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $rows = [];
        }
    }
    foreach ($rows as $row) {
        if (!$row['y']) {
            continue;
        }
        $v = $row['v'] !== null ? round((float)$row['v'], 3) : null;
        if ($v === null) {
            continue;
        }
        $categories[] = (string)$row['y'];
        $values[] = $v;
        $total = ($total === null) ? $v : ($total + $v);
    }
    return [
        'categories' => $categories,
        'values' => $values,
        'total' => $total === null ? null : round($total, 3),
    ];
}

/**
 * Build interval volume series from lifetime counters (1-min or 5-min rows).
 * Returns m3 per sample interval (for day charts) or m3/h when $asRate is true.
 *
 * @param list<array{timestamp: string, counter: ?float}> $rows
 * @return list<array{0: int, 1: ?float}> ms + value
 */
function gw_counter_to_interval_points(array $rows, bool $asRate): array
{
    $out = [];
    $prevTs = null;
    $prevCtr = null;
    foreach ($rows as $row) {
        $ts = strtotime((string)$row['timestamp']);
        if ($ts === false) {
            continue;
        }
        $ctr = $row['counter'];
        $ms = $ts * 1000;
        if ($ctr === null || $ctr === '') {
            $out[] = [$ms, null];
            $prevTs = $ts;
            $prevCtr = null;
            continue;
        }
        $ctrF = (float)$ctr;
        if ($prevTs === null || $prevCtr === null) {
            $out[] = [$ms, null];
            $prevTs = $ts;
            $prevCtr = $ctrF;
            continue;
        }
        $dt = $ts - $prevTs;
        $dV = $ctrF - $prevCtr;
        $prevTs = $ts;
        $prevCtr = $ctrF;
        if ($dt <= 0 || $dV < -0.01) {
            $out[] = [$ms, null];
            continue;
        }
        if ($dV < 0) {
            $dV = 0.0;
        }
        if ($asRate) {
            // Instantaneous rate m3/h from the sample delta.
            $out[] = [$ms, round($dV * (3600.0 / $dt), 4)];
        } else {
            $out[] = [$ms, round($dV, 4)];
        }
    }
    return $out;
}

/**
 * Gas day curve: 1-min P1 when enough samples, else 5-min history, else Fluvius hours.
 *
 * @return array{
 *   source: 'p1_1min'|'p1_5min'|'fluvius_hour'|'none',
 *   points: list<array{0: int, 1: ?float}>,
 *   total_m3: ?float,
 *   unit: string
 * }
 */
function gw_gas_day_curve(PDO $pdo, string $date): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['source' => 'none', 'points' => [], 'total_m3' => null, 'unit' => 'm3'];
    }
    $start = $date . ' 00:00:00';
    $end = $date . ' 23:59:59';

    // Prefer 1-min P1 when the day has a usable set of gas samples.
    try {
        $stmt = $pdo->prepare(
            'SELECT timestamp, gas AS counter FROM readings
             WHERE timestamp >= ? AND timestamp <= ? AND gas IS NOT NULL
             ORDER BY timestamp ASC'
        );
        $stmt->execute([$start, $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) >= 3) {
            $points = gw_counter_to_interval_points($rows, false);
            $total = gw_day_total_m3($pdo, $date, 'gas_m3');
            return [
                'source' => 'p1_1min',
                'points' => $points,
                'total_m3' => $total,
                'unit' => 'm3',
            ];
        }
    } catch (Exception $e) {
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT timestamp, gas AS counter FROM history_5min
             WHERE timestamp >= ? AND timestamp <= ? AND gas IS NOT NULL
             ORDER BY timestamp ASC'
        );
        $stmt->execute([$start, $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) >= 2) {
            $points = gw_counter_to_interval_points($rows, false);
            $total = gw_day_total_m3($pdo, $date, 'gas_m3');
            return [
                'source' => 'p1_5min',
                'points' => $points,
                'total_m3' => $total,
                'unit' => 'm3',
            ];
        }
    } catch (Exception $e) {
    }

    // Fluvius hour bars (volume per hour at interval_start).
    $ean = '';
    try {
        $row = $pdo->query(
            "SELECT value FROM app_settings WHERE key = 'fluvius_ean_gas' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['value'])) {
            $ean = preg_replace('/\D+/', '', (string)$row['value']);
        }
    } catch (Exception $e) {
    }
    $points = [];
    $total = null;
    if ($ean !== '') {
        try {
            $stmt = $pdo->prepare(
                "SELECT interval_start, volume FROM fluvius_interval
                 WHERE ean = ? AND register = 'afname' AND unit = 'm3'
                   AND resolution = 'hour'
                   AND substr(interval_start, 1, 10) = ?
                 ORDER BY interval_start ASC, occurrence ASC"
            );
            $stmt->execute([$ean, $date]);
            $byTs = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ts = strtotime((string)$row['interval_start']);
                if ($ts === false) {
                    continue;
                }
                $v = $row['volume'] !== null ? (float)$row['volume'] : 0.0;
                $key = (string)$row['interval_start'];
                // Autumn duplicate local starts: sum occurrences.
                $byTs[$key] = ($byTs[$key] ?? 0.0) + $v;
            }
            foreach ($byTs as $key => $v) {
                $ts = strtotime($key);
                if ($ts === false) {
                    continue;
                }
                $points[] = [$ts * 1000, round($v, 4)];
                $total = ($total === null) ? $v : ($total + $v);
            }
        } catch (Exception $e) {
        }
    }
    if (count($points) === 0) {
        $total = gw_day_total_m3($pdo, $date, 'gas_m3');
        return ['source' => 'none', 'points' => [], 'total_m3' => $total, 'unit' => 'm3'];
    }
    return [
        'source' => 'fluvius_hour',
        'points' => $points,
        'total_m3' => $total === null ? null : round($total, 3),
        'unit' => 'm3',
    ];
}

/**
 * @return ?float
 */
function gw_day_total_m3(PDO $pdo, string $date, string $column): ?float
{
    if ($column !== 'gas_m3' && $column !== 'water_m3') {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT {$column} FROM daily_history WHERE date = ?");
        $stmt->execute([$date]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : round((float)$v, 3);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Instant m3/h series for the live 2 h pulse from readings.
 *
 * @return list<array{0: int, 1: ?float}>
 */
function gw_live_rate_series(PDO $pdo, string $column, int $startTs, int $endTs): array
{
    if ($column !== 'gas' && $column !== 'water') {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT timestamp, {$column} AS counter FROM readings
             WHERE timestamp >= ? AND timestamp <= ?
             ORDER BY timestamp ASC"
        );
        $stmt->execute([
            date('Y-m-d H:i:s', $startTs),
            date('Y-m-d H:i:s', $endTs),
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
    return gw_counter_to_interval_points($rows, true);
}

/**
 * Gas or water rate curve for an arbitrary explorer window (analytics).
 * Prefer 1-min P1, else 5-min history; gas may fall back to Fluvius hour volumes
 * (plotted as m3/h for that hour). Water has no Fluvius path.
 *
 * @return array{
 *   source: 'p1_1min'|'p1_5min'|'fluvius_hour'|'none',
 *   points: list<array{0: int, 1: ?float}>,
 *   unit: string
 * }
 */
function gw_utility_window_curve(PDO $pdo, string $kind, int $startTs, int $endTs): array
{
    if ($kind !== 'gas' && $kind !== 'water') {
        return ['source' => 'none', 'points' => [], 'unit' => 'm3/h'];
    }
    if ($endTs <= $startTs) {
        return ['source' => 'none', 'points' => [], 'unit' => 'm3/h'];
    }
    $column = $kind;
    $startSql = date('Y-m-d H:i:s', $startTs);
    $endSql = date('Y-m-d H:i:s', $endTs);

    try {
        $stmt = $pdo->prepare(
            "SELECT timestamp, {$column} AS counter FROM readings
             WHERE timestamp >= ? AND timestamp <= ? AND {$column} IS NOT NULL
             ORDER BY timestamp ASC"
        );
        $stmt->execute([$startSql, $endSql]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) >= 3) {
            return [
                'source' => 'p1_1min',
                'points' => gw_counter_to_interval_points($rows, true),
                'unit' => 'm3/h',
            ];
        }
    } catch (Exception $e) {
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT timestamp, {$column} AS counter FROM history_5min
             WHERE timestamp >= ? AND timestamp <= ? AND {$column} IS NOT NULL
             ORDER BY timestamp ASC"
        );
        $stmt->execute([$startSql, $endSql]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) >= 2) {
            return [
                'source' => 'p1_5min',
                'points' => gw_counter_to_interval_points($rows, true),
                'unit' => 'm3/h',
            ];
        }
    } catch (Exception $e) {
    }

    if ($kind !== 'gas') {
        return ['source' => 'none', 'points' => [], 'unit' => 'm3/h'];
    }

    $ean = '';
    try {
        $row = $pdo->query(
            "SELECT value FROM app_settings WHERE key = 'fluvius_ean_gas' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['value'])) {
            $ean = preg_replace('/\D+/', '', (string)$row['value']);
        }
    } catch (Exception $e) {
    }
    if ($ean === '') {
        return ['source' => 'none', 'points' => [], 'unit' => 'm3/h'];
    }
    $points = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT interval_start, volume FROM fluvius_interval
             WHERE ean = ? AND register = 'afname' AND unit = 'm3'
               AND resolution = 'hour'
               AND interval_start >= ? AND interval_start <= ?
             ORDER BY interval_start ASC, occurrence ASC"
        );
        $stmt->execute([$ean, $startSql, $endSql]);
        $byTs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = (string)$row['interval_start'];
            $v = $row['volume'] !== null ? (float)$row['volume'] : 0.0;
            $byTs[$key] = ($byTs[$key] ?? 0.0) + $v;
        }
        foreach ($byTs as $key => $v) {
            $ts = strtotime($key);
            if ($ts === false) {
                continue;
            }
            // One-hour Fluvius bucket: m3 in that hour == average m3/h for the hour.
            $points[] = [$ts * 1000, round($v, 4)];
        }
    } catch (Exception $e) {
        return ['source' => 'none', 'points' => [], 'unit' => 'm3/h'];
    }
    if (count($points) === 0) {
        return ['source' => 'none', 'points' => [], 'unit' => 'm3/h'];
    }
    return ['source' => 'fluvius_hour', 'points' => $points, 'unit' => 'm3/h'];
}

/**
 * Top-N days by gas_m3 or water_m3.
 *
 * @return list<array{rank: int, period_key: string, m3: float}>
 */
function gw_top_days(PDO $pdo, string $column, string $direction, int $limit, bool $excludeToday, bool $excludeZero): array
{
    if ($column !== 'gas_m3' && $column !== 'water_m3') {
        return [];
    }
    $dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
    $today = date('Y-m-d');
    $sql = "SELECT date AS period_key, {$column} AS m3
            FROM daily_history
            WHERE {$column} IS NOT NULL";
    $params = [];
    if ($excludeToday) {
        $sql .= ' AND date < ?';
        $params[] = $today;
    }
    if ($excludeZero) {
        $sql .= " AND {$column} > 0";
    }
    $sql .= " ORDER BY {$column} {$dir}, date ASC LIMIT " . (int)$limit;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Exception $e) {
        return [];
    }
    $out = [];
    $rank = 1;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[] = [
            'rank' => $rank,
            'period_key' => (string)$row['period_key'],
            'm3' => round((float)$row['m3'], 3),
        ];
        $rank++;
    }
    return $out;
}

/**
 * Top-N months by gas_m3 or water_m3.
 *
 * @return list<array{rank: int, period_key: string, m3: float}>
 */
function gw_top_months(PDO $pdo, string $column, string $direction, int $limit, bool $excludeZero): array
{
    if ($column !== 'gas_m3' && $column !== 'water_m3') {
        return [];
    }
    $dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
    $sql = "SELECT year_month AS period_key, {$column} AS m3
            FROM monthly_history
            WHERE {$column} IS NOT NULL";
    if ($excludeZero) {
        $sql .= " AND {$column} > 0";
    }
    $sql .= " ORDER BY {$column} {$dir}, year_month ASC LIMIT " . (int)$limit;
    try {
        $stmt = $pdo->query($sql);
    } catch (Exception $e) {
        return [];
    }
    $out = [];
    $rank = 1;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[] = [
            'rank' => $rank,
            'period_key' => (string)$row['period_key'],
            'm3' => round((float)$row['m3'], 3),
        ];
        $rank++;
    }
    return $out;
}
