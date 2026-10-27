# --- file: docs/todo/phaseP-portals.md ---
# Phase P — Cloud / utility portal ingest

**Status:** P3 Done. P2 closed as P1 duplicate. P1 **coding** 2026-10-27 (pull CLI + rollup wire + `.env` cleanup shipped; non-PV map after `-probe`).  
**Last updated:** 2026-10-27 (P1 implement)  
**Product pointers:** [`docs/architecture.md`](../architecture.md) · `logger.py` (local SMA/WebBox already — **not** this track)

---

## Affinity

Pull historical / periodic data from **cloud or utility portals** into the warehouse (staging or derived — TBD at kickoff). Distinct from LAN SMA WebConnect / Sunny WebBox polling.

---

## Sequence (this letter)

| Id | Status | What |
|---|---|---|
| **P1** | coding | Sunny Portal / ennexOS cloud ingest (same as former P2) |
| **P2** | Done | Duplicate of P1 — closed 2026-10-27 |
| **P3** | Done | Fluvius manual CSV into `fluvius_interval`. Product: [`docs/architecture.md`](../architecture.md) |

**Source / auth:** ennexOS via unpaid portal login (paid Monitoring API out). **Target tables / channel map:** see P1 locks; non-PV column map still open.

**Depends on:** none across P1–P3 (P2 closed; P3 Done).

---

## Verbatim inbox

### 2026-10-22 — triage (initial)

> triage pull from sunnyportal and/or SMA portal

### 2026-10-23 — placement + split

> letter P
> split in three phases: sunnyportal, SMA portal and energy portal from fluvius
> source: tbd at kickoff

### 2026-10-25 — kickoff P1

> kickoff P1
> https://ennexos.sunnyportal.com
> is there an API? I have login & PW for https://portal.sma.energy/login which gets forwarded to ennexos

### 2026-10-25 — API out, CSV instead

> the API is payable
> check options to pull data from the website
> https://ennexos.sunnyportal.com/13941795/monitoring/view-energy-and-power
> C:\data\downloads\Energy and power - PV - Day - Hofmans Vic - 2026-10-06.csv
> there is a button there which allows download of CSV
> this is a one-time import of historical data, we get live data from P1 ourselves

### 2026-10-25 — automate, no hand-dropped CSVs

> don't want to drop CSV, that is too much work - automate this
> create test-script for this

### 2026-10-25 — pull on another machine

> cool; then it works - i will run this one-time-import on a different machine then where the DB sits - so create a script that pulls ALL data and puts it in a DB table, then this DB table must be transferreed to the real DB somehow, give insight

`sunnyportal_pull.py` writes a standalone `sunnyportal_pv.sqlite` (PV channel only, every component that returns samples). It opens `meter_history.db` only to read the Settings page (user, password, plant id, timezone). Copy the closed sample file to the dashboard host and `ATTACH` it. Do not merge into `history_5min` on the pull machine.

### 2026-10-26 — kickoff P3

> kickoff this P3, don't implement step 2 (automatic importer) - which table is needed additionaly, give what needs to be added/changed - any open items or questions?

### 2026-10-27 — kickoff P1 + P2

> kickoff P1 & P2 - they are the same, no? that is this ennexos?

### 2026-10-27 — P1 lock answers

> 1 yes
> 2 confirm
> 3 fill all that is not presently in the db
> 4 pull all the portal will give

### 2026-10-27 — re-assess three workstreams

> ok, we need three things:
> - functionality of the current sunnyportal_pull.py as "initial import" as single py file, then merge/copy that into meter_history.db
> - same functionality to grab gaps if needed, probable only last hours/days (when could this be needed?) - merged into that table in our meter_history.db
> - check other "channels" within either portal
> re-assess

### 2026-10-27 — CLI / staging locks

> 1: propose name - no, buildall must not fill this table, this table is filled from the sunnyportal_pull.py - this file launched without switches will do nothing, add a -pullall to have today's functionality
> 2: will be a switch -lastdays -n 7 (pulls last week from today) n can be 1-366
> 3: -probe first, lets see which cnalles there are, next we'll see what we can do
> 4: see above

### 2026-10-27 — table / buildall / lastdays / Sofie file

> 1 ok
> 2 ok, change: buildall parses sunnyportal_pv_5min  and wires it into history_5min: update the wiring plan - cfr the fluvius fill
> 3 include today
>
> -pullall: currently sofie is working with the current script to put everything into sunnyportal_pv.sqlite -- this must be merged into our db: how

