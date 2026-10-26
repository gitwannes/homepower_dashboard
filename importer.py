#!/usr/bin/env python3
# --- file: importer.py ---
import os
import sys
import glob
import shutil
import sqlite3
import zipfile
import io
import json
import re
import time
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from datetime import datetime

import site_config

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DB_FILE = site_config.resolve_db_path()
QUEUE_DIR = os.path.join(BASE_DIR, 'import_queue')
PROCESSED_DIR = os.path.join(QUEUE_DIR, 'processed')

SEVEN_ZIP_BIN = shutil.which('7z') or '/bin/7z'

# Parse command line flags
CLI_ARGS = [a.lower() for a in sys.argv[1:]]
HANDLE_OUTER = ('-outerzip' in CLI_ARGS) or ('--outerzip' in CLI_ARGS)

# Strict Sunny WebBox Daily Archive Regex: wb<serial>.<YYYYMMDD-HHMMSS>.(zip|7z)
DAILY_ARCHIVE_REGEX = re.compile(r'^wb\d+\.\d{8}-\d{6}\.(zip|7z)$', re.IGNORECASE)

# Seed baseline targets from wpv_expected
EXPECTED_MONTHLY = [
    (1, 1, 30, 23.1, 27.9),
    (2, 2, 30, 44.5, 54.5),
    (3, 3, 30, 63.5, 79.5),
    (4, 4, 30, 91.4, 118.0),
    (5, 5, 30, 110.0, 145.0),
    (6, 6, 30, 102.0, 138.0),
    (7, 7, 30, 112.0, 152.0),
    (8, 8, 30, 102.0, 137.0),
    (9, 9, 30, 73.4, 96.7),
    (10, 10, 30, 53.5, 68.3),
    (11, 11, 30, 29.9, 36.7),
    (12, 12, 30, 18.1, 21.9),
    (13, 1, 15, 19.5, 24.1),
    (14, 2, 15, 38.5, 47.4),  # Normalized from 474 typo
    (15, 3, 15, 59.7, 74.6),
    (16, 4, 15, 89.3, 115.0),
    (17, 5, 15, 111.0, 146.0),
    (18, 6, 15, 105.0, 142.0),
    (19, 7, 15, 114.0, 154.0),
    (20, 8, 15, 101.0, 135.0),
    (21, 9, 15, 69.6, 91.2),
    (22, 10, 15, 47.8, 61.1),
    (23, 11, 15, 25.2, 31.5),
    (24, 12, 15, 15.0, 18.8)
]


def log_event(event_type, **kwargs):
    """Outputs progress formatted for SSH terminal or as JSON for web dashboard."""
    ts = datetime.now().strftime('%H:%M:%S')
    if HANDLE_OUTER:
        if event_type == "start":
            print(f"[{ts}] {kwargs.get('message')}")
        elif event_type == "progress":
            file_name = kwargs.get('file', '')
            parent = f"[{kwargs.get('parent')}] " if kwargs.get('parent') else ""
            idx = kwargs.get('index', 0)
            tot = kwargs.get('total', 0)
            s_ins = kwargs.get('s_ins', 0)
            i_ins = kwargs.get('i_ins', 0)
            dups = kwargs.get('s_dup', 0) + kwargs.get('i_dup', 0)
            print(f"[{ts}] {parent}[{idx}/{tot}] {file_name} -> +{s_ins} sensor, +{i_ins} inv ({dups} dupes)", flush=True)
        elif event_type == "error":
            print(f"[{ts}] [ERROR] {kwargs.get('file')}: {kwargs.get('error')}", file=sys.stderr, flush=True)
        elif event_type == "info":
            print(f"[{ts}] [INFO] {kwargs.get('message')}")
        elif event_type == "complete":
            print("\n" + "=" * 60)
            print(f"[{ts}] ALL IMPORTS COMPLETED in {kwargs.get('duration')}s")
            print(f"  SensorBox Rows Added:       {kwargs.get('tot_sensor_inserted', 0):,}")
            print(f"  SensorBox Duplicates:       {kwargs.get('tot_sensor_duplicates', 0):,}")
            print(f"  Inverter Rows Added:        {kwargs.get('tot_inverter_inserted', 0):,}")
            print(f"  Inverter Duplicates:        {kwargs.get('tot_inverter_duplicates', 0):,}")
            print(f"  Errors Encountered:         {kwargs.get('tot_errors', 0)}")
            print(f"  Processed Directory:        {PROCESSED_DIR}")
            print("=" * 60)
    else:
        payload = {"type": event_type, "timestamp": ts, **kwargs}
        print(json.dumps(payload), flush=True)


