# --- file: docs/todo/phaseB-battery.md ---
# Phase B — Battery (Marstek Venus) + policy engine

**Status:** **B1** kickoff **complete** (ready for `implement` / `patch` when commanded). **B2** design options & policy proposals documented (queued after W1).  
**Last updated:** 2026-11-28 (B2+ options, B1 soak baselines, and priority state machine added)  
**Product pointers:** (none shipped yet) · **Operator checklist (NL):** [`docs/operator-b1-marstek.md`](../operator-b1-marstek.md) · site note `C:\data\OneDrive\_immo\Borsbeekhuis\04-werken\thuisbatterij\thuisbatterij.md` (install context)

---

## Affinity

Marstek Venus (HomeWizard P1 zero-sum + Modbus). **Same repo** as homepower; engine UI under **`/marstek`** (different folder). **Same DB** `meter_history.db` (1 s Marstek tables never-purged). Homepower = history + graphs; engine owns Modbus control after S6. Multi-tenant: any site with HomeWizard P1 + Marstek (Settings-configurable; DS723 + Sofie).

---

## Sequence (this letter)

| Id | Status | What |
|---|---|---|
| **B1** | kickoff | S6 observe: Marstek Modbus telemetry → `meter_history.db` (1 s tables) → G1–G9 + `/marstek` status; no policy control |
| **B2** | queued | Policy engine (own UI at `/marstek`) + Modbus control + contract with homepower (after B1 soak + after **W1**) |
| **B3+** | queued | Euro / ROI scoring, dry-run backtesting, advanced multi-day forecast optimization — TBD after B2 |

**Pipeline order (global):** **B1** → **W1** → **B2** → **B3+** → Ops9 → Ops7.

---

## Verbatim inbox

### 2026-11-28 — plan + intent (prior turn)

> C:\data\OneDrive\_immo\Borsbeekhuis\04-werken\thuisbatterij\thuisbatterij.md I bought Marstek Venus E 3.0
> I will connect it to a seperate wall outle so that I can use 2,5kW
> I have homewizard P1 plug already, you know that
> when the marstek arrives, I want to integrate it with both the P1 already in use and also optimize it via own app
> in homepower-dashboard I want an additional line: what comes in or out the battery & consolidate this into the tool
> what needs to be done?
> how can my app help in optimizing money?
> which scenarios are there - plan mode

### 2026-11-28 — locks (answers to plan open Qs)

> 1: after S6: policy ownser will be our app reading modbus controller
> 2: on synology: different engine with own interface: agree? must talk with homepower app (also on synology)
> 3: first 2 weeks: S6: measurement from marstek only + all graphs that can be drawn + keeping all data as granular as possible
> 4: battery charge = positive for graph on battery
> 5: triage & kickoff

### 2026-11-28 — triage placement + kickoff answers

> placement:
> 1: B ok
> 2: B1 before W1, then W1, then the other B's
> 3: same homepower tree, different folder/path
> open QS:
> 1: what is recommended if we want this data to guide future policy engines?
> 2: different DB file
> 3: all in B1
> 4: both - app must be user-configurable / goal must be to deploy to all users who have homewizard P1 + marstek
> 5: probe on arrival / upgrade to latest firmware
> 6: what is best with our goal in mind: future policy in own app /// can we mimic the 'marstek AI' if needed/wanted?
> 7: hold

### 2026-11-28 — B1 R1/R2 confirm

> R1:1s
> R2: auto, not their AI engine

### 2026-11-28 — config + URL

> settings in homepower settings
> URL path /marstek
> any other open Qs? or items?

### 2026-11-28 — Q8/Q11

> Q8: confirm list of marstek graphs and where they live
> Q11: same power admin unlock

### 2026-11-28 — /marstek after B1

> option B

### 2026-11-28 — G1-G9 + not configured

> when no marstek IP + crentials in settings: same UI as today? confirm
> G1-G9 confirmed

### 2026-11-28 — Save probe

