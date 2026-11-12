# --- file: docs/architecture.md ---
# Architecture

**Status:** Migrated into this repo (2026-10-22). Phase A warehouse + UI Done. Phase P portals Done. **W2** WanOS→Power display bridge Done.  
**Last updated:** 2026-11-12 (Ops5 closed; Ops9 Sofie backup setup)  
**Operator cheat sheet:** [`power-notes.md`](power-notes.md) (commands / tables / rebuild).  
**Delivery archives:** [`docs/todo/_archive/phaseM-migration.md`](todo/_archive/phaseM-migration.md) · [`docs/todo/_archive/phaseA-aggregation.md`](todo/_archive/phaseA-aggregation.md) (A0–A3) · [`docs/todo/phaseA-analytics.md`](todo/phaseA-analytics.md) (A4–A27 Done — move offline when convenient) · [`docs/todo/phaseP-portals.md`](todo/phaseP-portals.md) (P1–P3 Done — move offline when convenient) · [`docs/todo/phaseW-wanos.md`](todo/phaseW-wanos.md) (**W2** Done; keep until **W1** closes)

## What this is

A **standalone** home energy hub: poll P1 + optional SMA / Sunny WebBox, store timeseries in SQLite, present a PHP dashboard. Designed to run on **Synology** (Web Station + scheduled Python), not as part of WanOS or WLW.

## What this is not

| System | Relation |
|---|---|
| **WanOS** | Separate smart-home OS. Its HomeWizard energy ingest stays there — do not move that bridge into this repo. **W2 (Done):** display-only bridge (machine API key; WanOS `docs/integration_power_bridge.md`) pulls allowlisted sensors into Raw Meter Registers / Utility Meters / Sensor readings; timestamps via bridge `updated_unix`. Water-Link SoT remains WanOS G24. **W1** (Power → WanOS) still open. **Host DNS (Ops8):** Synology must resolve the WanOS hostname (e.g. `app.wanos.be`) from PHP/`curl`; Settings **ACTIVE** only reflects the last probe — a DNS timeout drops all mapped rows and can stall index ~5s. Diagnose: `index.php?action=wanos_live`. See [`deploy.md`](deploy.md). |
| **WLW** | Former git home of `public/p1`. After M2: stub/pointer only. |
| **Unrelated "P1" phase ids** | Other repos may use "P1" for unrelated work. This product is the meter/dashboard stack. |

## Data flow

```
.env  (DB_BACKUP_ENCRYPTION_KEY only; encrypted storage -> host by hand)
    -> settings page writes meter_history.db app_settings (devices, titles, plant, Fluvius EANs)
    -> logger.py (poll P1 / SMA / WebBox)  -> readings (1 min)
    -> importer.py (+ -outerzip for a master container) -> staging (5 min)
    -> fluvius_import.py -> fluvius_interval (CSV in import_queue/fluvius/)
    -> sunnyportal_pull.py -> sunnyportal_pv_5min (ennexOS PV; on-demand)
    -> rollup.py -consolidate / -daily / -buildall
    -> history_5min + daily/monthly/envelopes/top_records/top_periods
    -> index.php / analytics.php / summary.php / topn.php / settings.php

backup/power_db_backup.sh  (Ops5; MIR to NAS)
    -> 04:00 Task Scheduler -> plain power-db-D|W|M-*.zip in /volume1/data/wlw_db_backup
    -> Hyper Backup/C2 already mirrors that folder (shared with WLW)

helpers/  (scripts may be in git; p1-sync.config.json gitignored)
    p1-sync.config.json  -> per-target path / excludes / optional source_subdir
    p1-sync.ps1|.bat     -> Synology: app only (no helpers/); OneDrive: helpers/ only
                         -> never copies .env*
```

## Warehouse (SoT vs derived)

```
SoT (keep forever)                    Derived (purge / -buildall OK)
─────────────────                     ─────────────────────────────
readings          1 min live    ──┐
sensorbox_history_5min  archive ──┼──► history_5min     5 min master
inverter_history_5min   archive ──┤         │
sunnyportal_pv_5min     PV fill ──┤         ├──► daily_history
fluvius_interval        util CSV ─┘         ├──► monthly_history
app_settings / expected_monthly             ├──► clear_sky_envelopes
virtual_kwh_meters (config)                 └──► top_records / top_periods
```

