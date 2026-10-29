#!/usr/bin/env python3
# --- file: rollup.py ---
import json
import os
import re
import sys
import sqlite3
import time
from datetime import datetime
from typing import Dict, List, Optional, Tuple

import fluvius_import
import site_config
import sunnyportal_pull

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DB_FILE = site_config.resolve_db_path()

# Daily yield from lifetime E-total: reject implausible meter math, fall back to PAC integral.
# Residential ceiling ~100 kWh/day; single 5-min step above ~3 kWh is not physical generation.
MAX_DAY_YIELD_KWH = 100.0
MAX_ETOTAL_STEP_KWH = 3.0
# Instantaneous PAC spikes (bad WebBox/P1 samples). Home array peaks are a few kW, not 30+ kW.
# Used by clear_sky_envelopes, daily peak, and top_records peak_surge.
MAX_PLAUSIBLE_PAC_W = 12000.0 * 1.05
# Deep night: hour >= start OR hour < end. Shoulder uses the same wrap,
# and only when solar_radiation is at or under PAC_RAD_FLOOR.
PAC_NIGHT_START = 22
PAC_NIGHT_END = 5
PAC_SHOULDER_START = 20
PAC_SHOULDER_END = 6

# Only annotate status lines when a step took longer than this (seconds).
STATUS_TIME_MIN_S = 10.0


def _status_time_suffix(duration_s: float) -> str:
    """Append ' (Ns)' only when elapsed time exceeds STATUS_TIME_MIN_S."""
    if duration_s > STATUS_TIME_MIN_S:
        return f" ({duration_s:.1f}s)"
    return ""


def _status_line(step: int, body: str, duration_s: float) -> None:
    """One-line step status: '-> N: ...'; optional time if > 10s."""
    print(f"-> {step}: {body}{_status_time_suffix(duration_s)}")

# WebBox archive night junk (audit 2026-10-22): non-zero PAC with ~0 radiation after
# a clean sunset zero-gap. Deep night always untrusted; shoulder needs radiation.
PAC_RAD_FLOOR = 5.0  # W/m2


def sql_pac_plausible(pac_expr: str = "COALESCE(solar_power_w, 0)") -> str:
    """SQL predicate: PAC is within cap and not night / no-sun shoulder junk."""
    return f"""(
        ({pac_expr}) > 0
        AND ({pac_expr}) <= {MAX_PLAUSIBLE_PAC_W}
        AND NOT (
            CAST(strftime('%H', timestamp) AS INTEGER) >= {int(PAC_NIGHT_START)}
            OR CAST(strftime('%H', timestamp) AS INTEGER) < {int(PAC_NIGHT_END)}
        )
        AND NOT (
            COALESCE(solar_radiation, 0) <= {float(PAC_RAD_FLOOR)}
            AND (
                CAST(strftime('%H', timestamp) AS INTEGER) >= {int(PAC_SHOULDER_START)}
                OR CAST(strftime('%H', timestamp) AS INTEGER) < {int(PAC_SHOULDER_END)}
            )
        )
    )"""


def init_analytical_db(conn: sqlite3.Connection) -> None:
    """Creates the unified 5-minute table and all downstream aggregation tables."""
    with conn:
        conn.execute("PRAGMA journal_mode = WAL")
        conn.execute("PRAGMA synchronous = NORMAL")

        # 1. The Consolidated 5-Minute Master Table
        conn.execute("""
            CREATE TABLE IF NOT EXISTS history_5min (
                timestamp TEXT PRIMARY KEY,
                grid_power_w INTEGER,
                import_t1 REAL, import_t2 REAL,
                export_t1 REAL, export_t2 REAL,
                total_import REAL, total_export REAL,
                capacity_peak_w INTEGER,
                gas REAL, water REAL,
                solar_power_w REAL,
                solar_power_max_w REAL,
                solar_total_kwh REAL,
                solar_radiation REAL,
                solar_radiation_max REAL,
                temp_module REAL,
                temp_ambient REAL,
                wind_speed REAL,
                wind_gust REAL,
                sample_count INTEGER DEFAULT 1,
                data_source TEXT,
                grid_import_kwh REAL,
                grid_export_kwh REAL
            )
        """)
        # Existing databases were created before the Fluvius quarter columns.
        fluvius_import.ensure_history_grid_columns(conn)
        fluvius_import.ensure_interval_schema(conn)
        sunnyportal_pull.ensure_staging_schema(conn)

        # 2. Daily Rollups
        conn.execute("""
            CREATE TABLE IF NOT EXISTS daily_history (
                date TEXT PRIMARY KEY,
                solar_yield_kwh REAL,
                solar_peak_w REAL,
                sun_hours REAL,
                grid_import_kwh REAL,
                grid_export_kwh REAL,
                self_consumption_kwh REAL,
                temp_ambient_min REAL,
                temp_ambient_max REAL,
                temp_module_max REAL,
                wind_gust_max_ms REAL,
                solar_radiation_max_wm2 REAL
            )
        """)
        # Speeds Hall of Fame / Top Days (ORDER BY solar_yield_kwh DESC LIMIT n).
        conn.execute(
            "CREATE INDEX IF NOT EXISTS idx_daily_yield ON daily_history(solar_yield_kwh DESC)"
        )

        # 3. Monthly Rollups (For PVGIS Target tracking)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS monthly_history (
                year_month TEXT PRIMARY KEY,     -- Format: 'YYYY-MM'
                total_solar_kwh REAL,
                expected_kwh REAL,
                target_pct REAL,                 -- (Actual / Expected) * 100
                total_import_kwh REAL,
                total_export_kwh REAL,
                max_peak_w REAL,
                max_temp_module REAL,
                max_wind_gust REAL
            )
        """)

        # 4. Clear Sky Envelopes (The Theoretical Best Curve)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS clear_sky_envelopes (
                month INTEGER,                   -- 1 to 12
                timepoint INTEGER,               -- 0 to 287 (5-minute slices of a day)
                max_pac_w REAL,
                max_pac_date TEXT,
                max_rad_wm2 REAL,
                PRIMARY KEY (month, timepoint)
            )
        """)

        # 5. All-time singles (A7) — shown on topn.php; rebuilt by -buildall / -buildalltoprecords
        conn.execute("""
            CREATE TABLE IF NOT EXISTS top_records (
                record_id TEXT PRIMARY KEY,
                record_value REAL,
                record_date TEXT,
                description TEXT
            )
        """)

        # 6. Top-N / worst-N period rankings (A4) — solar yield kWh
        conn.execute("""
            CREATE TABLE IF NOT EXISTS top_periods (
                grain TEXT NOT NULL,
                direction TEXT NOT NULL,
                rank INTEGER NOT NULL,
                period_key TEXT NOT NULL,
                yield_kwh REAL NOT NULL,
                PRIMARY KEY (grain, direction, rank)
            )
        """)
        conn.execute(
            "CREATE INDEX IF NOT EXISTS idx_top_periods_lookup "
            "ON top_periods(grain, direction, rank)"
        )