> when marstek ip & credentials are set and operator clicks save: there must be a check before applyoing - same as for P1 and webbox today, check

### 2026-11-28 — same DB + same repo

> does it make sense to have another DB/file for marstek? it is fully integrated with homepower, so leave in same DB - agree?
> same repo as homepower

### 2026-11-28 — B2+ scenarios & policy options

> in B1 we're just going to measure data
> after B1, we will code B2, this chat is not about help with that code - let's talk options
> so, when we measured 2 weeks in "zero-summing" mode (this B1), there are several scenarios:
> - i want to use the battery to keep standby power consumption to a minimum - using the solar power charged during day
> - i have a 9kW sauna, I want to use it to do peak shaving
> - when no or almost no solar power can be stored during the day: we need to draw power from the net when the tarriff are low and give it back to our house when the tariffs are high(er)
> - give options, insight and proposals

---

## Cross-cutting locks (B letter)

| Id | Lock |
|---|---|
| L1 | Letter **B** = Battery (Marstek Venus + policy engine track). |
| L2 | Global sequence: **B1** → **W1** → **B2** → **B3+**. |
| L3 | **Same git repo** `homepower_dashboard` (reconfirmed). Engine code in a **different folder** (folder name TBD at implement); served at URL path **`/marstek`**. |
| L4 | After S6: policy owner = **own Synology engine** via Modbus (not Marstek cloud AI as SoT). |
| L5 | Engine = separate app + own UI at **`/marstek`**; reads device config from **homepower Settings** (`app_settings` / `settings.php`). Charts/history stay in homepower. |
| L6 | Graph sign: **battery charge = positive**, discharge = negative. |
| L7 | First ~2 weeks after install = **S6 only** (observe / measure / graphs); no own policy control yet. |
| L8 | Product goal: **user-configurable**; deployable to **all** sites with HomeWizard P1 + Marstek (not Borsbeek-only hardcoding). Targets: **DS723 and Sofie**. |
| L17 | **Marstek IP** (and related device fields: unit id, active/probe flags as needed) configured in **homepower Settings** — same pattern as P1/SMA. |
| L18 | **`/marstek` auth:** same **power admin unlock** as homepower Settings (shared unlock window / admin password — no separate Marstek password). |
| L20 | **`/marstek` after B1 (option B):** light **status** page only — live SOC, live battery W (L6 sign), link OK/fail, link to homepower Settings. **No** history charts on `/marstek`. History = G1–G9 on power pages. Policy/control UI → **B2**. |
| L19 | Homepower battery UI when Marstek **configured + active:** **G1–G9** (table below). |
| L21 | When Marstek **not** configured (no IP / cleared / inactive — same idea as P1 not set): **power UI identical to today** — no battery series, no SOC tile, Balance/Exchange/Classic/Phases unchanged, no battery summary/topn metric, no Marstek error banner. `/marstek` may exist but only a **not configured** state (point to Settings); it must not alter index/analytics/summary/topn. Note: Modbus SoT fields are **IP + unit id + active** (no cloud password unless probe proves otherwise). |
| L22 | **Settings Save** for Marstek follows **A15** like P1 / WebBox: on Save, if Marstek fields are filled, **probe Modbus** (reachability + readable SOC/power or equivalent) via the shared probe path (`integration_probes.py` pattern). Partial fill blocks save (atomic). Probe **ok** → `marstek_active=1`. Fail/offline → still save address/fields but `marstek_active=0` + dismissible modal. Cleared Marstek → forced inactive, no probe. Status chip Active / Inactive / Not configured. Homepage error banner only when configured-but-inactive (G9 / L21). |

---

## B1 — S6 observe (telemetry + graphs)

**Status:** kickoff **locked** (awaiting `implement` / `patch`; hardware for Modbus probe)  
**Size:** L  
**Depends on:** Venus on LAN + Ethernet Modbus; HomeWizard Local API for Marstek zero-sum (Marstek-side). Not blocked by W1.

### Locked for B1

