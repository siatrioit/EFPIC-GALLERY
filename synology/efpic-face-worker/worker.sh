#!/bin/bash

# Pirms set -o pipefail — bind-mount no Windows bieži ir CRLF (Synology).
if [ -z "${EFPIC_WORKER_REEXECED:-}" ] && grep -q $'\r' "$0" 2>/dev/null; then
  sed 's/\r$//' "$0" > /tmp/efpic-face-worker.sh
  chmod +x /tmp/efpic-face-worker.sh
  export EFPIC_WORKER_REEXECED=1
  exec /tmp/efpic-face-worker.sh
fi

set -euo pipefail

WORKER_VERSION="1.9.137"

: "${EFPIC_API_BASE:?Set EFPIC_API_BASE}"
: "${EFPIC_API_TOKEN:?Set EFPIC_API_TOKEN}"

AUTH="Authorization: Bearer ${EFPIC_API_TOKEN}"
POLL_SEC="${EFPIC_POLL_SEC:-60}"
CLAIM_BACKOFF_MAX="${EFPIC_CLAIM_FAIL_MAX_SEC:-300}"
CLAIM_BACKOFF_SEC="$POLL_SEC"
WORKDIR="${EFPIC_WORK_DIR:-/tmp/efpic-face}"
EXTRACT_BATCH="${EFPIC_EXTRACT_BATCH:-/app/extract_batch.py}"
EXTRACT_SINGLE="${EFPIC_EXTRACT_PY:-/app/extract_faces.py}"
EXTRACT_SERVE="${EFPIC_EXTRACT_SERVE:-/app/extract_serve.py}"
FACE_API="${EFPIC_FACE_API_PY:-/app/face_api.py}"
COOLDOWN_SEC="${EFPIC_BATCH_COOLDOWN_SEC:-45}"
EXTRACT_TIMEOUT_SEC="${EFPIC_EXTRACT_TIMEOUT_SEC:-900}"
DAEMON_FIFO="${WORKDIR}/extract.req"
DAEMON_RESP="${WORKDIR}/extract.resp"
DAEMON_ERR="${WORKDIR}/daemon.err"

mkdir -p "$WORKDIR"