def _table_exists(conn: sqlite3.Connection, name: str) -> bool:
    """True when sqlite_master has a table with this name."""
    row = conn.execute(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
        (name,),
    ).fetchone()
    return row is not None


def _ensure_consolidate_columns(conn: sqlite3.Connection) -> None:
    """Add columns consolidate reads. Same ALTER as importer.py on older logger schemas."""
    if _table_exists(conn, 'inverter_history_5min'):
        try:
            conn.execute("ALTER TABLE inverter_history_5min ADD COLUMN pac_max_w REAL")
        except sqlite3.OperationalError:
            pass
    if _table_exists(conn, 'sensorbox_history_5min'):
        try:
            conn.execute("ALTER TABLE sensorbox_history_5min ADD COLUMN wind_gust_ms REAL")
        except sqlite3.OperationalError:
            pass


def consolidate_historical_webbox(conn: sqlite3.Connection, step: int = 1) -> None:
    """Snaps and merges raw sensorbox and inverter tables into history_5min.

    A missing staging table is skipped. WebBox data lives only in those tables;
    an absent table means that source was never created, not that rows were lost.
    """
    start_time = time.time()
    _ensure_consolidate_columns(conn)
    missing = []

    with conn:
        if _table_exists(conn, 'inverter_history_5min'):
            conn.execute("""
            INSERT INTO history_5min (
                timestamp, solar_power_w, solar_power_max_w, solar_total_kwh, data_source
            )
            SELECT 
                strftime('%Y-%m-%d %H:', timestamp) || 
                printf('%02d:00', (CAST(strftime('%M', timestamp) AS INTEGER) / 5) * 5) AS snapped_ts,
                SUM(pac_w),
                SUM(pac_max_w),
                SUM(etotal_kwh),
                'webbox_archive'
            FROM inverter_history_5min
            GROUP BY snapped_ts
            ON CONFLICT(timestamp) DO UPDATE SET
                solar_power_w = COALESCE(excluded.solar_power_w, history_5min.solar_power_w),
                solar_power_max_w = COALESCE(excluded.solar_power_max_w, history_5min.solar_power_max_w),
                solar_total_kwh = COALESCE(excluded.solar_total_kwh, history_5min.solar_total_kwh),
                data_source = CASE WHEN history_5min.data_source = 'live_p1' THEN 'merged' ELSE 'webbox_archive' END;
        """)
        else:
            missing.append('inverter_history_5min')

        if _table_exists(conn, 'sensorbox_history_5min'):
            conn.execute("""
            INSERT INTO history_5min (
                timestamp, solar_radiation, solar_radiation_max, 
                temp_module, temp_ambient, wind_speed, wind_gust, data_source
            )
            SELECT 
                strftime('%Y-%m-%d %H:', timestamp) || 
                printf('%02d:00', (CAST(strftime('%M', timestamp) AS INTEGER) / 5) * 5) AS snapped_ts,
                AVG(radiation_wm2), MAX(radiation_wm2),
                AVG(temp_module_c), AVG(temp_ambient_c),
                AVG(wind_speed_ms), MAX(wind_gust_ms),
                'webbox_archive'
            FROM sensorbox_history_5min
            GROUP BY snapped_ts
            ON CONFLICT(timestamp) DO UPDATE SET
                solar_radiation = COALESCE(excluded.solar_radiation, history_5min.solar_radiation),
                solar_radiation_max = COALESCE(excluded.solar_radiation_max, history_5min.solar_radiation_max),
                temp_module = COALESCE(excluded.temp_module, history_5min.temp_module),
                temp_ambient = COALESCE(excluded.temp_ambient, history_5min.temp_ambient),
                wind_speed = COALESCE(excluded.wind_speed, history_5min.wind_speed),
                wind_gust = COALESCE(excluded.wind_gust, history_5min.wind_gust),
                data_source = CASE WHEN history_5min.data_source = 'live_p1' THEN 'merged' ELSE 'webbox_archive' END;
        """)
        else:
            missing.append('sensorbox_history_5min')

    duration = time.time() - start_time
    count = conn.execute("SELECT COUNT(*) FROM history_5min").fetchone()[0]
    missing_txt = f"; missing {', '.join(missing)}" if missing else ""
    _status_line(
        step,
        f"history_5min: consolidate staging; table total {count:,} rows{missing_txt}",
        duration,
    )


