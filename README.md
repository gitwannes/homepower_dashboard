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
`index.php` / `analytics.php` / `summary.php` read that database. `settings.php` writes site configuration into it.

```
.env (backup key) + Settings page -> meter_history.db -> logger.py -> index.php / analytics.php / summary.php
```

See [`docs/architecture.md`](docs/architecture.md).

## Configuration

| File | Role | Git |
|---|---|---|
| `.env` | `DB_BACKUP_ENCRYPTION_KEY` only. Legacy device keys are imported once into the database. | **no** (encrypted storage → host by hand) |
| `.env.example` | Key names only | yes |
| `helpers/` | Local sync tool + `p1-sync.config.json` | **no** (entire dir gitignored; MIR copies it to NAS) |

Never put real LAN IPs or site nicknames in committed files.

## Main components

| File | Role |
|---|---|
| `logger.py` | 1-minute poll / hot DB writer |
| `index.php` | Live dashboard |
| `settings.php` | Admin password and site configuration |
| `analytics.php` | Exploration: intraday, two-day compare, clear-sky envelope; **Generate table** for today |
| `summary.php` | All years / week / month / year (`daily_history` / `monthly_history`) |
| `topn.php` | Best/worst yield Top-N (days/months/years); click-through to period views |
| `importer.py` / `import_dashboard.php` | WebBox archive ingest |
| `rollup.py` / `sql_importer.py` | Warehouse: consolidate, `-daily`/`-today`, `-buildall`, gap-fill |
| `powertests.py` / `powertests.php` | Live hardware probes |

## Docs

| Doc | Purpose |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | Scope + config split |
| [`docs/deploy.md`](docs/deploy.md) | Synology + local sync notes |
| [`docs/roadmap.md`](docs/roadmap.md) | Feature / archive roadmap |
| [`docs/todo/pipeline.md`](docs/todo/pipeline.md) | Delivery pipeline |
| [`docs/todo/_archive/phaseM-migration.md`](docs/todo/_archive/phaseM-migration.md) | Migrate DoD (Done) |
| [`docs/todo/phaseA-aggregation.md`](docs/todo/phaseA-aggregation.md) | Aggregation + A3 UI (Done — move offline when convenient) |
