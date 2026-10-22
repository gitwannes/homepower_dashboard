# --- file: docs/todo/pipeline.md ---
# homepower_dashboard — Implementation pipeline

Ordered backlog + closed history. Specs / DoD / locks live in lettered phase files.

**Last updated:** 2026-10-22 (**A0–A3 / Q3 + Ops4 Done** — Phase A ready for offline archive)

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
| **A3 / Q3** | `analytics.php` + `summary.php` UI split; operator smoke Done — **2026-10-22** — [`phaseA-aggregation.md`](phaseA-aggregation.md) (**archive ready**) |

---

## Sequence

| # | Status | Size | Id | What | Detail |
|---|---|---|---|---|---|
| — | — | — | — | (none open) | — |

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
| **A** | Aggregation warehouse + analytics UI | [`phaseA-aggregation.md`](phaseA-aggregation.md) (**Done — move offline**) |