def build_daily_history(
    conn: sqlite3.Connection,
    days_back: Optional[int] = None,
    step: int = 2,
) -> None:
    """Crunches 5-minute data into daily yields and weather extremes.

    Solar yield preference:
      1) E-total at last ts minus E-total at first ts of the day, if
         0 <= delta <= MAX_DAY_YIELD_KWH and no 5-min step is outside
         [-0.01, MAX_ETOTAL_STEP_KWH] (rejects spikes/resets/counter mixes).
      2) Else PAC integral: SUM(solar_power_w) * (5/60) / 1000.
    """
    where_clause = ""
    if days_back is not None:
        # Local calendar window. datetime('now') is UTC and drops the Brussels evening.
        days = int(days_back)
        if days < 1:
            raise ValueError(f"days_back must be >= 1, got {days_back}")
        where_clause = f"WHERE timestamp >= datetime('now', 'localtime', '-{days} days')"

    start_time = time.time()
    # Fall-out days take Fluvius kWh. The open local day stays on the counter delta.
    if days_back is None:
        fluvius_import.prepare_day_overrides(conn, None, None)
    else:
        day_lo, day_hi = fluvius_import.local_span(conn, int(days_back))
        fluvius_import.prepare_day_overrides(conn, day_lo, day_hi)
    with conn:
        conn.execute(f"""
            INSERT OR REPLACE INTO daily_history (
                date, solar_yield_kwh, solar_peak_w, sun_hours,
                grid_import_kwh, grid_export_kwh, self_consumption_kwh,
                temp_ambient_min, temp_ambient_max, temp_module_max,
                wind_gust_max_ms, solar_radiation_max_wm2
            )
            WITH base AS (
                SELECT
                    substr(timestamp, 1, 10) AS dt,
                    timestamp,
                    solar_total_kwh,
                    solar_power_w,
                    solar_power_max_w,
                    total_import,
                    total_export,
                    temp_ambient,
                    temp_module,
                    wind_gust,
                    wind_speed,
                    solar_radiation,
                    solar_radiation_max,
                    LAG(solar_total_kwh) OVER (
                        PARTITION BY substr(timestamp, 1, 10) ORDER BY timestamp
                    ) AS prev_etotal
                FROM history_5min
                {where_clause}
            ),
            step_flags AS (
                SELECT
                    dt,
                    MAX(CASE
                        WHEN prev_etotal IS NOT NULL AND solar_total_kwh IS NOT NULL
                             AND (solar_total_kwh - prev_etotal) > {MAX_ETOTAL_STEP_KWH}
                        THEN 1
                        WHEN prev_etotal IS NOT NULL AND solar_total_kwh IS NOT NULL
                             AND (solar_total_kwh - prev_etotal) < -0.01
                        THEN 1
                        ELSE 0
                    END) AS bad_etotal_steps
                FROM base
                GROUP BY dt
            ),
            bounds AS (
                SELECT
                    dt,
                    MIN(timestamp) AS ts_first,
                    MAX(timestamp) AS ts_last,
                    SUM(CASE
                        WHEN {sql_pac_plausible("COALESCE(solar_power_w, 0)")}
                        THEN COALESCE(solar_power_w, 0) ELSE 0
                    END) * (5.0 / 60.0) / 1000.0 AS power_integral_kwh,
                    MAX(CASE
                        WHEN {sql_pac_plausible("COALESCE(solar_power_max_w, solar_power_w, 0)")}
                        THEN COALESCE(solar_power_max_w, solar_power_w, 0)
                    END) AS peak_w,
                    ROUND(COUNT(CASE
                        WHEN {sql_pac_plausible("COALESCE(solar_power_w, 0)")}
                         AND solar_power_w > 50 THEN 1
                    END) * (5.0 / 60.0), 2) AS sun_h,
                    ROUND(MAX(total_import) - MIN(total_import), 3) AS import_kwh,
                    ROUND(MAX(total_export) - MIN(total_export), 3) AS export_kwh,
                    MIN(temp_ambient) AS t_amb_min,
                    MAX(temp_ambient) AS t_amb_max,
                    MAX(temp_module) AS t_mod_max,
                    MAX(COALESCE(wind_gust, wind_speed)) AS wind_max,
                    MAX(COALESCE(solar_radiation_max, solar_radiation)) AS rad_max
                FROM base
                GROUP BY dt
            ),
            et_ends AS (
                SELECT
                    b.dt,
                    b.ts_first,
                    b.ts_last,
                    b.power_integral_kwh,
                    b.peak_w,
                    b.sun_h,
                    b.import_kwh,
                    b.export_kwh,
                    b.t_amb_min,
                    b.t_amb_max,
                    b.t_mod_max,
                    b.wind_max,
                    b.rad_max,
                    f.solar_total_kwh AS et_first,
                    l.solar_total_kwh AS et_last,
                    COALESCE(sf.bad_etotal_steps, 0) AS bad_etotal_steps
                FROM bounds b
                LEFT JOIN step_flags sf ON sf.dt = b.dt
                LEFT JOIN base f ON f.dt = b.dt AND f.timestamp = b.ts_first
                LEFT JOIN base l ON l.dt = b.dt AND l.timestamp = b.ts_last
            )
            SELECT
                dt,
                ROUND(
                    CASE
                        WHEN et_first IS NOT NULL AND et_last IS NOT NULL
                             AND bad_etotal_steps = 0
                             AND (et_last - et_first) >= 0
                             AND (et_last - et_first) <= {MAX_DAY_YIELD_KWH}
                        THEN et_last - et_first
                        ELSE power_integral_kwh
                    END
                , 2) AS yield_kwh,
                peak_w,
                sun_h,
                CASE WHEN ovr.date IS NOT NULL THEN ovr.grid_import_kwh ELSE import_kwh END,
                CASE WHEN ovr.date IS NOT NULL THEN ovr.grid_export_kwh ELSE export_kwh END,
                ROUND(
                    CASE
                        WHEN et_first IS NOT NULL AND et_last IS NOT NULL
                             AND bad_etotal_steps = 0
                             AND (et_last - et_first) >= 0
                             AND (et_last - et_first) <= {MAX_DAY_YIELD_KWH}
                             AND (CASE WHEN ovr.date IS NOT NULL THEN ovr.grid_export_kwh ELSE export_kwh END) IS NOT NULL
                        THEN MAX(0.0, (et_last - et_first) - COALESCE(
                            CASE WHEN ovr.date IS NOT NULL THEN ovr.grid_export_kwh ELSE export_kwh END, 0))
                        ELSE NULL
                    END
                , 2) AS self_consumption_kwh,
                t_amb_min,
                t_amb_max,
                t_mod_max,
                wind_max,
                rad_max
            FROM et_ends
            LEFT JOIN fluvius_day_override ovr ON ovr.date = et_ends.dt;
        """)
        fluvius_import.insert_missing_override_days(conn)

    duration = time.time() - start_time
    count = conn.execute("SELECT COUNT(*) FROM daily_history").fetchone()[0]
    if days_back:
        body = (
            f"daily_history: upserted last {days_back} days; "
            f"table total {count:,} days"
        )
    else:
        body = f"daily_history: full rebuild; table total {count:,} days"
    _status_line(step, body, duration)


