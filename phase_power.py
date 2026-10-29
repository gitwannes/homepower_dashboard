# --- file: phase_power.py ---
"""Per-phase W helpers shared by logger (1-min) and rollup (5-min)."""
from __future__ import annotations

import math
import sqlite3
from typing import Dict, List, Optional, Tuple


def _ensure_phase_columns(conn: sqlite3.Connection, table: str) -> None:
    """ADD power_l1/l2/l3_w INTEGER when missing on table."""
    have = {str(info[1]) for info in conn.execute(f"PRAGMA table_info({table})")}
    for col in ("power_l1_w", "power_l2_w", "power_l3_w"):
        if col not in have:
            conn.execute(f"ALTER TABLE {table} ADD COLUMN {col} INTEGER")


def ensure_readings_phase_columns(conn: sqlite3.Connection) -> None:
    """Add power_l1/l2/l3_w on readings when missing (A16)."""
    _ensure_phase_columns(conn, "readings")


def ensure_history_phase_columns(conn: sqlite3.Connection) -> None:
    """Add power_l1/l2/l3_w on history_5min when missing (A17)."""
    _ensure_phase_columns(conn, "history_5min")

# Fixed message so system_logs streaks collapse repeated Q2 hard-edge samples.
PHASE_SUM_HARD_EDGE_WARN = (
    "Phase power sum is 0 but active_power_w is not; stored raw L1/L2/L3 without scale."
)


def scale_phases_to_active(raw: List[int], active_power: int) -> List[int]:
    """Largest-remainder scale so L1+L2+L3 == active_power (INTEGER watts).

    When sum(raw) == 0, returns raw unchanged (Q2 hard edge is handled by caller).
    """
    phase_sum = raw[0] + raw[1] + raw[2]
    if phase_sum == active_power:
        return list(raw)
    if phase_sum == 0:
        return list(raw)

    exact = [r * active_power / phase_sum for r in raw]
    floors = [math.floor(x) for x in exact]
    residual = active_power - sum(floors)
    # Largest leftover fraction first; tie-break largest |raw|, then index.
    order = sorted(
        range(3),
        key=lambda i: (-(exact[i] - floors[i]), -abs(raw[i]), i),
    )
    out = list(floors)
    if residual > 0:
        for k in range(residual):
            out[order[k % 3]] += 1
    elif residual < 0:
        rev = list(reversed(order))
        for k in range(-residual):
            out[rev[k % 3]] -= 1
    return out


def compute_phase_powers(
    p1_data: Dict,
    active_power: int,
) -> Tuple[Optional[int], Optional[int], Optional[int], Optional[str]]:
    """Return stored L1/L2/L3 W and an optional Q2 WARN message.

    Fills only when all three active_power_l*_w keys are present and non-null.
    Otherwise returns (None, None, None, None).
    """
    keys = ("active_power_l1_w", "active_power_l2_w", "active_power_l3_w")
    if any(k not in p1_data or p1_data[k] is None for k in keys):
        return None, None, None, None

    raw = [int(p1_data[k]) for k in keys]
    phase_sum = raw[0] + raw[1] + raw[2]

    if phase_sum == 0 and active_power != 0:
        return raw[0], raw[1], raw[2], PHASE_SUM_HARD_EDGE_WARN

    scaled = scale_phases_to_active(raw, active_power)
    return scaled[0], scaled[1], scaled[2], None
