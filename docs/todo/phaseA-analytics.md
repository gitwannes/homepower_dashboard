# --- file: docs/todo/phaseA-analytics.md ---
# Phase A (continued) — Analytics UI + plant config

**Status:** **A4**-**A13**, **A16**, **A17**, **A18**, **A19 Done**; **A20** kicked off; **A11**, **A14**, **A15** queued. (A0-A3 Done - [`_archive/phaseA-aggregation.md`](_archive/phaseA-aggregation.md))  
**Last updated:** 2026-10-29 (**A18** closed)  
**Product pointers:** [`docs/architecture.md`](../architecture.md) - `topn.php` - `rollup.py` - `settings.php` - `explorer_chart.php` - `balance_chart.php` - `logger.py`

---

## Sequence (this file)

| Id | Status | What |
|---|---|---|
| **A4** | Done | Top-N tables (days / months / years) + graphs |
| **A5** | Done | Balance/Classic multi-series power (both pages). Product: [`docs/architecture.md`](../architecture.md) |
| **A5b** | Done | Explorer + index pulse + Exchange default. Product: [`docs/architecture.md`](../architecture.md) |
| **A6** | Done | Settings page (`settings.php`). Product: [`docs/architecture.md`](../architecture.md) |
| **A7** | Done | `top_records` on `topn.php`. Product: [`docs/architecture.md`](../architecture.md) |
| **A8** | Done | Live chart clock window. Product: [`docs/architecture.md`](../architecture.md) |
| **A9** | Done | Settings encrypted backup download + restore. Product: [`docs/architecture.md`](../architecture.md) |
| **A10** | Done | Warehouse rebuild. Product: [`docs/architecture.md`](../architecture.md) (Rollup CLI) |
| **A11** | open | Historical gas, wind, and similar series on the pages. Not P3. Not kicked off |
| **A12** | Done | Capacity peak on summary + top-n. Product: [`docs/architecture.md`](../architecture.md) |
| **A13** | Done | Grid import/export on summary and top-n. Product: [`docs/architecture.md`](../architecture.md) |
| **A14** | open | Virtual kWh meters from Settings (bias to import / export / PV) |
| **A15** | open | Settings save: probe every filled integration; fail -> modal, stay on page. Kicked off |
| **A16** | Done | Per-phase W on `readings` + logger. Product: [`docs/architecture.md`](../architecture.md) |
| **A17** | Done | Rollup: per-phase W into `history_5min`. Product: [`docs/architecture.md`](../architecture.md) |
| **A18** | Done | UI: Phases view + Sum mountain; CSV phase cols. Product: [`docs/architecture.md`](../architecture.md) |
| **A19** | Done | Analytics explorer: calendar-day **Today** preset (default). Product: [`docs/architecture.md`](../architecture.md) |
| **A20** | open | Settings locked-by-default / Unlock then Save-if-dirty. Kicked off |

**Parallel:** **A17**/**A18**/**A19** closed. **A15** / **A20** kicked off (both Settings Save). **A11** Fluvius gas / wind. **A14** Settings-adjacent.

**Related:** A3 removed Hall of Fame UI. A4 is yield Top-N. A7 shipped all-time `top_records` on `topn.php`.

---

## Verbatim inbox

### 2026-10-29 - close A17 + kickoff A18

> clode A17 & kickoff A18

A17 closed. A18 kickoff locked 2026-10-29 (operator: all ok). Waiting `implement`.

### 2026-10-29 - triage A20 (Settings unlock UX, assess)

> settings page: all fields are disabled (including DB backup but NOT CSV download)- button "save settings" is not there, it reads "unlock settings" - which will ask for pw in modal - if correct: all fields ware enabled, then the button becomes save IF something is changed
> assess

Placed: **A20** (new; not reopen **A6**). Affinity **A**. Kicked off 2026-10-29; open Qs in the A20 section.

### 2026-10-29 - triage + kickoff A19 (Today explorer preset)

> analytics page: grid exchange: add view: "today" which is default, which will show 00:00-23:59 today - assess

> 1: fixed midnight, so "today" always shows 24hrs - arrows left-right then move day forward/back (if possible)
> 2: all 3
> 3: yes
> 4: yes, cfr 1

> 1: if today: then right-arrow will be disabled (same for very first day in DB for left arrow)
> 2: DST days: spring ~23 h / autumn ~25 h

> triage (give it a phase) & kickoff (check for open items)

> 1: also readins, whatever is first in dB
> 2: next to 24h but default
> 3: ok
> 4: yes, same

Placed: **A19**. Locks complete in the A19 section. No open Qs.

### 2026-10-29 - A16 kickoff answers + triage A17/A18