def build_monthly_history(conn: sqlite3.Connection, step: int = 3) -> None:
    """Rolls up daily totals into months and tracks against PVGIS expected baselines."""
    start_time = time.time()
    
    with conn:
        conn.execute("""
            INSERT OR REPLACE INTO monthly_history (
                year_month, total_solar_kwh, expected_kwh, target_pct,
                total_import_kwh, total_export_kwh,
                max_peak_w, max_temp_module, max_wind_gust
            )
            SELECT 
                substr(d.date, 1, 7) AS ym,
                ROUND(SUM(d.solar_yield_kwh), 1),
                COALESCE(exp.expected_kwh, 0.0),
                CASE WHEN exp.expected_kwh > 0 THEN ROUND((SUM(d.solar_yield_kwh) / exp.expected_kwh) * 100, 1) ELSE 0 END,
                ROUND(SUM(d.grid_import_kwh), 1),
                ROUND(SUM(d.grid_export_kwh), 1),
                MAX(d.solar_peak_w), MAX(d.temp_module_max), MAX(d.wind_gust_max_ms)
            FROM daily_history d
            LEFT JOIN expected_monthly exp ON exp.month = CAST(substr(d.date, 6, 2) AS INTEGER) AND exp.inclination = 30
            GROUP BY ym;
        """)

    duration = time.time() - start_time
    count = conn.execute("SELECT COUNT(*) FROM monthly_history").fetchone()[0]
    _status_line(
        step,
        f"monthly_history: full rebuild; table total {count:,} months",
        duration,
    )


def build_clear_sky_envelopes(conn: sqlite3.Connection, step: int = 4) -> None:
    """Finds the all-time highest plausible AC power for every 5-min slice (288) per month.

    Uses interval mean solar_power_w (not solar_power_max_w surge). Ignores samples
    above MAX_PLAUSIBLE_PAC_W and night / no-radiation shoulder junk (sql_pac_plausible).
    """
    start_time = time.time()
    pac_ok = sql_pac_plausible("COALESCE(solar_power_w, 0)")

    with conn:
        conn.execute("DELETE FROM clear_sky_envelopes;")
        conn.execute(f"""
            INSERT INTO clear_sky_envelopes (month, timepoint, max_pac_w, max_pac_date, max_rad_wm2)
            SELECT
                CAST(substr(timestamp, 6, 2) AS INTEGER) AS mth,
                (CAST(substr(timestamp, 12, 2) AS INTEGER) * 12)
                    + (CAST(substr(timestamp, 15, 2) AS INTEGER) / 5) AS tp,
                MAX(pac),
                substr(MAX(printf('%09d', pac) || timestamp), 10, 10),
                MAX(COALESCE(solar_radiation_max, solar_radiation, 0))
            FROM (
                SELECT
                    timestamp,
                    solar_radiation,
                    solar_radiation_max,
                    COALESCE(solar_power_w, 0) AS pac
                FROM history_5min
                WHERE {pac_ok}
            )
            GROUP BY mth, tp;
        """)

    duration = time.time() - start_time
    count = conn.execute("SELECT COUNT(*) FROM clear_sky_envelopes").fetchone()[0]
    _status_line(
        step,
        f"clear_sky_envelopes: full rebuild; table total {count:,} timepoints",
        duration,
    )


# All-time singles on topn.php (A7 / A12). direction: max = higher wins, min = lower wins.
# kind daily = daily_history column; peak = history_5min PAC surge;
# year_solar = sum of monthly/daily solar by calendar year;
# fluvius_month_peak / fluvius_year_avg_peak = Capacity peak from fluvius_interval (+ live P1 open month).
TOP_RECORD_DEFS: Tuple[Tuple[str, str, str, str, Optional[str]], ...] = (
    ("best_day_kwh", "max", "All-time highest daily generation (kWh)", "daily", "solar_yield_kwh"),
    ("peak_surge_w", "max", "All-time instantaneous solar AC surge peak (W)", "peak", None),
    ("max_sun_hours", "max", "Most sun-hours in a single day", "daily", "sun_hours"),
    (
        "best_self_consumption_kwh",
        "max",
        "Highest daily self-consumption (kWh)",
        "daily",
        "self_consumption_kwh",
    ),
    (
        "best_year_kwh",
        "max",
        "All-time highest year generation (kWh)",
        "year_solar",
        None,
    ),
    ("max_wind_ms", "max", "Maximum recorded wind gust (m/s)", "daily", "wind_gust_max_ms"),
    ("max_temp_c", "max", "Highest recorded ambient temperature (C)", "daily", "temp_ambient_max"),
    ("min_temp_c", "min", "Lowest recorded ambient temperature (C)", "daily", "temp_ambient_min"),
    ("max_module_temp_c", "max", "Highest recorded module temperature (C)", "daily", "temp_module_max"),
    (
        "max_capacity_peak_kw",
        "max",
        "Highest Capacity peak month (kW)",
        "fluvius_month_peak",
        None,
    ),
    (
        "best_year_avg_capacity_peak_kw",
        "max",
        "Year with highest average Capacity peak (kW)",
        "fluvius_year_avg_peak",
        None,
    ),
)


