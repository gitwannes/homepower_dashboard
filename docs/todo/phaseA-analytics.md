# --- file: docs/todo/phaseA-analytics.md ---
# Phase A (continued) — Analytics UI + plant config

**Status:** A4–A6 queued. (A0–A3 Done — [`_archive/phaseA-aggregation.md`](_archive/phaseA-aggregation.md))  
**Last updated:** 2026-10-23  
**Product pointers:** [`docs/architecture.md`](../architecture.md) · `analytics.php` · `summary.php` · `index.php` · `rollup.py` · `expected_monthly`

---

## Sequence (this file)

| Id | Status | What |
|---|---|---|
| **A4** | open | Top-N tables (days / months / years) + graphs |
| **A5** | open | Multi-series power graph (PV + grid import + home usage) — ref image |
| **A6** | open | Plant config: max converter power + expected generation (env vs config at kickoff) |

**Parallel:** A4–A6 are independent of **P1–P3** (portal ingest); kickoff order TBD by operator.

**Related (not open duplicates):** A3 removed Hall of Fame / Top 10 UI (slow). `top_records` still exists for single extremes. **A4** is the new Top-N design (days/months/years + graphs) — not a silent reopen of the old Top 10 page.

---

## Verbatim inbox

### 2026-10-23 — triage (three items)

> also triage:
> - create table for top n days, months years & create graphs for it
> - add , next to PV power generation, power pull from net and net power usage (more or less the graph like HomeWizard-graph.jpg - image is saved in \docs\todo in repo)
> - add to .env (or maybe split env and config, to assess at kickoff): max converter power (and remove spikes based on this), expected power generation for the plant (now hardcoded)

---

## A4 — Top-N days / months / years + graphs

**Status:** open (not kicked off)  
**Scope (placement):** Derived table(s) for top-N periods + UI graphs.  
**Ref:** replaces/supersedes removed Top 10 UI intent; broader (months/years).  
**Locks:** N, schema, which page (`summary` / `analytics` / both), rebuild hook — kickoff.

---

## A5 — Multi-series power (PV / grid pull / home usage)

**Status:** open (not kicked off)  
**Scope (placement):** Chart series beside PV: power from grid + net/home usage, in the spirit of [`HomeWizard-graph.jpg`](HomeWizard-graph.jpg).  
**Locks:** Which page (`index` live vs `analytics` intraday vs both), exact series definitions (P1 channels), resolution — kickoff.

---

## A6 — Plant config (max PAC + expected generation)

**Status:** open (not kicked off)  
**Scope (placement):** Configurable max converter power (drive spike rejection instead of hardcoded `MAX_PLAUSIBLE_PAC_W`); plant expected generation (today inclination=30 / `expected_monthly` + code constants). Assess **`.env` vs split config file** at kickoff.  
**Locks:** file home, key names, whether expected stays DB-seeded vs config-only — kickoff.

---

## Out of scope (until re-triaged)

- Portal ingest (**P1–P3**)
- Restoring the old slow Top 10 AJAX query as-is
