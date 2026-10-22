# --- file: docs/todo/phaseA-aggregation.md ---
# Phase A — Aggregation rollup + analytics UI

**Status:** **A0–A2 + Ops4 Done**. **A3 kickoff locked** (2026-10-22) — wait for `implement`.  
**Last updated:** 2026-10-22  
**Product pointers:** [`docs/roadmap.md`](../roadmap.md) · `rollup.py` · `sql_importer.py` · `analytics.php` · `summary.php` (new) · `index.php`

---

## Done summary

| Id | What | Closed |
|---|---|---|
| **A0** | `sql_importer` gap-fills **staging** only (snap floors; inverter skip if ZIP floor occupied; `legacy_sql`) | 2026-10-22 |
| **Ops4** | Purge `history_5min` → re-import `.sql` → `-consolidate` → `-daily` → `-buildall` | 2026-10-22 |
| **A1** | **Q4 locked:** `-purge_derived` **may** delete `history_5min` (rebuildable via consolidate+daily). **Leave code as-is.** | 2026-10-22 |
| **A2** | Smoke analytics / Hall of Fame — done (incl. yield gate for implausible E-total) | 2026-10-22 |

**Rebuild recipe after `-purge_derived`:**  
`rollup.py -consolidate` → `-daily` → `-buildall`  
(Staging + `readings` untouched.)

**Also shipped in this track (not original Sequence ids):** `idx_daily_yield`; daily yield rejects bad E-total steps (`MAX_DAY_YIELD_KWH` / `MAX_ETOTAL_STEP_KWH`).

---

## Locks (confirmed — warehouse)

| # | Lock |
|---|---|
| **L1** | Staging + `readings` = SoT; `history_5min` = derived (`-consolidate` + `-daily`). SQL never writes master. |
| **L2** | SQL inverter: skip snapped ts if any inverter staging row on that floor; else `device_serial='legacy_sql'`. |
| **L3** | Sensorbox SQL: gap-fill only when floor free (ZIP wins). |
| **L4** | Bad first `sql_raw` master: purged; re-imported via A0 path (not migrate). |
| **L5** | No value-compare gate on overlaps. |
| **L6 (Q4)** | `-purge_derived` **includes** `history_5min` + analytical tables. Staging + `readings` stay. Rebuild with consolidate/daily/buildall. |

---

## Verbatim inbox

### 2026-10-22 — aggregation smoke (triage)

> triage: test aggregation tables: now, only ingested 5-minute table is filled (17 years is complete) & 1-minute readings are polled every minute
> next up: test aggregation tables & smoke graphics screen - goal: rollup -buildall (? confirm) - test screen, then purge aggregation tables again
> give advice

### 2026-10-22 — letter A + SQL before A + purge policy (triage)

> phase letter 1
> also triage: and this is BEFORE this A: SQL import operator ToDo
> now: ALL 1-minute readings are kept (confirm)
> wipe 17-year master is NOT the goal - what needs to change for this? this history table should NEVER be purged, only the derivec/merged 5-min (and other aggregation) tables

### 2026-10-22 — kickoff (architecture + history_5min todo)

> kickoff + give todo list of what to do with the current history_5min table (purge it?)

### 2026-10-22 — kickoff (purge + re-import SQL correct way)

> I prefer purging the 5m table and importing from the .sql files again, the correct way - kickoff that
> 2: yes
> re-assess

### 2026-10-22 — close A1/A2 + triage UI split

> A1 / Q4: -purge_derived  can purge history_5min, it can be rebuilt, correct? if yes: leave it
> Q2: smoke done
> UI split: triage first

### 2026-10-22 — UI split (earlier ask, now triaged)

> Top 10 Days still loads slow - is it an idea to put this on another page , together with the 2-day overlay, and keep the analytics.php for aggregation tables - rename analysits to aggregation.php and create th new analytics for top10 and 2-day compare
> also, extend the aggregation page with weekly, monthly and yearly graphs

### 2026-10-22 — A3 fold advisor naming + arrows + kickoff

> change ui as you proposed + add left & right arrows to all summary tables (daily/week/month/year) to jump to previous/next day/week/...
> fold into Q3, then kickoff

### 2026-10-22 — A3 kickoff answers (locked)

> 1: ok
> 2: keep
> 3: yes
> 4: daily_history (tday will not be in the 5-min chart since the daily rollup hans't run - confirm)
> 5: wrap
> 6: yes

---

## A3 — UI split + period summaries (**kickoff locked**)

### Locks

| # | Lock |
|---|---|
| **A3-L1** | Names: **`analytics.php`** = exploration; **`summary.php`** = period summaries. **No** `aggregation.php`. |
| **A3-L2** | **`analytics.php`:** **Intraday Explorer** (keep) + Two-Day Overlay + Hall of Fame (Top 10). Remove Yearly Trends tab (moved to summary). |
| **A3-L3** | **`summary.php`:** modes **Day / Week / Month / Year** — charts + compact stats from **`daily_history` / `monthly_history`** (not `history_5min` for these modes). |
| **A3-L4** | **← / →** on every summary mode: prev/next day, ISO week, calendar month, year. **Wrap** at data range edges (last→first / first→last among available periods). |
| **A3-L5** | Yearly trends / year graph live on **`summary.php`** year mode (incl. PVGIS baseline as today). |
| **A3-L6** | Cross-nav: `index.php` ↔ `analytics.php` ↔ `summary.php`. Update product docs (`architecture.md`, `README`, roadmap pointers). |
| **A3-L7** | Week = **ISO week Mon–Sun**, label `YYYY-Www`. |
| **A3-L8** | Summary **Day** mode = **`daily_history` only** (no embedded 5-min chart). Intraday curve stays on analytics. |
| **A3-L9** | UI language: **English**. |
| **A3-L10** | Perf: split only; no Top-10 cache layer in A3. |

### Today vs rollups (confirmed)

| View | Data | “Today” |
|---|---|---|
| **Analytics → Intraday** | `history_5min` | Can show **today** as soon as consolidate/live/`-daily` has written 5-min slots. |
| **Summary → Day** | `daily_history` | **Today appears only after** a daily rollup has inserted/updated that date (`rollup.py -daily` or `-buildall`). Until then, day picker/arrows only walk dates that exist in `daily_history`. |

So: today is **not** missing from the 5-min chart because of daily rollup — that chart does not use `daily_history`. Today **is** missing from **summary day** until `-daily` / `-buildall` runs. [Certain]

### DoD (when implementing)

- [ ] `summary.php` with Day/Week/Month/Year + wrap arrows
- [ ] `analytics.php` trimmed (intraday + compare + records); yearly tab removed
- [ ] Nav links on index / analytics / summary
- [ ] Product docs updated
- [ ] Smoke: wrap at ends; ISO week label; year vs PVGIS; Top 10 still loads

**Code gate:** wait for `implement`.

---

## Out of scope (A3)

- Fixing Top 10 latency beyond split + existing index (no cache layer unless new item)
- Auto-including “today” on summary before `-daily` has run
- Pruning `readings` / nightly `-daily` scheduler
- Notifications
- `-aggregate5min` changes
- Flemish UI
