# --- file: docs/architecture.md ---
# Architecture

**Status:** Migrated into this repo (2026-10-22). Phase A warehouse + UI Done.  
**Last updated:** 2026-10-22  
**Delivery archives:** [`docs/todo/_archive/phaseM-migration.md`](todo/_archive/phaseM-migration.md) · Phase A detail ready for offline archive (`docs/todo/phaseA-aggregation.md`)

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
.env  (devices + UI branding; encrypted storage -> host by hand)
    -> logger.py  (poll P1 / SMA / WebBox)  -> readings (1 min)
    -> importer / sql_importer              -> staging (5 min)
    -> rollup.py -consolidate / -daily / -today / -buildall
    -> history_5min + daily/monthly/envelopes/top_records
    -> index.php / analytics.php / summary.php (read-only UI)

helpers/  (gitignored entire directory; PC-only sync tool)
    p1-sync.config.json  -> per-target path / excludes / optional source_subdir
    p1-sync.ps1|.bat     -> Synology: app only (no helpers/); OneDrive: helpers/ only
                         -> never copies .env*
```

## Warehouse (SoT vs derived)

| Store | Role |
|---|---|
| `readings` | Live 1-min SoT (logger). Not purged by `-purge_derived`. |
| `sensorbox_history_5min` / `inverter_history_5min` | Staging SoT (ZIP / SQL import). |
| `history_5min` | Derived master (5-min). From `-consolidate` (staging) + `-daily`/`-today` (`readings`). Rebuildable. |
| `daily_history` / `monthly_history` / `clear_sky_envelopes` / `top_records` / `top_periods` | Derived analytics. `-buildall` or incremental `-daily` (Top-N yield lists in `top_periods`). |

**PAC quality gates** (rollup + analytics charts): hard cap `MAX_PLAUSIBLE_PAC_W` (12 kW); ignore deep-night PAC (22–04); shoulder hours (20–21, 05) require `solar_radiation > 5`. Night WebBox junk with rad≈0 must not define envelopes or inflate integrals.

**Timestamps:** logger uses host local time (CET). Downsample cutoffs use SQLite `datetime('now', 'localtime')` (UTC `now` drops afternoon slots).

## UI map

| Page | Role |
|---|---|
| `index.php` | Live dashboard |
| `analytics.php` | Exploration: daily 5-min curve (`history_5min`), two-day overlay, clear-sky envelope. **Generate table** (today only) downsamples today’s `readings` → `history_5min`. Deep link: `?date=YYYY-MM-DD`. |
| `summary.php` | Period bars: **All years**, week, month, year (from `daily_history` / `monthly_history` + PVGIS). Deep link: `?month=YYYY-MM`, `?year=YYYY`. |
| `topn.php` | Best/worst yield rankings (20 days, 10 months, 10 years) from `top_periods`; click bar → analytics/summary. Worst **days** exclude yield ≤ 0 and the current calendar day. |

## Rollup CLI (operator)

| Flag | Effect |
|---|---|
| `-consolidate` | Staging → `history_5min` |
| `-daily` | Last 2 local days `readings` → `history_5min`; refresh recent daily/monthly |
| `-today` | Today only (same path as analytics **Generate table**) |
| `-buildall` | Rebuild daily, monthly, envelopes, top_records, **top_periods** from `history_5min` (does **not** ingest `readings`) |
| `-purge_derived` | Wipes `history_5min` + analytical tables; staging + `readings` stay |

## Config split

| Concern | File | Public git | Sync to NAS |
|---|---|---|---|
| Device IPs + passwords | `.env` | `.env.example` only | **never** |
| UI title / subtitle / labels | `.env` (`SITE_*`) | placeholders in example | **never** |
| Sync source + SMB targets | `helpers/p1-sync.config.json` | no | Synology: **no** / OneDrive helpers-backup: **yes** |
| Sync scripts + local helpers README | `helpers/` | no (whole dir gitignored) | Synology: **no** / OneDrive helpers-backup: **yes** |

Committed files (code + docs): **no** real LAN IPs, **no** household/site nicknames.

## License

**MIT** — root [`LICENSE`](../LICENSE).
