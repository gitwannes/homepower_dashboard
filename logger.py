#!/usr/bin/env python3
import urllib.request
import urllib.error
import ssl
import socket
import json
import sqlite3
import hashlib
import os
import sys
import fcntl
from datetime import datetime

# Allow both cron (www-data) and personal user to access created database
os.umask(0)

# 1. Platform Detection & Storage Path Resolution
IS_SYNOLOGY = os.path.exists('/etc/synoinfo.conf') or os.path.exists('/etc.defaults/synoinfo.conf')
# MAX_ROWS = 14400  # 10 days @ 1 poll/min  # nothing gets deleted

if not IS_SYNOLOGY:
    if os.path.exists('/var/log/p1') and os.access('/var/log/p1', os.W_OK):
        DB_FILE = '/var/log/p1/meter_history.db'
    elif os.path.exists('/dev/shm') and os.access('/dev/shm', os.W_OK):
        DB_FILE = '/dev/shm/meter_history.db'
    else:
        DB_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'meter_history.db')
else:
    DB_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'meter_history.db')


def load_env():
    """Load .env from app directory or parent (never from git; place from encrypted storage)."""
    candidates = [
        os.path.join(os.path.dirname(os.path.abspath(__file__)), '.env'),
        os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), '.env'),
        '.env'
    ]
    env_path = None
    for c in candidates:
        if os.path.exists(c) and os.access(c, os.R_OK):
            env_path = c
            break

    if not env_path:
        return None

    config = {}
    with open(env_path, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith('#') and '=' in line:
                k, v = line.split('=', 1)
                config[k.strip()] = v.strip().strip("'\"")
    return config


def init_db():
    """Initializes SQLite tables and handles schema migrations."""
    conn = sqlite3.connect(DB_FILE, timeout=10)
    with conn:
        # Enable WAL mode for high concurrency between logger writes and web reads
        conn.execute("PRAGMA journal_mode=WAL")

        # 1-Minute Hot Telemetry Table
        conn.execute("""
            CREATE TABLE IF NOT EXISTS readings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp TEXT UNIQUE,
                active_power INTEGER,
                solar_power INTEGER DEFAULT 0,
                import_t1 REAL,
                import_t2 REAL,
                export_t1 REAL,
                export_t2 REAL,
                total_import REAL,
                total_export REAL,
                capacity_peak_w INTEGER,
                solar_total_kwh REAL,
                solar_radiation REAL,
                temp_module REAL,
                wind_speed REAL,
                gas REAL,
                water REAL
            )
        """)
        conn.execute("CREATE INDEX IF NOT EXISTS idx_readings_timestamp ON readings(timestamp)")

        # 5-Minute Weather & SensorBox History (Permanent 17-year archive)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS sensorbox_history_5min (
                timestamp TEXT PRIMARY KEY,
                radiation_wm2 REAL,
                temp_module_c REAL,
                temp_ambient_c REAL,
                wind_speed_ms REAL
            )
        """)

        # 5-Minute Inverter Telemetry History (Composite PK handles multiple inverters)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS inverter_history_5min (
                timestamp TEXT,
                device_serial TEXT,
                pac_w REAL,
                etotal_kwh REAL,
                string_a_w REAL,
                string_a_v REAL,
                string_a_a REAL,
                string_b_w REAL,
                string_b_v REAL,
                string_b_a REAL,
                grid_voltage_v REAL,
                grid_freq_hz REAL,
                PRIMARY KEY (timestamp, device_serial)
            )
        """)
        conn.execute("CREATE INDEX IF NOT EXISTS idx_inv_ts ON inverter_history_5min(timestamp)")

        # System Logs (Streak Counter)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS system_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_seen TEXT,
                last_seen TEXT,
                status TEXT,
                message TEXT,
                count INTEGER DEFAULT 1
            )
        """)
        conn.execute("CREATE INDEX IF NOT EXISTS idx_logs_last_seen ON system_logs(last_seen)")

        # Latest State Snapshot (Read by index.php)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS latest_state (
                id INTEGER PRIMARY KEY,
                updated_at TEXT,
                data_json TEXT
            )
        """)

    try:
        os.chmod(DB_FILE, 0o666)
    except OSError:
        pass
    return conn

