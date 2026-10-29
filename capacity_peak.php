<?php
# --- file: capacity_peak.php ---
/**
 * Fluvius monthly Capacity peak (piekvermogen) helpers for summary.php / topn.php (A12).
 * Source: fluvius_interval. Open calendar month may be replaced by live P1 peak (kW).
 */

/**
 * Digits-only electricity EAN from Settings, or empty.
 */
function capacity_peak_ean(PDO $pdo): string
{
    $stored = hp_load_stored($pdo);
    $merged = hp_merge_settings($stored);
    $digits = preg_replace('/\D/', '', (string)($merged['fluvius_ean_electricity'] ?? ''));
    return is_string($digits) ? $digits : '';
}

/**
 * Live P1 monthly capacity peak in kW from latest_state, or null.
 */
function capacity_peak_live_kw(PDO $pdo): ?float
{
    try {
        $stmt = $pdo->query('SELECT data_json FROM latest_state WHERE id = 1');
        $json = $stmt->fetchColumn();
        if ($json === false || $json === null || $json === '') {
            return null;
        }
        $state = json_decode((string)$json, true);
        if (!is_array($state)) {
            return null;
        }
        $p1 = $state['p1_data'] ?? null;
        if (!is_array($p1)) {
            return null;
        }
        if (!isset($p1['montly_power_peak_w'])) {
            return null;
        }
        $watts = (float)$p1['montly_power_peak_w'];
        if ($watts <= 0) {
            return null;
        }
        return round($watts / 1000.0, 3);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Fluvius piekvermogen months for the configured electricity EAN.
 * Key = YYYY-MM, value = kW. Multiple occurrences for one month keep the max volume.
 *
 * @return array<string, float>
 */
function capacity_peak_fluvius_months(PDO $pdo): array
{
    $ean = capacity_peak_ean($pdo);
    if ($ean === '') {
        return [];
    }
    $byMonth = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT substr(interval_start, 1, 7) AS ym, MAX(volume) AS peak_kw
             FROM fluvius_interval
             WHERE ean = ?
               AND register = 'piekvermogen'
               AND resolution = 'month'
             GROUP BY ym
             ORDER BY ym ASC"
        );
        $stmt->execute([$ean]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ym = (string)($row['ym'] ?? '');
            if ($ym === '' || $row['peak_kw'] === null) {
                continue;
            }
            $byMonth[$ym] = round((float)$row['peak_kw'], 3);
        }
    } catch (Exception $e) {
        return [];
    }
    return $byMonth;
}

/**
 * Fluvius months with the open local month replaced by live P1 kW when available.
 *
 * @return array<string, float>
 */
function capacity_peak_months_display(PDO $pdo): array
{
    $months = capacity_peak_fluvius_months($pdo);
    $live = capacity_peak_live_kw($pdo);
    if ($live === null) {
        return $months;
    }
    $openYm = date('Y-m');
    $months[$openYm] = $live;
    ksort($months);
    return $months;
}

/**
 * Distinct calendar years present in a YYYY-MM map.
 *
 * @param array<string, float> $months
 * @return list<string>
 */
function capacity_peak_years(array $months): array
{
    $years = [];
    foreach (array_keys($months) as $ym) {
        if (strlen($ym) >= 4) {
            $years[substr($ym, 0, 4)] = true;
        }
    }
    $list = array_keys($years);
    sort($list);
    return $list;
}

/**
 * Twelve month slots for one year (gaps stay null).
 *
 * @param array<string, float> $months
 * @return array{categories: list<string>, peaks: list<?float>, max_kw: ?float, avg_kw: ?float, count: int}
 */
function capacity_peak_year_payload(array $months, string $year): array
{
    $categories = [];
    $peaks = [];
    $sum = 0.0;
    $n = 0;
    $max = null;
    for ($m = 1; $m <= 12; $m++) {
        $ym = sprintf('%s-%02d', $year, $m);
        $categories[] = $ym;
        if (array_key_exists($ym, $months)) {
            $v = $months[$ym];
            $peaks[] = $v;
            $sum += $v;
            $n++;
            if ($max === null || $v > $max) {
                $max = $v;
            }
        } else {
            $peaks[] = null;
        }
    }
    return [
        'categories' => $categories,
        'peaks' => $peaks,
        'max_kw' => $max,
        'avg_kw' => $n > 0 ? round($sum / $n, 3) : null,
        'count' => $n,
    ];
}

/**
 * One bar per calendar year: average or max of months that have a value.
 *
 * @param array<string, float> $months
 * @param 'avg'|'max' $agg
 * @return array{categories: list<string>, peaks: list<float>, years: int}
 */
function capacity_peak_all_years_payload(array $months, string $agg): array
{
    $byYear = [];
    foreach ($months as $ym => $kw) {
        $y = substr($ym, 0, 4);
        if ($y === '') {
            continue;
        }
        if (!isset($byYear[$y])) {
            $byYear[$y] = [];
        }
        $byYear[$y][] = $kw;
    }
    ksort($byYear);
    $categories = [];
    $peaks = [];
    foreach ($byYear as $y => $vals) {
        if (count($vals) === 0) {
            continue;
        }
        $categories[] = (string)$y;
        if ($agg === 'avg') {
            $peaks[] = round(array_sum($vals) / count($vals), 3);
        } else {
            $peaks[] = round(max($vals), 3);
        }
    }
    return [
        'categories' => $categories,
        'peaks' => $peaks,
        'years' => count($categories),
    ];
}

/**
 * Highest Capacity peak months (top N). Open month already P1-replaced in $months.
 *
 * @param array<string, float> $months
 * @return list<array{rank: int, period_key: string, peak_kw: float}>
 */
function capacity_peak_top_months(array $months, int $limit = 10): array
{
    $pairs = [];
    foreach ($months as $ym => $kw) {
        $pairs[] = ['period_key' => $ym, 'peak_kw' => $kw];
    }
    usort($pairs, static function (array $a, array $b): int {
        if ($a['peak_kw'] === $b['peak_kw']) {
            return strcmp($a['period_key'], $b['period_key']);
        }
        return $a['peak_kw'] < $b['peak_kw'] ? 1 : -1;
    });
    $pairs = array_slice($pairs, 0, max(0, $limit));
    $out = [];
    $rank = 1;
    foreach ($pairs as $row) {
        $out[] = [
            'rank' => $rank,
            'period_key' => $row['period_key'],
            'peak_kw' => $row['peak_kw'],
        ];
        $rank++;
    }
    return $out;
}
