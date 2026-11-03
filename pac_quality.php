<?php
# --- file: pac_quality.php ---
/**
 * PAC sanitize + despike helpers shared by analytics explorer and Top-N Virtual best-day.
 */

/**
 * @param mixed $w PAC watts
 * @param int|null $hour 0..23 from timestamp (null = skip night gate)
 * @param mixed $rad solar_radiation W/m2 (null treated as 0 when hour set)
 * @return float|null null if missing, spike, deep night, or shoulder with no sun
 */
function sanitize_pac_w($w, $hour = null, $rad = null)
{
    if ($w === null || $w === '') {
        return null;
    }
    $v = (float)$w;
    $cap = isset($GLOBALS['HP_PAC_CAP']) ? (float)$GLOBALS['HP_PAC_CAP'] : (12000.0 * 1.05);
    if ($v <= 0 || $v > $cap) {
        return null;
    }
    if ($hour !== null) {
        $h = (int)$hour;
        $nightStart = isset($GLOBALS['HP_PAC_NIGHT_START']) ? (int)$GLOBALS['HP_PAC_NIGHT_START'] : 22;
        $nightEnd = isset($GLOBALS['HP_PAC_NIGHT_END']) ? (int)$GLOBALS['HP_PAC_NIGHT_END'] : 5;
        $shoulderStart = isset($GLOBALS['HP_PAC_SHOULDER_START']) ? (int)$GLOBALS['HP_PAC_SHOULDER_START'] : 20;
        $shoulderEnd = isset($GLOBALS['HP_PAC_SHOULDER_END']) ? (int)$GLOBALS['HP_PAC_SHOULDER_END'] : 6;
        $radFloor = isset($GLOBALS['HP_PAC_RAD']) ? (float)$GLOBALS['HP_PAC_RAD'] : 5.0;
        // Deep night: never trust PAC (hour >= start OR hour < end).
        if ($h >= $nightStart || $h < $nightEnd) {
            return null;
        }
        $r = ($rad === null || $rad === '') ? 0.0 : (float)$rad;
        // Shoulder evening/morning: PAC without light.
        if ($r <= $radFloor && ($h >= $shoulderStart || $h < $shoulderEnd)) {
            return null;
        }
    }
    return $v;
}

/**
 * Median of a numeric list (empty -> 0).
 *
 * @param list<float> $vals
 */
function median_float(array $vals): float
{
    $n = count($vals);
    if ($n === 0) {
        return 0.0;
    }
    sort($vals, SORT_NUMERIC);
    $mid = (int)floor($n / 2);
    if ($n % 2 === 1) {
        return (float)$vals[$mid];
    }
    return ((float)$vals[$mid - 1] + (float)$vals[$mid]) / 2.0;
}

/**
 * Drop PAC needles vs local median (not max). Twin adjacent spikes defeat max-of-neighbors;
 * median + a few passes catches them. Mutates $pac / $pacDates in place.
 *
 * @param list<float> $pac
 * @param list<string|null> $pacDates
 */
function despike_pac_series(array &$pac, array &$pacDates, int $radius = 4, float $ratio = 1.15, int $passes = 4): void
{
    $n = count($pac);
    if ($n < 3) {
        return;
    }
    for ($pass = 0; $pass < $passes; $pass++) {
        $cleaned = $pac;
        $changed = false;
        for ($i = 0; $i < $n; $i++) {
            if ($pac[$i] <= 0) {
                continue;
            }
            $lo = max(0, $i - $radius);
            $hi = min($n - 1, $i + $radius);
            $neigh = [];
            for ($j = $lo; $j <= $hi; $j++) {
                if ($j === $i) {
                    continue;
                }
                if ($pac[$j] > 0) {
                    $neigh[] = $pac[$j];
                }
            }
            if (count($neigh) < 2) {
                continue;
            }
            $ref = median_float($neigh);
            if ($ref > 0 && $pac[$i] > $ref * $ratio) {
                $cleaned[$i] = $ref;
                $pacDates[$i] = null;
                $changed = true;
            }
        }
        $pac = $cleaned;
        if (!$changed) {
            break;
        }
    }
}
