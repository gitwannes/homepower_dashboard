#!/usr/bin/env python3
"""
Power Hardware Diagnostics Tool (powertests.py)
Tests connectivity, authentication, and live telemetry across:
  1. P1 Smart Meter
  2. SMA Inverter (WebConnect HTTPS)
  3. Sunny WebBox Gateway & Bus Devices (RS485/Bluetooth + SensorBox)
Zero pip dependencies; runs natively on Raspberry Pi & Synology DSM.
"""
import urllib.request
import urllib.error
import ssl
import socket
import json
import hashlib
import os
import sys

# Allow both root/user and www-data to execute
os.umask(0)

def load_env():
    """Load .env from script directory or parent (place from encrypted storage)."""
    candidates = [
        os.path.join(os.path.dirname(os.path.abspath(__file__)), '.env'),
        os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), '.env'),
        '.env'
    ]
    for c in candidates:
        if os.path.exists(c) and os.access(c, os.R_OK):
            config = {}
            with open(c, 'r', encoding='utf-8') as f:
                for line in f:
                    line = line.strip()
                    if line and not line.startswith('#') and '=' in line:
                        k, v = line.split('=', 1)
                        config[k.strip()] = v.strip().strip("'\"")
            return config, c
    return {}, None


# -------------------------------------------------------------------
# 1. P1 SMART METER PROBE
# -------------------------------------------------------------------
def test_p1(ip):
    print("=" * 65)
    print(" 1. P1 DIGITAL SMART METER")
    print("=" * 65)
    if not ip:
        print("[ WARN ] P1_IP not defined in .env. Skipping test.")
        return

    url = f"http://{ip}/api/v1/data"
    print(f"Connecting to {url} ...")
    try:
        req = urllib.request.Request(url)
        with urllib.request.urlopen(req, timeout=5) as resp:
            data = json.loads(resp.read().decode('utf-8'))

        meter_model = data.get('meter_model', 'Unknown')
        serial = data.get('unique_id', 'Unknown')
        smr = data.get('smr_version', 'Unknown')
        ssid = data.get('wifi_ssid', 'Unknown')
        rssi = data.get('wifi_strength', 0)
        pwr = data.get('active_power_w', 0)
        import_kwh = data.get('total_power_import_kwh', 0)
        export_kwh = data.get('total_power_export_kwh', 0)

        print(f"[ OK ] P1 Smart Meter ONLINE")
        print(f"       * Meter Model   : {meter_model}")
        print(f"       * Serial Number : {serial}")
        print(f"       * Protocol      : DSMR/SMR v{smr}")
        print(f"       * Wi-Fi Signal  : {ssid} ({rssi}%)")
        print(f"       * Current Power : {pwr:,.0f} W".replace(',', '.'))
        print(f"       * Total Import  : {import_kwh:,.3f} kWh".replace(',', 'X').replace('.', ',').replace('X', '.'))
        print(f"       * Total Export  : {export_kwh:,.3f} kWh".replace(',', 'X').replace('.', ',').replace('X', '.'))

        # Check sub-meters
        external = data.get('external', [])
        if external:
            print("       * Sub-Meters (M-Bus):")
            for ext in external:
                m_type = "Gas" if ext.get('type') == 'gas_meter' else ("Water" if ext.get('type') == 'water_meter' else ext.get('type'))
                print(f"         - {m_type.ljust(6)} : {ext.get('value')} {ext.get('unit')} (S/N: {ext.get('unique_id')})")
        else:
            gas_sn = data.get('gas_unique_id')
            if gas_sn:
                print(f"       * Gas Meter     : S/N {gas_sn}")

    except urllib.error.URLError as e:
        print(f"[FAIL] Unable to connect to P1 meter at {ip}: {e.reason}")
    except json.JSONDecodeError:
        print(f"[FAIL] P1 meter at {ip} returned invalid JSON payload.")
    except Exception as e:
        print(f"[FAIL] Error querying P1 meter: {e}")


