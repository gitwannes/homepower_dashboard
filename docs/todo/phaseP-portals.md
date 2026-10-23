# --- file: docs/todo/phaseP-portals.md ---
# Phase P — Cloud / utility portal ingest

**Status:** Queued (P1–P3 open).  
**Last updated:** 2026-10-23  
**Product pointers:** [`docs/architecture.md`](../architecture.md) · `logger.py` (local SMA/WebBox already — **not** this track)

---

## Affinity

Pull historical / periodic data from **cloud or utility portals** into the warehouse (staging or derived — TBD at kickoff). Distinct from LAN SMA WebConnect / Sunny WebBox polling.

---

## Sequence (this letter)

| Id | Status | What |
|---|---|---|
| **P1** | open | Sunny Portal (cloud) ingest |
| **P2** | open | SMA portal ingest |
| **P3** | open | Fluvius Energy Portal ingest |

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

---

## P1 — Sunny Portal

**Status:** open (not kicked off)  
**Scope (placement only):** ingest from Sunny Portal cloud.  
**Locks:** none yet — kickoff required before code.

---

## P2 — SMA portal

**Status:** open (not kicked off)  
**Scope (placement only):** ingest from SMA portal (cloud / account — not LAN WebConnect).  
**Locks:** none yet — kickoff required before code.

---

## P3 — Fluvius Energy Portal

**Status:** open (not kicked off)  
**Scope (placement only):** ingest from Fluvius Energy Portal.  
**Locks:** none yet — kickoff required before code.

---

## Out of scope (until re-triaged)

- Changing local `logger.py` SMA / WebBox poll behaviour
- Replacing WebBox ZIP / `sql_importer` archive path without an explicit kickoff lock
