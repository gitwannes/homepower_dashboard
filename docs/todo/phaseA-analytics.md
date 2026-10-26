# --- file: docs/todo/phaseA-analytics.md ---
# Phase A (continued) — Analytics UI + plant config

**Status:** **A4 Done**; A10 coding; A5–A9 and A11–A12 queued. (A0–A3 Done — [`_archive/phaseA-aggregation.md`](_archive/phaseA-aggregation.md))  
**Last updated:** 2026-10-26 (A11–A12 triaged from P3; no code)  
**Product pointers:** [`docs/architecture.md`](../architecture.md) · `topn.php` · `rollup.py`

---

## Sequence (this file)

| Id | Status | What |
|---|---|---|
| **A4** | Done | Top-N tables (days / months / years) + graphs |
| **A5** | open | Multi-series power graph (PV + grid import + home usage) — ref image |
| **A6** | implemented | Settings page in code; operator smoke not done; `DB_BACKUP_ENCRYPTION_KEY` stays in `.env` |
| **A7** | open | `top_records`: drop or change (assess at kickoff) |
| **A8** | open | Live Solar & Consumption chart: scaled X-axis, gaps, zoom to 1 week, title |
| **A9** | open | Full DB download + restore on the A6 page (admin password); `p1-sync` pulls the DB |
| **A10** | coding | Warehouse rebuild: drop `-aggregate5min` and `-today`; `-buildall` refills `history_5min` then aggregates. Locked, not closed |
| **A11** | open | Historical gas, wind, and similar series on the pages. Not P3. Not kicked off |
| **A12** | open | Historical monthly Fluvius peak on a page. Not P3. Not kicked off |

**Parallel:** A5, A7, A8, A10 independent of **P1–P3**. **A9** depends on **A6** (control sits on that page). A8 is not part of **A5**. A10 has no code dependency on A5–A9. **A11** and **A12** wait on P3 only for the Fluvius rows they will read. Wind for A11 is already in the warehouse.

**Related:** A3 removed Hall of Fame UI. A4 is new Top-N. A7 decides fate of leftover `top_records` singles table.

---

## Verbatim inbox

### 2026-10-23 — triage (three items)

> also triage:
> - create table for top n days, months years & create graphs for it
> - add , next to PV power generation, power pull from net and net power usage (more or less the graph like HomeWizard-graph.jpg - image is saved in \docs\todo in repo)
> - add to .env (or maybe split env and config, to assess at kickoff): max converter power (and remove spikes based on this), expected power generation for the plant (now hardcoded)

### 2026-10-23 — A4 kickoff answers + top_records triage

> 1: top 20 day, top 10 mointh & year
> 2: ??
> 3: also worst days/months/years - that what you mean?
> 4: new page
> 5: generated now with -buildall, and also with -daily
> 6: good idea: clicking on a bar will show that day/month/year in the other page
> 7: what does top_records do?

> 2A
> 7B + add to triage to either drop or change

### 2026-10-25 — fold into A6

> A6: fold into this: configuration page to configure all this, including everything that is in .env / give options, suggestions, base on industry standard

### 2026-10-25 — A6 answers (configure all)

> when this is deplyed on a new machine, the user does not have access to .env - how does a new user configure passwords?
> I want the user to be able to confug ALL
> 3: for now, store per month expected generation
> 4: spike = max inverter AC 105M
> 5 also on the pase

### 2026-10-25 — A6 admin password

> 1: admin password - if no admin yet (current .env): ask at first page open
> 2: don't show passwords, show * (stars), but allow the pws to be changed, then entering the admin pw is needed

### 2026-10-25 — A6 password edit + save gate

> password fields: are NOT editable unless right-click on them and choose "edit", then a modal appears where the admin pw is asked - then the field becomes editable and can be saved
> 1: Admin password on every settings save: yes
> 2: admin pw first, then config page

### 2026-10-25 — A6 password fields (unset + Edit control)

> 1: if unset: direct edit will be possible (save still requires admin pw)
> 2: yes, no right-click

### 2026-10-25 — A6 empty password + change admin password

