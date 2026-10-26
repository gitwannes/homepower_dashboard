# --- file: docs/todo/phaseP-portals.md ---
# Phase P — Cloud / utility portal ingest

**Status:** P3 kickoff locked, no code. P1 kickoff in progress. P2 queued.  
**Last updated:** 2026-10-26 (P3 backfill — stitch empty quarters, fall-out uses Fluvius)  
**Product pointers:** [`docs/architecture.md`](../architecture.md) · `logger.py` (local SMA/WebBox already — **not** this track)

---

## Affinity

Pull historical / periodic data from **cloud or utility portals** into the warehouse (staging or derived — TBD at kickoff). Distinct from LAN SMA WebConnect / Sunny WebBox polling.

---

## Sequence (this letter)

| Id | Status | What |
|---|---|---|
| **P1** | kickoff | Sunny Portal cloud ingest |
| **P2** | open | SMA portal ingest |
| **P3** | kickoff, locked, no code | Fluvius manual CSV into `fluvius_interval`. No portal script |

**Source / API / auth / target tables:** TBD at each kickoff (operator lock). Do not assume APIs or credentials.

**Depends on:** none across P1–P3 (may run in any order after kickoff; ask if parallel).

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

---

## P1 — Sunny Portal

**Status:** kickoff (pull script written; not locked into `history_5min`)  
**Scope (placement only):** ingest from Sunny Portal cloud. Operator URL for this kickoff: `https://ennexos.sunnyportal.com`. `https://portal.sma.energy/login` forwards there.  
**Locks:** none yet.

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

### Operator answers (2026-10-25, not a full lock)

1. **Source:** only `portal.sma.energy` / ennexOS. No second Classic plant. P2 still needs an explicit close or keep.
2. **Pull:** paid API rejected. Do not hand-drop CSVs. One-time historical pull, automated. Live PV / grid / home stay on the P1 logger. No daily portal job. The earlier “config page + daily check/fill” answer is superseded.
3. **Probe result (2026-10-25, day 2026-10-06):** `measurements/search` returned three rows. System id `13941795` and `13941796` had empty `values`. Component `13941800` had 287 samples. Converted from UTC to `Europe/Paris`, those clocks and watts match the Day CSV exactly (max abs diff 0, peak 2256 W). Both sides omit `20:05`. The stamp is the CSV clock, which is the bucket **end** (`22:05Z` = local `00:05`). Header `max` on that row is 3000 and is not the peak of the samples. For `history_5min`, subtract 5 minutes so the value sits on the interval start.
4. **Warehouse:** suggestion below. Not locked.

### Warehouse suggestion (pending confirm)

Importer calls the same `measurements/search` the chart uses (see the probe). One-shot CLI. No portal password in git. No hand-dropped CSV folder.

Do **not** write `readings`. Do **not** overwrite a `history_5min` slot that already has WebBox or P1 solar. `-daily` will otherwise treat the row as logger-owned and can rewrite `data_source`.

Keep a small SoT staging table (survives `-purge_derived`): interval start and PV watts. Then one consolidate copies into `history_5min.solar_power_w` only where that slot is still empty. No `solar_total_kwh` on this channel, so daily yield for those days uses the existing PAC integral (`SUM(solar_power_w) * 5/60/1000`), not an E-total delta. Missing stamps stay missing.

The probe (`sunnyportal_csv_test.py`) logs in over HTTP with the same SMA ID form the website uses (no Playwright; that package has no build on the Pi), takes an access token, then calls `measurements/search` for the day. `sunnyportal_pull.py` repeats that call per day. It will break when SMA changes the login form. That is the cost of staying off the paid API. User, password, plant id, and timezone live on the Settings page (`sunnyportal_user`, `sunnyportal_password`, `sunnyportal_plant_id`, `sunnyportal_timezone`). The password is not in git. An empty timezone means `Europe/Paris` in the scripts. Samples stay in `sunnyportal_pv.sqlite` until a later lock says how they enter `history_5min`.

Night zeros that the API returns are real zeros. Missing stamps stay missing. There is no lifetime energy counter on this channel, so those days get their yield from the power integral already in the rollup.

### Still open