def init_db(conn):
    """Ensures raw staging tables and consolidated production tables exist."""
    with conn:
        conn.execute("PRAGMA journal_mode = WAL")
        conn.execute("PRAGMA synchronous = NORMAL")

        # 1. Consolidated Production 5-Minute Master Table
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
        conn.execute("CREATE INDEX IF NOT EXISTS idx_hist5m_ts ON history_5min(timestamp)")

        # 2. Raw SensorBox 5-Minute Staging Table
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

        # 3. Raw Inverter 5-Minute Staging Table
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

        # 4. Expected Monthly Baseline Targets
        conn.execute("""
            CREATE TABLE IF NOT EXISTS expected_monthly (
                id INTEGER PRIMARY KEY,
                month INTEGER NOT NULL,
                inclination INTEGER NOT NULL,
                expected_kwh REAL NOT NULL,
                expected_irr REAL NOT NULL,
                UNIQUE(month, inclination)
            )
        """)
        conn.executemany("""
            INSERT INTO expected_monthly (id, month, inclination, expected_kwh, expected_irr)
            SELECT ?, ?, ?, ?, ?
            WHERE NOT EXISTS (
                SELECT 1 FROM expected_monthly WHERE month = ? AND inclination = ?
            )
        """, [
            (row[0], row[1], row[2], row[3], row[4], row[1], row[2]) for row in EXPECTED_MONTHLY
        ])


def count_files_in_archive(archive_path):
    """Queries archive table of contents to return total file count."""
    if not os.path.exists(SEVEN_ZIP_BIN):
        return None
    try:
        res = subprocess.run([SEVEN_ZIP_BIN, 'l', archive_path], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=15)
        out = res.stdout.decode('utf-8', errors='ignore')
        m = re.search(r'(\d+)\s+files', out, re.IGNORECASE)
        if m:
            return int(m.group(1))
        m = re.search(r'Files:\s*(\d+)', out, re.IGNORECASE)
        if m:
            return int(m.group(1))
    except Exception:
        pass
    return None


