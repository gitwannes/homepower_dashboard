# ENERGY DASHBOARD & ARCHIVE ROADMAP

## PHASE 0: RAW INGESTION & DATA SANITIZATION (CURRENT)
- [x] Schema parity verified between Synology nodes (1-minute `readings` table structured identically).
- [x] Standardize 5-minute schema (`sensorbox_history_5min`, `inverter_history_5min`, `expected_monthly`).
- [x] Configure CLI-only master archive mode (`importer.py -outerzip`) with live extraction progress.
- [x] Implement strict regex isolation (`wb<serial>.<timestamp>.(zip|7z)`) so web importer never touches master archives.
- [x] Direct consolidation mode (`importer.py -aggregate5min`) — removed in A10. Import writes staging only. `rollup.py -consolidate` is the path into `history_5min`.
- [ ] **[IN PROGRESS]** Run full historical ingestion of 17-year archive into SQLite tables.

---

### THE INGESTION & AGGREGATION SEQUENCE

**Step 1: Finish ZIP Import & Verify**
Let the active `importer.py` complete. Verify all archives moved to `import_queue/processed/` and inspect rows:
```bash
python3 -c "
import sqlite3
conn = sqlite3.connect('meter_history.db')
cur = conn.cursor()
for tbl in ['sensorbox_history_5min', 'inverter_history_5min', 'history_5min']:
    try:
        cnt, start_t, end_t = cur.execute(f'SELECT COUNT(*), MIN(timestamp), MAX(timestamp) FROM {tbl}').fetchone()
        print(f'{tbl:<26}: {cnt:>10,} rows | {start_t} --> {end_t}')
    except sqlite3.OperationalError:
        pass
conn.close()
"
```

**Step 2: Consolidate staging into `history_5min`**
Plain `importer.py` writes `sensorbox_history_5min` and `inverter_history_5min` only. Then:
```bash
python3 rollup.py -consolidate
```

**Step 3: SQL gap-fill**
Already ran (A0). `sql_importer.py` is not in this repo. A10 does not restore it. `-buildall` refills `history_5min` from `readings` and from the WebBox staging tables.

**Step 4: Defragment and Optimize the Database**
Decompressing and bulk-inserting millions of rows creates B-tree page fragmentation. Reclaim unallocated disk space and update SQLite's internal query planner statistics:
```bash
sqlite3 meter_history.db "VACUUM; PRAGMA optimize;"
```

**Step 5: Run the Rollup Engine (`rollup.py`)**
`-buildall` deletes `history_5min`, `daily_history`, and `monthly_history`, refills `history_5min` from all `readings` (open 5-minute slice skipped), from WebBox staging, empty-fills `solar_power_w` from `sunnyportal_pv_5min`, then stitches `fluvius_interval` electricity quarters, then builds `daily_history`, `monthly_history`, `clear_sky_envelopes`, `top_records`, and `top_periods`. Rare. The day chart is empty until the command finishes. Run `fluvius_import.py` and/or `sunnyportal_pull.py` first when those staging sources need a refresh. `-buildallsunnyportal` empty-fills solar only. `-buildalltoprecords` rewrites `top_records` only. `-daily` also beat-updates `top_records` for the recent calendar span.
```bash
python3 rollup.py -buildall
```

**Step 6: Audit Using `wpv_periodicvalues`**
Compare new `daily_history` yields against the old MariaDB `wpv_periodicvalues` table to prove mathematical consistency across the 17-year history.

---

## PHASE 1: AGGREGATION ENGINE (DATA FOUNDATION)
- [x] Design and create the 3-Tier Data Architecture schemas:
      - `history_5min` (Consolidated 17-year master table).
      - `daily_history` (date, solar_yield_kwh, solar_peak_w, sun_hours, grid_import_kwh, grid_export_kwh, self_consumption_kwh, temps, wind, radiation).
      - `monthly_history` & `expected_monthly` (PVGIS baseline tracking).
      - `clear_sky_envelopes` (month, timepoint [0..287], max_pac_w, max_pac_date, max_rad_wm2). A24: envelope rebuild requires radiation above PAC rad floor (WebBox kept).
