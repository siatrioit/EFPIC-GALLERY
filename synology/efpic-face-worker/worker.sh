#!/bin/bash

# Pirms set -o pipefail — bind-mount no Windows bieži ir CRLF (Synology).
if [ -z "${EFPIC_WORKER_REEXECED:-}" ] && grep -q $'\r' "$0" 2>/dev/null; then
  sed 's/\r$//' "$0" > /tmp/efpic-face-worker.sh
  chmod +x /tmp/efpic-face-worker.sh
  export EFPIC_WORKER_REEXECED=1
  exec /tmp/efpic-face-worker.sh
fi

set -euo pipefail

: "${EFPIC_API_BASE:?Set EFPIC_API_BASE}"
: "${EFPIC_API_TOKEN:?Set EFPIC_API_TOKEN}"

AUTH="Authorization: Bearer ${EFPIC_API_TOKEN}"
POLL_SEC="${EFPIC_POLL_SEC:-15}"
WORKDIR="${EFPIC_WORK_DIR:-/tmp/efpic-face}"
EXTRACT_BATCH="${EFPIC_EXTRACT_BATCH:-/app/extract_batch.py}"
EXTRACT_SINGLE="${EFPIC_EXTRACT_PY:-/app/extract_faces.py}"
EXTRACT_SERVE="${EFPIC_EXTRACT_SERVE:-/app/extract_serve.py}"
COOLDOWN_SEC="${EFPIC_BATCH_COOLDOWN_SEC:-45}"
EXTRACT_TIMEOUT_SEC="${EFPIC_EXTRACT_TIMEOUT_SEC:-900}"
DAEMON_FIFO="${WORKDIR}/extract.req"
DAEMON_RESP="${WORKDIR}/extract.resp"
DAEMON_ERR="${WORKDIR}/daemon.err"

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

ping_server() {
  curl -sf -H "$AUTH" "${EFPIC_API_BASE}/api/face/ping" >/dev/null || true
}

safe_face_json() {
  local raw="$1"
  if [ -z "$raw" ]; then
    echo '[]'
    return
  fi
  if echo "$raw" | jq -e 'type == "array"' >/dev/null 2>&1; then
    echo "$raw"
    return
  fi
  echo '[]'
}

start_extract_daemon() {
  if [ -n "${DAEMON_READY:-}" ] && kill -0 "${DAEMON_PID:-0}" 2>/dev/null; then
    return 0
  fi
  DAEMON_READY=
  rm -f "$DAEMON_FIFO" "$DAEMON_RESP"
  mkfifo "$DAEMON_FIFO" "$DAEMON_RESP"
  python3 "$EXTRACT_SERVE" < "$DAEMON_FIFO" > "$DAEMON_RESP" 2>>"$DAEMON_ERR" &
  DAEMON_PID=$!
  exec 3>"$DAEMON_FIFO"
  exec 4<"$DAEMON_RESP"
  log "InsightFace ielāde (~1–3 min) — NAS var kļūt lēns; modelis tiek ielādēts tikai vienreiz"
  local waited=0
  while [ "$waited" -lt 300 ]; do
    if grep -q "face extractor ready" "$DAEMON_ERR" 2>/dev/null; then
      DAEMON_READY=1
      log "face extractor ready"
      return 0
    fi
    if ! kill -0 "$DAEMON_PID" 2>/dev/null; then
      log "extract daemon failed: $(tail -n 5 "$DAEMON_ERR" 2>/dev/null | tr '\n' ' ')"
      return 1
    fi
    sleep 5
    waited=$((waited + 5))
    if [ $((waited % 30)) -eq 0 ]; then
      ping_server
      log "still loading model…"
    fi
  done
  log "extract daemon load timeout"
  return 1
}