### 2026-10-27 — csv_test + daily

> sunnyportal_csv_test.py is no longer usefull?
> yes, daily should also empty-fill from staging

### 2026-10-27 — remove csv probe

> remove the csv importer
> sunnyportal_pull.py should use the credentials in the meter_history.db
> update docs (including power-notes.md)

### 2026-10-27 — fold .env reader cleanup

> ok, fold cleanup of those old .env readers into this phase
> any open Qs or items?

### 2026-10-27 — close remaining open Qs

> 1 ok
> 2 console only
> 3 write straight into meter_history.db (is that ok, concorrent-sqlite-wise?)
> 4 ok
> 5 ok

### 2026-10-27 — implement

> one-shot ATTACH of Sofie’s current portable file is done
> non-PV map after -probe: we 'll get to that once I do the probe
> implement

---

## P1 — Sunny Portal / ennexOS

**Status:** coding 2026-10-27 — PV pull + empty-fill shipped; non-PV map waits on `-probe`  
**Scope:** historical (+ on-demand gap) ingest from Sunny Portal powered by ennexOS (`https://ennexos.sunnyportal.com`; `https://portal.sma.energy/login` forwards there). Same surface as former **P2**. Live PV / grid / home stay on the P1 logger. Paid Monitoring API out. There is **one** portal, not two.

### Locks (operator 2026-10-27)

1. **P2** is a duplicate of **P1**. Close P2 (Done).
2. **Staging table:** `sunnyportal_pv_5min` in `meter_history.db`. Never purged. Filled **only** by `sunnyportal_pull.py`. `-buildall` must **not** write this table.
3. **`-buildall` / `-daily` / Fluvius-style wire:** after readings downsample and WebBox consolidate, **read** `sunnyportal_pv_5min` and fill `history_5min.solar_power_w` only where that field is still empty. `-buildall`: full table. `-daily`: same empty-fill for the Fluvius stitch calendar span (last 2 local days). Do not overwrite P1/WebBox solar. Stamp = `interval_start`. Use the component that has samples (probe: `13941800`); ignore empty plant-id rows. No `solar_total_kwh` from this channel; daily yield uses the PAC integral.
4. **`data_source`:** portal-only new rows use `sunnyportal`. When only filling a null `solar_power_w` on an existing row, leave that row’s `data_source` unchanged (Fluvius-style).
5. **Fill rule (intent):** portal series the warehouse can take **and** that are not already present — non-PV channel set TBD after `-probe` results (PV first).
6. **CLI (single file `sunnyportal_pull.py`):**
   - No switches → usage only / exit.
   - `-pullall` → full-history pull **straight into** `sunnyportal_pv_5min` in `meter_history.db` (default DB via `site_config.resolve_db_path()` / `HP_DB_FILE`).
   - `-lastdays` with `-n N` → last **N** local calendar days **including today**; **N** in **1..366**.
   - `-probe` → list channels to **console only** (no JSON dump file).
7. **Who fills staging:** `sunnyportal_pull.py` only. One-shot ATTACH of an existing Sofie `sunnyportal_pv.sqlite` remains valid as a **migration** of that already-pulled file; new pulls do not use a portable sqlite as the primary path.
8. **`.env` device/portal import cleanup (in P1):** remove dead one-shot maps/helpers that copy device or Sunny Portal keys from `.env` into `app_settings`. Keep `.env` / `read_env()` only for `DB_BACKUP_ENCRYPTION_KEY` (`db_backup.py`). Mirror in PHP `site_settings.php`. Drop `import_env_if_empty` call sites (keep `ensure_schema` where needed). Update architecture / deploy / power-notes.
9. **Implement order:** (a) `.env` cleanup + docs → (b) CLI `-pullall` / `-lastdays` / `-probe` + default = usage → (c) rollup empty-fill on `-buildall` / `-daily` → (d) channel map after `-probe` results.

### SQLite concurrency (lock note for implement)

**Yes — writing straight into `meter_history.db` is OK** if the pull follows the same pattern as the logger/rollup:

