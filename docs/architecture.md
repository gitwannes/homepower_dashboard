# --- file: docs/architecture.md ---
# Architecture

**Status:** Migrated into this repo (2026-10-22). Phase A warehouse + UI Done.  
**Last updated:** 2026-10-27 (A9 encrypted backup closed)  
**Delivery archives:** [`docs/todo/_archive/phaseM-migration.md`](todo/_archive/phaseM-migration.md) · [`docs/todo/_archive/phaseA-aggregation.md`](todo/_archive/phaseA-aggregation.md) (A0–A3)

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
| `readings` | Live 1-min SoT (logger). Not purged by `-purge_derived`. |
| `sensorbox_history_5min` / `inverter_history_5min` | Staging SoT (WebBox import). Never purged. Holds every WebBox year. |
| `fluvius_interval` | Fluvius CSV SoT. Never purged. Electricity quarters, gas hours and days, monthly peak. |
| `history_5min` | Derived master (5-min). Rebuilt by `-buildall` from all `readings`, then WebBox staging, then Fluvius electricity quarters. `-daily` refreshes the last 2 local days of `readings` and stitches Fluvius quarters on those days. `grid_import_kwh` / `grid_export_kwh` are the published quarter kWh on the quarter-start row. |
| `daily_history` / `monthly_history` / `clear_sky_envelopes` / `top_records` / `top_periods` | Derived analytics. `-buildall` rebuilds them after `history_5min`. `-daily` refreshes recent `daily_history`, then rewrites `monthly_history` and `top_periods`, and beat-updates `top_records` when a recent day is a stricter extreme. `-buildalltoprecords` rewrites `top_records` only. |

**PAC quality gates** (rollup + analytics charts): spike cap is max inverter AC × 1.05 (default nameplate 12 kW, cap 12.6 kW) from Settings. Deep night and shoulder hours are editable; defaults drop PAC from 22:00 through 05:00, and on the shoulder (from 20:00 and before 06:00) when solar radiation is at or under 5 W/m². Night WebBox junk with rad≈0 must not define envelopes or inflate integrals.

**Timestamps:** logger uses host local time (CET). Downsample cutoffs use SQLite `datetime('now', 'localtime')` (UTC `now` drops afternoon slots).

## Settings

`settings.php` is the configuration surface. `logger.py`, `rollup.py`, `powertests.py`, and the PHP pages read `app_settings` in `meter_history.db`. PHP does not rewrite `.env`.

| Behavior | Rule |
|---|---|
| First open, no admin row | Create the admin password (enter it twice). The app does not continue until it is set. |
| This host, after that password | Dashboard. Settings is linked, not forced. Existing device keys in `.env` are copied once into `app_settings` when that table is empty. |
| New install, no P1 address | Admin password, then Settings. The live dashboard waits until P1 is saved. |
| Every settings save | Modal asks for the admin password. A wrong password rejects the save. No stay-logged-in session. |
| Device passwords | Stars when set. **Edit** asks for the admin password, then the field is cleared for a new value. The stored secret is never in the page. An empty field on save does not change the password. An unset field can be typed directly; save still asks for the admin password. |
| Change admin password | Separate modal: current password, new password, new password again, Enter. The two new values must match. |
| Admin hash | One-way (`password_hash`). Device passwords are stored so the poller can log in. |
| Spike cap | Max inverter AC × 1.05. Default nameplate 12000 W, cap 12600 W. |
| Night / shoulder | Defaults: drop PAC when the hour is `>= 22` or `< 5`; shoulder `>= 20` or `< 6` when radiation is `<= 5` W/m². |
| Apply | Titles on the next page load. Plant limits and monthly kWh when `rollup.py -buildall` runs. No rebuild button. `importer.py` inserts an `expected_monthly` row only when that month and inclination are missing. |

`DB_BACKUP_ENCRYPTION_KEY` is not a setting. It stays in `.env`. An empty key refuses both download and restore. Settings asks for the admin password on both. Restore also requires an explicit confirm that everything currently in the database will be destroyed. There is no stay-logged-in session.

`db_backup.py` makes a SQLite online backup, zips that file, and encrypts the zip (PBKDF2-SHA256, 100000 iterations, AES-256-GCM, salt + IV + ciphertext + tag). PHP checks the password and the confirm, starts Python, sends the file or swaps the database, and deletes the temp files. The key is not a command argument. The browser file is `meter_history.db.<yymmdd-HHMMSS>.backup` (Europe/Brussels). The zip member is `meter_history.db.<stamp>.bak`. After the download is sent, the server deletes the plain backup and the encrypted file. `p1-sync` does not pull those files.

