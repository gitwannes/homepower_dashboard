# --- file: docs/architecture.md ---
# Architecture

**Status:** Spec **LOCKED** (2026-10-22). App migrate **HOLD** (no implement yet).  
**Last updated:** 2026-10-22  
**Delivery:** [`docs/todo/phaseM-migration.md`](todo/phaseM-migration.md)

## What this is

A **standalone** home energy hub: poll P1 + optional SMA / Sunny WebBox, store timeseries in SQLite, present a PHP dashboard. Designed to run on **Synology** (Web Station + scheduled Python), not as part of WanOS or WLW.

## What this is not

| System | Relation |
|---|---|
| **WanOS** | Separate smart-home OS. Its HomeWizard energy ingest stays there — do not move that bridge into this repo. |
| **WLW** | Former git home of `public/p1`. After M2: remove or stub; the portal no longer owns this app. |
| **Unrelated "P1" phase ids** | Other repos may use "P1" for unrelated work. This product is the meter/dashboard stack. |

## Data flow (target)

```
.env  (devices + UI branding; encrypted storage -> host by hand)
    -> logger.py  (poll P1 / SMA / WebBox)
    -> meter_history.db (SQLite)
    -> index.php / analytics.php (read-only UI)

helpers/  (gitignored entire directory)
    p1-sync.config.json  -> source path + SMB targets
    p1-sync.ps1|.bat     -> robocopy MIR app + helpers/ to NAS
                         -> never copies .env*
```

## Config split (locked)

| Concern | File | Public git | Sync to NAS |
|---|---|---|---|
| Device IPs + passwords | `.env` | `.env.example` only | **never** |
| UI title / subtitle / labels | `.env` | placeholders in example | **never** |
| Sync source + SMB targets | `helpers/p1-sync.config.json` | no | **yes** |
| Sync scripts + local helpers README | `helpers/` | no (whole dir gitignored) | **yes** |

- Sync tooling is **operator-local**, not published.
- Runtime `.env` on Synology is placed from **encrypted storage**, not from `p1-sync`.
- Committed files (code + docs): **no** real LAN IPs, **no** household/site nicknames; UI falls back to a generic tagline when `.env` keys are absent.

## License

**MIT** — root [`LICENSE`](../LICENSE).
