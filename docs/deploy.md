# --- file: docs/deploy.md ---
# Deploy notes

**Status:** as-is for Synology-first host.  
**Last updated:** 2026-10-28 (P1 Sunny Portal pull note)

## Runtime `.env`

1. Keep the real `.env` in **encrypted storage**.
2. Place a copy next to the app on the host (same folder as `logger.py` / `index.php`).
3. **Never** commit `.env`. **Never** include `.env*` in robocopy MIR.

The file holds **`DB_BACKUP_ENCRYPTION_KEY` only**. Device addresses, device passwords, titles, plant limits, and Sunny Portal credentials are on the **Settings** page (`settings.php`) and stored in `meter_history.db`. Nothing else is read from `.env`.

The first browser open asks for an admin password (enter it twice) when none is stored. A new install with no imported P1 address then shows Settings and stays there until that page is saved.

Use [`.env.example`](../.env.example) as the key list.

## Local sync (`helpers/` — not in git)

On the operator PC, keep under `helpers/` (gitignored):

| File | Role |
|---|---|
| `p1-sync.ps1` / `p1-sync.bat` | Robocopy MIR PC → NAS |
| `p1-sync.config.json` | Source path + named SMB targets (UNC / probe IP) |
| `README.md` | Local notes |

Per-target rules (local `p1-sync.config.json`):

| Target type | Behaviour |
|---|---|
| Synology | Mirror **app** files; **`helpers/` not copied** (`exclude_dirs`) |
| OneDrive (optional) | Mirror **`helpers/` only** (`source_subdir`) — backup of sync tool, not the app |

Shared excludes: `.env*`, SQLite DBs, import working dirs, `.gitignore`, `LICENSE`, `*.md`, `docs/`.

Example config shape (placeholders only — put real values only in the local JSON):

```json
{
  "source": "C:\\path\\to\\homepower_dashboard",
  "targets": {
    "nas_a": {
      "path": "\\\\0.0.0.0\\web",
      "ip": "0.0.0.0",
      "exclude_dirs": [ "helpers" ]
    },
    "onedrive": {
      "path": "C:\\path\\to\\onedrive\\p1",
      "ip": "127.0.0.1",
      "source_subdir": "helpers"
    }
  }
}
```

## Synology

- Web Station: PHP with `curl`, `pdo_sqlite`, and `sqlite3`. `exec()` must be allowed. The dashboard poll button already uses it. The PHP `zip` extension is not used for the Settings backup.
- Settings backup and restore run `db_backup.py` (Package Center Python, same search order as the poll button). That script uses the Python standard library plus OpenSSL `libcrypto`, which DSM already has. No extra pip package. The key is read from `.env` inside Python. It is not a command argument.
- Download and restore call `set_time_limit(0)`. On Linux the wait for Python is outside PHP `max_execution_time`. The web server or PHP-FPM can still close the request. In Web Station, Core `default_socket_timeout` and `hard_timeout` are not that limit. `hard_timeout` is only the extra seconds after `max_execution_time`. The values that can still cut the request are Core `max_execution_time` (if `set_time_limit` is disabled), FPM `request_terminate_timeout`, and the web server read timeout.
- Task Scheduler: every 1 minute `logger.py -poll`; daily at 00:05 `rollup.py -daily`; weekly Sunday `PRAGMA optimize`. `rollup.py -buildall` is a rare full rebuild, not a weekly job. `fluvius_import.py` is manual: drop CSVs in `import_queue/fluvius/` and run it when a file arrives. `sunnyportal_pull.py -pullall` / `-lastdays` is on-demand (Settings credentials → `sunnyportal_pv_5min`); nightly `-daily` empty-fills solar and stitches Fluvius quarters.
- DB file lives beside the app on Synology (`meter_history.db`).

## Pi / log2ram (optional)

If not on Synology, `logger.py` / `index.php` may use `/var/log/p1/` or `/dev/shm/` for the hot DB when those paths are writable. Prefer Synology for this product.