> 1: if left empty: do not change pw
> 2: yes: change admin pw opens a modal: current pw, enter new pw twice, then enter

### 2026-10-25 — triage (live chart axis)

> triage: live dashboard: Solar & Consumption Profile (Last 2 Hours)
> 	make sure the X-asis is to scale: display only last 2 hours and if there is no reading, don't display a line there
> 	change the viewport: not just 2 hours, but allow to zoom in/out to max 1 week of 1-minute data
> 	so remove the " (Last 2 Hours)" from the title

### 2026-10-25 — triage (DB backup)

> also triage: download backup of entire DB, same functionality/way of working as the budget download
> ask questions

### 2026-10-25 — A9 answers

> DB_BACKUP_ENCRYPTION_KEY will stay in .env - after A6 this is the only variable that stays in .env (it is not user configurable)
> 1: new letter: A9
> 2: download & restore, no wipe
> 3: everyone?
> 4: yes
> 5: option sits in the admin page: A6 is prerequisite
> 6: in .env, not comitted to GIT
> 7: plain sql in the DB only - synctool already copies fo local PC: confirm

### 2026-10-25 — A9 gate + sync pull

> 1: when download and restore: ask for admin pw
> 2: yes, p1-sync should also pull the db

---

## A4 — Top-N days / months / years + graphs

**Status:** **Done** 2026-10-25  
**Product:** [`docs/architecture.md`](../architecture.md) (`topn.php`, `top_periods`).

### Done summary

Best/worst yield rankings (20 days, 10 months, 10 years) in `top_periods` via `-buildall` and `-daily`; UI `topn.php` with click-through. Worst days exclude yield ≤ 0 and today. `top_records` unchanged → **A7**. `rollup.py -today` was removed in **A10**.

### DoD

- [x] Storage + rollup + page + deep links + nav + product docs
- [x] Operator close (smoke)

### Out of scope (A4)

- Peak-W Top-N; drop/change `top_records` (**A7**); old live Top-10 AJAX

---

## A5 — Multi-series power (PV / grid pull / home usage)

**Status:** open (not kicked off)  
**Scope (placement):** Chart series beside PV: power from grid + net/home usage, in the spirit of [`HomeWizard-graph.jpg`](HomeWizard-graph.jpg).  
**Locks:** Which page (`index` live vs `analytics` intraday vs both), exact series definitions (P1 channels), resolution — kickoff.

---

## A6 — Settings page (all site config)

**Status:** **implemented** 2026-10-25. Operator smoke not done. Not closed.  
**Size:** **L** (settings page, first-open admin password, readers in PHP, `rollup.py`, `logger.py`).  
**Supersedes** the earlier “secrets stay in `.env`” suggestion. A new install must be configurable from the browser. Site config is not edited in `.env`.

### Operator answers (2026-10-25)

- The page configures the current `.env` keys, including `SMA_PASSWORD` and `SUNNYWEBBOX_PASSWORD`, plus plant settings. **Exception (2026-10-25, A9):** `DB_BACKUP_ENCRYPTION_KEY` stays in `.env`. It is the only variable that remains there after A6. It is not on the settings page, not imported into SQLite, and not committed. `.env.example` may name the key; the real value stays on the host.
- Expected generation: **12 monthly kWh values** only. No kWp, azimuth, or tilt field in this phase. Storage stays `expected_monthly` (existing `inclination = 30` key so current readers keep working).
- Spike cap = **max inverter AC × 1.05**. Reading of “105M” as 105%. Operator enters max inverter AC watts; the page shows the computed cap. Replaces hardcoded `MAX_PLAUSIBLE_PAC_W` (12 kW) in `rollup.py` and `analytics.php`.
- Night / shoulder gates are on the same page. Defaults match the code today (`rollup.py` `sql_pac_plausible`, `analytics.php` `sanitize_pac_w`):
  - Deep night: hour `>= 22` or `< 5` (PAC dropped).
  - Shoulder: hour `>= 20` or `< 6`, and only when `solar_radiation <= 5` W/m² (PAC dropped).
  - Radiation floor default `5` W/m² (`PAC_RAD_FLOOR`).
