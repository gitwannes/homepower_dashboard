#!/usr/bin/env python3
# --- file: integration_probes.py ---
"""Structured live-integration probes for Settings Save (A15) and powertests.

Status values: ok | fail | offline | skipped
No pip dependencies. Console / JSON CLI for PHP.
"""
from __future__ import annotations

import hashlib
import json
import socket
import ssl
import sys
import urllib.error
import urllib.request
from typing import Any, Dict, List, Optional

import site_config

ProbeResult = Dict[str, Any]


def _result(status: str, message: str, data: Optional[Any] = None) -> ProbeResult:
    out: ProbeResult = {"status": status, "message": message}
    if data is not None:
        out["data"] = data
    return out


def probe_p1(ip: str) -> ProbeResult:
    """HTTP GET /api/v1/data on the P1 meter."""
    host = (ip or "").strip()
    if not host:
        return _result("skipped", "P1 address is empty.")
    url = f"http://{host}/api/v1/data"
    try:
        req = urllib.request.Request(url)
        with urllib.request.urlopen(req, timeout=5) as resp:
            data = json.loads(resp.read().decode("utf-8"))
        if not isinstance(data, dict) or "active_power_w" not in data:
            return _result("fail", f"P1 at {host} returned an unexpected payload.")
        return _result("ok", f"P1 at {host} is online.", data)
    except urllib.error.URLError as exc:
        reason = getattr(exc, "reason", exc)
        return _result("fail", f"Unable to reach P1 at {host}: {reason}")
    except json.JSONDecodeError:
        return _result("fail", f"P1 at {host} returned invalid JSON.")
    except Exception as exc:
        return _result("fail", f"P1 probe error: {exc}")


def probe_sma(ip: str, user: str, password: str) -> ProbeResult:
    """SMA WebConnect login + a small values read."""
    host = (ip or "").strip()
    if not host:
        return _result("skipped", "SMA address is empty.")
    if not password:
        return _result("fail", "SMA password is missing.")

    ctx = ssl._create_unverified_context()
    right = "istl" if (user or "").strip().lower() in ("istl", "installer") else "usr"
    sid = None
    try:
        req = urllib.request.Request(
            f"https://{host}/dyn/login.json",
            data=json.dumps({"right": right, "pass": password}).encode("utf-8"),
            headers={"Content-Type": "application/json"},
        )
        with urllib.request.urlopen(req, context=ctx, timeout=6) as resp:
            login_res = json.loads(resp.read().decode("utf-8"))
        sid = (login_res.get("result") or {}).get("sid")
        err_code = login_res.get("err")
        if not sid:
            if err_code == 401:
                return _result("fail", "SMA authentication failed (401).")
            if err_code == 503:
                return _result("fail", "SMA session limit reached (503). Try again later.")
            return _result("fail", f"SMA login rejected: {login_res}")

        keys = [
            "6100_40263F00",
            "6100_0046C200",
            "6400_00262200",
            "6400_00260100",
            "6800_085C5E00",
            "6800_00A21E00",
        ]
        val_req = urllib.request.Request(
            f"https://{host}/dyn/getValues.json?sid={sid}",
            data=json.dumps({"destDev": [], "keys": keys}).encode("utf-8"),
            headers={"Content-Type": "application/json"},
        )
        with urllib.request.urlopen(val_req, context=ctx, timeout=6) as resp:
            val_res = json.loads(resp.read().decode("utf-8"))
        return _result("ok", f"SMA at {host} is online.", val_res)
    except (urllib.error.URLError, socket.timeout):
        return _result(
            "offline",
            f"Unable to reach SMA at {host} (standby / night sleep or network).",
        )
    except Exception as exc:
        return _result("fail", f"SMA probe error: {exc}")
    finally:
        if sid:
            try:
                out_req = urllib.request.Request(
                    f"https://{host}/dyn/logout.json?sid={sid}",
                    data=b"{}",
                    headers={"Content-Type": "application/json"},
                )
                with urllib.request.urlopen(out_req, context=ctx, timeout=3):
                    pass
            except Exception:
                pass


