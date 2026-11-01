"""Run one bounded queue worker, or the three initial worker processes."""

import argparse
import signal
import sys


def _worker(queue, metrics_path, iterations):
    from lhie_candidate.runtime import load_runtime
    if not load_runtime().workers:
        raise RuntimeError("Worker admission refused")
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.readiness import verify_database
    verify_database()
    from lhie_candidate.workers.consumer import BoundedConsumer
    from lhie_candidate.workers.contract import queue_spec
    from lhie_candidate.workers.metrics import WorkerMetrics
    from lhie_candidate.workers.services import build_handler

    spec = queue_spec(queue)
    metrics = WorkerMetrics(queue)
    consumer = BoundedConsumer(spec, build_handler(queue), metrics)
    signal.signal(signal.SIGTERM, consumer.request_stop)
    signal.signal(signal.SIGINT, consumer.request_stop)
    try:
        consumer.run(max_iterations=iterations)
    finally:
        if metrics_path:
            metrics.write(metrics_path)
    return 0


def _supervisor(metrics_dir):
    from lhie_candidate.runtime import load_runtime
    if not load_runtime().workers:
        raise RuntimeError("Worker admission refused")
    from lhie_candidate.workers.supervisor import run
    return run(metrics_dir, include_http=False)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--queue", help="run one isolated queue consumer")
    group.add_argument("--supervisor", action="store_true", help="supervise all queue consumers")
    parser.add_argument("--metrics", help="write one queue's bounded metrics here")
    parser.add_argument("--metrics-dir", default="/tmp", help="supervisor metrics directory")
    parser.add_argument("--iterations", type=int, default=None,
                        help="bounded test/ops run; omit for the normal long-lived consumer")
    args = parser.parse_args(argv)
    if args.queue:
        from lhie_candidate.workers.contract import validate_queue_name
        validate_queue_name(args.queue)
        if args.iterations is not None and not 1 <= args.iterations <= 10000:
            parser.error("invalid iteration bound")
        return _worker(args.queue, args.metrics, args.iterations)
    if args.metrics or args.iterations is not None:
        parser.error("supervisor mode accepts only --metrics-dir")
    return _supervisor(args.metrics_dir)


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except SystemExit:
        raise
    except Exception:
        sys.stderr.write("candidate worker refused\n")
        raise SystemExit(1) from None