Restore accepts only that encrypted file. `PRAGMA integrity_check` must be `ok`, then the live database is replaced and `-wal` / `-shm` are deleted. A failed check keeps the live database. One in-flight poll may fail. Runtime requirements are in [`deploy.md`](deploy.md).

The live chart on `index.php` is a **2-hour pulse** of 1-minute `readings` (auto-refresh). Segmented switch: **Exchange** (default), **Balance** (`?view=balance`), or **Classic** (`?view=classic`). Exchange is bipolar signed net (Grid above 0 / Surplus below 0). Balance paints Self-sufficient, Grid, and Surplus; Production and Consumption are tooltip/legend composites. Longer windows, pan, and mixed history live on `analytics.php`. A step longer than 3 minutes is a gap on the live pulse.

## UI map

| Page | Role |
|---|---|
| `index.php` | Live dashboard. Power chart: last **2 hours** of 1-minute `readings`. Switch: **Exchange** (default, bipolar signed net) / **Balance** (`?view=balance`, smooth stacked bands) / **Classic** (`?view=classic`, straight area). Link to Analytics. No telemetry table; 1-min CSV on Settings. |
| `analytics.php` | Explorer: clock window presets 15 m–7 d (default **6 h** ending now), **Reset view**, pan ←/→ by one window. Switch: **Exchange** (default) / **Balance** (`?view=balance`) / **Classic** (`?view=classic`) — same modes as index. Balance legend includes split dots for Consumption / Production. Mixed resolution per 5-minute bucket (rule V): if at least two 1-min `readings` fall in **bucket ∩ window**, use those 1-min points; else use `history_5min` (Fluvius W derive as before). No linear bridge across a 1-min/5-min change. Tooltip shows source (`1 min` / `5 min`). Overlay and envelope stay solar-only calendar tools. Deep link: `range`, `from`/`to`, `view`. |
| `summary.php` | Period charts: **All years**, week, month, year. Segmented switch: **Solar yield** (default) or **Grid import / export** (`?metric=grid`). Solar uses `solar_yield_kwh` / `total_solar_kwh` plus PVGIS. Grid uses grouped bars from `grid_import_kwh` / `grid_export_kwh` and the monthly `total_import_kwh` / `total_export_kwh`: import, export, and net (import minus export). A missing register is a gap. A second switch, only in grid mode, is **All** (default), **Import / export** (`bars=registers`), or **Net** (`bars=net`). Deep link: `?month=YYYY-MM`, `?year=YYYY`. |
| `topn.php` | Same two switches. **All-time records** (above the charts) from `top_records`: best day kWh, peak surge W, sun-hours, self-consumption, wind, ambient min/max, module max — wind/ambient/module/self-consumption rows only when present. Each dated row → analytics that day. Solar: best/worst yield (20 days, 10 months, 10 years) from `top_periods`; day click → analytics, month/year → summary. Worst **days** exclude yield ≤ 0 and the current calendar day. Grid: highest net import (import minus export) and highest net export (export minus import), same limits, read from `daily_history` / `monthly_history`. Both day lists omit the local day still in progress. Each card shows import, export, and the ranking net, filtered by the bars switch. A grid day click opens that month on summary; a month or year click opens summary. The link keeps `metric` and `bars`. |
| `settings.php` | First open creates the admin password (entered twice) when none is stored. After that, graph pages open with no login session. The page edits devices, titles, 12 monthly expected kWh (inclination 30 only; irradiance stays the seed), max inverter AC, the night/shoulder gates, and the optional Fluvius electricity and gas EANs. Download and restore of the encrypted database backup ask for the admin password. Restore asks for an explicit confirm that everything currently in the database will be destroyed. **Download 1-min CSV** (all `readings` rows) sits beside backup and also asks for the admin password. |

## Rollup CLI (operator)

