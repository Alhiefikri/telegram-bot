"""Trusted disposable composition; no recipient or provider comes from a request."""
from ..auth.bot_identity import TelegramBotIdentity
from ..compat.crypto import LaravelEncrypter
from ..funding.service import FundingService
from ..payments.presentation import QrPresentation
from ..runtime import application_key, load_runtime
from .policy import TestRecipients
from .service import Notifications
from .transport import NotificationTransport


def notification_service(*, recipients=None, effect_types=None, using='default', queue=None):
    runtime = load_runtime()
    admitted = recipients if recipients is not None else TestRecipients(
        frozenset({'1'}) if runtime.notifications else frozenset())
    if not isinstance(admitted, TestRecipients):
        raise ValueError('Trusted recipient admission required')
    # The explicit fake-only admission uses a synthetic bot identity. Every
    # other admitted composition obtains its actual identity through getMe.
    username = ('LhieStoreTestBot' if runtime.notifications else TelegramBotIdentity().username()) if admitted.telegram else None
    cipher = LaravelEncrypter(application_key())
    return Notifications(NotificationTransport(), recipients=admitted, effect_types=effect_types,
                         using=using, bot_username=username, queue=queue,
                         funding=FundingService(encrypter=cipher, using=using),
                         qr_presentation=QrPresentation(public_storage='/run/harness/public-assets', using=using))
