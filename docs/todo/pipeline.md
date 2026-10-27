# --- file: docs/todo/pipeline.md ---
# homepower_dashboard — Implementation pipeline

Ordered backlog + closed history. Specs / DoD / locks live in lettered phase files.

**Last updated:** 2026-10-27 (**A5** closed; **A5b** kickoff locked)

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
| **A0** | `sql_importer` staging gap-fill (not master) — **2026-10-22** — [`_archive/phaseA-aggregation.md`](_archive/phaseA-aggregation.md) |
| **Ops4** | Purge master + re-import `.sql` + consolidate/daily/buildall — **2026-10-22** |
| **A1** | Q4: `-purge_derived` keeps wiping `history_5min` (rebuildable) — leave as-is — **2026-10-22** |
| **A2** | Smoke analytics / Hall of Fame — **2026-10-22** |
| **A3 / Q3** | `analytics.php` + `summary.php` UI split; operator smoke Done — **2026-10-22** — [`_archive/phaseA-aggregation.md`](_archive/phaseA-aggregation.md) |
| **A4** | Top-N yield rankings (`topn.php` / `top_periods`) — **2026-10-25** — [`phaseA-analytics.md`](phaseA-analytics.md) |
| **A6** | Settings page (`settings.php`, `app_settings`) — **2026-10-26** — [`phaseA-analytics.md`](phaseA-analytics.md) · [`architecture.md`](../architecture.md) |
| **A10** | Warehouse rebuild: `-buildall` refills `history_5min` from `readings` and WebBox staging; `-aggregate5min` and `-today` removed — **2026-10-26** — [`phaseA-analytics.md`](phaseA-analytics.md) · [`architecture.md`](../architecture.md) |
| **P3** | Fluvius manual CSV into `fluvius_interval`; electricity quarter backfill on `-buildall` / `-daily`. NAS CSV import smoked; `-buildall` not run — **2026-10-26** — [`phaseP-portals.md`](phaseP-portals.md) · [`architecture.md`](../architecture.md) |
| **A8** | Live chart clock window, presets through 7 days, drag zoom, gaps — **2026-10-27** — [`phaseA-analytics.md`](phaseA-analytics.md) · [`architecture.md`](../architecture.md) |
| **A9** | Settings encrypted database download and restore (`db_backup.py`); no synctool pull — **2026-10-27** — [`phaseA-analytics.md`](phaseA-analytics.md) · [`architecture.md`](../architecture.md) |
| **A13** | Grid import/export mode on summary and top-n. Analytics stays in A5 — **2026-10-27** — [`phaseA-analytics.md`](phaseA-analytics.md) · [`architecture.md`](../architecture.md) |
| **A5** | Balance chart (Balance/Classic, three painted bands, Fluvius W on analytics day) — **2026-10-27** — [`phaseA-analytics.md`](phaseA-analytics.md) · [`architecture.md`](../architecture.md) |

---

## Sequence

| # | Status | Size | Id | What | Detail |
|---|---|---|---|---|---|
| 1 | open | M | **A5b** | Balance explorer (window+pan, mixed 1/5-min V); index 2h pulse; CSV to Settings; drop telemetry table — kickoff locked | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 2 | open | S | **A7** | `top_records`: drop or change (assess at kickoff) | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 3 | open | L | **P1** | Sunny Portal one-time history — kickoff, paid API out, not locked | [`phaseP-portals.md`](phaseP-portals.md) |
| 4 | open | L | **P2** | SMA portal ingest — source TBD at kickoff | [`phaseP-portals.md`](phaseP-portals.md) |
| 5 | open | M | **A11** | Historical gas, wind, and similar on the pages. Triaged from P3, not kicked off | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 6 | open | S | **A12** | Historical monthly Fluvius peak on a page. Triaged from P3, not kicked off | [`phaseA-analytics.md`](phaseA-analytics.md) |

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
