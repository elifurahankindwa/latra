#!/bin/bash
# Scheduler tick wrapper for cron.
#
# Runs cron.php once under a wall-clock timeout so a single stuck network call
# can never outlive the crontab cadence. cron.php already takes its own DB and
# file locks, so overlapping invocations are harmless (they exit immediately).
#
# Usage (crontab):
#   */3 * * * * /opt/lampp/htdocs/latra-wialon-integration/scripts/scheduler_tick.sh
set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-/opt/lampp/bin/php}"
# Hard ceiling below the 3-minute cadence so ticks cannot pile up.
TICK_TIMEOUT="${TICK_TIMEOUT:-170}"
LOG_FILE="${APP_DIR}/logs/scheduler.log"

mkdir -p "${APP_DIR}/logs"

# Rotate the wrapper log at ~5 MB so it never grows without bound.
if [[ -f "${LOG_FILE}" ]] && [[ "$(stat -c %s "${LOG_FILE}" 2>/dev/null || echo 0)" -gt 5242880 ]]; then
  mv -f "${LOG_FILE}" "${LOG_FILE}.1"
fi

{
  echo "=== $(date '+%Y-%m-%d %H:%M:%S %Z') tick start (timeout ${TICK_TIMEOUT}s) ==="
  timeout --signal=KILL "${TICK_TIMEOUT}" "${PHP_BIN}" "${APP_DIR}/cron.php" 2>&1
  code=$?
  if [[ ${code} -eq 137 || ${code} -eq 124 ]]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S %Z')] tick exceeded ${TICK_TIMEOUT}s and was killed; a job is blocked on a slow endpoint."
  fi
  echo "=== $(date '+%Y-%m-%d %H:%M:%S %Z') tick end (exit ${code}) ==="
} >> "${LOG_FILE}" 2>&1

# Always succeed so cron does not email on transient endpoint failures.
exit 0