1. Confirm staging, then fill empty `solar_power_w` only. Stamp rule from the 2026-10-06 compare: store interval start = API local time minus 5 minutes. Use the component row that actually has `values` (`13941800` on this day), not the empty system-id row.
2. Span (first year / last year) is still unknown. The one-day compare is done.
3. PV only, or also grid and consumption for the years before the P1 logger?
4. P2: close as duplicate, or keep?
5. A5–A7 are still ahead of P1. Continue in parallel?

---

## P2 — SMA portal

**Status:** open (not kicked off)  
**Scope (placement only):** ingest from SMA portal (cloud / account — not LAN WebConnect).  
**Locks:** none yet — kickoff required before code.

---

## P3 — Fluvius Energy Portal

**Status:** kickoff, locked 2026-10-26, no code  
**Size:** **L**  
**Depends on:** none. Parallel with A5–A10 and P1–P2. Display of gas, wind, and monthly peak is **A11** / **A12**. The multi-series chart stays **A5**.

### Locked

Manual CSV only. Step 2 (a script that logs into the portal and pulls the last x days) is out. The P1 logger is already the finer source for every day it ran. A portal session has no stable consumer API in what we have seen, and recent gas kWh is often still `Voorlopig`.

Settings holds the electricity EAN and the gas EAN. `fluvius_import` imports a row only when its EAN matches one of those two. Any other EAN in a file is skipped and is not an error. A file that contains none of the configured EANs is still moved to `processed/` after that skip. If both settings are empty, the import stops before reading files and says so. No EAN is hardcoded and none go into git.

Backfill comes after the CSV is stored. Historical days with no `readings` and days that fall out use the same path. A passing day keeps its counter total and only borrows Fluvius where a whole quarter is empty. Details are under **Backfill** below.

P3 does not draw the day chart. **A5** does. P3 does not add historical gas, wind, or the monthly peak to any page. **A11** and **A12** do. Gas and peak rows for the configured EANs are still stored here.

### One additional table

`fluvius_interval`. Nothing else. It is source of truth. `-purge_derived` and `-buildall` must not delete it. Peak fits in this table: same CSV shape, resolution `month`, unit `kW`.

| Column | Role |
|---|---|
| `ean` | Digits only. The Excel `="5414…"` wrapper is stripped. |
| `register` | `afname_dag`, `afname_nacht`, `injectie_dag`, `injectie_nacht`, `afname` (gas), `piekvermogen` |
| `unit` | `kWh`, `m3`, `kW` |
| `resolution` | `15min`, `hour`, `day`, `month` |
| `interval_start` | `Van`, local `YYYY-MM-DD HH:MM:SS` |
| `interval_end` | `Tot`, same form. Gas day is 06:00–06:00. DST moves that end to 05:00 or 07:00. Electricity day is calendar midnight, with the spring row running to 01:00 next day and the fall row stopping at 23:00. |
| `occurrence` | 0, then 1, for a DST fall-back clock that appears twice. Order is file order. |
| `volume` | Number, including `0`. `Geen verbruik` with a blank volume is 0. |
| `validation` | `Uitgelezen` or `Geen verbruik` |
| `calorific` | Gas kWh only: `Definitief`, `Voorlopig`, or empty. Not a conversion factor. |
| `meter`, `meter_type`, `source_file` | Trace. Not part of the key. |

Primary key: `(ean, register, unit, resolution, interval_start, occurrence)`. A second drop of the same interval replaces the row, so a later `Definitief` file replaces `Voorlopig`.

Do not hardcode an EAN, a meter, or a cutoff. The samples are two different sites.

Grain inside `fluvius_interval`, for a day that is taken from Fluvius (no `readings`, or the day fell out):

- Electricity: a complete 15-minute day wins, else an hour file if one is ever dropped, else the day file. Do not expand one quarter into three rows. Do not invent a load curve from a day total. A passing P1 day does not use this choice. Its total stays the counter delta. See **Backfill**.
- Gas m³ and gas kWh are chosen separately. A complete hour window wins, else the day row. If any hour kWh is `Voorlopig` and the day kWh is `Definitief`, the day kWh wins. m³ has no calorific flag; the hour sum matches the day file on the samples.
- Peak stays a monthly kW. It is electricity, not gas. It is not added to any kWh sum and it is not written into `capacity_peak_w`.

Counters on a Fluvius `history_5min` row stay empty. Do not write a quarter kWh into `total_import` or `total_export`. A Fluvius day total is the sum of the published quarter kWh, not `SUM(watts) * 5/60` and not counter MAX−MIN.

