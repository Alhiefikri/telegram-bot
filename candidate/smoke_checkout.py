"""Manual local P07 proof; no workers, provider requests or Telegram sends."""
import json
import os
import sys


def run():
    if os.environ.get('P07_CHECKOUT_SMOKE') != 'disposable-local-only':
        raise RuntimeError('Explicit disposable checkout opt-in required')
    from lhie_candidate.startup import configure_django
    configure_django()
    if os.environ.get('P18_SUPPLIER_SMOKE') == '1':
        from tests.test_supplier_postgres import exercise_supplier_protocol
        exercise = exercise_supplier_protocol
    elif os.environ.get('P19_AUTH_SMOKE') == '1':
        from tests.test_auth_postgres import exercise_auth_protocol
        exercise = exercise_auth_protocol
    elif os.environ.get('P12_LIFECYCLE_SMOKE') == '1':
        from tests.test_order_lifecycle_postgres import exercise_order_lifecycle
        exercise = exercise_order_lifecycle
    elif os.environ.get('P07_RECEIPT_SMOKE') == '1':
        from tests.test_receipt_postgres import exercise_receipt_protocol
        exercise = exercise_receipt_protocol
    else:
        from tests.test_checkout_postgres import exercise_checkout_protocol
        exercise = exercise_checkout_protocol
    fixtures = json.load(sys.stdin)
    outcomes = exercise(fixtures)
    import django
    dataset = ('synthetic-p18-supplier-v1' if os.environ.get('P18_SUPPLIER_SMOKE') == '1'
               else 'synthetic-p19-auth-v1' if os.environ.get('P19_AUTH_SMOKE') == '1'
               else 'synthetic-p12-lifecycle-v1' if os.environ.get('P12_LIFECYCLE_SMOKE') == '1'
               else 'synthetic-p07-checkout-v1')
    return {'status':'passed','dataset':dataset,
            'python':sys.version.split()[0],'django':django.get_version(),**outcomes}


if __name__ == '__main__':
    try:
        print(json.dumps(run(),indent=2))
    except Exception as error:
        details = {'status':'failed','error_type':type(error).__name__}
        frame = error.__traceback__
        while frame and frame.tb_next:
            frame = frame.tb_next
        if frame:
            details.update(file=frame.tb_frame.f_code.co_filename.rsplit('/', 1)[-1],
                           line=frame.tb_lineno, function=frame.tb_frame.f_code.co_name)
        print(json.dumps(details),file=sys.stderr)
        if os.environ.get('P07_RECEIPT_SMOKE') != '1':
            import traceback
            from pathlib import Path
            diagnostic = Path('/evidence/probe-failure.txt')
            diagnostic.write_text(traceback.format_exc(limit=30))
            diagnostic.chmod(0o600)
        raise SystemExit(1)