- Live DB already uses **WAL** (`logger.py` / `rollup.py`) and **busy timeouts** (10–30 s). Readers (PHP) and the 1-min logger can coexist with short writers.
- Commit **one local day per transaction** (already how the pull stores). Do not hold a multi-day write lock. Open with `timeout=` + `busy_timeout` like the other CLIs. Do **not** force `journal_mode=DELETE` on the live DB (that was only for the portable copy file).
- Network wait between days is outside the transaction — good.
- Avoid overlapping a long `-pullall` with `-buildall` / restore. `-lastdays` is light.

[Certain] WAL + short per-day commits matches how this host already runs. [Likely] minute logger polls will only briefly wait if they hit a commit.

### Wiring plan (`history_5min` rebuild) — cfr Fluvius

Same pattern as Fluvius: never-purged SoT table → rollup **reads** it → empty-only columns on `history_5min`. Portal does not own the row when solar already exists.

| Step | `-buildall` | Notes |
|---|---|---|
| 1 | `DELETE` `history_5min` / daily / monthly | Unchanged |
| 2 | `readings` → `history_5min` | Unchanged (skip open 5-min slice) |
| 3 | WebBox staging → solar / weather | Unchanged (`webbox_archive`) |
| 4 | **`sunnyportal_pv_5min` → empty `solar_power_w` only** | **New.** Analog to Fluvius stitch, but solar W not grid kWh. Insert row if timestamp missing; if row exists and `solar_power_w` IS NULL, set it. Leave non-null solar alone. |
| 5 | Fluvius electricity quarters → grid columns | Unchanged (after solar so WebBox/`data_source` rules stay coherent) |
| 6 | daily / monthly / envelopes / tops | Unchanged |

**`-daily` (locked):** after readings downsample, also empty-fill solar from `sunnyportal_pv_5min` for the same recent calendar span as the Fluvius stitch (last 2 local days), so `-lastdays` staging shows up without a full `-buildall`.

**`-purge_derived`:** keep `sunnyportal_pv_5min` (add to the never-purged source list next to WebBox + Fluvius when coding).

### Sofie portable file → `meter_history.db` (now)

Current Sofie run writes `sunnyportal_pv.sqlite` (table `sunnyportal_pv_5min`). That file is already the right schema. When the pull finishes (one file, no `-wal`):

1. Copy `sunnyportal_pv.sqlite` next to `meter_history.db` on the dashboard host (or run ATTACH on Sofie if that **is** the host and both files sit together).
2. Load into the real DB (creates the table if missing):

```sql
ATTACH 'sunnyportal_pv.sqlite' AS src;
CREATE TABLE IF NOT EXISTS sunnyportal_pv_5min (
    component_id TEXT NOT NULL,
    interval_start TEXT NOT NULL,
    interval_end TEXT NOT NULL,
    power_w REAL,
    PRIMARY KEY (component_id, interval_start)
);
INSERT OR REPLACE INTO sunnyportal_pv_5min
    SELECT component_id, interval_start, interval_end, power_w
    FROM src.sunnyportal_pv_5min;
DETACH src;
```

3. Portal solar appears on charts only after the **wiring** exists and you run `rollup.py -buildall` (or `-daily` if that stitch is locked). Until then the staging table is SoT only.

Do not open the live `meter_history.db` from two machines at once. Do not merge into `history_5min` by hand.

### Verified (public SMA docs, 2026-10-25)

