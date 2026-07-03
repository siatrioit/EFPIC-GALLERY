FROM python:3.11-slim-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends bash curl jq ca-certificates libgl1 libglib2.0-0 util-linux \
    && rm -rf /var/lib/apt/lists/*

RUN pip install --no-cache-dir insightface onnxruntime opencv-python-headless numpy

WORKDIR /app
COPY extract_faces.py extract_batch.py extract_serve.py face_engine.py face_api.py nas-verify.sh worker.sh ./
RUN chmod +x worker.sh nas-verify.sh

ENV EFPIC_WORK_DIR=/tmp/efpic-face
ENV EFPIC_POLL_SEC=30
ENV EFPIC_EXTRACT_PY=/app/extract_faces.py
ENV EFPIC_EXTRACT_BATCH=/app/extract_batch.py
ENV EFPIC_EXTRACT_SERVE=/app/extract_serve.py
ENV EFPIC_FACE_MODEL=buffalo_s
ENV EFPIC_FACE_THREADS=1
ENV EFPIC_FACE_DET_SIZE=256
ENV EFPIC_FACE_MAX_EDGE=640
ENV EFPIC_FACE_NICE=19
ENV EFPIC_BATCH_COOLDOWN_SEC=90

CMD ["bash", "/app/worker.sh"]
