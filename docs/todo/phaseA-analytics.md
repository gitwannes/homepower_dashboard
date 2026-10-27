# --- file: docs/todo/phaseA-analytics.md ---
# Phase A (continued) — Analytics UI + plant config

**Status:** **A4**, **A5**, **A6**, **A7**, **A8**, **A9**, **A10**, and **A13 Done**; **A5b** coding on `main`; A11–A12 queued. (A0–A3 Done — [`_archive/phaseA-aggregation.md`](_archive/phaseA-aggregation.md))  
**Last updated:** 2026-10-27 (**A7** closed)  
**Product pointers:** [`docs/architecture.md`](../architecture.md) · `topn.php` · `rollup.py` · `settings.php`

---

## Sequence (this file)

| Id | Status | What |
|---|---|---|
| **A4** | Done | Top-N tables (days / months / years) + graphs |
| **A5** | Done | Balance/Classic multi-series power (both pages). Product: [`docs/architecture.md`](../architecture.md) |
| **A5b** | coding | Balance explorer; index 2h pulse; mixed 1/5-min; CSV→Settings; drop telemetry table |
| **A6** | Done | Settings page (`settings.php`). Product: [`docs/architecture.md`](../architecture.md) |
| **A7** | Done | `top_records` on `topn.php`. Product: [`docs/architecture.md`](../architecture.md) |
| **A8** | Done | Live chart clock window. Product: [`docs/architecture.md`](../architecture.md) |
| **A9** | Done | Settings encrypted backup download + restore. Product: [`docs/architecture.md`](../architecture.md) |
| **A10** | Done | Warehouse rebuild. Product: [`docs/architecture.md`](../architecture.md) (Rollup CLI) |
| **A11** | open | Historical gas, wind, and similar series on the pages. Not P3. Not kicked off |
| **A12** | open | Historical monthly Fluvius peak on a page. Not P3. Not kicked off |
| **A13** | Done | Grid import/export on summary and top-n. Product: [`docs/architecture.md`](../architecture.md) |

**Parallel:** **A5b** is independent of **P1–P2**. **A11** and **A12** read Fluvius rows **P3** stored. They are not kicked off. Wind for A11 is already in the warehouse.

**Related:** A3 removed Hall of Fame UI. A4 is yield Top-N. A7 shipped all-time `top_records` on `topn.php`.

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

**Status:** coding (branch `feature/a5b-balance-explorer`)  
**Depends on:** **A5** Done  
**Scope:** Analytics owns Balance exploration (clock window + pan). Index keeps a short live pulse with link to analytics. Mixed 1-min / 5-min by 5-minute bucket. Warehouse tables unchanged (`readings` + `history_5min` stay).

### Locks (operator 2026-10-27)

| # | Lock |
|---|---|
| 1 | **Index:** 2 h live pulse (Balance default). Link to analytics uses the same control pattern as the top-nav Analytics button. |
| 2 | **Analytics Balance:** presets 15 m, 1 h, 2 h, 6 h, 24 h, 7 d; default **6 h** (ending now). Date/time anchor. **Reset view** returns to that default. Split-dot legend for Consumption / Production (plus the three bands). |
| 3 | **Pan:** ← / → moves the current window by **one full step** (window length). |
| 4 | **Mixed resolution (per 5-minute bucket):** if at least **two** 1-minute `readings` exist in that bucket under rule 4a, use 1-min points for that bucket; otherwise use the `history_5min` row for that bucket (Fluvius derive rules from A5 still apply on 5-min). Chart **may mix** densities in one view. |
| 4a | **V — visible only:** count and emit only 1-min samples in **bucket ∩ chart window**. |
| 5 | **Analytics Classic:** same window presets, pan ←/→, deep links as Balance (not calendar-day-only). Classic series stay solar / home+solar style from A5, resolution mix same as Balance. |
| 6 | **Deep link:** `range` and `from`/`to` parity with live (plus `view=balance|classic`). |
| 7 | **Index cleanup:** remove Detailed Telemetry Log table. Move **Download CSV** to Settings, beside DB backup, with operator info: download all 1-min samples as CSV. |
| 8 | **Not in scope:** dropping `history_5min` or `readings`; changing overlay/envelope. |

### Assess — is per-bucket mix a good idea?

**Yes, with native points and resolution-aware gaps** — not as one blended average series.

| | |
|---|---|
| **Good** | Uses detail where the logger actually sampled; falls back per bucket when thin; matches how 5-min is produced (up to five 1-min samples). |
| **Risk** | Mid-chart density change (jagged 1-min next to stepped 5-min). Blind linear bridging across a 1-min cluster and a Fluvius 5-min point invents fake slopes. |
| **Rule to implement** | Emit **native** samples: qualifying 1-min rows, else the single 5-min point. Gap rules: 1-min regions keep the 3-minute break; 5-min-only stretches keep ≤2 missing 5-min slots. **Do not** interpolate across a resolution change. Tooltip should show source (`1 min` / `5 min`) when mixed. |

### Q1 elaborate — count/emit: **V** vs **F**

For each aligned 5-minute bucket that intersects the chart window:

| | **V — visible only** (matches “visible” in your wording) | **F — full bucket in DB** |
|---|---|---|
| **Count ≥2 using** | 1-min rows with timestamp in **bucket ∩ window** | All 1-min rows stored in that bucket (even if outside the window) |
| **If qualified, plot** | Those same in-window 1-min points | In-window 1-min points only (still clip; do not draw outside the axis) |
| **If not qualified** | `history_5min` for that bucket if its stamp is in the window | Same |
| **Window edge example** | Window starts 14:03; bucket 14:00–14:05 has DB samples at 14:00,14:01,14:02,14:03,14:04. **Visible** = 14:03,14:04 → count 2 → 1-min. If only 14:03 in window → count 1 → **5-min** fallback even though the bucket is rich in the DB. | Same bucket always count 5 → 1-min; plot only 14:03–14:04 in view. |
| **Pros** | Honest to what the user sees; no “secret” samples deciding the mode. | Stable mode for a bucket when panning by less than 5 minutes; less flicker at edges. |
| **Cons** | Panning by 1 minute can flip a bucket 1-min ↔ 5-min at the cut. | A bucket can “qualify” as 1-min while only one point is drawn in the window (odd). Slightly disagrees with “visible”. |

**Recommendation:** **V**. Your phrase was “2 minute-readings … are visible.” Edge flicker is acceptable; tooltip source makes flips understandable.

### Still open

None for kickoff.

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

## A12 — Historical monthly Fluvius peak

**Status:** open (triaged 2026-10-26, not kicked off)  
**Size:** **S**  
**Depends on:** P3 (Done) stores `piekvermogen` in `fluvius_interval`. The live `capacity_peak_w` register on `index.php` stays as it is.

### Placement

Not part of P3. P3 does not write the monthly peak into `capacity_peak_w` and does not add it to the live card. This phase decides where the monthly kW series is shown. No code until kickoff.

**Beside A13:** A13 is period import/export kWh on summary and top-n. This phase is the monthly peak kW (`piekvermogen` in `fluvius_interval`). Different unit, different store. A13 does not draw the peak.

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

## Out of scope (until re-triaged)

- Portal ingest (**P1–P3**)
- Restoring the old slow Top 10 AJAX query as-is
