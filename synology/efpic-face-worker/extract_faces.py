#!/usr/bin/env python3
"""Extract face embeddings from a single image (selfie search jobs)."""

from __future__ import annotations

import json
import sys

from face_engine import create_app, extract_faces


def main() -> int:
    if len(sys.argv) < 2:
        print(json.dumps([]))
        return 1
    app = create_app()
    faces = extract_faces(app, sys.argv[1])
    print(json.dumps(faces))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