> Q1: why not split them between the 3? give advice
> Q2: could happen: if eg net im/export = 0 and P1 = 100W and P2 = -100W --- is this the edge case? give advice
> Q3: make sure you only do this is effectively there ARE 3 phases (not all P1 meters have that) - if not: just skip this for that plant
> Q4: #1
> & triage the rollup and UI changes

Placed follow-ons: **A17** rollup, **A18** UI. Operator confirmed Q1/Q2 2026-10-29 - A16 kickoff locked.

### 2026-10-29 - triage + kickoff (A16 per-phase W)

> change database to, from now on, calculate power per phase, keep per minute, make sure the sum of the 3 phases matches the readout from im/export - don't change the UI yet, first i want to get some readings

> 1 triage into new phase and kickoff
> 2A
> 3 yes: add columns and change poller to calculate these and store them - nothing rollup or UI for now

Placed: **A16**. Kickoff locks below. Open Qs in the A16 section.

### 2026-10-28 - triage (A14 / A15 + Ops / pending letters)

> triage:
> [open]  WanOS: sensors + MQTT listener; this app pushes on
>           rollup.py -daily
>           and when raw readings are opened
>           (Generate today removed - nightly -daily owns downsample)
>
> [open]  notifier.py (roadmap Phase 3)
>
> [open]  virtual kWh meters from Settings (bias to import / export / PV)
>
> [open]  meter_history.db -> Synology Hyper Backup (roadmap Phase 4)
>
> [open]  Settings save: probe every filled integration; fail -> modal, stay on page
>
> Task Scheduler: daily 00:05 rollup.py -daily = done: update docs

Placed: **A14** virtual kWh meters; **A15** Settings save probe. **Ops5** Hyper Backup; **Ops6** Done; **Ops7** `notifier.py`. **W1** WanOS push ? [`phaseW-wanos.md`](phaseW-wanos.md). Operator confirmed 2026-10-28 (W1 / Ops / rest ok).

### 2026-10-23 - triage (three items)

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

### 2026-10-27 — A8 / A9 kickoff answers

Questions were: (1) zoom (2) X-axis when the window is not full (3) gap rule (4) titles (5) browser file (6) restore swap (7) when the synctool pulls.

> use sqlite backup, which will have a timestamp in the name, sync that to the PC with the synctool, delete the bak from synology

> 1B
> 2A
> 3B
> 4A
> 5A
> 6A + ask very specifically whether the user is sure to restore and that eveyrthing currently iin the DB will be destroyed
> 7A

### 2026-10-27 — A9 filename + who creates it