| Id | Lock |
|---|---|
| L9 | Scope = **observe only**: read Marstek (Modbus after probe); store; draw graphs. No charge/discharge setpoints from our code in B1. |
| L10 | **Same SQLite file** as homepower: `meter_history.db` (no second Marstek database file). 1 s Marstek samples live in **dedicated never-purged table(s)** in that DB (not wiped by `-purge_derived`; not a substitute for `readings` / `history_5min` sources). Charts/logger/Settings all use this one DB. Table names/schema TBD at implement. |
| L11 | Battery graphs live in **homepower** pages (not on `/marstek`). Inventory = **L19**. |
| L12 | Hosts: **both** Synology units; settings must configure P1 + Marstek endpoints per install. |
| L13 | Modbus map: **probe on arrival**; upgrade Venus to **latest firmware** before locking register map. |
| L14 | B2 policy/control surface: still **queued** (after W1); URL **`/marstek`** and Settings SoT already locked (L3/L5/L17). Finer B2 feature split at B2 kickoff. |
| L15 | **R1:** battery DB stores **1-second** samples (`power_w`, `soc_pct`, mode/status if available, timestamp). Homepower charts use **1-min pulse** / **5-min history** derived from that raw. If probe proves 1 s unsustainable on NAS/Modbus, fall back to **5 s** same schema — never 1 min for the battery DB SoT. |
| L16 | **R2:** during S6, Marstek runs **Auto / self-consumption (P1 zero-sum)** only — **not** Marstek AI tariff engine. No AI comparison window in B1. |

### L19 — Graph inventory (confirmed)

Sign everywhere: **charge = positive**, discharge = negative (L6). Raw SoT = 1 s battery DB; UI = 1-min pulse / 5-min history as today. **Only when Marstek configured + active (L21).**

| # | What | Where | Notes |
|---|---|---|---|
| G1 | **Battery power** line (bipolar W) | `index.php` 2 h pulse | Beside Exchange / as extra series; same refresh as P1 |
| G2 | **Battery power** line (bipolar W) | `analytics.php` explorer | All presets + **Today** day pan; mixed 1-min/5-min rule V where applicable |
| G3 | **SOC %** | `index.php` tile (and/or thin series on pulse) | When Marstek active |
| G4 | **SOC %** | `analytics.php` | Secondary axis or companion series on day/rolling windows |
| G5 | **Balance** bands rewritten with battery | `index.php` + `analytics.php` `view=balance` | House load uses solar + grid_net − battery_w (L6) |
| G6 | Exchange / Classic / Phases | unchanged behaviour when no battery; with battery, Exchange/Phases stay grid/phase SoT — battery is **extra**, not a fake phase | |
| G7 | **Battery charge / discharge kWh** | `summary.php` | Period bars (week/month/year/all-years) |
| G8 | **Battery charge / discharge rankings + records** | `topn.php` | Highest charge/discharge days (and months if data allows) |
| G9 | Live SOC / W / link status | Settings Save probe (**L22**) + homepage error if **configured but inactive** | A15 same as P1/WebBox; absent when not configured (L21) |

**`/marstek` in B1:** **L20** (option B status). Charts stay on power pages (G1–G9).

### B1 Soak Baselines to Extract (Input for B2 Policy Tuning)

During the ~2-week S6 soak, derive fourempirical baselines from the 1 s `meter_history.db` data (`power_w`, `soc_pct`, `grid_net`, 15-min averages):

1. **Empirical Round-Trip Efficiency (`RTE %`):** `sum(discharge_kwh) / sum(charge_kwh)` between equal-SOC anchor points, segmented into low-load standby discharge (200–350 W) vs. high-load bursts (2,000–2,500 W) to set accurate arbitrage spread gates.
2. **Native Zero-Sum Reaction Lag:** Latency (seconds) between P1 `grid_net` step-changes and Marstek `power_w` response, plus double-polling stability on the HomeWizard P1 Local API (keep Synology P1 poll $\ge 2\text{ s}$).
3. **Nightly Standby Consumption (`22:00–07:00`):** Distribution of overnight kWh draw per site (DS723 vs. Sofie) to set data-driven SOC reserve targets.
4. **Monthly *Kwartierpiek* Baseline:** Running 15-minute block averages (`00, :15, :30, :45`) and sauna/heavy-appliance peak signatures.