One 5-min spine and one day/month pair for **all** domains (grid/solar, gas/water, weather extremes + averages). No separate `weather_*` rollup tables. Fluvius gas stays in `fluvius_interval` and is never copied onto `history_5min`.

| Store | Role |
|---|---|
| `readings` | Live 1-min SoT (logger). Not purged by `-purge_derived`. On 3-phase P1 payloads, `power_l1_w` / `power_l2_w` / `power_l3_w` are filled each poll (largest-remainder scale so they sum to `active_power`). Single-phase or incomplete phase keys leave those columns NULL. Live SensorBox: `wind_speed`, `temp_module`, `solar_radiation` (no live gust/ambient). |
| `sensorbox_history_5min` / `inverter_history_5min` | Staging SoT (WebBox import). Never purged. Holds every WebBox year. SensorBox archive may include gust/ambient. |
| `fluvius_interval` | Fluvius CSV SoT. Never purged. Electricity quarters, gas hours and days, monthly peak. |
| `sunnyportal_pv_5min` | Sunny Portal / ennexOS PV staging. Never purged. Filled only by `sunnyportal_pull.py`. `-buildall` does not write this table. |
| `virtual_kwh_meters` | Config SoT for virtual lifetime meters (A14). Never purged by `-purge_derived`. Not written by rollup. Live `V` is computed in PHP from source counters + stored bias / factor / `S0`. |
| `history_5min` | Derived master (5-min). Rebuilt by `-buildall` from all `readings`, then WebBox staging, then empty-fill `solar_power_w` from `sunnyportal_pv_5min` where still null, then Fluvius electricity quarters. `-daily` refreshes the last 2 local days of `readings`, empty-fills Sunny Portal solar for that span, then stitches Fluvius quarters. `grid_import_kwh` / `grid_export_kwh` are the published quarter kWh on the quarter-start row. From P1 downsample: `power_l1_w` / `power_l2_w` / `power_l3_w` are AVG+ROUND of the 1-min phases when every sample in the bucket has them, then largest-remainder re-scaled to `grid_power_w`. Incomplete buckets, WebBox-only, and Fluvius-only rows leave phase columns NULL. Weather columns: `wind_speed`, `wind_gust`, `temp_module`, `temp_ambient`, `solar_radiation` (+ max where used). |
| `daily_history` / `monthly_history` / `clear_sky_envelopes` / `top_records` / `top_periods` | Derived analytics. `-buildall` rebuilds them after `history_5min`. `-daily` refreshes recent `daily_history`, then rewrites `monthly_history` and `top_periods`, and beat-updates `top_records` when a recent day is a stricter extreme. `-buildalltoprecords` rewrites `top_records` only. **`clear_sky_envelopes` (A24):** same PAC night/shoulder gates as elsewhere, **plus** envelope-only require `COALESCE(solar_radiation_max, solar_radiation, 0) >` rad floor (keeps WebBox history; drops PAC-with-no-sun junk). Refresh via `-buildall`. |

### Day / month domain columns

| Domain | `daily_history` | `monthly_history` |
|---|---|---|
| Solar / grid | `solar_yield_kwh`, `solar_peak_w`, `sun_hours`, import/export/self-consumption | `total_solar_kwh`, expected/target, import/export, `max_peak_w` |
| Gas / water | `gas_m3`, `water_m3` | `gas_m3`, `water_m3` (sums) |
| Weather extremes + averages | Extremes: ambient min/max, `temp_module_max` / `temp_module_min`, `solar_radiation_max_wm2`, `wind_speed_max_ms`, `wind_gust_max_ms` (gust never coalesced with speed). **A27 averages:** `temp_ambient_avg`, `temp_module_avg`, `wind_speed_avg_ms`, `wind_gust_avg_ms`, `solar_radiation_avg_wm2`. | Monthly: extremes via `MIN`/`MAX` of daily; averages via `AVG` of daily avgs. Legacy `max_temp_module` / `max_wind_gust` unused on older DBs. |

