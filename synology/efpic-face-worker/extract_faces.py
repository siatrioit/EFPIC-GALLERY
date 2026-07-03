#!/usr/bin/env python3
"""Extract face embeddings with InsightFace buffalo_l (512-dim, L2-normalized)."""

from __future__ import annotations

import base64
import json
import sys

import cv2
import numpy as np
from insightface.app import FaceAnalysis


def main() -> int:
    if len(sys.argv) < 2:
        print(json.dumps([]))
        return 1
    path = sys.argv[1]
    img = cv2.imread(path)
    if img is None:
        print(json.dumps([]))
        return 1

    app = FaceAnalysis(name="buffalo_l", providers=["CPUExecutionProvider"])
    app.prepare(ctx_id=0, det_size=(640, 640))
    faces = app.get(img)
    out = []
    for face in faces:
        emb = face.embedding.astype(np.float32)
        norm = float(np.linalg.norm(emb))
        if norm > 0:
            emb = emb / norm
        out.append(
            {
                "bbox": [float(x) for x in face.bbox.tolist()],
                "v": base64.b64encode(emb.tobytes()).decode("ascii"),
            }
        )
    print(json.dumps(out))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
