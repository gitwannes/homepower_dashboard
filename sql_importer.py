#!/usr/bin/env python3
import os
import sys
import glob
import shutil
import sqlite3
import time
from datetime import datetime

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))

# If located inside a 'helpers' subfolder, set BASE_DIR to the parent project directory
if os.path.basename(SCRIPT_DIR).lower() == 'helpers':
    BASE_DIR = os.path.dirname(SCRIPT_DIR)
else:
    BASE_DIR = SCRIPT_DIR

DB_FILE = os.path.join(BASE_DIR, 'meter_history.db')
QUEUE_DIR = os.path.join(BASE_DIR, 'import_queue')
PROCESSED_DIR = os.path.join(QUEUE_DIR, 'processed')

def snap_to_5min(ts_str):
    """Snaps a timestamp 'YYYY-MM-DD HH:MM:SS' to its 5-minute floor boundary."""
    ts = ts_str.strip()
    parts = ts.split(' ')
    if len(parts) == 2:
        d, t = parts
        t_parts = t.split(':')
        if len(t_parts) >= 2:
            h = t_parts[0]
            m = int(t_parts[1])
            m_snap = (m // 5) * 5
            return f"{d} {h}:{m_snap:02d}:00"
    return ts

def init_db(conn):
    """Ensures the target consolidated table exists in WAL mode."""
    with conn:
        conn.execute("PRAGMA journal_mode = WAL")
        conn.execute("PRAGMA synchronous = NORMAL")
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

def parse_tuple_line(line):
    """Safely splits a raw SQL VALUES tuple: ('2010-03-27 23:25:27', 0, 0, 9.53, ...)"""
    line = line.strip()
    if line.endswith(',') or line.endswith(';'):
        line = line[:-1]
    
    if line.startswith('(') and line.endswith(')'):
        line = line[1:-1]
    
    parts = line.split(',')
    # wpv_raw has exactly 22 columns
    if len(parts) == 22:
        entry = parts[0].strip().strip("'")
        nums = []
        for p in parts[1:]:
            try:
                nums.append(float(p))
            except ValueError:
                nums.append(None)
        return (entry, *nums)
    return None

def process_sql_file(file_path, conn):
    print(f"=== Starting SQL Extraction on {os.path.basename(file_path)} ===")
    start_time = time.time()
    
    in_wpv_raw_block = False
    batch = []
    batch_size = 10000
    
    total_parsed = 0
    total_inserted = 0
    total_skipped = 0

    insert_query = """
        INSERT OR IGNORE INTO history_5min (
            timestamp, 
            solar_power_w, solar_power_max_w, solar_total_kwh,
            solar_radiation, solar_radiation_max, 
            temp_ambient, temp_module, 
            wind_speed, wind_gust,
            string_a_a, string_a_v, string_a_w,
            string_b_a, string_b_v, string_b_w,
            grid_voltage_v, grid_freq_hz,
            data_source
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sql_raw'
        )
    """

    with open(file_path, 'r', encoding='utf-8', errors='ignore') as f:
        for line_num, line in enumerate(f, 1):
            
            # Detect the start of the core data payload
            if "INSERT INTO `wpv_raw`" in line:
                in_wpv_raw_block = True
                continue
            
            if in_wpv_raw_block:
                if not line.strip() or line.startswith('--') or "INSERT INTO" in line:
                    if line.startswith('('):
                        pass # Valid continued tuple
                    else:
                        in_wpv_raw_block = False
                        continue
                
                if not line.strip().startswith('('):
                    continue

                parsed = parse_tuple_line(line)
                if parsed:
                    (entry, rad, rad_max, t_amb, t_pan, wnd, wnd_max, 
                     sb_act, a_i, a_u, a_p, a_pmax, b_i, b_u, b_p, b_pmax, 
                     net_i, net_u, net_f, net_p, net_pmax, total_p) = parsed
                    
                    snapped_ts = snap_to_5min(entry)
                    
                    clean_rad = max(0.0, rad) if rad is not None else None
                    clean_rad_max = max(0.0, rad_max) if rad_max is not None else None
                    clean_tamb = t_amb if t_amb is not None and t_amb > -100 else None
                    clean_tpan = t_pan if t_pan is not None and t_pan > -100 else None
                    clean_wnd = max(0.0, wnd) if wnd is not None else None
                    clean_wnd_max = max(0.0, wnd_max) if wnd_max is not None else None
                    
                    clean_pac = net_p if net_p is not None else 0.0
                    clean_pac_max = net_pmax if net_pmax is not None else 0.0
                    clean_etotal = total_p if total_p is not None else 0.0

                    batch.append((
                        snapped_ts, 
                        clean_pac, clean_pac_max, clean_etotal,
                        clean_rad, clean_rad_max, 
                        clean_tamb, clean_tpan,
                        clean_wnd, clean_wnd_max,
                        a_i, a_u, a_p,
                        b_i, b_u, b_p,
                        net_u, net_f
                    ))
                    
                    total_parsed += 1

                    if len(batch) >= batch_size:
                        with conn:
                            before = conn.execute("SELECT COUNT(*) FROM history_5min").fetchone()[0]
                            conn.executemany(insert_query, batch)
                            after = conn.execute("SELECT COUNT(*) FROM history_5min").fetchone()[0]
                            inserted = after - before
                            total_inserted += inserted
                            total_skipped += (len(batch) - inserted)
                        print(f"[{datetime.now().strftime('%H:%M:%S')}] Processed {total_parsed:,} rows... (+{inserted:,} inserted, {len(batch)-inserted:,} skipped)")
                        batch.clear()

    # Flush remaining rows
    if batch:
        with conn:
            before = conn.execute("SELECT COUNT(*) FROM history_5min").fetchone()[0]
            conn.executemany(insert_query, batch)
            after = conn.execute("SELECT COUNT(*) FROM history_5min").fetchone()[0]
            inserted = after - before
            total_inserted += inserted
            total_skipped += (len(batch) - inserted)

    duration = time.time() - start_time
    print(f"-> SQL file '{os.path.basename(file_path)}' Complete in {duration:.1f} seconds!")
    print(f"   Parsed: {total_parsed:,} | Inserted: {total_inserted:,} | Skipped: {total_skipped:,}")

def run_queue_import():
    os.makedirs(QUEUE_DIR, exist_ok=True)
    os.makedirs(PROCESSED_DIR, exist_ok=True)

    sql_files = sorted(glob.glob(os.path.join(QUEUE_DIR, '*.sql')))
    
    if not sql_files:
        print("[INFO] No .sql files found in import_queue/.")
        sys.exit(0)

    print(f"[INFO] Found {len(sql_files)} .sql file(s) in queue.")
    conn = sqlite3.connect(DB_FILE, timeout=30.0)
    init_db(conn)

    try:
        for sql_file in sql_files:
            process_sql_file(sql_file, conn)
            dest = os.path.join(PROCESSED_DIR, os.path.basename(sql_file))
            if os.path.exists(dest):
                os.remove(dest)
            shutil.move(sql_file, dest)
            print(f"-> Moved {os.path.basename(sql_file)} to processed folder.\n")
    except KeyboardInterrupt:
        print("\n[WARN] Import interrupted by user. Partial data committed safely.")
    finally:
        conn.close()

if __name__ == '__main__':
    run_queue_import()