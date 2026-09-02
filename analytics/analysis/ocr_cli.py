#!/usr/bin/env python3
"""Standalone OCR worker: image file in argv[1], JSON text lines on stdout.

Runs as a short-lived subprocess so the ONNX engine's memory (and any
crash) never touches the long-lived analytics service - on a shared host
with an account-wide memory ceiling, the engine only exists for the
seconds an upload is being read.
"""
import json
import os
import sys

# Shared-host thread ceiling: OpenBLAS/ONNX default to one thread per CPU
# (32 here) and pthread_create hits the account's NPROC limit. Single-
# threaded is fine - one small screenshot, a few seconds of work.
for _var in ("OPENBLAS_NUM_THREADS", "OMP_NUM_THREADS", "MKL_NUM_THREADS",
             "NUMEXPR_NUM_THREADS", "VECLIB_MAXIMUM_THREADS"):
    os.environ.setdefault(_var, "1")


def main() -> int:
    try:
        from rapidocr_onnxruntime import RapidOCR
        try:
            engine = RapidOCR(intra_op_num_threads=1, inter_op_num_threads=1)
        except TypeError:
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
