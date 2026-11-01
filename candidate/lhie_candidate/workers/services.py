"""Trusted factories for the three initial worker families."""

from .contract import LEASE_SECONDS, validate_queue_name


def interaction_handler():
    from ..checkout.navigation import NavigationCheckout, sandbox_request
    from ..funding.service import FundingService
    from ..navigation.presentation import CatalogPresenter
    from ..navigation.processor import NavigationProcessor
    from ..navigation.repository import CatalogRepository
    from ..navigation.roots import NavigationRoots
    from ..navigation.transport import RelayTransport
    from ..payments.lifecycle import SakurupiahLifecycle
    from ..payments.relay import SandboxRelay
    from ..payments.sakurupiah import SakurupiahSandboxAdapter
    from ..compat.crypto import LaravelEncrypter
    from ..runtime import application_key
    from .interaction import InteractionHandler

    repository = CatalogRepository()
    transport = RelayTransport()
    scope = "LhieStoreTestBot"
    banner = repository.banner_asset(scope) or {}
    roots = NavigationRoots(transport, bot_asset_scope=scope, banner_reference=banner.get("file_id"),
                            banner_version=banner.get("version"))
    # The funding rail is intentionally inactive here: no provider or request
    # factory is installed until an approved P08/P15 operator configuration
    # exists. The service still owns callback parsing and fails closed.
    funding = FundingService()
    # Checkout uses the same sandbox-only Sakurupiah lifecycle as the payment
    # worker. It never retargets the provider and fails closed when the local
    # sandbox relay is absent. The request identity is synthetic and carries no
    # production credential or endpoint. A missing admitted key disables
    # checkout instead of taking the read-only navigation worker down.
    try:
        key = application_key()
        lifecycle = SakurupiahLifecycle(SakurupiahSandboxAdapter(transport=SandboxRelay()),
                                        LaravelEncrypter(key))
    except Exception:
        key, lifecycle = None, None
    checkout = NavigationCheckout(lifecycle=lifecycle,
                                  request_factory=sandbox_request if lifecycle else None,
                                  key=key)
    presenter = CatalogPresenter(repository, bot_scope=scope, custom_emoji=True,
                                 funding=funding, checkout=checkout)
    return InteractionHandler(NavigationProcessor(repository, presenter, roots, transport))


def payment_handler():
    from ..checkout.db import Database
    from ..compat.crypto import LaravelEncrypter
    from ..payments.lifecycle import SakurupiahLifecycle
    from ..payments.relay import SandboxRelay
    from ..payments.sakurupiah import SakurupiahSandboxAdapter
    from ..runtime import application_key
    from .payment import PaymentHandler

    lifecycle = SakurupiahLifecycle(SakurupiahSandboxAdapter(transport=SandboxRelay()),
                                    LaravelEncrypter(application_key()))
    return PaymentHandler(lifecycle, Database())


def receipt_handler():
    from ..receipt.runtime import receipt_service
    from .receipt import ReceiptHandler

    return ReceiptHandler(receipt_service(lease_seconds=LEASE_SECONDS))


def build_handler(queue):
    validate_queue_name(queue)
    if queue == "interaction":
        return interaction_handler()
    if queue == "payment":
        return payment_handler()
    return receipt_handler()
