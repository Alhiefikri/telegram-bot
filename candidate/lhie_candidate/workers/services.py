"""Trusted factories for the three initial worker families."""

from .contract import LEASE_SECONDS, validate_queue_name


def _external_checkout_selectable():
    from ..checkout.db import Database
    admitted = Database().one("""SELECT has_column_privilege(current_user,
        'public.payments','binance_receiver_value','INSERT') AS checkout""")
    if not admitted['checkout']:
        return False
    row = Database().one('''SELECT EXISTS (SELECT 1 FROM public.manual_payment_settings s
        WHERE s.is_active AND ((s.binance_pay_enabled AND s.binance_receiver_type IN ('pay_id','uid')
          AND s.binance_receiver_value IS NOT NULL) OR s.bsc_payment_enabled)) AS visible''')
    return row['visible']


def _navigation_processor(*, lifecycle=None, key=None, family, usdt_funding=None, usdt_settlement=None):
    from ..checkout.navigation import NavigationCheckout, sandbox_request
    from ..funding.service import FundingService
    from ..navigation.presentation import CatalogPresenter
    from ..navigation.processor import NavigationProcessor
    from ..navigation.repository import CatalogRepository
    from ..navigation.roots import NavigationRoots
    from ..navigation.transport import RelayTransport
    from ..usdt.funding import FundingService as UsdtFundingService
    from ..usdt.funding_settlement import FundingSettlement

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
                                 dollar_rail_selectable=_external_checkout_selectable,
                                 funding=funding, checkout=checkout,
                                 usdt_funding=usdt_funding or UsdtFundingService(),
                                 usdt_settlement=usdt_settlement or FundingSettlement())
    return NavigationProcessor(repository, presenter, roots, transport, family=family)


def interaction_handler(*, usdt_funding=None, usdt_settlement=None):
    from ..runtime import application_key
    from .interaction import InteractionHandler

    try:
        key = application_key()
    except Exception:
        key = None
    return InteractionHandler(_navigation_processor(key=key, family="interaction", usdt_funding=usdt_funding,
                                                    usdt_settlement=usdt_settlement))


def payment_handler(*, funding_klik=None, funding=None, usdt_funding=None, usdt_settlement=None, reorg_monitor=None):
    from ..checkout.db import Database
    from ..runtime import application_key
    from .payment import PaymentHandler

    key = application_key()
    from ..payments.runtime import payment_services
    lifecycle = payment_services()
    db = Database()
    admitted = db.one('''SELECT
        has_column_privilege(current_user,'public.customer_usdt_top_ups','id','INSERT') AS funding,
        has_column_privilege(current_user,'public.usdt_reorg_incidents','top_up_id','INSERT') AS operations''')
    if admitted['funding']:
        from ..usdt.funding import FundingService as UsdtFundingService
        from ..usdt.funding_settlement import FundingSettlement
        usdt_funding = usdt_funding or UsdtFundingService()
        usdt_settlement = usdt_settlement or FundingSettlement()
    if admitted['operations'] and reorg_monitor is None:
        setting = db.one('''SELECT admin_telegram_chat_id FROM public.manual_payment_settings
            WHERE is_active=true ORDER BY id LIMIT 1''')
        chat = str((setting or {}).get('admin_telegram_chat_id') or '').strip()
        from ..payments.bsc import BscRpcClient
        from ..usdt.operations import ReorgIncidents
        reorg_monitor = ReorgIncidents(BscRpcClient(), admin_chat_id=chat or None)
    processor = _navigation_processor(lifecycle=lifecycle, key=key, family="payment", usdt_funding=usdt_funding,
                                      usdt_settlement=usdt_settlement)
    return PaymentHandler(lifecycle, db, processor=processor, funding_klik=funding_klik, funding=funding,
                          usdt_funding=usdt_funding, usdt_settlement=usdt_settlement, reorg_monitor=reorg_monitor)


def receipt_handler():
    from ..receipt.runtime import receipt_service
    from .receipt import ReceiptHandler

    return ReceiptHandler(receipt_service(lease_seconds=LEASE_SECONDS))


def build_handler(queue, *, funding_klik=None, funding=None, usdt_funding=None, usdt_settlement=None, reorg_monitor=None):
    validate_queue_name(queue)
    if queue == "interaction":
        return interaction_handler(usdt_funding=usdt_funding, usdt_settlement=usdt_settlement)
    if queue == "payment":
        return payment_handler(funding_klik=funding_klik, funding=funding, usdt_funding=usdt_funding,
                               usdt_settlement=usdt_settlement, reorg_monitor=reorg_monitor)
    return receipt_handler()
