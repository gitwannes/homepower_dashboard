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
`-buildall` deletes `history_5min`, `daily_history`, and `monthly_history`, refills `history_5min` from all `readings` (open 5-minute slice skipped), from WebBox staging, and from `fluvius_interval` electricity quarters, then builds `daily_history`, `monthly_history`, `clear_sky_envelopes`, `top_records`, and `top_periods`. Rare. The day chart is empty until the command finishes. Run `fluvius_import.py` first when new Fluvius CSVs are in `import_queue/fluvius/`. `-buildalltoprecords` rewrites `top_records` only. `-daily` also beat-updates `top_records` for the recent calendar span.
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
      - `clear_sky_envelopes` (month, timepoint [0..287], max_pac_w, max_pac_date, max_rad_wm2).
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
      - Cross-navigation between hubs.
- [x] Day Explorer on `analytics.php` (**A5b**):
      - Exchange / Balance / Classic explorer: clock window (default 6 h), presets, pan, mixed 1-min / 5-min (rule V). Clear-sky envelope is a separate chart.
- [x] Two-Day Overlay on `analytics.php`.
- [x] Summary period views (`summary.php`):
      - **All years** (lifetime bars vs PVGIS year baseline), week, month, year (months vs PVGIS).
      - **A13 Done:** Solar yield / Grid switch. Grid mode is grouped bars (import, export, net). A second switch shows all three, import/export only, or net only.
- [x] Hall of Fame / Top 10:
      - Old live Top 10 removed in A3. **A4 Done:** `topn.php` + `top_periods` (best/worst days 20, months/years 10); rebuilt by `-buildall`/`-daily`. Worst days skip zero yield and today.
      - **A13 Done:** the same page ranks highest net import and highest net export from `daily_history` / `monthly_history` (20 days, 10 months, 10 years). Both day lists omit today. No second ranking table.
      - **A7 Done:** all-time singles on `topn.php` (`top_records`); `-daily` beat + `-buildalltoprecords`.
- [x] Settings (`settings.php`): admin password on first open; devices, titles, monthly expected kWh, spike cap, and night gates in `meter_history.db`. Encrypted full-database download and restore (`db_backup.py`). See [`architecture.md`](architecture.md).
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
- [ ] Integrate `meter_history.db` into Synology Hyper Backup for offsite snapshot protection.
- [ ] Configure Synology DSM Task Scheduler:
      - [x] Every 1 minute: `logger.py -poll` (Live P1 + Inverter ingestion).
      - [ ] Daily at 00:05: `rollup.py -daily` (Locks in yesterday's daily aggregates).
      - [x] Weekly on Sunday: `sqlite3 meter_history.db "PRAGMA optimize;"`.