`top_records` / `top_periods` stay ranking views over those day/month facts, not a second warehouse.

**PAC quality gates** (rollup + analytics / Top-N Virtual 'best-day' charts via `pac_quality.php`): spike cap is max inverter AC × 1.05 (default nameplate 12 kW, cap 12.6 kW) from Settings. Deep night and shoulder hours are editable; defaults drop PAC from 22:00 through 05:00, and on the shoulder (from 20:00 and before 06:00) when solar radiation is at or under 5 W/m². Night WebBox junk with rad≈0 must not define envelopes or inflate integrals. **Envelope-only (A24):** also require radiation above that floor at the sample (WebBox kept; PAC with rad≈0 before 20:00 cannot win a Virtual 'best-day' slot).

**Timestamps:** logger uses host local time (CET). Downsample cutoffs use SQLite `datetime('now', 'localtime')` (UTC `now` drops afternoon slots).

## Settings

`settings.php` is the configuration surface. `logger.py`, `rollup.py`, `powertests.py`, and the PHP pages read `app_settings` in `meter_history.db`. PHP does not rewrite `.env`.

| Behavior | Rule |
|---|---|
| First open, no admin row | Create the admin password (enter it twice). The app does not continue until it is set. |
| This host, after that password | Dashboard. Settings is linked, not forced. Device and portal settings live in `app_settings` only. |
| New install, no P1 address | Admin password, then Settings. The live dashboard waits until P1 is saved. |
| Locked by default (A20) | Config fields, backup download/restore, and Change admin password start disabled. Primary control is **Unlock settings** (admin password modal). Wrong password stays locked. |
| Unlock window | Timed from unlock (`HP_SETTINGS_UNLOCK_SECONDS` in `settings.php`, default 300). A visible countdown shows remaining time. Reload or leave Settings ends unlock immediately. On expiry: clean form re-locks; dirty form opens Save / Cancel (Save asks admin password; Cancel discards and re-locks). |
| Save | **Save settings** appears only when unlocked and the form is dirty. Within the unlock window, Save does not ask again (page JS still posts the unlock password so the server can reject crafted POSTs). Save uses AJAX: validation/probe failures write **nothing** and keep the unlock window open so the operator can fix and retry. After a **successful** save the page reloads locked. |
| Save probes (A15) | Every Save re-probes filled live integrations (**P1**, **SMA**, **WebBox**, **Sunny Portal**) via `integration_probes.py`. Partial fill (e.g. portal user without password) blocks the save (atomic: no settings written). Probe **ok** sets `p1_active` / `sma_active` / `sunnywebbox_active` / `sunnyportal_active` to `1`. Fail or offline still saves credentials but sets active to `0` and shows a dismissible modal. Cleared integrations are forced inactive (no probe). Missing active keys on older DBs count as active until the next Save. Fluvius EANs are CSV-only (no probe, no active flag). Status chips: green Active / red Inactive / Not configured. **WanOS (W2)** is separate: **Save & check connection** in the WanOS section (stays unlocked) probes via the same probe helper and sets `wanos_active`; do not rely on page-bottom Save for the bridge key. |
| Device passwords | Stars when set. **Edit** (only while unlocked) clears the field for a new value with no extra admin ask inside the unlock window. **Clear** marks the stored secret for removal on the next successful Save (empty Edit alone does not wipe a stored password). The stored secret is never in the page. An unset field is editable once unlocked. |
| Change admin password | Disabled until unlock. Separate modal: current password, new password, new password again, Enter. The two new values must match. No extra unlock gate inside the window. |
| 1-min CSV | Own **Readings export** pane (not in the admin/config lock). No admin password. |
| Admin hash | One-way (`password_hash`). Device passwords are stored so the poller can log in. |
| Spike cap | Max inverter AC × 1.05. Default nameplate 12000 W, cap 12600 W. |
| Night / shoulder | Defaults: drop PAC when the hour is `>= 22` or `< 5`; shoulder `>= 20` or `< 6` when radiation is `<= 5` W/m². |
| Apply | Titles and Summary PVGIS curve on the next page load (all-zero months = no PVGIS series). Plant limits and monthly `target_pct` when `rollup.py -buildall` / `-daily` runs. No rebuild button. `importer.py` inserts an `expected_monthly` row only when that month and inclination are missing. |
| Virtual kWh meters (A14/A21) | Settings section (Unlock): add / edit / delete meters; live source + virtual check; **Re-bias on current reading** (confirm modal) freezes a new `S0`. Checkbox **Show in Raw Meter Registers** shows that meter on the index Raw Meter Registers modal. Mutations use admin password AJAX and do not run integration probes. Shared calc in `virtual_meters.php`. Read-only debug: `virtual_meters_debug.php` (admin unlock, timed). |
| Optional raw registers | Settings **Show optional meters in raw readings**: Peak/Off-peak Import T1/T2, Peak/Off-peak Export T1/T2, Solar Generated Today, Solar Lifetime Yield. Default on; unchecked hides the row. Total Import/Export, Capacity Peak, gas, water always shown. |

