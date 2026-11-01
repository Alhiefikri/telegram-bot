"""Trusted factories for the three initial worker families."""

from .contract import LEASE_SECONDS, validate_queue_name


def interaction_handler():
    from ..navigation.presentation import CatalogPresenter
    from ..navigation.processor import NavigationProcessor
    from ..navigation.repository import CatalogRepository
    from ..navigation.roots import NavigationRoots
    from ..navigation.transport import RelayTransport
    from .interaction import InteractionHandler

    repository = CatalogRepository()
    transport = RelayTransport()
    scope = "LhieStoreTestBot"
    banner = repository.banner_asset(scope) or {}
    roots = NavigationRoots(transport, bot_asset_scope=scope, banner_reference=banner.get("file_id"),
                            banner_version=banner.get("version"))
    presenter = CatalogPresenter(repository, bot_scope=scope, custom_emoji=True)
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