def probe_webbox(ip: str, password: str) -> ProbeResult:
    """Sunny WebBox GetPlantOverview RPC."""
    host = (ip or "").strip()
    if not host:
        return _result("skipped", "Sunny WebBox address is empty.")
    if not password:
        return _result("fail", "Sunny WebBox password is missing.")

    md5_pass = hashlib.md5(password.encode("utf-8")).hexdigest()

    def rpc_call(proc: str, params: Optional[Any] = None) -> Dict[str, Any]:
        payload: Dict[str, Any] = {
            "version": "1.0",
            "proc": proc,
            "id": "1",
            "format": "JSON",
            "passwd": md5_pass,
        }
        if params is not None:
            payload["params"] = params
        post_data = ("RPC=" + json.dumps(payload)).encode("utf-8")
        req = urllib.request.Request(
            f"http://{host}/rpc",
            data=post_data,
            headers={
                "Content-Type": "application/x-www-form-urlencoded",
                "Accept": "application/json",
            },
        )
        with urllib.request.urlopen(req, timeout=5) as resp:
            return json.loads(resp.read().decode("utf-8"))

    try:
        plant = rpc_call("GetPlantOverview")
        if "error" in plant:
            err = plant.get("error") or {}
            msg = err.get("message") if isinstance(err, dict) else str(err)
            return _result("fail", f"WebBox authentication failed: {msg}")
        devices_res = rpc_call("GetDevices")
        return _result(
            "ok",
            f"Sunny WebBox at {host} is online.",
            {"plant": plant, "devices": devices_res},
        )
    except (urllib.error.URLError, socket.timeout):
        return _result(
            "offline",
            f"Unable to reach Sunny WebBox at {host} (power / network).",
        )
    except Exception as exc:
        return _result("fail", f"WebBox probe error: {exc}")


def probe_sunnyportal(
    user: str,
    password: str,
    plant_id: str,
    timezone_name: str = "",
) -> ProbeResult:
    """Login-only check against ennexOS (no channel discovery)."""
    u = (user or "").strip()
    p = (password or "").strip()
    plant = (plant_id or "").strip()
    if not u and not p and not plant:
        return _result("skipped", "Sunny Portal is empty.")
    if not u or not p or not plant:
        return _result(
            "fail",
            "Sunny Portal needs user, password, and plant id together.",
        )
    try:
        # Local import keeps powertests / Settings free of portal pull side effects.
        import sunnyportal_pull

        sunnyportal_pull.login_and_token(plant, u, p)
        zone = (timezone_name or "").strip() or "Europe/Paris"
        return _result(
            "ok",
            f"Sunny Portal login OK (plant {plant}, zone {zone}).",
        )
    except SystemExit as exc:
        return _result("fail", str(exc) or "Sunny Portal login failed.")
    except (urllib.error.URLError, socket.timeout) as exc:
        return _result("offline", f"Sunny Portal unreachable: {exc}")
    except Exception as exc:
        return _result("fail", f"Sunny Portal probe error: {exc}")


def probe_selected(settings: Dict[str, str], names: List[str]) -> Dict[str, ProbeResult]:
    """Run named probes against a settings dict (resolved passwords)."""
    out: Dict[str, ProbeResult] = {}
    for name in names:
        if name == "p1":
            out["p1"] = probe_p1(settings.get("p1_ip", ""))
        elif name == "sma":
            out["sma"] = probe_sma(
                settings.get("sma_ip", ""),
                settings.get("sma_user", "") or "usr",
                settings.get("sma_password", ""),
            )
        elif name == "sunnywebbox":
            out["sunnywebbox"] = probe_webbox(
                settings.get("sunnywebbox_ip", ""),
                settings.get("sunnywebbox_password", ""),
            )
        elif name == "sunnyportal":
            out["sunnyportal"] = probe_sunnyportal(
                settings.get("sunnyportal_user", ""),
                settings.get("sunnyportal_password", ""),
                settings.get("sunnyportal_plant_id", ""),
                settings.get("sunnyportal_timezone", ""),
            )
        else:
            out[name] = _result("fail", f"Unknown integration: {name}")
    return out


def main() -> None:
    """CLI: JSON object on stdin -> JSON probe results on stdout.

    Input: {"probe": ["p1","sma",...], "settings": { ... }}
    """
    raw = sys.stdin.read()
    try:
        payload = json.loads(raw) if raw.strip() else {}
    except json.JSONDecodeError:
        print(json.dumps({"error": "Invalid JSON on stdin."}))
        raise SystemExit(2)
    names = payload.get("probe") or []
    settings = payload.get("settings") or {}
    if not isinstance(names, list) or not isinstance(settings, dict):
        print(json.dumps({"error": "Expected probe list and settings object."}))
        raise SystemExit(2)
    # Strip nested data from responses for a smaller PHP payload.
    results = probe_selected(
        {str(k): "" if v is None else str(v) for k, v in settings.items()},
        [str(n) for n in names],
    )
    slim = {
        key: {"status": val.get("status"), "message": val.get("message")}
        for key, val in results.items()
    }
    print(json.dumps(slim))


if __name__ == "__main__":
    main()
