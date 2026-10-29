# --- file: docs/todo/phaseW-wanos.md ---
# Phase W — WanOS bridge (outbound)

**Status:** **W1** open (triaged 2026-10-28, not kicked off)
**Last updated:** 2026-10-28
**Product pointers:** [`docs/architecture.md`](../architecture.md) (WanOS stays a separate OS; this letter is push *to* WanOS, not ingest *from* it)

---

## Affinity

Outbound integration from this dashboard into **WanOS** (sensors + MQTT listener on the WanOS side). Distinct from portal ingest (**P**) and from analytics UI (**A**).

---

## Sequence (this letter)

| Id | Status | What |
|---|---|---|
| **W1** | open | This app pushes to WanOS on `rollup.py -daily` and when raw readings are opened |

---

## Verbatim inbox

### 2026-10-28 — triage placement

> [open]  WanOS: sensors + MQTT listener; this app pushes on
>           rollup.py -daily
>           and when raw readings are opened
>           (Generate today removed - nightly -daily owns downsample)

Operator placed as **W1** (2026-10-28).

---

## W1 — Push to WanOS MQTT / sensors

**Status:** open (triaged 2026-10-28, not kicked off)
**Size:** **M** (estimate; refine at kickoff)

### Placement

WanOS owns sensors + MQTT listener. This repo **pushes** on:

1. `rollup.py -daily`
2. When raw readings are opened

Nightly `-daily` owns downsample (Generate today removed). Topic map, payload shape, auth, and “raw readings opened” trigger are kickoff locks. Do not move WanOS HomeWizard ingest into this repo ([`architecture.md`](../architecture.md)).

### Out of scope (until re-triaged)

- WanOS-side listener implementation (lives in WanOS)
- Pulling energy data *from* WanOS into this warehouse