def log_event(status, message):
    """Logs deduplicated event streaks to system_logs."""
    try:
        conn = init_db()
        now_str = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        with conn:
            cur = conn.cursor()
            cur.execute("SELECT id, status, message, count FROM system_logs ORDER BY id DESC LIMIT 1")
            last_row = cur.fetchone()

            if last_row and last_row[1] == status and last_row[2] == message:
                cur.execute("UPDATE system_logs SET last_seen = ?, count = count + 1 WHERE id = ?", (now_str, last_row[0]))
            else:
                cur.execute("INSERT INTO system_logs (first_seen, last_seen, status, message, count) VALUES (?, ?, ?, ?, 1)",
                            (now_str, now_str, status, message))
        conn.close()
    except Exception as e:
        sys.stderr.write(f"Database logging failure: {e}\n")


def store_fatal_error(message):
    """Saves fatal errors into latest_state for index.php to display exclusively."""
    try:
        conn = init_db()
        now_str = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        with conn:
            conn.execute("""
                INSERT INTO latest_state (id, updated_at, data_json)
                VALUES (1, ?, ?)
                ON CONFLICT(id) DO UPDATE SET updated_at=excluded.updated_at, data_json=excluded.data_json
            """, (now_str, json.dumps({'fatal_error': message})))
        conn.close()
    except Exception:
        pass


def fetch_p1(ip):
    """Queries P1 meter via HTTP GET."""
    req = urllib.request.Request(f"http://{ip}/api/v1/data")
    with urllib.request.urlopen(req, timeout=5) as response:
        raw = response.read().decode('utf-8')
        return json.loads(raw)


def extract_sma_val(device_dict, key):
    reg = device_dict.get(key)
    if not reg or not isinstance(reg, dict):
        return None
    channel = next(iter(reg.values()), [])
    if channel and isinstance(channel, list) and len(channel) > 0:
        val = channel[0].get("val")
        if val is not None and isinstance(val, (int, float)):
            return val
    return None


def fetch_sma(ip, user, password, last_state):
    """Queries SMA inverter via HTTPS WebConnect. Returns (data_dict, alert_string)."""
    last_sma = (last_state.get('sma_data') or {})
    state = {
        'status': 'Offline',
        'device_id': last_sma.get('device_id', 'SMA Inverter'),
        'current_w': 0,
        'today_kwh': last_sma.get('today_kwh'),
        'total_kwh': last_sma.get('total_kwh')
    }

    if not user or not password:
        return None, "SMA Inverter Error: Missing SMA_USER or SMA_PASSWORD in .env."

    ctx = ssl._create_unverified_context()
    user_right = "istl" if user.lower() in ("istl", "installer") else "usr"
    sid = None

    try:
        req = urllib.request.Request(
            f"https://{ip}/dyn/login.json",
            data=json.dumps({"right": user_right, "pass": password}).encode("utf-8"),
            headers={"Content-Type": "application/json"}
        )
        with urllib.request.urlopen(req, context=ctx, timeout=5) as resp:
            login_res = json.loads(resp.read().decode("utf-8"))
            sid = login_res.get("result", {}).get("sid")
            err_code = login_res.get("err")

        if not sid:
            if err_code == 401 or login_res.get("result") is None:
                return None, "SMA Inverter Error: Authentication failed (HTTP 401). Check SMA_USER and SMA_PASSWORD in .env."
            elif err_code == 503:
                return state, None  # Session busy
            return None, f"SMA Inverter Error: Inverter rejected login (Code {err_code})."

        keys = ["6100_40263F00", "6100_0046C200", "6400_00262200", "6400_00260100"]
        val_req = urllib.request.Request(
            f"https://{ip}/dyn/getValues.json?sid={sid}",
            data=json.dumps({"destDev": [], "keys": keys}).encode("utf-8"),
            headers={"Content-Type": "application/json"}
        )
        with urllib.request.urlopen(val_req, context=ctx, timeout=5) as resp:
            val_res = json.loads(resp.read().decode("utf-8"))

        result = val_res.get("result", {})
        if result:
            dev_id = next(iter(result.keys()), None)
            dev_data = result.get(dev_id, {})
            if dev_id:
                state['device_id'] = dev_id

            ac_power = extract_sma_val(dev_data, "6100_40263F00")
            dc_power = extract_sma_val(dev_data, "6100_0046C200")
            today = extract_sma_val(dev_data, "6400_00262200")
            total = extract_sma_val(dev_data, "6400_00260100")

            current_w = ac_power if ac_power is not None else (dc_power if dc_power is not None else 0)
            if current_w < 0:
                current_w = 0

            state['status'] = 'Online'
            state['current_w'] = int(current_w)
            if today is not None:
                state['today_kwh'] = today / 1000.0
            if total is not None:
                state['total_kwh'] = (total / 1000.0) if total > 500000 else total

        return state, None

    except (urllib.error.URLError, socket.timeout):
        return state, None  # Graceful offline / sleep at night
    except Exception:
        return state, None
    finally:
        if sid:
            try:
                out_req = urllib.request.Request(
                    f"https://{ip}/dyn/logout.json?sid={sid}",
                    data=b"{}", headers={"Content-Type": "application/json"}
                )
                with urllib.request.urlopen(out_req, context=ctx, timeout=3):
                    pass
            except Exception:
                pass