def _top_record_stricter(direction: str, candidate: float, current: Optional[float]) -> bool:
    """True when candidate should replace current (or when there is no current row)."""
    if current is None:
        return True
    if direction == "min":
        return candidate < current
    return candidate > current


def _peak_surge_candidate(
    conn: sqlite3.Connection,
    day_lo: Optional[str] = None,
    day_hi: Optional[str] = None,
) -> Optional[Tuple[str, float]]:
    """Best PAC surge day + watts; optional inclusive calendar-day window on timestamp date."""
    pac_expr = "COALESCE(solar_power_max_w, solar_power_w)"
    where = sql_pac_plausible(f"COALESCE(solar_power_max_w, solar_power_w, 0)")
    params: Tuple[object, ...] = ()
    if day_lo is not None and day_hi is not None:
        where = f"{where} AND substr(timestamp, 1, 10) >= ? AND substr(timestamp, 1, 10) <= ?"
        params = (day_lo, day_hi)
    row = conn.execute(
        f"""
        SELECT substr(timestamp, 1, 10) AS d, {pac_expr} AS val
        FROM history_5min
        WHERE {where}
        ORDER BY val DESC
        LIMIT 1
        """,
        params,
    ).fetchone()
    if not row or row[1] is None:
        return None
    return (str(row[0]), float(row[1]))


def _daily_extreme_candidate(
    conn: sqlite3.Connection,
    column: str,
    direction: str,
    day_lo: Optional[str] = None,
    day_hi: Optional[str] = None,
) -> Optional[Tuple[str, float]]:
    """Best day for one daily_history column; optional inclusive date window."""
    order = "ASC" if direction == "min" else "DESC"
    where = f"{column} IS NOT NULL"
    params: Tuple[object, ...] = ()
    if day_lo is not None and day_hi is not None:
        where = f"{where} AND date >= ? AND date <= ?"
        params = (day_lo, day_hi)
    row = conn.execute(
        f"""
        SELECT date, {column} AS val
        FROM daily_history
        WHERE {where}
        ORDER BY val {order}, date ASC
        LIMIT 1
        """,
        params,
    ).fetchone()
    if not row or row[1] is None:
        return None
    return (str(row[0]), float(row[1]))


def _electricity_ean(conn: sqlite3.Connection) -> str:
    """Digits-only Fluvius electricity EAN from Settings."""
    settings = site_config.load_settings(conn)
    return re.sub(r"\D", "", settings.get("fluvius_ean_electricity", "") or "")


def _live_capacity_peak_kw(conn: sqlite3.Connection) -> Optional[Tuple[str, float]]:
    """Open-month live P1 capacity peak as (YYYY-MM, kW), or None."""
    row = conn.execute(
        "SELECT data_json FROM latest_state WHERE id = 1"
    ).fetchone()
    if not row or not row[0]:
        return None
    try:
        state = json.loads(str(row[0]))
    except (TypeError, ValueError):
        return None
    if not isinstance(state, dict):
        return None
    p1 = state.get("p1_data")
    if not isinstance(p1, dict):
        return None
    watts = p1.get("montly_power_peak_w")
    if watts is None:
        return None
    try:
        w = float(watts)
    except (TypeError, ValueError):
        return None
    if w <= 0:
        return None
    return (datetime.now().strftime("%Y-%m"), round(w / 1000.0, 3))


def _capacity_peak_months_kw(conn: sqlite3.Connection) -> Dict[str, float]:
    """Fluvius piekvermogen months (kW), open month replaced by live P1 when present."""
    ean = _electricity_ean(conn)
    by_month: Dict[str, float] = {}
    if ean:
        try:
            rows = conn.execute(
                """
                SELECT substr(interval_start, 1, 7) AS ym, MAX(volume) AS peak_kw
                FROM fluvius_interval
                WHERE ean = ?
                  AND register = 'piekvermogen'
                  AND resolution = 'month'
                GROUP BY ym
                """,
                (ean,),
            ).fetchall()
        except sqlite3.Error:
            rows = []
        for ym, peak_kw in rows:
            if ym and peak_kw is not None:
                by_month[str(ym)] = round(float(peak_kw), 3)
    live = _live_capacity_peak_kw(conn)
    if live is not None:
        by_month[live[0]] = live[1]
    return by_month


def _year_solar_candidate(
    conn: sqlite3.Connection,
    day_lo: Optional[str] = None,
    day_hi: Optional[str] = None,
) -> Optional[Tuple[str, float]]:
    """Calendar year with highest solar kWh. record_date is YYYY-01-01. Window filters months."""
    where = "total_solar_kwh IS NOT NULL"
    params: Tuple[object, ...] = ()
    if day_lo is not None and day_hi is not None:
        # Beat path: only years touched by the stitch span can improve.
        where = (
            f"{where} AND substr(year_month, 1, 4) >= ? AND substr(year_month, 1, 4) <= ?"
        )
        params = (day_lo[:4], day_hi[:4])
    row = conn.execute(
        f"""
        SELECT substr(year_month, 1, 4) AS y, SUM(total_solar_kwh) AS tot
        FROM monthly_history
        WHERE {where}
        GROUP BY y
        ORDER BY tot DESC, y ASC
        LIMIT 1
        """,
        params,
    ).fetchone()
    if row and row[1] is not None:
        return (f"{row[0]}-01-01", round(float(row[1]), 1))
    # Fallback: daily_history when monthly is empty
    where_d = "solar_yield_kwh IS NOT NULL"
    params_d: Tuple[object, ...] = ()
    if day_lo is not None and day_hi is not None:
        where_d = f"{where_d} AND substr(date, 1, 4) >= ? AND substr(date, 1, 4) <= ?"
        params_d = (day_lo[:4], day_hi[:4])
    row = conn.execute(
        f"""
        SELECT substr(date, 1, 4) AS y, SUM(solar_yield_kwh) AS tot
        FROM daily_history
        WHERE {where_d}
        GROUP BY y
        ORDER BY tot DESC, y ASC
        LIMIT 1
        """,
        params_d,
    ).fetchone()
    if not row or row[1] is None:
        return None
    return (f"{row[0]}-01-01", round(float(row[1]), 1))


