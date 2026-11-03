# --- file: utility_volume.py ---
"""P1 + Fluvius gas/water period volumes for daily_history / monthly_history (A11).

history_5min.gas / .water stay lifetime counters. Fluvius gas stays in
fluvius_interval (never copied onto history_5min). Period m3 lands on
daily_history.gas_m3 / water_m3 and monthly totals.
"""
from __future__ import annotations

import sqlite3
from typing import Dict, Iterable, List, Optional, Set, Tuple

import fluvius_import


def _table_columns(conn: sqlite3.Connection, table: str) -> Set[str]:
    return {str(row[1]) for row in conn.execute(f"PRAGMA table_info({table})")}


def ensure_utility_volume_columns(conn: sqlite3.Connection) -> None:
    """Add gas_m3 / water_m3 on daily and monthly rollup tables when missing."""
    daily_cols = _table_columns(conn, "daily_history")
    if "gas_m3" not in daily_cols:
        conn.execute("ALTER TABLE daily_history ADD COLUMN gas_m3 REAL")
    if "water_m3" not in daily_cols:
        conn.execute("ALTER TABLE daily_history ADD COLUMN water_m3 REAL")
    monthly_cols = _table_columns(conn, "monthly_history")
    if "gas_m3" not in monthly_cols:
        conn.execute("ALTER TABLE monthly_history ADD COLUMN gas_m3 REAL")
    if "water_m3" not in monthly_cols:
        conn.execute("ALTER TABLE monthly_history ADD COLUMN water_m3 REAL")


def _local_today(conn: sqlite3.Connection) -> str:
    return fluvius_import.local_today(conn)


def _span_where(
    day_lo: Optional[str], day_hi: Optional[str], col: str = "substr(timestamp, 1, 10)"
) -> Tuple[str, List[str]]:
    where = "1 = 1"
    params: List[str] = []
    if day_lo is not None:
        where += f" AND {col} >= ?"
        params.append(day_lo)
    if day_hi is not None:
        where += f" AND {col} <= ?"
        params.append(day_hi)
    return where, params


def _p1_counter_deltas(
    conn: sqlite3.Connection,
    column: str,
    day_lo: Optional[str],
    day_hi: Optional[str],
) -> Dict[str, float]:
    """MAX(counter) - MIN(counter) per local day from history_5min."""
    if column not in ("gas", "water"):
        raise ValueError(f"unsupported utility column: {column}")
    if not fluvius_import._table_exists(conn, "history_5min"):
        return {}
    where, params = _span_where(day_lo, day_hi)
    rows = conn.execute(
        f"""
        SELECT substr(timestamp, 1, 10) AS d,
               ROUND(MAX({column}) - MIN({column}), 4) AS delta
        FROM history_5min
        WHERE {where}
          AND {column} IS NOT NULL
        GROUP BY d
        HAVING COUNT({column}) >= 2
        """,
        params,
    )
    out: Dict[str, float] = {}
    for day, delta in rows:
        if day is None or delta is None:
            continue
        # Negative or tiny noise after meter swap is treated as absent.
        if float(delta) < 0:
            continue
        out[str(day)] = float(delta)
    return out


def _utility_fallout_days(
    conn: sqlite3.Connection,
    column: str,
    day_lo: Optional[str],
    day_hi: Optional[str],
    candidate_days: Iterable[str],
) -> Set[str]:
    """Mirror electricity fall-out, keyed on one lifetime counter column.

    Open local day is never a fall-out day. Finished days fall out when the
    readings window misses 00:15 / 23:45, when the counter steps down by more
    than 0.01, or when that day has no readings with that column.
    """
    if column not in ("gas", "water"):
        raise ValueError(f"unsupported utility column: {column}")
    today = _local_today(conn)
    out: Set[str] = set()
    if fluvius_import._table_exists(conn, "readings"):
        where, params = _span_where(day_lo, day_hi)
        where_col = f"{where} AND {column} IS NOT NULL"
        brackets = conn.execute(
            f"""
            SELECT substr(timestamp, 1, 10) AS d
            FROM readings
            WHERE {where_col}
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
                       {column} AS ctr,
                       LAG({column}) OVER (
                           PARTITION BY substr(timestamp, 1, 10) ORDER BY timestamp
                       ) AS prev_ctr
                FROM readings
                WHERE {where_col}
            )
            SELECT DISTINCT d FROM ordered
            WHERE prev_ctr IS NOT NULL
              AND (ctr - prev_ctr) < -0.01
            """,
            params,
        )
        out.update(str(row[0]) for row in steps)
        present = conn.execute(
            f"""
            SELECT DISTINCT substr(timestamp, 1, 10)
            FROM readings
            WHERE {where_col}
            """,
            params,
        )
        have = {str(row[0]) for row in present}
    else:
        have = set()

    for day in candidate_days:
        if day_lo is not None and day < day_lo:
            continue
        if day_hi is not None and day > day_hi:
            continue
        if day not in have:
            out.add(day)
    out.discard(today)
    return out


