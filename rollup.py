#!/usr/bin/env python3
import os
import sys
import sqlite3
import time

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DB_FILE = os.path.join(BASE_DIR, 'meter_history.db')

def init_analytical_db(conn):
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
                data_source TEXT
            )
        """)

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

        # 5. Hall of Fame (All-time records)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS top_records (
                record_id TEXT PRIMARY KEY,
                record_value REAL,
                record_date TEXT,
                description TEXT
            )
        """)


def consolidate_historical_webbox(conn):
    """Snaps and merges raw sensorbox and inverter tables into history_5min."""
    print("--- 1. Consolidating Staging into history_5min ---")
    start_time = time.time()
    
    with conn:
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
        
    duration = time.time() - start_time
    count = conn.execute("SELECT COUNT(*) FROM history_5min").fetchone()[0]
    print(f"-> Complete in {duration:.1f}s. history_5min rows: {count:,}")


def build_daily_history(conn, days_back=None):
    """Crunches 5-minute data into daily yields and weather extremes."""
    where_clause = ""
    if days_back:
        where_clause = f"WHERE timestamp >= datetime('now', '-{days_back} days')"
        print(f"--- 2. Updating daily_history (Last {days_back} days) ---")
    else:
        print("--- 2. Building daily_history (Full History) ---")
        
    start_time = time.time()
    with conn:
        conn.execute(f"""
            INSERT OR REPLACE INTO daily_history (
                date, solar_yield_kwh, solar_peak_w, sun_hours,
                grid_import_kwh, grid_export_kwh, self_consumption_kwh,
                temp_ambient_min, temp_ambient_max, temp_module_max,
                wind_gust_max_ms, solar_radiation_max_wm2
            )
            SELECT 
                substr(timestamp, 1, 10) AS dt,
                ROUND(CASE 
                    WHEN MAX(solar_total_kwh) > MIN(solar_total_kwh) AND MIN(solar_total_kwh) > 0 
                    THEN MAX(solar_total_kwh) - MIN(solar_total_kwh)
                    ELSE SUM(COALESCE(solar_power_w, 0)) * (5.0 / 60.0) / 1000.0
                END, 2) AS yield_kwh,
                MAX(COALESCE(solar_power_max_w, solar_power_w, 0)),
                ROUND(COUNT(CASE WHEN solar_power_w > 50 THEN 1 END) * (5.0 / 60.0), 2),
                ROUND(MAX(total_import) - MIN(total_import), 3),
                ROUND(MAX(total_export) - MIN(total_export), 3),
                ROUND(
                    CASE WHEN MAX(solar_total_kwh) > MIN(solar_total_kwh) AND MAX(total_export) >= MIN(total_export)
                    THEN MAX(0.0, (MAX(solar_total_kwh) - MIN(solar_total_kwh)) - (MAX(total_export) - MIN(total_export)))
                    ELSE NULL END
                , 2),
                MIN(temp_ambient), MAX(temp_ambient), MAX(temp_module),
                MAX(COALESCE(wind_gust, wind_speed)), MAX(COALESCE(solar_radiation_max, solar_radiation))
            FROM history_5min
            {where_clause}
            GROUP BY dt;
        """)

    duration = time.time() - start_time
    count = conn.execute("SELECT COUNT(*) FROM daily_history").fetchone()[0]
    print(f"-> Complete in {duration:.1f}s. daily_history rows: {count:,}")


def build_monthly_history(conn):
    """Rolls up daily totals into months and tracks against PVGIS expected baselines."""
    print("--- 3. Building monthly_history (PVGIS Targets) ---")
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
    print(f"-> Complete in {duration:.1f}s. monthly_history rows: {count:,}")


def build_clear_sky_envelopes(conn):
    """Finds the all-time absolute highest AC power for every 5-min slice (288 timepoints) per month."""
    print("--- 4. Building clear_sky_envelopes ---")
    start_time = time.time()
    
    with conn:
        conn.execute("DELETE FROM clear_sky_envelopes;")
        conn.execute("""
            INSERT INTO clear_sky_envelopes (month, timepoint, max_pac_w, max_pac_date, max_rad_wm2)
            SELECT 
                CAST(substr(timestamp, 6, 2) AS INTEGER) AS mth,
                (CAST(substr(timestamp, 12, 2) AS INTEGER) * 12) + (CAST(substr(timestamp, 15, 2) AS INTEGER) / 5) AS tp,
                MAX(COALESCE(solar_power_max_w, solar_power_w, 0)),
                substr(MAX(printf('%09d', COALESCE(solar_power_max_w, solar_power_w, 0)) || timestamp), 10, 10),
                MAX(COALESCE(solar_radiation_max, solar_radiation, 0))
            FROM history_5min
            WHERE solar_power_w > 0
            GROUP BY mth, tp;
        """)
        
    duration = time.time() - start_time
    count = conn.execute("SELECT COUNT(*) FROM clear_sky_envelopes").fetchone()[0]
    print(f"-> Complete in {duration:.1f}s. Envelopes mapped: {count:,} timepoints")