def _fluvius_month_peak_candidate(
    conn: sqlite3.Connection,
    day_lo: Optional[str] = None,
    day_hi: Optional[str] = None,
) -> Optional[Tuple[str, float]]:
    """Highest Capacity peak month. record_date is YYYY-MM-01."""
    months = _capacity_peak_months_kw(conn)
    if day_lo is not None and day_hi is not None:
        lo_ym = day_lo[:7]
        hi_ym = day_hi[:7]
        months = {ym: kw for ym, kw in months.items() if lo_ym <= ym <= hi_ym}
    if not months:
        return None
    best_ym = sorted(months.keys(), key=lambda ym: (-months[ym], ym))[0]
    return (f"{best_ym}-01", months[best_ym])


def _fluvius_year_avg_peak_candidate(
    conn: sqlite3.Connection,
    day_lo: Optional[str] = None,
    day_hi: Optional[str] = None,
) -> Optional[Tuple[str, float]]:
    """Year whose monthly Capacity peaks have the highest average. record_date YYYY-01-01."""
    months = _capacity_peak_months_kw(conn)
    if day_lo is not None and day_hi is not None:
        lo_y = day_lo[:4]
        hi_y = day_hi[:4]
        months = {ym: kw for ym, kw in months.items() if lo_y <= ym[:4] <= hi_y}
    by_year: Dict[str, List[float]] = {}
    for ym, kw in months.items():
        y = ym[:4]
        by_year.setdefault(y, []).append(kw)
    if not by_year:
        return None
    ranked = sorted(
        by_year.keys(),
        key=lambda y: (-(sum(by_year[y]) / len(by_year[y])), y),
    )
    best_y = ranked[0]
    avg = round(sum(by_year[best_y]) / len(by_year[best_y]), 3)
    return (f"{best_y}-01-01", avg)


def _top_record_candidate(
    conn: sqlite3.Connection,
    record_id: str,
    direction: str,
    kind: str,
    column: Optional[str],
    day_lo: Optional[str] = None,
    day_hi: Optional[str] = None,
) -> Optional[Tuple[str, float]]:
    """Resolve one extreme; window None = all history."""
    if kind == "peak":
        return _peak_surge_candidate(conn, day_lo, day_hi)
    if kind == "daily" and column:
        return _daily_extreme_candidate(conn, column, direction, day_lo, day_hi)
    if kind == "year_solar":
        return _year_solar_candidate(conn, day_lo, day_hi)
    if kind == "fluvius_month_peak":
        return _fluvius_month_peak_candidate(conn, day_lo, day_hi)
    if kind == "fluvius_year_avg_peak":
        return _fluvius_year_avg_peak_candidate(conn, day_lo, day_hi)
    return None


def build_top_records(conn: sqlite3.Connection, step: int = 5) -> None:
    """Full rewrite of all-time singles from daily_history / history_5min."""
    start_time = time.time()
    inserted = 0

    with conn:
        conn.execute("DELETE FROM top_records;")
        for record_id, direction, desc, kind, column in TOP_RECORD_DEFS:
            cand = _top_record_candidate(conn, record_id, direction, kind, column)
            if cand is None:
                continue
            record_date, record_value = cand
            conn.execute(
                "INSERT INTO top_records (record_id, record_value, record_date, description) "
                "VALUES (?, ?, ?, ?)",
                (record_id, record_value, record_date, desc),
            )
            inserted += 1

    duration = time.time() - start_time
    _status_line(step, f"top_records: full rewrite; {inserted} rows", duration)


def refresh_top_records_from_recent(
    conn: sqlite3.Connection,
    day_lo: str,
    day_hi: str,
    step: int = 5,
) -> None:
    """
    Beat path for -daily: replace a top_records row only when a candidate in
    [day_lo, day_hi] is a stricter extreme than the stored value.
    """
    start_time = time.time()
    replaced = 0
    checked = 0

    with conn:
        for record_id, direction, desc, kind, column in TOP_RECORD_DEFS:
            cand = _top_record_candidate(
                conn, record_id, direction, kind, column, day_lo, day_hi
            )
            checked += 1
            if cand is None:
                continue
            record_date, record_value = cand
            existing = conn.execute(
                "SELECT record_value FROM top_records WHERE record_id = ?",
                (record_id,),
            ).fetchone()
            current_val: Optional[float] = (
                float(existing[0]) if existing and existing[0] is not None else None
            )
            if not _top_record_stricter(direction, record_value, current_val):
                continue
            conn.execute(
                """
                INSERT INTO top_records (record_id, record_value, record_date, description)
                VALUES (?, ?, ?, ?)
                ON CONFLICT(record_id) DO UPDATE SET
                    record_value = excluded.record_value,
                    record_date = excluded.record_date,
                    description = excluded.description
                """,
                (record_id, record_value, record_date, desc),
            )
            replaced += 1

    duration = time.time() - start_time
    _status_line(
        step,
        f"top_records: beat check {day_lo}..{day_hi}; replaced {replaced}/{checked}",
        duration,
    )


