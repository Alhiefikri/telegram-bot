"""Disjoint notice and SMTP consumers; PostgreSQL remains the work authority."""
from . import wakeup
from .contract import queue_spec


class NotificationHandler:
    def __init__(self, service, queue):
        self.service = service
        self.spec = queue_spec(queue)
        if queue not in ('notifications', 'bulk'):
            raise ValueError('Invalid notification worker')

    def handle(self, raw):
        _, identity = wakeup.decode(self.spec.wakeup_queue, raw)
        return self.service.deliver(identity)

    def sweep(self, limit):
        return self.service.recover(limit)


class MailHandler:
    def __init__(self, credential, waitlist, guest=None):
        self.services = {'credential.mail': credential, 'waitlist.mail': waitlist}
        if guest is not None:
            self.services['guest.mail'] = guest
        self.spec = queue_spec('mail')
        self._first = 0

    def handle(self, raw):
        kind, identity = wakeup.decode(self.spec.wakeup_queue, raw)
        return self.services[kind].deliver(identity)

    def sweep(self, limit):
        return {kind: service.recover(limit) for kind, service in self.services.items()}

    def sweep_bounded(self, limit, execute, stopped):
        # A slow family must not starve other SMTP work on every sweep. Each
        # family has its own alarm; SIGTERM prevents the next family starting.
        services = list(self.services.values())
        start = self._first
        self._first = (start + 1) % len(services)
        for index in range(len(services)):
            if stopped():
                break
            execute(services[(start + index) % len(services)].recover, limit)


class AuthMailHandler:
    def __init__(self, service):
        self.service = service
        self.spec = queue_spec('auth-mail')

    def handle(self, raw):
        _, identity = wakeup.decode(self.spec.wakeup_queue, raw)
        return self.service.deliver(identity)

    def sweep(self, limit):
        return self.service.recover(limit)


def notification_services(queue, *, recipients=None):
    from ..notifications.families import BULK_TYPES, LIMITS
    from ..notifications.policy import TestRecipients
    from ..notifications.runtime import notification_service
    admitted = recipients if recipients is not None else TestRecipients()
    if queue == 'notifications':
        families = tuple(kind for kind in LIMITS if kind not in BULK_TYPES)
    elif queue == 'bulk':
        families = tuple(kind for kind in LIMITS if kind in BULK_TYPES)
    else:
        raise ValueError('Invalid notification worker queue')
    return notification_service(recipients=admitted, effect_types=families, queue=queue)


def mail_services(*, recipients=None):
    from ..compat.crypto import LaravelEncrypter
    from ..notifications.mail import CredentialMail
    from ..notifications.policy import TestRecipients
    from ..notifications.waitlist import WaitlistMail
    from ..notifications.guest_mail import GuestMail
    from ..receipt.source import ReceiptSource
    from ..runtime import application_key
    admitted = recipients if recipients is not None else TestRecipients()
    key = application_key()
    cipher = LaravelEncrypter(key)
    base = 'http://127.0.0.1:18801'
    source = ReceiptSource(cipher, signing_key=key, invoice_base_url=base)
    return MailHandler(CredentialMail(source, cipher, recipients=admitted),
                       WaitlistMail(cipher, signing_key=key, base_url=base, recipients=admitted),
                       GuestMail(cipher, signing_key=key, recipients=admitted, invoice_base_url=base))


def auth_mail_service(*, recipients=None):
    from ..auth.mail import LocalMailSink, MailWork
    from ..compat.crypto import LaravelEncrypter
    from ..notifications.policy import TestRecipients
    from ..runtime import application_key
    admitted = recipients if recipients is not None else TestRecipients()

    class AdmittedSink:
        def send(self, email, purpose, capability):
            return LocalMailSink().send(admitted.mail(email), purpose, capability)

    key = application_key()
    service = MailWork(LaravelEncrypter(key), app_key=key, transport=AdmittedSink())
    if not admitted.telegram:
        # An unadmitted composition must not claim, expire or cancel auth work.
        class Disabled:
            def deliver(self, identity):
                return False

            def recover(self, limit):
                return 0
        service = Disabled()
    return AuthMailHandler(service)
