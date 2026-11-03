"""Navigation checkout bridge: detail selection -> canonical Cart -> P07 -> P08.

This owner adds no second checkout, payment, provider or receipt writer. It
materializes the exact Product/quantity selected in the Telegram detail screen
as a canonical Cart item, delegates all commercial work to ``CheckoutService``
and delegates provider invoice creation and observation to the existing
``SakurupiahLifecycle``. Provider I/O always runs outside a database
transaction; replay is resolved from the canonical Order ``checkout_key`` before
any Cart, stock or provider mutation.

The admitted runtime injects the sandbox-only P08 adapter and a fixed synthetic
request identity. No production endpoint, credential or real-money path exists
here; when the sandbox relay is absent the QRIS rail fails closed as
``provider_unavailable``. Store Balance keeps using the existing P07 ledger and
spending gates.
"""

from dataclasses import dataclass
from datetime import datetime, timedelta
from uuid import UUID
from zoneinfo import ZoneInfo

from django.db import transaction

from lhie_candidate.domain.money import require_integer
from lhie_candidate.payments.lifecycle import SakurupiahLifecycle
from lhie_candidate.payments.sakurupiah_urls import (
    validate_sakurupiah_checkout_url, validate_sakurupiah_qr_image_url,
)

from .db import Database
from .errors import CheckoutError
from .intents import StoreBalancePreparation, telegram_detail_checkout_key
from .sakurupiah import SakurupiahPreparation
from .service import CheckoutRequest, CheckoutResult, CheckoutService


JAKARTA = ZoneInfo('Asia/Jakarta')
RAILS = ('qris', 'balance')
CART_TTL = timedelta(hours=24)
_ORDER_COLUMNS = (
    'id customer_id invoice_number order_status payment_status total_amount currency'
).split()
_PAYMENT_COLUMNS = (
    'id order_id provider method amount currency status merchant_ref sakurupiah_channel '
    'expired_at provider_reference provider_total_payment provider_fee provider_expires_at '
    'provider_presentation provider_create_state provider_create_attempted_at '
    'provider_payment_method'
).split()
_SUCCESS = 'paid'
_EXPIRED = ('expired', 'provider_expired')
_UNAVAILABLE = ('provider_invalid_result', 'provider_qris_unusable', 'failed', 'refunded')
_RECOVERY = ('outcome_unknown', 'recovery_unconfirmed', 'recovery_pending', 'busy')


class NavigationCheckoutError(ValueError):
    """Payload-free local rejection; the presenter translates the stable code."""

    def __init__(self, code):
        self.code = code
        super().__init__(code)


@dataclass(frozen=True, slots=True)
class CheckoutView:
    state: str
    order_id: str | None = None
    payment_id: str | None = None
    invoice_number: str | None = None
    amount: int | None = None
    fee: int | None = None
    total: int | None = None
    expires_at: datetime | None = None
    qris_url: str | None = None
    checkout_url: str | None = None
    replayed: bool = False
    rail: str | None = None


def _now():
    return datetime.now(JAKARTA).replace(tzinfo=None, microsecond=0)


def _deadline(value):
    if value is None:
        return None
    if isinstance(value, str):
        value = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if not isinstance(value, datetime):
        raise ValueError('invalid deadline')
    return value.astimezone(JAKARTA).replace(tzinfo=None) if value.tzinfo else value




def sandbox_request(payment):
    """Non-secret placeholders; the admitted relay supplies its own targets."""
    return {
        'phone': '08000000000',
        'callback_url': 'https://sakurupiah.id/callback/local-candidate',
        'return_url': 'https://sakurupiah.id/return/local-candidate',
        'merchant_fee': 2,
    }


