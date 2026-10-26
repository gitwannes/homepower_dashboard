<?php
# --- file: analytics.php ---
/**
 * Historical exploration: intraday, two-day overlay, clear-sky envelope.
 * Period rollups live on summary.php.
 */
date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/site_settings.php';

$hp = hp_boot();
hp_require_ready($hp);
hp_publish_pac($hp['settings']);
$dbFile = $hp['db'];

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

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    if (!file_exists($dbFile)) {
        echo json_encode(['error' => 'Database not found']);
        exit;
    }

    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        function getEmptyDayTemplate() {
            $slots = [];
            for ($h = 0; $h < 24; $h++) {
                for ($m = 0; $m < 60; $m += 5) {
                    $slots[sprintf('%02d:%02d', $h, $m)] = 0.0;
                }
            }
            return $slots;
        }

        $action = $_GET['ajax'];

        if ($action === 'range') {
            $min = null;
            $max = null;
            try {
                $row = $pdo->query(
                    "SELECT substr(MIN(timestamp), 1, 10) AS dmin, substr(MAX(timestamp), 1, 10) AS dmax
                     FROM history_5min"
                )->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $min = $row['dmin'];
                    $max = $row['dmax'];
                }
            } catch (Exception $e) {
            }
            echo json_encode(['min' => $min, 'max' => $max]);
            exit;
        }

        // On-demand: downsample today's readings -> history_5min (same SQL as rollup -today).
        // Server-side locked to Europe/Brussels "today" only.
        if ($action === 'generate_today') {
            $todayLocal = date('Y-m-d');
            $nowCutSql = "strftime('%Y-%m-%d %H:', datetime('now', 'localtime')) || "
                . "printf('%02d:00', (CAST(strftime('%M', datetime('now', 'localtime')) AS INTEGER) / 5) * 5)";
            try {
                $sql = "
                    INSERT INTO history_5min (
                        timestamp, grid_power_w, import_t1, import_t2, export_t1, export_t2,
                        total_import, total_export, capacity_peak_w, gas, water,
                        solar_power_w, solar_total_kwh, solar_radiation, sample_count, data_source
                    )
                    SELECT
                        strftime('%Y-%m-%d %H:', timestamp) ||
                        printf('%02d:00', (CAST(strftime('%M', timestamp) AS INTEGER) / 5) * 5) AS snapped_ts,
                        ROUND(AVG(active_power)),
                        MAX(import_t1), MAX(import_t2), MAX(export_t1), MAX(export_t2),
                        MAX(total_import), MAX(total_export), MAX(capacity_peak_w),
                        MAX(gas), MAX(water),
                        ROUND(AVG(solar_power)), MAX(solar_total_kwh),
                        AVG(solar_radiation),
                        COUNT(*), 'live_p1'
                    FROM readings
                    WHERE timestamp LIKE ?
                    GROUP BY snapped_ts
                    HAVING snapped_ts < ({$nowCutSql})
                    ON CONFLICT(timestamp) DO UPDATE SET
                        grid_power_w    = excluded.grid_power_w,
                        import_t1       = excluded.import_t1,
                        import_t2       = excluded.import_t2,
                        export_t1       = excluded.export_t1,
                        export_t2       = excluded.export_t2,
                        total_import    = excluded.total_import,
                        total_export    = excluded.total_export,
                        capacity_peak_w = excluded.capacity_peak_w,
                        gas             = excluded.gas,
                        water           = excluded.water,
                        solar_power_w   = CASE
                            WHEN history_5min.data_source = 'webbox_archive'
                                 AND COALESCE(excluded.solar_power_w, 0) = 0
                                 AND COALESCE(history_5min.solar_power_w, 0) > 0
                            THEN history_5min.solar_power_w
                            ELSE COALESCE(excluded.solar_power_w, history_5min.solar_power_w)
                        END,
                        solar_total_kwh = COALESCE(excluded.solar_total_kwh, history_5min.solar_total_kwh),
                        solar_radiation = COALESCE(excluded.solar_radiation, history_5min.solar_radiation),
                        sample_count    = excluded.sample_count,
                        data_source     = CASE
                            WHEN history_5min.data_source = 'webbox_archive' THEN 'merged'
                            ELSE 'live_p1'
                        END
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$todayLocal . '%']);
                $rowcount = $stmt->rowCount();
                // How many 5-min slots for today now have PAC
                $filled = (int)$pdo->query(
                    "SELECT COUNT(*) FROM history_5min
                     WHERE timestamp LIKE " . $pdo->quote($todayLocal . '%') . "
                       AND COALESCE(solar_power_w, 0) > 0"
                )->fetchColumn();
                echo json_encode([
                    'ok' => true,
                    'day' => $todayLocal,
                    'upsert_rowcount' => $rowcount,
                    'slots_with_pac' => $filled,
                ]);
            } catch (Exception $e) {
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit;
        }

        if ($action === 'day' && isset($_GET['date'])) {
            $date = $_GET['date'];
            $slots = getEmptyDayTemplate();
            $stmt = $pdo->prepare(
                "SELECT strftime('%H:%M', timestamp) as time_slot,
                        CAST(strftime('%H', timestamp) AS INTEGER) as hh,
                        solar_power_w, solar_radiation
                 FROM history_5min
                 WHERE timestamp LIKE ?
                 ORDER BY timestamp ASC"
            );
            $stmt->execute([$date . '%']);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (isset($slots[$row['time_slot']])) {
                    $pac = sanitize_pac_w($row['solar_power_w'], (int)$row['hh'], $row['solar_radiation']);
                    $slots[$row['time_slot']] = $pac !== null ? $pac : 0.0;
                }
            }
            echo json_encode([
                'categories' => array_keys($slots),
                'series' => array_values($slots),
            ]);
            exit;
        }

        if ($action === 'compare' && isset($_GET['date1']) && isset($_GET['date2'])) {
            $d1 = $_GET['date1'];
            $d2 = $_GET['date2'];
            $slots1 = getEmptyDayTemplate();
            $slots2 = getEmptyDayTemplate();
            $stmt1 = $pdo->prepare(
                "SELECT strftime('%H:%M', timestamp) as time_slot,
                        CAST(strftime('%H', timestamp) AS INTEGER) as hh,
                        solar_power_w, solar_radiation
                 FROM history_5min WHERE timestamp LIKE ?"
            );
            $stmt1->execute([$d1 . '%']);
            while ($row = $stmt1->fetch(PDO::FETCH_ASSOC)) {
                if (isset($slots1[$row['time_slot']])) {
                    $pac = sanitize_pac_w($row['solar_power_w'], (int)$row['hh'], $row['solar_radiation']);
                    $slots1[$row['time_slot']] = $pac !== null ? $pac : 0.0;
                }
            }
            $stmt2 = $pdo->prepare(
                "SELECT strftime('%H:%M', timestamp) as time_slot,
                        CAST(strftime('%H', timestamp) AS INTEGER) as hh,
                        solar_power_w, solar_radiation
                 FROM history_5min WHERE timestamp LIKE ?"
            );
            $stmt2->execute([$d2 . '%']);
            while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                if (isset($slots2[$row['time_slot']])) {
                    $pac = sanitize_pac_w($row['solar_power_w'], (int)$row['hh'], $row['solar_radiation']);
                    $slots2[$row['time_slot']] = $pac !== null ? $pac : 0.0;
                }
            }
            echo json_encode([
                'categories' => array_keys($slots1),
                'series1' => array_values($slots1),
                'series2' => array_values($slots2),
            ]);
            exit;
        }

        // month=1..12 or month=0 / all = whole-year composite (MAX across months per timepoint)
        if ($action === 'envelope' && isset($_GET['month'])) {
            $monthRaw = strtolower(trim((string)$_GET['month']));
            $wholeYear = ($monthRaw === '0' || $monthRaw === 'all' || $monthRaw === 'year');
            $month = (int)$monthRaw;
            if (!$wholeYear && ($month < 1 || $month > 12)) {
                echo json_encode(['error' => 'Invalid month']);
                exit;
            }
            $categories = [];
            $pac = [];
            $rad = [];
            $pacDates = [];
            for ($tp = 0; $tp < 288; $tp++) {
                $h = (int)floor($tp / 12);
                $mi = ($tp % 12) * 5;
                $categories[] = sprintf('%02d:%02d', $h, $mi);
                $pac[] = 0.0;
                $rad[] = 0.0;
                $pacDates[] = null;
            }
            $filled = 0;
            try {
                if ($wholeYear) {
                    $stmt = $pdo->query(
                        'SELECT timepoint, max_pac_w, max_pac_date, max_rad_wm2, month
                         FROM clear_sky_envelopes ORDER BY timepoint ASC, month ASC'
                    );
                } else {
                    $stmt = $pdo->prepare(
                        'SELECT timepoint, max_pac_w, max_pac_date, max_rad_wm2, month
                         FROM clear_sky_envelopes WHERE month = ? ORDER BY timepoint ASC'
                    );
                    $stmt->execute([$month]);
                }
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $tp = (int)$row['timepoint'];
                    if ($tp < 0 || $tp > 287) {
                        continue;
                    }
                    if ($row['max_pac_w'] !== null) {
                        $v = sanitize_pac_w($row['max_pac_w']);
                        if ($v !== null && $v >= $pac[$tp]) {
                            $pac[$tp] = $v;
                            $pacDates[$tp] = $row['max_pac_date'];
                            if ($wholeYear && !empty($row['month'])) {
                                $pacDates[$tp] = $row['max_pac_date']
                                    ? ($row['max_pac_date'] . ' (m' . (int)$row['month'] . ')')
                                    : ('m' . (int)$row['month']);
                            }
                        }
                        if ($v !== null) {
                            $filled++;
                        }
                    }
                    if ($row['max_rad_wm2'] !== null) {
                        $rv = (float)$row['max_rad_wm2'];
                        if ($rv > $rad[$tp]) {
                            $rad[$tp] = $rv;
                        }
                    }
                }
            } catch (Exception $e) {
                echo json_encode([
                    'error' => 'clear_sky_envelopes missing - run rollup.py -buildall',
                    'detail' => $e->getMessage(),
                ]);
                exit;
            }
            // Soft despike: hard cap alone leaves mid-range corrupt surges (e.g. ~8 kW needles).
            despike_pac_series($pac, $pacDates);

            // Count slots that actually have power (avoid counting zero-filled blanks as filled)
            $nonzero = 0;
            foreach ($pac as $v) {
                if ($v > 0) {
                    $nonzero++;
                }
            }
            echo json_encode([
                'month' => $wholeYear ? 0 : $month,
                'label' => $wholeYear ? 'Whole year' : $month,
                'filled' => $nonzero,
                'categories' => $categories,
                'max_pac_w' => $pac,
                'max_rad_wm2' => $rad,
                'max_pac_date' => $pacDates,
            ]);
            exit;
        }

        echo json_encode(['error' => 'Unknown action']);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$defaultDay = $yesterday;
