from dataclasses import dataclass, replace
from datetime import datetime
import json
import re
import secrets
from uuid import uuid4
from zoneinfo import ZoneInfo

from django.db import DatabaseError, connections, transaction

from lhie_candidate.domain.money import require_integer
from .balance import StoreBalanceLedger
from .db import Database
from .errors import CheckoutError
from .intents import PaymentSetting, StoreBalancePreparation
from .inventory import InventoryLedger
from .lifecycle import OrderLifecycle
from .pricing import PricingSnapshot


@dataclass(frozen=True, slots=True)
class CheckoutRequest:
    customer_id: int
    cart_id: int
    checkout_key: str
    expected_pricing: PricingSnapshot
    terms_version: str | None = None
    terms_accepted_at: datetime | None = None

    def __post_init__(self):
        require_integer(self.customer_id, minimum=1)
        require_integer(self.cart_id, minimum=1)
        if not isinstance(self.expected_pricing, PricingSnapshot):
            raise ValueError('Expected an immutable pricing snapshot')
        if not isinstance(self.checkout_key, str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', self.checkout_key):
            raise ValueError('Invalid checkout key')
        if (self.terms_version is None) != (self.terms_accepted_at is None):
            raise ValueError('Incomplete terms snapshot')
        if self.terms_version is not None and not 1 <= len(self.terms_version) <= 100:
            raise ValueError('Invalid terms version')
        if self.terms_accepted_at is not None and self.terms_accepted_at.tzinfo is None:
            raise ValueError('Terms acceptance requires an explicit timezone')


@dataclass(frozen=True, slots=True)
class CheckoutResult:
    order_id: str
    payment_id: str
    invoice_number: str
    order_status: str
    payment_status: str
    amount: int
    replayed: bool


class CheckoutService:
    """Single transaction owner for the experimental local-credential IDR slice."""

    def __init__(self, *, using='default', preparation=None, reseller=False):
        self.db = Database(using)
        self.using = using
        self.preparation = preparation or StoreBalancePreparation()
        self.reseller = reseller
        self.balance = StoreBalanceLedger(using)
        self.inventory = InventoryLedger(using)

    def quote_cart(self, customer_id, cart_id):
        require_integer(customer_id, minimum=1)
        require_integer(cart_id, minimum=1)
        cart = self.db.one('SELECT customer_id FROM public.carts WHERE id=%s', (cart_id,))
        if cart is None or cart['customer_id'] != customer_id:
            raise CheckoutError('foreign_cart')
        items = self.db.rows('SELECT product_id,quantity FROM public.cart_items WHERE cart_id=%s ORDER BY product_id', (cart_id,))
        products = {row['id']: row for row in self.db.rows('''SELECT p.* FROM public.products p
            JOIN public.cart_items i ON i.product_id=p.id WHERE i.cart_id=%s ORDER BY p.id''', (cart_id,))}
        return self._pricing(items, products, reseller=self.reseller)

    def checkout(self, request: CheckoutRequest):
        if connections[self.using].in_atomic_block:
            raise RuntimeError('Checkout owns its outer transaction; preflight must run outside locks')
        # Recover accepted money before readiness, stock, price, or guard revalidation.
        existing = self._replay(request)
        if existing:
            return existing
        setting = self._setting(lock=False)
        preflight = self.preparation.preflight(setting)
        for attempt in range(5):
            try:
                with transaction.atomic(using=self.using):
                    self.db.require_transaction()
                    return self._checkout_once(request, preflight)
            except DatabaseError as error:
                cause = error.__cause__
                state = getattr(cause, 'sqlstate', None)
                constraint = getattr(getattr(cause, 'diag', None), 'constraint_name', None)
                retry = state in ('40P01', '40001') or (state == '23505' and constraint in (
                    'orders_checkout_key_unique', 'orders_cart_id_unique',
                    'orders_invoice_number_unique', 'payments_provider_merchant_ref_unique'))
                if not retry or attempt == 4:
                    raise
        raise RuntimeError('Unreachable checkout retry state')

    def _checkout_once(self, request, preflight):
        existing = self._replay(request)
        if existing:
            return existing
        customer = self.db.one('SELECT id,telegram_id FROM public.customers WHERE id=%s FOR SHARE', (request.customer_id,))
        cart = self.db.one('SELECT * FROM public.carts WHERE id=%s FOR UPDATE', (request.cart_id,))
        if customer is None or cart is None or cart['customer_id'] != request.customer_id:
            raise CheckoutError('foreign_cart')
        # The Cart lock also fences a replay with a different request key.
        existing = self._replay(request, by_cart=True)
        if existing:
            return existing
        now = datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)
        if cart['status'] != 'active' or (cart['expires_at'] and cart['expires_at'] <= now):
            raise CheckoutError('inactive_cart')
        is_balance = self.preparation.provider == 'store_balance'
        if is_balance:
            if customer['telegram_id'] is None:
                raise CheckoutError('balance_customer_required')
            self.balance.lock_for_purchase(request.customer_id)
            policy = self.db.one('''SELECT id FROM public.customer_usdt_funding_policies
                WHERE singleton_key='customer_usdt_funding_policy' FOR SHARE''')
            restore = self.db.one('SELECT state FROM public.balance_restore_gates WHERE id=1 FOR SHARE')
            if policy is None:
                raise CheckoutError('spending_policy_missing')
            if restore is None or restore['state'] != 'cleared':
                raise CheckoutError('restore_gate_open')
            if self.db.one("SELECT id FROM public.customer_usdt_funding_pauses WHERE scope='spending' LIMIT 1"):
                raise CheckoutError('spending_paused')
        maintenance = self.db.one('''SELECT is_active FROM public.checkout_maintenance_settings
            WHERE singleton_key='checkout_maintenance' FOR UPDATE''')
        if maintenance is None or maintenance['is_active']:
            raise CheckoutError('checkout_maintenance')
        items = self.db.rows('SELECT product_id,quantity FROM public.cart_items WHERE cart_id=%s ORDER BY product_id FOR UPDATE', (request.cart_id,))
        if not items:
            raise CheckoutError('empty_cart')
        products = self.inventory.lock_products(items)
        setting = self._setting(lock=True)
        pricing = self._pricing(items, products, reseller=self.reseller)
        if pricing != request.expected_pricing:
            raise CheckoutError('price_changed')
        if (preflight.provider, preflight.method) != (self.preparation.provider, self.preparation.method):
            raise CheckoutError('stale_preflight')
        if preflight.setting != setting:
            if preflight.observation is not None:
                raise CheckoutError('stale_preflight')
            preflight = replace(preflight, setting=setting)
        plan = self.preparation.prepare(pricing, setting, preflight, now)
        if (plan.provider, plan.method) != (self.preparation.provider, self.preparation.method):
            raise CheckoutError('invalid_payment_plan')
        if require_integer(plan.amount, minimum=1) != pricing.idr_total or plan.expires_at <= now:
            raise CheckoutError('invalid_payment_plan')
        if plan.balance_paid != is_balance or (is_balance and plan.method != 'STORE_BALANCE'):
            raise CheckoutError('invalid_payment_plan')
        order_id, payment_id = str(uuid4()), str(uuid4())
        invoice = _reference('INV', now)
        snapshot = None if not is_balance else json.dumps({
            'preference': 'idr_only', 'source': 'explicit_rail', 'currency': 'IDR',
            'amount': plan.amount, 'expected_idr_total': None, 'expected_usd_total_micro': None})
        self.db.execute('''INSERT INTO public.orders
            (id,customer_id,cart_id,checkout_key,invoice_number,order_status,payment_status,
             total_amount,currency,expires_at,terms_version,terms_accepted_at,balance_checkout_snapshot,created_at,updated_at)
            VALUES (%s,%s,%s,%s,%s,'pending_payment','pending',%s,'IDR',%s,%s,%s,%s,%s,%s)''',
            (order_id, request.customer_id, request.cart_id, request.checkout_key, invoice,
             pricing.idr_total, plan.expires_at, request.terms_version,
             None if request.terms_accepted_at is None else request.terms_accepted_at.astimezone(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None),
             snapshot, now, now))
        self.db.execute('''INSERT INTO public.payments
            (id,order_id,provider,merchant_ref,method,amount,currency,status,expired_at,
             sakurupiah_channel,binance_verification_status,created_at,updated_at)
            VALUES (%s,%s,%s,%s,%s,%s,'IDR','pending',%s,%s,'checking',%s,%s)''',
            (payment_id, order_id, plan.provider, _reference('PAY', now), plan.method, plan.amount, plan.expires_at,
             plan.sakurupiah_channel, now, now))
        for quote in pricing.quotes:
            product = products[quote.product_id]
            family = None
            if product.get('product_family_id') is not None:
                family = self.db.one('SELECT name,icon,custom_emoji_id FROM public.product_families WHERE id=%s', (product['product_family_id'],))
            icon, custom_emoji_id = product.get('icon'), product.get('custom_emoji_id')
            if not (icon or '').strip() and not (custom_emoji_id or '').strip() and family:
                icon, custom_emoji_id = family['icon'], family['custom_emoji_id']
            item = self.db.one('''INSERT INTO public.order_items
                (order_id,product_id,product_name,product_icon,product_custom_emoji_id,family_id,family_name,
                 delivery_note,delivery_note_snapshotted,usage_guide_title,usage_guide_url,usage_guide_snapshotted,
                 quantity,local_quantity,supplier_quantity,quantity_price_tier_minimum,unit_price,subtotal,created_at,updated_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,true,%s,%s,true,%s,%s,0,%s,%s,%s,%s,%s) RETURNING id''',
                (order_id, quote.product_id, product['name'], icon, custom_emoji_id,
                 product.get('product_family_id'), family['name'] if family else None,
                 product.get('delivery_note'), product.get('usage_guide_title'), product.get('usage_guide_url'),
                 quote.quantity, quote.quantity, quote.tier_minimum, quote.unit_price, quote.subtotal, now, now))
            self.inventory.allocate(item['id'], quote.product_id, quote.quantity)
            self.inventory.reserve(order_id, quote.product_id, quote.quantity)
        if is_balance:
            self.balance.debit_purchase(request.customer_id, order_id)
            OrderLifecycle(using=self.using).balance_paid_locked(order_id, payment_id)
        self.db.execute("UPDATE public.carts SET status='checked_out',updated_at=%s WHERE id=%s", (now, request.cart_id))
        return CheckoutResult(order_id, payment_id, invoice, 'completed' if is_balance else 'pending_payment',
                              'paid' if is_balance else 'pending', plan.amount, False)

    def _replay(self, request, *, by_cart=False):
        column, value = ('cart_id', request.cart_id) if by_cart else ('checkout_key', request.checkout_key)
        order = self.db.one(f'''SELECT id,customer_id,invoice_number,order_status,payment_status,total_amount
            FROM public.orders WHERE {column}=%s''', (value,))
        if order is None:
            return None
        if order['customer_id'] != request.customer_id:
            raise CheckoutError('foreign_checkout')
        cart = self.db.one('SELECT customer_id FROM public.carts WHERE id=%s', (request.cart_id,))
        if cart is None or cart['customer_id'] != request.customer_id:
            raise CheckoutError('foreign_cart')
        payment = self.db.one('SELECT id,method FROM public.payments WHERE order_id=%s ORDER BY created_at,id LIMIT 1', (order['id'],))
        if payment is None:
            raise CheckoutError('checkout_invariant')
        return CheckoutResult(str(order['id']), str(payment['id']), order['invoice_number'],
                              order['order_status'], order['payment_status'], order['total_amount'], True)

    def _pricing(self, items, products, *, reseller=False):
        tiers = {}
        if products:
            for tier in self.db.rows('''SELECT product_id,minimum_quantity,price_amount,usdt_price_amount
                FROM public.product_quantity_price_tiers WHERE product_id=ANY(%s) ORDER BY product_id,minimum_quantity''', (list(products),)):
                tiers.setdefault(tier['product_id'], []).append(tier)
        return PricingSnapshot.build(items, products, tiers, reseller=reseller)

    def _setting(self, *, lock):
        suffix = ' FOR UPDATE' if lock else ''
        setting = self.db.one('''SELECT id,is_active,payment_provider_version,payment_mode,sakurupiah_channel
            FROM public.manual_payment_settings ORDER BY is_active DESC,id LIMIT 1''' + suffix)
        return None if setting is None else PaymentSetting(setting['id'], setting['payment_provider_version'],
            setting['is_active'], setting['payment_mode'], setting['sakurupiah_channel'])


def _reference(prefix, now):
    alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'
    value = (int(now.replace(tzinfo=ZoneInfo('Asia/Jakarta')).timestamp() * 1000) << 80) | secrets.randbits(80)
    suffix = ''.join(alphabet[(value >> shift) & 31] for shift in range(125, -1, -5))
    return f'{prefix}-{now:%Y%m%d}-{suffix}'