class NavigationCheckout:
    def __init__(self, *, lifecycle=None, request_factory=None, key=None,
                 using='default', clock=None):
        self.db = Database(using)
        self.using = using
        self.lifecycle = lifecycle
        self.request_factory = request_factory
        self.key = key
        self.clock = clock or _now
        self.preparation = (SakurupiahPreparation(lifecycle.adapter)
                            if isinstance(lifecycle, SakurupiahLifecycle) else None)

    # -- reads ---------------------------------------------------------

    def _root_matches(self, root, *, customer_id, chat_id, message_id, lock=False):
        scope = getattr(root, 'bot_asset_scope', None)
        if (not isinstance(scope, str) or not scope or type(customer_id) is not int
                or customer_id < 1 or not isinstance(chat_id, str) or not chat_id
                or type(message_id) is not int or message_id < 1):
            return False
        return self.db.one(
            'SELECT 1 AS present FROM django_candidate.navigation_roots '
            'WHERE bot_asset_scope=%s AND customer_id=%s AND chat_id=%s AND message_id=%s'
            + (' FOR SHARE' if lock else ''),
            (scope, customer_id, chat_id, message_id)) is not None

    def _context(self, customer, root):
        value = customer.get if isinstance(customer, dict) else lambda name: getattr(customer, name, None)
        customer_id = value('id')
        telegram_id = value('telegram_id')
        chat_id = str(getattr(root, 'chat_id', '') or '')
        message_id = getattr(root, 'message_id', None)
        if (type(customer_id) is not int or customer_id < 1
                or type(telegram_id) is not int or telegram_id < 1
                or not chat_id or type(message_id) is not int or message_id < 1):
            raise NavigationCheckoutError('stale')
        if not self._root_matches(
                root, customer_id=customer_id, chat_id=chat_id, message_id=message_id):
            raise NavigationCheckoutError('stale')
        return customer_id, telegram_id, chat_id, message_id

    def _key(self, customer_id, telegram_id, chat_id, message_id, product_id, token):
        key = self.key
        if key is None:
            try:
                from lhie_candidate.runtime import application_key
                key = application_key()
            except Exception:
                from django.conf import settings
                key = settings.SECRET_KEY
        return telegram_detail_checkout_key(
            key, customer_id, telegram_id, chat_id, message_id, product_id, token)

    def _order(self, checkout_key):
        return self.db.one(
            'SELECT ' + ','.join(_ORDER_COLUMNS) + ' FROM public.orders WHERE checkout_key=%s',
            (checkout_key,))

    def _payment(self, payment_id, *, customer_id=None):
        where = 'WHERE p.id=%s'
        params = [payment_id]
        if customer_id is not None:
            where += ' AND o.customer_id=%s'
            params.append(customer_id)
        return self.db.one(
            'SELECT ' + ','.join('p.' + name for name in _PAYMENT_COLUMNS)
            + ',o.invoice_number,o.order_status,o.payment_status,o.total_amount,o.customer_id '
            'FROM public.payments p JOIN public.orders o ON o.id=p.order_id ' + where,
            tuple(params))

    # -- checkout ------------------------------------------------------

    def checkout(self, customer, root, *, product_id, quantity, token, rail):
        """Create or replay exactly one Order/Payment for the selected Product."""
        try:
            require_integer(product_id, minimum=1)
            require_integer(quantity, minimum=1)
            if rail not in RAILS or not isinstance(token, str) or not token:
                raise NavigationCheckoutError('stale')
            customer_id, telegram_id, chat_id, message_id = self._context(customer, root)
        except (NavigationCheckoutError, ValueError, TypeError) as error:
            return CheckoutView(getattr(error, 'code', 'stale'))
        checkout_key = self._key(customer_id, telegram_id, chat_id, message_id, product_id, token)
        existing = self._order(checkout_key)
        if existing is not None:
            if existing['customer_id'] != customer_id:
                return CheckoutView('stale')
            return self._replay_view(existing, rail)
        if rail == 'qris' and self.preparation is None:
            # Never let CheckoutService's Store Balance default turn an
            # unavailable QRIS rail into a wallet debit.
            return CheckoutView('provider_unavailable', rail='qris')
        try:
            cart_id = self._prepare_cart(customer_id, product_id, quantity,
                                         chat_id=chat_id, message_id=message_id, root=root)
            service = (CheckoutService(preparation=StoreBalancePreparation(), using=self.using)
                       if rail == 'balance' else
                       CheckoutService(preparation=self.preparation, using=self.using))
            request = CheckoutRequest(
                customer_id, cart_id, checkout_key,
                service.quote_cart(customer_id, cart_id))
            result = service.checkout(request)
        except NavigationCheckoutError as error:
            return CheckoutView(error.code, rail=rail)
        except CheckoutError as error:
            return CheckoutView(_checkout_state(error.code), rail=rail)
        if result.payment_status == 'paid':
            return CheckoutView('paid', order_id=result.order_id, payment_id=result.payment_id,
                                invoice_number=result.invoice_number, amount=result.amount,
                                total=result.amount, rail=rail)
        return self._present_payment(result, rail=rail, replayed=result.replayed)

    def _prepare_cart(self, customer_id, product_id, quantity, *, chat_id, message_id, root):
        """Lock the Customer, then reuse or create one deterministic Cart.

        The Customer ``FOR UPDATE`` lock is the canonical CartService
        serialization boundary. A matching active single-item Cart without an
        Order is reusable; anything else gets a fresh Cart so a failed attempt
        can never stack quantities or inherit unrelated items.
        """
        with transaction.atomic(using=self.using):
            self.db.require_transaction()
            customer = self.db.one(
                'SELECT id,telegram_id FROM public.customers WHERE id=%s FOR UPDATE',
                (customer_id,))
            if customer is None or customer['telegram_id'] is None:
                raise NavigationCheckoutError('stale')
            if not self._root_matches(
                    root, customer_id=customer_id, chat_id=chat_id,
                    message_id=message_id, lock=True):
                raise NavigationCheckoutError('stale')
            now = self.clock()
            cart = self.db.one(
                '''SELECT c.id FROM public.carts c
                   WHERE c.customer_id=%s AND c.origin='customer' AND c.status='active'
                     AND (c.expires_at IS NULL OR c.expires_at > %s)
                     AND NOT EXISTS (SELECT 1 FROM public.orders o WHERE o.cart_id=c.id)
                     AND (SELECT count(*) FROM public.cart_items i WHERE i.cart_id=c.id)=1
                     AND EXISTS (SELECT 1 FROM public.cart_items i
                         WHERE i.cart_id=c.id AND i.product_id=%s AND i.quantity=%s)
                   ORDER BY c.id DESC LIMIT 1 FOR UPDATE''',
                (customer_id, now, product_id, quantity))
            if cart is not None:
                return cart['id']
            cart = self.db.one(
                '''INSERT INTO public.carts
                   (customer_id,origin,status,expires_at,created_at,updated_at)
                   VALUES (%s,'customer','active',%s,%s,%s) RETURNING id''',
                (customer_id, now + CART_TTL, now, now))
            if cart is None:
                raise NavigationCheckoutError('invalid')
            self.db.execute(
                '''INSERT INTO public.cart_items
                   (cart_id,product_id,quantity,created_at,updated_at)
                   VALUES (%s,%s,%s,%s,%s)''',
                (cart['id'], product_id, quantity, now, now))
            return cart['id']

    def _replay_view(self, order, rail):
        payment = self.db.one(
            'SELECT id,provider,method FROM public.payments WHERE order_id=%s '
            'ORDER BY created_at,id LIMIT 1', (order['id'],))
        if payment is None:
            return CheckoutView('invalid', rail=rail)
        payment_id = str(payment['id'])
        actual_rail = 'balance' if payment['provider'] == 'store_balance' else 'qris'
        if order['payment_status'] == 'paid':
            if actual_rail == 'qris':
                return self._payment_view(self._payment(payment_id), state='paid', replayed=True)
            return CheckoutView('paid', order_id=str(order['id']), payment_id=payment_id,
                                invoice_number=order['invoice_number'],
                                amount=order['total_amount'], total=order['total_amount'],
                                replayed=True, rail=actual_rail)
        return self._present_payment(
            CheckoutResult(str(order['id']), payment_id, order['invoice_number'],
                           order['order_status'], order['payment_status'],
                           order['total_amount'], True), rail=actual_rail, replayed=True)

    # -- provider invoice and authoritative observation ----------------

    def _request(self, payment):
        if self.request_factory is None:
            return None
        try:
            args = self.request_factory(payment)
        except Exception:
            return None
        if not isinstance(args, dict):
            return None
        allowed = {'phone', 'callback_url', 'return_url', 'merchant_fee'}
        if not {'phone', 'callback_url', 'return_url'} <= set(args) or set(args) - allowed:
            return None
        return args

    def _present_payment(self, result, *, rail, replayed=False):
        try:
            payment = self._payment(result.payment_id)
        except Exception:
            payment = None
        if payment is None:
            return CheckoutView('invalid', order_id=result.order_id,
                                payment_id=result.payment_id,
                                invoice_number=result.invoice_number,
                                amount=result.amount, rail=rail)
        if payment['status'] == 'paid' and rail == 'qris':
            return self._payment_view(payment, state='paid', replayed=replayed)
        if payment['status'] == 'paid':
            return CheckoutView('paid', order_id=result.order_id, payment_id=result.payment_id,
                                invoice_number=result.invoice_number, amount=result.amount,
                                total=payment['provider_total_payment'] or payment['amount'],
                                replayed=replayed, rail=rail)
        if rail != 'qris' or self.lifecycle is None or self.preparation is None:
            return CheckoutView('provider_unavailable', order_id=result.order_id,
                                payment_id=result.payment_id,
                                invoice_number=result.invoice_number,
                                amount=result.amount, rail=rail)
        try:
            lifecycle = self.lifecycle.classify(result.payment_id)
            if lifecycle.state in _RECOVERY or lifecycle.state == 'pending':
                recovered = self.lifecycle.recover(result.payment_id)
                if recovered.state not in _RECOVERY:
                    lifecycle = recovered
            if (lifecycle.state in ('pending', 'create_failed', 'provider_unavailable')
                    and payment['provider_create_state'] in
                    (None, 'create_failed', 'provider_unavailable')):
                args = self._request(payment)
                if args is None:
                    return CheckoutView('provider_unavailable', order_id=result.order_id,
                                        payment_id=result.payment_id,
                                        invoice_number=result.invoice_number,
                                        amount=result.amount, rail=rail)
                lifecycle = self.lifecycle.create(result.payment_id, **args)
        except Exception:
            return self._payment_view(payment, state='provider_unavailable', replayed=replayed)
        if lifecycle.state == 'paid':
            return self._payment_view(self._payment(result.payment_id), state='paid', replayed=replayed)
        if lifecycle.state in _RECOVERY or lifecycle.state == 'pending':
            return self._payment_view(payment, state='pending', replayed=replayed)
        return _lifecycle_view(
            lifecycle, order_id=result.order_id, payment_id=result.payment_id,
            invoice_number=result.invoice_number, amount=result.amount,
            replayed=replayed, rail=rail)

    def check(self, customer, root, *, payment_id):
        """Observe the provider authoritatively; never create a second invoice."""
        try:
            customer_id, _, chat_id, message_id = self._context(customer, root)
            UUID(str(payment_id))
        except (NavigationCheckoutError, ValueError, TypeError, AttributeError):
            return CheckoutView('stale')
        try:
            payment = self._payment(payment_id, customer_id=customer_id)
        except Exception:
            payment = None
        if payment is None:
            return CheckoutView('stale')
        if payment['status'] == 'paid':
            return self._payment_view(payment, state='paid', replayed=True)
        if self.lifecycle is None or payment['provider'] != 'sakurupiah':
            return self._payment_view(payment, state='provider_unavailable')
        if not self._root_matches(
                root, customer_id=customer_id, chat_id=chat_id, message_id=message_id):
            return CheckoutView('stale')
        if (payment['provider_reference'] is None
                and payment['provider_create_state'] in
                (None, 'create_failed', 'provider_unavailable')):
            return self._present_payment(
                CheckoutResult(str(payment['order_id']), str(payment['id']),
                               payment['invoice_number'], payment['order_status'],
                               payment['payment_status'], payment['amount'], True),
                rail='qris', replayed=True)
        try:
            if (payment['provider_reference'] is None
                    and payment['provider_create_state'] in _RECOVERY):
                result = self.lifecycle.recover(str(payment_id))
            else:
                result = self.lifecycle.refresh(str(payment_id))
        except Exception:
            return self._payment_view(payment, state='pending')
        if result.state == 'paid':
            return self._payment_view(self._payment(payment_id, customer_id=customer_id),
                                      state='paid', replayed=True)
        if result.state not in ('paid',) and result.state in _RECOVERY:
            return self._payment_view(payment, state='pending')
        return _lifecycle_view(
            result, order_id=str(payment['order_id']), payment_id=str(payment['id']),
            invoice_number=payment['invoice_number'], amount=payment['amount'],
            replayed=True, rail='qris')

    def _payment_view(self, payment, *, state, replayed=False):
        fee = None
        try:
            presentation = self.lifecycle.encrypter.decrypt_json(payment['provider_presentation'])
            provider_fee = payment['provider_fee']
            if (type(presentation) is dict and presentation.get('customer_pays_fee') is True
                    and type(provider_fee) is int and provider_fee >= 0
                    and payment['provider_total_payment'] == payment['amount'] + provider_fee):
                fee = provider_fee
        except (AttributeError, KeyError, TypeError, ValueError):
            pass
        return _payment_view(payment, state=state, replayed=replayed, fee=fee)


