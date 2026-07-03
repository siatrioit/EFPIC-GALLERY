#!/usr/bin/env python3
"""Extract faces for a batch of images — one-shot mode (stdin JSON array)."""

from __future__ import annotations

import json
import sys

from face_engine import create_app, process_items


def main() -> int:
    try:
        items = json.load(sys.stdin)
    except json.JSONDecodeError:
        print("[]")
        return 1
    if not isinstance(items, list):
        print("[]")
        return 1

    app = create_app()
    results = process_items(app, items)
    sys.stdout.write(json.dumps(results, separators=(",", ":")))
    sys.stdout.flush()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