log() {
  printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

run_python() {
  if command -v ionice >/dev/null 2>&1; then
    ionice -c 3 nice -n "${EFPIC_FACE_NICE:-19}" python3 "$@"
  else
    nice -n "${EFPIC_FACE_NICE:-19}" python3 "$@"
  fi
}

require_worker_files() {
  local missing=""
  for f in "$EXTRACT_BATCH" "$EXTRACT_SINGLE" /app/face_engine.py "$FACE_API"; do
    if [ ! -f "$f" ]; then
      missing="$missing $f"
    fi
  done
  if [ -n "$missing" ]; then
    log "TRŪKST failu:$missing"
    log "Nokopē VISU synology/efpic-face-worker/ uz NAS + atjaunini docker-compose.yml + Recreate"
    log "Pārbaude: docker exec efpic-face-worker sh /app/nas-verify.sh"
    exit 1
  fi
}

fail_job() {
  local job_id="$1"
  local message="$2"
  python3 "$FACE_API" fail "$EFPIC_API_BASE" "$EFPIC_API_TOKEN" "$job_id" "$message" >/dev/null 2>&1 || true
}

ping_server() {
  curl -sf -H "$AUTH" "${EFPIC_API_BASE}/api/face/ping" >/dev/null || true
}

start_extract_daemon() {
  if [ ! -f "$EXTRACT_SERVE" ]; then
    return 1
  fi
  if [ -n "${DAEMON_READY:-}" ] && kill -0 "${DAEMON_PID:-0}" 2>/dev/null; then
    return 0
  fi
  DAEMON_READY=
  rm -f "$DAEMON_FIFO" "$DAEMON_RESP"
  mkfifo "$DAEMON_FIFO" "$DAEMON_RESP"
  run_python "$EXTRACT_SERVE" < "$DAEMON_FIFO" > "$DAEMON_RESP" 2>>"$DAEMON_ERR" &
  DAEMON_PID=$!
  exec 3>"$DAEMON_FIFO"
  exec 4<"$DAEMON_RESP"
  log "InsightFace ielāde (~1–3 min) — DSM var kļūt lēns, tas ir normāli"
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

run_extract_oneshot() {
  local manifest="$1"
  local results_file="$2"
  local pyerr="$3"
  log "extract one-shot (bez daemon)"
  run_python "$EXTRACT_BATCH" < "$manifest" > "$results_file" 2>"$pyerr" &
  local py_pid=$!
  while kill -0 "$py_pid" 2>/dev/null; do
    sleep 45
    if kill -0 "$py_pid" 2>/dev/null; then
      ping_server
      log "still extracting…"
    fi
  done
  wait "$py_pid"
}

run_extract_daemon() {
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

run_extract() {
  local manifest="$1"
  local results_file="$2"
  local pyerr="$3"
  if [ -f "$EXTRACT_SERVE" ]; then
    run_extract_daemon "$manifest" "$results_file" "$pyerr"
  else
    run_extract_oneshot "$manifest" "$results_file" "$pyerr"
  fi
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
    echo '[]' > "$results_file"
    python3 "$FACE_API" batch "$EFPIC_API_BASE" "$EFPIC_API_TOKEN" "$job_id" "$results_file" >/dev/null
    python3 "$FACE_API" complete "$EFPIC_API_BASE" "$EFPIC_API_TOKEN" "$job_id" >/dev/null
    return 0
  fi

  echo "$items" > "$manifest"
  local count
  count="$(echo "$items" | jq 'length')"
  log "extracting ${job_id} (${count} images)"
  ping_server
  : >"$pyerr"

  if ! run_extract "$manifest" "$results_file" "$pyerr"; then
    log "extract failed job ${job_id}: $(tail -n 3 "$pyerr" 2>/dev/null | tr '\n' ' ')"
    fail_job "$job_id" "Face extract failed"
    rm -f "$manifest" "$results_file" "$pyerr" "${WORKDIR}/${job_id}"-*.jpg
    return 1
  fi

  if ! python3 -c "import json,sys; json.load(open(sys.argv[1]))" "$results_file" 2>/dev/null; then
    log "invalid results json job ${job_id}: $(head -c 120 "$results_file" 2>/dev/null | tr '\n' ' ')"
    fail_job "$job_id" "Invalid face extract output"
    rm -f "$manifest" "$results_file" "$pyerr" "${WORKDIR}/${job_id}"-*.jpg
    return 1
  fi

  if ! python3 "$FACE_API" batch "$EFPIC_API_BASE" "$EFPIC_API_TOKEN" "$job_id" "$results_file"; then
    log "batch upload failed job ${job_id}"
    fail_job "$job_id" "Face batch upload failed"
    rm -f "$manifest" "$results_file" "$pyerr" "${WORKDIR}/${job_id}"-*.jpg
    return 1
  fi

  python3 "$FACE_API" complete "$EFPIC_API_BASE" "$EFPIC_API_TOKEN" "$job_id" >/dev/null
  local done_count
  done_count="$(python3 -c "import json,sys; print(len(json.load(open(sys.argv[1]))))" "$results_file")"
  rm -f "$manifest" "$results_file" "$pyerr" "${WORKDIR}/${job_id}"-*.jpg
  log "index job ${job_id} done (${done_count} images)"
}

process_search_job() {
  local job_json="$1"
  local job_id selfie_url tmp faces_file
  job_id="$(echo "$job_json" | jq -r '.job.id')"
  selfie_url="$(echo "$job_json" | jq -r '.job.selfie_url')"
  log "search job ${job_id}"
  tmp="${WORKDIR}/${job_id}-selfie.jpg"
  faces_file="${WORKDIR}/${job_id}-faces.json"
  if ! curl -sf -H "$AUTH" -o "$tmp" "$selfie_url"; then
    fail_job "$job_id" "Neizdevās lejupielādēt selfiju"
    return 1
  fi
  if ! run_python "$EXTRACT_SINGLE" "$tmp" > "$faces_file" 2>/dev/null; then
    echo '[]' > "$faces_file"
  fi
  rm -f "$tmp"
  if ! python3 -c "import json,sys; f=json.load(open(sys.argv[1])); sys.exit(0 if isinstance(f,list) and len(f)>0 else 1)" "$faces_file"; then
    fail_job "$job_id" "Seja selfijā nav atrasta"
    rm -f "$faces_file"
    return 1
  fi
  python3 "$FACE_API" search-complete "$EFPIC_API_BASE" "$EFPIC_API_TOKEN" "$job_id" "$faces_file" >/dev/null
  rm -f "$faces_file"
  log "search job ${job_id} done"
}

require_worker_files
log "EFPIC face worker ${WORKER_VERSION} start — ${EFPIC_API_BASE}"
log "ekonomijas režīms: model=${EFPIC_FACE_MODEL:-buffalo_s} threads=${EFPIC_FACE_THREADS:-1} det=${EFPIC_FACE_DET_SIZE:-256}"

while true; do
  resp=""
  if ! resp="$(curl -sf -X POST -H "$AUTH" "${EFPIC_API_BASE}/api/face/claim")"; then
    log "claim failed — gaidu ${CLAIM_BACKOFF_SEC}s (max ${CLAIM_BACKOFF_MAX}s; hosting IP bloķējums?)"
    sleep "$CLAIM_BACKOFF_SEC"
    next=$((CLAIM_BACKOFF_SEC * 2))
    if [ "$next" -gt "$CLAIM_BACKOFF_MAX" ]; then
      next="$CLAIM_BACKOFF_MAX"
    fi
    CLAIM_BACKOFF_SEC="$next"
    continue
  fi
  CLAIM_BACKOFF_SEC="$POLL_SEC"

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
    log "cooldown ${COOLDOWN_SEC}s pirms nākamās bildes"
    sleep "$COOLDOWN_SEC"
  fi
done