def _checkout_state(code):
    if code == 'insufficient_store_balance':
        return 'balance_insufficient'
    if code in ('insufficient_stock', 'unavailable_product', 'inventory_invariant'):
        return 'stock_conflict'
    if code == 'checkout_maintenance':
        return 'maintenance'
    if code in ('provider_unavailable', 'stale_preflight', 'invalid_payment_plan'):
        return 'provider_unavailable'
    if code in ('provider_amount_outside_limits', 'price_changed'):
        return 'invalid'
    if code in ('spending_paused', 'restore_gate_open', 'spending_policy_missing',
                'balance_customer_required', 'balance_projection_mismatch',
                'balance_account_unavailable'):
        return 'balance_unavailable'
    if code in ('foreign_cart', 'foreign_checkout'):
        return 'stale'
    if code in ('empty_cart', 'inactive_cart'):
        return 'invalid'
    return 'invalid'


def _presentation_values(presentation):
    if not isinstance(presentation, dict):
        return None, None, None
    try:
        expires = _deadline(presentation.get('effective_expires_at'))
    except (TypeError, ValueError):
        expires = None
    if (presentation.get('qris_payload') is not None
            or presentation.get('mode') not in ('qris_image', 'hosted')):
        return None, None, None
    qr = presentation.get('qris_image_url')
    qris_url = qr if validate_sakurupiah_qr_image_url(qr) else None
    hosted = presentation.get('checkout_url')
    checkout_url = hosted if validate_sakurupiah_checkout_url(hosted) else None
    if presentation.get('mode') == 'qris_image' and qris_url is None:
        return None, None, None
    if presentation.get('mode') == 'hosted' and checkout_url is None:
        return None, None, None
    return qris_url, checkout_url, expires


