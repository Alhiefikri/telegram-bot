"""Run a bounded, local receipt batch or durable recovery pass."""
import json
import sys


def main():
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.readiness import verify_dependencies
    from lhie_candidate.receipt.jobs import take
    from lhie_candidate.receipt.runtime import receipt_service
    verify_dependencies()
    service = receipt_service()
    if sys.argv[1:] == ['--recover']:
        result = service.recover()
    elif sys.argv[1:] == ['--once']:
        outcomes = []
        for _ in range(25):
            job = take()
            if job is None:
                break
            result = service.handle(job)
            outcomes.append(result if isinstance(result, str) else ('materialized' if result else 'ineligible'))
        result = {'outcomes': outcomes}
    else:
        raise ValueError('Invalid receipt command')
    print(json.dumps({'status': 'processed', **result}))


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('{"status":"receipt_refused"}')
        raise SystemExit(1) from None