Cross-check on import, log only: electricity quarter sum vs day file within 0.001 kWh on a complete day; gas m³ exact to 0.001; gas kWh within 0.002 when both sides are `Definitief`. `Voorlopig` against `Definitief` is not a failure.

### Backfill

Two paths. Fluvius never fills solar. An empty solar slot stays empty.

**Quarter stitch.** For one Fluvius quarter (`:00–:15`, `:15–:30`, `:30–:45`, `:45–:00`), if all three 5-minute slots are missing from P1, write one `history_5min` row at the quarter start. Grid import is Afname Dag or Afname Nacht, whichever that quarter has. Grid injection is Injectie Dag or Injectie Nacht. `0,000` stays zero. A register that is not in the file is left empty. The other two slots of the quarter stay absent. Six missing slots are two quarters, so both are written. Three missing slots that cross a quarter boundary are not a Fluvius quarter. Leave them as a gap.

A WebBox row at that timestamp does not block this. WebBox has no grid import or injection. Fill those fields on the existing row when the P1 slots for that quarter are missing.

On a day that still passes, the official electricity kWh stays the P1 counter delta. That delta already includes the energy inside the hole. The stitched quarter is only the curve point. Do not add it to the daily sum.

**Day falls out.** An electricity day falls out when `readings` has no sample at or before 00:15, or no sample at or after 23:45, or `total_import` or `total_export` steps down by more than 0.01 kWh. A hole longer than 30 minutes does not fall the day out. The missing hour on a spring DST day (02:00–03:00) is not a hole. The repeated 02:00 in autumn counts as continuous. Partial P1 samples on a fallen-out day are ignored.

A day with no `readings` at all, which is the historical case, is this same path. Use the 15-minute series when that day has a complete one. The day total is the sum of those quarters. When the 15-minute series is missing or incomplete, the day file is the total and no quarter rows are written. Do not spread one day total across the 5-minute slots.

**Gas.** Gas does not go into `history_5min`. A clock hour with no gas-counter samples takes the Fluvius hour. A gas day falls out when the 06:00–06:00 window is not bracketed by counter samples, or the m³ counter steps down. Then use the hours when that window is complete, otherwise the day row. The electricity midnight test does not apply. Hour kWh that is `Voorlopig` still loses to a `Definitief` day kWh. A passing gas day keeps the counter delta. Filled hours are not added on top of it. Showing gas is **A11**.

### What has to be added or changed (not in this kickoff)

- New `fluvius_import.py`. It reads Fluvius CSVs and writes `fluvius_interval`. It does not parse WebBox archives. `importer.py` ignores `.csv`, so the files can sit in `import_queue/fluvius/` and move to `import_queue/fluvius/processed/`.
- `rollup.py -purge_derived` keeps `fluvius_interval`.
- `rollup.py -buildall` and `-daily`, after `history_5min` is rebuilt from `readings` and WebBox: apply the backfill above. `data_source` `fluvius` on a row that Fluvius created. Counters on that row stay null. A WebBox row that only gains grid fields keeps its solar source and records that grid came from Fluvius. Gas hours do not go into `history_5min`.
- Derived day and month grid import/export: counter delta on a passing electricity day, Fluvius quarter sum or day file on a fallen-out day. Solar on those days is untouched. No `gas_m3` / `gas_kwh` columns in this phase.
- Settings page: two `app_settings` keys, electricity EAN and gas EAN. The loader reads those keys and ignores every other EAN.
- `docs/architecture.md`, `README.md`, and `.cursor/rules/history-5min-derived.mdc` when the code lands. The cursor rule already forbids purging this source and already states the 1-of-3 chart rule.

`analytics.php` still fills 288 solar slots with 0 and does not plot grid. That drawing is **A5**, not P3. P3 only writes the empty quarter slots so A5 has something to read.

### Still open

None. No code until the operator says implement.

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

The 30-minute hole was dropped from the fall-out test in the same pass. A long hole is stitched quarter by quarter. Fall-out stays the midnight brackets and a counter step down over 0.01 kWh.

---

## Out of scope (until re-triaged)

- Changing local `logger.py` SMA / WebBox poll behaviour
- Replacing WebBox ZIP / `sql_importer` archive path without an explicit kickoff lock
- Fluvius portal script (P3 step 2)
- Drawing Fluvius quarters on the day chart (**A5**)
- Pages for historical gas, wind, and the monthly peak (**A11**, **A12**)
