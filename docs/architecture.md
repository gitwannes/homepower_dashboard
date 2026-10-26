# --- file: docs/architecture.md ---
# Architecture

**Status:** Migrated into this repo (2026-10-22). Phase A warehouse + UI Done.  
**Last updated:** 2026-10-26 (A6 settings closed; A10 warehouse commands)  
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
    -> settings page writes meter_history.db app_settings (devices, titles, plant)
    -> logger.py (poll P1 / SMA / WebBox)  -> readings (1 min)
    -> importer.py (+ -outerzip for a master container) -> staging (5 min)
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
| `history_5min` | Derived master (5-min). Rebuilt by `-buildall` from all `readings` plus staging, and refreshed by nightly `-daily` (last 2 local days of `readings`). |
| `daily_history` / `monthly_history` / `clear_sky_envelopes` / `top_records` / `top_periods` | Derived analytics. `-buildall` rebuilds them after `history_5min`. `-daily` refreshes recent `daily_history`, then rewrites `monthly_history` and `top_periods`. |

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

`DB_BACKUP_ENCRYPTION_KEY` is not a setting. It stays in `.env`. Download and restore of the database is **A9**, not this page.

## UI map

| Page | Role |
|---|---|
| `index.php` | Live dashboard |
| `analytics.php` | Exploration: daily 5-min curve (`history_5min`), two-day overlay, clear-sky envelope. **Generate table** (today only) downsamples today’s `readings` → `history_5min`. Deep link: `?date=YYYY-MM-DD`. |
| `summary.php` | Period bars: **All years**, week, month, year (from `daily_history` / `monthly_history` + PVGIS). Deep link: `?month=YYYY-MM`, `?year=YYYY`. |
| `topn.php` | Best/worst yield rankings (20 days, 10 months, 10 years) from `top_periods`; click bar → analytics/summary. Worst **days** exclude yield ≤ 0 and the current calendar day. |
| `settings.php` | First open creates the admin password (entered twice) when none is stored. After that, graph pages open with no login session. The page edits devices, titles, 12 monthly expected kWh (inclination 30 only; irradiance stays the seed), max inverter AC, and the night/shoulder gates. |

## Rollup CLI (operator)

| Flag | Effect |
|---|---|
| `-consolidate` | Staging → solar and weather on `history_5min` (`data_source` `webbox_archive`) |
| `-daily` | Last 2 local days of `readings` → `history_5min`; last 3 local days of `history_5min` → `daily_history`; full rewrite of `monthly_history` and `top_periods` |
| `-buildall` | Delete `history_5min`, `daily_history`, `monthly_history` (no `VACUUM`). Refill `history_5min` from every `readings` row except the open 5-minute slice, then from staging. Then daily, monthly, envelopes, `top_records`, `top_periods`. Rare. The day chart is empty until the command finishes. |
| `-purge_derived` | Wipes the six derived tables and `VACUUM`. `readings` and staging stay. Separate from `-buildall`. |

`importer.py -aggregate5min` and `rollup.py -today` are removed. **Generate table** in `analytics.php` is the today-only downsample into `history_5min`. It does not write `daily_history` or `top_periods`. WebBox import writes staging only.

## Config split

| Concern | File | Public git | Sync to NAS |
|---|---|---|---|
| Backup encryption key | `.env` (`DB_BACKUP_ENCRYPTION_KEY` only) | name in `.env.example` | **never** |
| Device IPs, device passwords, titles, plant limits, Sunny Portal user / password / plant id / timezone | `meter_history.db` `app_settings` (Settings page). Existing `.env` device keys are imported once when that table is empty. Sunny Portal keys are copied when that settings row is still absent. | no | DB is not in git; sync excludes `.env*` and `*.db` |
| Admin password | `meter_history.db` `app_admin` (one-way hash) | no | same as the DB |
| Sync source + SMB targets | `helpers/p1-sync.config.json` | no | Synology: **no** / OneDrive helpers-backup: **yes** |
| Sync scripts + local helpers README | `helpers/` | no (whole dir gitignored) | Synology: **no** / OneDrive helpers-backup: **yes** |

Committed files (code + docs): **no** real LAN IPs, **no** household/site nicknames.

## License

**MIT** — root [`LICENSE`](../LICENSE).