def rpc_webbox(ip, proc, params=None, passwd=None):
    """Executes a JSON-RPC request to Sunny WebBox."""
    req_body = {
        "version": "1.0",
        "proc": proc,
        "id": "1",
        "format": "JSON"
    }
    if passwd is not None:
        req_body["passwd"] = passwd
    if params is not None:
        req_body["params"] = params

    post_data = ("RPC=" + json.dumps(req_body)).encode('utf-8')
    url = f"http://{ip}/rpc"
    req = urllib.request.Request(
        url,
        data=post_data,
        headers={
            "Content-Type": "application/x-www-form-urlencoded",
            "Accept": "application/json"
        }
    )
    with urllib.request.urlopen(req, timeout=5) as resp:
        return json.loads(resp.read().decode('utf-8'))


def fetch_webbox(ip, password, last_state):
    """
    Queries Sunny WebBox gateway via JSON-RPC.
    Retrieves connected inverters, SensorBox environmental metrics, and plant totals.
    Returns: (state_dict, alert_string)
    """
    last_wb = (last_state.get('webbox_data') or {})
    state = {
        'status': 'Offline',
        'ip': ip,
        'name': last_wb.get('name', 'Sunny WebBox'),
        'current_w': 0,
        'today_kwh': last_wb.get('today_kwh'),
        'total_kwh': last_wb.get('total_kwh'),
        'devices': last_wb.get('devices', []),
        'sensorbox': last_wb.get('sensorbox', None)
    }

    if not password:
        return None, "Sunny WebBox Error: Missing SUNNYWEBBOX_PASSWORD in .env."

    md5_pass = hashlib.md5(password.encode('utf-8')).hexdigest()

    def rpc_call(proc, params=None):
        req_body = {
            "version": "1.0",
            "proc": proc,
            "id": "1",
            "format": "JSON",
            "passwd": md5_pass
        }
        if params is not None:
            req_body["params"] = params

        post_data = ("RPC=" + json.dumps(req_body)).encode('utf-8')
        url = f"http://{ip}/rpc"
        req = urllib.request.Request(
            url,
            data=post_data,
            headers={
                "Content-Type": "application/x-www-form-urlencoded",
                "Accept": "application/json"
            }
        )
        with urllib.request.urlopen(req, timeout=5) as resp:
            return json.loads(resp.read().decode('utf-8'))

    try:
        # Step 1: Discover all bus-connected devices
        dev_res = rpc_call("GetDevices")
        if "error" in dev_res:
            err = dev_res["error"]
            err_msg = err.get("message", "Authentication or RPC error")
            return None, f"Sunny WebBox Error: {err_msg}. Check SUNNYWEBBOX_PASSWORD in .env."

        dev_list = dev_res.get("result", {}).get("devices", [])
        if not dev_list:
            state['status'] = 'Online'
            return state, None

        # Step 2: Fetch Plant Overview (Gateway-level memory for Today and Lifetime totals)
        plant_today = None
        plant_total = None
        plant_power = None
        try:
            p_res = rpc_call("GetPlantOverview")
            for item in p_res.get("result", {}).get("overview", []):
                meta = (item.get("meta") or "").lower()
                val = item.get("value")
                unit = (item.get("unit") or "").lower()
                try:
                    fval = float(str(val).replace(',', '.'))
                    if 'mwh' in unit:
                        fval *= 1000.0
                    elif unit == 'wh':
                        fval /= 1000.0
                except (ValueError, TypeError):
                    continue

                if 'gripwr' in meta or 'pwr' in meta:
                    plant_power = max(0.0, fval)
                elif 'tdy' in meta or 'today' in meta or 'heute' in meta:
                    plant_today = fval
                elif 'tot' in meta or 'total' in meta or 'gesamt' in meta:
                    plant_total = fval
        except Exception:
            pass

        # Step 3: Fetch process data for individual devices
        device_keys = [d["key"] for d in dev_list if "key" in d]
        proc_params = {"devices": [{"key": k} for k in device_keys]}
        proc_res = rpc_call("GetProcessData", params=proc_params)

        if "error" in proc_res:
            err = proc_res["error"]
            err_msg = err.get("message", "Error reading process data")
            return None, f"Sunny WebBox Error: {err_msg}."

        res_devices = proc_res.get("result", {}).get("devices", [])

        total_inv_w = 0.0
        total_inv_today = 0.0
        total_inv_total = 0.0
        inverter_found = False
        parsed_devices = []
        sensorbox_obj = None

        for d in res_devices:
            d_key = d.get("key", "")
            d_name = d.get("name") or d_key
            channels = d.get("channels", [])

            # Identify if device is a SensorBox
            is_sensor = d_key.startswith("SENS") or any(
                any(x in (c.get("meta") or "").lower() or x in (c.get("name") or "").lower()
                    for x in ['radiation', 'solirr', 'tmpmdul', 'mdul', 'tmpmod', 'wndspd', 'wind'])
                for c in channels
            )

            if is_sensor:
                s_rad = 0.0
                s_mod = None
                s_wnd = 0.0
                for c in channels:
                    meta = (c.get("meta") or "").lower()
                    cname = (c.get("name") or "").lower()
                    raw_val = c.get("value")
                    try:
                        fval = float(str(raw_val).replace(',', '.'))
                    except (ValueError, TypeError):
                        continue

                    # Solar Radiation (W/m²)
                    if 'intsolirr' in meta or ('solirr' in meta and 'ext' not in meta) or ('rad' in meta and 'ext' not in meta):
                        s_rad = fval
                    elif 'extsolirr' in meta and s_rad == 0.0:
                        s_rad = fval

                    # Module Temperature (°C) - matches 'TmpMdul C'
                    elif 'mdul' in meta or 'modul' in meta or 'module' in cname or 'tmpmdul' in meta:
                        s_mod = fval

                    # Wind Speed (m/s)
                    elif 'wnd' in meta or 'wind' in meta or 'vel' in meta:
                        s_wnd = fval

                sensorbox_obj = {
                    'key': d_key,
                    'name': d_name,
                    'radiation': s_rad,
                    'temp_module': s_mod if s_mod is not None else 0.0,
                    'wind_speed': s_wnd
                }
                parsed_devices.append({
                    'key': d_key,
                    'name': d_name,
                    'type': 'sensor',
                    'summary': f"{s_rad:,.0f} W/m² · {s_mod if s_mod is not None else 0.0:.1f} °C · {s_wnd:.1f} m/s"
                })

            else:
                # Inverter Device
                p_w = 0.0
                today_k = None
                total_k = None

                for c in channels:
                    meta = (c.get("meta") or "").lower()
                    cname = (c.get("name") or "").lower()
                    unit = (c.get("unit") or "").lower()
                    raw_val = c.get("value")
                    try:
                        fval = float(str(raw_val).replace(',', '.'))
                    except (ValueError, TypeError):
                        continue

                    # 1. AC Active Power (Pac)
                    if 'pac' in meta or 'pwr' in meta or 'power' in cname or 'leistung' in cname:
                        if 'kw' in unit:
                            fval *= 1000.0
                        p_w = max(0.0, fval)

                    # 2. Today Yield (if inverter reports channel directly)
                    elif any(x in meta for x in ['etoday', 'e-today', 'egytdy', 'heute']) or 'today' in cname:
                        if 'mwh' in unit:
                            fval *= 1000.0
                        elif unit == 'wh':
                            fval /= 1000.0
                        today_k = fval

                    # 3. Energy Total (E-Total ONLY - exclude h-Total and operating hours)
                    elif ('e-tot' in meta or 'etot' in meta or 'egytot' in meta) or (
                        'tot' in meta and 'h-tot' not in meta and 'h_' not in meta and 'hour' not in cname
                    ):
                        if 'mwh' in unit:
                            fval *= 1000.0
                        elif unit == 'wh':
                            fval /= 1000.0
                        total_k = fval

                inverter_found = True
                total_inv_w += p_w

                # Fallback to plant overview values if inverter channels were empty (night standby)
                dev_today = today_k if today_k is not None else (plant_today if plant_today is not None else 0.0)
                dev_total = total_k if total_k is not None else (plant_total if plant_total is not None else 0.0)

                total_inv_today += dev_today
                total_inv_total += dev_total

                parsed_devices.append({
                    'key': d_key,
                    'name': d_name,
                    'type': 'inverter',
                    'power_w': int(p_w),
                    'today_kwh': dev_today,
                    'total_kwh': dev_total
                })

        state['status'] = 'Online'
        state['devices'] = parsed_devices
        state['sensorbox'] = sensorbox_obj

        if inverter_found:
            state['current_w'] = int(total_inv_w)
            state['today_kwh'] = total_inv_today if total_inv_today > 0 else plant_today
            state['total_kwh'] = total_inv_total if total_inv_total > 0 else plant_total
        elif plant_power is not None:
            state['current_w'] = int(plant_power)
            state['today_kwh'] = plant_today
            state['total_kwh'] = plant_total

        return state, None

    except (urllib.error.URLError, socket.timeout):
        return state, None  # Graceful offline / night standby
    except Exception:
        return state, None

