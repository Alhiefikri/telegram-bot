"""Inspect IDR balance projections without changing ledger history."""
import argparse
from dataclasses import asdict
import json
import sys

from lhie_candidate.startup import configure_django, FAILURE


def positive_integer(value):
    if not value.isascii() or not value.isdecimal() or value.startswith('0'):
        raise argparse.ArgumentTypeError('Expected a positive integer')
    number = int(value)
    if number > (1 << 63) - 1:
        raise argparse.ArgumentTypeError('Integer exceeds the database limit')
    return number


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--customer', type=positive_integer)
    parser.add_argument('--limit', type=positive_integer, default=100)
    args = parser.parse_args()
    configure_django()
    from lhie_candidate.funding.reconciliation import StoreBalanceReconciliation
    from lhie_candidate.readiness import verify_dependencies

    verify_dependencies()
    report = StoreBalanceReconciliation().inspect(customer_id=args.customer, limit=args.limit)
    print(json.dumps({'consistent': report.consistent, 'accounts_inspected': report.accounts_inspected,
                      'mismatch_count': len(report.mismatches),
                      'mismatches': [asdict(item) for item in report.mismatches]}))
    return 0 if report.consistent else 1


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception:
        sys.stderr.write(FAILURE)
        raise SystemExit(1)
