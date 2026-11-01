"""Disposable P10 worker probe; launches the real runtime and fault drill."""

import json
import os
from pathlib import Path
import sys
import traceback


def run():
    if os.environ.get("P10_WORKERS_SMOKE") != "disposable-local-only":
        raise RuntimeError("Explicit disposable worker opt-in required")
    if len(sys.argv) != 2 or sys.argv[1] not in ("mixed", "recovery"):
        raise ValueError("Usage: smoke_workers.py mixed|recovery")
    fixtures = json.load(sys.stdin)
    navigation, checkout = fixtures["navigation"], fixtures["checkout"]
    from tests.test_workers_postgres import mixed_load, recovery
    result = mixed_load(navigation, checkout) if sys.argv[1] == "mixed" else recovery(navigation, checkout)
    import django
    return {"status": "passed", "mode": sys.argv[1], "python": sys.version.split()[0],
            "django": django.get_version(), **result}


if __name__ == "__main__":
    try:
        print(json.dumps(run(), indent=2))
    except Exception as error:
        evidence = Path("/evidence")
        if evidence.is_dir():
            diagnostic = evidence / "probe-failure.txt"
            diagnostic.write_text(traceback.format_exc())
            diagnostic.chmod(0o600)
        frame = error.__traceback__
        while frame and frame.tb_next:
            frame = frame.tb_next
        details = {"status": "failed", "error_type": type(error).__name__}
        if frame:
            details.update(file=frame.tb_frame.f_code.co_filename.rsplit("/", 1)[-1],
                           line=frame.tb_lineno, function=frame.tb_frame.f_code.co_name)
        print(json.dumps(details), file=sys.stderr)
        raise SystemExit(1) from None
