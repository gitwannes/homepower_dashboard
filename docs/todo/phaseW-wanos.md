# --- file: docs/todo/phaseW-wanos.md ---
# Phase W — WanOS bridge

**Status:** **W1** / **W2** open (not kicked off)
**Last updated:** 2026-11-03
**Product pointers:** [`docs/architecture.md`](../architecture.md) (WanOS stays a separate OS; this letter is the bridge *to/from* WanOS — not moving WanOS HomeWizard energy ingest into this repo)

---

## Affinity

WanOS integration for this dashboard: **outbound push** (energy / readings → WanOS MQTT) and **inbound ambient** (outside temperatures → warehouse / `weather.php`). Distinct from portal ingest (**P**) and from analytics UI (**A**). Do not move WanOS HomeWizard energy ingest into this repo.

---

## Sequence (this letter)

| Id | Status | What |
|---|---|---|
| **W1** | open | This app pushes to WanOS on `rollup.py -daily`, when raw readings are opened, and historical push |
| **W2** | open | Ingest WanOS outside (ambient) temperatures: daily + one-off historical → available on `weather.php` |

---

## Verbatim inbox

### 2026-10-28 — triage placement

> [open]  WanOS: sensors + MQTT listener; this app pushes on
>           rollup.py -daily
>           and when raw readings are opened
>           (Generate today removed - nightly -daily owns downsample)

Operator placed as **W1** (2026-10-28).

### 2026-11-03 — W1 historical + W2 ambient ingest

> W1: also add historical push
> W2: (triage new): get outside (ambient) temperatures from wanos: daily + one-off historical, so that this info is available in "weather" page

Operator: expand **W1**; place **W2** (2026-11-03).

---

## W1 — Push to WanOS MQTT / sensors

**Status:** open (triaged 2026-10-28; historical push added 2026-11-03; not kicked off)
**Size:** **M** (estimate; refine at kickoff)

### Placement

WanOS owns sensors + MQTT listener. This repo **pushes** on:

1. `rollup.py -daily`
2. When raw readings are opened
3. **Historical push** (one-off / backfill of past data — kickoff locks scope, command, and payload)

Nightly `-daily` owns downsample (Generate today removed). Topic map, payload shape, auth, “raw readings opened” trigger, and historical-push command/range are kickoff locks. Do not move WanOS HomeWizard ingest into this repo ([`architecture.md`](../architecture.md)).

### Out of scope (until re-triaged)

- WanOS-side listener implementation (lives in WanOS)
- Pulling energy data *from* WanOS into this warehouse (ambient temps are **W2**, not energy)

---

## W2 — Ambient temperatures from WanOS → weather page

**Status:** open (triaged 2026-11-03, not kicked off)
**Size:** **M** (estimate; refine at kickoff)
**Depends on:** **A11** weather page (shipped; ambient UI already exists from WebBox / sensorbox series)

### Placement

Affinity **W** (WanOS bridge). Pull **outside (ambient) temperatures** from WanOS into this warehouse so `weather.php` can show them.

Triggers / modes (operator):

1. **Daily** ingest
2. **One-off historical** backfill

Storage table vs empty-fill into existing ambient columns, resolution, auth, and how WanOS ambient coexists with WebBox/sensorbox ambient are kickoff locks. Consumer surface is the existing **weather** page (A11) — not a new page.

### Out of scope (until re-triaged)

- WanOS HomeWizard *energy* ingest (stays in WanOS)
- Rework of A11 weather UI beyond making WanOS ambient available
