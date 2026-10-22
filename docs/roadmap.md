# ENERGY DASHBOARD & ARCHIVE ROADMAP

## PHASE 0: RAW INGESTION & DATA SANITIZATION (CURRENT)
- [x] Schema parity verified between Synology nodes (1-minute `readings` table structured identically).
- [x] Standardize 5-minute schema (`sensorbox_history_5min`, `inverter_history_5min`, `expected_monthly`).
- [x] Configure CLI-only master archive mode (`importer.py -outerzip`) with live extraction progress.
- [x] Implement strict regex isolation (`wb<serial>.<timestamp>.(zip|7z)`) so web importer never touches master archives.
- [x] Develop Direct Consolidation Mode (`importer.py -aggregate5min`) for 1-step ingestion into `history_5min`.
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

**Step 2: Consolidate ZIP Data into `history_5min` (If Needed)**
- *If imported with `-aggregate5min`:* Skip this step (already in `history_5min`).
- *If imported in Staging mode:* Run consolidation to merge staging tables into `history_5min`:
```bash
python3 rollup.py -consolidate
```

**Step 3: Run the SQL Gap-Filler**
Drop the legacy MariaDB dump (`.sql`) into `import_queue/` and run the SQL importer. This safely fills any missing historical intervals by pulling from `wpv_raw` and snapping to the same 5-minute grid:
```bash
python3 sql_importer.py
```

**Step 4: Defragment and Optimize the Database**
Decompressing and bulk-inserting millions of rows creates B-tree page fragmentation. Reclaim unallocated disk space and update SQLite's internal query planner statistics:
```bash
sqlite3 meter_history.db "VACUUM; PRAGMA optimize;"
```

**Step 5: Run the Rollup Engine (`rollup.py`)**
Now that `history_5min` holds the complete gap-filled timeline, populate the analytical tables (`daily_history`, `monthly_history`, `clear_sky_envelopes`, `top_records`):
```bash
python3 rollup.py -buildall
```

**Step 6: Audit Using `wpv_periodicvalues`**
Compare new `daily_history` yields against the old MariaDB `wpv_periodicvalues` table to prove mathematical consistency across the 17-year history.

---

## PHASE 1: AGGREGATION ENGINE (DATA FOUNDATION)
- [x] Design and create the 3-Tier Data Architecture schemas:
      - `history_5min` (Consolidated 17-year master table).
      - `daily_history` (date, solar_yield_kwh, peak_w, sun_hours, import_kwh, export_kwh, self_consumption_kwh, temps, wind, radiation).
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
- [x] Multi-Tab Dashboard Architecture:
      - Separated live operations (`index.php`) from historical trends (`analytics.php`).
      - Added cross-navigation links between the two hubs.
- [x] Implement Day Explorer (Intraday):
      - Built SPA AJAX fetcher for 24-hour 5-minute generation curve.
      - *(Pending: Overlay faint clear-sky envelope behind today's curve once `rollup.py` Mode C is finished).*
- [x] Implement Two-Day Overlay:
      - Built dual-date picker and synchronized ApexCharts comparison engine.
- [x] Implement Month / Year Trend Views:
      - Built yearly yield bars plotted directly against the PVGIS expected step-line (`expected_monthly`).
- [x] Implement Hall of Fame Card:
      - Built Top 10 Best Generation Days ranking table.
- [ ] Verify the Analytics Dashboard:
      - Open the host `analytics.php` URL and test rendering using the populated historical database.

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
- [x] Implement live hardware diagnostic utility (`powertests.py` & `powertests.php`) to test P1, SMA, and WebBox connectivity via HTTP/JSON-RPC without touching the database.
- [ ] Implement `readings` hot-buffer retention strategy:
      - Once 1-minute data is downsampled and aggregated, prune `readings` to 30–90 rolling days to keep hot queries lightweight while preserving permanent history in `history_5min`.
- [ ] Integrate `meter_history.db` into Synology Hyper Backup for offsite snapshot protection.
- [ ] Configure Synology DSM Task Scheduler:
      - [x] Every 1 minute: `logger.py -poll` (Live P1 + Inverter ingestion).
      - [ ] Daily at 00:05: `rollup.py -daily` (Locks in yesterday's daily aggregates).
      - [x] Weekly on Sunday: `sqlite3 meter_history.db "PRAGMA optimize;"`.