def build_top_periods(conn: sqlite3.Connection, step: int = 6) -> None:
    """Rebuild Top-N / worst-N yield rankings (A4): days 20, months/years 10 each."""
    start_time = time.time()
    limits = {"day": 20, "month": 10, "year": 10}

    def _insert_list(
        grain: str,
        direction: str,
        rows: list,
    ) -> None:
        limit = limits[grain]
        for i, (period_key, yield_kwh) in enumerate(rows[:limit], start=1):
            if period_key is None or yield_kwh is None:
                continue
            conn.execute(
                "INSERT INTO top_periods (grain, direction, rank, period_key, yield_kwh) "
                "VALUES (?, ?, ?, ?, ?)",
                (grain, direction, i, str(period_key), float(yield_kwh)),
            )

    with conn:
        conn.execute("DELETE FROM top_periods;")

        best_days = conn.execute(
            "SELECT date, solar_yield_kwh FROM daily_history "
            "WHERE solar_yield_kwh IS NOT NULL "
            "ORDER BY solar_yield_kwh DESC, date ASC LIMIT ?",
            (limits["day"],),
        ).fetchall()
        # Worst days: some production, not today (partial day would unfairly rank as worst).
        today_iso = datetime.now().strftime('%Y-%m-%d')
        worst_days = conn.execute(
            "SELECT date, solar_yield_kwh FROM daily_history "
            "WHERE solar_yield_kwh IS NOT NULL AND solar_yield_kwh > 0 "
            "AND date <> ? "
            "ORDER BY solar_yield_kwh ASC, date ASC LIMIT ?",
            (today_iso, limits["day"]),
        ).fetchall()
        _insert_list("day", "best", best_days)
        _insert_list("day", "worst", worst_days)

        best_months = conn.execute(
            "SELECT year_month, total_solar_kwh FROM monthly_history "
            "WHERE total_solar_kwh IS NOT NULL "
            "ORDER BY total_solar_kwh DESC, year_month ASC LIMIT ?",
            (limits["month"],),
        ).fetchall()
        worst_months = conn.execute(
            "SELECT year_month, total_solar_kwh FROM monthly_history "
            "WHERE total_solar_kwh IS NOT NULL "
            "ORDER BY total_solar_kwh ASC, year_month ASC LIMIT ?",
            (limits["month"],),
        ).fetchall()
        _insert_list("month", "best", best_months)
        _insert_list("month", "worst", worst_months)

        # Prefer monthly_history year sums; fall back to daily_history
        year_rows = conn.execute(
            "SELECT substr(year_month, 1, 4) AS y, SUM(total_solar_kwh) AS tot "
            "FROM monthly_history "
            "WHERE total_solar_kwh IS NOT NULL "
            "GROUP BY y"
        ).fetchall()
        if not year_rows:
            year_rows = conn.execute(
                "SELECT substr(date, 1, 4) AS y, SUM(solar_yield_kwh) AS tot "
                "FROM daily_history "
                "WHERE solar_yield_kwh IS NOT NULL "
                "GROUP BY y"
            ).fetchall()
        year_sorted_best = sorted(
            [(r[0], r[1]) for r in year_rows if r[0] and r[1] is not None],
            key=lambda t: (-float(t[1]), str(t[0])),
        )
        year_sorted_worst = sorted(
            [(r[0], r[1]) for r in year_rows if r[0] and r[1] is not None],
            key=lambda t: (float(t[1]), str(t[0])),
        )
        _insert_list("year", "best", year_sorted_best)
        _insert_list("year", "worst", year_sorted_worst)

    count = conn.execute("SELECT COUNT(*) FROM top_periods").fetchone()[0]
    duration = time.time() - start_time
    _status_line(step, f"top_periods: {count:,} rows", duration)


def downsample_live_readings(
    conn: sqlite3.Connection,
    day_iso: Optional[str] = None,
    step: int = 1,
    all_rows: bool = False,
) -> int:
    """Downsample 1-minute readings into history_5min.

    day_iso: optional YYYY-MM-DD (local calendar day).
    all_rows: every readings row. Mutually exclusive with day_iso.
    Default (both unset): last 2 local days.

    The open 5-minute slice (the bucket that contains local now) is always
    excluded. Its average would stand in for a full five minutes.

    logger.py stores timestamps with datetime.now() (host local / CET).
    SQLite datetime('now') is UTC - always use datetime('now', 'localtime') for cutoffs.
    """
    where_params: Tuple[str, ...]
    if day_iso is not None and all_rows:
        raise ValueError("day_iso and all_rows are mutually exclusive")
    if day_iso is not None:
        if not re.match(r'^\d{4}-\d{2}-\d{2}$', day_iso):
            raise ValueError(f"Invalid day_iso: {day_iso}")
        where_sql = "timestamp LIKE ?"
        where_params = (day_iso + '%',)
        scope_label = day_iso
    elif all_rows:
        where_sql = "1 = 1"
        where_params = ()
        scope_label = "all readings"
    else:
        where_sql = "timestamp >= datetime('now', 'localtime', '-2 days')"
        where_params = ()
        scope_label = "last 2 days"

    start_time = time.time()
    # Open 5-min bucket in local time (exclude incomplete current slice)
    now_cut = """strftime('%Y-%m-%d %H:', datetime('now', 'localtime')) ||
                 printf('%02d:00', (CAST(strftime('%M', datetime('now', 'localtime')) AS INTEGER) / 5) * 5)"""

    sql = f"""
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
            WHERE {where_sql}
            GROUP BY snapped_ts
            HAVING snapped_ts < ({now_cut})
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
                END;
        """
    with conn:
        cur = conn.execute(sql, where_params)
        changed = cur.rowcount if cur.rowcount is not None and cur.rowcount >= 0 else -1
    duration = time.time() - start_time
    rows_txt = f"{changed:,}" if isinstance(changed, int) and changed >= 0 else str(changed)
    _status_line(
        step,
        f"history_5min: downsampling {scope_label}; {rows_txt} rows",
        duration,
    )
    return int(changed) if changed is not None else -1


