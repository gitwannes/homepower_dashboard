# --- file: README.md ---
# Home Power Dashboard

Standalone energy telemetry dashboard for:

- **P1** digital meter (HomeWizard Local API / DSMR-style JSON)
- **SMA** WebConnect inverter
- **Sunny WebBox** gateway (+ SensorBox when present)

**Runtime host:** Synology DSM (Web Station + Python Task Scheduler).  
**Origin:** extracted from the WLW portal tree (`public/p1`) after the live stack moved to Synology.  
**License:** [MIT](LICENSE)

## Status

Scaffold + **migration spec locked** (2026-10-22). Application sources are **not** in this repo yet — implement on hold until the operator says `implement`.

## Layout (planned)

| Path | Role |
|---|---|
| App root | `logger.py`, PHP UI, importers, `.env.example` (after M1) |
| `docs/` | Product reference (as-is) |
| `docs/todo/` | Delivery pipeline |
| `helpers/` | **Local only** (entire directory gitignored): sync tool, config, local README — mirrored to NAS by sync |

## Config (locked)

| File | Holds | Git | Deploy |
|---|---|---|---|
| `.env` | Device endpoints/secrets + UI title/subtitle/labels | no | Encrypted storage → host by hand; **never** via sync |
| `.env.example` | Placeholders only | yes | — |
| `helpers/p1-sync.config.json` | Sync source + SMB targets | no | Mirrored with `helpers/` |
| `helpers/p1-sync.ps1` / `.bat` + `helpers/README.md` | Sync tool + local notes | no | Mirrored with `helpers/` |

Committed files (including documentation) must not contain real LAN IPs or household/site nicknames.

## Docs

| Doc | Purpose |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | Scope, config split, hosts |
| [`docs/todo/pipeline.md`](docs/todo/pipeline.md) | Delivery sequence |
| [`docs/todo/phaseM-migration.md`](docs/todo/phaseM-migration.md) | Locked migrate DoD |