def run_poll():
    """Master polling pipeline enforcing fatal P1 hierarchy and addon solar processing."""
    # Prevent concurrent runs from stacking processes if network or DB stalls
    try:
        lock_fd = open('/tmp/p1_logger.lock', 'w')
        fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except (IOError, BlockingIOError):
        sys.exit(0)

    try:
        # 1. Check .env
        config = load_env()
        if config is None:
            err_msg = "Configuration error: .env file not found."
            log_event("ERROR", err_msg)
            store_fatal_error(err_msg)
            print(err_msg)
            sys.exit(0)

        # 2. Check P1_IP
        p1_ip = config.get('P1_IP', '').strip()
        if not p1_ip:
            err_msg = "Configuration error: P1_IP is not defined in .env."
            log_event("ERROR", err_msg)
            store_fatal_error(err_msg)
            print(err_msg)
            sys.exit(0)

        args = [a.lower() for a in sys.argv]
        is_forced = '-force' in args or '--force' in args

        now = datetime.now()
        timestamp = now.strftime('%Y-%m-%d %H:%M:%S')

        conn = init_db()
        cur = conn.cursor()

        last_state_data = {}
        if not is_forced:
            cur.execute("SELECT updated_at, data_json FROM latest_state WHERE id = 1")
            last_row = cur.fetchone()
            if last_row:
                try:
                    last_dt = datetime.strptime(last_row[0], '%Y-%m-%d %H:%M:%S')
                    if (now - last_dt).total_seconds() < 45:
                        print("P1 meter live. (Already logged recently)")
                        conn.close()
                        sys.exit(0)
                    last_state_data = json.loads(last_row[1])
                except Exception:
                    pass

        # 3. Fetch P1 Meter
        try:
            p1_data = fetch_p1(p1_ip)
            if not isinstance(p1_data, dict) or 'active_power_w' not in p1_data:
                raise ValueError("Payload missing active_power_w")
        except (urllib.error.URLError, socket.timeout):
            err_msg = f"Connection error: Unable to reach P1 meter at {p1_ip}."
            log_event("ERROR", err_msg)
            store_fatal_error(err_msg)
            conn.close()
            print(err_msg)
            sys.exit(0)
        except Exception:
            err_msg = f"Payload error: P1 smart meter at {p1_ip} returned malformed data."
            log_event("ERROR", err_msg)
            store_fatal_error(err_msg)
            conn.close()
            print(err_msg)
            sys.exit(0)

        # 4. Check Solar Addons (SMA & WebBox)
        alerts = []

        # SMA Inverter
        has_sma_conf = 'SMA_IP' in config and config['SMA_IP'].strip() != ''
        sma_data = None
        if has_sma_conf:
            sma_ip = config['SMA_IP'].strip()
            sma_user = config.get('SMA_USER', 'usr').strip()
            sma_pwd = config.get('SMA_PASSWORD', '').strip()
            sma_data, sma_alert = fetch_sma(sma_ip, sma_user, sma_pwd, last_state_data)
            if sma_alert:
                alerts.append(sma_alert)

        # Sunny WebBox
        has_webbox_conf = 'SUNNYWEBBOX_IP' in config and config['SUNNYWEBBOX_IP'].strip() != ''
        webbox_data = None
        if has_webbox_conf:
            wb_ip = config['SUNNYWEBBOX_IP'].strip()
            wb_pwd = config.get('SUNNYWEBBOX_PASSWORD', '').strip()
            webbox_data, wb_alert = fetch_webbox(wb_ip, wb_pwd, last_state_data)
            if wb_alert:
                alerts.append(wb_alert)

        # 5. Aggregate Solar Generation
        total_solar_w = 0
        today_solar_kwh = 0.0
        total_solar_kwh = 0.0
        has_solar = False

        if sma_data is not None:
            has_solar = True
            total_solar_w += sma_data.get('current_w', 0)
            if sma_data.get('today_kwh') is not None:
                today_solar_kwh += sma_data['today_kwh']
            if sma_data.get('total_kwh') is not None:
                total_solar_kwh += sma_data['total_kwh']

        if webbox_data is not None:
            has_solar = True
            total_solar_w += webbox_data.get('current_w', 0)
            if webbox_data.get('today_kwh') is not None:
                today_solar_kwh += webbox_data['today_kwh']
            if webbox_data.get('total_kwh') is not None:
                total_solar_kwh += webbox_data['total_kwh']

        # 6. Extract P1 Utility Meters (Nullable water handling)
        gas, water = None, None
        for ext in p1_data.get('external', []):
            if ext.get('type') == 'gas_meter':
                gas = float(ext['value'])
            elif ext.get('type') == 'water_meter':
                water = float(ext['value'])

        active_power = int(p1_data.get('active_power_w', 0))
        home_consumption_w = max(0, total_solar_w + active_power) if has_solar else max(0, active_power)

        # 7. Construct Full JSON Snapshot
        full_state = {
            'fatal_error': None,
            'has_solar': has_solar,
            'has_sma': (sma_data is not None),
            'has_webbox': (webbox_data is not None),
            'has_water': (water is not None),
            'alerts': alerts,
            'p1_data': p1_data,
            'sma_data': sma_data,
            'webbox_data': webbox_data,
            'calculated': {
                'total_solar_w': total_solar_w,
                'today_solar_kwh': today_solar_kwh if has_solar else None,
                'total_solar_kwh': total_solar_kwh if has_solar else None,
                'home_consumption_w': home_consumption_w
            }
        }

        # Extract SensorBox metrics if available
        s_box = webbox_data.get('sensorbox') if webbox_data else None
        rad = s_box.get('radiation') if s_box else None
        t_mod = s_box.get('temp_module') if s_box else None
        w_spd = s_box.get('wind_speed') if s_box else None

        # Calculate total import / export and fetch capacity peak
        tot_import = float(p1_data.get('total_power_import_kwh') or 0.0)
        tot_export = float(p1_data.get('total_power_export_kwh') or 0.0)
        cap_peak = int(p1_data.get('montly_power_peak_w') or 0)

        # 8. Commit to Database
        try:
            with conn:
                conn.execute("""
                    INSERT OR IGNORE INTO readings
                    (timestamp, active_power, solar_power, import_t1, import_t2, export_t1, export_t2,
                     total_import, total_export, capacity_peak_w, solar_total_kwh,
                     solar_radiation, temp_module, wind_speed, gas, water)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """, (
                    timestamp,
                    active_power,
                    total_solar_w,
                    float(p1_data.get('total_power_import_t1_kwh', 0.0)),
                    float(p1_data.get('total_power_import_t2_kwh', 0.0)),
                    float(p1_data.get('total_power_export_t1_kwh', 0.0)),
                    float(p1_data.get('total_power_export_t2_kwh', 0.0)),
                    tot_import,
                    tot_export,
                    cap_peak,
                    total_solar_kwh if has_solar else None,
                    rad,
                    t_mod,
                    w_spd,
                    gas,
                    water
                ))
                conn.execute("""
                    INSERT INTO latest_state (id, updated_at, data_json)
                    VALUES (1, ?, ?)
                    ON CONFLICT(id) DO UPDATE SET updated_at=excluded.updated_at, data_json=excluded.data_json
                """, (timestamp, json.dumps(full_state)))

                # no deletion!
                # conn.execute("DELETE FROM readings WHERE id NOT IN (SELECT id FROM readings ORDER BY id DESC LIMIT ?)", (MAX_ROWS,))

            conn.close()

            # Update event streak
            if alerts:
                log_event("WARN", "P1 read & DB saved. Alerts: " + "; ".join(alerts))
            else:
                devs = ["P1"]
                if sma_data:
                    devs.append(f"SMA({sma_data['status']})")
                if webbox_data:
                    devs.append(f"WebBox({webbox_data['status']})")
                log_event("SUCCESS", "Polled " + " & ".join(devs))

            print(f"Poll successful. P1: {p1_ip} | Solar: {total_solar_w}W")
            sys.exit(0)

        except Exception as e:
            msg = f"Database commit error: {e}"
            print(msg)
            log_event("ERROR", msg)
            conn.close()
            sys.exit(0)

    except Exception as unexpected:
        sys.stderr.write(f"Unexpected error: {unexpected}\n")
        sys.exit(0)


def show_log():
    if not os.path.exists(DB_FILE):
        print(f"Database file not found at: {DB_FILE}")
        sys.exit(0)
    try:
        conn = sqlite3.connect(DB_FILE)
        cur = conn.cursor()
        cur.execute("SELECT first_seen, last_seen, status, count, message FROM system_logs ORDER BY id DESC LIMIT 20")
        rows = cur.fetchall()
        conn.close()

        print(f"--- Last {len(rows)} event streaks ---")
        for r in reversed(rows):
            f_seen, l_seen, stat, count, msg = r
            t_span = f"[{f_seen}]" if f_seen == l_seen else f"[{f_seen} -> {l_seen}]"
            print(f"{t_span} [{stat.ljust(7)}] (x{str(count).ljust(4)}) {msg}")
    except Exception as e:
        print(f"Error reading DB: {e}")
    sys.exit(0)


if __name__ == "__main__":
    args = [a.lower() for a in sys.argv]
    if '-poll' in args or '--poll' in args:
        run_poll()
    elif '-log' in args or '--log' in args:
        show_log()
    else:
        print("P1 & Solar Data Logger (SQLite Edition)")
        print("Usage: python3 logger.py [-poll | -poll -force | -log]")