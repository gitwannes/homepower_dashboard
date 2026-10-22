# --- file: docs/todo/pipeline.md ---
# homepower_dashboard — Implementation pipeline

Ordered backlog + closed history. Specs / DoD / locks live in lettered phase files.

**Last updated:** 2026-10-22 (**A0 coded** — sync + Ops4 next; **Q4** open for A1)

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

---

## Sequence

| # | Status | Size | Id | What | Detail |
|---|---|---|---|---|---|
| 1 | coding | M | **A0** | `sql_importer`: gap-fill **staging** (not master); inverter skip if ZIP floor exists — **coded, sync to NAS** | [`phaseA-aggregation.md`](phaseA-aggregation.md) |
| 2 | open | S | **Ops4** | Purge `history_5min` + re-import `.sql` into staging + `-consolidate` + `-daily` + `-buildall` | Ops below · phase A |
| 3 | hold | S | **A1** | `-purge_derived` — **blocked on Q4** | [`phaseA-aggregation.md`](phaseA-aggregation.md) |
| 4 | open | M | **A2** | Smoke `analytics.php` after Ops4 rebuild | [`phaseA-aggregation.md`](phaseA-aggregation.md) |

---

## Ops

| Id | Status | What |
|---|---|---|
| **Ops4** | open | After **A0** on NAS: purge `history_5min` (`sql_raw`), re-queue `.sql`, run new `sql_importer` → staging gaps, then `-consolidate`, `-daily`, `-buildall`. Playbook in [`phaseA-aggregation.md`](phaseA-aggregation.md). |

---

## Detail files

| Letter | Affinity | File |
|---|---|---|
| **M** | Migration + public-repo hygiene + local sync | [`_archive/phaseM-migration.md`](_archive/phaseM-migration.md) (**archived** — M1+M2 Done) |
| **A** | Aggregation rollup + SQL→staging + rebuild + smoke | [`phaseA-aggregation.md`](phaseA-aggregation.md) |
