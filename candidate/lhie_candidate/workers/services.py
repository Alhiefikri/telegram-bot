"""Trusted factories for the three initial worker families."""

from .contract import LEASE_SECONDS, validate_queue_name


def _navigation_processor(*, lifecycle=None, key=None, family):
    from ..checkout.navigation import NavigationCheckout, sandbox_request
    from ..funding.service import FundingService
    from ..navigation.presentation import CatalogPresenter
    from ..navigation.processor import NavigationProcessor
    from ..navigation.repository import CatalogRepository
    from ..navigation.roots import NavigationRoots
    from ..navigation.transport import RelayTransport

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
    # Provider access is installed only on the payment worker.
    checkout = NavigationCheckout(lifecycle=lifecycle,
                                  request_factory=sandbox_request if lifecycle else None,
                                  key=key)
    presenter = CatalogPresenter(repository, bot_scope=scope, custom_emoji=True,
                                 funding=funding, checkout=checkout)
    return NavigationProcessor(repository, presenter, roots, transport, family=family)


def interaction_handler():
    from ..runtime import application_key
    from .interaction import InteractionHandler

    try:
        key = application_key()
    except Exception:
        key = None
    return InteractionHandler(_navigation_processor(key=key, family="interaction"))


def payment_handler():
    from ..checkout.db import Database
    from ..compat.crypto import LaravelEncrypter
    from ..payments.lifecycle import SakurupiahLifecycle
    from ..payments.relay import SandboxRelay
    from ..payments.sakurupiah import SakurupiahSandboxAdapter
    from ..runtime import application_key
    from .payment import PaymentHandler

    key = application_key()
    lifecycle = SakurupiahLifecycle(SakurupiahSandboxAdapter(transport=SandboxRelay()),
                                    LaravelEncrypter(key))
    processor = _navigation_processor(lifecycle=lifecycle, key=key, family="payment")
    return PaymentHandler(lifecycle, Database(), processor=processor)


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
