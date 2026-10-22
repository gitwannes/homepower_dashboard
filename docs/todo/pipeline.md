# --- file: docs/todo/pipeline.md ---
# homepower_dashboard — Implementation pipeline

Ordered backlog + closed history. Specs / DoD / locks live in lettered phase files.

**Last updated:** 2026-10-22 (**M1 + M2 Done**)

**Product reference:** [`docs/architecture.md`](../architecture.md) · [`docs/deploy.md`](../deploy.md)

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

---

## Sequence

| # | Status | Size | Id | What | Detail |
|---|---|---|---|---|---|
| — | — | — | — | (none open for M) | — |

---

## Ops

| Id | Status | What |
|---|---|---|
| Ops1 | open | Confirm Synology path + Task Scheduler jobs remain SoT after migrate |
| Ops2 | open | Keep runtime `.env` in encrypted storage; place on host by hand (never via sync) |
| Ops3 | open | Fill local `helpers/p1-sync.config.json` with real SMB targets (placeholders only in working copy until edited) |

---

## Detail files

| Letter | Affinity | File |
|---|---|---|
| **M** | Migration + public-repo hygiene + local sync | [`_archive/phaseM-migration.md`](_archive/phaseM-migration.md) (**archived** — M1+M2 Done) |
