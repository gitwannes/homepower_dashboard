# --- file: docs/architecture.md ---
# Architecture

**Status:** Migrated into this repo (2026-10-22). Phase A warehouse + UI Done. Phase P portals Done.  
**Last updated:** 2026-10-29 (A18 Phases view closed)  
**Operator cheat sheet:** [`power-notes.md`](power-notes.md) (commands / tables / rebuild).  
**Delivery archives:** [`docs/todo/_archive/phaseM-migration.md`](todo/_archive/phaseM-migration.md) · [`docs/todo/_archive/phaseA-aggregation.md`](todo/_archive/phaseA-aggregation.md) (A0–A3) · [`docs/todo/phaseP-portals.md`](todo/phaseP-portals.md) (P1–P3 Done — move offline when convenient)

## What this is

A **standalone** home energy hub: poll P1 + optional SMA / Sunny WebBox, store timeseries in SQLite, present a PHP dashboard. Designed to run on **Synology** (Web Station + scheduled Python), not as part of WanOS or WLW.

## What this is not

| System | Relation |
|---|---|
| **WanOS** | Separate smart-home OS. Its HomeWizard energy ingest stays there — do not move that bridge into this repo. |
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

helpers/  (gitignored entire directory; PC-only sync tool)
    p1-sync.config.json  -> per-target path / excludes / optional source_subdir
    p1-sync.ps1|.bat     -> Synology: app only (no helpers/); OneDrive: helpers/ only
                         -> never copies .env*