# -------------------------------------------------------------------
# 2. SMA INVERTER PROBE (WebConnect HTTPS)
# -------------------------------------------------------------------
def test_sma(ip, user, password):
    print("\n" + "=" * 65)
    print(" 2. SMA SOLAR INVERTER (WebConnect)")
    print("=" * 65)
    if not ip:
        print("[ INFO ] SMA_IP not defined in .env. (Not configured for this site)")
        return

    print(f"Target IP : {ip}")
    if not password:
        print("[FAIL] Missing SMA_PASSWORD in .env.")
        return

    ctx = ssl._create_unverified_context()
    user_right = "istl" if user.lower() in ("istl", "installer") else "usr"
    sid = None

    print(f"Connecting to https://{ip}/dyn/login.json ...")
    try:
        req = urllib.request.Request(
            f"https://{ip}/dyn/login.json",
            data=json.dumps({"right": user_right, "pass": password}).encode("utf-8"),
            headers={"Content-Type": "application/json"}
        )
        with urllib.request.urlopen(req, context=ctx, timeout=6) as resp:
            login_res = json.loads(resp.read().decode("utf-8"))
            sid = login_res.get("result", {}).get("sid")
            err_code = login_res.get("err")

        if not sid:
            if err_code == 401:
                print(f"[FAIL] Authentication failed (HTTP 401). Check SMA_USER and SMA_PASSWORD.")
            elif err_code == 503:
                print(f"[WARN] Inverter session limit reached (503). Wait 5 minutes.")
            else:
                print(f"[FAIL] Login rejected: {login_res}")
            return

        print(f"[ OK ] Authenticated. Session ID: {sid}")

        keys = ["6100_40263F00", "6100_0046C200", "6400_00262200", "6400_00260100", "6800_085C5E00", "6800_00A21E00"]
        val_req = urllib.request.Request(
            f"https://{ip}/dyn/getValues.json?sid={sid}",
            data=json.dumps({"destDev": [], "keys": keys}).encode("utf-8"),
            headers={"Content-Type": "application/json"}
        )
        with urllib.request.urlopen(val_req, context=ctx, timeout=6) as resp:
            val_res = json.loads(resp.read().decode("utf-8"))

        result = val_res.get("result", {})
        dev_id = next(iter(result.keys()), 'Unknown')
        dev_data = result.get(dev_id, {})

        def extract_val(key):
            ch = next(iter((dev_data.get(key) or {}).values()), [])
            if ch and isinstance(ch, list) and len(ch) > 0:
                return ch[0].get("val")
            return None

        serial_no = extract_val("6800_00A21E00") or dev_id
        ac_pwr = extract_val("6100_40263F00")
        dc_pwr = extract_val("6100_0046C200")
        today = extract_val("6400_00262200")
        total = extract_val("6400_00260100")

        current_w = ac_pwr if ac_pwr is not None else (dc_pwr if dc_pwr is not None else 0)
        if current_w < 0: current_w = 0

        print(f"[ OK ] SMA Inverter ONLINE")
        print(f"       * Device Key    : {dev_id}")
        print(f"       * Serial Number : {serial_no}")
        print(f"       * Live Power    : {current_w:,.0f} W".replace(',', '.'))
        if today is not None:
            print(f"       * Today's Yield : {today / 1000:,.2f} kWh".replace(',', 'X').replace('.', ',').replace('X', '.'))
        if total is not None:
            kwh_tot = (total / 1000.0) if total > 500000 else total
            print(f"       * Lifetime Total: {kwh_tot:,.1f} kWh".replace(',', 'X').replace('.', ',').replace('X', '.'))

    except (urllib.error.URLError, socket.timeout) as e:
        print(f"[ OFFLINE ] Unable to reach SMA Inverter at {ip} (Standby / Night sleep mode).")
    except Exception as e:
        print(f"[FAIL] Error querying SMA Inverter: {e}")
    finally:
        if sid:
            try:
                out_req = urllib.request.Request(
                    f"https://{ip}/dyn/logout.json?sid={sid}",
                    data=b"{}", headers={"Content-Type": "application/json"}
                )
                with urllib.request.urlopen(out_req, context=ctx, timeout=3): pass
                print("       * Session closed cleanly via /dyn/logout.json.")
            except Exception: pass