- **Admin password.** If none is stored (today’s host: `.env` present, no admin), the first open of any page asks to create one (enter + confirm) and does not continue until it is set. After it exists, graph pages open without asking again. There is no standing login session.
- **New install (no settings yet):** admin password first, then the configuration page. `.env` on that machine holds `DB_BACKUP_ENCRYPTION_KEY` only. The live dashboard waits until that page has been saved (`P1_IP` required, same as today).
- **Current host (`.env` imported):** admin password first, then the dashboard. The configuration page is available; it is not forced.
- **Every settings save** requires the admin password. Save opens a modal; a wrong password rejects the save. Covers IPs, titles, monthly kWh, max AC, night/shoulder gates, and device passwords.
- **Device passwords** (`SMA_PASSWORD`, `SUNNYWEBBOX_PASSWORD`). The real value is never in the HTML. No right-click menu.
  - **Unset:** the field is directly editable. Save still requires the admin password.
  - **Set:** the field shows stars and is not editable. An **Edit** control beside the field opens a modal that asks for the admin password. If it matches, that field becomes editable and empty (the stored secret is not shown). Stars left untouched keep the stored password. **Edit, leave empty, Save: do not change the password.** The following Save still asks for the admin password once for the whole form.
- **Change admin password** is a separate control on the configuration page. It opens its own modal: current password, new password, new password again, then Enter submits. The two new values must match. A wrong current password rejects the change. This modal does not go through the settings Save gate.

### Store

SQLite in `meter_history.db` (web user and poller can both open it). PHP does not rewrite `.env`. After A6, `.env` on the machine holds `DB_BACKUP_ENCRYPTION_KEY` only. An existing host imports the other `.env` keys once when the settings rows are empty, and leaves that key in `.env`. Admin password is stored as a **one-way hash**. Device passwords are stored **reversibly** so `logger.py` can log in. `helpers/p1-sync.config.json` stays PC-only.

`index.php` today fatals when `.env` is missing. That fatal becomes: no admin yet → create admin password; no device settings yet → configuration page.

Branding applies on the next page load. Plant limits and monthly kWh apply when the operator runs `rollup.py -buildall` (no rebuild button). The page says so.

### Locked 2026-10-25 (implement)

1. Spike cap = max inverter AC × **1.05**. Confirmed.
2. This host: `.env` already has the site, so after the admin password is created the **normal dashboard** opens. The configuration page is not forced.
3. Import the existing `.env` into SQLite **once** when `app_settings` is empty. Confirmed. Leave `DB_BACKUP_ENCRYPTION_KEY` in `.env`.
4. SQLite `meter_history.db` is the site-config store. PHP does not rewrite `.env`. Confirmed.
5. Rebuild path is `rollup.py -buildall`. Confirmed. No button that runs it.
6. First admin password is entered **twice**. Confirmed.
7. The page edits 12 monthly **kWh** values only. `expected_irr` stays at the seed. Confirmed.

`importer.py` must not replace `expected_monthly` on every import. Seed a month only when that month is missing.

### Verbatim — open items closed

> 1: yes
> 2: all info is in .env so show normal dashboard: confirm
> 3: yes
> 4: yes
> 5: rebuild is done with rollup -buildall   - confirm
> 6: twice
> 7: ok

---

## A7 — `top_records`: drop or change

**Status:** open (not kicked off)  
**Scope (placement):** Decide whether to **drop** `top_records` (+ stop `build_top_records` in rollup) or **change** it (new purpose / UI).  
**Context:** Single-row extremes (best day, peak surge, wind, temps). Unused in UI since A3. A4 covers Top-N yield lists instead.  
**Locks:** drop vs redesign — kickoff.

### Verbatim

> 7B + add to triage to either drop or change

---

## A8 — Live chart time axis + zoom

**Status:** open (not kicked off)  
**Size:** **M**  
**Scope (placement):** Live dashboard chart on `index.php`. Today the solar title is `Solar & Consumption Profile (Last 2 Hours)` (no-solar fallback: `Power History (Last 2 Hours)`).