def parse_mean_xml_content(xml_bytes):
    """Parses a single Mean.*.xml payload extracting averages and peak surges."""
    try:
        root = ET.fromstring(xml_bytes)
    except Exception:
        return [], []

    sensor_dict = {}
    inverter_dict = {}

    for mp in root.findall('.//MeanPublic'):
        key_elem = mp.find('Key')
        mean_elem = mp.find('Mean')
        max_elem = mp.find('Max')
        ts_elem = mp.find('TimeStamp')

        if key_elem is None or mean_elem is None or ts_elem is None:
            continue
        if not key_elem.text or not mean_elem.text or not ts_elem.text:
            continue

        key = key_elem.text.strip()
        ts = ts_elem.text.strip().replace('T', ' ')

        try:
            val_mean = float(mean_elem.text.strip())
        except (ValueError, TypeError):
            continue

        val_max = None
        if max_elem is not None and max_elem.text:
            try:
                val_max = float(max_elem.text.strip())
            except (ValueError, TypeError):
                pass

        parts = key.split(':')
        if len(parts) >= 3:
            dev_type, dev_serial, metric = parts[0], parts[1], ":".join(parts[2:])
        elif len(parts) == 2:
            dev_type, metric = parts[0], parts[1]
            dev_serial = dev_type
        else:
            continue

        # 1. Sunny SensorBox environmental telemetry
        if dev_type.startswith('SENS'):
            if ts not in sensor_dict:
                sensor_dict[ts] = {
                    'timestamp': ts,
                    'rad': None,
                    't_mod': None,
                    't_amb': None,
                    'wnd': None,
                    'wnd_gust': None
                }
            s = sensor_dict[ts]
            if metric in ('IntSolIrr', 'SolIrr'):
                s['rad'] = max(0.0, val_mean)
            elif metric == 'TmpMdul C':
                s['t_mod'] = val_mean if val_mean > -100 else None
            elif metric == 'TmpAmb C':
                # Convert dead sensor / absolute zero values (-273.17) to NULL
                s['t_amb'] = val_mean if val_mean > -100 else None
            elif metric in ('WindVel m/s', 'WndSpd'):
                s['wnd'] = max(0.0, val_mean)
                s['wnd_gust'] = max(0.0, val_max) if val_max is not None else max(0.0, val_mean)

        # 2. Inverter electrical telemetry
        elif dev_type.startswith('WR') or 'WRTL' in dev_type:
            inv_key = (ts, dev_serial)
            if inv_key not in inverter_dict:
                inverter_dict[inv_key] = {
                    'timestamp': ts,
                    'device_serial': dev_serial,
                    'pac_w': None,
                    'pac_max_w': None,
                    'etotal_kwh': None,
                    'string_a_w': None, 'string_a_v': None, 'string_a_a': None,
                    'string_b_w': None, 'string_b_v': None, 'string_b_a': None,
                    'grid_voltage_v': None, 'grid_freq_hz': None
                }
            inv = inverter_dict[inv_key]
            if metric == 'Pac':
                inv['pac_w'] = round(val_mean, 1)
                inv['pac_max_w'] = round(val_max, 1) if val_max is not None else round(val_mean, 1)
            elif metric == 'E-Total':
                inv['etotal_kwh'] = round(val_mean, 3)
            elif metric == 'A.Ms.Watt':
                inv['string_a_w'] = round(val_mean, 1)
            elif metric == 'A.Ms.Vol':
                inv['string_a_v'] = round(val_mean, 1)
            elif metric == 'A.Ms.Amp':
                inv['string_a_a'] = round(val_mean, 3)
            elif metric == 'B.Ms.Watt':
                inv['string_b_w'] = round(val_mean, 1)
            elif metric == 'B.Ms.Vol':
                inv['string_b_v'] = round(val_mean, 1)
            elif metric == 'B.Ms.Amp':
                inv['string_b_a'] = round(val_mean, 3)
            elif metric in ('GridMs.PhV.phsA', 'GridMs.Vol'):
                inv['grid_voltage_v'] = round(val_mean, 1)
            elif metric == 'GridMs.Hz':
                inv['grid_freq_hz'] = round(val_mean, 2)

    sensor_rows = [
        (s['timestamp'], s['rad'], s['t_mod'], s['t_amb'], s['wnd'], s['wnd_gust'])
        for s in sensor_dict.values()
    ]
    inverter_rows = [
        (i['timestamp'], i['device_serial'], i['pac_w'], i['pac_max_w'], i['etotal_kwh'],
         i['string_a_w'], i['string_a_v'], i['string_a_a'],
         i['string_b_w'], i['string_b_v'], i['string_b_a'],
         i['grid_voltage_v'], i['grid_freq_hz'])
        for i in inverter_dict.values()
    ]
    return sensor_rows, inverter_rows


