FROM python:3.11-slim-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends bash curl jq ca-certificates libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

RUN pip install --no-cache-dir insightface onnxruntime opencv-python-headless numpy

WORKDIR /app
COPY extract_faces.py worker.sh ./
RUN chmod +x worker.sh \
    && python3 -c "from insightface.app import FaceAnalysis; app = FaceAnalysis(name='buffalo_l', providers=['CPUExecutionProvider']); app.prepare(ctx_id=0, det_size=(640, 640))"

ENV EFPIC_WORK_DIR=/tmp/efpic-face
ENV EFPIC_POLL_SEC=15
ENV EFPIC_EXTRACT_PY=/app/extract_faces.py

CMD ["bash", "/app/worker.sh"]