// Deep link from topn.php: ?date=YYYY-MM-DD
if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])) {
    $defaultDay = (string)$_GET['date'];
}
$defaultCompare1 = $today;
$defaultCompare2 = $yesterday;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historical Analytics | Energy Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        body { background-color: #f8fafc; font-family: system-ui, -apple-system, sans-serif; }
        .card { @apply bg-white p-6 rounded-3xl shadow-sm border border-slate-100 flex flex-col; }
        .jump-btn {
            @apply text-xs font-semibold px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white
                   text-slate-600 hover:bg-slate-50 hover:text-indigo-600 transition-colors cursor-pointer;
        }
        .nav-arrow {
            @apply w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-700
                   flex items-center justify-center hover:bg-slate-50 shadow-sm transition-colors cursor-pointer;
        }
        .date-wrap {
            @apply inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 pl-2 pr-1 py-0.5;
        }
        .date-wrap input[type="text"] {
            @apply text-sm px-1 py-1 bg-transparent border-0 outline-none text-slate-700 font-mono w-[9.5rem];
        }
        .date-icon-btn {
            @apply w-8 h-8 rounded-md text-slate-500 hover:text-indigo-600 hover:bg-white
                   flex items-center justify-center transition-colors cursor-pointer;
        }
        .date-native {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }
    </style>
</head>
<body class="p-4 md:p-8">

<div class="max-w-6xl mx-auto space-y-6">

    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <a href="index.php" class="w-10 h-10 rounded-2xl bg-white border border-slate-200 text-slate-600 flex items-center justify-center hover:bg-slate-50 transition-colors shadow-sm">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-black text-slate-900 tracking-tight">Historical Analytics</h1>
                    <p class="text-xs text-slate-400 mt-0.5">Intraday, day compare, clear-sky envelope</p>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="activity" class="w-3.5 h-3.5 text-emerald-600"></i> Live Dashboard
            </a>
            <a href="summary.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="calendar-range" class="w-3.5 h-3.5 text-emerald-600"></i> Summary
            </a>
            <a href="topn.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-sm transition-all">
                <i data-lucide="trophy" class="w-3.5 h-3.5 text-amber-500"></i> Top-N
            </a>
            <?= hp_nav_settings() ?>
        </div>
    </header>

    <div class="card border-t-4 border-t-amber-500">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                    <i data-lucide="sun" class="w-5 h-5 text-amber-500"></i> Daily Generation Curve
                </h2>
                <p class="text-xs text-slate-400">5-minute profile from history_5min - type a date or open the calendar</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="jump-btn" onclick="goToToday()" title="Jump to today">Today</button>
                <button type="button" id="btnGenerateToday" class="jump-btn hidden"
                        onclick="generateTodayTable()"
                        title="Downsample today's 1-min readings into history_5min">Generate table</button>
                <button type="button" class="nav-arrow" onclick="stepIntraday(-1)" title="Previous day" aria-label="Previous day">
                    <i data-lucide="chevron-left" class="w-5 h-5"></i>
                </button>
                <div class="date-wrap relative">
                    <input type="text" id="singleDateText" inputmode="numeric" placeholder="YYYY-MM-DD" maxlength="10"
                           value="<?= htmlspecialchars($defaultDay) ?>" autocomplete="off" spellcheck="false">
                    <input type="date" id="singleDatePicker" class="date-native" value="<?= htmlspecialchars($defaultDay) ?>" tabindex="-1" aria-hidden="true">
                    <button type="button" class="date-icon-btn" title="Open calendar" aria-label="Open calendar"
                            onclick="openDatePicker('singleDatePicker')">
                        <i data-lucide="calendar" class="w-4 h-4"></i>
                    </button>
                </div>
                <button type="button" class="nav-arrow" onclick="stepIntraday(1)" title="Next day" aria-label="Next day">
                    <i data-lucide="chevron-right" class="w-5 h-5"></i>
                </button>
            </div>
        </div>
        <div id="chart-intraday" class="w-full h-[400px]"></div>
    </div>

    <div class="card border-t-4 border-t-indigo-500">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                    <i data-lucide="layers" class="w-5 h-5 text-indigo-500"></i> Two-Day Overlay
                </h2>
                <p class="text-xs text-slate-400">Defaults: today vs yesterday - type or use the calendar icon</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 bg-slate-50 p-2 rounded-xl border border-slate-100">
                <div class="flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                    <div class="date-wrap relative">
                        <input type="text" id="compareText1" inputmode="numeric" placeholder="YYYY-MM-DD" maxlength="10"
                               value="<?= htmlspecialchars($defaultCompare1) ?>" autocomplete="off" spellcheck="false">
                        <input type="date" id="comparePicker1" class="date-native" value="<?= htmlspecialchars($defaultCompare1) ?>" tabindex="-1" aria-hidden="true">
                        <button type="button" class="date-icon-btn" title="Open calendar" aria-label="Open calendar"
                                onclick="openDatePicker('comparePicker1')">
                            <i data-lucide="calendar" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
                <span class="text-slate-400 text-xs font-bold uppercase">VS</span>
                <div class="flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                    <div class="date-wrap relative">
                        <input type="text" id="compareText2" inputmode="numeric" placeholder="YYYY-MM-DD" maxlength="10"
                               value="<?= htmlspecialchars($defaultCompare2) ?>" autocomplete="off" spellcheck="false">
                        <input type="date" id="comparePicker2" class="date-native" value="<?= htmlspecialchars($defaultCompare2) ?>" tabindex="-1" aria-hidden="true">
                        <button type="button" class="date-icon-btn" title="Open calendar" aria-label="Open calendar"
                                onclick="openDatePicker('comparePicker2')">
                            <i data-lucide="calendar" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div id="chart-compare" class="w-full h-[400px]"></div>
    </div>

    <div class="card border-t-4 border-t-sky-500">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                    <i data-lucide="cloud-sun" class="w-5 h-5 text-sky-500"></i> Clear-sky Envelope
                </h2>
                <p class="text-xs text-slate-400">
                    All-time max AC power at each 5-min slot
                    (<code>clear_sky_envelopes</code>).
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="nav-arrow" onclick="stepEnvelope(-1)" title="Previous" aria-label="Previous period">
                    <i data-lucide="chevron-left" class="w-5 h-5"></i>
                </button>
                <select id="envelopeMonth" class="text-sm px-3 py-1.5 border border-slate-200 rounded-lg text-slate-700 bg-slate-50 min-w-[8rem]"
                        onchange="loadEnvelope()">
                    <option value="0">Whole year</option>
                    <?php
                    $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                                   7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
                    $curM = (int)date('n');
                    foreach ($monthNames as $num => $name) {
                        $sel = ($num === $curM) ? ' selected' : '';
                        echo "<option value=\"{$num}\"{$sel}>{$name}</option>\n";
                    }
                    ?>
                </select>
                <button type="button" class="nav-arrow" onclick="stepEnvelope(1)" title="Next" aria-label="Next period">
                    <i data-lucide="chevron-right" class="w-5 h-5"></i>
                </button>
            </div>
        </div>
        <div id="chart-envelope" class="w-full h-[400px]"></div>
        <div id="envelope-warning" class="hidden text-xs text-amber-600 bg-amber-50 border border-amber-200 p-3 rounded-xl mt-4"></div>
    </div>

</div>

<script>
    lucide.createIcons();

    const TODAY_ISO = <?= json_encode($today) ?>;

    let chartIntraday = null;
    let chartCompare = null;
    let chartEnvelope = null;
    /** @type {{min: string|null, max: string|null}} */
    let dayRange = { min: null, max: null };

    /**
     * Dense 288-point time axes: label whole hours only.
     * Resolve numeric index -> category (Apex sometimes passes the index).
     */
    function hourOnlyLabel(val, opts) {
        let s = val;
        if (typeof val === 'number') {
            const cats = (opts && opts.w && opts.w.config && opts.w.config.xaxis && opts.w.config.xaxis.categories) || [];
            s = cats[val] != null ? cats[val] : '';
        }
        if (typeof s !== 'string') return '';
        return s.endsWith(':00') ? s : '';
    }

    const timeAxisLabels = {
        rotate: 0,
        rotateAlways: false,
        hideOverlappingLabels: true,
        showDuplicates: false,
        trim: false,
        style: { colors: '#94a3b8', fontSize: '10px' },
        formatter: hourOnlyLabel
    };

    /**
     * Custom tooltip: Apex reuses blanked axis labels for the title when hourOnlyLabel
     * returns '' for non-:00 ticks. Always read HH:MM from config.categories.
     */
    function pacTooltip(opts) {
        const cats = (opts.w && opts.w.config && opts.w.config.xaxis && opts.w.config.xaxis.categories) || [];
        const i = opts.dataPointIndex;
        const time = (i != null && cats[i] != null) ? String(cats[i]) : '';
        const series = opts.w.config.series || [];
        const colors = opts.w.globals.colors || [];
        let rows = '';
        for (let s = 0; s < series.length; s++) {
            const raw = opts.series[s][i];
            if (raw == null) continue;
            const name = series[s].name || '';
            const color = colors[s] || '#64748b';
            let extra = '';
            if (opts.pacDates && opts.pacDates[i]) {
                extra = ' (on ' + opts.pacDates[i] + ')';
            }
            rows += '<div class="apexcharts-tooltip-series-group" style="display:flex;align-items:center;gap:6px;padding:2px 0">'
                + '<span style="width:8px;height:8px;border-radius:50%;background:' + color + ';flex-shrink:0"></span>'
                + '<span style="font-size:12px;color:#0f172a"><strong>' + name + ':</strong> '
                + Math.round(Number(raw)) + ' W' + extra + '</span></div>';
        }
        return '<div class="apexcharts-tooltip-title" style="font-family:inherit;font-size:12px;margin-bottom:4px">'
            + (time ? ('Time: ' + time) : '')
            + '</div>' + rows;
    }

    function stepEnvelope(delta) {
        const sel = document.getElementById('envelopeMonth');
        const opts = Array.from(sel.options).map((o) => o.value);
        let idx = opts.indexOf(sel.value);
        if (idx < 0) idx = 0;
        idx = (idx + delta + opts.length) % opts.length;
        sel.value = opts[idx];
        loadEnvelope();
    }

    const baseChartOptions = {
        chart: { toolbar: { show: true }, zoom: { enabled: true }, animations: { enabled: false } },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        xaxis: { type: 'category', labels: timeAxisLabels },
        yaxis: { labels: { style: { colors: '#94a3b8', fontSize: '10px' } } },
        grid: { borderColor: '#f1f5f9', strokeDashArray: 4 }
    };

    function isValidIsoDate(s) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return false;
        return !Number.isNaN(Date.parse(s + 'T12:00:00'));
    }

    function openDatePicker(pickerId) {
        const el = document.getElementById(pickerId);
        if (!el) return;
        try {
            if (typeof el.showPicker === 'function') {
                el.showPicker();
                return;
            }
        } catch (e) {
        }
        el.focus();
        el.click();
    }

    function bindDateField(textId, pickerId, onValid) {
        const text = document.getElementById(textId);
        const picker = document.getElementById(pickerId);
        picker.addEventListener('change', () => {
            text.value = picker.value;
            if (isValidIsoDate(picker.value)) onValid(picker.value);
        });
        ['input', 'change', 'blur'].forEach((ev) => {
            text.addEventListener(ev, () => {
                const v = text.value.trim();
                if (isValidIsoDate(v)) {
                    picker.value = v;
                    onValid(v);
                }
            });
        });
    }

    function addDaysIso(iso, delta) {
        const t = new Date(iso + 'T12:00:00');
        t.setDate(t.getDate() + delta);
        const y = t.getFullYear();
        const m = String(t.getMonth() + 1).padStart(2, '0');
        const d = String(t.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function setIntradayDate(iso, reload) {
        if (!isValidIsoDate(iso)) return;
        document.getElementById('singleDatePicker').value = iso;
        document.getElementById('singleDateText').value = iso;
        updateGenerateTodayBtn();
        if (reload) loadIntraday();
    }

    function goToToday() {
        setIntradayDate(TODAY_ISO, true);
        updateGenerateTodayBtn();
    }

    /** Show Generate table only when the intraday date is today (Brussels). */
    function updateGenerateTodayBtn() {
        const btn = document.getElementById('btnGenerateToday');
        if (!btn) return;
        const d = document.getElementById('singleDateText').value.trim();
        if (d === TODAY_ISO) {
            btn.classList.remove('hidden');
        } else {
            btn.classList.add('hidden');
        }
    }

    /**
     * Downsample today's readings into history_5min, then reload the curve.
     */
    async function generateTodayTable() {
        const btn = document.getElementById('btnGenerateToday');
        if (!btn || btn.disabled) return;
        const cur = document.getElementById('singleDateText').value.trim();
        if (cur !== TODAY_ISO) {
            updateGenerateTodayBtn();
            return;
        }
        const prev = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Generating...';
        try {
            const res = await fetch('analytics.php?ajax=generate_today');
            const data = await res.json();
            if (data.error) {
                alert('Generate failed: ' + data.error);
                return;
            }
            await loadIntraday();
            btn.textContent = 'Updated (' + (data.slots_with_pac || 0) + ' slots)';
            setTimeout(() => { btn.textContent = prev; }, 2500);
        } catch (e) {
            alert('Generate failed: network or server error');
            btn.textContent = prev;
        } finally {
            btn.disabled = false;
            updateGenerateTodayBtn();
        }
    }

    function stepIntraday(delta) {
        const cur = document.getElementById('singleDateText').value.trim();
        if (!isValidIsoDate(cur)) return;
        let next = addDaysIso(cur, delta);
        if (dayRange.min && dayRange.max) {
            if (next < dayRange.min) next = dayRange.max;
            if (next > dayRange.max) next = dayRange.min;
        }
        setIntradayDate(next, true);
    }

    async function loadIntraday() {
        const date = document.getElementById('singleDateText').value.trim();
        if (!isValidIsoDate(date)) return;

        const res = await fetch(`analytics.php?ajax=day&date=${encodeURIComponent(date)}`);
        const data = await res.json();
        if (data.error) return;

        const options = {
            ...baseChartOptions,
            series: [{ name: 'Solar Output (W)', data: data.series }],
            chart: { ...baseChartOptions.chart, type: 'area', height: 400 },
            colors: ['#f59e0b'],
            fill: {
                type: 'gradient',
                gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 100] }
            },
            xaxis: { type: 'category', categories: data.categories, labels: timeAxisLabels },
            tooltip: {
                custom: function (opts) {
                    return pacTooltip(opts);
                }
            }
        };
        if (chartIntraday) {
            chartIntraday.destroy();
            chartIntraday = null;
        }
        const host = document.querySelector('#chart-intraday');
        host.innerHTML = '';
        chartIntraday = new ApexCharts(host, options);
        chartIntraday.render();
    }

    let compareLoadTimer = null;

    async function loadComparison() {
        const host = document.querySelector('#chart-compare');
        const d1 = document.getElementById('compareText1').value.trim();
        const d2 = document.getElementById('compareText2').value.trim();
        if (!isValidIsoDate(d1) || !isValidIsoDate(d2)) return;

        const res = await fetch(
            `analytics.php?ajax=compare&date1=${encodeURIComponent(d1)}&date2=${encodeURIComponent(d2)}`
        );
        const data = await res.json();
        if (data.error) return;

        const options = {
            ...baseChartOptions,
            series: [
                { name: d1, data: data.series1 },
                { name: d2, data: data.series2 }
            ],
            chart: { ...baseChartOptions.chart, type: 'line', height: 400 },
            colors: ['#4f46e5', '#f59e0b'],
            xaxis: { type: 'category', categories: data.categories, labels: timeAxisLabels },
            tooltip: {
                custom: function (opts) {
                    return pacTooltip(opts);
                }
            }
        };
        if (chartCompare) {
            chartCompare.destroy();
            chartCompare = null;
        }
        host.innerHTML = '';
        chartCompare = new ApexCharts(host, options);
        chartCompare.render();
    }

    function scheduleCompareLoad() {
        if (compareLoadTimer) clearTimeout(compareLoadTimer);
        compareLoadTimer = setTimeout(() => { loadComparison(); }, 200);
    }

    let intradayLoadTimer = null;
    function scheduleIntradayLoad() {
        if (intradayLoadTimer) clearTimeout(intradayLoadTimer);
        intradayLoadTimer = setTimeout(() => { loadIntraday(); }, 200);
    }

    async function loadEnvelope() {
        const month = document.getElementById('envelopeMonth').value;
        const warn = document.getElementById('envelope-warning');
        const host = document.querySelector('#chart-envelope');
        warn.classList.add('hidden');
        warn.textContent = '';

        let data;
        try {
            const res = await fetch(`analytics.php?ajax=envelope&month=${encodeURIComponent(month)}`);
            data = await res.json();
        } catch (e) {
            warn.textContent = 'Failed to load envelope data.';
            warn.classList.remove('hidden');
            return;
        }

        if (data.error) {
            warn.textContent = data.error + (data.detail ? ' (' + data.detail + ')' : '');
            warn.classList.remove('hidden');
            if (chartEnvelope) { chartEnvelope.destroy(); chartEnvelope = null; }
            return;
        }
        if (!data.filled) {
            warn.textContent = 'No envelope data for this period in clear_sky_envelopes. Re-run: python3 rollup.py -buildall';
            warn.classList.remove('hidden');
            if (chartEnvelope) { chartEnvelope.destroy(); chartEnvelope = null; }
            return;
        }

        const pacDates = data.max_pac_date || [];
        const seriesName = (data.month === 0) ? 'Whole-year max PAC (W)' : 'Max PAC (W)';
        const options = {
            chart: {
                type: 'area',
                height: 400,
                toolbar: { show: true },
                animations: { enabled: false },
                redrawOnParentResize: true,
                redrawOnWindowResize: true
            },
            series: [{ name: seriesName, data: data.max_pac_w }],
            colors: ['#0ea5e9'],
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2 },
            fill: {
                type: 'gradient',
                gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 100] }
            },
            xaxis: { type: 'category', categories: data.categories, labels: timeAxisLabels },
            yaxis: {
                title: { text: 'PAC (W)', style: { color: '#94a3b8', fontSize: '10px' } },
                labels: { style: { colors: '#94a3b8', fontSize: '10px' } }
            },
            grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
            tooltip: {
                custom: function (opts) {
                    opts.pacDates = pacDates;
                    return pacTooltip(opts);
                }
            }
        };
        if (chartEnvelope) {
            chartEnvelope.destroy();
            chartEnvelope = null;
        }
        host.innerHTML = '';
        chartEnvelope = new ApexCharts(host, options);
        await chartEnvelope.render();
    }

    document.addEventListener('DOMContentLoaded', async () => {
        try {
            const rr = await fetch('analytics.php?ajax=range');
            dayRange = await rr.json();
        } catch (e) {
            dayRange = { min: null, max: null };
        }

        bindDateField('singleDateText', 'singleDatePicker', () => {
            updateGenerateTodayBtn();
            scheduleIntradayLoad();
        });
        bindDateField('compareText1', 'comparePicker1', () => scheduleCompareLoad());
        bindDateField('compareText2', 'comparePicker2', () => scheduleCompareLoad());

        updateGenerateTodayBtn();
        loadIntraday();
        loadComparison();
        loadEnvelope();
        lucide.createIcons();
    });
</script>

</body>
</html>
