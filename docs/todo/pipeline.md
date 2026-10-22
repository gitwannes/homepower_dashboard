# --- file: docs/todo/pipeline.md ---
# homepower_dashboard — Implementation pipeline

Ordered backlog + closed history. Specs / DoD / locks live in lettered phase files.

**Last updated:** 2026-10-22 (M locks done; **implement HOLD**)

**Product reference:** [`docs/architecture.md`](../architecture.md)

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
| — | Scaffold + **M locks** documented 2026-10-22 (no app migrate yet) |

---

## Sequence

| # | Status | Size | Id | What | Detail |
|---|---|---|---|---|---|
| 1 | hold | mid | M1 | Sanitize + migrate WLW `public/p1` (locks done; wait `implement`) | [`phaseM-migration.md`](phaseM-migration.md) |
| 2 | open | low | M2 | Retire / stub WLW `public/p1`; update WLW docs | phaseM |

---

## Ops

| Id | Status | What |
|---|---|---|
| Ops1 | open | Confirm Synology path + Task Scheduler jobs remain SoT after migrate |
| Ops2 | open | Keep runtime `.env` in encrypted storage; place on host by hand (never via sync) |

---

## Detail files

| Letter | Affinity | File |
|---|---|---|
| **M** | Migration + public-repo hygiene + local sync | [`phaseM-migration.md`](phaseM-migration.md) (**LOCKED**; implement HOLD) |
