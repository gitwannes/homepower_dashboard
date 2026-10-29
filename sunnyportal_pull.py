#!/usr/bin/env python3
# --- file: sunnyportal_pull.py ---
"""Sunny Portal (ennexOS) PV pull into meter_history.db staging.

Credentials come from the Settings page in meter_history.db (app_settings).
HP_DB_FILE overrides the database path. Needs Python 3.9+. No pip packages.

  python3 sunnyportal_pull.py                 # usage only
  python3 sunnyportal_pull.py -pullall        # all days the portal returns
  python3 sunnyportal_pull.py -lastdays -n 7  # last N days including today
  python3 sunnyportal_pull.py -probe          # list channels (console)

Writes sunnyportal_pv_5min only. Does not write history_5min; rollup
-buildall / -daily empty-fill solar_power_w from this table.

Pulls Measurement.GridMs.TotW.Pv. Stamp: API UTC -> Settings timezone;
clock is bucket end; interval_start is five minutes earlier.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import http.cookiejar
import json
import secrets
import sqlite3
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, datetime, timedelta, timezone
from html.parser import HTMLParser
from typing import Dict, List, Optional, Tuple
from zoneinfo import ZoneInfo

import site_config

SEARCH_URL = "https://uiapi.sunnyportal.com/api/v1/measurements/search"
AUTH_URL = "https://login.sma.energy/auth/realms/SMA/protocol/openid-connect/auth"
TOKEN_URL = "https://login.sma.energy/auth/realms/SMA/protocol/openid-connect/token"
PORTAL_ORIGIN = "https://ennexos.sunnyportal.com"
CLIENT_ID = "SPpbeOS"
PV_CHANNEL = "Measurement.GridMs.TotW.Pv"
USER_AGENT = "Mozilla/5.0"
BUCKET = timedelta(minutes=5)

# Candidate channel ids for -probe (unpaid UI search). Not a warehouse map.
PROBE_CHANNELS = [
    "Measurement.GridMs.TotW.Pv",
    "Measurement.GridMs.TotW",
    "Measurement.Metering.GridMs.TotWIn",
    "Measurement.Metering.GridMs.TotWOut",
    "Measurement.Metering.TotWIn",
    "Measurement.Metering.TotWOut",
    "Measurement.Metering.GridMs.TotW.Pv",
    "Measurement.Bat.ChaStt",
    "Measurement.Bat.Amp",
    "Measurement.Bat.TotW",
    "Measurement.DcMs.Watt",
    "Measurement.Isolation.LeakRis",
]


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


class SunnyportalHttpError(RuntimeError):
    """measurements/search failed. code is the HTTP status."""

    def __init__(self, code: int, detail: str) -> None:
        super().__init__(f"measurements/search HTTP {code}: {detail}")
        self.code = code


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


def fetch_day(
    token: str,
    plant_id: str,
    day: str,
    zone_name: str,
    channel_id: str = PV_CHANNEL,
    quiet: bool = False,
) -> object:
    """POST measurements/search for one local day."""
    begin, end = utc_bounds(day, zone_name)
    body = {
        "dateTimeBegin": begin,
        "dateTimeEnd": end,
        "queryItems": [
            {
                "componentId": plant_id,
                "channelId": channel_id,
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
        raise SunnyportalHttpError(exc.code, detail) from exc
    if not quiet:
        print(f"search HTTP {status} window {begin} -> {end}")
    return json.loads(payload)


def open_live_db() -> Tuple[sqlite3.Connection, str]:
    """Open meter_history.db with WAL and busy timeout (concurrent with logger)."""
    path = site_config.resolve_db_path()
    conn = sqlite3.connect(path, timeout=30.0)
    conn.execute("PRAGMA busy_timeout = 10000")
    conn.execute("PRAGMA journal_mode = WAL")
    ensure_staging_schema(conn)
    return conn, path


def ensure_staging_schema(conn: sqlite3.Connection) -> None:
    """Never-purged Sunny Portal staging + pull resume table."""
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS sunnyportal_pv_5min (
            component_id TEXT NOT NULL,
            interval_start TEXT NOT NULL,
            interval_end TEXT NOT NULL,
            power_w REAL,
            PRIMARY KEY (component_id, interval_start)
        )
        """
    )
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS sunnyportal_pull_day (
            day TEXT PRIMARY KEY,
            fetched_at TEXT NOT NULL,
            points INTEGER NOT NULL
        )
        """
    )
    conn.commit()


def _local_pair(raw_time: str, zone: ZoneInfo) -> Tuple[datetime, datetime]:
    """Return (interval_start, interval_end) in the plant zone."""
    end = datetime.fromisoformat(raw_time.replace("Z", "+00:00")).astimezone(zone)
    return end - BUCKET, end


def store_day(
    conn: sqlite3.Connection,
    payload: object,
    zone_name: str,
    day: date,
    only_components: Optional[List[str]],
) -> Tuple[int, Dict[str, int]]:
    """Replace this local day's rows. Points that belong to another day are ignored."""
    if not isinstance(payload, list):
        raise RuntimeError("measurements/search did not return a list")
    zone = ZoneInfo(zone_name)
    allowed = set(only_components) if only_components else None
    day_text = day.isoformat()
    next_text = (day + timedelta(days=1)).isoformat()
    start_key = day_text + " 00:00:00"
    end_key = next_text + " 00:00:00"
    if allowed:
        placeholders = ",".join("?" for _ in allowed)
        conn.execute(
            f"""
            DELETE FROM sunnyportal_pv_5min
            WHERE interval_start >= ? AND interval_start < ?
              AND component_id IN ({placeholders})
            """,
            (start_key, end_key, *sorted(allowed)),
        )
    else:
        conn.execute(
            "DELETE FROM sunnyportal_pv_5min WHERE interval_start >= ? AND interval_start < ?",
            (start_key, end_key),
        )
    counts: Dict[str, int] = {}
    batch: List[Tuple[str, str, str, Optional[float]]] = []
    for row in payload:
        if not isinstance(row, dict):
            continue
        component = str(row.get("componentId") or "")
        if allowed is not None and component not in allowed:
            continue
        values = row.get("values") or []
        if not isinstance(values, list):
            continue
        for item in values:
            if not isinstance(item, dict) or not item.get("time"):
                continue
            start, end = _local_pair(str(item["time"]), zone)
            if start.strftime("%Y-%m-%d") != day_text:
                continue
            raw_power = item.get("value")
            power = None if raw_power is None else float(raw_power)
            batch.append(
                (
                    component,
                    start.strftime("%Y-%m-%d %H:%M:%S"),
                    end.strftime("%Y-%m-%d %H:%M:%S"),
                    power,
                )
            )
            counts[component] = counts.get(component, 0) + 1
    conn.executemany(
        """
        INSERT OR REPLACE INTO sunnyportal_pv_5min
            (component_id, interval_start, interval_end, power_w)
        VALUES (?, ?, ?, ?)
        """,
        batch,
    )
    conn.execute(
        """
        INSERT OR REPLACE INTO sunnyportal_pull_day (day, fetched_at, points)
        VALUES (?, ?, ?)
        """,
        (
            day_text,
            datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            len(batch),
        ),
    )
    conn.commit()
    return len(batch), counts


