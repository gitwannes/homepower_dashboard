#!/usr/bin/env python3
# --- file: fluvius_import.py ---
"""Fluvius CSV into fluvius_interval, and the electricity backfill onto history_5min.

CLI only. Drop files in import_queue/fluvius/. A row is stored when its EAN
matches Electricity EAN or Gas EAN on the Settings page. Rollup calls
stitch_electricity_curve and prepare_day_overrides. Gas stays in fluvius_interval.
"""
from __future__ import annotations

import csv
import os
import shutil
import sqlite3
import sys
from datetime import datetime, timedelta
from typing import Dict, Iterable, List, Optional, Sequence, Set, Tuple
import site_config

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
QUEUE_DIR = os.path.join(BASE_DIR, "import_queue", "fluvius")
PROCESSED_DIR = os.path.join(QUEUE_DIR, "processed")
# Filename token -> resolution stored on fluvius_interval.
_RESOLUTION_TOKENS: Tuple[Tuple[str, str], ...] = (
    ("kwartiertotalen", "15min"),
    ("uurtotalen", "hour"),
    ("dagtotalen", "day"),
    ("piekvermogen", "month"),
)

# Longer labels first so "Afname Dag" is not stored as gas "Afname".
_REGISTER_LABELS: Tuple[Tuple[str, str], ...] = (
    ("afname dag", "afname_dag"),
    ("afname nacht", "afname_nacht"),
    ("injectie dag", "injectie_dag"),
    ("injectie nacht", "injectie_nacht"),
    ("piekvermogen", "piekvermogen"),
    ("afname", "afname"),
)

_IMPORT_REGS = ("afname_dag", "afname_nacht")
_EXPORT_REGS = ("injectie_dag", "injectie_nacht")
_P1_SOURCES = ("live_p1", "merged")

_REQUIRED_COLUMNS = (
    "Van (datum)",
    "Van (tijdstip)",
    "Tot (datum)",
    "Tot (tijdstip)",
    "EAN-code",
    "Meter",
    "Metertype",
    "Register",
    "Volume",
    "Eenheid",
    "Validatiestatus",
)


class FluviusParseError(ValueError):
    """One CSV cannot be stored. The file stays in the queue."""


def digits_only(raw: str) -> str:
    """Keep digits. Strips the Excel =\"5414...\" wrapper and any spaces."""
    return "".join(ch for ch in raw if ch.isdigit())


