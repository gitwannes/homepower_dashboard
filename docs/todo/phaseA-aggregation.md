# --- file: docs/todo/phaseA-aggregation.md ---
# Phase A — Aggregation rollup + analytics smoke

**Status:** **A0 coded** (2026-10-22) — sync `sql_importer.py` to NAS, then Ops4; **Q4** still open for A1  
**Last updated:** 2026-10-22  
**Product pointers:** [`docs/roadmap.md`](../roadmap.md) · `rollup.py` · `sql_importer.py` · `analytics.php`

---

## Depends on

| Id | What | Gate |
|---|---|---|
| **A0** | `sql_importer` → staging gap-fill | Code before Ops4 re-import |
| **Ops4** | Purge master + re-import `.sql` into staging + rebuild | Blocks A2 smoke |

---

## Verbatim inbox

### 2026-10-22 — aggregation smoke (triage)

> triage: test aggregation tables: now, only ingested 5-minute table is filled (17 years is complete) & 1-minute readings are polled every minute
> next up: test aggregation tables & smoke graphics screen - goal: rollup -buildall (? confirm) - test screen, then purge aggregation tables again
> give advice

### 2026-10-22 — letter A + SQL before A + purge policy (triage)

> phase letter 1
> also triage: and this is BEFORE this A: SQL import operator ToDo
> now: ALL 1-minute readings are kept (confirm)
> wipe 17-year master is NOT the goal - what needs to change for this? this history table should NEVER be purged, only the derivec/merged 5-min (and other aggregation) tables

### 2026-10-22 — kickoff (architecture + history_5min todo)

> kickoff + give todo list of what to do with the current history_5min table (purge it?)

### 2026-10-22 — kickoff (purge + re-import SQL correct way)

> I prefer purging the 5m table and importing from the .sql files again, the correct way - kickoff that
> 2: yes
> re-assess

---

## Kickoff — verified facts

| Fact | Source |
|---|---|
| Commands run: `importer.py -outerzip`, `logger.py -poll`, `sql_importer.py` only (no `-aggregate5min` / `-consolidate` / `-daily`) | Operator |
| Staging filled from ZIP: sensorbox ~1.74M (2009→2026), inverter ~0.88M (2009→2026) | NAS counts |
| `history_5min` = **only** `sql_raw`, 1,058,916 rows, 2010-03-27 → 2020-06-27 | `GROUP BY data_source` |
| `readings` = live ~2.6k rows (~2 days) | NAS counts |
| Timestamp overlap (snapped): both 511,332 · sb-only 491,496 · inv-only 2 · history-only **56,086** | Operator run 2026-10-22 |
| `-purge_derived` today deletes `history_5min` + analytical tables | `rollup.py` |
| `sql_importer` today writes **master** columns only | Code after 2026-10-22 fix |

---

## Locks (confirmed)

| # | Lock |
|---|---|
| **L1** | **SoT model:** Staging (`sensorbox_history_5min`, `inverter_history_5min`) + `readings` = sources of truth. `history_5min` = **derived only** (`-consolidate` + `-daily`). SQL never writes the master again. |
| **L2** | **SQL inverter gap rule:** If any inverter staging row exists for that snapped 5-min ts, **skip** SQL inverter insert for that ts (no site-total beside ZIP devices). Synthetic `device_serial` = `legacy_sql` when inserting. |
| **L3** | **Sensorbox SQL:** snap + `INSERT OR IGNORE` on `timestamp` (ZIP wins on overlap; SQL fills holes). |
| **L4** | **Current `sql_raw` master:** **Do not migrate.** Purge `history_5min`, then **re-import from `.sql`** via redesigned importer into staging. |
| **L5** | **Value-compare on 511k overlaps:** **Not required** — trust ZIP staging on conflicts; SQL is gap-fill only. |

*(Earlier triage “never purge history_5min” is **superseded** by L1+L4: master is disposable/rebuildable.)*

---

## Still open (block final lock / A1 code)

| # | Question |
|---|---|
| **Q4** | After L1: should `-purge_derived` **also** `DELETE FROM history_5min` (full derived wipe, then re-consolidate/-daily/-buildall), or only analytical tables (`daily_history`, `monthly_history`, `clear_sky_envelopes`, `top_records`) and leave master until an explicit rebuild command? |

---

## A0 — code (after `implement`)

Redesign `sql_importer.py`:

1. Parse `wpv_raw` as today.
2. Snap timestamps to 5-min floor.
3. Write **sensorbox_history_5min** (rad/temps/wind) with `INSERT OR IGNORE`.
4. Write **inverter_history_5min** only when snapped ts has **zero** existing inverter rows; one row with `device_serial='legacy_sql'`, PAC / pac_max / etotal from dump.
5. **Do not** insert into `history_5min`.
6. Ensure staging schemas exist (same as `importer.py` / `logger.py`).

---

## Ops4 — operator playbook (after A0 shipped to NAS)

| # | Action |
|---|---|
| 0 | Optional: backup `meter_history.db` |
| 1 | Ensure `.sql` is back in `import_queue/` (move out of `processed/` if needed) |
| 2 | `DELETE FROM history_5min;` (optional `VACUUM`) — discard current `sql_raw` master |
| 3 | Run redesigned `python3 sql_importer.py` → gap-fill **staging** |
| 4 | Spot-check: staging counts; sample `legacy_sql` rows; confirm no double device+legacy on same ts |
| 5 | `python3 rollup.py -consolidate` |
| 6 | `python3 rollup.py -daily` |
| 7 | `python3 rollup.py -buildall` |
| 8 | Smoke `analytics.php` (**A2**) |

**Order note:** Purge master (2) may run before or right after A0 deploy; must run **before** consolidate so master is rebuilt clean from staging (+ later daily). Re-import (3) does not require master to be empty, but empty master avoids confusion.

---

## A1 / A2 (after Q4)

- **A1:** Align `-purge_derived` (+ help text) with Q4 answer.
- **A2:** Smoke graphics; optional re-purge drill per A1.

---

## Sequence (this phase)

| Id | What |
|---|---|
| **A0** | `sql_importer` → staging gap-fill (L1–L5) |
| **Ops4** | Purge `history_5min` + re-import `.sql` + consolidate + daily + buildall |
| **A1** | `-purge_derived` per Q4 |
| **A2** | Smoke `analytics.php` |

---

## Out of scope

- Pruning `readings` (roadmap Phase 4)
- Nightly Task Scheduler for `-daily` (manual OK for Ops4)
- Notifications
- `-aggregate5min` changes
- Migrating existing `sql_raw` rows into staging