def _apply_saved_pac(conn: sqlite3.Connection) -> None:
    """Load spike cap and night gates from app_settings before any rebuild SQL."""
    global MAX_PLAUSIBLE_PAC_W, PAC_RAD_FLOOR
    global PAC_NIGHT_START, PAC_NIGHT_END, PAC_SHOULDER_START, PAC_SHOULDER_END
    pac = site_config.pac_runtime(conn)
    MAX_PLAUSIBLE_PAC_W = pac['cap_w']
    PAC_RAD_FLOOR = pac['rad_floor']
    PAC_NIGHT_START = int(pac['night_start'])
    PAC_NIGHT_END = int(pac['night_end'])
    PAC_SHOULDER_START = int(pac['shoulder_start'])
    PAC_SHOULDER_END = int(pac['shoulder_end'])


if __name__ == '__main__':
    conn = None
    exit_code = 0
    try:
        args = [a.lower() for a in sys.argv]
        conn = sqlite3.connect(DB_FILE, timeout=30.0)
        init_analytical_db(conn)
        site_config.ensure_schema(conn)
        _apply_saved_pac(conn)
        
        if '-consolidate' in args:
            # Step 2: Bridge raw staging into consolidated master
            consolidate_historical_webbox(conn, step=1)

        # Longer -buildall* flags before -buildall (prefix match).
        elif '-buildallsunnyportal' in args:
            # Empty-fill solar only from sunnyportal_pv_5min. Does not wipe history_5min.
            result = sunnyportal_pull.stitch_solar_power(conn)
            sunnyportal_pull.print_stitch_summary(result, "full staging")
            print("=== SUCCESS: sunnyportal merge into history_5min done ===")

        elif '-buildalltoprecords' in args:
            build_top_records(conn, step=1)
            print("=== SUCCESS: top_records rebuilt; open topn.php ===")

        elif '-buildall' in args:
            # Refuse before DELETE. A missing readings table would empty the chart
            # and then have nothing to refill it from.
            if not _table_exists(conn, 'readings'):
                sys.stderr.write(
                    "rollup.py -buildall: readings table is missing. Nothing was deleted.\n"
                )
                exit_code = 1
            else:
                # Full rebuild. history_5min is empty until this command finishes.
                print("=== FULL WAREHOUSE BUILD INITIATED ===")
                print("history_5min is cleared first. The day chart is empty until this command finishes.")
                with conn:
                    conn.execute('DELETE FROM history_5min;')
                    conn.execute('DELETE FROM daily_history;')
                    conn.execute('DELETE FROM monthly_history;')
                downsample_live_readings(conn, all_rows=True, step=1)
                consolidate_historical_webbox(conn, step=2)
                sp = sunnyportal_pull.stitch_solar_power(conn)
                sunnyportal_pull.print_stitch_summary(sp, "full staging")
                fluvius_import.stitch_electricity_curve(conn)
                build_daily_history(conn, step=3)
                build_monthly_history(conn, step=4)
                build_clear_sky_envelopes(conn, step=5)
                build_top_records(conn, step=6)
                build_top_periods(conn, step=7)
                print("=== SUCCESS: Open analytics.php / topn.php in browser ===")

        elif '-daily' in args:
            # Nightly: last 2 local days of readings, last 3 local days of daily_history.
            downsample_live_readings(conn, step=1)
            stitch_lo, stitch_hi = fluvius_import.local_span(conn, 2)
            sp = sunnyportal_pull.stitch_solar_power(conn, stitch_lo, stitch_hi)
            sunnyportal_pull.print_stitch_summary(sp, f"{stitch_lo}..{stitch_hi}")
            fluvius_import.stitch_electricity_curve(conn, stitch_lo, stitch_hi)
            build_daily_history(conn, days_back=3, step=2)
            build_monthly_history(conn, step=3)
            build_top_periods(conn, step=4)
            # Beat check uses the same calendar span as the Fluvius stitch (last 2 days back).
            refresh_top_records_from_recent(conn, stitch_lo, stitch_hi, step=5)

        elif '-today' in args:
            sys.stderr.write(
                "rollup.py -today was removed. "
                "Nightly -daily refreshes history_5min, daily_history, top_periods, and top_records.\n"
            )
            exit_code = 2

        elif '-purge_derived' in args:
            # Dev utility: safely wipes all derived analytical tables for fresh testing
            with conn:
                conn.execute('DELETE FROM history_5min;')
                conn.execute('DELETE FROM daily_history;')
                conn.execute('DELETE FROM monthly_history;')
                conn.execute('DELETE FROM clear_sky_envelopes;')
                conn.execute('DELETE FROM top_records;')
                conn.execute('DELETE FROM top_periods;')
            conn.execute('VACUUM;')
            print(
                "[INFO] All derived analytical tables purged and vacuumed. "
                "(readings, WebBox staging, fluvius_interval, sunnyportal_pv_5min untouched)."
            )

        else:
            print("P1 & Solar Data Warehouse Manager")
            print("Usage:")
            print("  python3 rollup.py -consolidate  (Snaps and merges raw staging into history_5min)")
            print("  python3 rollup.py -buildall     (Rebuild history_5min from readings + staging, then all analytical tables)")
            print("  python3 rollup.py -buildallsunnyportal (Empty-fill solar_power_w from sunnyportal_pv_5min only)")
            print("  python3 rollup.py -buildalltoprecords (Full rewrite of top_records only)")
            print("  python3 rollup.py -daily        (Nightly: last 2 local days of readings, last 3 local days of daily_history)")
            print("  python3 rollup.py -purge_derived (Dev tool: deletes all analytical data safely to restart tests)")

    except Exception as e:
        sys.stderr.write(f"Rollup execution error: {e}\n")
        exit_code = 1
    finally:
        if conn:
            try:
                conn.close()
            except Exception:
                pass
        sys.exit(exit_code)