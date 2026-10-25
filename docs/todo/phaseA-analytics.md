# --- file: docs/todo/phaseA-analytics.md ---
# Phase A (continued) — Analytics UI + plant config

**Status:** **A4 Done**; A5–A7 queued. (A0–A3 Done — [`_archive/phaseA-aggregation.md`](_archive/phaseA-aggregation.md))  
**Last updated:** 2026-10-25  
**Product pointers:** [`docs/architecture.md`](../architecture.md) · `topn.php` · `rollup.py`

---

## Sequence (this file)

| Id | Status | What |
|---|---|---|
| **A4** | Done | Top-N tables (days / months / years) + graphs |
| **A5** | open | Multi-series power graph (PV + grid import + home usage) — ref image |
| **A6** | open | Plant config: max converter power + expected generation (env vs config at kickoff) |
| **A7** | open | `top_records`: drop or change (assess at kickoff) |

**Parallel:** A4–A7 independent of **P1–P3**.

**Related:** A3 removed Hall of Fame UI. A4 is new Top-N. A7 decides fate of leftover `top_records` singles table.

---

## Verbatim inbox

### 2026-10-23 — triage (three items)

> also triage:
> - create table for top n days, months years & create graphs for it
> - add , next to PV power generation, power pull from net and net power usage (more or less the graph like HomeWizard-graph.jpg - image is saved in \docs\todo in repo)
> - add to .env (or maybe split env and config, to assess at kickoff): max converter power (and remove spikes based on this), expected power generation for the plant (now hardcoded)

### 2026-10-23 — A4 kickoff answers + top_records triage

> 1: top 20 day, top 10 mointh & year
> 2: ??
> 3: also worst days/months/years - that what you mean?
> 4: new page
> 5: generated now with -buildall, and also with -daily
> 6: good idea: clicking on a bar will show that day/month/year in the other page
> 7: what does top_records do?

> 2A
> 7B + add to triage to either drop or change

---

## A4 — Top-N days / months / years + graphs

**Status:** **Done** 2026-10-25  
**Product:** [`docs/architecture.md`](../architecture.md) (`topn.php`, `top_periods`).

### Done summary

Best/worst yield rankings (20 days, 10 months, 10 years) in `top_periods` via `-buildall`/`-daily`/`-today`; UI `topn.php` with click-through. Worst days exclude yield ≤ 0 and today. `top_records` unchanged → **A7**.

### DoD

- [x] Storage + rollup + page + deep links + nav + product docs
- [x] Operator close (smoke)

### Out of scope (A4)

- Peak-W Top-N; drop/change `top_records` (**A7**); old live Top-10 AJAX

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

## A7 — `top_records`: drop or change

**Status:** open (not kicked off)  
**Scope (placement):** Decide whether to **drop** `top_records` (+ stop `build_top_records` in rollup) or **change** it (new purpose / UI).  
**Context:** Single-row extremes (best day, peak surge, wind, temps). Unused in UI since A3. A4 covers Top-N yield lists instead.  
**Locks:** drop vs redesign — kickoff.

### Verbatim

> 7B + add to triage to either drop or change

---

## Out of scope (until re-triaged)

- Portal ingest (**P1–P3**)
- Restoring the old slow Top 10 AJAX query as-is
