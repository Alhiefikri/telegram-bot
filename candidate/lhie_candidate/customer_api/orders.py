"""Durable API purchase claims over the shared balance checkout owner."""
from dataclasses import dataclass
import hashlib
import hmac
import json
import re
import secrets
import time
from zoneinfo import ZoneInfo

from django.db import DatabaseError, connection, transaction

from lhie_candidate.checkout.db import Database
from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
from lhie_candidate.checkout.wallet_selection import BalanceCheckoutRequest
from lhie_candidate.domain.money import Currency, MAX_UNITS, Money
from .admission import ApiFailure, check_availability, unavailable

_PREFERENCES = frozenset(('idr_only', 'usd_only', 'idr_first', 'usd_first'))
_POSITIVE = re.compile(r'[1-9][0-9]*\Z', re.ASCII)
_KEY = re.compile(r'[\x20-\x7e]{1,128}\Z', re.ASCII)
_USD = re.compile(r'(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\Z', re.ASCII)


def _invalid(message):
    return ApiFailure(422, 'invalid_input', message)


def _positive(value, message):
    if not isinstance(value, str) or not _POSITIVE.fullmatch(value) or len(value) > 19:
        raise _invalid(message)
    result = int(value)
    if result > MAX_UNITS:
        raise _invalid(message)
    return result


@dataclass(frozen=True, slots=True)
class OrderRequest:
    idempotency_key: str
    product_id: int
    quantity: int
    preference: str | None
    expected_idr: int | None
    expected_usd_micro: int | None

    @classmethod
    def parse(cls, key, body):
        if not isinstance(key, str) or not _KEY.fullmatch(key):
            raise _invalid('A printable Idempotency-Key header of at most 128 characters is required.')
        if not isinstance(body, dict):
            raise _invalid('The request body must be a JSON object.')
        if set(body) - {'product_id', 'quantity', 'preference', 'expected_totals'}:
            raise _invalid('The request contains unsupported fields.')
        product = _positive(body.get('product_id'), 'product_id must be a canonical positive decimal string.')
        quantity = body.get('quantity')
        if type(quantity) is not int or not 1 <= quantity <= 100:
            raise _invalid('quantity must be an integer between 1 and 100.')
        preference = body.get('preference')
        if preference is not None and (not isinstance(preference, str) or preference not in _PREFERENCES):
            raise _invalid('preference must be idr_only, usd_only, idr_first or usd_first.')
        totals = body.get('expected_totals')
        if totals is None:
            totals = {}
        if not isinstance(totals, dict) or set(totals) - {'IDR', 'USD'}:
            raise _invalid('expected_totals must be a JSON object keyed by IDR and USD.')
        idr = totals.get('IDR')
        if idr is not None:
            idr = _positive(idr, 'expected_totals.IDR must be a canonical positive whole-rupiah string.')
        usd = totals.get('USD')
        if usd is not None:
            if not isinstance(usd, str) or len(usd) > 32 or not _USD.fullmatch(usd):
                raise _invalid('expected_totals.USD must be a positive exact decimal string with at most six decimals.')
            try:
                usd = Money.from_decimal(Currency.USDT, usd).units
                if usd == 0:
                    raise ValueError
            except ValueError:
                raise _invalid('expected_totals.USD must be a positive exact decimal string with at most six decimals.') from None
        return cls(key, product, quantity, preference, idr, usd)

    def fingerprint(self):
        payload = {'v': 1, 'operation': 'create_order', 'product_id': self.product_id,
                   'quantity': self.quantity, 'inputs': {}, 'preference': self.preference,
                   'expected_idr_total': self.expected_idr,
                   'expected_usd_total_micro': self.expected_usd_micro}
        return hashlib.sha256(json.dumps(payload, separators=(',', ':'), ensure_ascii=False).encode()).hexdigest()


def _fence(principal, db):
    check_availability(for_mutation=True)
    if db.one('SELECT id FROM public.customers WHERE id=%s FOR SHARE', (principal.customer_id,)) is None:
        raise ApiFailure(401, 'unauthenticated', 'A valid API key is required.')
    return principal.revalidate_for_mutation()


def _in_progress():
    return ApiFailure(409, 'in_progress', 'An identical request is still being processed. Retry shortly with the same Idempotency-Key.', {'Retry-After': '2'})


