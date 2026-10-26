#!/usr/bin/env python3
# --- file: ennexos_csv_test.py ---
"""Pull one day of ennexOS PV power the way the chart page does.

This is a probe, not the warehouse importer. It logs in with the portal
password over HTTP (no browser package) and POSTs
https://uiapi.sunnyportal.com/api/v1/measurements/search.

Needs Python 3.9+ and nothing from pip. Playwright does not publish a
build for the Pi, which is why `pip install playwright` fails there.

Credentials come from .env (ENNEXOS_USER, ENNEXOS_PASSWORD, ENNEXOS_PLANT_ID).
The password is never printed.

Usage:
  python3 ennexos_csv_test.py --date 2026-10-06
  python3 ennexos_csv_test.py --date 2026-10-06 --compare /path/to/day.csv
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import http.cookiejar
import json
import secrets
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from html.parser import HTMLParser
from pathlib import Path
from typing import Dict, List, Optional, Tuple
from zoneinfo import ZoneInfo

SEARCH_URL = "https://uiapi.sunnyportal.com/api/v1/measurements/search"
AUTH_URL = "https://login.sma.energy/auth/realms/SMA/protocol/openid-connect/auth"
TOKEN_URL = "https://login.sma.energy/auth/realms/SMA/protocol/openid-connect/token"
PORTAL_ORIGIN = "https://ennexos.sunnyportal.com"
CLIENT_ID = "SPpbeOS"
PV_CHANNEL = "Measurement.GridMs.TotW.Pv"
USER_AGENT = "Mozilla/5.0"
ROOT = Path(__file__).resolve().parent
OUT_DIR = ROOT / "helpers" / "ennexos_out"


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """Return 3xx responses so the login code in the Location header is kept."""

    def http_error_302(self, req, fp, code, msg, headers):  # type: ignore[override]
        fp.status = code
        return fp

    http_error_301 = http_error_303 = http_error_307 = http_error_308 = http_error_302


class _FormParser(HTMLParser):
    """First login form: action plus named inputs, skipping buttons."""

    def __init__(self) -> None:
        super().__init__()
        self.action = ""
        self.fields: Dict[str, str] = {}
        self._in_form = False

    def handle_starttag(self, tag: str, attrs: List[Tuple[str, Optional[str]]]) -> None:
        data = dict(attrs)
        if tag == "form" and not self._in_form:
            self.action = data.get("action") or ""
            self._in_form = True
            return
        if tag != "input" or not self._in_form:
            return
        name = data.get("name")
        kind = (data.get("type") or "").lower()
        if not name or kind in ("submit", "button", "checkbox", "image"):
            return
        self.fields[name] = data.get("value") or ""


def load_env() -> Dict[str, str]:
    """Read the first .env that exists. Same shape as logger.load_env."""
    candidates = [ROOT / ".env", Path.cwd() / ".env"]
    env_path = next((path for path in candidates if path.is_file()), None)
    if env_path is None:
        raise SystemExit(
            "No .env next to this script or in the current directory. "
            "Set ENNEXOS_USER, ENNEXOS_PASSWORD, ENNEXOS_PLANT_ID."
        )
    config: Dict[str, str] = {}
    for line in env_path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        config[key.strip()] = value.strip().strip("'\"")
    return config


def require(config: Dict[str, str], key: str) -> str:
    value = config.get(key, "").strip()
    if not value:
        raise SystemExit(f"{key} is empty in .env.")
    return value


def utc_bounds(day: str, zone_name: str) -> Tuple[str, str]:
    """Local calendar day as the UTC stamps the portal sends."""
    zone = ZoneInfo(zone_name)
    start = datetime.fromisoformat(day + "T00:00:00").replace(tzinfo=zone)
    end = start + timedelta(days=1)
    fmt = "%Y-%m-%dT%H:%M:%S.000Z"
    return (
        start.astimezone(timezone.utc).strftime(fmt),
        end.astimezone(timezone.utc).strftime(fmt),
    )


def _opener() -> urllib.request.OpenerDirector:
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar),
        _NoRedirect(),
    )


def _open(opener: urllib.request.OpenerDirector, request: urllib.request.Request):
    request.add_header("User-Agent", USER_AGENT)
    try:
        return opener.open(request, timeout=60)
    except urllib.error.HTTPError as exc:
        if exc.code in (301, 302, 303, 307, 308):
            return exc
        raise


def _follow(opener: urllib.request.OpenerDirector, request: urllib.request.Request):
    """Follow redirects. Stop when the Location URL carries an OAuth code."""
    current = request
    for _ in range(12):
        response = _open(opener, current)
        status = getattr(response, "status", None) or response.getcode()
        if status in (301, 302, 303, 307, 308):
            location = response.headers.get("Location")
            if not location:
                raise SystemExit("Login redirect had no Location header.")
            next_url = urllib.parse.urljoin(response.geturl(), location)
            query = urllib.parse.parse_qs(urllib.parse.urlparse(next_url).query)
            code = (query.get("code") or [None])[0]
            if code:
                return code
            method = "GET" if status in (301, 302, 303) else current.get_method()
            current = urllib.request.Request(next_url, method=method)
            continue
        body = response.read()
        return status, response.geturl(), body
    raise SystemExit("Login exceeded 12 redirects.")


def login_and_token(plant_id: str, user: str, password: str) -> str:
    """SMA ID authorization-code + PKCE, same public client the website uses."""
    verifier = secrets.token_urlsafe(48)
    challenge = base64.urlsafe_b64encode(
        hashlib.sha256(verifier.encode("ascii")).digest()
    ).decode("ascii").rstrip("=")
    redirect_uri = f"{PORTAL_ORIGIN}/{plant_id}/monitoring/view-energy-and-power"
    auth_query = urllib.parse.urlencode(
        {
            "response_type": "code",
            "client_id": CLIENT_ID,
            "redirect_uri": redirect_uri,
            "scope": "openid profile",
            "state": secrets.token_urlsafe(18),
            "nonce": secrets.token_urlsafe(18),
            "code_challenge": challenge,
            "code_challenge_method": "S256",
        }
    )
    opener = _opener()
    landed = _follow(opener, urllib.request.Request(AUTH_URL + "?" + auth_query))
    if isinstance(landed, str):
        raise SystemExit("Auth redirected to a code before the password was sent.")
    status, page_url, html_bytes = landed
    html = html_bytes.decode("utf-8", "replace")
    parser = _FormParser()
    parser.feed(html)
    if not parser.action:
        raise SystemExit(f"No login form on the auth page (HTTP {status}).")

    parser.fields["username"] = user
    parser.fields["password"] = password
    parser.fields.setdefault("credentialId", "")
    post_url = urllib.parse.urljoin(page_url, parser.action)
    posted = _follow(
        opener,
        urllib.request.Request(
            post_url,
            data=urllib.parse.urlencode(parser.fields).encode("utf-8"),
            method="POST",
            headers={"Content-Type": "application/x-www-form-urlencoded"},
        ),
    )
    if not isinstance(posted, str):
        text = " ".join(posted[2].decode("utf-8", "replace").split())
        if "invalid username or password" in text.lower():
            raise SystemExit("SMA ID rejected the username or password.")
        raise SystemExit(f"Login did not return a code (HTTP {posted[0]}). {text[:240]}")

    token_body = urllib.parse.urlencode(
        {
            "grant_type": "authorization_code",
            "code": posted,
            "redirect_uri": redirect_uri,
            "client_id": CLIENT_ID,
            "code_verifier": verifier,
        }
    ).encode("utf-8")
    token_request = urllib.request.Request(
        TOKEN_URL,
        data=token_body,
        method="POST",
        headers={
            "Content-Type": "application/x-www-form-urlencoded",
            "Accept": "application/json",
            "User-Agent": USER_AGENT,
        },
    )
    try:
        with opener.open(token_request, timeout=60) as response:
            raw = response.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", "replace")[:400]
        raise SystemExit(f"Token request HTTP {exc.code}: {detail}") from exc
    payload = json.loads(raw)
    token = payload.get("access_token")
    if not token:
        error = payload.get("error_description") or payload.get("error") or "no access_token"
        raise SystemExit(f"Token response had no access_token: {error}")
    return str(token)


class EnnexosHttpError(RuntimeError):
    """measurements/search failed. code is the HTTP status."""

    def __init__(self, code: int, detail: str) -> None:
        super().__init__(f"measurements/search HTTP {code}: {detail}")
        self.code = code


def fetch_day(token: str, plant_id: str, day: str, zone_name: str, quiet: bool = False) -> object:
    begin, end = utc_bounds(day, zone_name)
    body = {
        "dateTimeBegin": begin,
        "dateTimeEnd": end,
        "queryItems": [
            {
                "componentId": plant_id,
                "channelId": PV_CHANNEL,
                "resolution": "FiveMinutes",
                "aggregate": "Avg",
                "timezone": zone_name,
                "allowDataReduction": False,
            }
        ],
    }
    request = urllib.request.Request(
        SEARCH_URL,
        data=json.dumps(body).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {token}",
            "Accept": "application/json",
            "Content-Type": "application/json",
            "Origin": PORTAL_ORIGIN,
            "Referer": PORTAL_ORIGIN + "/",
            "User-Agent": USER_AGENT,
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            payload = response.read().decode("utf-8", "replace")
            status = response.status
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", "replace")[:800]
        raise EnnexosHttpError(exc.code, detail) from exc
    if not quiet:
        print(f"search HTTP {status} window {begin} -> {end}")
    return json.loads(payload)


def _points(payload: object) -> List[Tuple[datetime, Optional[float]]]:
    rows = payload if isinstance(payload, list) else []
    points: List[Tuple[datetime, Optional[float]]] = []
    for row in rows:
        if not isinstance(row, dict):
            continue
        values = row.get("values")
        if not isinstance(values, list):
            continue
        for item in values:
            if not isinstance(item, dict) or "time" not in item:
                continue
            stamp = datetime.fromisoformat(str(item["time"]).replace("Z", "+00:00"))
            if stamp.tzinfo is None:
                stamp = stamp.replace(tzinfo=timezone.utc)
            raw = item.get("value")
            points.append((stamp, None if raw is None else float(raw)))
    points.sort(key=lambda pair: pair[0])
    return points


def _parse_csv_day(path: Path) -> Tuple[str, Dict[str, float]]:
    """Map 'HH:MM' end-of-bucket labels to watts. Day comes from the filename."""
    import csv
    import re

    match = re.search(r"(\d{4}-\d{2}-\d{2})", path.name)
    if not match:
        raise SystemExit(f"CSV filename has no YYYY-MM-DD: {path.name}")
    day = match.group(1)
    watts: Dict[str, float] = {}
    with path.open(encoding="utf-8-sig", newline="") as handle:
        reader = csv.reader(handle, delimiter=";")
        started = False
        for row in reader:
            if not row:
                continue
            if row[0].strip().strip('"') == "Time period":
                started = True
                continue
            if not started or len(row) < 2:
                continue
            label = row[0].strip().strip('"').replace(".", ":")
            stamp = datetime.strptime(label, "%I:%M %p")
            number = row[1].strip().strip('"').replace(",", "")
            watts[stamp.strftime("%H:%M")] = float(number)
    return day, watts


def compare(payload: object, csv_path: Path, zone_name: str) -> None:
    csv_day, csv_watts = _parse_csv_day(csv_path)
    zone = ZoneInfo(zone_name)
    api = _points(payload)
    print(f"csv {csv_path.name}: {len(csv_watts)} stamps, day {csv_day}")
    print(f"api points: {len(api)}")
    if not api:
        print("api returned no points; compare skipped")
        return

    def score(shift_minutes: int) -> Tuple[int, int]:
        matched = 0
        missed = 0
        for stamp, value in api:
            if value is None:
                continue
            local = stamp.astimezone(zone) + timedelta(minutes=shift_minutes)
            if local.strftime("%Y-%m-%d") != csv_day:
                continue
            key = local.strftime("%H:%M")
            if key not in csv_watts:
                missed += 1
                continue
            if abs(csv_watts[key] - value) <= 1.0:
                matched += 1
            else:
                missed += 1
        return matched, missed

    for shift, label in ((0, "api stamp is the bucket start"), (5, "api stamp is the bucket end")):
        matched, missed = score(shift)
        print(f"align {label}: matched {matched}, off-or-missing {missed}")


def main() -> None:
    parser = argparse.ArgumentParser(description="Probe one ennexOS day of PV power.")
    parser.add_argument("--date", required=True, help="Local day YYYY-MM-DD")
    parser.add_argument("--compare", help="Optional path to a Day CSV from the chart button")
    args = parser.parse_args()
    datetime.strptime(args.date, "%Y-%m-%d")

    config = load_env()
    user = require(config, "ENNEXOS_USER")
    password = require(config, "ENNEXOS_PASSWORD")
    plant_id = require(config, "ENNEXOS_PLANT_ID")
    zone_name = config.get("ENNEXOS_TIMEZONE", "Europe/Paris").strip() or "Europe/Paris"

    print(f"login plant {plant_id} day {args.date} zone {zone_name}")
    token = login_and_token(plant_id, user, password)
    try:
        payload = fetch_day(token, plant_id, args.date, zone_name)
    except EnnexosHttpError as exc:
        raise SystemExit(str(exc)) from exc
    points = _points(payload)
    print(f"points {len(points)}")
    if points:
        first, last = points[0][0], points[-1][0]
        print(f"first {first.isoformat()} last {last.isoformat()}")

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    out_path = OUT_DIR / f"pv-{args.date}.json"
    out_path.write_text(json.dumps(payload, indent=2), encoding="utf-8")
    print(f"wrote {out_path}")

    if args.compare:
        compare(payload, Path(args.compare), zone_name)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(130)