def build_top_records(conn):
    """Calculates Hall of Fame extremes across all 17 years."""
    print("--- 5. Building top_records (Hall of Fame) ---")
    start_time = time.time()
    
    with conn:
        conn.execute("DELETE FROM top_records;")
        
        queries = [
            ("best_day_kwh", "SELECT date, solar_yield_kwh FROM daily_history ORDER BY solar_yield_kwh DESC LIMIT 1", "All-time highest daily generation (kWh)"),
            ("peak_surge_w", "SELECT substr(timestamp, 1, 10), COALESCE(solar_power_max_w, solar_power_w) AS val FROM history_5min ORDER BY val DESC LIMIT 1", "All-time instantaneous solar AC surge peak (W)"),
            ("max_wind_ms", "SELECT date, wind_gust_max_ms FROM daily_history ORDER BY wind_gust_max_ms DESC LIMIT 1", "Maximum recorded wind gust (m/s)"),
            ("max_temp_c", "SELECT date, temp_ambient_max FROM daily_history ORDER BY temp_ambient_max DESC LIMIT 1", "Highest recorded ambient temperature (°C)"),
            ("min_temp_c", "SELECT date, temp_ambient_min FROM daily_history WHERE temp_ambient_min IS NOT NULL ORDER BY temp_ambient_min ASC LIMIT 1", "Lowest recorded ambient temperature (°C)")
        ]
        
        for record_id, sql, desc in queries:
            row = conn.execute(sql).fetchone()
            if row and row[1] is not None:
                conn.execute("INSERT INTO top_records VALUES (?, ?, ?, ?)", (record_id, row[1], row[0], desc))
                
    duration = time.time() - start_time
    print(f"-> Complete in {duration:.1f}s. top_records updated.")


def downsample_live_readings(conn):
    """
    (Scheduled Nightly) 
    Downsamples the hot 1-minute `readings` table into 5-minute boundaries,
    snaps them to history_5min, and automatically runs daily rollups.
    """
    with conn:
        conn.execute("""
            INSERT INTO history_5min (
                timestamp, grid_power_w, import_t1, import_t2, export_t1, export_t2,
                total_import, total_export, capacity_peak_w, gas, water,
                solar_power_w, solar_total_kwh, sample_count, data_source
            )
            SELECT 
                strftime('%Y-%m-%d %H:', timestamp) || 
                printf('%02d:00', (CAST(strftime('%M', timestamp) AS INTEGER) / 5) * 5) AS snapped_ts,
                ROUND(AVG(active_power)),
                MAX(import_t1), MAX(import_t2), MAX(export_t1), MAX(export_t2),
                MAX(total_import), MAX(total_export), MAX(capacity_peak_w),
                MAX(gas), MAX(water),
                ROUND(AVG(solar_power)), MAX(solar_total_kwh), COUNT(*), 'live_p1'
            FROM readings
            WHERE timestamp >= datetime('now', '-2 days')
            GROUP BY snapped_ts
            HAVING snapped_ts < strftime('%Y-%m-%d %H:', datetime('now')) || 
                   printf('%02d:00', (CAST(strftime('%M', datetime('now')) AS INTEGER) / 5) * 5)
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
                solar_power_w   = COALESCE(history_5min.solar_power_w, excluded.solar_power_w),
                solar_total_kwh = COALESCE(history_5min.solar_total_kwh, excluded.solar_total_kwh),
                data_source     = CASE WHEN history_5min.data_source = 'webbox_archive' THEN 'merged' ELSE 'live_p1' END;
        """)

if __name__ == '__main__':
    args = [a.lower() for a in sys.argv]
    conn = sqlite3.connect(DB_FILE, timeout=30.0)
    init_analytical_db(conn)
    
    if '-consolidate' in args:
        # Step 2: Bridge raw staging into consolidated master
        consolidate_historical_webbox(conn)

    elif '-buildall' in args:
        # Step 5: Full 17-Year Analytics Rebuild
        print(f"=== FULL WAREHOUSE BUILD INITIATED ===")
        build_daily_history(conn)
        build_monthly_history(conn)
        build_clear_sky_envelopes(conn)
        build_top_records(conn)
        print("=== SUCCESS: Open analytics.php in browser ===")

    elif '-daily' in args:
        # Step 6: Routine automated midnight downsampler
        downsample_live_readings(conn)
        build_daily_history(conn, days_back=3)
        build_monthly_history(conn)

    elif '-purge_derived' in args:
        # Dev utility: safely wipes all derived analytical tables for fresh testing
        with conn:
            conn.execute('DELETE FROM history_5min;')
            conn.execute('DELETE FROM daily_history;')
            conn.execute('DELETE FROM monthly_history;')
            conn.execute('DELETE FROM clear_sky_envelopes;')
            conn.execute('DELETE FROM top_records;')
        conn.execute('VACUUM;')
        print("[INFO] All derived analytical tables purged and vacuumed. (Raw staging & readings untouched).")

    else:
        print("P1 & Solar Data Warehouse Manager")
        print("Usage:")
        print("  python3 rollup.py -consolidate  (Snaps and merges raw staging into history_5min)")
        print("  python3 rollup.py -buildall     (Populates all daily, monthly, envelope, and top_records tables)")
        print("  python3 rollup.py -daily        (Automated nightly cron: downsamples live P1 readings)")
        print("  python3 rollup.py -purge_derived (Dev tool: deletes all analytical data safely to restart tests)")
        
    conn.close()