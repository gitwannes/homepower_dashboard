#!/usr/bin/env python3
# --- file: sql_importer.py ---
"""Gap-fill staging tables from a legacy MariaDB wpv_raw dump (.sql) in import_queue/.

Does NOT write history_5min. Archive SoT is sensorbox_history_5min + inverter_history_5min
(ZIP first; SQL fills snapped 5-min holes only). Rebuild the master with:
  python3 rollup.py -consolidate
  python3 rollup.py -daily
"""
import os
import sys
import glob
import shutil
import sqlite3
import time
from datetime import datetime
from typing import Any, List, Optional, Set, Tuple

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))

# If located inside a 'helpers' subfolder, set BASE_DIR to the parent project directory
if os.path.basename(SCRIPT_DIR).lower() == 'helpers':
    BASE_DIR = os.path.dirname(SCRIPT_DIR)
else:
    BASE_DIR = SCRIPT_DIR

DB_FILE = os.path.join(BASE_DIR, 'meter_history.db')
QUEUE_DIR = os.path.join(BASE_DIR, 'import_queue')
PROCESSED_DIR = os.path.join(QUEUE_DIR, 'processed')

# Synthetic serial for site-level wpv_raw inverter rows (never alongside ZIP device rows).
LEGACY_SQL_SERIAL = 'legacy_sql'

# Batch sizes for staged INSERT OR IGNORE / INSERT OR IGNORE on (timestamp, device_serial).
BATCH_SIZE = 5000


def snap_to_5min(ts_str: str) -> str:
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


def init_db(conn: sqlite3.Connection) -> None:
    """Ensures staging tables exist (same shape as importer.py / logger.py)."""
    with conn:
        conn.execute("PRAGMA journal_mode = WAL")
        conn.execute("PRAGMA synchronous = NORMAL")
        conn.execute("""
            CREATE TABLE IF NOT EXISTS sensorbox_history_5min (
                timestamp TEXT PRIMARY KEY,
                radiation_wm2 REAL,
                temp_module_c REAL,
                temp_ambient_c REAL,
                wind_speed_ms REAL,
                wind_gust_ms REAL
            )
        """)
        try:
            conn.execute("ALTER TABLE sensorbox_history_5min ADD COLUMN wind_gust_ms REAL")
        except sqlite3.OperationalError:
            pass

        conn.execute("""
            CREATE TABLE IF NOT EXISTS inverter_history_5min (
                timestamp TEXT,
                device_serial TEXT,
                pac_w REAL,
                pac_max_w REAL,
                etotal_kwh REAL,
                string_a_w REAL, string_a_v REAL, string_a_a REAL,
                string_b_w REAL, string_b_v REAL, string_b_a REAL,
                grid_voltage_v REAL, grid_freq_hz REAL,
                PRIMARY KEY (timestamp, device_serial)
            )
        """)
        try:
            conn.execute("ALTER TABLE inverter_history_5min ADD COLUMN pac_max_w REAL")
        except sqlite3.OperationalError:
            pass
        conn.execute("CREATE INDEX IF NOT EXISTS idx_inv_ts ON inverter_history_5min(timestamp)")


def load_occupied_snaps(conn: sqlite3.Connection) -> Tuple[Set[str], Set[str]]:
    """
    Build sets of 5-min floors already present in staging (ZIP may use unsnapped PKs).
    SQL gap-fill must not insert into an occupied floor (ZIP wins; avoid consolidate double-count).
    """
    print("[INFO] Loading occupied sensorbox 5-min floors...")
    t0 = time.time()
    sb_occupied: Set[str] = set()
    for (ts,) in conn.execute("SELECT timestamp FROM sensorbox_history_5min"):
        sb_occupied.add(snap_to_5min(ts))
    print(f"  sensorbox floors: {len(sb_occupied):,} ({time.time() - t0:.1f}s)")

    print("[INFO] Loading occupied inverter 5-min floors...")
    t1 = time.time()
    inv_occupied: Set[str] = set()
    for (ts,) in conn.execute("SELECT timestamp FROM inverter_history_5min"):
        inv_occupied.add(snap_to_5min(ts))
    print(f"  inverter floors: {len(inv_occupied):,} ({time.time() - t1:.1f}s)")
    return sb_occupied, inv_occupied