```

## Warehouse (SoT vs derived)

| Store | Role |
|---|---|
| `readings` | Live 1-min SoT (logger). Not purged by `-purge_derived`. On 3-phase P1 payloads, `power_l1_w` / `power_l2_w` / `power_l3_w` are filled each poll (largest-remainder scale so they sum to `active_power`). Single-phase or incomplete phase keys leave those columns NULL. |
| `sensorbox_history_5min` / `inverter_history_5min` | Staging SoT (WebBox import). Never purged. Holds every WebBox year. |
| `fluvius_interval` | Fluvius CSV SoT. Never purged. Electricity quarters, gas hours and days, monthly peak. |
| `sunnyportal_pv_5min` | Sunny Portal / ennexOS PV staging. Never purged. Filled only by `sunnyportal_pull.py`. `-buildall` does not write this table. |
| `history_5min` | Derived master (5-min). Rebuilt by `-buildall` from all `readings`, then WebBox staging, then empty-fill `solar_power_w` from `sunnyportal_pv_5min` where still null, then Fluvius electricity quarters. `-daily` refreshes the last 2 local days of `readings`, empty-fills Sunny Portal solar for that span, then stitches Fluvius quarters. `grid_import_kwh` / `grid_export_kwh` are the published quarter kWh on the quarter-start row. From P1 downsample: `power_l1_w` / `power_l2_w` / `power_l3_w` are AVG+ROUND of the 1-min phases when every sample in the bucket has them, then largest-remainder re-scaled to `grid_power_w`. Incomplete buckets, WebBox-only, and Fluvius-only rows leave phase columns NULL. |
| `daily_history` / `monthly_history` / `clear_sky_envelopes` / `top_records` / `top_periods` | Derived analytics. `-buildall` rebuilds them after `history_5min`. `-daily` refreshes recent `daily_history`, then rewrites `monthly_history` and `top_periods`, and beat-updates `top_records` when a recent day is a stricter extreme. `-buildalltoprecords` rewrites `top_records` only. |

**PAC quality gates** (rollup + analytics charts): spike cap is max inverter AC × 1.05 (default nameplate 12 kW, cap 12.6 kW) from Settings. Deep night and shoulder hours are editable; defaults drop PAC from 22:00 through 05:00, and on the shoulder (from 20:00 and before 06:00) when solar radiation is at or under 5 W/m². Night WebBox junk with rad≈0 must not define envelopes or inflate integrals.

**Timestamps:** logger uses host local time (CET). Downsample cutoffs use SQLite `datetime('now', 'localtime')` (UTC `now` drops afternoon slots).

## Settings

`settings.php` is the configuration surface. `logger.py`, `rollup.py`, `powertests.py`, and the PHP pages read `app_settings` in `meter_history.db`. PHP does not rewrite `.env`.

| Behavior | Rule |
|---|---|
| First open, no admin row | Create the admin password (enter it twice). The app does not continue until it is set. |
| This host, after that password | Dashboard. Settings is linked, not forced. Device and portal settings live in `app_settings` only. |
| New install, no P1 address | Admin password, then Settings. The live dashboard waits until P1 is saved. |
| Every settings save | Modal asks for the admin password. A wrong password rejects the save. No stay-logged-in session. |
| Device passwords | Stars when set. **Edit** asks for the admin password, then the field is cleared for a new value. The stored secret is never in the page. An empty field on save does not change the password. An unset field can be typed directly; save still asks for the admin password. |
| Change admin password | Separate modal: current password, new password, new password again, Enter. The two new values must match. |
| Admin hash | One-way (`password_hash`). Device passwords are stored so the poller can log in. |
| Spike cap | Max inverter AC × 1.05. Default nameplate 12000 W, cap 12600 W. |
| Night / shoulder | Defaults: drop PAC when the hour is `>= 22` or `< 5`; shoulder `>= 20` or `< 6` when radiation is `<= 5` W/m². |
| Apply | Titles and Summary PVGIS curve on the next page load (all-zero months = no PVGIS series). Plant limits and monthly `target_pct` when `rollup.py -buildall` / `-daily` runs. No rebuild button. `importer.py` inserts an `expected_monthly` row only when that month and inclination are missing. |

`DB_BACKUP_ENCRYPTION_KEY` is not a setting. It stays in `.env`. An empty key refuses both download and restore. Settings asks for the admin password on both. Restore also requires an explicit confirm that everything currently in the database will be destroyed. There is no stay-logged-in session.

`db_backup.py` makes a SQLite online backup, zips that file, and encrypts the zip (PBKDF2-SHA256, 100000 iterations, AES-256-GCM, salt + IV + ciphertext + tag). PHP checks the password and the confirm, starts Python, sends the file or swaps the database, and deletes the temp files. The key is not a command argument. The browser file is `meter_history.db.<yymmdd-HHMMSS>.backup` (Europe/Brussels). The zip member is `meter_history.db.<stamp>.bak`. After the download is sent, the server deletes the plain backup and the encrypted file. `p1-sync` does not pull those files.

Restore accepts only that encrypted file. `PRAGMA integrity_check` must be `ok`, then the live database is replaced and `-wal` / `-shm` are deleted. A failed check keeps the live database. One in-flight poll may fail. Runtime requirements are in [`deploy.md`](deploy.md).

The live chart on `index.php` is a **2-hour pulse** of 1-minute `readings` (auto-refresh). Segmented switch: **Exchange** (default), **Balance** (`?view=balance`), **Classic** (`?view=classic`), or **Phases** (`?view=phases`) when the pulse has `power_l*_w` samples. Exchange is bipolar signed net (Grid above 0 / Surplus below 0). Balance paints Self-sufficient, Grid, and Surplus; Production and Consumption are tooltip/legend composites. Phases draws three signed L1/L2/L3 lines plus a light filled **Sum** mountain (L1+L2+L3); null phase samples are omitted (gaps, no zero-fill). Longer windows, pan, and mixed history live on `analytics.php`. A step longer than 3 minutes is a gap on the live pulse.

## UI map

| Page | Role |
|---|---|
| `index.php` | Live dashboard. Power chart: last **2 hours** of 1-minute `readings`. Switch: **Exchange** (default, bipolar signed net) / **Balance** (`?view=balance`, smooth stacked bands) / **Classic** (`?view=classic`, straight area) / **Phases** (`?view=phases`, L1/L2/L3 lines + light Sum mountain from `power_l*_w`; control hidden when the pulse has no phase samples). Link to Analytics. No telemetry table; 1-min CSV on Settings. |
| `analytics.php` | Explorer: rolling presets 15 m–7 d plus calendar-day **Today** (chip after **24 h**, before **7 d**; **default**). **Today** / `?date=` use local midnight to next midnight (DST length as-is; incomplete today keeps the full axis). **Reset view** returns to Today. In day mode, pan ←/→ moves one calendar day (→ disabled on today; ← disabled on the earliest day in `history_5min` or `readings`). Rolling presets still pan by one window step ending at now. Switch: **Exchange** (default) / **Balance** / **Classic** / **Phases** — same modes as index; Phases hidden when the loaded window has no `power_l*_w`. Balance legend includes split dots for Consumption / Production. Mixed resolution per 5-minute bucket (rule V): if at least two 1-min `readings` fall in **bucket ∩ window**, use those 1-min points; else use `history_5min` (Fluvius W derive as before). Within a 5-min run, grid nulls of length 1..2 are linear-bridged for Classic/Balance/Exchange (Fluvius 1-in-3); phase series are not bridged. Tooltip shows source (`1 min` / `5 min`); Phases tooltip lists Sum + L1/L2/L3. Overlay and envelope stay solar-only calendar tools. Deep link: `range`, `date`, `from`/`to`, `view` (omit `range` when default Today). |
| `summary.php` | Period charts in order: week, month, year, then **All years** last. Segmented switch: **Solar yield** (default), **Grid import / export** (`?metric=grid`), or **Capacity peak** (`?metric=peak`). Solar uses `solar_yield_kwh` / `total_solar_kwh` plus live `expected_monthly` PVGIS (omit the curve and PVGIS stats when every month is 0). Grid uses grouped bars from `grid_import_kwh` / `grid_export_kwh` and the monthly `total_import_kwh` / `total_export_kwh`: import, export, and net (import minus export). A missing register is a gap. A second switch, only in grid mode, is **All** (default), **Import / export** (`bars=registers`), or **Net** (`bars=net`). Capacity peak reads Fluvius `piekvermogen` months from `fluvius_interval` (kW) for the Settings electricity EAN; shows **year** and **All years** only (week/month cards hidden). All-years peak bars are one per calendar year; **Max** / **Average** switch sits on the All years card (`peak_agg=avg`). Max and Average share the same Y-axis ceiling (Max-mode highs). The open local month bar is replaced by live P1 `montly_power_peak_w` converted to kW. Deep link: `?month=YYYY-MM` sets week (ISO week of day 1 of that month, or earliest overlapping available week), month, and year; `?year=YYYY` sets year; also `metric`, `bars`, `peak_agg`. |
| `topn.php` | Same three metric switches. **All-time records** (above the charts) from `top_records`, filtered by metric: **Solar (yield)** — best day kWh, best year kWh, peak surge W, sun-hours, self-consumption; **Capacity peak** — highest Capacity peak month (kW), year with highest average Capacity peak (kW); **wind / ambient / module** wait for A11 and stay hidden on solar/grid/peak; **Grid** hides the records card. Yield day rows → analytics; year generation / peak rows → summary. Solar rankings: best/worst yield (20 days, 10 months, 10 years) from `top_periods`. Grid: highest net import / export from `daily_history` / `monthly_history` (today excluded on days). Capacity peak rankings: highest **10** months; open month may use live P1 kW. Links keep `metric` and `bars`. Rebuild singles with `-buildalltoprecords` / `-buildall` / `-daily` beat. |
| `settings.php` | First open creates the admin password (entered twice) when none is stored. After that, graph pages open with no login session. The page edits devices, titles, 12 monthly expected kWh (inclination 30 only; irradiance stays the seed), max inverter AC, the night/shoulder gates, and the optional Fluvius electricity and gas EANs. Download and restore of the encrypted database backup ask for the admin password. Restore asks for an explicit confirm that everything currently in the database will be destroyed. **Download 1-min CSV** (all `readings` rows, including `power_l1_w` / `power_l2_w` / `power_l3_w` when present) sits beside backup and also asks for the admin password. |

## Rollup CLI (operator)

| Flag | Effect |
|---|---|
| `-consolidate` | Staging → solar and weather on `history_5min` (`data_source` `webbox_archive`) |
| `-daily` | Six status steps: (1) last 2 local days of `readings` → `history_5min`; (2) Sunny Portal empty-fill `solar_power_w` for that span (no console block when staging is empty); Fluvius quarter stitch (no status line); (3) last 3 local days → `daily_history`; (4) full rewrite `monthly_history`; (5) `top_periods`; (6) beat-update `top_records` for that stitch calendar span (stricter extreme only) |
| `-buildall` | Delete `history_5min`, `daily_history`, `monthly_history` (no `VACUUM`). Refill `history_5min` from every `readings` row except the open 5-minute slice, then WebBox staging, then Sunny Portal empty-fill `solar_power_w`, then Fluvius electricity quarters. Then daily, monthly, envelopes, `top_records`, `top_periods`. Rare. The day chart is empty until the command finishes. |
| `-buildallsunnyportal` | Empty-fill `history_5min.solar_power_w` from `sunnyportal_pv_5min` only (no wipe, no daily/monthly rebuild). Prints how many 5-min slots were written, first/last stamp (silent when staging is empty). |
| `-buildalltoprecords` | Full rewrite of `top_records` only (no wipe of `history_5min` / daily / monthly) |
| `-purge_derived` | Wipes the six derived tables and `VACUUM`. `readings`, WebBox staging, `fluvius_interval`, and `sunnyportal_pv_5min` stay. Separate from `-buildall`. |

`importer.py -aggregate5min`, `rollup.py -today`, and the analytics **Generate today table** UI are removed. Today’s closed 5-minute buckets land in `history_5min` via nightly `-daily` (or a rare `-buildall`). WebBox import writes staging only.

`sunnyportal_pull.py` logs in with Sunny Portal credentials from `app_settings` in `meter_history.db` (Settings page). It POSTs the unpaid ennexOS `measurements/search` API for PV power into `sunnyportal_pv_5min` on that same database. No switches prints usage. `-pullall` pulls the full portal span through today. `-lastdays -n N` pulls the last N days including today (N 1..366). `-probe` prints candidate channels that return samples (console only). It does not write `history_5min`; rollup empty-fills `solar_power_w` where null (`data_source` `sunnyportal` only on new rows). There is no `sunnyportal_csv_test.py`.

`fluvius_import.py` reads `import_queue/fluvius/*.csv` and writes `fluvius_interval`. A row is kept when its EAN equals Electricity EAN or Gas EAN from Settings. Both empty: the CLI stops and reads nothing. A file that parses is moved to `import_queue/fluvius/processed/` even when every row was skipped. A file that does not parse stays in the queue and the CLI names the row. The process exits 1 if any file failed. There is no import button. `importer.py` does not read these CSVs.

Resolution comes from exactly one filename token: `kwartiertotalen` → `15min`, `uurtotalen` → `hour`, `dagtotalen` → `day`, `piekvermogen` → `month`. A CSV without exactly one of those stays in the queue. UTF-8 with or without BOM, separator `;`, dates `DD-MM-YYYY`, decimal comma. EAN digits after stripping `="…"`. Required header: Van (datum), Van (tijdstip), Tot (datum), Tot (tijdstip), EAN-code, Meter, Metertype, Register, Volume, Eenheid, Validatiestatus. Registers: `afname_dag`, `afname_nacht`, `injectie_dag`, `injectie_nacht`, gas `afname`, `piekvermogen`. File unit `m³` is stored as `m3`. `Geen verbruik` with a blank volume is 0. Primary key `(ean, register, unit, resolution, interval_start, occurrence)`. A later file with the same key replaces the row. `validation` is the portal text, stored as written (`Uitgelezen`, `Geen verbruik`, `Geschat`, `Voorlopig`, and any other non-blank status). A blank validation cell rejects the whole file. Gas kWh also stores `calorific` (`Definitief`, `Voorlopig`, or empty). After a run, a log-only cross-check prints electricity quarter-sum versus day-file gaps over 0.001 kWh on a complete day, gas m³ gaps over 0.001, and gas kWh gaps over 0.002 when both sides are `Definitief`. It does not fail the import or skip a row.

On a passing electricity day the daily kWh stays the P1 counter delta. A finished day falls out when `readings` has no sample at or before 00:15, or none at or after 23:45, or `total_import` / `total_export` steps down by more than 0.01 kWh. The local calendar day still in progress is not a fall-out day. A fall-out day with a complete 15-minute set uses the sum of those quarters and writes one curve point per quarter start. Otherwise the day file is the total and no curve is written. Gas is not copied onto `history_5min`. A passing day's empty quarter (all three 5-minute slots missing from P1) gets one curve point. Autumn's repeated local start sums both occurrences into that one timestamp. The spring `01:45`–`03:00` row is stored at `01:45` only.

## Config split

| Concern | File | Public git | Sync to NAS |
|---|---|---|---|
| Backup encryption key | `.env` (`DB_BACKUP_ENCRYPTION_KEY` only) | name in `.env.example` | **never** |
| Device IPs, device passwords, titles, plant limits, Sunny Portal user / password / plant id / timezone, Fluvius electricity EAN and gas EAN | `meter_history.db` `app_settings` (Settings page only; not read from `.env`) | no | DB is not in git; sync excludes `.env*` and `*.db` |
| Admin password | `meter_history.db` `app_admin` (one-way hash) | no | same as the DB |
| Sync source + SMB targets | `helpers/p1-sync.config.json` | no | Synology: **no** / OneDrive helpers-backup: **yes** |
| Sync scripts + local helpers README | `helpers/` | no (whole dir gitignored) | Synology: **no** / OneDrive helpers-backup: **yes** |

Committed files (code + docs): **no** real LAN IPs, **no** household/site nicknames.

## License

**MIT** — root [`LICENSE`](../LICENSE).
