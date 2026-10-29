# --- file: docs/todo/pipeline.md ---
# homepower_dashboard — Implementation pipeline

Ordered backlog + closed history. Specs / DoD / locks live in lettered phase files.

**Last updated:** 2026-10-29 (**A16** Done)

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
| **A7** | `top_records` on `topn.php`; `-daily` beat; `-buildalltoprecords` — **2026-10-27** — [`phaseA-analytics.md`](phaseA-analytics.md) · [`architecture.md`](../architecture.md) |
| **A5b** | Explorer + index 2h pulse; mixed 1/5-min V; Exchange default; CSV→Settings; drop telemetry — **2026-10-27** — [`phaseA-analytics.md`](phaseA-analytics.md) · [`architecture.md`](../architecture.md) |
| **P2** | Closed as duplicate of **P1** (same ennexOS / `portal.sma.energy` login; no second Classic plant) — **2026-10-27** — [`phaseP-portals.md`](phaseP-portals.md) |
| **P1** | Sunny Portal / ennexOS PV: `sunnyportal_pull.py` → `sunnyportal_pv_5min`; rollup empty-fill; `.env` cleanup. Non-PV after `-probe` out until re-triaged — **2026-10-28** — [`phaseP-portals.md`](phaseP-portals.md) · [`architecture.md`](../architecture.md) |
| **A12** | Capacity peak on summary + top-n (Fluvius `piekvermogen` + live P1 open month) — **2026-10-29** — [`phaseA-analytics.md`](phaseA-analytics.md) · [`architecture.md`](../architecture.md)
| **Ops6** | Task Scheduler daily 00:05 `rollup.py -daily` — **2026-10-28** — [`roadmap.md`](../roadmap.md) · [`deploy.md`](../deploy.md) |
| **A16** | Per-phase W on `readings` + logger scale to `active_power` — **2026-10-29** — [`phaseA-analytics.md`](phaseA-analytics.md) · [`architecture.md`](../architecture.md) |

---

## Sequence

| # | Status | Size | Id | What | Detail |
|---|---|---|---|---|---|
| 1 | open | S | **A17** | Rollup: carry per-phase W into `history_5min` (depends on A16). Not kicked off | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 2 | open | M | **A18** | UI: show / chart per-phase power (depends on A16; A17 if 5-min views). Not kicked off | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 3 | open | M | **A11** | Historical gas, wind, and similar on the pages. Triaged from P3, not kicked off | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 4 | open | M | **A14** | Virtual kWh meters from Settings (bias to import / export / PV) | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 5 | open | S | **A15** | Settings save: probe every filled integration; fail → modal, stay on page | [`phaseA-analytics.md`](phaseA-analytics.md) |
| 6 | open | M | **W1** | WanOS: this app pushes on `-daily` and when raw readings are opened | [`phaseW-wanos.md`](phaseW-wanos.md) |
| 7 | open | S | **Ops5** | `meter_history.db` → Synology Hyper Backup (roadmap Phase 4) | [`roadmap.md`](../roadmap.md) |
| 8 | open | M | **Ops7** | `notifier.py` (roadmap Phase 3) | [`roadmap.md`](../roadmap.md) |

---

## Ops

Open Ops rows are also in **Sequence** (same ids). Done Ops stay in **Done** only.

| Id | Status | What |
|---|---|---|
| **Ops5** | open | `meter_history.db` → Synology Hyper Backup (roadmap Phase 4) |
| **Ops7** | open | `notifier.py` (roadmap Phase 3) |

---

## Detail files

| Letter | Affinity | File |
|---|---|---|
| **M** | Migration + public-repo hygiene + local sync | [`_archive/phaseM-migration.md`](_archive/phaseM-migration.md) (**archived**) |
| **A** | Aggregation warehouse + analytics UI | [`_archive/phaseA-aggregation.md`](_archive/phaseA-aggregation.md) (A0–A3 Done) · [`phaseA-analytics.md`](phaseA-analytics.md) (A4+) |
| **P** | Cloud / utility portal ingest (P1–P3 Done — move file offline when convenient) | [`phaseP-portals.md`](phaseP-portals.md) |
| **W** | WanOS outbound bridge (push from this app) | [`phaseW-wanos.md`](phaseW-wanos.md) |
