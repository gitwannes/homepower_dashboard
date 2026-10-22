# --- file: docs/todo/phaseM-migration.md ---
# Phase M — Migrate P1 dashboard from WLW

**Status:** Spec **LOCKED** (2026-10-22). **Implement: HOLD** until the operator says `implement`.  
**Last updated:** 2026-10-22

## Source (verified)

| Item | Path |
|---|---|
| App tree | WLW repo path `public/p1/` |
| Typical tracked app files | `.env.example`, `logger.py`, PHP UI, importers, rollup/sql helpers, powertests, product README/roadmap |
| Never publish | `.env`, `meter_history.db*`, local archives under `_archive/` |

## Verbatim inbox (scrubbed for public git)

Operator request (2026-10-22): extract the P1 / SMA / WebBox dashboard from WLW into the public `homepower_dashboard` repo (runtime is Synology, not WLW). Assess; scaffold README / `.gitignore` / docs / license; audit for site-specific data; create `docs/`, `helpers/`, `docs/todo`.

Follow-ups (same day): sync tool + entire `helpers/` local-only / gitignored; sync must mirror `helpers/` (scripts, config, local README); device/UI site facts in `.env`; sync target IPs in `p1-sync.config.json` only; never sync `.env` (encrypted storage).

---

## Locked decisions (2026-10-22)

### License

- **MIT** (public repo). Root `LICENSE`.

### What moves / what stays

| Item | Decision |
|---|---|
| WLW `public/p1` app (PHP/Python) | Migrate into **this** repo (M1) |
| WanOS HomeWizard energy bridge | **Out of scope** — stays in WanOS |
| Entire `helpers/` (sync scripts, config, local README) | **Local only** — gitignored; mirrored to NAS by sync |
| `.env` | **Encrypted storage** — never committed; **never** mirrored by sync |

### Config split (SoT)

| Concern | File | In public git? | Robocopy → NAS? |
|---|---|---|---|
| Device endpoints + secrets | `.env` | no (`.env.example` only) | **no** |
| UI branding (title, subtitle, site labels) | `.env` | no | **no** |
| Sync topology (source path, target names, SMB UNC, probe IPs) | `helpers/p1-sync.config.json` | no | **yes** |
| Sync scripts + `helpers/README.md` | `helpers/` | no | **yes** (whole directory) |
| App code | repo root (flat) | yes | yes |

Operator places runtime `.env` on the host from **encrypted storage** (not via `p1-sync`).

### Sync behaviour (locked)

- Whole `helpers/` is gitignored and must be included in MIR to the NAS.
- MIR **excludes** `.env*` always.
- No site names or LAN IPs in any **committed** file; local JSON holds operator-chosen target keys and UNC/IPs.
- Source path in local config points at this repo (not the old WLW `public/p1` path).

### Public-repo hygiene (before first app commit)

| Kind | Required |
|---|---|
| Docs / README / comments | No real LAN IPs, no household or site host nicknames |
| UI strings | From `.env`; code default = generic (`Home Energy Hub`) |
| `helpers/` | Not in git |
| Archives / DB | Never commit |

### Layout

- Flat app root (same shape as today’s WLW `public/p1` app files), plus `docs/`, `docs/todo/`.
- `helpers/` exists on disk for the operator only (gitignored).

---

## M1 — Sanitize + migrate 🔜 HOLD (implement)

**Status:** open / **hold implement**  
**Depends on:** locks above (done).

### DoD

- [ ] App files copied into this repo; scrubbed of site IPs / site names in tracked sources
- [ ] `.env.example` with device + presentation placeholders only (no sync targets)
- [ ] `.gitignore` ignores entire `helpers/`, `.env`, `*.db`, archives
- [ ] Local sync reads `p1-sync.config.json`; MIR includes all of `helpers/`; excludes `.env*`
- [ ] UI branding from `.env` with boring default
- [ ] Product README + `docs/architecture.md` match shipped layout; **no site-specific data in any committed file**
- [ ] **Last DoD:** audit all `docs/**/*.md` + root README against shipped behaviour

## M2 — Retire from WLW

**Status:** open (after M1)

- [ ] Remove or stub WLW `public/p1`
- [ ] Pointer in WLW docs/README if anything still links that path

## M3 — (folded)

Local sync is **locked into M1**. No committed sync tool.

## Out of scope

- WanOS HomeWizard energy bridge
- Committing `meter_history.db`, import archives, or real `.env`
- Publishing anything under `helpers/` in git
