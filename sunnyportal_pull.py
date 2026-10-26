#!/usr/bin/env python3
# --- file: sunnyportal_pull.py ---
"""One-shot pull of Sunny Portal PV power into a portable SQLite file.

Reads the Sunny Portal user, password, plant id, and timezone from the
Settings page (app_settings in meter_history.db; HP_DB_FILE when that file
is elsewhere). Writes samples only to sunnyportal_pv.sqlite. Does not merge
those rows into history_5min.

Pulls Measurement.GridMs.TotW.Pv, the channel that matched the Day CSV.
Every component that returns samples is stored. On 2026-10-06 the system
curve was component 13941800; the system-id row itself was empty.

Stamp rule: API time is UTC, the CSV clock is the Settings timezone
(default Europe/Paris), and that clock is the bucket end. interval_start
is five minutes earlier, which lines up with history_5min.

Today is skipped. Live PV stays on the P1 logger.

Needs Python 3.9+ and sunnyportal_csv_test.py beside this file. No pip packages.
Resume is safe: a day recorded in sunnyportal_pull_day is not requested again
unless --force is set.

Transfer, on the dashboard host, after the file has been copied next to
meter_history.db and this process has exited (one file, no -wal):

  sqlite3 meter_history.db
  ATTACH 'sunnyportal_pv.sqlite' AS src;
  CREATE TABLE IF NOT EXISTS sunnyportal_pv_5min (
      component_id TEXT NOT NULL,
      interval_start TEXT NOT NULL,
      interval_end TEXT NOT NULL,
      power_w REAL,
      PRIMARY KEY (component_id, interval_start)
  );
  INSERT OR REPLACE INTO sunnyportal_pv_5min
      SELECT component_id, interval_start, interval_end, power_w
      FROM src.sunnyportal_pv_5min;
  DETACH src;

Do not merge into history_5min from the pull machine. That merge has to
see the WebBox and P1 rows, which live only in the real database.
"""
from __future__ import annotations

import argparse
import sqlite3
import sys
import time
from datetime import date, datetime, timedelta, timezone
from typing import Dict, List, Optional, Tuple
from zoneinfo import ZoneInfo

import site_config
from sunnyportal_csv_test import SunnyportalHttpError, fetch_day, login_and_token

BUCKET = timedelta(minutes=5)


def init_db(path: str) -> sqlite3.Connection:
    """Open the portable file as a single copyable database (no WAL)."""
    conn = sqlite3.connect(path)
    conn.execute("PRAGMA journal_mode = DELETE")
    conn.execute("PRAGMA synchronous = FULL")
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
    return conn


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
        payload = fetch_day(token_box["token"], plant_id, day.isoformat(), zone_name, quiet=True)
    except SunnyportalHttpError as exc:
        if exc.code != 401:
            raise
        print("token expired, logging in again")
        token_box["token"] = login_and_token(plant_id, user, password)
        payload = fetch_day(token_box["token"], plant_id, day.isoformat(), zone_name, quiet=True)
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
    yesterday: date,
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

    if not probe(yesterday):
        raise SystemExit(f"No PV samples on {yesterday.isoformat()}. Nothing to pull.")
    floor = date(2000, 1, 1)
    print(f"probe {floor.isoformat()}")
    if probe(floor):
        print("samples exist on 2000-01-01; that is the start")
        return floor
    lo = floor
    hi = yesterday
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
    print(f"file {db_path}")
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


def main() -> None:
    parser = argparse.ArgumentParser(description="Pull all Sunny Portal PV power into a portable SQLite file.")
    parser.add_argument("--db", default="sunnyportal_pv.sqlite", help="Output SQLite file (default sunnyportal_pv.sqlite)")
    parser.add_argument("--from", dest="day_from", help="First local day YYYY-MM-DD (default: search)")
    parser.add_argument("--to", dest="day_to", help="Last local day YYYY-MM-DD (default: yesterday)")
    parser.add_argument("--component", action="append", help="Keep only this component id. Repeatable.")
    parser.add_argument("--sleep", type=float, default=0.25, help="Seconds between portal requests")
    parser.add_argument("--force", action="store_true", help="Request days again even if already recorded")
    args = parser.parse_args()

    login = site_config.sunnyportal_login()
    user = login["user"]
    password = login["password"]
    plant_id = login["plant_id"]
    zone_name = login["timezone"]
    zone = ZoneInfo(zone_name)
    yesterday = (datetime.now(zone) - timedelta(days=1)).date()
    day_to = datetime.strptime(args.day_to, "%Y-%m-%d").date() if args.day_to else yesterday
    if day_to > yesterday:
        raise SystemExit(f"--to {day_to.isoformat()} is not before today in {zone_name}. Today stays on the P1 logger.")

    conn = init_db(args.db)
    print("login")
    token_box = {"token": login_and_token(plant_id, user, password)}
    try:
        if args.day_from:
            day_from = datetime.strptime(args.day_from, "%Y-%m-%d").date()
        else:
            day_from = find_oldest(
                conn, token_box, user, password, plant_id, zone_name, day_to, args.sleep, args.component
            )
        if day_from > day_to:
            raise SystemExit(f"--from {day_from.isoformat()} is after --to {day_to.isoformat()}.")

        done = set()
        if not args.force:
            done = {row[0] for row in conn.execute("SELECT day FROM sunnyportal_pull_day")}
        print(f"pull {day_from.isoformat()} -> {day_to.isoformat()}")
        day = day_from
        while day <= day_to:
            if day.isoformat() in done:
                day += timedelta(days=1)
                continue
            try:
                fetch_stored(
                    conn, token_box, user, password, plant_id, zone_name, day, args.component
                )
            except SunnyportalHttpError as exc:
                raise SystemExit(str(exc)) from exc
            time.sleep(args.sleep)
            day += timedelta(days=1)
        summarize(conn, args.db)
    finally:
        conn.close()
    print("copy this file to the dashboard host, then ATTACH it. SQL is in the script header.")


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(130)
