#!/usr/bin/env python3
"""Standalone OCR worker: image file in argv[1], JSON text lines on stdout.

Runs as a short-lived subprocess so the ONNX engine's memory (and any
crash) never touches the long-lived analytics service - on a shared host
with an account-wide memory ceiling, the engine only exists for the
seconds an upload is being read.
"""
import json
import sys


def main() -> int:
    try:
        from rapidocr_onnxruntime import RapidOCR
        engine = RapidOCR()
        result, _ = engine(sys.argv[1])
        lines = [item[1] for item in (result or []) if len(item) > 1]
        print(json.dumps({"ok": True, "lines": lines}))
        return 0
    except Exception as exc:  # noqa: BLE001 - the parent needs the reason
        print(json.dumps({"ok": False, "error": str(exc)}))
        return 1


if __name__ == "__main__":
    sys.exit(main())