run_extract() {
  local manifest="$1"
  local results_file="$2"
  local pyerr="$3"
  start_extract_daemon || return 1
  printf '%s\n' "$manifest" >&3
  local resp=""
  if ! read -r -t "$EXTRACT_TIMEOUT_SEC" resp <&4; then
    log "extract timeout after ${EXTRACT_TIMEOUT_SEC}s"
    return 1
  fi
  case "$resp" in
    OK:*)
      cp "${resp#OK:}" "$results_file"
      return 0
      ;;
    ERR:*)
      echo "${resp#ERR:}" >>"$pyerr"
      return 1
      ;;
    *)
      echo "$resp" >>"$pyerr"
      return 1
      ;;
  esac
}

process_index_job() {
  local job_json="$1"
  local job_id
  job_id="$(echo "$job_json" | jq -r '.job.id')"
  log "index job ${job_id}"
  local manifest="${WORKDIR}/${job_id}-manifest.json"
  local results_file="${WORKDIR}/${job_id}-results.json"
  local pyerr="${WORKDIR}/${job_id}.pyerr"
  local items='[]'
  local row token url tmp

  while IFS= read -r row; do
    [ -z "$row" ] && continue
    token="$(echo "$row" | jq -r '.token')"
    url="$(echo "$row" | jq -r '.url')"
    tmp="${WORKDIR}/${job_id}-${token}.jpg"
    if ! curl -sf -H "$AUTH" -o "$tmp" "$url"; then
      log "skip ${token} — download failed"
      items="$(jq -c --arg t "$token" --arg p "" '. + [{token: $t, path: $p}]' <<< "$items")"
      continue
    fi
    items="$(jq -c --arg t "$token" --arg p "$tmp" '. + [{token: $t, path: $p}]' <<< "$items")"
  done < <(echo "$job_json" | jq -c '.job.images[]?')

  if [ "$(echo "$items" | jq 'length')" -eq 0 ]; then
    log "index job ${job_id} — no images"
    curl -sf -X POST \
      -H "$AUTH" \
      -H "Content-Type: application/json" \
      -d '{"results":[]}' \
      "${EFPIC_API_BASE}/api/face/jobs/${job_id}/batch" >/dev/null || true
    curl -sf -X POST -H "$AUTH" "${EFPIC_API_BASE}/api/face/jobs/${job_id}/complete" >/dev/null || true
    return 0
  fi

  echo "$items" > "$manifest"
  local count
  count="$(echo "$items" | jq 'length')"
  log "extracting ${job_id} (${count} images) — max ${EXTRACT_TIMEOUT_SEC}s"
  ping_server
  : >"$pyerr"

  if ! run_extract "$manifest" "$results_file" "$pyerr"; then
    log "extract failed job ${job_id}: $(tail -n 3 "$pyerr" 2>/dev/null | tr '\n' ' ')"
    fail_job "$job_id" "Face extract failed"
    rm -f "$manifest" "$results_file" "$pyerr" "${WORKDIR}/${job_id}"-*.jpg
    return 1
  fi

  local results
  results="$(cat "$results_file")"
  if ! echo "$results" | jq -e 'type == "array"' >/dev/null 2>&1; then
    log "invalid results json job ${job_id}"
    fail_job "$job_id" "Invalid face extract output"
    rm -f "$manifest" "$results_file" "$pyerr" "${WORKDIR}/${job_id}"-*.jpg
    return 1
  fi

  curl -sf -X POST \
    -H "$AUTH" \
    -H "Content-Type: application/json" \
    -d "$(jq -n --argjson r "$results" '{results: $r}')" \
    "${EFPIC_API_BASE}/api/face/jobs/${job_id}/batch" >/dev/null

  curl -sf -X POST -H "$AUTH" "${EFPIC_API_BASE}/api/face/jobs/${job_id}/complete" >/dev/null
  rm -f "$manifest" "$results_file" "$pyerr" "${WORKDIR}/${job_id}"-*.jpg
  log "index job ${job_id} done ($(echo "$results" | jq 'length') images)"
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
  faces="$(safe_face_json "$(python3 "$EXTRACT_SINGLE" "$tmp" 2>/dev/null || echo '[]')")"
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
    log "cooldown ${COOLDOWN_SEC}s pirms nākamās partijas"
    sleep "$COOLDOWN_SEC"
  fi
done