- There is an official API. **SMA Monitoring API** covers both Sunny Portal Classic (`www.sunnyportal.com`) and Sunny Portal powered by ennexOS (`ennexos.sunnyportal.com`). Catalogue: [developer.sma.de/sma-apis](https://developer.sma.de/sma-apis). Swagger: [sandbox.smaapis.de/monitoring](https://sandbox.smaapis.de/monitoring/index.html). Production host: `https://monitoring.smaapis.de`.
- Plant id shape tells the backend apart: Classic = GUID, ennexOS = numeric. The client does not pick a portal host.
- ennexOS uploads are normally every 5 minutes and processed on the fly. The client cannot request a resolution.
- **Grain** (Monitoring API OpenAPI, sandbox spec read 2026-10-25). Plant `GET /v1/plants/{plantId}/measurements/sets/{setType}/{period}`:

  | Period | Grain | Unit |
  |---|---|---|
  | `Recent`, `Day`, `Week` | Highest the plant has (ennexOS normally 5 min; examples in the spec include 15 min and 1 h) | W (mean) |
  | `Month` | One value per day | Wh |
  | `Year` | One value per month | Wh |
  | `Total` | One value per year since plant start | Wh |

  `Date` query: `Day`/`Week` = `YYYY-MM-DD`, `Month` = `YYYY-MM`, `Year` = `YYYY`. `Recent` and `Total` take no date.
- Timestamp in the schema is **plant-local** time. SMA’s FAQ calls it `UTC_END`: it is the **end** of the bucket, and the number is the mean over the interval before that stamp. Do not treat the clock as UTC. The earlier “UTC” note in this file was wrong and is replaced by this sentence.
- Plant set types: `EnergyBalance` (all periods), `EnergyAndPowerPv`, plus `Sensor` and `GridSettings` (**Recent and Day only**). `Sensor` follows the portal sensor assignment (satellite default or an external sensor). Channels the plant does not have are omitted, not returned as null.
- `EnergyBalance` fields that matter here: `pvGeneration`, `totalConsumption`, `directConsumption`, `gridFeedIn`, `gridConsumption`, `batteryCharging`, `batteryDischarging`, `batteryStateOfCharge` (SoC only on Recent/Day/Week), `selfConsumption`. Diesel / CHP / hydro exist in the schema and will be absent if the plant has none.
- Device sets (`PowerAc`, `PowerDc`, `Sensor`, …) are finer. `PowerAc` / `PowerDc` / device `Sensor` are **Recent and Day only**. Portal Analysis Pro no longer shows extended AC/DC and battery detail older than 100 days (SMA America product page, 2026). That cutoff is a UI statement; the OpenAPI text does not repeat it.
- How far back `Day`/`Week` still return ~5 min is **not** in the OpenAPI. Portal Data Export (SMA America, update Sept 2026) can download up to one year of Energy Balance, including an intraday power CSV per year, current year only through the previous month. That is the CSV page, not an API guarantee. First CLI run must probe.
- SMA states Sunny WebBox plants are **not** migrated to ennexOS. The local WebBox archive stays the pre-ennexOS history. Cloud ingest does not replace it.
- There is an older limited **BasicReporting** API (`basicreporting.smaapis.de`) whose week samples come back hourly and whose month samples are daily totals. That is not the Monitoring API. Do not use it for this backfill.
- **Portal login + password is not an API credential.** SMA issues `client_id` / `client_secret` via API Developer Support (`api-developer-support@sma.de`) under a contract. Access is OAuth2. A client-credentials token still returns nothing until the **plant owner** grants consent (sync code-grant, or async email via `bc-authorize`). Consent is revoked at `ennexos.sunnyportal.com/configuration/view-third-party-apps`. The consent account must have the ennexOS **owner** role.
- From 2025-07-01 SMA rate-limits requests per credential inside a 5-minute window. Exact cap not copied here (not stated in the pages read).
- A separate **Live API** exists for near-time EnergyBalance on WebConnect. SMA says it is not for constant monitoring. Not assessed as the P1 source.
- **Official Monitoring API is out of P1.** Operator 2026-10-25: it is payable. No client credentials, no daily API poll.

### CSV sample (operator file, day 2026-10-06, read 2026-10-25)

Monitoring page `.../monitoring/view-energy-and-power`, channel PV, period Day. Not the paid API.

- `sep=;`, quoted fields, en-US. Metadata header, then `"Time period";"Power [W]"`.
- One column of power. No date column. `"Local export date"` is when the button was clicked, not the day of the curve. The day is the `YYYY-MM-DD` in the filename.
- Clock is 12-hour with a dot: `12.05 AM`. Values use a thousands comma (`1,092`). Header time zone on this file: `Europe/Paris` (same offset as the host CET zone).
- Stamps are bucket **ends**: first `00:05`, last `24:00` written as `12.00 AM`. A full day is 288 stamps. This file has **287**. The missing one is `20:05`. Do not interpolate the hole.
- Map into the warehouse as interval **start** on the filename date: `00:05` → `00:00`, final `12.00 AM` → `23:55` that same date. Header identity fields (account email, system name, system id) stay out of the database and out of git.
- This sample is PV only. Grid and home usage for the live path stay on the P1 logger.

SMA also documents two other downloads, not used by this file:

- Analysis Pro → Details → Download. The manual describes that details table as 15-minute means. Different page, coarser than this sample.
- Configuration → Data Export (portal update Sept 2026): up to one year of Energy Balance per download, plus an intraday power CSV per year; current year only through the previous month. Intraday resolution is **not** stated. Not confirmed on this plant.

### Operator answers (2026-10-25; superseded where 2026-10-27 locks differ)

1. **Source:** only `portal.sma.energy` / ennexOS. No second Classic plant. (**P2 closed as duplicate 2026-10-27.**)
2. **Pull:** paid API rejected. Do not hand-drop CSVs. One-time historical pull, automated. Live PV / grid / home stay on the P1 logger. No daily portal job. The earlier “config page + daily check/fill” answer is superseded.
3. **Probe result (2026-10-25, day 2026-10-06):** `measurements/search` returned three rows. System id `13941795` and `13941796` had empty `values`. Component `13941800` had 287 samples. Converted from UTC to `Europe/Paris`, those clocks and watts match the Day CSV exactly (max abs diff 0, peak 2256 W). Both sides omit `20:05`. The stamp is the CSV clock, which is the bucket **end** (`22:05Z` = local `00:05`). Header `max` on that row is 3000 and is not the peak of the samples. For `history_5min`, subtract 5 minutes so the value sits on the interval start.
4. **Warehouse:** staging + empty-only merge — **locked 2026-10-27** (see Locks above). Channel set beyond PV still open.

### Warehouse (locked shape; PV proven, other channels TBD)

Importer calls the same `measurements/search` the chart uses (see the probe). One-shot CLI. No portal password in git. No hand-dropped CSV folder.

Do **not** write `readings`. Do **not** overwrite a `history_5min` field that already has a value (WebBox, P1, Fluvius, or earlier portal fill).

Never-purged staging SoT, then consolidate into empty target fields only. For the proven PV channel: interval start + PV watts → `history_5min.solar_power_w` where null. No `solar_total_kwh` on this channel, so daily yield for those days uses the existing PAC integral (`SUM(solar_power_w) * 5/60/1000`), not an E-total delta. Missing stamps stay missing.

`sunnyportal_pull.py` owns login + `measurements/search` (no separate CSV probe). It logs in over HTTP with the same SMA ID form the website uses (no Playwright). It will break when SMA changes the login form. That is the cost of staying off the paid API. User, password, plant id, and timezone come from `app_settings` in `meter_history.db` (Settings). Samples go into `sunnyportal_pv_5min` on that DB. Rollup empty-fills `history_5min.solar_power_w`. **`sunnyportal_csv_test.py` removed 2026-10-27.**

Night zeros that the API returns are real zeros. Missing stamps stay missing. There is no lifetime energy counter on this PV channel, so those days get their yield from the power integral already in the rollup.

### Re-assess — three workstreams (2026-10-27)

**Existing code (after implement):** `sunnyportal_pull.py` + rollup empty-fill. Operator run on Sofie found portal PV from **2024-06-19** onward (component `13941800`).

| # | Ask | Assessment |
|---|---|---|
| **A** | Initial import = current pull as one py, then into `meter_history.db` | **Keep.** Pull (or ATTACH) into a **never-purged** staging table in `meter_history.db`, then empty-only consolidate into `history_5min`. Staging must survive `-purge_derived` and be a **source for `-buildall`**, or the next rebuild wipes portal-filled solar. Do not write `readings`. |
| **B** | Gap grab last hours/days into that same staging table | **On-demand** `-lastdays -n N` (includes today; N 1..366). Not a scheduled daily job. Needed on logger/NAS outage while cloud still got uploads. Empty-field merge into `history_5min` via rollup wire only. |
| **C** | Check other channels / “either portal” | **One portal** (ennexOS). Classic is not in play. Channel probe is still missing; only PV is verified. Other Energy Balance series may exist; warehouse columns for consumption/grid are awkward (Fluvius/P1 already own grid; no 5-min home-load column). Probe first; map second; do not invent columns in the same breath as the probe. |

**Hard constraint:** portal samples that only land in `history_5min` and not in never-purged staging are lost on `-buildall` / `-purge_derived`. Same pattern as WebBox staging and `fluvius_interval`.

### Shipped in this implement (2026-10-27)

- Removed dead `.env`→`app_settings` importers (`site_config.py`, `site_settings.php`, call sites).
- `sunnyportal_pull.py`: `-pullall` / `-lastdays -n` / `-probe`; default = usage; writes `sunnyportal_pv_5min` on live DB (WAL).
- `rollup.py -buildall` / `-daily`: empty-fill `solar_power_w` from staging (preferred component by row count); new rows `data_source` `sunnyportal`.
- Docs: architecture, deploy, power-notes, pipeline, this file, cursor rule.

### Still open

1. **Non-PV channel map** — run `python3.14 sunnyportal_pull.py -probe`, then lock what (if anything) besides PV enters the warehouse.
2. Operator smoke: `-lastdays -n 1` or `-pullall` resume, then `-daily` or `-buildall` and confirm empty solar slots fill.

### Sofie note

Portable ATTACH already done. Future pulls: `python3.14 sunnyportal_pull.py -pullall` or `-lastdays -n N` on the host (same DB as the dashboard).

Merge staging into `history_5min` without a full rebuild:

```bash
python3.14 rollup.py -buildallsunnyportal
```

Prints staging component, 5-min written (inserted/updated/skipped), first and last written stamp.

### 2026-10-27 — buildallsunnyportal

> add a rollup -buildallsunnyportal -- which will only execute the last step : merge sunnyportal into the 5min table
> add summary when tool ends: how many records (5min?) were imported into the 5min - which was the first? the last? etc

---

## P2 — SMA portal (duplicate of P1)

**Status:** **Done** 2026-10-27 — closed as duplicate of **P1**  
**Scope:** was “SMA portal ingest”; same login as ennexOS Sunny Portal (`portal.sma.energy` → `ennexos.sunnyportal.com`). No second Classic plant.

### Done summary

Operator confirmed P1 and P2 are the same portal. All cloud-history work for this site lives under **P1**. No separate P2 source, auth, or tables.

---

## P3 — Fluvius Energy Portal

**Status:** **Done** 2026-10-26 (NAS CSV import smoked; `-buildall` not run)  
**Product:** [`docs/architecture.md`](../architecture.md) (Fluvius paragraphs, Rollup CLI)

### Done summary

Manual CSV only, into `fluvius_interval`, for the electricity and gas EANs on the Settings page. `-buildall` and `-daily` stitch electricity quarters onto `history_5min` (`grid_import_kwh`, `grid_export_kwh`) and use Fluvius for a fallen-out day's grid total. Gas and the monthly peak stay in `fluvius_interval`. No portal login script. No chart. NAS smoke on 2026-10-26 imported the real downloads (10 files moved to `processed/`). Two cut `*-truncated.csv` copies stayed in the queue because a blank validation cell rejects the file. `-buildall` was not part of that smoke.

### Out of scope

- Fluvius portal script (step 2).
- Drawing the quarters (**A5**).
- Pages for historical gas and the monthly peak (**A11**, **A12**).

### Verbatim — 2026-10-26 (close)

> close phase

### Verbatim — 2026-10-26 (kickoff)

> kickoff this P3, don't implement step 2 (automatic importer) - which table is needed additionaly, give what needs to be added/changed - any open items or questions?

### Verbatim — 2026-10-26 (locks)

> Q1: EANs to be entered in the settings page - fluvius_import will import only CSVs for those EANs so no errors occur
> Q2: confirm
> Q3: hitorical gas, wind speed, etc is not for this phase - tirage that into another phase
> Q4: also another phase: triage
> Q5: Q5 is seperate
> write docs, no code

### Verbatim — 2026-10-26 (backfill)

> we need to rethink this - first part is easy: fill historical data
> second part is trickier: it is used as backfill where P1 (or webbox) has no data. if 3 consecutive 5min records are missing: then the 15min record from fluvius should be used (for both injection and export, where available)
> if a whole day falls out (and this is where those numbers come in I assume): then also the 15min readings if available are used and if not the day reading is used
> for gas: this becomes hours and days
> - agree? what is the impact of this?

The 30-minute hole was dropped from the fall-out test. A long hole is stitched quarter by quarter. Fall-out stays the midnight brackets and a counter step down over 0.01 kWh. Product text: [`docs/architecture.md`](../architecture.md).

### Verbatim — 2026-10-26 (curve columns + EANs)

> A10 smoke done: close
> EANs (elek+gas) should be in settings page so that fluvius csv importer (CLI only) will import only from that EAN
> confirm
> re-assess

---

## Out of scope (until re-triaged)

- Changing local `logger.py` SMA / WebBox poll behaviour
- Replacing WebBox ZIP / `sql_importer` archive path without an explicit kickoff lock
- Fluvius portal script (P3 step 2)
- Drawing Fluvius quarters on the day chart (**A5**)
- Pages for historical gas, wind, and the monthly peak (**A11**, **A12**)