def insert_batches_staging(conn, sensor_batch, inverter_batch):
    """Legacy/Staging insertion into separate sensorbox and inverter tables."""
    s_ins, s_dup = 0, 0
    i_ins, i_dup = 0, 0

    if not sensor_batch and not inverter_batch:
        return 0, 0, 0, 0

    with conn:
        if sensor_batch:
            before = conn.execute("SELECT COUNT(*) FROM sensorbox_history_5min").fetchone()[0]
            conn.executemany("""
                INSERT OR IGNORE INTO sensorbox_history_5min 
                (timestamp, radiation_wm2, temp_module_c, temp_ambient_c, wind_speed_ms, wind_gust_ms)
                VALUES (?, ?, ?, ?, ?, ?)
            """, sensor_batch)
            after = conn.execute("SELECT COUNT(*) FROM sensorbox_history_5min").fetchone()[0]
            s_ins = after - before
            s_dup = len(sensor_batch) - s_ins

        if inverter_batch:
            before = conn.execute("SELECT COUNT(*) FROM inverter_history_5min").fetchone()[0]
            conn.executemany("""
                INSERT OR IGNORE INTO inverter_history_5min 
                (timestamp, device_serial, pac_w, pac_max_w, etotal_kwh,
                 string_a_w, string_a_v, string_a_a,
                 string_b_w, string_b_v, string_b_a,
                 grid_voltage_v, grid_freq_hz)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, inverter_batch)
            after = conn.execute("SELECT COUNT(*) FROM inverter_history_5min").fetchone()[0]
            i_ins = after - before
            i_dup = len(inverter_batch) - i_ins

    return s_ins, s_dup, i_ins, i_dup


def commit_parsed_batches(conn, s_batch, i_batch):
    """Writes parsed Mean rows into the staging tables."""
    return insert_batches_staging(conn, s_batch, i_batch)


def process_raw_xml(file_path, conn):
    with open(file_path, 'rb') as f:
        content = f.read()
    s_rows, i_rows = parse_mean_xml_content(content)
    return commit_parsed_batches(conn, s_rows, i_rows)


def process_zip_archive(archive_path, conn):
    """Reads wb*.zip or Mean.*.xml.zip in memory without extracting to disk."""
    sensor_batch = []
    inverter_batch = []

    with zipfile.ZipFile(archive_path, 'r') as outer_zip:
        for fname in outer_zip.namelist():
            if fname.endswith('.xml.zip') and 'Mean.' in fname:
                try:
                    inner_bytes = outer_zip.read(fname)
                    with zipfile.ZipFile(io.BytesIO(inner_bytes), 'r') as inner_zip:
                        for inner_name in inner_zip.namelist():
                            if inner_name.startswith('Mean.') and inner_name.endswith('.xml'):
                                s, i = parse_mean_xml_content(inner_zip.read(inner_name))
                                sensor_batch.extend(s)
                                inverter_batch.extend(i)
                except Exception:
                    continue
            elif fname.startswith('Mean.') and fname.endswith('.xml'):
                try:
                    s, i = parse_mean_xml_content(outer_zip.read(fname))
                    sensor_batch.extend(s)
                    inverter_batch.extend(i)
                except Exception:
                    continue

    return commit_parsed_batches(conn, sensor_batch, inverter_batch)


def process_7z_archive(archive_path, conn):
    """Extracts wb*.7z using /bin/7z and parses contained files."""
    if not os.path.exists(SEVEN_ZIP_BIN):
        raise FileNotFoundError(f"7z binary not found at {SEVEN_ZIP_BIN}")

    sensor_batch = []
    inverter_batch = []
    tmp_dir = tempfile.mkdtemp(prefix="webbox_7z_")

    try:
        cmd = [SEVEN_ZIP_BIN, 'x', archive_path, f'-o{tmp_dir}', '-y']
        res = subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        if res.returncode != 0:
            err = res.stderr.decode('utf-8', errors='ignore')
            raise RuntimeError(f"/bin/7z failed: {err}")

        for root, _, files in os.walk(tmp_dir):
            for f in files:
                f_path = os.path.join(root, f)
                if f.endswith('.xml.zip') and 'Mean.' in f:
                    try:
                        with zipfile.ZipFile(f_path, 'r') as inner_z:
                            for inner_name in inner_z.namelist():
                                if inner_name.startswith('Mean.') and inner_name.endswith('.xml'):
                                    s, i = parse_mean_xml_content(inner_z.read(inner_name))
                                    sensor_batch.extend(s)
                                    inverter_batch.extend(i)
                    except Exception:
                        continue
                elif f.startswith('Mean.') and f.endswith('.xml'):
                    try:
                        with open(f_path, 'rb') as xml_f:
                            s, i = parse_mean_xml_content(xml_f.read())
                            sensor_batch.extend(s)
                            inverter_batch.extend(i)
                    except Exception:
                        continue

        return commit_parsed_batches(conn, sensor_batch, inverter_batch)
    finally:
        shutil.rmtree(tmp_dir, ignore_errors=True)


def process_outer_master_archive(master_path, conn):
    """Handles outer container archive (.rar, master .7z, or master .zip)."""
    if not os.path.exists(SEVEN_ZIP_BIN):
        raise FileNotFoundError(f"7z binary not found at {SEVEN_ZIP_BIN}. Required for outer archive extraction.")

    fname = os.path.basename(master_path)
    tmp_master_dir = tempfile.mkdtemp(prefix="outer_container_")

    m_s_ins, m_s_dup = 0, 0
    m_i_ins, m_i_dup = 0, 0
    m_errors = 0

    try:
        total_expected_files = count_files_in_archive(master_path)
        if total_expected_files:
            log_event("info", message=f"Outer container '{fname}' contains ~{total_expected_files:,} items. Unpacking...")
        else:
            log_event("info", message=f"Unpacking outer container '{fname}' via {SEVEN_ZIP_BIN}...")

        cmd = [SEVEN_ZIP_BIN, 'x', master_path, f'-o{tmp_master_dir}', '-y']
        proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)

        extracted_count = 0
        last_progress_time = time.time()

        for line in iter(proc.stdout.readline, b''):
            line_str = line.decode('utf-8', errors='ignore').strip()
            if line_str.startswith("Extracting  ") and not line_str.startswith("Extracting archive:"):
                extracted_count += 1
                curr_item = line_str[12:].strip()

                now = time.time()
                if now - last_progress_time >= 1.5 or (total_expected_files and extracted_count == total_expected_files):
                    ts = datetime.now().strftime('%H:%M:%S')
                    pct_str = f" ({(extracted_count / total_expected_files) * 100:.1f}%)" if total_expected_files else ""
                    print(f"[{ts}] [UNPACKING] {fname}: {extracted_count:,}{f' / {total_expected_files:,}' if total_expected_files else ''}{pct_str} -> {curr_item}", flush=True)
                    last_progress_time = now

        proc.wait()
        if proc.returncode != 0:
            raise RuntimeError(f"/bin/7z exited with error code {proc.returncode}")

        inner_items = []
        for root, _, files in os.walk(tmp_master_dir):
            for f in files:
                lower = f.lower()
                if lower.endswith(('.zip', '.xml', '.7z')):
                    inner_items.append(os.path.join(root, f))
        inner_items.sort()

        total_inner = len(inner_items)
        target_dest = "staging tables"
        log_event("info", message=f"Unpacking complete! Ingesting {total_inner:,} file(s) into {target_dest}...")

        for idx, item in enumerate(inner_items, 1):
            sub_name = os.path.basename(item)
            lower = sub_name.lower()
            try:
                if lower.endswith('.xml'):
                    if 'mean.' in lower:
                        s_ins, s_dup, i_ins, i_dup = process_raw_xml(item, conn)
                    else:
                        continue
                elif lower.endswith('.7z'):
                    s_ins, s_dup, i_ins, i_dup = process_7z_archive(item, conn)
                else:  # .zip
                    s_ins, s_dup, i_ins, i_dup = process_zip_archive(item, conn)

                m_s_ins += s_ins
                m_s_dup += s_dup
                m_i_ins += i_ins
                m_i_dup += i_dup

                log_event(
                    "progress",
                    parent=fname,
                    index=idx,
                    total=total_inner,
                    file=sub_name,
                    s_ins=s_ins,
                    s_dup=s_dup,
                    i_ins=i_ins,
                    i_dup=i_dup
                )
            except Exception as e:
                m_errors += 1
                log_event("error", parent=fname, index=idx, total=total_inner, file=sub_name, error=str(e))

        return m_s_ins, m_s_dup, m_i_ins, m_i_dup, m_errors
    finally:
        shutil.rmtree(tmp_master_dir, ignore_errors=True)


def run_queue_import():
    if '-aggregate5min' in CLI_ARGS or '--aggregate5min' in CLI_ARGS:
        sys.stderr.write(
            "importer.py -aggregate5min was removed. "
            "Import writes the staging tables. Then run: python3 rollup.py -consolidate\n"
        )
        sys.exit(2)
    os.makedirs(QUEUE_DIR, exist_ok=True)
    os.makedirs(PROCESSED_DIR, exist_ok=True)

    conn = sqlite3.connect(DB_FILE, timeout=30.0)
    init_db(conn)

    tot_s_ins, tot_s_dup = 0, 0
    tot_i_ins, tot_i_dup = 0, 0
    tot_errors = 0
    start_time = datetime.now()

    all_files = sorted(os.listdir(QUEUE_DIR))

    # Log operational mode
    mode_desc = "RAW STAGING (sensorbox & inverter)"
    if HANDLE_OUTER:
        print(f"=== WebBox Ingestion Pipeline | Mode: {mode_desc} ===")

    # 1. SSH OUTER ARCHIVE MODE (-outerzip)
    if HANDLE_OUTER:
        outer_archives = []
        for f in all_files:
            lower = f.lower()
            f_path = os.path.join(QUEUE_DIR, f)
            if not os.path.isfile(f_path):
                continue
            if lower.endswith(('.rar', '.7z', '.zip')):
                if not DAILY_ARCHIVE_REGEX.match(f):
                    outer_archives.append(f_path)

        if outer_archives:
            log_event("start", total_files=len(outer_archives), message=f"Found {len(outer_archives)} outer master archive(s)...")

            for idx, m_path in enumerate(outer_archives, 1):
                fname = os.path.basename(m_path)
                try:
                    s_ins, s_dup, i_ins, i_dup, errs = process_outer_master_archive(m_path, conn)
                    tot_s_ins += s_ins
                    tot_s_dup += s_dup
                    tot_i_ins += i_ins
                    tot_i_dup += i_dup
                    tot_errors += errs

                    dest = os.path.join(PROCESSED_DIR, fname)
                    if os.path.exists(dest):
                        os.remove(dest)
                    shutil.move(m_path, dest)
                    log_event("info", message=f"Moved master container '{fname}' to processed/.")
                except Exception as e:
                    tot_errors += 1
                    log_event("error", index=idx, total=len(outer_archives), file=fname, error=str(e))
        else:
            log_event("info", message="No outer containers (.rar, master .7z, or master .zip) found in import_queue/.")

    # 2. STANDARD INGESTION MODE (Daily wb*.zip or loose XML files)
    queue_items = []
    ignored_outer_count = 0

    for f in all_files:
        f_path = os.path.join(QUEUE_DIR, f)
        if not os.path.isfile(f_path):
            continue
        lower = f.lower()

        if lower.endswith('.xml'):
            queue_items.append(f_path)
        elif DAILY_ARCHIVE_REGEX.match(f):
            queue_items.append(f_path)
        elif lower.endswith(('.zip', '.7z', '.rar')):
            ignored_outer_count += 1

    if ignored_outer_count > 0 and not HANDLE_OUTER:
        log_event("info", message=f"Left {ignored_outer_count} outer container archive(s) untouched in queue. (Use -outerzip via SSH to unpack)")

    if queue_items:
        total_files = len(queue_items)
        log_event("start", total_files=total_files, message=f"Starting ingestion of {total_files} standard file(s)...")

        for idx, item_path in enumerate(queue_items, 1):
            fname = os.path.basename(item_path)
            lower_name = fname.lower()

            try:
                if lower_name.endswith('.xml'):
                    s_ins, s_dup, i_ins, i_dup = process_raw_xml(item_path, conn)
                elif lower_name.endswith('.7z'):
                    s_ins, s_dup, i_ins, i_dup = process_7z_archive(item_path, conn)
                else:
                    s_ins, s_dup, i_ins, i_dup = process_zip_archive(item_path, conn)

                tot_s_ins += s_ins
                tot_s_dup += s_dup
                tot_i_ins += i_ins
                tot_i_dup += i_dup

                log_event(
                    "progress",
                    index=idx,
                    total=total_files,
                    file=fname,
                    s_ins=s_ins,
                    s_dup=s_dup,
                    i_ins=i_ins,
                    i_dup=i_dup
                )

                dest = os.path.join(PROCESSED_DIR, fname)
                if os.path.exists(dest):
                    os.remove(dest)
                shutil.move(item_path, dest)

            except Exception as e:
                tot_errors += 1
                log_event("error", index=idx, total=total_files, file=fname, error=str(e))
    elif not HANDLE_OUTER:
        log_event("info", message="No standard daily wb*.zip or .xml files found in import_queue/.")

    conn.close()
    duration = (datetime.now() - start_time).total_seconds()

    log_event(
        "complete",
        duration=round(duration, 1),
        tot_sensor_inserted=tot_s_ins,
        tot_sensor_duplicates=tot_s_dup,
        tot_inverter_inserted=tot_i_ins,
        tot_inverter_duplicates=tot_i_dup,
        tot_errors=tot_errors
    )


if __name__ == '__main__':
    run_queue_import()