def _acquire(principal, request, db):
    deadline = time.monotonic() + 2
    fingerprint = request.fingerprint()
    while time.monotonic() < deadline:
        try:
            with transaction.atomic():
                preference = _fence(principal, db)
                with connection.cursor() as cursor:
                    cursor.execute("SET LOCAL lock_timeout = '500ms'")
                claim = db.one('''SELECT * FROM public.customer_api_order_claims
                    WHERE customer_id=%s AND operation='create_order' AND idempotency_key=%s''',
                    (principal.customer_id, request.idempotency_key))
                if claim is None:
                    if db.one('SELECT id FROM public.products WHERE id=%s', (request.product_id,)) is None:
                        raise ApiFailure(404, 'not_found', 'Resource not found.')
                    token = secrets.token_hex(16)
                    claim = db.one('''INSERT INTO public.customer_api_order_claims
                        (customer_id,operation,idempotency_key,fingerprint,credential_id,state,attempt_token,
                         lease_expires_at,preference,preference_source,product_id,quantity,expected_idr_total,
                         expected_usd_total_micro,created_at,updated_at)
                        VALUES (%s,'create_order',%s,%s,%s,'in_progress',%s,
                         timezone('Asia/Jakarta',clock_timestamp()) + interval '30 seconds',%s,%s,%s,%s,%s,%s,
                         timezone('Asia/Jakarta',clock_timestamp()),timezone('Asia/Jakarta',clock_timestamp()))
                        ON CONFLICT (customer_id,operation,idempotency_key) DO NOTHING RETURNING *''',
                        (principal.customer_id, request.idempotency_key, fingerprint, principal.credential_id,
                         token, request.preference or preference,
                         'override' if request.preference else 'account', request.product_id,
                         request.quantity, request.expected_idr, request.expected_usd_micro))
                    if claim is not None:
                        return claim, False
                else:
                    if not hmac.compare_digest(claim['fingerprint'], fingerprint):
                        raise ApiFailure(409, 'idempotency_conflict', 'This Idempotency-Key was already used with a different request body.')
                    if claim['state'] == 'accepted':
                        return claim, True
                    # A lock timeout bounds the wait behind an active financial commit.
                    with connection.cursor() as cursor:
                        cursor.execute("SET LOCAL lock_timeout = '500ms'")
                    token = secrets.token_hex(16)
                    acquired = db.one('''UPDATE public.customer_api_order_claims SET attempt_token=%s,
                        lease_expires_at=timezone('Asia/Jakarta',clock_timestamp()) + interval '30 seconds',
                        updated_at=timezone('Asia/Jakarta',clock_timestamp())
                        WHERE id=%s AND state='in_progress' AND
                        (lease_expires_at IS NULL OR lease_expires_at < timezone('Asia/Jakarta',clock_timestamp()))
                        RETURNING *''', (token, claim['id']))
                    if acquired is not None:
                        return acquired, False
        except DatabaseError as exc:
            state = getattr(exc.__cause__, 'sqlstate', None)
            if state not in ('55P03', '40P01'):
                raise
        remaining = deadline - time.monotonic()
        if remaining > 0:
            time.sleep(min(0.1, remaining))
    raise _in_progress()


def _abandon(principal, claim, db):
    try:
        with transaction.atomic():
            _fence(principal, db)
            db.execute('''UPDATE public.customer_api_order_claims SET attempt_token=NULL,
                lease_expires_at=timezone('Asia/Jakarta',clock_timestamp()),
                updated_at=timezone('Asia/Jakarta',clock_timestamp())
                WHERE id=%s AND state='in_progress' AND attempt_token=%s''',
                (claim['id'], claim['attempt_token']))
    except Exception:
        pass


def _commit(principal, request, claim, db, checkout):
    _fence(principal, db)
    owned = db.one('SELECT * FROM public.customer_api_order_claims WHERE id=%s FOR UPDATE', (claim['id'],))
    if owned is None or owned['state'] != 'in_progress' or owned['attempt_token'] != claim['attempt_token']:
        raise _in_progress()
    visible = db.one('''SELECT p.id FROM public.products p WHERE p.id=%s AND p.is_active AND p.deleted_at IS NULL
        AND (p.category_id IS NULL OR EXISTS (SELECT 1 FROM public.categories c
             WHERE c.id=p.category_id AND c.is_active AND c.deleted_at IS NULL))''', (request.product_id,))
    if visible is None:
        raise ApiFailure(404, 'not_found', 'Resource not found.')
    cart = db.one('''INSERT INTO public.carts (customer_id,origin,status,expires_at,created_at,updated_at)
        VALUES (%s,'api','active',timezone('Asia/Jakarta',clock_timestamp()) + interval '30 minutes',
                timezone('Asia/Jakarta',clock_timestamp()),timezone('Asia/Jakarta',clock_timestamp())) RETURNING id''',
        (principal.customer_id,))
    db.execute('''INSERT INTO public.cart_items (cart_id,product_id,quantity,created_at,updated_at)
        VALUES (%s,%s,%s,timezone('Asia/Jakarta',clock_timestamp()),timezone('Asia/Jakarta',clock_timestamp()))''',
        (cart['id'], request.product_id, request.quantity))
    key = 'api-' + hashlib.sha256(f"api-order:{claim['id']}".encode()).hexdigest()[:60]
    checkout_request = CheckoutRequest(principal.customer_id, cart['id'], key,
        checkout.quote_cart(principal.customer_id, cart['id']), balance_request=BalanceCheckoutRequest(
            owned['preference'], owned['expected_idr_total'], owned['expected_usd_total_micro'], owned['preference_source']))
    result = checkout.checkout_api_locked(checkout_request, claim['id'])
    updated = db.execute('''UPDATE public.customer_api_order_claims SET state='accepted',order_id=%s,
        attempt_token=NULL,lease_expires_at=NULL,updated_at=timezone('Asia/Jakarta',clock_timestamp())
        WHERE id=%s AND state='in_progress' AND attempt_token=%s''',
        (result.order_id, claim['id'], claim['attempt_token']))
    if updated != 1:
        raise _in_progress()
    return result.order_id


