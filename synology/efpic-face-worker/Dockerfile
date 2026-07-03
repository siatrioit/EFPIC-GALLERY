FROM python:3.11-slim-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends bash curl jq ca-certificates libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

RUN pip install --no-cache-dir insightface onnxruntime opencv-python-headless numpy

WORKDIR /app
COPY extract_faces.py extract_batch.py extract_serve.py face_engine.py face_api.py nas-verify.sh worker.sh ./
RUN chmod +x worker.sh nas-verify.sh \
    && EFPIC_FACE_THREADS=2 EFPIC_FACE_DET_SIZE=320 python3 -c "from face_engine import create_app; create_app()"

ENV EFPIC_WORK_DIR=/tmp/efpic-face
ENV EFPIC_POLL_SEC=15
ENV EFPIC_EXTRACT_PY=/app/extract_faces.py
ENV EFPIC_EXTRACT_BATCH=/app/extract_batch.py
ENV EFPIC_EXTRACT_SERVE=/app/extract_serve.py
ENV EFPIC_FACE_THREADS=2
ENV EFPIC_FACE_DET_SIZE=320
ENV EFPIC_BATCH_COOLDOWN_SEC=45

CMD ["bash", "/app/worker.sh"]
