# --- file: docs/deploy.md ---
# Deploy notes

**Status:** as-is for Synology-first host.  
**Last updated:** 2026-10-22

## Runtime `.env`

1. Keep the real `.env` in **encrypted storage**.
2. Place a copy next to the app on the host (same folder as `logger.py` / `index.php`).
3. **Never** commit `.env`. **Never** include `.env*` in robocopy MIR.

Use [`.env.example`](../.env.example) as the key list (`P1_IP`, optional SMA/WebBox, optional `SITE_*`).

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

- Web Station: PHP with `curl`, `pdo_sqlite`, `sqlite3`.
- Task Scheduler: run `logger.py` on the poll interval; optional `rollup.py` daily/weekly as needed.
- DB file lives beside the app on Synology (`meter_history.db`).

## Pi / log2ram (optional)

If not on Synology, `logger.py` / `index.php` may use `/var/log/p1/` or `/dev/shm/` for the hot DB when those paths are writable. Prefer Synology for this product.
