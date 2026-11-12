#!/usr/bin/env bash
# --- file: backup/power_db_backup.sh ---
# Ops5: daily/weekly/monthly plain zip of meter_history.db into the Synology
# drop dir that Hyper Backup already mirrors to C2 (same folder as WLW Ops8).
# Local write + in-script prune of power-db-* only (do not touch wlw-db-*).
#
# DSM Task Scheduler (separate from rollup -daily at 00:05):
#   /volume1/web/backup/power_db_backup.sh
# Daily 04:00 Europe/Brussels. Always writes D; also W on Sunday; also M on day 1.
#
# Overrides (optional): HP_DB_FILE, HP_DB_BACKUP_DIR
#
# Staging must stay on a volume path (default: under DROP_DIR). Do not use /tmp:
# meter_history.db is large; Synology /tmp is often a small tmpfs and .backup appears
# to hang when that fills.

set -euo pipefail

log() { echo "[power-db-backup] $*"; }
die() { echo "[power-db-backup] ERROR: $*" >&2; exit 1; }

# Human-readable size for console logs (ASCII; binary KiB/MiB/GiB).
human_bytes() {
    local n="$1"
    awk -v b="${n}" 'BEGIN {
        split("B KiB MiB GiB TiB", u, " ")
        i = 1
        while (b >= 1024 && i < 5) { b /= 1024; i++ }
        if (i == 1) printf "%d %s", b, u[i]
        else printf "%.1f %s", b, u[i]
    }'
}

need_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "missing command: $1"
}

need_cmd sqlite3
need_cmd zip
need_cmd date
need_cmd mktemp
need_cmd find
need_cmd awk

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
WEB_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

DB_FILE="${HP_DB_FILE:-${WEB_ROOT}/meter_history.db}"
DROP_DIR="${HP_DB_BACKUP_DIR:-/volume1/data/wlw_db_backup}"

[[ -f "${DB_FILE}" ]] || die "missing DB: ${DB_FILE}"
mkdir -p "${DROP_DIR}"
[[ -d "${DROP_DIR}" ]] || die "drop dir not usable: ${DROP_DIR}"

export TZ="${TZ:-Europe/Brussels}"
STAMP="$(date +%Y%m%d-%H%M%S)"
DOW="$(date +%u)"   # 1=Mon .. 7=Sun
DOM="$(date +%d)"   # 01..31

# Stage on the data volume (same filesystem as the drop dir), never /tmp.
STAGE="$(mktemp -d "${DROP_DIR}/.power-db-backup.XXXXXX")"
cleanup() { rm -rf "${STAGE}"; }
trap cleanup EXIT

SNAP_DIR="${STAGE}/snap"
mkdir -p "${SNAP_DIR}"
SNAP_FILE="${SNAP_DIR}/meter_history.db"

DB_SIZE="$(wc -c < "${DB_FILE}" | tr -d ' ')"
log "snapshot ${DB_FILE} ($(human_bytes "${DB_SIZE}")) -> ${SNAP_FILE}"
log "sqlite online backup starting (large DB can take minutes; staged on volume not /tmp)"

# Busy timeout so a concurrent logger poll does not block forever.
# Quote path for sqlite; expand SNAP_FILE in the shell before sqlite sees it.
sqlite3 "${DB_FILE}" <<EOF
.timeout 60000
.backup '${SNAP_FILE}'
EOF

[[ -f "${SNAP_FILE}" ]] || die "sqlite .backup produced no file"
SNAP_SIZE="$(wc -c < "${SNAP_FILE}" | tr -d ' ')"
log "snapshot ok ($(human_bytes "${SNAP_SIZE}"))"

make_and_write() {
    local lane="$1"   # D | W | M
    local zipname="power-db-${lane}-${STAMP}.zip"
    local zippath="${STAGE}/${zipname}"
    local zip_size pct
    log "zip ${zipname}"
    (
        cd "${SNAP_DIR}"
        zip -q -r "${zippath}" .
    )
    zip_size="$(wc -c < "${zippath}" | tr -d ' ')"
    # Reduction vs snapshot size (e.g. 72.3% smaller). Guard SNAP_SIZE=0.
    pct="$(awk -v z="${zip_size}" -v s="${SNAP_SIZE}" 'BEGIN {
        if (s <= 0) { print "n/a"; exit }
        printf "%.1f", (1 - z / s) * 100
    }')"
    log "zip size $(human_bytes "${zip_size}") (${pct}% reduction vs snapshot)"
    log "write ${zipname} -> ${DROP_DIR}/"
    cp -f "${zippath}" "${DROP_DIR}/${zipname}"
}

# Always daily; weekly on Sunday; monthly on day-of-month 1 (match WLW Ops8)
make_and_write D
if [[ "${DOW}" == "7" ]]; then
    make_and_write W
fi
if [[ "${DOM}" == "01" ]]; then
    make_and_write M
fi

# Prune older power-db-* only (mtime retention; match WLW Ops8). Leave wlw-db-* alone.
log "prune power-db D>14d W>90d M>365d under ${DROP_DIR}"
find "${DROP_DIR}" -maxdepth 1 -type f -name 'power-db-D-*.zip' -mtime +14 -delete
find "${DROP_DIR}" -maxdepth 1 -type f -name 'power-db-W-*.zip' -mtime +90 -delete
find "${DROP_DIR}" -maxdepth 1 -type f -name 'power-db-M-*.zip' -mtime +365 -delete

log "done"
