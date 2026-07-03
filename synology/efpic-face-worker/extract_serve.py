#!/usr/bin/env python3
"""Long-running extractor — loads InsightFace model once, reads manifest paths from stdin."""

from __future__ import annotations

import json
import sys

from face_engine import create_app, process_manifest_file


def main() -> int:
    app = create_app()
    sys.stderr.write("face extractor ready\n")
    sys.stderr.flush()
    for line in sys.stdin:
        manifest = line.strip()
        if manifest == "":
            continue
        try:
            results = process_manifest_file(app, manifest)
            out_path = manifest.replace("-manifest.json", "-results.json")
            with open(out_path, "w", encoding="utf-8") as handle:
                json.dump(results, handle, separators=(",", ":"))
            print(f"OK:{out_path}", flush=True)
        except Exception as exc:  # noqa: BLE001 — worker needs a single-line error
            print(f"ERR:{exc}", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
