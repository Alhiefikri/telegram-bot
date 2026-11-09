"""Manual isolated fake-only P25 batch; never started by the P10 supervisor."""
import argparse
import json
import signal

from lhie_candidate.workers.consumer import WorkerTimeout


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--once', action='store_true', required=True)
    parser.add_argument('--family', choices=('telegram', 'credential-mail', 'waitlist'), required=True)
    args = parser.parse_args()
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.readiness import verify_dependencies
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.runtime import application_key
    from lhie_candidate.notifications.mail import CredentialMail
    from lhie_candidate.notifications.policy import TestRecipients
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from lhie_candidate.receipt.source import ReceiptSource
    from lhie_candidate.workers.consumer import _alarm_handler
    verify_dependencies()
    # Only P01's synthetic chat is admitted. This CLI is not a live recipient
    # enrollment surface and accepts no recipient/content/transport arguments.
    recipients = TestRecipients(frozenset({'1'}))
    if args.family == 'telegram':
        from lhie_candidate.notifications.order_notices import OrderNotices
        from lhie_candidate.notifications.proof_publication import SalesProofPublication
        service = Notifications(NotificationTransport(), recipients=recipients, admin_target='1',
                                proof=SalesProofPublication(recipients), order_notices=OrderNotices(recipients))
    elif args.family == 'credential-mail':
        cipher = LaravelEncrypter(application_key())
        source = ReceiptSource(cipher, signing_key=application_key(), invoice_base_url='http://127.0.0.1:18801')
        service = CredentialMail(source, cipher, recipients=recipients)
    else:
        from lhie_candidate.notifications.waitlist import Waitlist
        service = Waitlist(LaravelEncrypter(application_key()), application_key(), enabled=True)
    previous = signal.signal(signal.SIGALRM, _alarm_handler)
    signal.alarm(60)
    try:
        result = service.recover(25)
    finally:
        signal.alarm(0)
        signal.signal(signal.SIGALRM, previous)
    print(json.dumps({'status': 'processed', **result}))


if __name__ == '__main__':
    try:
        main()
    except (Exception, WorkerTimeout):
        print('{"status":"notification_refused"}')
        raise SystemExit(1) from None
