# --- file: docs/todo/phaseA-analytics.md ---
# Phase A (continued) â Analytics UI + plant config

**Status:** **A4**-**A24** + **A11** + **A23** Done; **A25** locked; **A26** open. (A0-A3 Done - [`_archive/phaseA-aggregation.md`](_archive/phaseA-aggregation.md))  
**Last updated:** 2026-11-03 (**A24** Done)  
**Product pointers:** [`docs/architecture.md`](../architecture.md) - `topn.php` - `rollup.py` - `settings.php` - `explorer_chart.php` - `balance_chart.php` - `logger.py` - `weather.php`

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
| **A11** | Done | Historical gas, water, weather page. Product: [`docs/architecture.md`](../architecture.md) |
| **A12** | Done | Capacity peak on summary + top-n. Product: [`docs/architecture.md`](../architecture.md) |
| **A13** | Done | Grid import/export on summary and top-n. Product: [`docs/architecture.md`](../architecture.md) |
| **A14** | Done | Virtual kWh meters: Settings + calc + debug. Product: [`docs/architecture.md`](../architecture.md) |
| **A15** | Done | Settings save: probe + `*_active`; homepage errors; unlock stay on fail. Product: [`docs/architecture.md`](../architecture.md) |
| **A16** | Done | Per-phase W on `readings` + logger. Product: [`docs/architecture.md`](../architecture.md) |
| **A17** | Done | Rollup: per-phase W into `history_5min`. Product: [`docs/architecture.md`](../architecture.md) |
| **A18** | Done | UI: Phases view + Sum mountain; CSV phase cols. Product: [`docs/architecture.md`](../architecture.md) |
| **A19** | Done | Analytics explorer: calendar-day **Today** preset (default). Product: [`docs/architecture.md`](../architecture.md) |
| **A20** | Done | Settings locked-by-default / Unlock timed window. Product: [`docs/architecture.md`](../architecture.md) |
| **A21** | Done | Raw Meter Registers: virtual meters + optional rows. Product: [`docs/architecture.md`](../architecture.md) |
| **A22** | Done | bugfix: analytics load; Virtual 'best-day' -> Top-N; PVGIS week/month |
| **A23** | Done | Weather extremes on daily/monthly; weather.php periods read rollups |
| **A24** | Done | bugfix: clear_sky envelopes rad>floor (WebBox kept) |
| **A25** | locked | weather.php All years card (monthly_history extremes) |
| **A26** | open | bugfix: clear_sky envelope harden (June evening jump) |

**Parallel:** **A14**-**A24** + **A11** + **A23** Done. **A25** locked (`implement` unblocked). **A26** open (envelope harden).

**Related:** A3 removed Hall of Fame UI. A4 is yield Top-N. A7 shipped all-time `top_records` on `topn.php`.

---

## Verbatim inbox

### 2026-11-03 - close A24

> close A24

