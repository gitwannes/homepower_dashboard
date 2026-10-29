#!/usr/bin/env python3
# --- file: powertests.py ---
"""
Power Hardware Diagnostics Tool (powertests.py)
Tests connectivity, authentication, and live telemetry across:
  1. P1 Smart Meter
  2. SMA Inverter (WebConnect HTTPS)
  3. Sunny WebBox Gateway & Bus Devices (RS485/Bluetooth + SensorBox)
Uses integration_probes.py (A15). Ignores *_active flags so operators can diagnose
stored credentials anytime. Zero pip dependencies.
"""
import os
import sqlite3
import sys

import integration_probes
import site_config

# Allow both root/user and www-data to execute
os.umask(0)


def _fmt_kwh(value: float, digits: int = 3) -> str:
    text = f"{value:,.{digits}f}".replace(",", "X").replace(".", ",").replace("X", ".")
    return text


def test_p1(ip):
    print("=" * 65)
    print(" 1. P1 DIGITAL SMART METER")
    print("=" * 65)
    result = integration_probes.probe_p1(ip or "")
    status = result.get("status")
    if status == "skipped":
        print("[ WARN ] P1 address is not set in Settings. Skipping test.")
        return
    if status != "ok":
        print(f"[FAIL] {result.get('message')}")
        return

    data = result.get("data") or {}
    print(f"Connecting to http://{(ip or '').strip()}/api/v1/data ...")
    print("[ OK ] P1 Smart Meter ONLINE")
    print(f"       * Meter Model   : {data.get('meter_model', 'Unknown')}")
    print(f"       * Serial Number : {data.get('unique_id', 'Unknown')}")
    print(f"       * Protocol      : DSMR/SMR v{data.get('smr_version', 'Unknown')}")
    print(
        f"       * Wi-Fi Signal  : {data.get('wifi_ssid', 'Unknown')} "
        f"({data.get('wifi_strength', 0)}%)"
    )
    pwr = data.get("active_power_w", 0) or 0
    print(f"       * Current Power : {pwr:,.0f} W".replace(",", "."))
    import_kwh = data.get("total_power_import_kwh", 0) or 0
    export_kwh = data.get("total_power_export_kwh", 0) or 0
    print(f"       * Total Import  : {_fmt_kwh(import_kwh)} kWh")
    print(f"       * Total Export  : {_fmt_kwh(export_kwh)} kWh")

    external = data.get("external", [])
    if external:
        print("       * Sub-Meters (M-Bus):")
        for ext in external:
            m_type = (
                "Gas"
                if ext.get("type") == "gas_meter"
                else ("Water" if ext.get("type") == "water_meter" else ext.get("type"))
            )
            print(
                f"         - {str(m_type).ljust(6)} : {ext.get('value')} "
                f"{ext.get('unit')} (S/N: {ext.get('unique_id')})"
            )
    else:
        gas_sn = data.get("gas_unique_id")
        if gas_sn:
            print(f"       * Gas Meter     : S/N {gas_sn}")


def test_sma(ip, user, password):
    print("\n" + "=" * 65)
    print(" 2. SMA SOLAR INVERTER (WebConnect)")
    print("=" * 65)
    result = integration_probes.probe_sma(ip or "", user or "usr", password or "")
    status = result.get("status")
    if status == "skipped":
        print("[ INFO ] SMA address is not set in Settings. (Not configured for this site)")
        return
    print(f"Target IP : {(ip or '').strip()}")
    if status == "offline":
        print(f"[ OFFLINE ] {result.get('message')}")
        return
    if status != "ok":
        print(f"[FAIL] {result.get('message')}")
        return

    val_res = result.get("data") or {}
    payload = val_res.get("result", {})
    dev_id = next(iter(payload.keys()), "Unknown")
    dev_data = payload.get(dev_id, {})

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
    if current_w is None:
        current_w = 0
    if current_w < 0:
        current_w = 0

    print("[ OK ] SMA Inverter ONLINE")
    print(f"       * Device Key    : {dev_id}")
    print(f"       * Serial Number : {serial_no}")
    print(f"       * Live Power    : {current_w:,.0f} W".replace(",", "."))
    if today is not None:
        print(f"       * Today's Yield : {_fmt_kwh(today / 1000, 2)} kWh")
    if total is not None:
        kwh_tot = (total / 1000.0) if total > 500000 else total
        print(f"       * Lifetime Total: {_fmt_kwh(kwh_tot, 1)} kWh")


