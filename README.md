# --- file: README.md ---
# Home Power Dashboard

Standalone energy telemetry dashboard for:

- **P1** digital meter (HomeWizard Local API / DSMR-style JSON)
- **SMA** WebConnect inverter
- **Sunny WebBox** gateway (+ SensorBox when present)

**Runtime host:** Synology DSM (Web Station + Python Task Scheduler).  
**License:** [MIT](LICENSE)

## Architecture

`logger.py` polls hardware and writes SQLite (`meter_history.db`).  
`index.php` / `analytics.php` are read-only presentation over the DB.

```
.env  ->  logger.py  ->  meter_history.db  ->  index.php / analytics.php
```

See [`docs/architecture.md`](docs/architecture.md).

## Configuration

| File | Role | Git |
|---|---|---|
| `.env` | Device endpoints, secrets, UI title/tagline | **no** (encrypted storage → host by hand) |
| `.env.example` | Placeholders only | yes |
| `helpers/` | Local sync tool + `p1-sync.config.json` | **no** (entire dir gitignored; MIR copies it to NAS) |

Never put real LAN IPs or site nicknames in committed files.

## Main components

| File | Role |
|---|---|
| `logger.py` | 1-minute poll / hot DB writer |
| `index.php` | Live dashboard |
| `analytics.php` | Historical analytics |
| `importer.py` / `import_dashboard.php` | WebBox archive ingest |
| `rollup.py` / `sql_importer.py` | Warehouse / gap-fill |
| `powertests.py` / `powertests.php` | Live hardware probes |

## Docs

| Doc | Purpose |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | Scope + config split |
| [`docs/deploy.md`](docs/deploy.md) | Synology + local sync notes |
| [`docs/roadmap.md`](docs/roadmap.md) | Feature / archive roadmap |
| [`docs/todo/pipeline.md`](docs/todo/pipeline.md) | Delivery pipeline |
| [`docs/todo/_archive/phaseM-migration.md`](docs/todo/_archive/phaseM-migration.md) | Migrate DoD (Done) |
