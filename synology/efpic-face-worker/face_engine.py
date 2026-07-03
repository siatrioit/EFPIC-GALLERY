#!/usr/bin/env python3
"""Shared InsightFace helpers — tuned for Synology CPU."""

from __future__ import annotations

import base64
import json
import os
import warnings

warnings.filterwarnings("ignore")
os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "3")

import cv2
import numpy as np
from insightface.app import FaceAnalysis


def apply_thread_limits() -> None:
    threads = str(os.environ.get("EFPIC_FACE_THREADS", "2")).strip() or "2"
    for key in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS", "NUMEXPR_NUM_THREADS"):
        os.environ[key] = threads


def apply_nice() -> None:
    try:
        nice = int(os.environ.get("EFPIC_FACE_NICE", "10"))
        os.nice(nice)
    except (TypeError, ValueError, OSError):
        pass


def det_size() -> tuple[int, int]:
    try:
        size = int(os.environ.get("EFPIC_FACE_DET_SIZE", "320"))
    except ValueError:
        size = 320
    size = max(160, min(size, 640))
    return (size, size)


def model_name() -> str:
    name = str(os.environ.get("EFPIC_FACE_MODEL", "buffalo_l")).strip()
    return name or "buffalo_l"


def create_app() -> FaceAnalysis:
    apply_thread_limits()
    apply_nice()
    app = FaceAnalysis(name=model_name(), providers=["CPUExecutionProvider"])
    app.prepare(ctx_id=0, det_size=det_size())
    return app


def load_image(path: str) -> np.ndarray | None:
    img = cv2.imread(path)
    if img is None:
        return None
    try:
        max_edge = int(os.environ.get("EFPIC_FACE_MAX_EDGE", "960"))
    except ValueError:
        max_edge = 960
    if max_edge <= 0:
        return img
    height, width = img.shape[:2]
    longest = max(height, width)
    if longest <= max_edge:
        return img
    scale = max_edge / float(longest)
    resized = cv2.resize(img, (int(width * scale), int(height * scale)), interpolation=cv2.INTER_AREA)
    return resized


def extract_faces(app: FaceAnalysis, path: str) -> list[dict]:
    img = load_image(path)
    if img is None:
        return []
    out = []
    for face in app.get(img):
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
    return out


def process_items(app: FaceAnalysis, items: list) -> list[dict]:
    results = []
    for item in items:
        if not isinstance(item, dict):
            continue
        token = str(item.get("token") or "")
        path = str(item.get("path") or "")
        if token == "" or path == "" or not os.path.isfile(path):
            if token != "":
                results.append({"token": token, "faces": []})
            continue
        results.append({"token": token, "faces": extract_faces(app, path)})
    return results


def process_manifest_file(app: FaceAnalysis, manifest_path: str) -> list[dict]:
    with open(manifest_path, encoding="utf-8") as handle:
        items = json.load(handle)
    if not isinstance(items, list):
        return []
    return process_items(app, items)
