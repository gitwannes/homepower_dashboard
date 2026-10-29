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
| `.env` | `DB_BACKUP_ENCRYPTION_KEY` only. Device and portal settings are on the Settings page. | **no** (encrypted storage → host by hand) |
| `.env.example` | Key names only | yes |
| `helpers/` | Local sync tool + `p1-sync.config.json` | **no** (entire dir gitignored; MIR copies it to NAS) |

Never put real LAN IPs or site nicknames in committed files.

## Main components

| File | Role |
|---|---|
| `logger.py` | 1-minute poll / hot DB writer |
| `index.php` | Live dashboard. 2-hour pulse; Exchange / Balance / Classic (Exchange default) |
| `settings.php` | Admin password, site configuration, encrypted DB backup/restore, 1-min CSV download |
| `analytics.php` | Explorer (window+pan, mixed 1/5-min, Exchange default), two-day compare, clear-sky envelope |
| `summary.php` | Week / month / year / All years (last). Solar yield (default), grid import/export/net, or Capacity peak |
| `topn.php` | Best/worst solar yield, grid net ranks, or highest Capacity peak months; metric-filtered all-time records |
| `importer.py` / `import_dashboard.php` | WebBox Mean XML and daily archives → staging tables |
| `fluvius_import.py` | Fluvius CSV in `import_queue/fluvius/` → `fluvius_interval` (Settings EANs only) |
| `sunnyportal_pull.py` | ennexOS PV → `sunnyportal_pv_5min` (`-pullall` / `-lastdays` / `-probe`) |
| `rollup.py` | Warehouse: `-consolidate`, nightly `-daily`, rare `-buildall` (Sunny Portal solar empty-fill + Fluvius electricity) |
| `powertests.py` / `powertests.php` | Live hardware probes |

## Docs

| Doc | Purpose |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | Scope + config split |
| [`docs/power-notes.md`](docs/power-notes.md) | Operator warehouse cheat sheet (commands / tables) |
| [`docs/deploy.md`](docs/deploy.md) | Synology + local sync notes |
| [`docs/roadmap.md`](docs/roadmap.md) | Feature / archive roadmap |
| [`docs/todo/pipeline.md`](docs/todo/pipeline.md) | Delivery pipeline |
| [`docs/todo/_archive/phaseM-migration.md`](docs/todo/_archive/phaseM-migration.md) | Migrate DoD (Done) |
| [`docs/todo/_archive/phaseA-aggregation.md`](docs/todo/_archive/phaseA-aggregation.md) | Aggregation + A3 UI (Done — move offline when convenient) |
