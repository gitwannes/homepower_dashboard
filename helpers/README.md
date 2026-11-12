# --- file: helpers/README.md ---
# Helpers (local only — not in git)

Sync tooling stays on the PC under `helpers/` (gitignored). It is **not** deployed to Synology.

## Local files

| File | Role |
|---|---|
| `p1-sync.ps1` | Robocopy MIR — reads `p1-sync.config.json` |
| `p1-sync.bat` | Windows launcher |
| `p1-sync.config.json` | Source + per-target path / excludes / optional `source_subdir` |
| `README.md` | This file |

## Usage

```text
.\p1-sync.bat test be90 verbose
.\p1-sync.bat test onedrive verbose
.\p1-sync.bat run be90 sofie
```

## Per-target behaviour (config)

| Target | What gets mirrored |
|---|---|
| Synology (`be90` / `sofie`) | App runtime files; **`helpers/` excluded** |
| `onedrive` | **`helpers/` only** (`source_subdir`) — sync-tool backup, not the PHP/Python app |

Shared excludes: `.env*`, DBs, `.gitignore`, `LICENSE`, `*.md`, `docs/`, etc.

## Warning

`/MIR` purges extras on the destination. For `onedrive` (helpers-only source), old app files already under that folder may show as `*EXTRA` and be deleted on a live `run`. Preview with `test` first.
