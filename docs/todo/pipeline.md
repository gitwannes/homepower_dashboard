# --- file: docs/todo/pipeline.md ---
# homepower_dashboard — Implementation pipeline

Ordered backlog + closed history. Specs / DoD / locks live in lettered phase files.

**Last updated:** 2026-10-26 (**P3** backfill locked in the phase file — stitch empty quarters, fall-out uses Fluvius; no code; **A11** / **A12** not kicked off; **A10** coding; **P1** row wording is Sunny Portal and stays open)

**Product reference:** [`docs/architecture.md`](../architecture.md) · [`docs/deploy.md`](../deploy.md) · [`docs/roadmap.md`](../roadmap.md)

---

## How to use

| Band | Meaning |
|---|---|
| **Done** | Closed |
| **Sequence** | Open work, in order. Status: **open** \| **coding** \| **hold** |
| **Ops** | Operator / site / non-lettered leftovers |

**Code gate:** No product code until the operator says `implement` / `ship` / `patch` after kickoff locks are in the phase file.

---

## Done

| Id | What |
|---|---|
| **M1** | Sanitize + migrate app from WLW `public/p1` — **2026-10-22** — [`_archive/phaseM-migration.md`](_archive/phaseM-migration.md) |
| **M2** | Stub WLW `public/p1` + docs pointer — **2026-10-22** — [`_archive/phaseM-migration.md`](_archive/phaseM-migration.md) |
| **Ops1** | Synology path + Task Scheduler jobs confirmed SoT after migrate — **2026-10-22** |
| **Ops2** | Runtime `.env` in encrypted storage; placed on host by hand (never via sync) — **2026-10-22** |
| **Ops3** | Local `helpers/p1-sync.config.json` filled with real sync targets — **2026-10-22** |
| **A0** | `sql_importer` staging gap-fill (not master) — **2026-10-22** — [`phaseA-aggregation.md`](phaseA-aggregation.md) |
| **Ops4** | Purge master + re-import `.sql` + consolidate/daily/buildall — **2026-10-22** |
| **A1** | Q4: `-purge_derived` keeps wiping `history_5min` (rebuildable) — leave as-is — **2026-10-22** |
| **A2** | Smoke analytics / Hall of Fame — **2026-10-22** |
| **A3 / Q3** | `analytics.php` + `summary.php` UI split; operator smoke Done — **2026-10-22** — [`_archive/phaseA-aggregation.md`](_archive/phaseA-aggregation.md) |
| **A4** | Top-N yield rankings (`topn.php` / `top_periods`) — **2026-10-25** — [`phaseA-analytics.md`](phaseA-analytics.md) |

---

## Sequence

| # | Status | Size | Id | What | Detail |
|---|---|---|---|---|---|
| 1 | kickoff | L | **P3** | Fluvius manual CSV into `fluvius_interval` for the settings EANs only. No portal script. Locked, no code | [`phaseP-portals.md`](phaseP-portals.md) |
| 2 | open | M | **A5** | Multi-series power graph (PV + grid import + home usage); ref `docs/todo/HomeWizard-graph.jpg` | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 3 | coding | L | **A6** | Settings page implemented; operator smoke not done; `DB_BACKUP_ENCRYPTION_KEY` stays in `.env` | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 4 | open | S | **A7** | `top_records`: drop or change (assess at kickoff) | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 5 | open | L | **P1** | Sunny Portal one-time history — kickoff, paid API out, not locked | [`phaseP-portals.md`](phaseP-portals.md) |
| 6 | open | L | **P2** | SMA portal ingest — source TBD at kickoff | [`phaseP-portals.md`](phaseP-portals.md) |
| 7 | open | M | **A8** | Live Solar & Consumption chart: scaled X-axis (no line across gaps), zoom to 1 week of 1-minute data, drop "(Last 2 Hours)" | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 8 | open | L | **A9** | Full DB download + restore on the A6 page (admin password); no wipe; `p1-sync` pulls `meter_history.db` to the PC | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 9 | coding | M | **A10** | Warehouse rebuild — drop `-aggregate5min` and `-today`; `-buildall` refills `history_5min` then aggregates. Locked, not closed | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 10 | open | M | **A11** | Historical gas, wind, and similar on the pages. Triaged from P3, not kicked off | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 11 | open | S | **A12** | Historical monthly Fluvius peak on a page. Triaged from P3, not kicked off | [`phaseA-analytics.md`](phaseA-analytics.md) |

---

## Ops

| Id | Status | What |
|---|---|---|
| — | — | (none open) |

---

## Detail files

| Letter | Affinity | File |
|---|---|---|
| **M** | Migration + public-repo hygiene + local sync | [`_archive/phaseM-migration.md`](_archive/phaseM-migration.md) (**archived**) |
| **A** | Aggregation warehouse + analytics UI | [`_archive/phaseA-aggregation.md`](_archive/phaseA-aggregation.md) (A0–A3 Done) · [`phaseA-analytics.md`](phaseA-analytics.md) (A4+) |
| **P** | Cloud / utility portal ingest (Sunny / SMA / Fluvius) | [`phaseP-portals.md`](phaseP-portals.md) |
