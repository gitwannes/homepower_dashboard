# --- file: docs/todo/phaseA-aggregation.md ---
# Phase A — Aggregation rollup + analytics UI

**Status:** **Done** (A0–A3 + Ops4) 2026-10-22 — **ready for offline archive**.  
**Last updated:** 2026-10-22 (close-out: Q3/A3 operator smoke done)  
**Product reference (canonical):** [`docs/architecture.md`](../architecture.md) · [`docs/roadmap.md`](../roadmap.md) · `rollup.py` · `analytics.php` · `summary.php`

> **Operator:** Phase A is closed. Manually move this file out of the repo into offline archive (same pattern as Phase M). Do not leave long-term product truth here.

---

## Done summary

| Id | What | Closed |
|---|---|---|
| **A0** | `sql_importer` staging gap-fill only | 2026-10-22 |
| **Ops4** | Purge `history_5min` → re-import `.sql` → consolidate/daily/buildall | 2026-10-22 |
| **A1** | `-purge_derived` may wipe `history_5min` (rebuildable) — leave as-is | 2026-10-22 |
| **A2** | Smoke analytics / Hall of Fame path | 2026-10-22 |
| **A3 / Q3** | UI split: `analytics.php` explore + `summary.php` periods; operator smoke Done | 2026-10-22 |

**Also shipped in this track:** yield gates; PAC night/rad gates; clear-sky chart; All years on summary; **Generate table** / `rollup.py -today`; localtime downsample fix.

**Rebuild after `-purge_derived`:** `-consolidate` → `-daily` → `-buildall` (staging + `readings` untouched).

---

## Delivery locks (pointers only)

Warehouse L1–L6 and A3-L1–L10: see shipped behaviour in [`docs/architecture.md`](../architecture.md) (SoT vs derived, UI map, rollup CLI, PAC gates).

---

## Verbatim inbox (delivery record)

### 2026-10-22 — fold into Q3 + kickoff

> change ui as you proposed + add left & right arrows to all summary tables (daily/week/month/year) to jump to previous/next day/week/...
> fold into Q3, then kickoff

### 2026-10-22 — A3 kickoff answers

> 1: ok / 2: keep / 3: yes / 4: daily_history … / 5: wrap / 6: yes

### 2026-10-22 — close

> Q3 is done, I still see that file in todo - check doc & close

---

## A3 DoD (closed)

- [x] `summary.php` week/month/year (+ All years post-ship)
- [x] `analytics.php` intraday + compare + envelope; Top 10 removed from UI
- [x] Cross-nav; product docs match shipped code
- [x] Operator smoke on NAS (Q3 Done)

---

## Out of scope (carried forward only if re-triaged)

- Top 10 UI / cache layer
- Auto-prune `readings`
- Notifications (roadmap Phase 3)
- Flemish UI
