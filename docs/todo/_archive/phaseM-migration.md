# --- file: docs/todo/phaseM-migration.md ---
# Phase M — Migrate P1 dashboard from WLW

**Status:** **Done** (M1 + M2) 2026-10-22 — archived.  
**Last updated:** 2026-10-22

## Source (historical)

Former WLW path: `public/p1/`. App now lives in this repository.

## Verbatim inbox (scrubbed for public git)

Operator request (2026-10-22): extract the P1 / SMA / WebBox dashboard from WLW into the public `homepower_dashboard` repo (runtime is Synology, not WLW). Assess; scaffold; audit; migrate; sync tool local-only; `.env` for devices/UI; sync targets in `p1-sync.config.json`; never sync `.env`.

---

## Locked decisions (2026-10-22) — shipped

See [`docs/architecture.md`](../architecture.md) and [`docs/deploy.md`](../deploy.md).

---

## M1 — Sanitize + migrate ✅ Done 2026-10-22

### DoD

- [x] App files in this repo; scrubbed of site IPs / site names in tracked sources
- [x] `.env.example` with device + `SITE_*` placeholders only (no sync targets)
- [x] `.gitignore` ignores entire `helpers/`, `.env`, `*.db`, archives
- [x] Local sync reads `p1-sync.config.json`; MIR includes all of `helpers/`; excludes `.env*`
- [x] UI branding from `.env` with boring default
- [x] Product docs updated; no site-specific data in committed files
- [x] **Last DoD:** audit docs against shipped layout

## M2 — Retire from WLW ✅ Done 2026-10-22

- [x] WLW `public/p1` app removed; stub `README.md` points at this repo
- [x] WLW root README + `docs/architecture.md` + pipeline Done row updated

## Out of scope

- WanOS HomeWizard energy bridge
- Committing `meter_history.db`, import archives, or real `.env`
- Publishing anything under `helpers/` in git
