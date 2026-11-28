# --- file: docs/todo/phaseB-battery.md ---
# Phase B — Battery (Marstek Venus) + policy engine

**Status:** **B1** kickoff **complete** (ready for `implement` / `patch` when commanded). **B2+** queued after W1.  
**Last updated:** 2026-11-28 (L10 revised: same `meter_history.db`, not a second file)  
**Product pointers:** (none shipped yet) · **Operator checklist (NL):** [`docs/operator-b1-marstek.md`](../operator-b1-marstek.md) · site note `C:\data\OneDrive\_immo\Borsbeekhuis\04-werken\thuisbatterij\thuisbatterij.md` (install context)

---

## Affinity

Marstek Venus (HomeWizard P1 zero-sum + Modbus). **Same repo** as homepower; engine UI under **`/marstek`** (different folder). **Same DB** `meter_history.db` (1 s Marstek tables never-purged). Homepower = history + graphs; engine owns Modbus control after S6. Multi-tenant: any site with HomeWizard P1 + Marstek (Settings-configurable; DS723 + Sofie).

---

## Sequence (this letter)

| Id | Status | What |
|---|---|---|
| **B1** | kickoff | S6 observe: Marstek Modbus telemetry → `meter_history.db` (1 s tables) → G1–G9 + `/marstek` status; no policy control |
| **B2** | queued | Policy engine (own UI) + Modbus control + contract with homepower (after B1 soak + after **W1**) |
| **B3+** | queued | Arbitrage / sauna / euro scoring — TBD after B2 |

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

**Status:** queued (after B1 + **W1**)  
**Size:** L (TBD at kickoff)

Own Synology UI; Modbus policy owner; talks to homepower. Boundary **hold** until operator releases Q7. Folder/path inside this repo TBD.

---

## B3+ — Money / scenarios (queued)

Tariff arbitrage, sauna peak shave, euro reports — after B2. Scenario ids from plan (S1–S5) remain reference only until kickoff.