def _fluvius_gas_day_m3(
    conn: sqlite3.Connection,
    day_lo: Optional[str],
    day_hi: Optional[str],
) -> Dict[str, float]:
    """Day-file gas volumes (m3) for the configured gas EAN."""
    _elec, gas = fluvius_import.configured_eans(conn)
    if gas == "" or not fluvius_import._table_exists(conn, "fluvius_interval"):
        return {}
    where, params = _span_where(day_lo, day_hi, "substr(interval_start, 1, 10)")
    params = [gas] + params
    rows = conn.execute(
        f"""
        SELECT substr(interval_start, 1, 10) AS d, SUM(volume) AS vol
        FROM fluvius_interval
        WHERE ean = ?
          AND register = 'afname'
          AND unit = 'm3'
          AND resolution = 'day'
          AND {where}
        GROUP BY d
        """,
        params,
    )
    out: Dict[str, float] = {}
    for day, vol in rows:
        if day is None or vol is None:
            continue
        out[str(day)] = float(vol)
    return out


def _merge_gas_days(
    p1: Dict[str, float],
    fluvius: Dict[str, float],
    fallout: Set[str],
    today: str,
) -> Dict[str, float]:
    """Prefer valid P1; else Fluvius. Today always keeps P1 when present."""
    days = set(p1) | set(fluvius)
    out: Dict[str, float] = {}
    for day in days:
        p1_val = p1.get(day)
        fl_val = fluvius.get(day)
        if day == today:
            if p1_val is not None:
                out[day] = p1_val
            elif fl_val is not None:
                out[day] = fl_val
            continue
        if p1_val is not None and day not in fallout:
            out[day] = p1_val
        elif fl_val is not None:
            out[day] = fl_val
        elif p1_val is not None:
            # Fall-out without Fluvius: keep P1 rather than invent a gap.
            out[day] = p1_val
    return out


def apply_utility_day_volumes(
    conn: sqlite3.Connection,
    days_back: Optional[int] = None,
) -> None:
    """Write gas_m3 / water_m3 onto daily_history for the span."""
    ensure_utility_volume_columns(conn)
    day_lo: Optional[str] = None
    day_hi: Optional[str] = None
    if days_back is not None:
        day_lo, day_hi = fluvius_import.local_span(conn, int(days_back))

    p1_gas = _p1_counter_deltas(conn, "gas", day_lo, day_hi)
    p1_water = _p1_counter_deltas(conn, "water", day_lo, day_hi)
    fluvius_gas = _fluvius_gas_day_m3(conn, day_lo, day_hi)
    today = _local_today(conn)

    gas_candidates = set(p1_gas) | set(fluvius_gas)
    gas_fallout = _utility_fallout_days(conn, "gas", day_lo, day_hi, gas_candidates)
    gas_days = _merge_gas_days(p1_gas, fluvius_gas, gas_fallout, today)

    water_fallout = _utility_fallout_days(conn, "water", day_lo, day_hi, set(p1_water))
    water_days: Dict[str, float] = {}
    for day, val in p1_water.items():
        if day == today or day not in water_fallout:
            water_days[day] = val
        # Fall-out finished water days with no other source stay null.

    # Days we explicitly evaluated (including fall-out clears for water).
    water_clear = {
        day
        for day in p1_water
        if day in water_fallout and day != today and day not in water_days
    }
    all_days = set(gas_days) | set(water_days) | water_clear
    with conn:
        for day in sorted(all_days):
            gas_v = gas_days.get(day)
            if day in water_days:
                water_v: Optional[float] = water_days[day]
            elif day in water_clear:
                water_v = None
            else:
                water_v = None
            existing = conn.execute(
                "SELECT gas_m3, water_m3 FROM daily_history WHERE date = ?",
                (day,),
            ).fetchone()
            if existing is None:
                conn.execute(
                    """
                    INSERT INTO daily_history (date, gas_m3, water_m3)
                    VALUES (?, ?, ?)
                    """,
                    (day, gas_v, water_v if day in water_days or day in water_clear else None),
                )
                continue
            new_gas = gas_v if day in gas_days else existing[0]
            if day in water_days or day in water_clear:
                new_water = water_v
            else:
                new_water = existing[1]
            conn.execute(
                "UPDATE daily_history SET gas_m3 = ?, water_m3 = ? WHERE date = ?",
                (new_gas, new_water, day),
            )


def apply_utility_month_volumes(conn: sqlite3.Connection) -> None:
    """Sum daily gas_m3 / water_m3 into monthly_history (full rebuild of those cols)."""
    ensure_utility_volume_columns(conn)
    with conn:
        # Clear then re-sum so months that lost all days do not keep stale totals.
        conn.execute("UPDATE monthly_history SET gas_m3 = NULL, water_m3 = NULL")
        conn.execute(
            """
            INSERT INTO monthly_history (year_month, gas_m3, water_m3)
            SELECT substr(date, 1, 7) AS ym,
                   ROUND(SUM(gas_m3), 3),
                   ROUND(SUM(water_m3), 3)
            FROM daily_history
            GROUP BY ym
            ON CONFLICT(year_month) DO UPDATE SET
                gas_m3 = excluded.gas_m3,
                water_m3 = excluded.water_m3
            """
        )
