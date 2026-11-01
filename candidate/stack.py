"""Supervise the candidate HTTP server and the three isolated queue workers."""

import argparse
import os
from pathlib import Path
import sys


ROOT = Path(__file__).resolve().parent


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--metrics-dir", default="/tmp")
    args = parser.parse_args(argv)
    from lhie_candidate.runtime import load_runtime
    runtime = load_runtime()
    if not runtime.workers:
        # P05/P06 raw stacks stay HTTP-only; P10 admission enables the workers.
        os.execv(sys.executable, [sys.executable, str(ROOT / "serve.py")])
    from lhie_candidate.workers.supervisor import run
    return run(args.metrics_dir, include_http=True)


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except SystemExit:
        raise
    except Exception:
        sys.stderr.write("candidate stack refused\n")
        raise SystemExit(1) from None