# -------------------------------------------------------------------
# 3. SUNNY WEBBOX PROBE (JSON-RPC + SensorBox)
# -------------------------------------------------------------------
def test_webbox(ip, password):
    print("\n" + "=" * 65)
    print(" 3. SUNNY WEBBOX GATEWAY & SENSORS")
    print("=" * 65)
    if not ip:
        print("[ INFO ] SUNNYWEBBOX_IP not defined in .env. (Not configured for this site)")
        return

    print(f"Target Gateway IP : {ip}")
    if not password:
        print("[FAIL] Missing SUNNYWEBBOX_PASSWORD in .env.")
        return

    md5_pass = hashlib.md5(password.encode('utf-8')).hexdigest()

    def rpc_call(proc, params=None):
        payload = {"version": "1.0", "proc": proc, "id": "1", "format": "JSON", "passwd": md5_pass}
        if params is not None: payload["params"] = params
        post_data = ("RPC=" + json.dumps(payload)).encode('utf-8')
        req = urllib.request.Request(
            f"http://{ip}/rpc",
            data=post_data,
            headers={"Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json"}
        )
        with urllib.request.urlopen(req, timeout=5) as resp:
            return json.loads(resp.read().decode('utf-8'))

    try:
        # Step A: Gateway Overview
        p_res = rpc_call("GetPlantOverview")
        if "error" in p_res:
            print(f"[FAIL] Authentication failed: {p_res['error'].get('message')}")
            return

        plant_items = p_res.get("result", {}).get("overview", [])
        p_power, p_today, p_total = "-", "-", "-"
        for item in plant_items:
            meta = (item.get("meta") or "").lower()
            val = str(item.get("value") or "").strip()
            unit = item.get("unit") or ""
            if 'gripwr' in meta or 'pwr' in meta: p_power = f"{val} {unit}"
            elif 'tdy' in meta or 'today' in meta: p_today = f"{val} {unit}"
            elif 'tot' in meta or 'total' in meta: p_total = f"{val} {unit}"

        print(f"[ OK ] Sunny WebBox Gateway ONLINE")
        print(f"       * Plant Overview: Power: {p_power} | Today: {p_today} | Lifetime: {p_total}")

        # Step B: Discover Bus Devices
        dev_res = rpc_call("GetDevices")
        devices = dev_res.get("result", {}).get("devices", [])
        print(f"       * Connected Devices: {len(devices)} device(s) found on bus")

        if devices:
            proc_params = {"devices": [{"key": d["key"]} for d in devices if "key" in d]}
            proc_res = rpc_call("GetProcessData", proc_params)
            proc_devices = {d["key"]: d for d in proc_res.get("result", {}).get("devices", [])}

            for idx, dev in enumerate(devices, 1):
                key = dev.get("key", "Unknown")
                name = dev.get("name") or key
                parts = key.split(":")
                dev_type = parts[0] if len(parts) > 1 else "Device"
                serial_no = parts[1] if len(parts) > 1 else key

                is_sensorbox = key.startswith("SENS") or "sensor" in name.lower()
                channels = proc_devices.get(key, {}).get("channels", [])

                print(f"\n       [{idx}] {name}")
                print(f"           * Device Type   : {'Sunny SensorBox' if is_sensorbox else 'Solar Inverter'} ({dev_type})")
                print(f"           * Serial Number : {serial_no}")
                print(f"           * Bus Key       : {key}")

                if is_sensorbox:
                    rad, t_mod, wnd = "-", "-", "-"
                    for c in channels:
                        meta = (c.get("meta") or "").lower()
                        cname = (c.get("name") or "").lower()
                        val = str(c.get("value") or "").strip()
                        if not val or val == "None": continue

                        if 'solirr' in meta or 'rad' in meta or 'rad' in cname:
                            rad = f"{val} W/m²"
                        elif 'mdul' in meta or 'modul' in meta or 'module' in cname:
                            t_mod = f"{val} °C"
                        elif 'wnd' in meta or 'wind' in meta or 'vel' in meta:
                            wnd = f"{val} m/s"
                    print(f"           * Live Sensors  : Rad: {rad} | Temp: {t_mod} | Wind: {wnd}")
                else:
                    pwr = "0 W (Standby / Night)"
                    tdy = p_today
                    tot = p_total
                    for c in channels:
                        meta = (c.get("meta") or "").lower()
                        cname = (c.get("name") or "").lower()
                        val = str(c.get("value") or "").strip()
                        unit = c.get("unit") or ""
                        if 'pac' in meta or 'pwr' in meta:
                            if val and val != "None": pwr = f"{val} {unit}"
                        elif ('e-tot' in meta or 'etot' in meta) and ('h-tot' not in meta and 'hour' not in cname):
                            if val and val != "None": tot = f"{val} {unit}"
                        elif any(x in meta for x in ['etoday', 'e-today', 'heute']) or 'today' in cname:
                            if val and val != "None": tdy = f"{val} {unit}"

                    print(f"           * Telemetry     : Power: {pwr} | Today: {tdy} | Lifetime: {tot}")

    except (urllib.error.URLError, socket.timeout):
        print(f"[ OFFLINE ] Unable to reach Sunny WebBox at {ip} (Check power & network cable).")
    except Exception as e:
        print(f"[FAIL] Error querying Sunny WebBox: {e}")


def main():
    print("=" * 65)
    print(" Power Hardware & Telemetry Diagnostic Tool")
    print("=" * 65)
    print(f"Python Runtime      : Python {sys.version.split()[0]} ({sys.executable})")

    config, env_path = load_env()
    if env_path:
        print(f"Loaded Configuration: {env_path}")
    else:
        print("[ WARN ] No .env configuration file found!")

    p1_ip = config.get("P1_IP")
    sma_ip = config.get("SMA_IP")
    sma_user = config.get("SMA_USER", "usr")
    sma_pwd = config.get("SMA_PASSWORD", "")
    wb_ip = config.get("SUNNYWEBBOX_IP")
    wb_pwd = config.get("SUNNYWEBBOX_PASSWORD", "")

    test_p1(p1_ip)
    test_sma(sma_ip, sma_user, sma_pwd)
    test_webbox(wb_ip, wb_pwd)

    print("\n" + "=" * 65)
    print(" Diagnostics Complete.")
    print("=" * 65 + "\n")

if __name__ == "__main__":
    main()