Last DoD. Operator smoke accepted. A25 implement unblocked. Phase file stays in-repo (**A25**/**A26** open).

### 2026-11-03 - A25 kickoff answers

> 1: ok
> 2: confirmed
> 3: rollup only
> 4: yes
> 5: keep consistency
> 6: keep null for empty years
> 7: after A24

Locked into **A25** section. No code this turn.

### 2026-11-03 - triage A26 (envelope harden)

> give options for envelope harden
> triage into new phase

Placed: **A26** (affinity **A**). Follow-on to A24; June spike = `2016-06-24` webbox discontinuity. Option choice at kickoff.

### 2026-11-03 - implement A24 (rad gate amend)

> ok, implement

Blanket webbox exclude emptied Jan-Sep. Amended: keep WebBox; envelope-only require rad > PAC_RAD_FLOOR. Operator `-buildall` + smoke again before `close A24`.

### 2026-11-03 - implement A24

> implement A24

First ship excluded `webbox_archive` (insufficient live history). Superseded by rad-gate amend same day.

### 2026-11-03 - triage A25; close A22/A24 request

> triage in A25, then close A22 (if needed) & A24

Placed: **A25**. **A22** already Done. **A24** left open then implemented same day.

### 2026-11-03 - close A22 + A24 locks

> close A22
> 1: yes
> 2: buildall , no need to create seperate buildallenvelopes
> no code yet

A22 closed (Last DoD). A24 Q1/Q2 locked; still awaiting `implement` (no code this turn).

### 2026-11-03 - A24 triage + kickoff (envelope webbox filter)

> 1: give stataments to check
> 2: A
> triage into A23 & kickoff

A23 already taken (weather). Placed as **A24**. Operator ran diagnostic SQL on NAS (2026-11-03); results confirm option A. See A24 section.

### 2026-11-03 - close A23

> close A23

### 2026-11-03 - A23 kickoff answers + A11 close

> 1: one ship
> 2: both daily & buildall code should be changed - -buildall is needed for db change after file sync
> 3: align
> 4: close A11 now
> 5: W2 is out

### 2026-11-03 - A23 triage + kickoff

> triage this into new then kickoff

Prior ask (verbatim body of the work):

> put that table in architecture MD:
> SoT (keep forever) ... virtual_kwh_meters (config) ... top_records / top_periods
> what is to do for db / ui changes?

(Architecture warehouse map already updated 2026-11-03. Remaining work placed as **A23**.)

### 2026-11-03 - A22 implement (Q2 locked)

> fix in this scope

Q2 load/blank-chart scope accepted. Coding authorized.

### 2026-11-03 - A22 kickoff answers (partial; Q2 advice)

> 1: blank charts
> 2: ??
> 3: ok
> 4: ok

Q1/Q3/Q4 locked. Q2 advice accepted on implement command.

### 2026-11-03 - A22 kickoff (rename lock + drop powertests)

> not "Best-day envelope" but "Virtual 'best-day'"
> drop move powertests
> kickoff

Powertests item dropped from **A22**. UI rename locked: **Virtual 'best-day'**. Open Qs in A22 section.

### 2026-11-03 - triage A22 (UI bugfix bundle)

> triage into 1 bugix phase:
> move powertests icon to settings page
> analytics page: often doenst load or loads slow
> clear-sky envelopes: rename (give proposal) & move to top-n: give advice
> PVGIS shows on all years & year but not week & month

Placed: **A22** (affinity **A**). Powertests later dropped at kickoff. No code until kickoff locks + `implement`.

### 2026-11-03 - close A14 + A21

> close A14
> and Q21
> A21

A14, A21, A11, A22, A23, and A24 closed (Last DoD). Product: [`docs/architecture.md`](../architecture.md). `phaseA-analytics.md` stays in-repo while **A25**/**A26** are open - do not archive the whole file yet.

### 2026-11-03 - A21 ship (raw registers virtual + optional)

> text "Show in Raw Meter Registers (flag; UI in A21)" rename to "Show in Raw Meter Registers"
> enabling this checkbox doens't show meter in "raw readings": fix
> additionally, add section: "show optional meters in raw readings", where these 5 are shown, each with a checkbox, default on - if disabled: don't show in raw readings:
> Peak Import T1 (1.8.1)
> Off-peak Import T2 (1.8.2)
> Peak Export T1 (2.8.1)
> Off-peak Export T2 (2.8.2)
> Solar Generated Today
> Solar Lifetime Yield

Shipped as **A21** (6 optional rows listed; operator wrote "5").

### 2026-10-29 - A14 kickoff locked (round 3)

> 1: yes
> 2: first save only - add button to re-bias on current reading (+ confirmation modal)
> 3: UI in Q21
> 4: all what you think is usefull to troubleshoot these virtual meters - no config, that is on the config page
> 5: yes
> 6: last readings

A14 kickoff locked. Waiting `implement`. Raw Meter Registers display = **A21**.

### 2026-10-29 - A14 kickoff round 2

> 1: correct
> 2: factor in % in settings, so 99.9996
> 3: possible S0 = P1 lifetime import/export/net (3) + SMA total + webbox total = 5 possible sources (if available)
> 4: add to settings: button "add virtual kWh meter" - then some field appear: name, source, bias, factor + info on source & virtual reading (to check if correct) + checkbox if this virtual reading should appear in "Raw Meter Registers"
> 5: add PHP debug page

Round 2 answers folded into A14 locks.

### 2026-10-29 - A14 kickoff answers + triage virtual-meter UI (A21)

> 1: it will track the same kWh reading from the source - meter but with a bias - I have several kWh meters which track yield - so it is just source + bias = reading for that virtual meter
> 2: selectable in settings: all kWh meters (so those 3) ("add one" button)
> 3: a constant offset + a percentage (with up to 4 decimems) of the new readings eg virtual meter 1 = yield minus 25000 kWh + 99,9996% of the additional yield kWh from now on
> 4: for now: only in code, we will worry about UI later (triage this) - give code lines to display these readings and calculation (for troubleshooting)
> 5: no need for rollup / history_5min / daily totals I think - confirm
> 6: leave asis

Placed UI follow-on: **A21**. See A14 / A21 sections.

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

### 2026-10-29 ? triage (Ops5 daily backup; fold)

> triage: daily backup, keep only last 14: rotate - in settings: (synology) path for backup save - filename: powerbackup-timestamp.sql.zip timestamp = YYMMDD-HHMMSS

Operator: fold into **Ops5** (replaces Hyper Backup scope). Detail: [`roadmap.md`](../roadmap.md) Phase 4. Encryption vs plain zip left for kickoff.

### 2026-10-23 - triage (three items)

> also triage:
> - create table for top n days, months years & create graphs for it
> - add , next to PV power generation, power pull from net and net power usage (more or less the graph like HomeWizard-graph.jpg - image is saved in \docs\todo in repo)
> - add to .env (or maybe split env and config, to assess at kickoff): max converter power (and remove spikes based on this), expected power generation for the plant (now hardcoded)

### 2026-10-23 â A4 kickoff answers + top_records triage

> 1: top 20 day, top 10 mointh & year
> 2: ??
> 3: also worst days/months/years - that what you mean?
> 4: new page
> 5: generated now with -buildall, and also with -daily
> 6: good idea: clicking on a bar will show that day/month/year in the other page
> 7: what does top_records do?

> 2A
> 7B + add to triage to either drop or change

### 2026-10-25 â fold into A6

> A6: fold into this: configuration page to configure all this, including everything that is in .env / give options, suggestions, base on industry standard

### 2026-10-25 â A6 answers (configure all)

> when this is deplyed on a new machine, the user does not have access to .env - how does a new user configure passwords?
> I want the user to be able to confug ALL
> 3: for now, store per month expected generation
> 4: spike = max inverter AC 105M
> 5 also on the pase

### 2026-10-25 â A6 admin password

> 1: admin password - if no admin yet (current .env): ask at first page open
> 2: don't show passwords, show * (stars), but allow the pws to be changed, then entering the admin pw is needed

### 2026-10-25 â A6 password edit + save gate

> password fields: are NOT editable unless right-click on them and choose "edit", then a modal appears where the admin pw is asked - then the field becomes editable and can be saved
> 1: Admin password on every settings save: yes
> 2: admin pw first, then config page

### 2026-10-25 â A6 password fields (unset + Edit control)

> 1: if unset: direct edit will be possible (save still requires admin pw)
> 2: yes, no right-click

### 2026-10-25 â A6 empty password + change admin password

> 1: if left empty: do not change pw
> 2: yes: change admin pw opens a modal: current pw, enter new pw twice, then enter

### 2026-10-25 â triage (live chart axis)

> triage: live dashboard: Solar & Consumption Profile (Last 2 Hours)
> 	make sure the X-asis is to scale: display only last 2 hours and if there is no reading, don't display a line there
> 	change the viewport: not just 2 hours, but allow to zoom in/out to max 1 week of 1-minute data
> 	so remove the " (Last 2 Hours)" from the title

### 2026-10-25 â triage (DB backup)

> also triage: download backup of entire DB, same functionality/way of working as the budget download
> ask questions

### 2026-10-25 â A9 answers

> DB_BACKUP_ENCRYPTION_KEY will stay in .env - after A6 this is the only variable that stays in .env (it is not user configurable)
> 1: new letter: A9
> 2: download & restore, no wipe
> 3: everyone?
> 4: yes
> 5: option sits in the admin page: A6 is prerequisite
> 6: in .env, not comitted to GIT
> 7: plain sql in the DB only - synctool already copies fo local PC: confirm

### 2026-10-25 â A9 gate + sync pull

> 1: when download and restore: ask for admin pw
> 2: yes, p1-sync should also pull the db

### 2026-10-27 â A8 / A9 kickoff answers

Questions were: (1) zoom (2) X-axis when the window is not full (3) gap rule (4) titles (5) browser file (6) restore swap (7) when the synctool pulls.

> use sqlite backup, which will have a timestamp in the name, sync that to the PC with the synctool, delete the bak from synology

> 1B
> 2A
> 3B
> 4A
> 5A
> 6A + ask very specifically whether the user is sure to restore and that eveyrthing currently iin the DB will be destroyed
> 7A

### 2026-10-27 â A9 filename + who creates it

> 1: timestamped copy - all copies remain (pull only)
> 2: backup is created by PHP, then sync copies all .bak (filename meter_history.db.<timestamp>.bak eg meter_history.db.261027-083600.bak

### 2026-10-27 â A9 download is the encrypted zip

> well, as with budget, that bak must be zipped and encrypted with the DB_BACKUP_ENCRYPTION_KEY in .env - that zipped and encrypted file must be downloaded, not the actual backup - the bacup is deleted, ony the zipped/encrypted file is downloaded

### 2026-10-27 â A9 no synctool pull

> well, actually, the synctool will never pull that backup
> since: button click -> sql backup -> zip -> encrypt -> download -> bak & encrypted files on source are deleted -> nothing to sync by tool
> confirm

---

## A4 â Top-N days / months / years + graphs

**Status:** **Done** 2026-10-25  
**Product:** [`docs/architecture.md`](../architecture.md) (`topn.php`, `top_periods`).

### Done summary

Best/worst yield rankings (20 days, 10 months, 10 years) in `top_periods` via `-buildall` and `-daily`; UI `topn.php` with click-through. Worst days exclude yield â¤ 0 and today. `top_records` unchanged â **A7**. `rollup.py -today` was removed in **A10**.

### DoD

- [x] Storage + rollup + page + deep links + nav + product docs
- [x] Operator close (smoke)

### Out of scope (A4)

- Peak-W Top-N; drop/change `top_records` (**A7**); old live Top-10 AJAX

---

## A5 â Multi-series power (PV / grid pull / home usage)

**Status:** **Done** 2026-10-27  
**Product:** [`docs/architecture.md`](../architecture.md) (live + analytics Balance/Classic)  
**Branch:** `feature/a5-balance-chart` (merge/commit still operator-owned)

### Done summary

Balance vs Classic on `index.php` and `analytics.php` (default Balance). Three painted W bands: Self-sufficient, Grid, Surplus. Production and Consumption are stack-outline composites (legend split dots + tooltip). Fluvius quarter kWh â average W on analytics when P1 grid is missing; â¤2 missing 5-min slots bridged. Overlay and envelope stay solar-only. No vendor product name in UI. Windowed explorer / index pulse / mixed 1-min+5-min â **A5b**.

### DoD

- [x] Balance formulas, paint rule, mode switch, Fluvius derive on analytics day
- [x] Product docs (`architecture.md`)
- [x] Operator close â A5b

### Locks (operator 2026-10-27) â shipped

| # | Lock |
|---|---|
| 1 | Both pages (superseded for layout by **A5b**; formulas stay). |
| 2â10 | Chart model, W, Fluvius derive, decomposition, overlay/envelope solar-only, mode switch, default Balance, five series with three painted, no vendor name. |

**Formulas** (unchanged; A5b reuses):

- `grid_net_w` = P1 signed net (`active_power` / `grid_power_w`; import positive, export negative). From Fluvius-only slots: `import_w - export_w` (`kWh * 4000`).
- **Production** = `max(0, solar_w)`
- **Consumption** = `max(0, solar_w + grid_net_w)`
- **Self-sufficient** = `min(production, consumption)`
- **Grid** = `max(0, consumption - production)`
- **Surplus** = `max(0, production - consumption)`

### Verbatim â 2026-10-27 (kickoff)

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

## A5b â Balance explorer (window, pan, mixed resolution)

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

### Locks (operator 2026-10-27) â shipped

| # | Lock |
|---|---|
| 1 | **Index:** 2 h live pulse (**Exchange** default; Balance / Classic via switch). Analytics link matches top-nav pattern. |
| 2 | **Analytics:** presets 15 mâ7 d; default **6 h** ending now; **Reset view**; Balance split-dot legend. |
| 3 | **Pan:** one full window step. |
| 4 / 4a | Mixed resolution per bucket; **V â visible only**. |
| 5 | Classic and Exchange share windows / pan / deep links. |
| 6 | Deep link `range`, `from`/`to`; `view=balance|classic` (Exchange default omits `view`). |
| 7 | Telemetry table off index; 1-min CSV on Settings beside backup (A20 later moved CSV to Readings export, no password). |
| 8 | Not in scope: dropping `history_5min` / `readings`; overlay/envelope changes. |

### Verbatim â 2026-10-27

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

## A6 â Settings page

**Status:** **Done** 2026-10-26
**Product:** [`docs/architecture.md`](../architecture.md) (Settings, config split) Â· [`docs/deploy.md`](../deploy.md) (runtime `.env`)

### Done summary

- The page configures the current `.env` keys, including `SMA_PASSWORD`, `SUNNYWEBBOX_PASSWORD`, and the Sunny Portal user, password, plant id, and timezone, plus plant settings. **Exception (2026-10-25, A9):** `DB_BACKUP_ENCRYPTION_KEY` stays in `.env`. It is the only variable that remains there after A6. It is not on the settings page, not imported into SQLite, and not committed. `.env.example` may name the key; the real value stays on the host. Sunny Portal fields are optional on save. `SUNNYPORTAL_*` is copied when that settings key is still absent (a leftover `ENNEXOS_*` name for the same value still counts). A saved empty Sunny Portal row is not overwritten.
- Expected generation: **12 monthly kWh values** only. No kWp, azimuth, or tilt field in this phase. Storage stays `expected_monthly` (existing `inclination = 30` key so current readers keep working).
- Spike cap = **max inverter AC Ã 1.05**. Reading of â105Mâ as 105%. Operator enters max inverter AC watts; the page shows the computed cap. Replaces hardcoded `MAX_PLAUSIBLE_PAC_W` (12 kW) in `rollup.py` and `analytics.php`.
- Night / shoulder gates are on the same page. Defaults match the code today (`rollup.py` `sql_pac_plausible`, `analytics.php` `sanitize_pac_w`):
  - Deep night: hour `>= 22` or `< 5` (PAC dropped).
  - Shoulder: hour `>= 20` or `< 6`, and only when `solar_radiation <= 5` W/mÂ² (PAC dropped).
  - Radiation floor default `5` W/mÂ² (`PAC_RAD_FLOOR`).
- **Admin password (A6):** first open creates it (enter + confirm). Graph pages have no standing login session. **Unlock / Save / device-password Edit UX was replaced by A20** - current rules: [`docs/architecture.md`](../architecture.md) (Settings).
- **New install / current host:** admin first; new install without P1 stays on Settings until saved. `.env` holds `DB_BACKUP_ENCRYPTION_KEY` only.

`settings.php` configures devices, titles, 12 monthly expected kWh, max inverter AC (spike cap x 1.05), and the night/shoulder gates. Values live in `app_settings`. Device passwords stay masked. `DB_BACKUP_ENCRYPTION_KEY` stays in `.env`. Plant changes apply on `rollup.py -buildall`. Page lock/unlock: **A20**.


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

## A7 â `top_records` on Top-N

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

### Verbatim â 2026-10-27 (close)

> close A7

### Verbatim â 2026-10-23 (triage origin)

> 7B + add to triage to either drop or change

### Verbatim â 2026-10-27 (kickoff)

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

## A8 â Live chart time axis + zoom

**Status:** **Done** 2026-10-27  
**Product:** [`docs/architecture.md`](../architecture.md) (live chart on `index.php`).

### Done summary

The live power chart uses a clock window of 1-minute `readings`. Presets are 15 min, 1 h, 2 h, 6 h, 24 h, and 7 d, ending at now. Default is the last 2 hours. Drag zoom keeps that range across the 60-second reload, from 15 minutes through 7 days. A 3-minute step stays drawn. A longer step is a gap. Titles are `Solar & Consumption Profile` and `Power History`. Series are unchanged. This is not A5.

### DoD

- [x] Chart window, presets, drag zoom, gaps, titles
- [x] Product docs
- [x] Operator close

---

## A9 â Full DB download + restore

**Status:** **Done** 2026-10-27  
**Product:** [`docs/architecture.md`](../architecture.md) (Settings backup) Â· [`docs/deploy.md`](../deploy.md)

### Done summary

Settings downloads and restores an encrypted copy of the whole database. `db_backup.py` does the SQLite online backup, the zip, and AES-256-GCM. Browser file `meter_history.db.<yymmdd-HHMMSS>.backup`. Plain and encrypted files are deleted on the server. `p1-sync` does not pull them. PHP `zip` is not used. **Auth UX after A20:** backup download needs Unlock (no second password); restore always asks admin password + destroy confirm. Product: [`docs/architecture.md`](../architecture.md).

### DoD

- [x] Download and restore
- [x] Product docs
- [x] Operator close

---

## A10 â Warehouse rebuild

**Status:** **Done** 2026-10-26 (NAS smoke)  
**Product:** [`docs/architecture.md`](../architecture.md) (Rollup CLI) Â· [`docs/roadmap.md`](../roadmap.md) Â· [`docs/deploy.md`](../deploy.md)

### Done summary

`-buildall` deletes `history_5min`, `daily_history`, and `monthly_history` (no `VACUUM`), refills `history_5min` from all `readings` except the open 5-minute slice, then from WebBox staging, then rebuilds daily, monthly, envelopes, `top_records`, and `top_periods`. Rare. The day chart is empty until it finishes. `-daily` uses local time: last 2 days of `readings`, last 3 days of `daily_history`, then a full `monthly_history` and `top_periods`. `-purge_derived` is unchanged. `importer.py -aggregate5min`, `rollup.py -today`, and analytics Generate today table are removed. Todayâs closed buckets go through nightly `-daily`. Import writes staging only. `processed/` is not scanned again. Both staging tables hold every WebBox year. P3 later stitches Fluvius electricity quarters in that same `-buildall`, after WebBox consolidate. See [`docs/architecture.md`](../architecture.md).

### Out of scope

- Fluvius (**P3**) and SMA / ennexOS (**P1**, **P2**).
- Restoring `sql_importer.py`.
- (A7 Done: `top_records` beat on `-daily` + `-buildalltoprecords`.)

### Verbatim â 2026-10-26 (close)

> A10 smoke done: close

### Verbatim â 2026-10-26 (lock + implement)

> 1: yes, all webbox data is in those 2 staging tables, the history_5min table does not contain data that is not in those 2 tables
> 2: very good - rebuild will not be done regularly - ideally, never
> if all ok: implement and push to seperate branch

### Verbatim â 2026-10-26 (placement)

> 1/ importer: gets data from Mean archives from import_queue and writes sensorbox_history_5min, inverter_history_5min
> importer.py -aggregate5min: gets data from those same Mean archives from import_queue and puts them into solar and weather columns in history_5min
> rollup.py -consolidate: gets data from sensorbox_history_5min, inverter_history_5min and puts that info solar and weather columns in history_5min
> so that seems a step in-between, so dumping importer.py -aggregate5min seems ok
> is this reasoning sound? if yes: put this in the todo list

### Verbatim â 2026-10-26 (fold + kickoff)

> fold all in A10, then kickoff

---

## A11 - Historical gas, wind, and similar

**Status:** **Done** 2026-11-03 (Last DoD / operator close)  
**Size:** **L**  
**Product:** [`docs/architecture.md`](../architecture.md) (gas/water + `weather.php`) � `gas_water.php` � `utility_volume.py` � `weather.php` � `summary.php` � `topn.php` � `index.php` � `rollup.py`

### Done summary

Shipped gas/water on summary/topn/index (m3; Fluvius gas not on `history_5min`) and standalone `weather.php` (explorer + Week/Month/Year + records). Weather period rollups completed in **A23**. WanOS ambient source: **W2**. All-years weather card: **A25**.

### DoD

- [x] Rollup `gas_m3` / `water_m3` on daily + monthly; Fluvius gas not on `history_5min`
- [x] Summary + Top-N Gas / Water metrics
- [x] Index 2 h gas/water rate charts
- [x] `weather.php` explorer + period + records; nav pill
- [x] Product docs (`architecture.md`)
- [x] Operator close / Last DoD (2026-11-03)

### Locked decisions (record)

| # | Lock |
|---|---|
| 1-13 | As locked 2026-11-03 (gas/water metrics, weather page, no Fluvius gas on `history_5min`). Full Q&A verbatim below. |

### Verbatim ? 2026-11-03 (lock + implement)

> lock & implement

### Verbatim â 2026-11-03 (kickoff round 4)

> 1: instant - cfr homewizard (att)
> 2: if available: 1min from P1 - else: 5min
> 3: mirror
> 4: water-link = future

Reference screenshot (HomeWizard Gas Day): chat assets `Screenshot_2026-11-03-09-35-30-50_*.jpg` (filled area, m3 per interval; cost line not in A11).

### Verbatim â 2026-11-03 (kickoff round 3)

> gas & water: does rollup need to create daily/monthly totals? amend tables?
> 1: also on Energy Dashboard, below Grid exchange and webbox info (same 2hr span)
> 2: no day view: reading per day on week chart
> 3: also P1 = more precise
> 4: B
> 5: toggles
> 6: ok
> 7: all
> 8: water lowest: exclude 0-days & today

### Verbatim â 2026-11-03 (kickoff round 2)

> 1: all available
> 2: gas: also day card
> not shared, show both
> wind/radiation/temp etc: create seprate page, don't fold into existing ones
> 3: m³
> 4: yes (P1 or a future water-link integration)
> 5: gas: only highest // water: both
> re-assess

### Verbatim â 2026-11-03 (kickoff)

> kickoff A11: give sugestions on how (type of graph) & where (which page and where on the page, seperate or integrated in other graphs) gas, water, wind & others can be displayed

### Verbatim â 2026-10-26

> Q3: hitorical gas, wind speed, etc is not for this phase - tirage that into another phase

---

## A12 â Historical monthly Fluvius peak (Capacity peak)

**Status:** **Done** 2026-10-29  
**Product:** [`docs/architecture.md`](../architecture.md) (UI map: `summary.php`, `topn.php`) Â· `capacity_peak.php` Â· `rollup.py` (`top_records`)

### Done summary

Third metric **Capacity peak** on `summary.php` and `topn.php` (`?metric=peak`). Fluvius `piekvermogen` months (kW); open month replaced by live P1 WâkW. Summary peak mode: year + All years only; All years last in every metric; Max/Average on the All years card with a shared Max Y-axis. Top-N: highest 10 peak months. All-time records filtered by metric (yield / peak; wind hidden until A11; grid hides the card). New singles: `best_year_kwh`, `max_capacity_peak_kw`, `best_year_avg_capacity_peak_kw`. Deep link `?month=YYYY-MM` sets week (ISO week of day 1) + month + year; `?year=YYYY` sets year. Live Capacity Peak gauge on `index.php` unchanged.

### DoD

- [x] Summary + Top-N Capacity peak
- [x] Product docs
- [x] Operator close

### Verbatim â 2026-10-29 (close)

> close A12

### Verbatim â 2026-10-28 (kickoff + implement)

> Q1: both pages / Q2: third top-level metric / Q3: year + all-years only; all-years last in all modes / Q4: kW / Q5: overlay live P1 / Q6: A12 only
> not worst, only highest month, not years; 1 bar per year avg&max; replace with P1 kW; Capacity peak; 10 is ok; implement

### Verbatim â 2026-10-26

> Q4: also another phase: triage

---

## A13 â Grid import / export mode

**Status:** **Done** 2026-10-27
**Product:** [`docs/architecture.md`](../architecture.md) (UI map: `summary.php`, `topn.php`)

### Done summary

Solar yield stays the default on `summary.php` and `topn.php`. Grid mode is grouped bars: import, export, and net. A second switch shows all three, import/export only, or net only. Top-N grid ranks are the two ends of import minus export. The open local day stays out of both day ranks. `analytics.php` is unchanged (**A5**). No shared series with **A12**.

### DoD

- [x] Switches, bars, rankings, links
- [x] Product docs
- [x] Operator close

### Verbatim â 2026-10-27

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

**Status:** Done (closed 2026-11-03; shipped 2026-10-29)
**Size:** **M**
**Product:** [`docs/architecture.md`](../architecture.md) - Virtual kWh meters / `virtual_kwh_meters` / `virtual_meters.php` / `virtual_meters_debug.php`

### Shipped summary

Settings CRUD for virtual lifetime meters: `V = (S0 + C) + p*(S - S0)` with percent factor; P1 import/export/net + SMA/WebBox sources; Re-bias + confirm; Unlock-gated debug page. No warehouse writes. Raw Meter Registers display of flagged meters = **A21**.

### DoD

- [x] Table + shared calc; Settings add/edit/delete/Re-bias; debug page; no rollup writes
- [x] Product docs match; phase closed

### Verbatim (kickoff) - see inbox 2026-10-28 / 2026-10-29

---

## A21 - UI: virtual kWh meter readings

**Status:** Done (closed 2026-11-03)
**Size:** **S**
**Depends on:** **A14** (Done)
**Product:** [`docs/architecture.md`](../architecture.md) - Raw Meter Registers / optional raw rows

### Shipped summary

Index Raw Meter Registers shows virtual meters with **Show in Raw Meter Registers**. Settings **Show optional meters in raw readings** toggles Import T1/T2, Export T1/T2, Solar Today, Solar Lifetime (default on).

### DoD

- [x] Virtual meters in Raw Meter Registers modal
- [x] Optional built-in row toggles (default on)
- [x] Product docs match; phase closed

### Verbatim - 2026-11-03

> text "Show in Raw Meter Registers (flag; UI in A21)" rename to "Show in Raw Meter Registers"
> enabling this checkbox doens't show meter in "raw readings": fix
> additionally, add section: "show optional meters in raw readings"...

---

## A15 - Settings save probe integrations

**Status:** Done (closed 2026-10-29)
**Size:** **M**
**Depends on:** Settings (**A6** Done); **A20** Done.
**Product:** [`docs/architecture.md`](../architecture.md) (Settings Save probes + `*_active`)

### DoD

- [x] Partial fill blocks save (atomic: no write); clear fields -> inactive
- [x] Save re-probes filled P1/SMA/WebBox/Sunny Portal; success -> active; fail/offline -> inactive + warn/modal
- [x] Fluvius unchanged (no active/probe)
- [x] Status chips green / red / not configured
- [x] Logger skips inactive SMA/WebBox; skips P1 poll when P1 inactive; portal pull refuses when inactive
- [x] Homepage errors for each filled-but-inactive integration; empty graph when P1 inactive; hide yield when none
- [x] Grandfather missing flags as active until next Save
- [x] Failed Save keeps unlock + countdown; successful Save reloads locked; **Clear** for device/portal passwords
- [x] Product docs on close-out

### Shipped summary

`integration_probes.py`; Settings Save AJAX + `*_active` chips; logger / portal / homepage consumers. Follow-up: unlock countdown, stay unlocked on failed Save, password **Clear**. Canonical behaviour: [`docs/architecture.md`](../architecture.md) (Settings table).

### Verbatim - 2026-10-29 (consumer lock)

> if P1 is active and SMA and/or sunny is not: don't poll them - P1 is enough for energy readings, not for sunny info - simply don't display yield info if there is none - and indeed: for all integrations which are filled in but not active: display error on homepage

### Verbatim - 2026-10-29 (chips + SMA/WebBox clarify)

> what is "SMA / WebBox -- Logger skips that device." ?
> yes: add status chips (green/red/<not configured>)

### Verbatim - 2026-10-29 (kickoff round 2)

> 1: fluvius: is not active integration, this is a one-time csv upload
> 2: yes
> 3: give proposal and info
> 4: give clear error on dashboard, show as normal but with empty graph for that period
> 5: re-probe on save
> 6: yes
> 7: active, if config page & save: then probe first, then active/inactive
> 8: give proposal
> 9: ok

### Verbatim - 2026-10-29 (kickoff answers)

> 1: if filled in, they must be tested, so all
> 2: if partial fill (eg only username but no pw: give warning, don't allow to save)
> 3: if fail: save but disable integration: so additional lock in DB: integration active - only active after successfull test
> 4: warn, then save, but don't enable
> 5: first A20
> 6: ??

### Verbatim - 2026-10-28

> [open]  Settings save: probe every filled integration; fail -> modal, stay on page

---

## A20 - Settings locked-by-default / Unlock then Save-if-dirty

**Status:** Done (2026-10-29)
**Size:** **S**
**Depends on:** Settings (**A6** Done). Related: **A9** backup; **A15** probes on Save after this ship.
**Product:** [`docs/architecture.md`](../architecture.md) (Settings unlock UX)

### Placement

Affinity **A**. Page starts locked; Unlock asks admin password; timed edit window; Save only when dirty. CSV outside admin pane.

### Kickoff locks (operator 2026-10-29) - shipped

| # | Lock |
|---|---|
| 1 | Unlock window: `HP_SETTINGS_UNLOCK_SECONDS` (default 300). Expiry: clean re-lock; dirty Save/Cancel (Save asks pw). Reload/leave ends unlock. |
| 2 | Within window: Save no second modal; JS still posts unlock password. |
| 3 | Change admin disabled until unlock; no extra gate in window. |
| 4 | 1-min CSV: no password; Readings export pane. |
| 5 | Device password **Edit** kept; no admin ask in window. |
| 6 | Backup download: no pw when unlocked. Restore: always pw + confirm. |
| 7 | After successful Save: page reload locked. |

### DoD

- [x] Settings loads locked; CSV outside admin pane, no password
- [x] Unlock timed window; Save when dirty; within window Save without modal; after Save re-lock
- [x] Timer expiry: clean re-lock or dirty Save/Cancel (Save asks pw)
- [x] Device-password Edit; no admin ask inside unlock window
- [x] Backup download no pw when unlocked; restore always pw + confirm
- [x] Wrong unlock pw stays locked
- [x] A6 first-run / need-config unchanged; product docs updated
- [x] No A15 probe work in this ship

### Shipped summary

`settings.php` locks config + backup + change-admin until Unlock. Window length `HP_SETTINGS_UNLOCK_SECONDS` (default 300). Within the window: Save without a second modal (password still posted from JS); device **Edit** without admin re-ask. Timer expiry: clean re-lock or dirty Save/Cancel. After Save the page reloads locked. CSV is a separate Readings export with no password. Backup download: unlock only; restore: always pw + confirm. Product: [`docs/architecture.md`](../architecture.md).

### Verbatim - 2026-10-29 (close)

> close A20

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




## A22 - UI bugfix bundle (analytics load / Virtual best-day / PVGIS)

**Status:** Done 2026-11-03.  
**Size:** M  
**Product:** [`docs/architecture.md`](../architecture.md) � `analytics.php` � `topn.php` � `summary.php` � `pac_quality.php`

### Shipped summary

- Analytics: Clear-sky panel removed; serial explorer then compare (rAF yield); soft-fail panel errors.
- Top-N (solar): **Virtual 'best-day'** from `clear_sky_envelopes` (`ajax=bestday`), below records.
- Summary solar week/month: PVGIS daily-share line + vs-PVGIS stats.
- Powertests icon move dropped from scope. Envelope WebBox junk filter = **A24** (not A22).

### DoD

- [x] Analytics: no envelope; serial explorer/compare per L7
- [x] Top-N (solar): Virtual 'best-day' panel
- [x] Summary solar week/month PVGIS line
- [x] Docs: architecture / README / roadmap
- [x] Last DoD docs audit (close 2026-11-03)

### Verbatim

See Verbatim inbox **2026-11-03 - triage/kickoff/implement A22** and **close A22 + A24 locks**.

---

## A23 - Weather extremes on daily/monthly rollups

**Status:** **Done** 2026-11-03 (Last DoD / operator close)  
**Size:** **M**  
**Product:** [`docs/architecture.md`](../architecture.md) � `rollup.py` � `weather.php`

### Done summary

Daily gained `wind_speed_max_ms`; monthly weather columns use the same names as daily and are filled from daily in `-daily` / `-buildall`. `weather.php` Week/Month read `daily_history`; Year reads `monthly_history` (open day/month may merge live `readings`). Legacy monthly `max_temp_module` / `max_wind_gust` unused on older DBs. Operator `-buildall` after sync is the one-time rewrite step (L6).

### Locked decisions (record)

| # | Lock |
|---|---|
| L1-L8 | As locked 2026-11-03 (no weather_* tables; aligned daily/monthly weather cols; one ship UI cutover; `-buildall` after sync; W2 out). |

### DoD

- [x] Daily schema + fill includes `wind_speed_max_ms`; gust not coalesced with wind
- [x] Monthly schema + fill uses aligned weather column names from daily
- [x] `-daily` and `-buildall` both write the new weather extremes
- [x] `weather.php` Week/Month/Year read daily/monthly
- [x] Architecture / phase docs match shipped behaviour
- [x] Operator close / Last DoD (2026-11-03); `-buildall` after sync is operator rebuild step

### Verbatim

See Verbatim inbox **2026-11-03 - A23 triage + kickoff**, **A23 kickoff answers + A11 close**, and **close A23**.

---

## A24 - bugfix: clear_sky_envelopes sun gate (WebBox kept)

**Status:** **Done** 2026-11-03 (amended rad gate; operator close).  
**Size:** S  
**Product:** [`docs/architecture.md`](../architecture.md) � `rollup.py` `build_clear_sky_envelopes`

### Shipped summary

Envelope rebuild keeps all `data_source` values (including `webbox_archive`). Adds envelope-only  
`COALESCE(solar_radiation_max, solar_radiation, 0) > PAC_RAD_FLOOR` on top of existing PAC night/shoulder gates. Status line: `rad>?`. Refresh via `-buildall` only. June evening jump left to **A26**.

### Locked (amended)

| # | Lock |
|---|---|
| L1' | Do **not** ban `webbox_archive` from envelopes (historical coverage). |
| L2 | No WebBox timestamp rewrite in this phase. |
| L3 | Operator **`rollup.py -buildall`** after sync. No `-buildallenvelopes`. |
| L7 | Envelope-only require radiation above `PAC_RAD_FLOOR`. |

### DoD

- [x] Envelope SQL requires rad > floor; WebBox included
- [x] Docs match amended behaviour
- [x] Operator `-buildall` + smoke (accepted on `close A24`)
- [x] Last DoD docs audit (`architecture.md` / `roadmap.md` / pipeline)

### Verbatim

See Verbatim inbox **A24 triage/kickoff**, first implement, **rad gate amend**, and **close A24**.

---

## A25 - weather.php All years card

**Status:** **locked** 2026-11-03 - **A24** Done; say `implement` to code.  
**Size:** S  
**Depends on:** **A23** Done (`monthly_history` weather extremes). **A24** Done (L7 satisfied).

### Placement

Affinity **A**. Follow-on to A11/A23: Summary has All years; weather currently stops at Year.

### Locked

| # | Lock | Source |
|---|---|---|
| L1 | Card after Year; static **All years** chrome (title only; no prev/next / Jump) - Summary-style. | operator "1: ok" |
| L2 | Aggregate per calendar year from `monthly_history`: `MAX` of monthly maxes; `MIN` of `temp_ambient_min`. No new tables / no rollup schema change. | operator "2: confirmed" |
| L3 | Rollup only - do **not** merge live `readings` into the open year. | operator "3: rollup only" |
| L4 | Same six period series + channel gates as Week/Month/Year (Wind max, Gust, Ambient max/min, Module max, Radiation max). | operator "4: yes" |
| L5 | Keep AJAX consistency: `ajax=period&mode=allyears` (plus SSR first paint). | operator "5: keep consistency" |
| L6 | Year axis spans first..last calendar year with any `monthly_history` row in scope; years with no weather extremes stay as **null** bars (not omitted, not zero). | operator "6: keep null for empty years" |
| L7 | Implement only after **A24** closed. | operator "7: after A24" - **satisfied** |

### Open questions

None.

### DoD

- [ ] `weather.php` All years card after Year; no nav arrows
- [ ] Year bars from `monthly_history` extremes (L2/L3/L6); same series gates (L4)
- [ ] SSR + `ajax=period&mode=allyears` (L5)
- [ ] Architecture `weather.php` row documents All years
- [ ] Last DoD docs audit

### Verbatim

See Verbatim inbox **2026-11-03 - triage A25** and **A25 kickoff answers**.

---

## A26 - bugfix: clear_sky envelope harden (June evening jump)

**Status:** open (triaged 2026-11-03). Kickoff not started.  
**Size:** S  
**Depends on:** **A24** Done (keep WebBox + rad>floor). Distinct from WebBox timestamp rewrite.

### Verified facts (NAS SQL 2026-11-03)

- June Virtual 'best-day' 21:10-21:55 spike owned by **`2016-06-24` `webbox_archive`**.
- Same day: 21:05 = 62 W / rad ~14 -> 21:10 = 1189 W / rad ~105 (discontinuity).
- 21:00-21:55 PAC>200: high winners (>900 W) are **month 6 only**; May/Jul ~200-440 W.
- Cliff at 22:00 = existing deep-night gate. A24 rad floor does not remove this (rad >> 5).

### Candidate harden options (pick at kickoff)

| # | Option | Note |
|---|---|---|
| 1 | Stronger step despike / jump reject | **Preferred in advice** |
| 2 | Month dusk cutoff table | Blunt |
| 3 | PAC-rad consistency model | Heavier |
| 4 | Same-day no re-rise after local min | Good second |
| 5 | Surgical denylist day/slots | Hotfix only |
| 6 | webbox_archive hour cap | Punishes archive evenings |

### Open (placement)

None. Spec locks at `kickoff A26` (which option(s), thresholds, `-buildall` refresh).

### Verbatim

See Verbatim inbox **2026-11-03 - triage A26**.

---

## Out of scope (until re-triaged)

- Portal ingest (**P1-P3**)
- Restoring the old slow Top 10 AJAX query as-is
- Fixing WebBox archive clock/timezone at source (re-triage if needed)