| Flag | Effect |
|---|---|
| `-consolidate` | Staging → solar and weather on `history_5min` (`data_source` `webbox_archive`) |
| `-daily` | Last 2 local days of `readings` → `history_5min`, then Fluvius quarter stitch for those days; last 3 local days of `history_5min` → `daily_history`; full rewrite of `monthly_history` and `top_periods`; beat-update `top_records` for that stitch calendar span (stricter extreme only) |
| `-buildall` | Delete `history_5min`, `daily_history`, `monthly_history` (no `VACUUM`). Refill `history_5min` from every `readings` row except the open 5-minute slice, then from staging, then Fluvius electricity quarters. Then daily, monthly, envelopes, `top_records`, `top_periods`. Rare. The day chart is empty until the command finishes. |
| `-buildalltoprecords` | Full rewrite of `top_records` only (no wipe of `history_5min` / daily / monthly) |
| `-purge_derived` | Wipes the six derived tables and `VACUUM`. `readings`, WebBox staging, and `fluvius_interval` stay. Separate from `-buildall`. |

`importer.py -aggregate5min`, `rollup.py -today`, and the analytics **Generate today table** UI are removed. Today’s closed 5-minute buckets land in `history_5min` via nightly `-daily` (or a rare `-buildall`). WebBox import writes staging only.

`fluvius_import.py` reads `import_queue/fluvius/*.csv` and writes `fluvius_interval`. A row is kept when its EAN equals Electricity EAN or Gas EAN from Settings. Both empty: the CLI stops and reads nothing. A file that parses is moved to `import_queue/fluvius/processed/` even when every row was skipped. A file that does not parse stays in the queue and the CLI names the row. The process exits 1 if any file failed. There is no import button. `importer.py` does not read these CSVs.

Resolution comes from exactly one filename token: `kwartiertotalen` → `15min`, `uurtotalen` → `hour`, `dagtotalen` → `day`, `piekvermogen` → `month`. A CSV without exactly one of those stays in the queue. UTF-8 with or without BOM, separator `;`, dates `DD-MM-YYYY`, decimal comma. EAN digits after stripping `="…"`. Required header: Van (datum), Van (tijdstip), Tot (datum), Tot (tijdstip), EAN-code, Meter, Metertype, Register, Volume, Eenheid, Validatiestatus. Registers: `afname_dag`, `afname_nacht`, `injectie_dag`, `injectie_nacht`, gas `afname`, `piekvermogen`. File unit `m³` is stored as `m3`. `Geen verbruik` with a blank volume is 0. Primary key `(ean, register, unit, resolution, interval_start, occurrence)`. A later file with the same key replaces the row. `validation` is the portal text, stored as written (`Uitgelezen`, `Geen verbruik`, `Geschat`, `Voorlopig`, and any other non-blank status). A blank validation cell rejects the whole file. Gas kWh also stores `calorific` (`Definitief`, `Voorlopig`, or empty). After a run, a log-only cross-check prints electricity quarter-sum versus day-file gaps over 0.001 kWh on a complete day, gas m³ gaps over 0.001, and gas kWh gaps over 0.002 when both sides are `Definitief`. It does not fail the import or skip a row.

On a passing electricity day the daily kWh stays the P1 counter delta. A finished day falls out when `readings` has no sample at or before 00:15, or none at or after 23:45, or `total_import` / `total_export` steps down by more than 0.01 kWh. The local calendar day still in progress is not a fall-out day. A fall-out day with a complete 15-minute set uses the sum of those quarters and writes one curve point per quarter start. Otherwise the day file is the total and no curve is written. Gas is not copied onto `history_5min`. A passing day's empty quarter (all three 5-minute slots missing from P1) gets one curve point. Autumn's repeated local start sums both occurrences into that one timestamp. The spring `01:45`–`03:00` row is stored at `01:45` only.

## Config split

| Concern | File | Public git | Sync to NAS |
|---|---|---|---|
| Backup encryption key | `.env` (`DB_BACKUP_ENCRYPTION_KEY` only) | name in `.env.example` | **never** |
| Device IPs, device passwords, titles, plant limits, Sunny Portal user / password / plant id / timezone, Fluvius electricity EAN and gas EAN | `meter_history.db` `app_settings` (Settings page). Existing `.env` device keys are imported once when that table is empty. Sunny Portal keys are copied when that settings row is still absent. | no | DB is not in git; sync excludes `.env*` and `*.db` |
| Admin password | `meter_history.db` `app_admin` (one-way hash) | no | same as the DB |
| Sync source + SMB targets | `helpers/p1-sync.config.json` | no | Synology: **no** / OneDrive helpers-backup: **yes** |
| Sync scripts + local helpers README | `helpers/` | no (whole dir gitignored) | Synology: **no** / OneDrive helpers-backup: **yes** |

Committed files (code + docs): **no** real LAN IPs, **no** household/site nicknames.

## License

**MIT** — root [`LICENSE`](../LICENSE).
