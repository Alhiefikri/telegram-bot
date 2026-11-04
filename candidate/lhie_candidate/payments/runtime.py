"""Payment composition under P01 admission; unqualified rails are absent by default."""
from ..compat.crypto import LaravelEncrypter
from ..runtime import application_key
from .lifecycle import SakurupiahLifecycle
from .presentation import QrPresentation
from .relay import SandboxRelay
from .routing import QrisPayments
from .sakurupiah import SakurupiahSandboxAdapter


def payment_services(*, klikqris=None, pakasir=None, sakurupiah=None, presentation=None, using='default'):
    cipher = LaravelEncrypter(application_key())
    rails = {'sakurupiah': SakurupiahLifecycle(
        sakurupiah or SakurupiahSandboxAdapter(transport=SandboxRelay()), cipher, using=using)}
    if klikqris is not None:
        from .klikqris import KlikQrisLifecycle
        rails['klikqris'] = KlikQrisLifecycle(klikqris, cipher, using=using)
    if pakasir is not None:
        from .pakasir import PakasirLifecycle
        rails['pakasir'] = PakasirLifecycle(pakasir, cipher, using=using)
    return QrisPayments(rails, presentation=presentation or QrPresentation(
        public_storage='/run/harness/public-assets', using=using), using=using)