### Virtual kWh meters

Formula: `V = (S0 + C) + p * (S - S0)` with `p = factor_percent / 100` (factor entered as percent, max 4 decimals, e.g. `99.9996`).

- `S0` freezes on **first save** of that meter. Changing name / source / bias / factor does **not** change `S0` (use Re-bias).
- **P1 net** = import − export.
- Sources when available: P1 import / export / net from the **last `readings` row**; SMA total and WebBox total from **`latest_state`** (`sma_data.total_kwh` / `webbox_data.total_kwh`) because `readings.solar_total_kwh` is the combined solar lifetime only.
- No writes into `history_5min` / daily / monthly / rollup.

`DB_BACKUP_ENCRYPTION_KEY` is not a setting. It stays in `.env`. An empty key refuses both download and restore. Backup download is disabled until unlock and does not ask for the admin password again. Restore always asks for the admin password and an explicit confirm that everything currently in the database will be destroyed. There is no stay-logged-in server session.

`db_backup.py` makes a SQLite online backup, zips that file, and encrypts the zip (PBKDF2-SHA256, 100000 iterations, AES-256-GCM, salt + IV + ciphertext + tag). PHP checks the restore password and the confirm, starts Python, sends the file or swaps the database, and deletes the temp files. The key is not a command argument. The browser file is `meter_history.db.<yymmdd-HHMMSS>.backup` (Europe/Brussels). The zip member is `meter_history.db.<stamp>.bak`. After the download is sent, the server deletes the plain backup and the encrypted file. `p1-sync` does not pull those files.

Restore accepts only that encrypted file. `PRAGMA integrity_check` must be `ok`, then the live database is replaced and `-wal` / `-shm` are deleted. A failed check keeps the live database. One in-flight poll may fail. Runtime requirements are in [`deploy.md`](deploy.md).

**Ops5 (scheduled, separate from A9; DS723 Done 2026-11-12):** DSM Task Scheduler daily **04:00** Europe/Brussels runs [`backup/power_db_backup.sh`](../backup/power_db_backup.sh). It SQLite-`.backup`s `meter_history.db` into a **plain** zip at `/volume1/data/wlw_db_backup` as `power-db-D|W|M-YYYYMMDD-HHMMSS.zip` (always D; W on Sunday; M on day 1), then prunes older `power-db-*` (D14 / W90 / M365). Staging is under the drop dir on `/volume1` (never Synology `/tmp` tmpfs). Logs use human-readable sizes and zip **% reduction vs snapshot**. Same drop folder as WLW Ops8 (`wlw-db-*`); Hyper Backup → C2 already mirrors it on DS723. No encrypt, no `.env` in the zip, no product restore UI — manual unzip/replace only. Leave `rollup.py -daily` at 00:05. **Sofie** unit setup → **Ops9**. Details: [`deploy.md`](deploy.md).