def parse_tuple_line(line: str) -> Optional[Tuple[Any, ...]]:
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
        nums: List[Optional[float]] = []
        for p in parts[1:]:
            try:
                nums.append(float(p))
            except ValueError:
                nums.append(None)
        return (entry, *nums)
    return None


def flush_batches(
    conn: sqlite3.Connection,
    sb_batch: List[Tuple[Any, ...]],
    inv_batch: List[Tuple[Any, ...]],
) -> Tuple[int, int]:
    """INSERT OR IGNORE both staging batches. Returns (sb_inserted, inv_inserted)."""
    sb_ins = 0
    inv_ins = 0
    with conn:
        if sb_batch:
            before = conn.execute("SELECT COUNT(*) FROM sensorbox_history_5min").fetchone()[0]
            conn.executemany(
                """
                INSERT OR IGNORE INTO sensorbox_history_5min (
                    timestamp, radiation_wm2, temp_module_c, temp_ambient_c,
                    wind_speed_ms, wind_gust_ms
                ) VALUES (?, ?, ?, ?, ?, ?)
                """,
                sb_batch,
            )
            after = conn.execute("SELECT COUNT(*) FROM sensorbox_history_5min").fetchone()[0]
            sb_ins = after - before
        if inv_batch:
            before = conn.execute("SELECT COUNT(*) FROM inverter_history_5min").fetchone()[0]
            conn.executemany(
                """
                INSERT OR IGNORE INTO inverter_history_5min (
                    timestamp, device_serial,
                    pac_w, pac_max_w, etotal_kwh,
                    string_a_w, string_a_v, string_a_a,
                    string_b_w, string_b_v, string_b_a,
                    grid_voltage_v, grid_freq_hz
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                inv_batch,
            )
            after = conn.execute("SELECT COUNT(*) FROM inverter_history_5min").fetchone()[0]
            inv_ins = after - before
    return sb_ins, inv_ins


def process_sql_file(
    file_path: str,
    conn: sqlite3.Connection,
    sb_occupied: Set[str],
    inv_occupied: Set[str],
) -> None:
    print(f"=== Starting SQL staging gap-fill on {os.path.basename(file_path)} ===")
    start_time = time.time()

    in_wpv_raw_block = False
    sb_batch: List[Tuple[Any, ...]] = []
    inv_batch: List[Tuple[Any, ...]] = []

    total_parsed = 0
    sb_inserted = 0
    sb_skipped = 0
    inv_inserted = 0
    inv_skipped = 0

    with open(file_path, 'r', encoding='utf-8', errors='ignore') as f:
        for line in f:
            if "INSERT INTO `wpv_raw`" in line:
                in_wpv_raw_block = True
                continue

            if not in_wpv_raw_block:
                continue

            if not line.strip() or line.startswith('--') or "INSERT INTO" in line:
                if line.startswith('('):
                    pass  # Valid continued tuple
                else:
                    in_wpv_raw_block = False
                    continue

            if not line.strip().startswith('('):
                continue

            parsed = parse_tuple_line(line)
            if not parsed:
                continue

            # rad_max / string peaks unused on sensorbox schema; kept in unpack for wpv_raw layout.
            (entry, rad, _rad_max, t_amb, t_pan, wnd, wnd_max,
             _sb_act, a_i, a_u, a_p, _a_pmax, b_i, b_u, b_p, _b_pmax,
             _net_i, net_u, net_f, net_p, net_pmax, total_p) = parsed

            snapped_ts = snap_to_5min(entry)
            total_parsed += 1

            # --- sensorbox gap-fill (ZIP floor wins) ---
            if snapped_ts in sb_occupied:
                sb_skipped += 1
            else:
                clean_rad = max(0.0, rad) if rad is not None else None
                clean_tamb = t_amb if t_amb is not None and t_amb > -100 else None
                clean_tpan = t_pan if t_pan is not None and t_pan > -100 else None
                clean_wnd = max(0.0, wnd) if wnd is not None else None
                clean_wnd_max = max(0.0, wnd_max) if wnd_max is not None else None
                sb_batch.append((
                    snapped_ts, clean_rad, clean_tpan, clean_tamb, clean_wnd, clean_wnd_max,
                ))
                sb_occupied.add(snapped_ts)

            # --- inverter gap-fill (skip if any ZIP/device row on this floor) ---
            if snapped_ts in inv_occupied:
                inv_skipped += 1
            else:
                clean_pac = net_p if net_p is not None else 0.0
                clean_pac_max = net_pmax if net_pmax is not None else 0.0
                clean_etotal = total_p if total_p is not None else 0.0
                inv_batch.append((
                    snapped_ts, LEGACY_SQL_SERIAL,
                    clean_pac, clean_pac_max, clean_etotal,
                    a_p, a_u, a_i,
                    b_p, b_u, b_i,
                    net_u, net_f,
                ))
                inv_occupied.add(snapped_ts)

            if len(sb_batch) >= BATCH_SIZE or len(inv_batch) >= BATCH_SIZE:
                s_ins, i_ins = flush_batches(conn, sb_batch, inv_batch)
                sb_inserted += s_ins
                inv_inserted += i_ins
                sb_batch.clear()
                inv_batch.clear()
                print(
                    f"[{datetime.now().strftime('%H:%M:%S')}] Parsed {total_parsed:,} | "
                    f"sb +{sb_inserted:,} (skip {sb_skipped:,}) | "
                    f"inv +{inv_inserted:,} (skip {inv_skipped:,})",
                    flush=True,
                )

    if sb_batch or inv_batch:
        s_ins, i_ins = flush_batches(conn, sb_batch, inv_batch)
        sb_inserted += s_ins
        inv_inserted += i_ins

    duration = time.time() - start_time
    print(f"-> SQL file '{os.path.basename(file_path)}' Complete in {duration:.1f} seconds!")
    print(f"   Parsed: {total_parsed:,}")
    print(f"   sensorbox: inserted {sb_inserted:,} | skipped (floor occupied) {sb_skipped:,}")
    print(f"   inverter:  inserted {inv_inserted:,} | skipped (floor occupied) {inv_skipped:,}")
    print("   history_5min: not written (rebuild with rollup.py -consolidate / -daily)")


def run_queue_import() -> None:
    os.makedirs(QUEUE_DIR, exist_ok=True)
    os.makedirs(PROCESSED_DIR, exist_ok=True)

    sql_files = sorted(glob.glob(os.path.join(QUEUE_DIR, '*.sql')))

    if not sql_files:
        print("[INFO] No .sql files found in import_queue/.")
        sys.exit(0)

    print(f"[INFO] Found {len(sql_files)} .sql file(s) in queue.")
    print("[INFO] Target: staging gap-fill only (sensorbox + inverter). Not history_5min.")
    conn = sqlite3.connect(DB_FILE, timeout=30.0)
    init_db(conn)
    sb_occupied, inv_occupied = load_occupied_snaps(conn)

    try:
        for sql_file in sql_files:
            process_sql_file(sql_file, conn, sb_occupied, inv_occupied)
            dest = os.path.join(PROCESSED_DIR, os.path.basename(sql_file))
            if os.path.exists(dest):
                os.remove(dest)
            shutil.move(sql_file, dest)
            print(f"-> Moved {os.path.basename(sql_file)} to processed folder.\n")
    except KeyboardInterrupt:
        print("\n[WARN] Import interrupted by user. Partial staging data committed safely.")
    finally:
        conn.close()


if __name__ == '__main__':
    run_queue_import()
