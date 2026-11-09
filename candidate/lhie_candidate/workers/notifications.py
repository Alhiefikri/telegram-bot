"""Disjoint notice/bulk and fair bounded mail consumers; no critical queues."""
from .wakeup import decode


class NotificationHandler:
    def __init__(self, service, queue):
        if queue not in ('notice', 'bulk'):
            raise ValueError('Invalid notification queue')
        self.service, self.queue = service, queue

    def handle(self, raw):
        _, identity = decode('candidate:notices' if self.queue == 'notice' else 'candidate:bulk', raw)
        return self.service.deliver(identity)

    def sweep(self, limit):
        return self.service.recover(limit)


class MailHandler:
    def __init__(self, credential, waitlist, auth=None, milestone=None):
        self.credential, self.waitlist = credential, waitlist
        self.auth = auth
        self.milestone = milestone
        self._first = 0

    def handle(self, raw):
        kind, identity = decode('candidate:mail', raw)
        return {'credential.mail': self.credential.deliver,
                'auth.mail': self.auth.deliver if self.auth is not None else self._disabled,
                'milestone.mail': self.milestone.deliver if self.milestone is not None else self._disabled,
                'waitlist.confirmation': self.waitlist.confirm_mail,
                'waitlist.delivery': self.waitlist.deliver}[kind](identity)

    def sweep_bounded(self, limit, bounded, stopped):
        owners = tuple(owner for owner in (self.credential, self.waitlist, self.auth, self.milestone) if owner is not None)
        first = self._first
        self._first = (first + 1) % len(owners)
        for index in range(len(owners)):
            if stopped():
                break
            bounded(owners[(first + index) % len(owners)].recover, limit)

    @staticmethod
    def _disabled(identity):
        return False


def build_notification_handler(queue):
    from ..compat.crypto import LaravelEncrypter
    from ..auth.mail import MailWork
    from ..notifications.mail import CredentialMail
    from ..notifications.milestones import MilestoneMail
    from ..notifications.policy import ADJUSTMENT, BROADCAST, MANUAL, PRICE, PRICE_CHANNEL, PROOF, STOCK, ORDER_TYPES, BALANCE_TYPES, TestRecipients
    from ..notifications.order_notices import OrderNotices
    from ..notifications.proof_publication import SalesProofPublication
    from ..notifications.service import Notifications
    from ..notifications.transport import NotificationTransport
    from ..notifications.waitlist import Waitlist
    from ..receipt.source import ReceiptSource
    from ..runtime import application_key

    # Trusted composition is fixed to P01's synthetic target. A queue payload
    # cannot enroll an audience or change SMTP, Telegram, or storefront targets.
    recipients = TestRecipients(frozenset({'1'}))
    if queue in ('notice', 'bulk'):
        families = (MANUAL, ADJUSTMENT, PROOF, *ORDER_TYPES, *BALANCE_TYPES) if queue == 'notice' else (BROADCAST, STOCK, PRICE, PRICE_CHANNEL)
        proof = SalesProofPublication(recipients) if queue == 'notice' else None
        return NotificationHandler(Notifications(NotificationTransport(), recipients=recipients,
                                                families=families, proof=proof,
                                                order_notices=OrderNotices(recipients) if queue == 'notice' else None), queue)
    if queue != 'mail':
        raise ValueError('Invalid notification queue')
    key = application_key()
    cipher = LaravelEncrypter(key)
    source = ReceiptSource(cipher, signing_key=key, invoice_base_url='http://127.0.0.1:18801')
    return MailHandler(CredentialMail(source, cipher, recipients=recipients), Waitlist(cipher, key, enabled=True),
                       MailWork(cipher, app_key=key), MilestoneMail(source, cipher, recipients=recipients))
