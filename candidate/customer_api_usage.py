"""Drain bounded Customer API usage buckets in the admitted local runtime."""
import json
import sys

from lhie_candidate.startup import FAILURE, configure_django


def main():
    if sys.argv[1:] != ['--once']:
        raise ValueError('Use customer_api_usage.py --once')
    configure_django()
    from lhie_candidate.customer_api.admission import Admission
    from lhie_candidate.customer_api.usage import flush
    from lhie_candidate.readiness import verify_dependencies
    verify_dependencies()
    print(json.dumps({'processed': flush(Admission(), limit=500), 'batch_limit': 500}))


if __name__ == '__main__':
    try:
        main()
    except Exception:
        sys.stderr.write(FAILURE)
        raise SystemExit(1) from None
