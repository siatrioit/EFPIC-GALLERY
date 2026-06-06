#!/bin/bash
set -euo pipefail

: "${EFPIC_API_BASE:?Set EFPIC_API_BASE}"
: "${EFPIC_API_TOKEN:?Set EFPIC_API_TOKEN}"

AUTH="Authorization: Bearer ${EFPIC_API_TOKEN}"
POLL_SEC="${EFPIC_POLL_SEC:-20}"
WORKDIR="${EFPIC_WORK_DIR:-/tmp/efpic-render}"

mkdir -p "$WORKDIR"

log() {
  printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

fail_job() {
  local job_id="$1"
  local message="$2"
  curl -sf -X POST \
    -H "$AUTH" \
    -H "Content-Type: application/json" \
    -d "$(jq -n --arg err "$message" '{error: $err}')" \
    "${EFPIC_API_BASE}/api/render/jobs/${job_id}/fail" >/dev/null || true
}

log "EFPIC render worker start — ${EFPIC_API_BASE}"

while true; do
  resp=""
  if ! resp="$(curl -sf -X POST -H "$AUTH" "${EFPIC_API_BASE}/api/render/claim")"; then
    log "claim request failed — retry in ${POLL_SEC}s"
    sleep "$POLL_SEC"
    continue
  fi

  job_id="$(echo "$resp" | jq -r '.job.id // empty')"
  if [ -z "$job_id" ] || [ "$job_id" = "null" ]; then
    sleep "$POLL_SEC"
    continue
  fi

  log "claimed job ${job_id}"
  if ! echo "$resp" | ./render.sh; then
    err="$(cat "${WORKDIR}/${job_id}.err" 2>/dev/null || echo 'Render script failed')"
    log "job ${job_id} failed: ${err}"
    fail_job "$job_id" "$err"
    rm -f "${WORKDIR}/${job_id}.err"
  else
    log "job ${job_id} complete"
  fi

  rm -rf "${WORKDIR}/${job_id}"
done