Operator request, not locked:

- X-axis to scale. Default view is the last 2 hours. A missing reading is a gap: no line drawn across it.
- Viewport is not fixed at 2 hours. Zoom in and out, up to **1 week of 1-minute data**.
- Remove ` (Last 2 Hours)` from the title.

**Not A5.** A5 adds series (grid import / home usage). A8 changes the time axis of the chart that already exists.

**Locks (kickoff):** zoom control, what “to scale” means when the window is not full, whether the no-solar title drops the same suffix, where the 1-minute series is read from.

---

## A9 — Full DB download + restore

**Status:** open (not kicked off). **Depends on A6.**  
**Size:** **L**  
**Where:** controls on the A6 admin page. Id **A9** on letter **A** (same detail file).

### Locked (2026-10-25)

- Download and restore. No wipe.
- Dump is every table in `meter_history.db`, including future settings rows and reversible device passwords.
- Same working shape as budget Home **Volledige back-up downloaden** / **Volledig herstellen**: browser file is the encrypted backup; restore replaces the live database from an upload. Budget’s admin wipe is out.
- `DB_BACKUP_ENCRYPTION_KEY` lives only in `.env` on the host. Not user-configurable. Not in git. `p1-sync` already excludes `.env*`.
- **Download and restore each ask for the admin password.** A wrong password rejects the action. There is no standing session (same gate as an A6 settings save).
- **No extra plain SQL files** on the host. The live `meter_history.db` is the only plain copy. Budget’s pre-restore `budget-backup-*.sql` is not copied.
- **`p1-sync` pulls the live DB** from the Synology app directory to the PC source tree (`config.source` / `meter_history.db`, already gitignored). The OneDrive target stays `helpers/` only and does not receive the DB.
- The existing PC → NAS `/MIR` **keeps excluding** `meter_history.db*` (and the `-wal` / `-shm` sidecars). That job stays a push. Including the DB in the mirror would copy PC → NAS and can delete or overwrite the live file.

### Verified (sync tool today)

`helpers/p1-sync.ps1` mirrors **PC → target** (`robocopy /MIR`). Shared excludes include `meter_history.db*` and `.env*`. The pull above is new work in this phase. It does not exist in the script yet.

### Still open

None for placement. Kickoff still has to say how the pull takes a consistent snapshot while `logger.py` is writing.

---

## A10 — Warehouse rebuild

**Status:** coding (locked 2026-10-26, not closed)  
**Size:** **M**  
**Depends on:** none. Sequenced after A9. No code dependency.

### Locked scope

WebBox archives stay a one-time ingest. `importer.py` writes `sensorbox_history_5min` and `inverter_history_5min` from loose `Mean` XML and from daily `wb<serial>.<YYYYMMDD-HHMMSS>.zip|.7z`. `importer.py -outerzip` is only the extra pass for a master container that is not a daily archive. Both move consumed files to `import_queue/processed/`.

`rollup.py -buildall` becomes the full derived rebuild:

1. Delete `history_5min`, `daily_history`, `monthly_history`. No `VACUUM`. `readings` and both staging tables stay. `clear_sky_envelopes`, `top_records`, and `top_periods` already delete their own rows later in the run.
2. Every row in `readings` → `history_5min`, using the existing downsample. Skip the open 5-minute slice (the bucket that contains now, local time).
3. `consolidate_historical_webbox`: staging → solar and weather columns on `history_5min`, `data_source` `webbox_archive`.
4. `history_5min` → `daily_history`, all days.
5. `daily_history` → `monthly_history`, all months.
6. `history_5min` → `clear_sky_envelopes`.
7. `history_5min` and `daily_history` → `top_records`.
8. `daily_history` and `monthly_history` → `top_periods`.

`-purge_derived` stays a separate operator wipe. It still deletes the same six derived tables and runs `VACUUM`.

Also in this phase:

