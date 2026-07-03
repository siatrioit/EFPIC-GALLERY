#!/usr/bin/env python3
"""POST face worker payloads without shell/jq."""

from __future__ import annotations

import json
import sys
import urllib.error
import urllib.request


def post_json(url: str, token: str, payload: dict, timeout: int = 180) -> None:
    body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=body,
        headers={
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        resp.read()


def main() -> int:
    if len(sys.argv) < 2:
        return 2
    cmd = sys.argv[1]
    if cmd == "batch":
        if len(sys.argv) != 6:
            return 2
        _, _, base_url, token, job_id, results_path = sys.argv
        with open(results_path, encoding="utf-8") as handle:
            results = json.load(handle)
        if not isinstance(results, list):
            raise ValueError("results must be a JSON array")
        url = f"{base_url.rstrip('/')}/api/face/jobs/{job_id}/batch"
        post_json(url, token, {"results": results})
        return 0
    if cmd == "complete":
        if len(sys.argv) != 5:
            return 2
        _, _, base_url, token, job_id = sys.argv
        url = f"{base_url.rstrip('/')}/api/face/jobs/{job_id}/complete"
        post_json(url, token, {})
        return 0
    if cmd == "search-complete":
        if len(sys.argv) != 6:
            return 2
        _, _, base_url, token, job_id, faces_path = sys.argv
        with open(faces_path, encoding="utf-8") as handle:
            faces = json.load(handle)
        if not isinstance(faces, list):
            raise ValueError("faces must be a JSON array")
        url = f"{base_url.rstrip('/')}/api/face/jobs/{job_id}/complete"
        post_json(url, token, {"faces": faces})
        return 0
    if cmd == "fail":
        if len(sys.argv) != 6:
            return 2
        _, _, base_url, token, job_id, message = sys.argv
        url = f"{base_url.rstrip('/')}/api/face/jobs/{job_id}/fail"
        post_json(url, token, {"error": message})
        return 0
    return 2


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except urllib.error.HTTPError as exc:
        sys.stderr.write(f"HTTP {exc.code}: {exc.read().decode('utf-8', errors='replace')}\n")
        raise SystemExit(1) from exc
    except Exception as exc:  # noqa: BLE001
        sys.stderr.write(f"{exc}\n")
        raise SystemExit(1) from exc
