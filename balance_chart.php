<?php
# --- file: balance_chart.php ---
/**
 * Energy-balance chart helpers (A5).
 * Modes: balance (default) | classic. No vendor product names in UI strings.
 */

/**
 * Chart view from query. Default balance.
 */
function hp_chart_view(): string
{
    $raw = isset($_GET['view']) ? strtolower(trim((string)$_GET['view'])) : 'balance';
    return $raw === 'classic' ? 'classic' : 'balance';
}

/**
 * Append view=classic when needed (balance is default and may be omitted).
 *
 * @param array<string, scalar> $params
 */
function hp_chart_view_query(array $params, string $view): string
{
    if ($view === 'classic') {
        $params['view'] = 'classic';
    } else {
        unset($params['view']);
    }
    if ($params === []) {
        return '';
    }
    return '?' . http_build_query($params);
}

/**
 * Signed grid net watts from a history_5min-style row.
 * P1 grid_power_w wins. Else Fluvius quarter kWh -> average W (kWh * 4000).
 *
 * @param mixed $gridPowerW
 * @param mixed $importKwh
 * @param mixed $exportKwh
 */
function hp_grid_net_w($gridPowerW, $importKwh, $exportKwh): ?float
{
    if ($gridPowerW !== null && $gridPowerW !== '') {
        return (float)$gridPowerW;
    }
    $hasImp = ($importKwh !== null && $importKwh !== '');
    $hasExp = ($exportKwh !== null && $exportKwh !== '');
    if (!$hasImp && !$hasExp) {
        return null;
    }
    $impW = $hasImp ? ((float)$importKwh * 4000.0) : 0.0;
    $expW = $hasExp ? ((float)$exportKwh * 4000.0) : 0.0;
    return $impW - $expW;
}

/**
 * Balance band watts from solar and signed grid net.
 * Returns null when neither channel has a sample.
 *
 * @return array{
 *   production: float|null,
 *   consumption: float|null,
 *   self_sufficient: float|null,
 *   grid: float|null,
 *   surplus: float|null
 * }|null
 */
function hp_balance_parts(?float $solarW, ?float $gridNetW): ?array
{
    if ($solarW === null && $gridNetW === null) {
        return null;
    }
    $production = max(0.0, (float)($solarW ?? 0.0));
    if ($gridNetW === null) {
        return [
            'production' => $production,
            'consumption' => null,
            'self_sufficient' => null,
            'grid' => null,
            'surplus' => null,
        ];
    }
    $gridNet = (float)$gridNetW;
    $consumption = max(0.0, $production + $gridNet);
    $self = min($production, $consumption);
    $grid = max(0.0, $consumption - $production);
    $surplus = max(0.0, $production - $consumption);
    return [
        'production' => $production,
        'consumption' => $consumption,
        'self_sufficient' => $self,
        'grid' => $grid,
        'surplus' => $surplus,
    ];
}

/**
 * Bridge runs of 1..$maxGap nulls between known values (day-graph Fluvius 1-in-3 rule).
 * Longer runs stay null (chart gap). Linear interpolation.
 *
 * @param list<float|null> $values
 * @return list<float|null>
 */
function hp_bridge_short_nulls(array $values, int $maxGap = 2): array
{
    $n = count($values);
    if ($n < 3 || $maxGap < 1) {
        return $values;
    }
    $out = $values;
    $i = 0;
    while ($i < $n) {
        if ($out[$i] !== null) {
            $i++;
            continue;
        }
        $start = $i;
        while ($i < $n && $out[$i] === null) {
            $i++;
        }
        $gapLen = $i - $start;
        $left = $start - 1;
        $right = $i;
        if (
            $gapLen >= 1
            && $gapLen <= $maxGap
            && $left >= 0
            && $right < $n
            && $out[$left] !== null
            && $out[$right] !== null
        ) {
            $a = (float)$out[$left];
            $b = (float)$out[$right];
            $span = $gapLen + 1;
            for ($k = 1; $k <= $gapLen; $k++) {
                $out[$left + $k] = $a + ($b - $a) * ($k / $span);
            }
        }
    }
    return $out;
}

/**
 * Round or null for JSON chart points.
 *
 * @param float|null $v
 */
function hp_chart_watt(?float $v): ?int
{
    if ($v === null) {
        return null;
    }
    return (int)round($v);
}
