#!/bin/bash
set -euo pipefail

if [ -z "${EFPIC_WORKER_REEXECED:-}" ] && grep -q $'\r' "$0" 2>/dev/null; then
  sed 's/\r$//' "$0" > /tmp/efpic-face-worker.sh
  chmod +x /tmp/efpic-face-worker.sh
  export EFPIC_WORKER_REEXECED=1
  exec /tmp/efpic-face-worker.sh
fi

: "${EFPIC_API_BASE:?Set EFPIC_API_BASE}"
: "${EFPIC_API_TOKEN:?Set EFPIC_API_TOKEN}"

AUTH="Authorization: Bearer ${EFPIC_API_TOKEN}"
POLL_SEC="${EFPIC_POLL_SEC:-15}"
WORKDIR="${EFPIC_WORK_DIR:-/tmp/efpic-face}"
EXTRACT="${EFPIC_EXTRACT_PY:-./extract_faces.py}"

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
    "${EFPIC_API_BASE}/api/face/jobs/${job_id}/fail" >/dev/null || true
}

process_index_job() {
  local job_json="$1"
  local job_id
  job_id="$(echo "$job_json" | jq -r '.job.id')"
  log "index job ${job_id}"
  local results='[]'
  while IFS= read -r row; do
    [ -z "$row" ] && continue
    local token url tmp faces
    token="$(echo "$row" | jq -r '.token')"
    url="$(echo "$row" | jq -r '.url')"
    tmp="${WORKDIR}/${job_id}-${token}.jpg"
    if ! curl -sf -H "$AUTH" -o "$tmp" "$url"; then
      log "skip ${token} — download failed"
      continue
    fi
    faces="$(python3 "$EXTRACT" "$tmp" 2>/dev/null || echo '[]')"
    rm -f "$tmp"
    results="$(jq -c --arg t "$token" --argjson f "$faces" '. + [{token: $t, faces: $f}]' <<< "$results")"
  done < <(echo "$job_json" | jq -c '.job.images[]?')

  curl -sf -X POST \
    -H "$AUTH" \
    -H "Content-Type: application/json" \
    -d "$(jq -n --argjson r "$results" '{results: $r}')" \
    "${EFPIC_API_BASE}/api/face/jobs/${job_id}/batch" >/dev/null

  curl -sf -X POST -H "$AUTH" "${EFPIC_API_BASE}/api/face/jobs/${job_id}/complete" >/dev/null
  log "index job ${job_id} done"
}

process_search_job() {
  local job_json="$1"
  local job_id selfie_url tmp faces
  job_id="$(echo "$job_json" | jq -r '.job.id')"
  selfie_url="$(echo "$job_json" | jq -r '.job.selfie_url')"
  log "search job ${job_id}"
  tmp="${WORKDIR}/${job_id}-selfie.jpg"
  if ! curl -sf -H "$AUTH" -o "$tmp" "$selfie_url"; then
    fail_job "$job_id" "Neizdevās lejupielādēt selfiju"
    return 1
  fi
  faces="$(python3 "$EXTRACT" "$tmp" 2>/dev/null || echo '[]')"
  rm -f "$tmp"
  if [ "$(echo "$faces" | jq 'length')" -eq 0 ]; then
    fail_job "$job_id" "Seja selfijā nav atrasta"
    return 1
  fi
  curl -sf -X POST \
    -H "$AUTH" \
    -H "Content-Type: application/json" \
    -d "$(jq -n --argjson f "$faces" '{faces: $f}')" \
    "${EFPIC_API_BASE}/api/face/jobs/${job_id}/complete" >/dev/null
  log "search job ${job_id} done"
}

log "EFPIC face worker start — ${EFPIC_API_BASE}"

while true; do
  resp=""
  if ! resp="$(curl -sf -X POST -H "$AUTH" "${EFPIC_API_BASE}/api/face/claim")"; then
    log "claim failed — retry in ${POLL_SEC}s"
    sleep "$POLL_SEC"
    continue
  fi

  job_id="$(echo "$resp" | jq -r '.job.id // empty')"
  if [ -z "$job_id" ] || [ "$job_id" = "null" ]; then
    sleep "$POLL_SEC"
    continue
  fi

  job_type="$(echo "$resp" | jq -r '.job.type // "index"')"
  if [ "$job_type" = "search" ]; then
    process_search_job "$resp" || true
  else
    process_index_job "$resp" || fail_job "$job_id" "Index batch failed"
  fi
done
