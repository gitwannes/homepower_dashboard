# --- file: docs/todo/phaseW-wanos.md ---
# Phase W — WanOS bridge

**Status:** **W2 Done** 2026-11-06; **W1** open  
**Last updated:** 2026-11-06 (W2 close-out)  
**Product pointers:** [`docs/architecture.md`](../architecture.md) · WanOS repo `docs/integration_power_bridge.md`

---

## Affinity

WanOS integration: **W1** Power → WanOS; **W2** WanOS → Power display bridge. Do not move WanOS HomeWizard energy ingest into this repo. Water-Link SoT remains WanOS G24.

---

## Sequence (this letter)

| Id | Status | What |
|---|---|---|
| **W1** | open | Power → WanOS: `-daily` / raw opened / historical + display Power readings in WanOS (shared API key design from W2) |
| **W2** | **Done** 2026-11-06 | WanOS → Power display bridge + Sensor readings / WebBox→Settings |

---

## Verbatim inbox

(See git history for full verbatim — 2026-10-28 through 2026-11-06 kickoff locks.)

---

## W1 — Power → WanOS (push + display in WanOS)

**Status:** open (not kicked off). Shared machine API key design landed with **W2**.

---

## W2 — WanOS → Power (link + selectable sensors)

**Status:** **Done** 2026-11-06  
**Size:** L  
**Cross-repo:** Power (`homepower_dashboard`) + WanOS (`wanos`)

### Shipped (product SoT)

| Area | Pointer |
|---|---|
| Behaviour / UI | [`docs/architecture.md`](../architecture.md) (WanOS row, Settings, index Utility / Sensor readings / Raw) |
| WanOS API | WanOS `docs/integration_power_bridge.md` (`/api/bridge/*`, allowlist, `updated_unix`) |

### Shipped summary

| Area | What |
|---|---|
| WanOS | Machine bridge key (64 hex); `/api/bridge/probe` + `/api/bridge/sensors` (allowlist + live value + `updated_unix`); Admin Generate; role `bridge` |
| Power | `wanos_bridge.php`; Settings address + key (**Save & check connection**); mappings Add / dirty **Save sensor changes** / Remove; probe → `wanos_active`; index Sensor readings + Utility/Raw waters; WebBox devices table on Settings only |
| Host | Prefer TLS hostname matching cert (e.g. `app.wanos.be`); do not use raw IP for HTTPS |

### Allowlist entity_ids

- `sensor.fluid.waterlink.digital_meter` (blocked when P1 active + water_meter)
- `sensor.fluid.koud_water`
- `sensor.fluid.warm_water`
- `sensor.temp_hum.outside_temp_hum` (ambient → Sensor readings)

### Out of scope (confirmed)

- Warehouse writes / weather.php backfill
- Water-Link client in Power
- W1 push/display product code

### DoD

- [x] Code both repos
- [x] Product docs (architecture + WanOS integration_power_bridge)
- [x] Operator close-out (**close W2** 2026-11-06)
- [x] Mark Done in pipeline

**Do not offline-archive this file yet** — **W1** still open.