def test_webbox(ip, password):
    print("\n" + "=" * 65)
    print(" 3. SUNNY WEBBOX GATEWAY & SENSORS")
    print("=" * 65)
    result = integration_probes.probe_webbox(ip or "", password or "")
    status = result.get("status")
    if status == "skipped":
        print("[ INFO ] Sunny WebBox address is not set in Settings. (Not configured for this site)")
        return
    print(f"Target Gateway IP : {(ip or '').strip()}")
    if status == "offline":
        print(f"[ OFFLINE ] {result.get('message')}")
        return
    if status != "ok":
        print(f"[FAIL] {result.get('message')}")
        return

    bundle = result.get("data") or {}
    p_res = bundle.get("plant") or {}
    plant_items = (p_res.get("result") or {}).get("overview", [])
    p_power, p_today, p_total = "-", "-", "-"
    for item in plant_items:
        meta = (item.get("meta") or "").lower()
        val = str(item.get("value") or "").strip()
        unit = item.get("unit") or ""
        if "gripwr" in meta or "pwr" in meta:
            p_power = f"{val} {unit}"
        elif "tdy" in meta or "today" in meta:
            p_today = f"{val} {unit}"
        elif "tot" in meta or "total" in meta:
            p_total = f"{val} {unit}"

    print("[ OK ] Sunny WebBox Gateway ONLINE")
    print(f"       * Plant Overview: Power: {p_power} | Today: {p_today} | Lifetime: {p_total}")

    devices = ((bundle.get("devices") or {}).get("result") or {}).get("devices", [])
    print(f"       * Connected Devices: {len(devices)} device(s) found on bus")
    if not devices:
        return

    for idx, dev in enumerate(devices, 1):
        key = dev.get("key", "Unknown")
        name = dev.get("name") or key
        parts = key.split(":")
        dev_type = parts[0] if len(parts) > 1 else "Device"
        serial_no = parts[1] if len(parts) > 1 else key
        is_sensorbox = key.startswith("SENS") or "sensor" in name.lower()
        print(f"\n       [{idx}] {name}")
        print(
            f"           * Device Type   : "
            f"{'Sunny SensorBox' if is_sensorbox else 'Solar Inverter'} ({dev_type})"
        )
        print(f"           * Serial Number : {serial_no}")
        print(f"           * Bus Key       : {key}")


def main():
    print("=" * 65)
    print(" Power Hardware & Telemetry Diagnostic Tool")
    print("=" * 65)
    print(f"Python Runtime      : Python {sys.version.split()[0]} ({sys.executable})")

    db_path = site_config.resolve_db_path()
    conn = sqlite3.connect(db_path, timeout=10)
    try:
        site_config.ensure_schema(conn)
        settings = site_config.load_settings(conn)
        configured = site_config.is_configured(conn)
    finally:
        conn.close()

    if configured:
        print(f"Loaded configuration: {db_path}")
    else:
        print("[ WARN ] P1 address is not set. Open Settings in the dashboard.")

    p1_ip = settings.get("p1_ip", "").strip() or None
    sma_ip = settings.get("sma_ip", "").strip() or None
    sma_user = settings.get("sma_user", "").strip() or "usr"
    sma_pwd = settings.get("sma_password", "")
    wb_ip = settings.get("sunnywebbox_ip", "").strip() or None
    wb_pwd = settings.get("sunnywebbox_password", "")

    test_p1(p1_ip)
    test_sma(sma_ip, sma_user, sma_pwd)
    test_webbox(wb_ip, wb_pwd)

    print("\n" + "=" * 65)
    print(" Diagnostics Complete.")
    print("=" * 65 + "\n")


if __name__ == "__main__":
    main()