### Open items (B1)

| # | Topic | Status | Notes |
|---|---|---|---|
| Q1–Q8a, Q11 | Prior kickoff | **locked** | See L* above |
| Q8b | G1–G9 | **locked L19** | Confirmed |
| Q8c | Not configured UI | **locked L21** | Same power UI as today |
| Q9 | Marstek **table** names/schema inside `meter_history.db` | **defer to implement** | Same file (L10); not a second DB |
| Q10 | Repo folder name for `/marstek` | **defer to implement** | |
| Q12 | Modbus unit id | **defer** | Default 1; probe |
| Q13 | Register map | **defer** | After firmware + probe |

### DoD (B1)

- [ ] **1 s** Marstek samples in `meter_history.db` (dedicated never-purged table(s)); survive reboot; configurable Marstek IP/unit in Settings
- [ ] Settings Save probes Marstek (**L22** / A15); `marstek_active` chip + modal + homepage banner behaviour match P1/WebBox
- [ ] Probe path + firmware note documented after first hardware smoke
- [ ] Index + analytics Battery series (charge positive); SOC visible; Balance + summary/topn per **L19**
- [ ] `/marstek` status page (L20): live SOC, live W, link OK/fail, Settings link; same admin unlock (L18); no history charts; no control
- [ ] Not configured → power UI identical to today (**L21**)
- [ ] Works with config on DS723 and Sofie (no hard-coded plant IPs in code)
- [ ] No policy/control writes in B1; operator leaves Marstek in **Auto** (not AI) for soak
- [ ] Product docs (`architecture.md` / deploy) updated on ship; Last DoD audit

### Out of scope (B1)

- Modbus force-charge / force-discharge / schedule UI (→ **B2**)
- Euro / day-ahead optimization scoring as a product feature (→ **B3+** unless pulled earlier)
- Replacing Marstek↔P1 zero-sum link (Marstek keeps talking to HomeWizard Local API)

---

## B2 — Policy engine (queued)

**Status:** queued (after B1 + **W1**; design options & proposals captured below for B2 kickoff)  
**Size:** L (TBD at kickoff)  
**Scope:** Own Synology UI at `/marstek`; Modbus policy owner; talks to homepower via shared `meter_history.db` / Settings. Boundary **hold** until operator releases Q7.

### B2 Control Architecture Options (To Lock at B2 Kickoff)

| Option | Mechanism | Pros | Cons / Watch-outs |
|---|---|---|---|
| **Opt A — Hybrid Override** *(Recommended)* | Leave Marstek in native **Auto (P1 zero-sum)** as baseline; push Modbus overrides only during active policy windows (force-charge, SOC hold, peak-shave). | Safe fallback: if NAS reboots or engine stalls, battery automatically reverts to P1 zero-sum. | Both Marstek and Synology read HomeWizard P1 Local API (keep Synology P1 poll $\ge 2\text{ s}$). |
| **Opt B — Full Synology Control Loop** | Synology reads P1 from `meter_history.db` and writes target W every 2–5 s over Modbus TCP. | Single P1 reader; scales cleanly to multi-battery sites (2+ units). | Requires hardware watchdog fallback register if NAS goes offline. |
| **Opt C — Schedule-Slot Writer** | Translate daily plan into Marstek's internal time-of-use schedule registers hourly. | Minimal Modbus write traffic. | Too rigid for live *kwartierpiek* spikes or ad-hoc sauna sessions. |

### Scenario Design Options & Proposals

Because all three user scenarios compete for the same **~4.5 kWh usable capacity** and **2,500 W inverter limit**, B2 should implement a **configurable priority state machine** per site (DS723 + Sofie) rather than isolated scripts:

#### 1. Priority State Machine (Highest → Lowest)

