# --- file: docs/power-notes.md ---
# Power notes (operator cheat sheet)

Dense warehouse map. Product detail: [`architecture.md`](architecture.md). Delivery backlog: [`todo/pipeline.md`](todo/pipeline.md).

**Last checked:** 2026-10-27 (P1 pull + rollup empty-fill shipped; non-PV map after probe)

---

## Historical import (once)

```
importer.py
importer.py -outerzip
	parses WebBox Mean archives from import_queue/
	writes staging only: sensorbox_history_5min, inverter_history_5min
	archives move to import_queue/processed/
	does not write history_5min

fluvius_import.py
	parses CSV from import_queue/fluvius/
	writes fluvius_interval (never-purged SoT)
	matching files move to import_queue/fluvius/processed/
	Settings EANs gate which rows are kept

sunnyportal_pull.py
	credentials from meter_history.db app_settings (Settings)
	writes sunnyportal_pv_5min on that same DB
	no switches -> usage
	-pullall / -lastdays -n N (1..366, includes today) / -probe
	does not write history_5min

rollup.py -consolidate
	reads sensorbox_history_5min, inverter_history_5min
	writes solar and weather columns into history_5min
	data_source: webbox_archive

sql_importer.py
	one-shot gap-fill into staging. Not in this repo any more. Do not reintroduce.
```

---

## Nightly

```
rollup.py -daily
	1/ readings, last 2 local days
	   -> history_5min (closed 5-minute buckets only)
	   then sunnyportal_pv_5min empty-fill solar_power_w (same 2-day span)
	   then Fluvius electricity quarter stitch for those calendar days
	2/ history_5min, last 3 local days
	   -> daily_history (those 3 days)
	3/ full rewrite: monthly_history, top_periods
	4/ beat-update top_records when a day in that stitch span is a stricter extreme
```

Today's closed 5-minute buckets land here. There is no `rollup.py -today`, no `importer.py -aggregate5min`, and no analytics "Generate today table".

---

## Other parsers

```
rollup.py -purge_derived
	deletes: history_5min, daily_history, monthly_history,
	         clear_sky_envelopes, top_records, top_periods
	runs VACUUM
	keeps: readings, sensorbox_history_5min, inverter_history_5min,
	       fluvius_interval, sunnyportal_pv_5min,
	       app_settings, expected_monthly

rollup.py -buildall
	1/ deletes history_5min, daily_history, monthly_history (no VACUUM)
	2/ readings, all rows -> history_5min
	   skip the open 5-minute slice
	3/ WebBox staging -> solar and weather on history_5min (-consolidate path)
	4/ sunnyportal_pv_5min -> empty solar_power_w only
	5/ fluvius_interval electricity quarters -> grid columns on history_5min
	   (1-in-3 quarter pattern; gas stays in fluvius_interval)
	6/ history_5min, all -> daily_history
	7/ daily_history, all -> monthly_history
	8/ history_5min, all -> clear_sky_envelopes
	9/ history_5min + daily_history -> top_records
	10/ daily_history + monthly_history -> top_periods
	rare; day chart empty until finished
	does not rescan import_queue/processed/ or fluvius/processed/
	does not write sunnyportal_pv_5min (pull script owns that table)

rollup.py -buildallsunnyportal
	empty-fill solar_power_w from sunnyportal_pv_5min only
	no wipe of history_5min; prints written / first / last

rollup.py -buildalltoprecords
	full rewrite of top_records only
```

---

## Tables