The live chart on `index.php` is a **2-hour pulse** of 1-minute `readings` (auto-refresh). Segmented switch: **Exchange** (default), **Balance** (`?view=balance`), **Classic** (`?view=classic`), or **Phases** (`?view=phases`) when the pulse has `power_l*_w` samples. Exchange is bipolar signed net (Grid above 0 / Surplus below 0). Balance paints Self-sufficient, Grid, and Surplus; Production and Consumption are tooltip/legend composites. Phases draws three signed L1/L2/L3 lines plus a light filled **Sum** mountain (L1+L2+L3); null phase samples are omitted (gaps, no zero-fill). Longer windows, pan, and mixed history live on `analytics.php`. A step longer than 3 minutes is a gap on the live pulse.

## UI map

| Page | Role |
|---|---|
| `index.php` | Live dashboard. Power chart: last **2 hours** of 1-minute `readings`. Switch: **Exchange** (default, bipolar signed net) / **Balance** (`?view=balance`, smooth stacked bands) / **Classic** (`?view=classic`, straight area) / **Phases** (`?view=phases`, L1/L2/L3 lines + light Sum mountain from `power_l*_w`; control hidden when the pulse has no phase samples). Filled-but-inactive integrations (A15) show a clear error banner. P1 inactive: empty pulse chart (page still `ready`). Live solar/yield pillars only when an active SMA or WebBox is contributing (no fake zeros). **Sensor readings** card: Radiation / Module / Wind from live SensorBox when present; **Ambient** from WanOS when mapped (missing tiles hidden). Sunny WebBox **devices table is not on the homepage** (Settings only). **Utility Meters**: P1 gas/water plus mapped WanOS water rows below gas (WanOS subtext = last-updated time from bridge `updated_unix`, same shape as Gas). Ambient tile shows the same timestamp. Optional **Gas rate** / **Water rate** 2 h charts. **Raw Meter Registers**: Total Import/Export, Capacity Peak, gas/water; WanOS water mappings below gas; optional T1/T2/solar; virtual meters. WanOS values fetched on page load and again when the Raw modal opens. Nav: Analytics, Summary, Top-N, Weather. |
| `analytics.php` | Explorer: rolling presets 15 m–7 d plus calendar-day **Today** (chip after **24 h**, before **7 d**; **default**). **Today** / `?date=` use local midnight to next midnight (DST length as-is; incomplete today keeps the full axis). **Reset view** returns to Today. In day mode, pan ←/→ moves one calendar day (→ disabled on today; ← disabled on the earliest day in `history_5min` or `readings`). Rolling presets still pan by one window step ending at now. Switch: **Exchange** (default) / **Balance** / **Classic** / **Phases** — same modes as index; Phases hidden when the loaded window has no `power_l*_w`. Balance legend includes split dots for Consumption / Production. Mixed resolution per 5-minute bucket (rule V): if at least two 1-min `readings` fall in **bucket ∩ window**, use those 1-min points; else use `history_5min` (Fluvius W derive as before). Within a 5-min run, grid nulls of length 1..2 are linear-bridged for Classic/Balance/Exchange (Fluvius 1-in-3); phase series are not bridged. Tooltip shows source (`1 min` / `5 min`); Phases tooltip lists Sum + L1/L2/L3. Below the power explorer: **Gas / Water rate** card (m³/h) with a Gas|Water switch; **same `from`/`to` window** (no own presets); Water hidden unless live P1 lists `water_meter`; gas uses P1 1-min → 5-min → Fluvius hour. **Two-Day Overlay** is solar-only. Charts mount **serially** (explorer, then compare) to avoid Apex blank first paint. Virtual 'best-day' / clear-sky envelope lives on **`topn.php`**, not here. Deep link: `range`, `date`, `from`/`to`, `view` (omit `range` when default Today). Weather series live on `weather.php`, not here. |
| `summary.php` | Period charts in order: week, month, year, then **All years** last (Gas also has a **day** card first). Segmented switch: **Solar yield** (default), **Grid import / export** (`?metric=grid`), **Capacity peak** (`?metric=peak`), **Gas** (`?metric=gas`, m³; chip when gas history/P1/Fluvius exists), **Water** (`?metric=water`, m³; chip when P1 water exists — water-link is future). Solar uses `solar_yield_kwh` / `total_solar_kwh` plus live `expected_monthly` PVGIS (omit the curve and PVGIS stats when every month is 0). **Week and month** draw a PVGIS **daily-share** line (month expected ÷ days-in-that-month; a week may span two months) beside yield bars, with week/month vs-PVGIS stats. **Year / all-years** keep per-period expected lines. Grid uses grouped bars from `grid_import_kwh` / `grid_export_kwh` and the monthly `total_import_kwh` / `total_export_kwh`: import, export, and net (import minus export). A missing register is a gap. A second switch, only in grid mode, is **All** (default), **Import / export** (`bars=registers`), or **Net** (`bars=net`). Capacity peak reads Fluvius `piekvermogen` months from `fluvius_interval` (kW) for the Settings electricity EAN; shows **year** and **All years** only (week/month cards hidden). Gas day curve: P1 **1-min** interval m³ when available, else **5-min**, else Fluvius **hour** bars; week+ use `daily_history.gas_m3`. Water has no day card; week+ use `daily_history.water_m3`. All-years peak bars are one per calendar year; **Max** / **Average** switch sits on the All years card (`peak_agg=avg`). Max and Average share the same Y-axis ceiling (Max-mode highs). The open local month bar is replaced by live P1 `montly_power_peak_w` converted to kW. Deep link: `?month=YYYY-MM` sets week (ISO week of day 1 of that month, or earliest overlapping available week), month, and year; `?year=YYYY` sets year; also `metric`, `bars`, `peak_agg`. |
| `topn.php` | Metric switches match summary (Solar / Grid / Capacity peak / Gas / Water). **All-time records** (above the charts) from `top_records`, filtered by metric: **Solar (yield)** — best day kWh, best year kWh, peak surge W, sun-hours, self-consumption; **Capacity peak** — highest Capacity peak month (kW), year with highest average Capacity peak (kW); **Grid / Gas / Water** hide the records card; weather Records (wind speed, module hi/lo, radiation) live on **`weather.php`**. Yield day rows → analytics; year generation / peak rows → summary. On **Solar** only, below records: **Virtual 'best-day'** — composite max PAC curve from `clear_sky_envelopes` (month picker / whole year); AJAX `topn.php?ajax=bestday` returns `total_kwh` (sum of slot mean W × 5/60 / 1000); UI shows **Virtual day yield** and integer PAC Y-axis ticks. Solar rankings: best/worst yield (20 days, 10 months, 10 years) from `top_periods`. Grid: highest net import / export from `daily_history` / `monthly_history` (today excluded on days). Capacity peak rankings: highest **10** months; open month may use live P1 kW. Gas: highest only (days/months/years). Water: highest + lowest; lowest excludes **0-days** and **today**; day ranks exclude today. Links keep `metric` and `bars`. Rebuild singles with `-buildalltoprecords` / `-buildall` / `-daily` beat. |
| `weather.php` | Standalone weather page (A11/A23/A25/A27). **Records:** wind speed max, module hi/lo, solar radiation max from `top_records` (channel-gated; no gust/ambient on this card). Day explorer from `history_5min` + live `readings` (unchanged). Stacked **Week** / **Month** / **Year** / **All years** plot **averages** (`*_avg*` on daily/monthly); open day/month bar = live `AVG` of samples so far; All years = `AVG` of monthly avgs; labels "… avg"; single Ambient avg series. AJAX `ajax=period&mode=` week|month|year|allyears. |
| `settings.php` | First open creates the admin password (entered twice) when none is stored. After that, graph pages open with no login session. Config starts locked: **Unlock settings** (timed window, see Settings table). Edits devices, titles, 12 monthly expected kWh (inclination 30 only; irradiance stays the seed), max inverter AC, the night/shoulder gates, and the optional Fluvius electricity and gas EANs. Save probes live integrations and writes `*_active` (see Save probes). **WanOS bridge** (W2 Done): address + API key; **Save & check connection** → `wanos_active`; when LIVE, **Add sensor from WanOS** maps allowlisted `entity_id`s (placement raw/main/both); edit name/placement on existing rows — one **Save sensor changes** appears only when dirty. Prefer TLS hostname that matches the cert (e.g. `app.wanos.be`). **Sunny WebBox Devices** table (when WebBox active) sits under WebBox, including SensorBox id. Virtual kWh meters CRUD + Re-bias. Backup download needs unlock; restore asks admin password + destroy confirm. **Download 1-min CSV** in Readings export pane with no password. |
| `wanos_bridge.php` | W2 helpers: schema `wanos_sensor_mappings`, probe/fetch, resolve live rows for index / Raw refresh. Live fetch failures return empty rows (no homepage banner); check `?action=wanos_live`. |
| `virtual_meters_debug.php` | Unlock-gated read-only dump of virtual meters (`S`, `S0`, `C`, percent, `p`, `V`, source detail). No config. |