| Priority | State | Trigger & Action | Configurable Site Parameters (`/marstek`) |
|---|---|---|---|
| **P1** | **Peak Guard (*Kwartierpiek*)** | Always active. Monitors running 15-min grid average (`00, :15, :30, :45`). If projected 15-min average approaches `peak_target_w` (monthly record or 2,500 W floor), immediately overrides any hold and discharges up to `max_discharge_w` (2,500 W) until the quarter-hour resets. | `peak_guard_enabled`, `peak_target_w` (auto from monthly max or manual), `peak_reserve_soc_pct` (e.g., 15%) |
| **P2** | **Heavy Load / Sauna Prep** | Triggered via **manual one-tap UI button** ("Sauna tonight at HH:MM") or **recurring weekly schedule**. Pre-charges to `sauna_target_soc_pct` (95–100%) ~2 h before start (throttled so charging itself never exceeds `peak_target_w`), holds SOC (`discharge = 0 W`) until window start, then releases into P1/P4 for the 45-min heat-up (shaving 2.5 kW off a ~9 kW sauna). | `sauna_schedule` (day/time), `sauna_manual_trigger`, `sauna_target_soc_pct`, `sauna_prep_lead_min` |
| **P3** | **Low-Solar Tariff Arbitrage** | Active when tomorrow's expected solar surplus is below `solar_threshold_kwh` (e.g., `< 3.5 kWh` in Nov–Feb) **AND** dynamic tariff spread `(peak_eur - charge_eur)` exceeds `min_spread_eur` (e.g., `€0.10–€0.12/kWh` after RTE loss).<br>• **Charge window:** Force-charge at up to 2,500 W during the cheapest 1.5–2 h block (typically 02:00–04:00).<br>• **Hold window:** Lock discharge (`0 W`) during cheap night hours so cheap grid energy is not wasted on ~220 W standby loads.<br>• **Release window:** Allow discharge during high-tariff morning/evening blocks (e.g., 07:00–10:00 and 17:00–21:00). | `arbitrage_enabled`, `solar_threshold_kwh`, `min_spread_eur`, `arb_target_soc_pct`, `hold_cheap_hours` |
| **P4** | **Solar & Standby Default** | Default state when P1–P3 are idle. Runs P1 zero-summing to absorb daytime solar and cover overnight standby. Optional evening discharge cap (`evening_max_discharge_w`, e.g., 600–800 W after 19:00) prevents early evening appliances from emptying the battery before night standby, while P1 Peak Guard still overrides if a 15-min peak threatens. | `evening_soft_cap_w` (optional), `min_discharge_load_w` (optional low-load efficiency cutoff) |

#### 2. W1 Data Dependency (Dynamic Tariffs & Solar Forecast)
* **Day-Ahead Prices in `meter_history.db`:** Pull quarter-hourly/hourly EPEX Belgium day-ahead prices (ENTSO-E / Energy-Charts / supplier feed) into `meter_history.db` during **W1** (or as B2 prerequisite) so B2 can compute exact charge/hold/release windows and dry-run against B1 traces before enabling live Modbus writes.
* **Solar Forecast Input:** Support lightweight solar yield estimation (Forecast.Solar / Solcast API per site latitude/kWp, or rolling yesterday-surplus fallback) to gate P3 Arbitrage automatically without manual winter/summer toggling.

---

## B3+ — Money / scenarios (queued)

**Status:** queued (after B2)  
**Candidates (to scope at B3 kickoff):**
* **Euro & ROI Reporting:** Actual € saved per stream (solar self-consumption vs. tariff arbitrage vs. *capaciteitstarief* peak reduction) inside homepower `summary.php` / `/marstek`.
* **Dry-Run / Shadow Simulator:** Replay candidate B2 policy parameters against historical 1 s `meter_history.db` traces (B1 soak data) to compare simulated € savings before applying live changes.
* **Multi-Battery Orchestration:** Coordinated Modbus dispatch across 2+ Venus units per site if a tenant expands capacity.