```
readings
	1-min from logger.py (live SoT). Never purged.

sensorbox_history_5min
	WebBox Mean staging (every WebBox year). Never purged.

inverter_history_5min
	WebBox Mean staging (every WebBox year). Never purged.

fluvius_interval
	Fluvius CSV SoT. Never purged.
	Electricity 15-min, gas hour/day, monthly peak.

sunnyportal_pv_5min
	Sunny Portal / ennexOS PV staging. Never purged.
	Filled only by sunnyportal_pull.py.
	-buildall must not write this table.
	Empty-fill into history_5min.solar_power_w on -buildall / -daily.

history_5min
	Derived master (5-min).
	Sources: readings, WebBox staging, sunnyportal_pv_5min (empty solar),
	         Fluvius electricity quarters.
	grid_import_kwh / grid_export_kwh = published quarter kWh on :00/:15/:30/:45 only.

daily_history
	From history_5min.

monthly_history
	From daily_history.
	(no year table: years = substr(year_month, 1, 4) summed from monthly_history)

clear_sky_envelopes
	From history_5min.

top_periods
	Top-n graphs (days / months / years).
	From daily_history and monthly_history.
	Rebuilt by -buildall and -daily.

top_records
	Eight all-time singles on topn.php.
	Full rewrite: -buildall, -buildalltoprecords.
	Beat-update: -daily (stricter extreme in stitch span only).
	best_day_kwh
	peak_surge_w
	max_sun_hours
	best_self_consumption_kwh
	max_wind_ms
	max_temp_c
	min_temp_c
	max_module_temp_c
	(wind / ambient / module / self-consumption rows hide in UI when absent)

app_settings
expected_monthly
	Config / PVGIS seed. Not timeseries sources. Stay across -purge_derived.
```

**Day-graph rule (5-min axis):** up to 2 missing slots between known points = draw (Fluvius 1-in-3), via linear bridge of grid W on the explorer/day chart only (`history_5min` stays sparse). More than 2 consecutive missing = gap, not zero.

---

## Full (re)build

```
0/ historical import (WebBox + Fluvius CSV + sunnyportal_pull -pullall) - once / when new archives arrive
1/ rollup.py -purge_derived          (optional hard reset + VACUUM)
2/ rollup.py -buildall
```

Run `fluvius_import.py` before `-buildall` when new CSVs sit in `import_queue/fluvius/`.
Run `sunnyportal_pull.py -pullall` (or `-lastdays`) before `-buildall` when staging needs a refresh.

---

## Fluvius

```
Shipped (P3):
	manual CSV -> fluvius_interval
	electricity quarters stitched into history_5min on -daily / -buildall
	gas stays in fluvius_interval (not on history_5min)
	UI for historical gas / peak: A11 / A12 (queued)

Still open:
	portal script for last-x-days pull
		assess: useful, or only to fill gaps when P1 could not read?
```

## SMA / Sunny Portal (ennexOS)

```
One portal: portal.sma.energy -> ennexos.sunnyportal.com
P2 closed as duplicate of P1.

sunnyportal_pull.py
	credentials: app_settings (Settings page)
	-pullall / -lastdays -n N / -probe
	staging: sunnyportal_pv_5min
	rollup empty-fills solar_power_w (new rows data_source sunnyportal)
	non-PV channel map: after operator -probe review
```

---

## Triage / later (not a Sequence edit)

Status vs current pipeline - keep here as a personal checklist; place new work with `triage` if it should enter Sequence.

```
[done]  .env device keys -> app_settings once; keep only DB_BACKUP_ENCRYPTION_KEY in .env
[done]  dead .env->settings importers removed (P1)
[done]  17-year WebBox archive ingestion into staging / rebuild path
[done]  first-run: DB + tables created; admin password; Settings; then dashboard
        (P1 address required before live dashboard)

[open]  WanOS: sensors + MQTT listener; this app pushes on
          rollup.py -daily
          and when raw readings are opened
          (Generate today removed - nightly -daily owns downsample)

[open]  notifier.py (roadmap Phase 3)

[open]  virtual kWh meters from Settings (bias to import / export / PV)

[open]  meter_history.db -> Synology Hyper Backup (roadmap Phase 4)

[open]  Settings save: probe every filled integration; fail -> modal, stay on page

[ops]   Task Scheduler: daily 00:05 rollup.py -daily (still to confirm on host)
```