def _lifecycle_view(result, *, order_id, payment_id, invoice_number, amount,
                    replayed=False, rail=None):
    state = result.state
    if state == _SUCCESS:
        mapped = 'paid'
    elif state in _EXPIRED:
        mapped = 'expired'
    elif state == 'review':
        mapped = 'review'
    elif state == 'created':
        mapped = 'pending'
    elif state in _UNAVAILABLE or state == 'not_payable':
        mapped = 'provider_unavailable'
    elif state in _RECOVERY or state == 'pending':
        mapped = 'pending'
    else:
        mapped = 'provider_unavailable'
    qris_url = checkout_url = expires = None
    if state == 'created':
        qris_url, checkout_url, expires = _presentation_values(result.presentation)
        if qris_url is None and checkout_url is None:
            mapped = 'provider_unavailable'
    return CheckoutView(
        mapped, order_id=order_id, payment_id=payment_id, invoice_number=invoice_number,
        amount=amount, fee=result.fee if result.customer_pays_fee else None,
        total=result.total, expires_at=expires, qris_url=qris_url,
        checkout_url=checkout_url, replayed=replayed, rail=rail)


def _payment_view(payment, *, state, replayed=False, fee=None):
    return CheckoutView(
        state, order_id=str(payment['order_id']), payment_id=str(payment['id']),
        invoice_number=payment['invoice_number'], amount=payment['amount'],
        fee=fee,
        total=(payment['provider_total_payment']
               if type(payment['provider_total_payment']) is int else None),
        replayed=replayed, rail='qris')
