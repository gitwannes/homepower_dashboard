#!/bin/sh
# --- file: run_python.sh ---
# Pick the newest usable Python 3 (same order as hp_python_bin in site_settings.php),
# then exec the remaining arguments with that interpreter.
#
# Task Scheduler (same command on every Synology host):
#   /volume1/web/run_python.sh /volume1/web/logger.py -poll
#   /volume1/web/run_python.sh /volume1/web/rollup.py -daily
#
# Optional: RUN_PYTHON_PRINT=1 prints the chosen binary to stderr before exec.

set -eu

if [ "$#" -lt 1 ]; then
    echo "Usage: run_python.sh <script.py|args...> [args...]" >&2
    echo "Picks newest Python 3, then execs: <python> <args...>" >&2
    exit 2
fi

# Keep in sync with hp_python_bin() in site_settings.php.
PY=""
for cand in \
    /var/packages/Python3.14/target/bin/python3.14 \
    /var/packages/Python3.14/target/bin/python3 \
    /var/packages/Python3.14/target/usr/bin/python3 \
    /usr/local/bin/python3.14 \
    /usr/bin/python3.14 \
    /usr/local/bin/python3.13 \
    /usr/bin/python3.13 \
    /usr/local/bin/python3.12 \
    /usr/bin/python3.12 \
    /usr/local/bin/python3.11 \
    /usr/bin/python3.11 \
    /usr/local/bin/python3.10 \
    /usr/bin/python3.10 \
    /usr/local/bin/python3.9 \
    /usr/bin/python3.9 \
    /usr/local/bin/python3 \
    /usr/bin/python3
do
    if [ -x "$cand" ]; then
        PY=$cand
        break
    fi
done

if [ -z "$PY" ]; then
    if command -v python3 >/dev/null 2>&1; then
        PY=$(command -v python3)
    else
        echo "run_python.sh: no Python 3 binary found" >&2
        exit 127
    fi
fi

if [ "${RUN_PYTHON_PRINT:-}" = "1" ]; then
    echo "run_python.sh: using $PY" >&2
fi

exec "$PY" "$@"
