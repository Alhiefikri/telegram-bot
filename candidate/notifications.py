"""One bounded run of the explicitly admitted, isolated P25 consumers."""
import argparse
import json
import signal

from lhie_candidate.workers.consumer import WorkerTimeout


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--once', action='store_true', required=True)
    parser.add_argument('--family', choices=('telegram', 'bulk', 'credential-mail', 'waitlist-mail', 'auth-mail'),
                        required=True)
    args = parser.parse_args()
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.readiness import verify_dependencies
    from lhie_candidate.runtime import load_runtime
    from lhie_candidate.workers.consumer import _alarm_handler
    from lhie_candidate.workers.services import build_handler
    runtime = load_runtime()
    if not runtime.workers or not runtime.notifications:
        raise RuntimeError('Notification admission refused')
    verify_dependencies()
    queue = {'telegram': 'notifications', 'bulk': 'bulk', 'auth-mail': 'auth-mail'}.get(args.family, 'mail')
    handler = build_handler(queue)
    if queue == 'mail':
        kind = 'credential.mail' if args.family == 'credential-mail' else 'waitlist.mail'
        service = handler.services[kind]
    else:
        service = handler.service
    previous = signal.signal(signal.SIGALRM, _alarm_handler)
    signal.alarm(60)
    try:
        result = service.recover(25)
    finally:
        signal.alarm(0)
        signal.signal(signal.SIGALRM, previous)
    print(json.dumps({'status': 'processed', 'result': result}))


if __name__ == '__main__':
    try:
        main()
    except (Exception, WorkerTimeout):
        print('{"status":"notification_refused"}')
        raise SystemExit(1) from None