## Rollup CLI (operator)

| Flag | Effect |
|---|---|
| `-consolidate` | Staging → solar and weather on `history_5min` (`data_source` `webbox_archive`) |
| `-daily` | Six status steps: (1) last 2 local days of `readings` → `history_5min`; (2) Sunny Portal empty-fill `solar_power_w` for that span (no console block when staging is empty); Fluvius quarter stitch (no status line); (3) last 3 local days → `daily_history` (includes A11 `gas_m3` / `water_m3` from P1 deltas; Fluvius gas day fallback when P1 gas falls out); (4) full rewrite `monthly_history` (sums those m³ columns); (5) `top_periods`; (6) beat-update `top_records` for that stitch calendar span (stricter extreme only) |
| `-buildall` | Delete `history_5min`, `daily_history`, `monthly_history` (no `VACUUM`). Refill `history_5min` from every `readings` row except the open 5-minute slice, then WebBox staging, then Sunny Portal empty-fill `solar_power_w`, then Fluvius electricity quarters. Then daily (with gas/water m³), monthly, envelopes, `top_records`, `top_periods`. Rare. The day chart is empty until the command finishes. Fluvius gas is never copied onto `history_5min`. |
| `-buildallsunnyportal` | Empty-fill `history_5min.solar_power_w` from `sunnyportal_pv_5min` only (no wipe, no daily/monthly rebuild). Prints how many 5-min slots were written, first/last stamp (silent when staging is empty). |
| `-buildalltoprecords` | Full rewrite of `top_records` only (no wipe of `history_5min` / daily / monthly) |
| `-purge_derived` | Wipes the six derived tables and `VACUUM`. `readings`, WebBox staging, `fluvius_interval`, and `sunnyportal_pv_5min` stay. Separate from `-buildall`. |
| `-purge_webbox_plus4h` | **A26.** Surgical delete of corrupt WebBox staging clones: timestamp ends in `:00`, Pac ≥ 100 W (or radiation ≥ 5) matches a sample at the same clock minute **four hours earlier** (Mean XML audit: clones absent from archives; cause evening Virtual 'best-day' bumps). Touches `inverter_history_5min` / `sensorbox_history_5min` only. Does **not** rewrite `history_5min` — run `-buildall` after. |

