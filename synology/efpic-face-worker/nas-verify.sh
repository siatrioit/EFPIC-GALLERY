#!/bin/sh
# Pārbauda, vai konteinerī ir jaunā face worker versija. Palaid:
#   docker exec efpic-face-worker sh /app/nas-verify.sh

echo "=== EFPIC face worker verify ==="
grep -q 'WORKER_VERSION=' /app/worker.sh 2>/dev/null && echo "OK  worker.sh (jauna versija)" || echo "FAIL worker.sh — VECAIS, nokopē no repo"
grep -q 'extracting' /app/worker.sh 2>/dev/null && echo "OK  daemon/batch režīms" || echo "FAIL worker.sh — nav batch režīma"
test -f /app/face_engine.py && echo "OK  face_engine.py" || echo "FAIL face_engine.py — trūkst"
test -f /app/extract_serve.py && echo "OK  extract_serve.py" || echo "FAIL extract_serve.py — trūkst"
test -f /app/extract_batch.py && echo "OK  extract_batch.py" || echo "FAIL extract_batch.py — trūkst"
test -f /app/face_api.py && echo "OK  face_api.py" || echo "FAIL face_api.py — trūkst"
echo "=== Ja kāds FAIL — nokopē VISUS failus uz NAS projekta mapi un Recreate ==="