def fetch_stored(
    conn: sqlite3.Connection,
    token_box: Dict[str, str],
    user: str,
    password: str,
    plant_id: str,
    zone_name: str,
    day: date,
    only_components: Optional[List[str]],
) -> int:
    """Fetch one day, re-login once on HTTP 401, then commit it."""
    try:
        payload = fetch_day(
            token_box["token"], plant_id, day.isoformat(), zone_name, quiet=True
        )
    except SunnyportalHttpError as exc:
        if exc.code != 401:
            raise
        print("token expired, logging in again")
        token_box["token"] = login_and_token(plant_id, user, password)
        payload = fetch_day(
            token_box["token"], plant_id, day.isoformat(), zone_name, quiet=True
        )
    count, per_component = store_day(conn, payload, zone_name, day, only_components)
    detail = " ".join(f"{key}:{value}" for key, value in sorted(per_component.items()))
    print(f"{day.isoformat()}  {count} pts  {detail}".rstrip())
    return count


def find_oldest(
    conn: sqlite3.Connection,
    token_box: Dict[str, str],
    user: str,
    password: str,
    plant_id: str,
    zone_name: str,
    day_to: date,
    pause_s: float,
    only_components: Optional[List[str]],
) -> date:
    """Earliest local day that returns samples. Assumes history is one contiguous span."""
    def probe(day: date) -> bool:
        count = fetch_stored(
            conn, token_box, user, password, plant_id, zone_name, day, only_components
        )
        time.sleep(pause_s)
        return count > 0

    if not probe(day_to):
        raise SystemExit(f"No PV samples on {day_to.isoformat()}. Nothing to pull.")
    floor = date(2000, 1, 1)
    print(f"probe {floor.isoformat()}")
    if probe(floor):
        print("samples exist on 2000-01-01; that is the start")
        return floor
    lo = floor
    hi = day_to
    while (hi - lo).days > 1:
        mid = lo + timedelta(days=(hi - lo).days // 2)
        print(f"probe {mid.isoformat()}")
        if probe(mid):
            hi = mid
        else:
            lo = mid
    print(f"oldest day with samples: {hi.isoformat()}")
    return hi


def summarize(conn: sqlite3.Connection, db_path: str) -> None:
    print(f"db {db_path}")
    rows = conn.execute(
        """
        SELECT component_id, COUNT(*), MIN(interval_start), MAX(interval_start)
        FROM sunnyportal_pv_5min
        GROUP BY component_id
        ORDER BY component_id
        """
    ).fetchall()
    if not rows:
        print("no rows stored")
        return
    for component_id, count, first, last in rows:
        print(f"component {component_id}  {count} rows  {first} -> {last}")
    days = conn.execute("SELECT COUNT(*) FROM sunnyportal_pull_day").fetchone()
    print(f"days recorded {days[0]}")


def _summarize_payload(payload: object) -> List[Tuple[str, int, Optional[float], Optional[float]]]:
    """Per component: (component_id, point_count, min_value, max_value)."""
    out: List[Tuple[str, int, Optional[float], Optional[float]]] = []
    if not isinstance(payload, list):
        return out
    for row in payload:
        if not isinstance(row, dict):
            continue
        component = str(row.get("componentId") or "")
        values = row.get("values")
        if not isinstance(values, list) or not values:
            out.append((component, 0, None, None))
            continue
        nums: List[float] = []
        for item in values:
            if not isinstance(item, dict) or item.get("value") is None:
                continue
            nums.append(float(item["value"]))
        if not nums:
            out.append((component, len(values), None, None))
        else:
            out.append((component, len(values), min(nums), max(nums)))
    return out


def run_probe(day: date, pause_s: float) -> None:
    """Try candidate channel ids; print which return samples (console only)."""
    login = site_config.sunnyportal_login()
    user = login["user"]
    password = login["password"]
    plant_id = login["plant_id"]
    zone_name = login["timezone"]
    day_text = day.isoformat()
    print(f"probe plant {plant_id} day {day_text} zone {zone_name}")
    print("login")
    token = login_and_token(plant_id, user, password)
    hits = 0
    for channel in PROBE_CHANNELS:
        try:
            payload = fetch_day(token, plant_id, day_text, zone_name, channel, quiet=True)
        except SunnyportalHttpError as exc:
            if exc.code == 401:
                print("token expired, logging in again")
                token = login_and_token(plant_id, user, password)
                try:
                    payload = fetch_day(
                        token, plant_id, day_text, zone_name, channel, quiet=True
                    )
                except SunnyportalHttpError as exc2:
                    print(f"{channel}  HTTP {exc2.code}")
                    time.sleep(pause_s)
                    continue
            else:
                print(f"{channel}  HTTP {exc.code}")
                time.sleep(pause_s)
                continue
        rows = _summarize_payload(payload)
        with_values = [row for row in rows if row[1] > 0]
        if not with_values:
            print(f"{channel}  no samples")
        else:
            hits += 1
            for component, count, lo, hi in with_values:
                lo_txt = "-" if lo is None else f"{lo:g}"
                hi_txt = "-" if hi is None else f"{hi:g}"
                print(
                    f"{channel}  component {component}  {count} pts  "
                    f"min {lo_txt}  max {hi_txt}"
                )
        time.sleep(pause_s)
    print(f"channels with samples: {hits} / {len(PROBE_CHANNELS)}")


def pull_range(
    day_from: date,
    day_to: date,
    pause_s: float,
    force: bool,
    only_components: Optional[List[str]],
    search_oldest: bool,
) -> None:
    """Pull inclusive local day range into sunnyportal_pv_5min on the live DB."""
    login = site_config.sunnyportal_login()
    user = login["user"]
    password = login["password"]
    plant_id = login["plant_id"]
    zone_name = login["timezone"]
    conn, db_path = open_live_db()
    print(f"login db {db_path}")
    token_box = {"token": login_and_token(plant_id, user, password)}
    try:
        if search_oldest:
            day_from = find_oldest(
                conn,
                token_box,
                user,
                password,
                plant_id,
                zone_name,
                day_to,
                pause_s,
                only_components,
            )
        if day_from > day_to:
            raise SystemExit(
                f"from {day_from.isoformat()} is after to {day_to.isoformat()}."
            )
        done = set()
        if not force:
            done = {row[0] for row in conn.execute("SELECT day FROM sunnyportal_pull_day")}
        print(f"pull {day_from.isoformat()} -> {day_to.isoformat()}")
        day = day_from
        while day <= day_to:
            if day.isoformat() in done:
                day += timedelta(days=1)
                continue
            try:
                fetch_stored(
                    conn,
                    token_box,
                    user,
                    password,
                    plant_id,
                    zone_name,
                    day,
                    only_components,
                )
            except SunnyportalHttpError as exc:
                raise SystemExit(str(exc)) from exc
            time.sleep(pause_s)
            day += timedelta(days=1)
        summarize(conn, db_path)
    finally:
        conn.close()


def preferred_component_id(conn: sqlite3.Connection) -> Optional[str]:
    """Component with the most staging rows (system curve over empty plant-id rows)."""
    row = conn.execute(
        """
        SELECT component_id FROM sunnyportal_pv_5min
        GROUP BY component_id
        ORDER BY COUNT(*) DESC, component_id
        LIMIT 1
        """
    ).fetchone()
    return None if row is None else str(row[0])


def stitch_solar_power(
    conn: sqlite3.Connection,
    day_lo: Optional[str] = None,
    day_hi: Optional[str] = None,
) -> Dict[str, object]:
    """Empty-fill history_5min.solar_power_w from sunnyportal_pv_5min.

    None span means the full staging table. Does not overwrite non-null solar.
    New rows get data_source sunnyportal; existing rows keep their data_source.

    Returns a summary dict:
      component, staging_rows, inserted, updated, skipped, written,
      first, last  (first/last are among slots written this run)
    """
    empty: Dict[str, object] = {
        "component": None,
        "staging_rows": 0,
        "inserted": 0,
        "updated": 0,
        "skipped": 0,
        "written": 0,
        "first": None,
        "last": None,
    }
    ensure_staging_schema(conn)
    tables = {
        str(row[0])
        for row in conn.execute(
            "SELECT name FROM sqlite_master WHERE type = 'table'"
        )
    }
    if "history_5min" not in tables or "sunnyportal_pv_5min" not in tables:
        return empty
    component = preferred_component_id(conn)
    if component is None:
        return empty
    where = "component_id = ?"
    params: List[str] = [component]
    if day_lo is not None:
        where += " AND interval_start >= ?"
        params.append(day_lo + " 00:00:00")
    if day_hi is not None:
        end = datetime.strptime(day_hi, "%Y-%m-%d") + timedelta(days=1)
        where += " AND interval_start < ?"
        params.append(end.strftime("%Y-%m-%d %H:%M:%S"))
    rows = conn.execute(
        f"""
        SELECT interval_start, power_w
        FROM sunnyportal_pv_5min
        WHERE {where}
        ORDER BY interval_start
        """,
        params,
    ).fetchall()
    inserted = 0
    updated = 0
    skipped = 0
    first: Optional[str] = None
    last: Optional[str] = None
    with conn:
        for stamp, power in rows:
            stamp_s = str(stamp)
            existing = conn.execute(
                "SELECT solar_power_w FROM history_5min WHERE timestamp = ?",
                (stamp_s,),
            ).fetchone()
            if existing is None:
                conn.execute(
                    """
                    INSERT INTO history_5min (timestamp, solar_power_w, data_source)
                    VALUES (?, ?, 'sunnyportal')
                    """,
                    (stamp_s, power),
                )
                inserted += 1
                if first is None:
                    first = stamp_s
                last = stamp_s
                continue
            if existing[0] is not None:
                skipped += 1
                continue
            conn.execute(
                """
                UPDATE history_5min
                SET solar_power_w = ?
                WHERE timestamp = ? AND solar_power_w IS NULL
                """,
                (power, stamp_s),
            )
            updated += 1
            if first is None:
                first = stamp_s
            last = stamp_s
    written = inserted + updated
    return {
        "component": component,
        "staging_rows": len(rows),
        "inserted": inserted,
        "updated": updated,
        "skipped": skipped,
        "written": written,
        "first": first,
        "last": last,
    }


def print_stitch_summary(
    result: Dict[str, object],
    span_label: str = "",
    step: Optional[int] = None,
) -> None:
    """ASCII console summary after a sunnyportal -> history_5min merge.

    Silent when staging is empty (nothing to empty-fill). Optional step prefixes
    the first line as '-> N: ...' for -daily numbering.
    """
    staging_rows = int(result.get("staging_rows", 0) or 0)
    written = int(result.get("written", 0) or 0)
    if staging_rows == 0 and written == 0:
        return
    suffix = f" ({span_label})" if span_label else ""
    title = f"sunnyportal -> history_5min{suffix}"
    if step is not None:
        print(f"-> {step}: {title}")
    else:
        print(title)
    print(f"  component     {result.get('component') or '-'}")
    print(f"  staging rows  {staging_rows}")
    print(f"  5-min written {written}  "
          f"(inserted {result.get('inserted', 0)}, "
          f"updated {result.get('updated', 0)}, "
          f"skipped {result.get('skipped', 0)})")
    print(f"  first written {result.get('first') or '-'}")
    print(f"  last written  {result.get('last') or '-'}")


def print_usage() -> None:
    print("Sunny Portal / ennexOS pull (staging into meter_history.db)")
    print("Usage:")
    print("  python3 sunnyportal_pull.py -pullall")
    print("  python3 sunnyportal_pull.py -lastdays -n N   (N = 1..366, includes today)")
    print("  python3 sunnyportal_pull.py -probe")
    print("Credentials: Settings page in meter_history.db. Does not write history_5min.")


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Sunny Portal pull into meter_history.db staging.",
        add_help=True,
    )
    parser.add_argument("-pullall", action="store_true", help="Pull all days the portal returns")
    parser.add_argument("-lastdays", action="store_true", help="Pull the last N days including today")
    parser.add_argument("-n", type=int, dest="days_n", help="Day count for -lastdays (1..366)")
    parser.add_argument("-probe", action="store_true", help="Probe candidate channels (console)")
    parser.add_argument(
        "--date",
        help="Local day YYYY-MM-DD for -probe (default: yesterday)",
    )
    parser.add_argument("--component", action="append", help="Keep only this component id")
    parser.add_argument("--sleep", type=float, default=0.25, help="Seconds between portal requests")
    parser.add_argument("--force", action="store_true", help="Request days again even if recorded")
    args = parser.parse_args()

    modes = int(args.pullall) + int(args.lastdays) + int(args.probe)
    if modes == 0:
        print_usage()
        return
    if modes > 1:
        raise SystemExit("Use only one of -pullall, -lastdays, or -probe.")

    login = site_config.sunnyportal_login()
    zone = ZoneInfo(login["timezone"])
    today = datetime.now(zone).date()
    yesterday = today - timedelta(days=1)

    if args.probe:
        probe_day = (
            datetime.strptime(args.date, "%Y-%m-%d").date() if args.date else yesterday
        )
        run_probe(probe_day, args.sleep)
        return

    if args.lastdays:
        if args.days_n is None:
            raise SystemExit("-lastdays requires -n N (1..366).")
        if args.days_n < 1 or args.days_n > 366:
            raise SystemExit("-n must be between 1 and 366.")
        day_to = today
        day_from = today - timedelta(days=args.days_n - 1)
        pull_range(day_from, day_to, args.sleep, args.force, args.component, False)
        return

    # -pullall: oldest -> today (inclusive)
    pull_range(today, today, args.sleep, args.force, args.component, True)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(130)