`importer.py -aggregate5min`, `rollup.py -today`, and the analytics **Generate today table** UI are removed. Today’s closed 5-minute buckets land in `history_5min` via nightly `-daily` (or a rare `-buildall`). WebBox import writes staging only.

`logger.py` polls P1 only when `p1_active` (grandfather: missing flag + filled address counts as active). Inactive SMA / WebBox are not contacted; P1 alone still writes grid energy readings. `powertests.py` ignores active flags and uses `integration_probes.py`.

`sunnyportal_pull.py` logs in with Sunny Portal credentials from `app_settings` in `meter_history.db` (Settings page) only when Sunny Portal is active. It POSTs the unpaid ennexOS `measurements/search` API for PV power into `sunnyportal_pv_5min` on that same database. No switches prints usage. `-pullall` pulls the full portal span through today. `-lastdays -n N` pulls the last N days including today (N 1..366). `-probe` prints candidate channels that return samples (console only). It does not write `history_5min`; rollup empty-fills `solar_power_w` where null (`data_source` `sunnyportal` only on new rows). There is no `sunnyportal_csv_test.py`.

`fluvius_import.py` reads `import_queue/fluvius/*.csv` and writes `fluvius_interval`. A row is kept when its EAN equals Electricity EAN or Gas EAN from Settings. Both empty: the CLI stops and reads nothing. A file that parses is moved to `import_queue/fluvius/processed/` even when every row was skipped. A file that does not parse stays in the queue and the CLI names the row. The process exits 1 if any file failed. There is no import button. `importer.py` does not read these CSVs.