- [x] Build `rollup.py` (CLI & Cron Worker) Framework:
      - [x] Mode A: Bridge Consolidation (`-consolidate`) snaps staging timestamps and UPSERTs into `history_5min`.
      - [x] Mode B: Incremental daily run (computes yesterday's final numbers at midnight into `daily_history`).
      - [x] Mode C: Envelope builder (computes the 288-point theoretical ceiling curves from 5-min history).
      - [x] Mode D: Record tracker (finds Top 10 best/worst days, max wind gust, max inverter surge).
- [x] 1-Minute to 5-Minute Hot Bridge:
      - Established the floor-snapping mathematical rule (`:00, :05`) and the reconciliation matrix for merging P1 digital meter data with WebBox hardware data cleanly.

---

## PHASE 2: MODERN UI & ANALYTICS SUITE (PRESENTATION)
- [x] Multi-page dashboard:
      - Live (`index.php`): 2-hour pulse of 1-minute readings; Exchange / Balance / Classic (Exchange default). Exploration (`analytics.php`), period summaries (`summary.php`).
      - Weather (`weather.php`): explorer + Week / Month / Year / **All years** from daily/monthly **averages** (A27); Records = wind speed max, module hi/lo, radiation max.
      - Cross-navigation between hubs.
- [x] Day Explorer on `analytics.php` (**A5b** + **A19**):
      - Exchange / Balance / Classic explorer: rolling presets plus calendar-day **Today** (default; chip after 24 h); day pan with arrow caps; mixed 1-min / 5-min (rule V). Serial Apex mounts (explorer then compare).
- [x] Two-Day Overlay on `analytics.php`.
- [x] Summary period views (`summary.php`):
      - Week, month, year, then **All years** last (lifetime bars vs live `expected_monthly` PVGIS; week/month use daily-share line; omit PVGIS when expected is 0).
      - **A13 Done:** Solar yield / Grid switch. Grid mode is grouped bars (import, export, net). A second switch shows all three, import/export only, or net only.
      - **A12 Done:** Capacity peak (`?metric=peak`) from Fluvius `piekvermogen` (kW); open month = live P1; year + All years only; Max/Average on All years (shared Y). Deep link `?month=` also sets week + year.
- [x] Hall of Fame / Top 10:
      - Old live Top 10 removed in A3. **A4 Done:** `topn.php` + `top_periods` (best/worst days 20, months/years 10); rebuilt by `-buildall`/`-daily`. Worst days skip zero yield and today.
      - **A13 Done:** the same page ranks highest net import and highest net export from `daily_history` / `monthly_history` (20 days, 10 months, 10 years). Both day lists omit today. No second ranking table.
      - **A7 Done:** all-time singles on `topn.php` (`top_records`); `-daily` beat + `-buildalltoprecords`.
      - **A12 Done:** Capacity peak ranks (top 10 months); records filtered by metric (yield / peak; weather records on `weather.php` / **A11** Done).
      - **A22:** Virtual 'best-day' (from `clear_sky_envelopes`) on solar Top-N; removed from analytics.
      - **A24 Done:** envelope rebuild requires rad above floor; WebBox archive kept.
      - **A26 Done:** `rollup.py -purge_webbox_plus4h` removes +4h `:00` Pac/rad clones from WebBox staging; then `-buildall`. Virtual 'best-day' shows composite-day kWh and integer PAC axis.
      - **A27 Done:** weather period cards use daily/monthly averages; Records = wind speed max, module hi/lo, radiation max.
- [x] Settings (`settings.php`): admin password on first open; locked-by-default Unlock window (countdown); devices, titles, monthly expected kWh, spike cap, and night gates in `meter_history.db`. Save probes P1/SMA/WebBox/Portal and writes `*_active` (`integration_probes.py`); partial fill is atomic. Virtual kWh meters (A14) + optional Raw Meter Registers rows (A21). Encrypted full-database download and restore (`db_backup.py`); 1-min CSV export without password. See [`architecture.md`](architecture.md).
- [x] Verify analytics / summary with populated rollups (A2/A3 + operator smoke).

---

## PHASE 3: NOTIFICATIONS & MILESTONE MONITORING
- [ ] Build `notifier.py` / Synology Notification Hook:
      - MWh Milestone Tracker: Detect when cumulative lifetime yield crosses an integer MWh boundary (e.g., crossing 52,000 kWh).
      - Inverter Fault Alerter: Notify if inverter status remains offline during peak daylight hours.
      - Monthly Summary Delivery: Send an automated email/webhook on the 1st of each month with previous month's performance vs PVGIS target.
- [ ] Synology DSM Integration:
      - Send notifications via Synology DSM Notification Center (Webhook/SMS/Email) or local SMTP.

---

## PHASE 4: SYSTEM MAINTENANCE & AUTOMATION
- [x] Implement live hardware diagnostic utility (`powertests.py` & `powertests.php`). It reads site settings from `meter_history.db` and probes P1, SMA, and WebBox. It does not write telemetry.
- [ ] Implement `readings` hot-buffer retention strategy:
      - Once 1-minute data is downsampled and aggregated, prune `readings` to 30–90 rolling days to keep hot queries lightweight while preserving permanent history in `history_5min`.
- [x] **Ops5** — Scheduled plain zip of `meter_history.db` into `/volume1/data/wlw_db_backup` (`power-db-D|W|M-…`; prune in-script; 04:00 DSM task) — **Done 2026-11-12** (DS723 smoked; console logs human size + zip % reduction). Script: [`backup/power_db_backup.sh`](../backup/power_db_backup.sh). Deploy: [`deploy.md`](deploy.md). Sofie unit → **Ops9**.
- [ ] **Ops9** — Setup the same Ops5 backup on Synology **Sofie** (MIR script, `chmod +x`, DSM 04:00 task, drop dir + Hyper Backup/C2). Detail below.

### Ops5 — kickoff locks (2026-11-12)

| Topic | Lock |
|---|---|
| Drop path | Fixed `/volume1/data/wlw_db_backup` (UNC `\\…\data\wlw_db_backup`) |
| Offsite | Existing Hyper Backup → C2 of that folder — **already runs**; no new Hyper Backup task |
| Schedule | Same as WLW Ops8: **04:00** Europe/Brussels daily; one job fire writes **D**, also **W** on Sunday, also **M** on day-of-month **1** |
| Filenames | `power-db-D-YYYYMMDD-HHMMSS.zip` · `power-db-W-…` · `power-db-M-…` (Brussels stamp) |
| Retention / prune | **Inside the same shell script** after writing new zips (match WLW Ops8): `find` drop dir `-maxdepth 1`, delete older **`power-db-D-*.zip`** (`-mtime +14`), **`power-db-W-*.zip`** (`+90`), **`power-db-M-*.zip`** (`+365`). Do **not** touch `wlw-db-*` or other files |
| Format | **Plain zip** (no encrypt). Snapshot via SQLite online `.backup` (be90 shape), not SQL dump, not A9 `.backup` encrypt |
| Zip contents | **`meter_history.db` only** (see config inventory below). **No** `.env` |
| Transport | Local write on the NAS into the drop dir (no scp) |
| Restore | **Out of scope** for product UI — manual unzip / replace only; document briefly in deploy |
| A9 | Settings encrypted download/restore stays separate (on-demand) |
| Sequence | **Ops5 first; W1 after** |
| Vehicle | Shell [`backup/power_db_backup.sh`](../backup/power_db_backup.sh) + **separate** DSM Task Scheduler job at **04:00** (leave `rollup.py -daily` at 00:05). Not folded into rollup. Stage under drop dir on `/volume1` (not `/tmp`) |
| Pattern ref | be90 `helpers/backup/wlw_db_backup.sh` / `docs/ops-checklist.md` § Ops8 |
| Shipped | **2026-11-12** — script + docs; DS723 DSM smoke + human-readable size / zip reduction logs |

**User-side config inventory (kickoff Q3):** almost all site config lives **inside** `meter_history.db` (`app_settings`, `app_admin`, `expected_monthly`, virtual meters, `wanos_sensor_mappings`, timeseries). Outside the DB on the host: **`.env`** = `DB_BACKUP_ENCRYPTION_KEY` only (excluded by lock). PC-only: `helpers/p1-sync.config.json` (sync targets; not on NAS product runtime; not in this zip).

### Ops5 — triage 2026-11-10 (verbatim)

> backup: is there anything in piple for regular backup of the DB?
> path \volume1\data\wlw_db_backup is becked up by C2 synology
> check be90webserver repo for how backup is arranged there - triage this

Operator locks (same turn): (1) fixed drop path; (2) same be90 D/W/M retention in that path; (3) filename prefix `power-db-D-…`.

### Ops5 — kickoff answers 2026-11-12 (verbatim)

> 1: schedules the same as wlw
> 2: no encrypt: just zip
> 3: no .env - which other user-side config/info  is there?
> 4: DSM backup already runs - path = /volume1/data/wlw_db_backup
> 5: no restore: manual
> 6: first, W1 after

### Ops5 — kickoff follow-up 2026-11-12 (verbatim)

> yes, DSM task scheduler
> for the daily task: can we re-use the rollup (so time will change) or is it best to add another task ?

> inside the shell script: check for older backups, then purge: match wlw setup

Operator: separate 04:00 DSM task (not reuse rollup); prune older `power-db-*` inside the backup shell script (WLW mtime find/delete shape).

- [x] Configure Synology DSM Task Scheduler (**DS723** / Ops5 host):
      - [x] Every 1 minute: `run_python.sh logger.py -poll` (Live P1 + Inverter ingestion).
      - [x] Daily at 00:05: `run_python.sh rollup.py -daily` (Locks in yesterday's daily aggregates).
      - [x] Daily at 04:00: `backup/power_db_backup.sh` (Ops5 — DS723 smoked 2026-11-12).
      - [x] Weekly on Sunday: `sqlite3 meter_history.db "PRAGMA optimize;"`.
      - Use absolute paths under `/volume1/web/`; `run_python.sh` picks newest Python (same order as `hp_python_bin()`). Sofie mirror of this backup job → **Ops9**.

### Ops9 — Sofie: Ops5 power DB backup setup (triage 2026-11-12)

**Status:** open · Sequence #2 · **Size:** M  
**Depends on:** **Ops5** Done (script + locks)  
**Host:** Synology unit **Sofie** (not DS723)

#### Scope (placement)

- Deploy / MIR [`backup/power_db_backup.sh`](../backup/power_db_backup.sh) onto Sofie web tree.
- `chmod +x`; DSM Task Scheduler daily **04:00** Europe/Brussels (same as Ops5).
- Confirm drop dir writable; prune stays in-script (`power-db-*` only).
- Confirm offsite (Hyper Backup → C2 or Sofie equivalent) covers the drop folder.

#### Open (placement — confirm before kickoff)

1. Sofie drop path — same `/volume1/data/wlw_db_backup`, or different?
2. Sofie web root — still `/volume1/web/` with `meter_history.db` beside the app?

#### Verbatim (triage 2026-11-12)

> close phase, add to triage: setup this backup for Synology unit "Sofie"