def _payload(db, customer_id, order_id, replayed, request_id):
    row = db.one('''SELECT o.id,o.invoice_number,o.order_status,o.payment_status,o.currency AS order_currency,
        o.created_at,p.currency,p.amount,i.product_id,i.product_name,i.quantity
        FROM public.orders o
        LEFT JOIN LATERAL (SELECT currency,amount FROM public.payments WHERE order_id=o.id
          ORDER BY (status='paid') DESC, id DESC LIMIT 1) p ON true
        LEFT JOIN LATERAL (SELECT product_id,product_name,quantity FROM public.order_items
          WHERE order_id=o.id ORDER BY id LIMIT 1) i ON true
        WHERE o.id=%s AND o.customer_id=%s AND o.api_order_claim_id IS NOT NULL''', (order_id, customer_id))
    if row is None:
        raise ApiFailure(404, 'not_found', 'Resource not found.')
    currency = row['currency'] or row['order_currency']
    amount = row['amount']
    if amount is not None:
        amount = Money(Currency.USDT, amount).format_decimal() if currency == 'USDT' else str(amount)
    created = row['created_at']
    if created is not None and created.tzinfo is None:
        created = created.replace(tzinfo=ZoneInfo('Asia/Jakarta'))
    return {'data': {'id': str(row['id']), 'invoice_number': row['invoice_number'], 'origin': 'api',
        'status': row['order_status'], 'payment_status': row['payment_status'],
        'currency': 'USD' if currency == 'USDT' else currency, 'amount': amount,
        'quantity': row['quantity'] or 0,
        'product': None if row['product_id'] is None else {'id': str(row['product_id']), 'name': row['product_name']},
        'created_at': created.isoformat() if created else None, 'request_id': request_id},
        'idempotent_replay': replayed}


def create_order(principal, request, request_id, *, supplier_encrypter=None):
    """Create or recover one API purchase; caller performs shared admission first."""
    db = Database()
    check_availability()
    claim, replayed = _acquire(principal, request, db)
    if replayed:
        return _payload(db, principal.customer_id, claim['order_id'], True, request_id)
    checkout = CheckoutService(reseller=True, supplier_encrypter=supplier_encrypter)
    try:
        for attempt in range(3):
            try:
                with transaction.atomic():
                    order_id = _commit(principal, request, claim, db, checkout)
                return _payload(db, principal.customer_id, order_id, False, request_id)
            except DatabaseError as exc:
                if getattr(exc.__cause__, 'sqlstate', None) not in ('40P01', '40001') or attempt == 2:
                    raise
    except CheckoutError as exc:
        _abandon(principal, claim, db)
        code = str(exc)
        if code == 'price_changed':
            pricing = getattr(exc, 'pricing', None)
            quotes = []
            if pricing is not None:
                quotes.append({'currency': 'IDR', 'total': str(pricing.idr_total)})
                if pricing.usdt_total is not None:
                    quotes.append({'currency': 'USD', 'total': Money(Currency.USDT, pricing.usdt_total).format_decimal()})
            raise ApiFailure(409, 'price_changed', 'Checkout prices changed; authorize fresh totals before purchasing.', details={'quotes': quotes}) from exc
        if code == 'balance_price_consent_required':
            raise _invalid('An expected total is required for the primary currency of the resolved payment preference.') from exc
        if code in ('insufficient_balance', 'insufficient_store_balance', 'insufficient_usdt_balance'):
            raise ApiFailure(402, 'insufficient_funds', 'No eligible balance wallet fully covers this order.') from exc
        if code == 'insufficient_stock':
            raise ApiFailure(409, 'stock_conflict', 'The requested quantity is no longer available.') from exc
        if code in ('unavailable_product', 'invalid_cart', 'empty_cart'):
            raise _invalid('The order could not be created as requested.') from exc
        raise unavailable() from exc
    except Exception:
        _abandon(principal, claim, db)
        raise