> 1: timestamped copy - all copies remain (pull only)
> 2: backup is created by PHP, then sync copies all .bak (filename meter_history.db.<timestamp>.bak eg meter_history.db.261027-083600.bak

### 2026-10-27 — A9 download is the encrypted zip

> well, as with budget, that bak must be zipped and encrypted with the DB_BACKUP_ENCRYPTION_KEY in .env - that zipped and encrypted file must be downloaded, not the actual backup - the bacup is deleted, ony the zipped/encrypted file is downloaded

### 2026-10-27 — A9 no synctool pull

> well, actually, the synctool will never pull that backup
> since: button click -> sql backup -> zip -> encrypt -> download -> bak & encrypted files on source are deleted -> nothing to sync by tool
> confirm

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

**Status:** **Done** 2026-10-27  
**Product:** [`docs/architecture.md`](../architecture.md) (live + analytics Balance/Classic)  
**Branch:** `feature/a5-balance-chart` (merge/commit still operator-owned)

### Done summary

Balance vs Classic on `index.php` and `analytics.php` (default Balance). Three painted W bands: Self-sufficient, Grid, Surplus. Production and Consumption are stack-outline composites (legend split dots + tooltip). Fluvius quarter kWh → average W on analytics when P1 grid is missing; ≤2 missing 5-min slots bridged. Overlay and envelope stay solar-only. No vendor product name in UI. Windowed explorer / index pulse / mixed 1-min+5-min → **A5b**.

### DoD

- [x] Balance formulas, paint rule, mode switch, Fluvius derive on analytics day
- [x] Product docs (`architecture.md`)
- [x] Operator close → A5b

### Locks (operator 2026-10-27) — shipped

| # | Lock |
|---|---|
| 1 | Both pages (superseded for layout by **A5b**; formulas stay). |
| 2–10 | Chart model, W, Fluvius derive, decomposition, overlay/envelope solar-only, mode switch, default Balance, five series with three painted, no vendor name. |

**Formulas** (unchanged; A5b reuses):

- `grid_net_w` = P1 signed net (`active_power` / `grid_power_w`; import positive, export negative). From Fluvius-only slots: `import_w - export_w` (`kWh * 4000`).
- **Production** = `max(0, solar_w)`
- **Consumption** = `max(0, solar_w + grid_net_w)`
- **Self-sufficient** = `min(production, consumption)`
- **Grid** = `max(0, consumption - production)`
- **Surplus** = `max(0, production - consumption)`

### Verbatim — 2026-10-27 (kickoff)

> 1: both
> 2: HW-style
> 3: all W
> 4: yes, derive
> 5: like HW
> 6: elaborate
> 7: give options

> 6: compare (that is the "Two-Day Overlay"?) stays as it is now, the graph we are creating is new - so 6A
> 7D

> 1 mode switch
> 2 default = HW
> 3 keep all
> no "HomeWizard" title should be anywhere, we are using the graphs as reference, not the name

---

## A5b — Balance explorer (window, pan, mixed resolution)

**Status:** **Done** 2026-10-27  
**Product:** [`docs/architecture.md`](../architecture.md) (UI map: index / analytics / Settings CSV) - `explorer_chart.php` - `balance_chart.php` - `live_chart.php`  
**Depends on:** **A5** Done

### Done summary

Analytics owns the power explorer: rolling presets 15 m-7 d, **Reset view**, pan, deep links (`range`, `from`/`to`, `view`). Index is a fixed **2 h** live pulse with Analytics link. Modes on both pages: **Exchange** (default), **Balance**, **Classic**. Mixed resolution rule **V**. Telemetry table removed from index; **Download 1-min CSV** on Settings. **A19** later made calendar-day **Today** the explorer default (see architecture).

### DoD

- [x] Index 2 h pulse + Analytics link; no telemetry table
- [x] Analytics window presets, pan, Reset view, deep links
- [x] Mixed 1-min / 5-min rule V on explorer
- [x] Exchange / Balance / Classic on both pages; Exchange default
- [x] Settings 1-min CSV download
- [x] Product docs (`architecture.md`, README, roadmap)
- [x] Operator close

### Locks (operator 2026-10-27) — shipped

| # | Lock |
|---|---|
| 1 | **Index:** 2 h live pulse (**Exchange** default; Balance / Classic via switch). Analytics link matches top-nav pattern. |
| 2 | **Analytics:** presets 15 m–7 d; default **6 h** ending now; **Reset view**; Balance split-dot legend. |
| 3 | **Pan:** one full window step. |
| 4 / 4a | Mixed resolution per bucket; **V — visible only**. |
| 5 | Classic and Exchange share windows / pan / deep links. |
| 6 | Deep link `range`, `from`/`to`; `view=balance|classic` (Exchange default omits `view`). |
| 7 | Telemetry table off index; 1-min CSV on Settings beside backup. |
| 8 | Not in scope: dropping `history_5min` / `readings`; overlay/envelope changes. |

### Verbatim — 2026-10-27

> why wouldn't we move the 1min energy balance table to the analytics page and remove that 5min table? and add filters and date selectors and defaults : 15 min, 1 h, 2 h, 6 h, 24 h, 7 d - and add arrows left/right to pan the view
> assess and propose

> prefer auto: use 1 min if there are any and otherwise 5min
> B: short (2hrs) live pulse on index with button to go to analytics (same button as on top of page)
> pan: take current window and move back/forward 1 "step"

> use 1min if at least 2 minute-readings in the 5minute are visible, otherwise use the 5min reading
> so can be mixed -- is this a good idea?
> placement: A5b - close A5, A5b starts now

> 1: elaborate on those 2 choices
> 2: same windows / left-right etc
> 3: yes
> also in A5b:
> "download CSV" move to config page, next to backup db, and add info: "download all 1min samples in csv"
> remove detailed telemetry log data table

> V

---

## A6 — Settings page

**Status:** **Done** 2026-10-26
**Product:** [`docs/architecture.md`](../architecture.md) (Settings, config split) · [`docs/deploy.md`](../deploy.md) (runtime `.env`)

### Done summary

- The page configures the current `.env` keys, including `SMA_PASSWORD`, `SUNNYWEBBOX_PASSWORD`, and the Sunny Portal user, password, plant id, and timezone, plus plant settings. **Exception (2026-10-25, A9):** `DB_BACKUP_ENCRYPTION_KEY` stays in `.env`. It is the only variable that remains there after A6. It is not on the settings page, not imported into SQLite, and not committed. `.env.example` may name the key; the real value stays on the host. Sunny Portal fields are optional on save. `SUNNYPORTAL_*` is copied when that settings key is still absent (a leftover `ENNEXOS_*` name for the same value still counts). A saved empty Sunny Portal row is not overwritten.
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

`settings.php` configures devices, titles, 12 monthly expected kWh, max inverter AC (spike cap × 1.05), and the night/shoulder gates. Values live in `app_settings`. The admin password is created on first open (entered twice) and required on every save. Device passwords stay masked. `DB_BACKUP_ENCRYPTION_KEY` stays in `.env`. An existing `.env` is imported once when `app_settings` is empty. Plant changes apply on `rollup.py -buildall`.


### DoD

SQLite in `meter_history.db` (web user and poller can both open it). PHP does not rewrite `.env`. After A6, `.env` on the machine holds `DB_BACKUP_ENCRYPTION_KEY` only. An existing host imports the other `.env` keys once when the settings rows are empty, and leaves that key in `.env`. Sunny Portal user, password, plant id, and timezone are the same store. They are copied when that key is still absent, including from a leftover `ENNEXOS_*` name. Admin password is stored as a **one-way hash**. Device passwords and the Sunny Portal password are stored **reversibly** so `logger.py` and `sunnyportal_pull.py` can log in. `helpers/p1-sync.config.json` stays PC-only.
- [x] Store, page, poller, rollup, charts, one-time `.env` import
- [x] Product docs
- [x] Operator close


### Out of scope (A6)

- Database download and restore (**A9**)
- kWp, azimuth, tilt, and editing `expected_irr`
- A rebuild button

---

## A7 — `top_records` on Top-N

**Status:** **Done** 2026-10-27  
**Product:** [`docs/architecture.md`](../architecture.md) (`topn.php`, Rollup CLI)

### Done summary

All-time singles live in `top_records` and on `topn.php` (above the rankings). Eight ids: best day kWh, peak surge W, sun-hours, self-consumption, wind, ambient min/max, module max. Wind / ambient / module / self-consumption rows hide when absent. Every dated row opens analytics for that day. `-buildall` and `-buildalltoprecords` full rewrite; `-daily` replaces a row only on a stricter extreme in the Fluvius stitch calendar span.

### DoD

- [x] Schema + `build_top_records` covers the eight ids; conditional insert when source null
- [x] `-daily` beat path; `-buildalltoprecords`; `-buildall` still builds tops
- [x] `topn.php` table + analytics day links; hide empty sensorbox/grid rows
- [x] Product docs (`architecture.md` / roadmap)
- [x] Operator close

### Verbatim — 2026-10-27 (close)

> close A7

### Verbatim — 2026-10-23 (triage origin)

> 7B + add to triage to either drop or change

### Verbatim — 2026-10-27 (kickoff)

> -daily should refresh: check if last 2 days readings are higher than what is in the table already and if it is: replace
> add switch -buildalltoprecords so that we don't have to rebuild all, only this
> keep top, create table with these records + if it is power: link to analytics that day

> 1: fold into topn
> of course, only show top wind/temp if sensorbox/tables have them
> 2: also best_day_kwh
> 3: yes, stricter extreme
> 4: ok
> 5: both: buildall  does all, buildalltoprecords  only toprecords
> 6: list all best x - and propose others if any

> Current five + max_sun_hours + max_module_temp_c + (if available) + best_self_consumption_kwh
> link all to analytics that day

---

## A8 — Live chart time axis + zoom

**Status:** **Done** 2026-10-27  
**Product:** [`docs/architecture.md`](../architecture.md) (live chart on `index.php`).

### Done summary

The live power chart uses a clock window of 1-minute `readings`. Presets are 15 min, 1 h, 2 h, 6 h, 24 h, and 7 d, ending at now. Default is the last 2 hours. Drag zoom keeps that range across the 60-second reload, from 15 minutes through 7 days. A 3-minute step stays drawn. A longer step is a gap. Titles are `Solar & Consumption Profile` and `Power History`. Series are unchanged. This is not A5.

### DoD

- [x] Chart window, presets, drag zoom, gaps, titles
- [x] Product docs
- [x] Operator close

---

## A9 — Full DB download + restore

**Status:** **Done** 2026-10-27  
**Product:** [`docs/architecture.md`](../architecture.md) (Settings backup) · [`docs/deploy.md`](../deploy.md)

### Done summary

Settings downloads and restores an encrypted copy of the whole database. `db_backup.py` does the SQLite online backup, the zip, and AES-256-GCM. PHP checks the admin password and the restore confirm, then sends the file or swaps the database. The browser file is `meter_history.db.<yymmdd-HHMMSS>.backup`. Plain and encrypted files are deleted on the server. `p1-sync` does not pull them. PHP `zip` is not used.

### DoD

- [x] Download and restore
- [x] Product docs
- [x] Operator close

---

## A10 — Warehouse rebuild

**Status:** **Done** 2026-10-26 (NAS smoke)  
**Product:** [`docs/architecture.md`](../architecture.md) (Rollup CLI) · [`docs/roadmap.md`](../roadmap.md) · [`docs/deploy.md`](../deploy.md)

### Done summary

`-buildall` deletes `history_5min`, `daily_history`, and `monthly_history` (no `VACUUM`), refills `history_5min` from all `readings` except the open 5-minute slice, then from WebBox staging, then rebuilds daily, monthly, envelopes, `top_records`, and `top_periods`. Rare. The day chart is empty until it finishes. `-daily` uses local time: last 2 days of `readings`, last 3 days of `daily_history`, then a full `monthly_history` and `top_periods`. `-purge_derived` is unchanged. `importer.py -aggregate5min`, `rollup.py -today`, and analytics Generate today table are removed. Today’s closed buckets go through nightly `-daily`. Import writes staging only. `processed/` is not scanned again. Both staging tables hold every WebBox year. P3 later stitches Fluvius electricity quarters in that same `-buildall`, after WebBox consolidate. See [`docs/architecture.md`](../architecture.md).

### Out of scope

- Fluvius (**P3**) and SMA / ennexOS (**P1**, **P2**).
- Restoring `sql_importer.py`.
- (A7 Done: `top_records` beat on `-daily` + `-buildalltoprecords`.)

### Verbatim — 2026-10-26 (close)

> A10 smoke done: close

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
**Depends on:** P3 (Done) for gas rows in `fluvius_interval`. Wind is already on `history_5min` / `daily_history`.

### Placement

Not part of P3. P3 stores gas for the configured gas EAN and does not put it on a page. This phase is the first UI for that gas, and for warehouse series the pages still do not show (wind, and whatever the kickoff adds to “etc”). No code until kickoff locks the page and the series list.

### Verbatim — 2026-10-26

> Q3: hitorical gas, wind speed, etc is not for this phase - tirage that into another phase

---

## A12 — Historical monthly Fluvius peak (Capacity peak)

**Status:** **Done** 2026-10-29  
**Product:** [`docs/architecture.md`](../architecture.md) (UI map: `summary.php`, `topn.php`) · `capacity_peak.php` · `rollup.py` (`top_records`)

### Done summary

Third metric **Capacity peak** on `summary.php` and `topn.php` (`?metric=peak`). Fluvius `piekvermogen` months (kW); open month replaced by live P1 W→kW. Summary peak mode: year + All years only; All years last in every metric; Max/Average on the All years card with a shared Max Y-axis. Top-N: highest 10 peak months. All-time records filtered by metric (yield / peak; wind hidden until A11; grid hides the card). New singles: `best_year_kwh`, `max_capacity_peak_kw`, `best_year_avg_capacity_peak_kw`. Deep link `?month=YYYY-MM` sets week (ISO week of day 1) + month + year; `?year=YYYY` sets year. Live Capacity Peak gauge on `index.php` unchanged.

### DoD

- [x] Summary + Top-N Capacity peak
- [x] Product docs
- [x] Operator close

### Verbatim — 2026-10-29 (close)

> close A12

### Verbatim — 2026-10-28 (kickoff + implement)

> Q1: both pages / Q2: third top-level metric / Q3: year + all-years only; all-years last in all modes / Q4: kW / Q5: overlay live P1 / Q6: A12 only
> not worst, only highest month, not years; 1 bar per year avg&max; replace with P1 kW; Capacity peak; 10 is ok; implement

### Verbatim — 2026-10-26

> Q4: also another phase: triage

---

## A13 — Grid import / export mode

**Status:** **Done** 2026-10-27
**Product:** [`docs/architecture.md`](../architecture.md) (UI map: `summary.php`, `topn.php`)

### Done summary

Solar yield stays the default on `summary.php` and `topn.php`. Grid mode is grouped bars: import, export, and net. A second switch shows all three, import/export only, or net only. Top-N grid ranks are the two ends of import minus export. The open local day stays out of both day ranks. `analytics.php` is unchanged (**A5**). No shared series with **A12**.

### DoD

- [x] Switches, bars, rankings, links
- [x] Product docs
- [x] Operator close

### Verbatim — 2026-10-27

> ok, extend the analytics, summary and top-n tables with net export & import -- create a selection at the top, default = solar yield, other option = net import/export - the graphs should switch to those (2 lines in 1 graph) -- this is partly overlapping A5: check & give feedback - give proposals on how to structure this, based on industry standard

> triage
> Q1: top-n graphs for grid:
> - highest net import days (how much is pulled/pushed to the grid)
> - highest net export days (how much is pushed to the grid)
> - same for months & years
> is it an idea to create a new PHP file ? compare pros & cons
> Q2: net analytics should only show day graph (selectable), not Two-Day Overlay and clear sky
> re-assess

> net

> 1: same file, use selector
> 2: move this question to A5, so leave analytics out of A13
>
> what is the overlap with A12?

> local day stays out

> create clearly visible disctiontion: these are  just text labels - make switch, eg
> also: net should be transformed to bars - also: add net bar (yellow)

> add checkboxes: show/hide import&export and show/hide net - mutually exclusive - so actually 3 options: show all, show only import/export or show only net -- how should the  UI best be styled?

> close phase

---


## A16 - Per-phase power on readings (logger only)

**Status:** Done (2026-10-29)
**Size:** **S**
**Depends on:** none (P1 logger path exists)
**Sequence:** was #1

### Placement

Affinity **A** (warehouse/`readings` + `logger.py`). Not portals. Not UI. Rollup -> **A17**. UI -> **A18**.

### Verbatim - 2026-10-29

> change database to, from now on, calculate power per phase, keep per minute, make sure the sum of the 3 phases matches the readout from im/export - don't change the UI yet, first i want to get some readings

> 1 triage into new phase and kickoff
> 2A
> 3 yes: add columns and change poller to calculate these and store them - nothing rollup or UI for now

> Q1: why not split them between the 3? give advice
> Q2: could happen: if eg net im/export = 0 and P1 = 100W and P2 = -100W --- is this the edge case? give advice
> Q3: make sure you only do this is effectively there ARE 3 phases (not all P1 meters have that) - if not: just skip this for that plant
> Q4: #1
> & triage the rollup and UI changes

### Kickoff locks

| Lock | Decision |
|---|---|
| Match rule | **2A:** take P1 `active_power_l1_w` / `_l2_` / `_l3_`; if `L1+L2+L3 != active_power_w`, scale/adjust so the stored three sum to `active_power_w` (signed net W; not kWh counters) |
| Persist | `readings` only (1-minute). Columns `power_l1_w`, `power_l2_w`, `power_l3_w` INTEGER |
| 3-phase gate (**Q3**) | Only fill phase columns when the payload **has all three** `active_power_l{1,2,3}_w` keys (present, not null). Otherwise leave all three NULL (skip). Do **not** treat a missing key as 0 |
| Sequence (**Q4**) | #1 |
| Residual (**Q1**) | **Largest-remainder (Hamilton):** proportional scale to floats, floor each phase, hand out leftover +/-1 W to largest leftover fractions (tie-break: largest \|raw\|) |
| Hard edge (**Q2**) | Phase sum == 0 and `active_power_w != 0`: store raw phases, accept mismatch, WARN once. Do not invent a distribution. (100/-100/0 with net 0 is normal - store raw, no scale) |
| Out of scope this ship | `history_5min` / rollup (**A17**); UI (**A18**); backfill of old rows |
| Old rows | New columns NULL for rows written before this ship |

### Open questions

None.

### DoD

- [x] `readings` schema + migrate existing DB (`ALTER` add `power_l1_w`, `power_l2_w`, `power_l3_w`)
- [x] `logger.py`: 3-phase gate; largest-remainder scale; when filled, sum == `active_power` (except Q2 hard-edge samples)
- [x] Q2 WARN via poller `alerts` -> `log_event("WARN", ...)` into `system_logs` (fixed message streaks)
- [x] No rollup / UI changes (A17 / A18)
- [x] Product docs: [`docs/architecture.md`](../architecture.md) readings row

### Shipped summary

`logger.py` fills `power_l1_w` / `power_l2_w` / `power_l3_w` on each 1-min insert when all three P1 phase keys are present. Scale uses largest-remainder to match `active_power`. Hard edge (phase sum 0, net != 0): store raw + WARN in `system_logs`. Settings 1-min CSV and UI unchanged.

---

## A17 - Rollup per-phase W into history_5min

**Status:** Done (2026-10-29)
**Size:** **S**
**Depends on:** **A16** (Done)

### Placement

Affinity **A**. Carry per-phase W from `readings` onto `history_5min` in the downsample path used by `-daily` / `-buildall`. No UI (**A18**).

### Verbatim - 2026-10-29

> & triage the rollup and UI changes

> kickoff A17

> ok & implement

### Kickoff locks (operator confirmed 2026-10-29)

| # | Lock |
|---|---|
| 1 | AVG + ROUND per phase (same as `grid_power_w`) |
| 2 | Re-scale trio to `grid_power_w` (A16 largest-remainder via `phase_power.py`) |
| 3 | Fill only if every 1-min sample in the bucket has non-NULL phases; else NULL trio |
| 4 | WebBox / Fluvius-only -> NULL |
| 5 | Columns `power_l1_w` / `power_l2_w` / `power_l3_w` INTEGER |
| 6 | Same path for `-daily` and `-buildall` (purge + buildall rebuilds phases). No mandatory `-buildall` at ship |

### DoD

- [x] `history_5min` schema + migrate three phase columns
- [x] `-daily` / `-buildall` downsample writes them per locked rules
- [x] Shared `phase_power.py` (logger + rollup)
- [x] No UI (A18); product docs: [`docs/architecture.md`](../architecture.md)

### Shipped summary

`downsample_live_readings` writes phase averages when the bucket is complete, then re-scales to `grid_power_w`. Incomplete / non-P1 rows stay NULL. Deploy `phase_power.py`, `rollup.py`, `logger.py`, `importer.py`; run `rollup.py -daily` (or `-buildall` after purge) to fill recent 5-min rows. `rollup.py` exits cleanly if Python &lt; 3.9 (use `run_python.sh` on Synology).

### Close-out (2026-10-29)

- Product reference: [`docs/architecture.md`](../architecture.md) (`readings` + `history_5min` phase columns).
- No separate phase file to archive (section lives in this file).
- Operator confirmed smoke on 1-min rows earlier; 5-min after `-daily`.

---


## A18 - UI per-phase power

**Status:** Done (closed 2026-10-29)
**Size:** **M**
**Depends on:** **A16** + **A17** (Done)
**Product:** [`docs/architecture.md`](../architecture.md) (Phases view + CSV)

### Verbatim - 2026-10-29

> & triage the rollup and UI changes

> clode A17 & kickoff A18

> all ok

> implement

> can you add a very light filled mountain line on the phases graph that shows the total of L1+2+3

> close A18

### Kickoff locks (operator confirmed)

| # | Lock |
|---|---|
| 1 | Both index + analytics |
| 2 | Fourth switch **Phases** (`?view=phases`) |
| 3 | L1/L2/L3 lines; Sum as light filled mountain (post-ship add) |
| 4 | Signed W bipolar lines |
| 5 | NULL omitted (gap, no zero-fill) |
| 6 | Hide Phases when window has no phase samples |
| 7 | Settings 1-min CSV includes phase columns |

### DoD

- [x] Index + analytics Phases view
- [x] Stored `power_l*_w`; Sum mountain; hide when empty
- [x] Settings CSV phase columns
- [x] Live blank-chart fix (omit null markers for Apex)
- [x] Product docs match shipped UI

### Close-out (2026-10-29)

Shipped: **Phases** on index (2 h) and analytics (mixed 1/5-min); light **Sum** area behind L1/L2/L3; CSV columns. No separate phase file to archive (section stays in this file).

---


## A19 - Analytics explorer: Today calendar-day preset

**Status:** Done (closed 2026-10-29)
**Size:** **S**
**Depends on:** none (**A5b** Done)
**Product:** [`docs/architecture.md`](../architecture.md) (analytics explorer Today default)

### Shipped summary

Calendar-day **Today** preset (chip after 24 h, before 7 d) is the explorer default for Exchange / Balance / Classic. Axis = local midnight to next midnight (DST length as-is). Day pan with -> disabled on today and <- disabled on earliest day in `history_5min` U `readings`. `?date=` matches that behaviour. Reset view -> Today. Rolling presets kept.

### DoD

- [x] Preset **Today**; default; Reset view -> Today
- [x] Axis / DST / day pan / arrow caps / `?date=`
- [x] Product doc + roadmap
- [x] Operator close

### Delivery locks (record)

| Lock | Decision |
|---|---|
| Default | **Today** (all three modes); chip after `24h`, before `7d` |
| Axis | Midnight-midnight; no cap at now |
| Pan | +/- 1 calendar day; caps as above |
| Floor | Earliest day in `history_5min` or `readings` |
| Out of scope | Index pulse; overlay/envelope; warehouse |

### Verbatim - 2026-10-29

> analytics page: grid exchange: add view: "today" which is default, which will show 00:00-23:59 today - assess

> 1: fixed midnight... 2: all 3 3: yes 4: yes, cfr 1

> 1: if today: then right-arrow will be disabled... 2: DST days: spring ~23 h / autumn ~25 h

> triage (give it a phase) & kickoff (check for open items)

> 1: also readins, whatever is first in dB 2: next to 24h but default 3: ok 4: yes, same

---

## A14 - Virtual kWh meters from Settings

**Status:** open (triaged 2026-10-28, not kicked off)
**Size:** **M**
**Depends on:** Settings (**A6** Done)

### Placement

Configure virtual kWh meters on the Settings page with a bias toward import / export / PV. Spec at kickoff (storage, UI, who consumes the meters).

### Verbatim - 2026-10-28

> [open]  virtual kWh meters from Settings (bias to import / export / PV)

---

## A15 - Settings save probe integrations

**Status:** open (triaged 2026-10-28, not kicked off)
**Size:** **S**
**Depends on:** Settings (**A6** Done); existing powertests probe path is a candidate reuse at kickoff

### Placement

On Settings save, probe every filled integration. On failure: modal and stay on the page (do not keep the bad save as committed config - exact fail/rollback rule at kickoff).

### Verbatim - 2026-10-28

> [open]  Settings save: probe every filled integration; fail -> modal, stay on page

---

## A20 - Settings locked-by-default / Unlock then Save-if-dirty

**Status:** open (kickoff locked 2026-10-29; waiting `implement`)
**Size:** **S**
**Depends on:** Settings (**A6** Done). Related: **A9** backup; **A15** still on Save after unlock (probe).
**Prereqs:** **A6** Done - ok. No blockers.

### Placement

Affinity **A**. New UX on `settings.php`: page starts locked; Unlock asks admin password; then fields (incl. DB backup) enable; primary button becomes Save only when the form is dirty. **1-min CSV** usable while locked, outside the admin pane. Does not reopen Done **A6** - supersedes parts of A6 UX at ship after kickoff locks.

### Verified (code / product docs)

- Save, backup download, backup restore, and **1-min CSV** all currently ask admin password via the gate modal (`settings.php` + [`architecture.md`](../architecture.md)).
- Device secrets: stars when set; **Edit** verifies admin then clears the field for a new value.
- Change admin password: separate modal (current + new + new); not via Save.
- No stay-logged-in session today. `verify_admin` JSON exists for Edit.
- First-run create-admin and need-config flows stay out of this unlock UX (page is not the normal locked form until admin exists).

### Kickoff locks (operator 2026-10-29)

| # | Lock |
|---|---|
| 1 | Unlock window duration is a **named constant / variable** (default **5 minutes** from unlock) so the operator can change it without hunting magic numbers. On expiry: if form **not dirty** -> re-lock. If **dirty** -> modal **Save** or **Cancel**. Choosing **Save** asks admin password (timeout path). Cancel discards edits and re-locks. Reload or leave Settings ends unlock immediately. |
| 2 | **Within** the unlock window: **Save does not ask** admin password again (UX). Server still receives the unlock password from page JS memory so crafted POSTs without a prior unlock do not save. |
| 3 | **Change admin password:** control **disabled until unlock**. Inside the unlock window, no extra unlock/gate ask (change form still collects current + new + new). |
| 4 | **1-min CSV:** **no password** (UI + server). Move the control out of the admin/config pane (readings export, available while locked). |
| 5 | **Device passwords after unlock:** keep **Edit**; inside the unlock window Edit does **not** ask admin. Edit still clears the field for replace; empty+untouched keeps the stored secret; stored value never shown. |
| 6 | **Backup download** while unlocked: **no password** (UI + server; control disabled until unlock). **Restore:** **always** admin password (+ existing destroy-confirm). |
| 7 | **After successful Save:** **re-lock** (clear dirty / end unlock window). |

### Open questions

None. Ready for `implement`.

### DoD

- [ ] Settings loads locked: config fields + backup download/restore + change-admin disabled; CSV outside admin pane, no password
- [ ] Unlock -> timed window (named variable, default 5 min); Save only when dirty; within window Save without pw; after Save re-lock
- [ ] Timer expiry: clean re-lock, or dirty Save/Cancel modal (Save asks pw)
- [ ] Device-password Edit kept; no admin ask inside unlock window
- [ ] Backup download: no pw when unlocked; restore always pw + confirm
- [ ] Wrong unlock pw stays locked
- [ ] A6 first-run / need-config unchanged; product docs on close-out
- [ ] No A15 probe work in this ship

### Verbatim - 2026-10-29 (device pw + confirm)

> device password fields after unlock: keep edit, no admin when in 5-min
> make that 5-min a variable so I can change if needed
> backup download & csv download: correct: no pw needed - restore: always pw

### Verbatim - 2026-10-29 (kickoff answers)

> 1: max 5 mins, then if no edits done: lock again - if edits are done: open modal to ask to save or cancel - to save: ask pw
> 2: if within time 5 mins: no pw
> 3: admin pw change: don't ask if in 5min-timeframe
> 4: download csv = no pw - put in in UI so that it not in the "admin" pane
> 5: disabled until unlock
> 6: restore: always pw
> 7: save = re-lock

### Verbatim - 2026-10-29 (triage)

> settings page: all fields are disabled (including DB backup but NOT CSV download)- button "save settings" is not there, it reads "unlock settings" - which will ask for pw in modal - if correct: all fields ware enabled, then the button becomes save IF something is changed
> assess

---




## Out of scope (until re-triaged)

- Portal ingest (**P1–P3**)
- Restoring the old slow Top 10 AJAX query as-is