Resolution comes from exactly one filename token: `kwartiertotalen` → `15min`, `uurtotalen` → `hour`, `dagtotalen` → `day`, `piekvermogen` → `month`. A CSV without exactly one of those stays in the queue. UTF-8 with or without BOM, separator `;`, dates `DD-MM-YYYY`, decimal comma. EAN digits after stripping `="…"`. Required header: Van (datum), Van (tijdstip), Tot (datum), Tot (tijdstip), EAN-code, Meter, Metertype, Register, Volume, Eenheid, Validatiestatus. Registers: `afname_dag`, `afname_nacht`, `injectie_dag`, `injectie_nacht`, gas `afname`, `piekvermogen`. File unit `m³` is stored as `m3`. `Geen verbruik` with a blank volume is 0. Primary key `(ean, register, unit, resolution, interval_start, occurrence)`. A later file with the same key replaces the row. `validation` is the portal text, stored as written (`Uitgelezen`, `Geen verbruik`, `Geschat`, `Voorlopig`, and any other non-blank status). A blank validation cell rejects the whole file. Gas kWh also stores `calorific` (`Definitief`, `Voorlopig`, or empty). After a run, a log-only cross-check prints electricity quarter-sum versus day-file gaps over 0.001 kWh on a complete day, gas m³ gaps over 0.001, and gas kWh gaps over 0.002 when both sides are `Definitief`. It does not fail the import or skip a row.

On a passing electricity day the daily kWh stays the P1 counter delta. A finished day falls out when `readings` has no sample at or before 00:15, or none at or after 23:45, or `total_import` / `total_export` steps down by more than 0.01 kWh. The local calendar day still in progress is not a fall-out day. A fall-out day with a complete 15-minute set uses the sum of those quarters and writes one curve point per quarter start. Otherwise the day file is the total and no curve is written. Gas is not copied onto `history_5min`. A passing day's empty quarter (all three 5-minute slots missing from P1) gets one curve point. Autumn's repeated local start sums both occurrences into that one timestamp. The spring `01:45`–`03:00` row is stored at `01:45` only.

## Config split

| Concern | File | Public git | Sync to NAS |
|---|---|---|---|
| Backup encryption key | `.env` (`DB_BACKUP_ENCRYPTION_KEY` only) | name in `.env.example` | **never** |
| Device IPs, device passwords, titles, plant limits, Sunny Portal user / password / plant id / timezone, Fluvius electricity EAN and gas EAN, WanOS host + API key, `p1_active` / `sma_active` / `sunnywebbox_active` / `sunnyportal_active` / `wanos_active` | `meter_history.db` `app_settings` (Settings page only; not read from `.env`). WanOS sensor placements live in `wanos_sensor_mappings`. | no | DB is not in git; sync excludes `.env*` and `*.db` |
| Admin password | `meter_history.db` `app_admin` (one-way hash) | no | same as the DB |
| Sync source + SMB targets | `helpers/p1-sync.config.json` | no (gitignored) | Synology: **no** / OneDrive helpers-backup: **yes** |
| Sync scripts + helpers README | `helpers/` (except config json) | yes when committed | Synology MIR usually excludes `helpers/`; OneDrive helpers-backup: **yes** |

Committed files (code + docs): **no** real LAN IPs, **no** household/site nicknames.

## License

**MIT** — root [`LICENSE`](../LICENSE).
