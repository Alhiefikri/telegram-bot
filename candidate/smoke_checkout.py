"""Manual local P07 proof; no workers, provider requests or Telegram sends."""
import json
import os
import sys


def run():
    if os.environ.get('P07_CHECKOUT_SMOKE') != 'disposable-local-only':
        raise RuntimeError('Explicit disposable checkout opt-in required')
    from lhie_candidate.startup import configure_django
    configure_django()
    from tests.test_checkout_postgres import exercise_checkout_protocol
    fixtures = json.load(sys.stdin)
    outcomes = exercise_checkout_protocol(fixtures)
    import django
    return {'status':'passed','dataset':'synthetic-p07-checkout-v1',
            'python':sys.version.split()[0],'django':django.get_version(),**outcomes}


if __name__ == '__main__':
    try:
        print(json.dumps(run(),indent=2))
    except Exception as error:
        print(json.dumps({'status':'failed','error_type':type(error).__name__}),file=sys.stderr)
        import traceback
        from pathlib import Path
        diagnostic = Path('/evidence/probe-failure.txt')
        diagnostic.write_text(traceback.format_exc())
        diagnostic.chmod(0o600)
        raise SystemExit(1)