def ensure_interval_schema(conn: sqlite3.Connection) -> None:
    """Create fluvius_interval. Rollup must not delete this table."""
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS fluvius_interval (
            ean TEXT NOT NULL,
            register TEXT NOT NULL,
            unit TEXT NOT NULL,
            resolution TEXT NOT NULL,
            interval_start TEXT NOT NULL,
            occurrence INTEGER NOT NULL,
            interval_end TEXT NOT NULL,
            volume REAL NOT NULL,
            validation TEXT NOT NULL,
            calorific TEXT NOT NULL DEFAULT '',
            meter TEXT NOT NULL DEFAULT '',
            meter_type TEXT NOT NULL DEFAULT '',
            source_file TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (ean, register, unit, resolution, interval_start, occurrence)
        )
        """
    )


def ensure_history_grid_columns(conn: sqlite3.Connection) -> None:
    """Add quarter-kWh columns on an existing history_5min table.

    CREATE TABLE IF NOT EXISTS does not alter a table that already exists.
    """
    row = conn.execute(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'history_5min'"
    ).fetchone()
    if row is None:
        return
    have = {str(info[1]) for info in conn.execute("PRAGMA table_info(history_5min)")}
    if "grid_import_kwh" not in have:
        conn.execute("ALTER TABLE history_5min ADD COLUMN grid_import_kwh REAL")
    if "grid_export_kwh" not in have:
        conn.execute("ALTER TABLE history_5min ADD COLUMN grid_export_kwh REAL")


def configured_eans(conn: sqlite3.Connection) -> Tuple[str, str]:
    """Electricity EAN, gas EAN. Digits only. Either may be empty."""
    settings = site_config.load_settings(conn)
    return (
        digits_only(settings.get("fluvius_ean_electricity", "")),
        digits_only(settings.get("fluvius_ean_gas", "")),
    )


def resolution_from_filename(name: str) -> str:
    """Map one filename token to 15min, hour, day, or month."""
    lower = name.lower()
    found = [res for token, res in _RESOLUTION_TOKENS if token in lower]
    if len(found) != 1:
        raise FluviusParseError(
            "filename needs one of kwartiertotalen, uurtotalen, dagtotalen, piekvermogen"
        )
    return found[0]


def _register_code(label: str) -> str:
    key = " ".join(label.strip().lower().split())
    for text, code in _REGISTER_LABELS:
        if key == text:
            return code
    raise FluviusParseError(f"unknown register {label!r}")


def _unit_code(label: str) -> str:
    key = label.strip().lower().replace("³", "3")
    if key == "kwh":
        return "kWh"
    if key == "m3":
        return "m3"
    if key == "kw":
        return "kW"
    raise FluviusParseError(f"unknown unit {label!r}")


def _volume(raw: str, validation: str) -> float:
    text = raw.strip()
    if text == "":
        if validation == "Geen verbruik":
            return 0.0
        raise FluviusParseError("blank volume")
    if "," in text and "." in text:
        raise FluviusParseError(f"volume {raw!r} has both a comma and a dot")
    try:
        return float(text.replace(",", "."))
    except ValueError as exc:
        raise FluviusParseError(f"volume {raw!r}") from exc


def _stamp(date_text: str, time_text: str) -> str:
    try:
        parsed = datetime.strptime(
            date_text.strip() + " " + time_text.strip(),
            "%d-%m-%Y %H:%M:%S",
        )
    except ValueError as exc:
        raise FluviusParseError(
            f"bad timestamp {date_text!r} {time_text!r}"
        ) from exc
    return parsed.strftime("%Y-%m-%d %H:%M:%S")


def parse_csv(path: str) -> List[Tuple[object, ...]]:
    """Parse one Fluvius CSV. Raises FluviusParseError and writes nothing.

    Occurrence is 0, then 1, in file order for the same interval start.
    The caller filters EANs.
    """
    resolution = resolution_from_filename(os.path.basename(path))
    source = os.path.basename(path)
    seen: Dict[Tuple[str, str, str, str, str], int] = {}
    rows: List[Tuple[object, ...]] = []
    # utf-8-sig drops a leading BOM. The portal files use one.
    with open(path, "r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle, delimiter=";")
        if reader.fieldnames is None:
            raise FluviusParseError("missing header")
        missing = [name for name in _REQUIRED_COLUMNS if name not in reader.fieldnames]
        if missing:
            raise FluviusParseError("missing column " + ", ".join(missing))
        for line_no, raw in enumerate(reader, start=2):
            try:
                # Portal statuses seen in the downloads: Uitgelezen, Geen verbruik,
                # Geschat, Voorlopig. A blank cell is a cut row, not a status.
                validation = (raw.get("Validatiestatus") or "").strip()
                if validation == "":
                    raise FluviusParseError("validation is blank; this row is incomplete")
                calorific = (raw.get("Calorische bovenwaarde") or "").strip()
                if calorific not in ("", "Definitief", "Voorlopig"):
                    raise FluviusParseError(f"calorific {calorific!r}")
                ean = digits_only(raw.get("EAN-code") or "")
                if ean == "":
                    raise FluviusParseError("blank EAN")
                register = _register_code(raw.get("Register") or "")
                unit = _unit_code(raw.get("Eenheid") or "")
                start = _stamp(raw.get("Van (datum)") or "", raw.get("Van (tijdstip)") or "")
                end = _stamp(raw.get("Tot (datum)") or "", raw.get("Tot (tijdstip)") or "")
                volume = _volume(raw.get("Volume") or "", validation)
                key = (ean, register, unit, resolution, start)
                occurrence = seen.get(key, 0)
                seen[key] = occurrence + 1
                rows.append(
                    (
                        ean,
                        register,
                        unit,
                        resolution,
                        start,
                        occurrence,
                        end,
                        volume,
                        validation,
                        calorific,
                        (raw.get("Meter") or "").strip(),
                        (raw.get("Metertype") or "").strip(),
                        source,
                    )
                )
            except FluviusParseError as exc:
                raise FluviusParseError(f"row {line_no}: {exc}") from exc
    return rows


def store_rows(conn: sqlite3.Connection, rows: Sequence[Tuple[object, ...]]) -> None:
    """Replace the same primary key. A later Definitief file overwrites Voorlopig."""
    conn.executemany(
        """
        INSERT OR REPLACE INTO fluvius_interval (
            ean, register, unit, resolution, interval_start, occurrence,
            interval_end, volume, validation, calorific, meter, meter_type, source_file
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """,
        list(rows),
    )


def _last_sunday(year: int, month: int) -> str:
    """Calendar date of the last Sunday in that month. EU DST uses March and October."""
    if month == 12:
        cursor = datetime(year + 1, 1, 1)
    else:
        cursor = datetime(year, month + 1, 1)
    cursor -= timedelta(days=1)
    while cursor.weekday() != 6:
        cursor -= timedelta(days=1)
    return cursor.strftime("%Y-%m-%d")


def expected_quarter_keys(day: str) -> List[Tuple[str, int]]:
    """Local quarter starts for one electricity calendar day, with DST repeats.

    Brussels follows the EU rule: last Sunday of March skips 02:00-03:00, last
    Sunday of October repeats it. The clock rule is written out here so a
    machine without a zone database still matches the Fluvius files.
    """
    year = int(day[0:4])
    spring = _last_sunday(year, 3)
    autumn = _last_sunday(year, 10)
    clock = datetime.strptime(day + " 00:00:00", "%Y-%m-%d %H:%M:%S")
    end = clock + timedelta(days=1)
    out: List[Tuple[str, int]] = []
    while clock < end:
        stamp = clock.strftime("%Y-%m-%d %H:%M:%S")
        minute = stamp[11:16]
        if day == spring and minute in ("02:00", "02:15", "02:30", "02:45"):
            clock += timedelta(minutes=15)
            continue
        out.append((stamp, 0))
        if day == autumn and minute in ("02:00", "02:15", "02:30", "02:45"):
            out.append((stamp, 1))
        clock += timedelta(minutes=15)
    return out


def _table_exists(conn: sqlite3.Connection, name: str) -> bool:
    row = conn.execute(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
        (name,),
    ).fetchone()
    return row is not None


def local_today(conn: sqlite3.Connection) -> str:
    row = conn.execute("SELECT date('now', 'localtime')").fetchone()
    return str(row[0])


def local_span(conn: sqlite3.Connection, days_back: int) -> Tuple[str, str]:
    """Inclusive calendar dates from local today minus days_back through today."""
    row = conn.execute(
        "SELECT date('now', 'localtime', ?), date('now', 'localtime')",
        (f"-{int(days_back)} days",),
    ).fetchone()
    return str(row[0]), str(row[1])


class _ElecIndex:
    """Electricity kWh rows for one EAN, grouped for the backfill."""

    def __init__(self) -> None:
        # (interval_start, occurrence) -> register -> volume
        self.quarters: Dict[Tuple[str, int], Dict[str, float]] = {}
        # interval_start -> one dict per occurrence
        self.by_start: Dict[str, List[Dict[str, float]]] = {}
        self.starts_by_day: Dict[str, List[str]] = {}
        self.covered_by_day: Dict[str, Set[Tuple[str, int]]] = {}
        # calendar day -> register -> volume (day files)
        self.day_files: Dict[str, Dict[str, float]] = {}

    def add_quarter(self, start: str, occurrence: int, register: str, volume: float) -> None:
        key = (start, occurrence)
        bucket = self.quarters.get(key)
        if bucket is None:
            bucket = {}
            self.quarters[key] = bucket
            self.by_start.setdefault(start, []).append(bucket)
            if occurrence == 0 and start not in self.starts_by_day.get(start[:10], []):
                self.starts_by_day.setdefault(start[:10], []).append(start)
        bucket[register] = volume
        if register in _IMPORT_REGS:
            self.covered_by_day.setdefault(start[:10], set()).add(key)

    def quarter_starts_on(self, day: str) -> List[str]:
        found = list(self.starts_by_day.get(day, []))
        found.sort()
        return found

    def covered(self, day: str) -> Set[Tuple[str, int]]:
        return self.covered_by_day.get(day, set())

    def energy_at(self, start: str) -> Tuple[Optional[float], Optional[float]]:
        imports: List[float] = []
        exports: List[float] = []
        for regs in self.by_start.get(start, []):
            for name in _IMPORT_REGS:
                if name in regs:
                    imports.append(regs[name])
            for name in _EXPORT_REGS:
                if name in regs:
                    exports.append(regs[name])
        imp = round(sum(imports), 3) if imports else None
        exp = round(sum(exports), 3) if exports else None
        return imp, exp

    def quarter_sum(self, day: str) -> Tuple[Optional[float], Optional[float]]:
        """Sum of published quarters. Each local start once (occurrences already inside energy_at)."""
        imports: List[float] = []
        exports: List[float] = []
        for start in self.quarter_starts_on(day):
            imp, exp = self.energy_at(start)
            if imp is not None:
                imports.append(imp)
            if exp is not None:
                exports.append(exp)
        imp_total = round(sum(imports), 3) if imports else None
        exp_total = round(sum(exports), 3) if exports else None
        return imp_total, exp_total

    def day_file_sum(self, day: str) -> Tuple[Optional[float], Optional[float]]:
        regs = self.day_files.get(day, {})
        imports = [regs[name] for name in _IMPORT_REGS if name in regs]
        exports = [regs[name] for name in _EXPORT_REGS if name in regs]
        imp = round(sum(imports), 3) if imports else None
        exp = round(sum(exports), 3) if exports else None
        return imp, exp

    def is_complete(self, day: str) -> bool:
        expected = set(expected_quarter_keys(day))
        return expected <= self.covered(day)


def load_electricity(conn: sqlite3.Connection, ean: str) -> _ElecIndex:
    index = _ElecIndex()
    if ean == "" or not _table_exists(conn, "fluvius_interval"):
        return index
    rows = conn.execute(
        """
        SELECT resolution, register, interval_start, occurrence, volume
        FROM fluvius_interval
        WHERE ean = ? AND unit = 'kWh'
          AND register IN ('afname_dag', 'afname_nacht', 'injectie_dag', 'injectie_nacht')
          AND resolution IN ('15min', 'day')
        """,
        (ean,),
    )
    for resolution, register, start, occurrence, volume in rows:
        if resolution == "15min":
            index.add_quarter(str(start), int(occurrence), str(register), float(volume))
        elif resolution == "day":
            day = str(start)[:10]
            regs = index.day_files.setdefault(day, {})
            regs[str(register)] = regs.get(str(register), 0.0) + float(volume)
    return index


def fallout_days(
    conn: sqlite3.Connection,
    day_lo: Optional[str],
    day_hi: Optional[str],
    fluvius_days: Iterable[str],
) -> Set[str]:
    """Electricity days that must not use the P1 counter delta.

    The open local day is excluded: it has no 23:45 sample yet because the
    day is not over. A finished day falls out when readings miss the 00:15
    or 23:45 bracket, when a counter steps down by more than 0.01 kWh, or
    when that day has no readings at all.
    """
    today = local_today(conn)
    out: Set[str] = set()
    if _table_exists(conn, "readings"):
        where = "1 = 1"
        params: List[str] = []
        if day_lo is not None:
            where += " AND substr(timestamp, 1, 10) >= ?"
            params.append(day_lo)
        if day_hi is not None:
            where += " AND substr(timestamp, 1, 10) <= ?"
            params.append(day_hi)
        brackets = conn.execute(
            f"""
            SELECT substr(timestamp, 1, 10) AS d
            FROM readings
            WHERE {where}
            GROUP BY d
            HAVING MIN(timestamp) > d || ' 00:15:00'
                OR MAX(timestamp) < d || ' 23:45:00'
            """,
            params,
        )
        out.update(str(row[0]) for row in brackets)
        steps = conn.execute(
            f"""
            WITH ordered AS (
                SELECT substr(timestamp, 1, 10) AS d,
                       total_import, total_export,
                       LAG(total_import) OVER (
                           PARTITION BY substr(timestamp, 1, 10) ORDER BY timestamp
                       ) AS prev_import,
                       LAG(total_export) OVER (
                           PARTITION BY substr(timestamp, 1, 10) ORDER BY timestamp
                       ) AS prev_export
                FROM readings
                WHERE {where}
            )
            SELECT DISTINCT d FROM ordered
            WHERE (total_import - prev_import) < -0.01
               OR (total_export - prev_export) < -0.01
            """,
            params,
        )
        out.update(str(row[0]) for row in steps)
        present = conn.execute(
            f"""
            SELECT DISTINCT substr(timestamp, 1, 10) FROM readings WHERE {where}
            """,
            params,
        )
        have_readings = {str(row[0]) for row in present}
    else:
        have_readings = set()

    for day in fluvius_days:
        if day_lo is not None and day < day_lo:
            continue
        if day_hi is not None and day > day_hi:
            continue
        if day not in have_readings:
            out.add(day)
    out.discard(today)
    return out


def _in_span(day: str, day_lo: Optional[str], day_hi: Optional[str]) -> bool:
    if day_lo is not None and day < day_lo:
        return False
    if day_hi is not None and day > day_hi:
        return False
    return True


def _slot_triplet(start: str) -> Tuple[str, str, str]:
    base = datetime.strptime(start, "%Y-%m-%d %H:%M:%S")
    return tuple(
        (base + timedelta(minutes=minutes)).strftime("%Y-%m-%d %H:%M:%S")
        for minutes in (0, 5, 10)
    )  # type: ignore[return-value]


def _p1_timestamps(
    conn: sqlite3.Connection,
    day_lo: Optional[str],
    day_hi: Optional[str],
) -> Set[str]:
    if not _table_exists(conn, "history_5min"):
        return set()
    where = "data_source IN ('live_p1', 'merged')"
    params: List[str] = []
    if day_lo is not None:
        where += " AND timestamp >= ?"
        params.append(day_lo + " 00:00:00")
    if day_hi is not None:
        where += " AND timestamp < ?"
        nxt = datetime.strptime(day_hi, "%Y-%m-%d") + timedelta(days=1)
        params.append(nxt.strftime("%Y-%m-%d %H:%M:%S"))
    rows = conn.execute(
        f"SELECT timestamp FROM history_5min WHERE {where}",
        params,
    )
    return {str(row[0]) for row in rows}


def _clear_curve_day(conn: sqlite3.Connection, day: str) -> None:
    """Drop Fluvius curve points for one day so the stitch can rewrite them."""
    if not _table_exists(conn, "history_5min"):
        return
    lo = day + " 00:00:00"
    hi = (datetime.strptime(day, "%Y-%m-%d") + timedelta(days=1)).strftime("%Y-%m-%d %H:%M:%S")
    conn.execute(
        """
        DELETE FROM history_5min
        WHERE data_source = 'fluvius' AND timestamp >= ? AND timestamp < ?
        """,
        (lo, hi),
    )
    conn.execute(
        """
        UPDATE history_5min
        SET grid_import_kwh = NULL, grid_export_kwh = NULL
        WHERE timestamp >= ? AND timestamp < ?
          AND (grid_import_kwh IS NOT NULL OR grid_export_kwh IS NOT NULL)
        """,
        (lo, hi),
    )


def _write_points(
    conn: sqlite3.Connection,
    points: Sequence[Tuple[str, Optional[float], Optional[float]]],
) -> None:
    if not points:
        return
    existing: Dict[str, str] = {}
    stamps = [item[0] for item in points]
    for offset in range(0, len(stamps), 400):
        chunk = stamps[offset:offset + 400]
        marks = ",".join("?" for _ in chunk)
        rows = conn.execute(
            f"SELECT timestamp, data_source FROM history_5min WHERE timestamp IN ({marks})",
            chunk,
        )
        for stamp, source in rows:
            existing[str(stamp)] = "" if source is None else str(source)
    inserts: List[Tuple[str, Optional[float], Optional[float]]] = []
    for stamp, imp, exp in points:
        if stamp not in existing:
            inserts.append((stamp, imp, exp))
            continue
        conn.execute(
            """
            UPDATE history_5min
            SET grid_import_kwh = ?, grid_export_kwh = ?
            WHERE timestamp = ?
            """,
            (imp, exp, stamp),
        )
    conn.executemany(
        """
        INSERT INTO history_5min (timestamp, grid_import_kwh, grid_export_kwh, data_source)
        VALUES (?, ?, ?, 'fluvius')
        """,
        inserts,
    )


def stitch_electricity_curve(
    conn: sqlite3.Connection,
    day_lo: Optional[str] = None,
    day_hi: Optional[str] = None,
) -> int:
    """Write quarter kWh onto history_5min for the given inclusive calendar span.

    None means every day. A passing day gets a point only when all three
    5-minute slots of that quarter lack P1. A fallen-out day with a complete
    15-minute set gets every quarter. An incomplete fall-out writes no curve.
    Returns the number of quarter points written.
    """
    ensure_history_grid_columns(conn)
    if not _table_exists(conn, "history_5min"):
        return 0
    elec, _gas = configured_eans(conn)
    index = load_electricity(conn, elec)
    days: Set[str] = set()
    for start, _occ in index.quarters:
        day = start[:10]
        if _in_span(day, day_lo, day_hi):
            days.add(day)
    for day in index.day_files:
        if _in_span(day, day_lo, day_hi):
            days.add(day)
    if day_lo is not None and day_hi is not None and _table_exists(conn, "history_5min"):
        # Re-clear days in the window that used to have a curve and no longer qualify.
        rows = conn.execute(
            """
            SELECT DISTINCT substr(timestamp, 1, 10)
            FROM history_5min
            WHERE timestamp >= ? AND timestamp < ?
              AND (data_source = 'fluvius' OR grid_import_kwh IS NOT NULL OR grid_export_kwh IS NOT NULL)
            """,
            (
                day_lo + " 00:00:00",
                (datetime.strptime(day_hi, "%Y-%m-%d") + timedelta(days=1)).strftime("%Y-%m-%d %H:%M:%S"),
            ),
        )
        for (day,) in rows:
            days.add(str(day))
    fallen = fallout_days(conn, day_lo, day_hi, days)
    p1 = _p1_timestamps(conn, day_lo, day_hi)
    written = 0
    with conn:
        for day in sorted(days):
            _clear_curve_day(conn, day)
            points: List[Tuple[str, Optional[float], Optional[float]]] = []
            if day in fallen:
                if not index.is_complete(day):
                    continue
                for start in index.quarter_starts_on(day):
                    points.append((start, *index.energy_at(start)))
            else:
                for start in index.quarter_starts_on(day):
                    slots = _slot_triplet(start)
                    if any(slot in p1 for slot in slots):
                        continue
                    points.append((start, *index.energy_at(start)))
            _write_points(conn, points)
            written += len(points)
    return written


def prepare_day_overrides(
    conn: sqlite3.Connection,
    day_lo: Optional[str],
    day_hi: Optional[str],
) -> None:
    """Temp table of fall-out days whose grid kWh comes from Fluvius, not counters.

    A row in the temp table replaces the counter delta even when both values
    are null (fall-out, and Fluvius has neither a complete quarter set nor a day file).
    """
    conn.execute("DROP TABLE IF EXISTS fluvius_day_override")
    conn.execute(
        """
        CREATE TEMP TABLE fluvius_day_override (
            date TEXT PRIMARY KEY,
            grid_import_kwh REAL,
            grid_export_kwh REAL
        )
        """
    )
    elec, _gas = configured_eans(conn)
    index = load_electricity(conn, elec)
    candidate_days: Set[str] = set(index.day_files)
    for start, _occ in index.quarters:
        candidate_days.add(start[:10])
    if _table_exists(conn, "readings"):
        where = "1 = 1"
        params: List[str] = []
        if day_lo is not None:
            where += " AND substr(timestamp, 1, 10) >= ?"
            params.append(day_lo)
        if day_hi is not None:
            where += " AND substr(timestamp, 1, 10) <= ?"
            params.append(day_hi)
        rows = conn.execute(
            f"SELECT DISTINCT substr(timestamp, 1, 10) FROM readings WHERE {where}",
            params,
        )
        candidate_days.update(str(row[0]) for row in rows)
    fallen = fallout_days(conn, day_lo, day_hi, candidate_days)
    payload: List[Tuple[str, Optional[float], Optional[float]]] = []
    for day in sorted(fallen):
        if not _in_span(day, day_lo, day_hi):
            continue
        if index.is_complete(day):
            imp, exp = index.quarter_sum(day)
        else:
            imp, exp = index.day_file_sum(day)
        payload.append((day, imp, exp))
    if payload:
        conn.executemany(
            "INSERT INTO fluvius_day_override (date, grid_import_kwh, grid_export_kwh) VALUES (?, ?, ?)",
            payload,
        )


def insert_missing_override_days(conn: sqlite3.Connection) -> None:
    """A Fluvius-only fall-out day has no history_5min rows, so the daily INSERT skips it."""
    conn.execute(
        """
        INSERT INTO daily_history (date, grid_import_kwh, grid_export_kwh)
        SELECT o.date, o.grid_import_kwh, o.grid_export_kwh
        FROM fluvius_day_override o
        WHERE NOT EXISTS (SELECT 1 FROM daily_history d WHERE d.date = o.date)
          AND (o.grid_import_kwh IS NOT NULL OR o.grid_export_kwh IS NOT NULL)
        """
    )


def _cross_check(conn: sqlite3.Connection, elec: str, gas: str) -> None:
    """Log quarter-sum versus day-file differences. Never fails the import."""
    if elec:
        index = load_electricity(conn, elec)
        days = set(index.day_files) | {start[:10] for start, _occ in index.quarters}
        for day in sorted(days):
            if not index.is_complete(day):
                continue
            q_imp, q_exp = index.quarter_sum(day)
            d_imp, d_exp = index.day_file_sum(day)
            if q_imp is not None and d_imp is not None and abs(q_imp - d_imp) > 0.001:
                print(
                    f"cross-check {day} electricity import quarter sum {q_imp:.3f} "
                    f"vs day file {d_imp:.3f}"
                )
            if q_exp is not None and d_exp is not None and abs(q_exp - d_exp) > 0.001:
                print(
                    f"cross-check {day} electricity injection quarter sum {q_exp:.3f} "
                    f"vs day file {d_exp:.3f}"
                )
    if gas == "" or not _table_exists(conn, "fluvius_interval"):
        return
    rows = conn.execute(
        """
        SELECT resolution, unit, interval_start, interval_end, volume, calorific
        FROM fluvius_interval
        WHERE ean = ? AND register = 'afname' AND resolution IN ('hour', 'day')
        """,
        (gas,),
    ).fetchall()
    hours: Dict[Tuple[str, str], List[Tuple[str, float, str]]] = {}
    day_rows: List[Tuple[str, str, str, float, str]] = []
    for resolution, unit, start, end, volume, calorific in rows:
        if resolution == "hour":
            hours.setdefault((str(unit), str(start)), []).append(
                (str(start), float(volume), str(calorific or ""))
            )
        else:
            day_rows.append((str(unit), str(start), str(end), float(volume), str(calorific or "")))
    # hours keyed by unit only, list of (start, volume, calorific)
    hour_list: Dict[str, List[Tuple[str, float, str]]] = {}
    for (unit, start), items in hours.items():
        hour_list.setdefault(unit, []).extend(items)
    for unit, start, end, volume, calorific in day_rows:
        matched = [
            item for item in hour_list.get(unit, [])
            if start <= item[0] < end
        ]
        if not matched:
            continue
        if unit == "kWh":
            if calorific != "Definitief":
                continue
            if any(item[2] != "Definitief" for item in matched):
                continue
            limit = 0.002
        elif unit == "m3":
            limit = 0.001
        else:
            continue
        hour_sum = round(sum(item[1] for item in matched), 3)
        if abs(hour_sum - volume) > limit:
            print(
                f"cross-check {start} gas {unit} hour sum {hour_sum:.3f} vs day file {volume:.3f}"
            )


def import_queue(conn: sqlite3.Connection) -> int:
    """Read import_queue/fluvius/*.csv. Return 0 when every file parsed."""
    ensure_interval_schema(conn)
    site_config.ensure_schema(conn)
    elec, gas = configured_eans(conn)
    allowed = {ean for ean in (elec, gas) if ean}
    if not allowed:
        sys.stderr.write(
            "fluvius_import.py: Electricity EAN and Gas EAN are empty. "
            "Set them on the Settings page. Nothing was read.\n"
        )
        return 1
    os.makedirs(QUEUE_DIR, exist_ok=True)
    os.makedirs(PROCESSED_DIR, exist_ok=True)
    names = sorted(
        name for name in os.listdir(QUEUE_DIR)
        if name.lower().endswith(".csv") and os.path.isfile(os.path.join(QUEUE_DIR, name))
    )
    if not names:
        print("fluvius_import.py: no CSV files in import_queue/fluvius/")
        return 0
    failed = 0
    for name in names:
        path = os.path.join(QUEUE_DIR, name)
        try:
            parsed = parse_csv(path)
        except FluviusParseError as exc:
            failed += 1
            sys.stderr.write(f"fluvius_import.py: kept {name}: {exc}\n")
            continue
        except OSError as exc:
            failed += 1
            sys.stderr.write(f"fluvius_import.py: kept {name}: {exc}\n")
            continue
        kept = [row for row in parsed if str(row[0]) in allowed]
        skipped = len(parsed) - len(kept)
        with conn:
            store_rows(conn, kept)
        dest = os.path.join(PROCESSED_DIR, name)
        if os.path.exists(dest):
            os.remove(dest)
        shutil.move(path, dest)
        print(f"fluvius_import.py: {name}: imported {len(kept)} rows, skipped {skipped} -> processed/")
    _cross_check(conn, elec, gas)
    return 1 if failed else 0


def main() -> int:
    conn = sqlite3.connect(site_config.resolve_db_path(), timeout=30.0)
    try:
        site_config.import_env_if_empty(conn)
        return import_queue(conn)
    except Exception as exc:
        sys.stderr.write(f"fluvius_import.py: {exc}\n")
        return 1
    finally:
        conn.close()


if __name__ == "__main__":
    sys.exit(main())