- Remove `importer.py -aggregate5min` (flag, direct insert into `history_5min`, branch).
- Remove the `rollup.py -today` branch and its usage line. `downsample_live_readings` stays.
- `build_daily_history` day window uses `datetime('now', 'localtime', …)`. `-daily` keeps last 2 local days of `readings` and last 3 local days of `daily_history`, then a full `monthly_history` and `top_periods`.
- **Generate table** stays today-only into `history_5min`. Update the comment that calls it the same path as `-today`. Keep that PHP SQL in step with `downsample_live_readings`.
- Update `docs/architecture.md`, `README.md`, `docs/roadmap.md`, and `.cursor/rules/history-5min-derived.mdc` to this command set.

### Verified

- `-aggregate5min` reads the same archives as plain import and writes solar and weather on `history_5min` with `data_source` `webbox`. `-consolidate` reads staging and writes `webbox_archive`. The live downsample only preserves an existing solar value when the source is `webbox_archive`.
- Nothing calls `rollup.py -today`. **Generate table** does not write `daily_history` or `top_periods`.
- `-buildall` today does not read `readings` or staging. It starts at `daily_history`.
- `-daily`’s 3-day filter is `datetime('now', '-3 days')` (UTC). The 2-day readings filter already uses local time.
- `sql_importer.py` is not in the repo. A0 records that the gap-fill into staging already ran.
- No year table. Years are summed from `monthly_history`.

### Out of this phase

- Fluvius CSV / portal (**P3**) and SMA / ennexOS (**P1**, **P2**).
- Restoring `sql_importer.py`.
- Running `top_records` from `-daily` (**A7**).
- A second historical import on every rebuild. `processed/` is not scanned again.

### Locked 2026-10-26

1. Both staging tables hold every WebBox year. `history_5min` has no WebBox rows those tables lack. Dropping `-aggregate5min` does not drop a span.
2. `-buildall` is rare (ideally never). An empty day chart while that one command runs is acceptable.

### Still open

None for the lock. Live NAS smoke is not done. Do not mark Done from this branch.

### Verbatim — 2026-10-26 (lock + implement)

> 1: yes, all webbox data is in those 2 staging tables, the history_5min table does not contain data that is not in those 2 tables
> 2: very good - rebuild will not be done regularly - ideally, never
> if all ok: implement and push to seperate branch

### Verbatim — 2026-10-26 (placement)

> 1/ importer: gets data from Mean archives from import_queue and writes sensorbox_history_5min, inverter_history_5min
> importer.py -aggregate5min: gets data from those same Mean archives from import_queue and puts them into solar and weather columns in history_5min
> rollup.py -consolidate: gets data from sensorbox_history_5min, inverter_history_5min and puts that info solar and weather columns in history_5min
> so that seems a step in-between, so dumping importer.py -aggregate5min seems ok
> is this reasoning sound? if yes: put this in the todo list

### Verbatim — 2026-10-26 (fold + kickoff)

> fold all in A10, then kickoff

---

## A11 — Historical gas, wind, and similar

**Status:** open (triaged 2026-10-26, not kicked off)  
**Size:** **M**  
**Depends on:** P3 for gas rows in `fluvius_interval`. Wind is already on `history_5min` / `daily_history`.

### Placement

Not part of P3. P3 stores gas for the configured gas EAN and does not put it on a page. This phase is the first UI for that gas, and for warehouse series the pages still do not show (wind, and whatever the kickoff adds to “etc”). No code until kickoff locks the page and the series list.

### Verbatim — 2026-10-26

> Q3: hitorical gas, wind speed, etc is not for this phase - tirage that into another phase

---

## A12 — Historical monthly Fluvius peak

**Status:** open (triaged 2026-10-26, not kicked off)  
**Size:** **S**  
**Depends on:** P3 stores `piekvermogen` in `fluvius_interval`. The live `capacity_peak_w` register on `index.php` stays as it is.

### Placement

Not part of P3. P3 does not write the monthly peak into `capacity_peak_w` and does not add it to the live card. This phase decides where the monthly kW series is shown. No code until kickoff.

### Verbatim — 2026-10-26

> Q4: also another phase: triage

---

## Out of scope (until re-triaged)

- Portal ingest (**P1–P3**)
- Restoring the old slow Top 10 AJAX query as-is
