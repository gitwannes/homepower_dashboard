# --- file: docs/todo/phaseP-portals.md ---
# Phase P — Cloud / utility portal ingest

**Status:** P3 Done. P1 kickoff in progress. P2 queued.  
**Last updated:** 2026-10-26 (P3 closed)  
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
| **P3** | Done | Fluvius manual CSV into `fluvius_interval`. Product: [`docs/architecture.md`](../architecture.md) |

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
