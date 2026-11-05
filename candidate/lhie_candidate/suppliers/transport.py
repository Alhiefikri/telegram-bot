"""Private supplier contracts. HTTP is injected and never enabled by default.

The injected transport must enforce the supplied timeout and response-size limit
while streaming, disable redirects, and return an HttpResponse. It must not log
URLs, headers, bodies, or provider responses: these can contain secrets.
"""
from dataclasses import dataclass, field
from datetime import datetime
from email.utils import parsedate_to_datetime
import hashlib
import json
import re
import time
from urllib.parse import quote, urlencode
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

from django.db import connections
from ..auth.limits import RedisAdmission


MAX_BYTES = 2_097_152
MAX_PRODUCTS = 500
MAX_QUANTITY = 10_000
BASE_URLS = {
    'prodseller': 'https://prodseller.com/v1',
    'csxstore': 'https://api.cssx.store/api/v1',
    'arrs': 'https://arrsnetworkzone.in/api/v1',
    'warzone': 'https://api.warzoneshop.in/api/v1',
    'aivault': 'https://reseller.aivaulthub.store/api/v1',
    'nystore': 'https://nystoreofficial.shop/api/v1',
    'toolorax': 'https://shopbot.00969600.xyz/shop-api/v1',
    'canboso': 'https://canboso.com/api/v2/telegram-buyer',
    'nastele': 'https://nastele.online/api/partner',
    'miraiapi': 'https://api.miraiapi.com/api/reseller/v1',
}
IDEMPOTENT = frozenset(('prodseller', 'aivault', 'nystore', 'canboso', 'miraiapi'))

_BUDGETS = {
    'prodseller': (300, 900, 240),
    'csxstore': (60, 60, 45),
    'arrs': (3, 1, 2),
    'warzone': (3, 1, 2),
    'aivault': (60, 60, 45),
    'nystore': (3, 1, 2),
    'toolorax': (30, 60, 20),
    'canboso': (30, 60, 20),
    'nastele': (60, 60, 45),
    'miraiapi': (60, 60, 45),
}

_RESERVE = """
if redis.call('EXISTS', KEYS[3]) ~= 0 then return 0 end
for i = 1, 2 do
  if i == 1 or ARGV[4] == '1' then
    local value = redis.call('GET', KEYS[i])
    if value and (not tonumber(value) or redis.call('PTTL', KEYS[i]) < 0) then
      return redis.error_reply('invalid supplier budget')
    end
    if tonumber(value or '0') >= tonumber(ARGV[i]) then return 0 end
  end
end
for i = 1, 2 do
  if i == 1 or ARGV[4] == '1' then
    local count = redis.call('INCR', KEYS[i])
    if count == 1 then redis.call('EXPIRE', KEYS[i], ARGV[3]) end
  end
end
return 1
"""
_COOLDOWN = """
local ttl = redis.call('TTL', KEYS[1])
if ttl == -1 then return redis.error_reply('invalid supplier cooldown') end
if ttl < tonumber(ARGV[1]) then redis.call('SET', KEYS[1], '1', 'EX', ARGV[1]) end
return 1
"""


def _retry_after(provider, headers):
    seconds = 900 if provider == 'prodseller' else 60
    maximum = 21600 if provider == 'canboso' else 3600
    values = [value for name, value in headers.items()
              if isinstance(name, str) and name.lower() == 'retry-after']
    if len(values) == 1 and isinstance(values[0], str):
        value = values[0].strip()
        try:
            if re.fullmatch(r'[0-9]{1,10}', value):
                seconds = int(value)
            else:
                parsed = parsedate_to_datetime(value)
                if parsed.tzinfo is not None:
                    seconds = int(parsed.timestamp() - time.time())
        except (ValueError, TypeError, OverflowError):
            pass
    return min(maximum, max(1, seconds))


class SupplierUnavailable(ValueError):
    def __init__(self, code):
        self.code = code
        super().__init__(code)


@dataclass(frozen=True, slots=True, repr=False)
class SupplierConfig:
    provider: str
    currency: str
    api_key: str
    active: bool = False
    currency_confirmed: bool = False


@dataclass(frozen=True, slots=True, repr=False)
class HttpResponse:
    status: int
    body: bytes
    headers: dict = field(default_factory=dict)


@dataclass(frozen=True, slots=True)
class Product:
    external_id: str
    name: str
    description: str | None
    unit_price_micros: int | None
    stock: int | None
    orderable: bool
    requires_input: bool
    tiers: tuple = ()
    discounts: tuple = ()


@dataclass(frozen=True, slots=True)
class CatalogSnapshot:
    balance_micros: int
    currency: str
    products: tuple

@dataclass(frozen=True, slots=True)
class OrderHistorySnapshot:
    limit: int
    count: int
    orders: tuple
    timezone: str
    digest: str
    coverage: str = 'first_page'


@dataclass(frozen=True, slots=True, repr=False)
class OrderResult:
    status: str
    order_id: str | None = None
    quantity: int | None = None
    cost_micros: int | None = None
    deliveries: tuple = ()
    error_code: str | None = None
    identity_trusted: bool = False
    delivery_trusted: bool = False


def _fail(code='invalid_payload'):
    raise SupplierUnavailable(code) from None


def _identifier(value, limit=160):
    if type(value) is int:
        value = str(value)
    if (type(value) is not str or len(value) > limit or value in ('.', '..')
            or re.fullmatch(r'[A-Za-z0-9_.:-]+', value) is None):
        _fail('invalid_identifier')
    return value


def _text(value, limit):
    if (type(value) is not str or not value.strip() or len(value.encode('utf-8')) > limit
            or re.search(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]', value)):
        _fail()
    return value


def _int(value, minimum, maximum):
    if type(value) is not int or not minimum <= value <= maximum:
        _fail()
    return value


def _money(value, round_up=True):
    if type(value) not in (int, str):
        _fail()
    value = str(value)
    if len(value) > 32 or re.fullmatch(r'(?:0|[1-9][0-9]*)(?:\.[0-9]{1,24})?', value) is None:
        _fail()
    whole, _, fraction = value.partition('.')
    micros = int(whole) * 1_000_000 + int((fraction + '000000')[:6])
    if round_up and any(c != '0' for c in fraction[6:]):
        micros += 1
    if micros > 2**63 - 1:
        _fail()
    return micros


def _bool(row, key, default=False):
    value = row.get(key, default)
    if type(value) is not bool:
        _fail()
    return value


def _first(row, *names):
    return next((row[name] for name in names if row.get(name) is not None), None)


def _payload(response, key=None):
    def unique(pairs):
        result = {}
        for name, value in pairs:
            if name in result:
                raise ValueError
            result[name] = value
        return result
    try:
        if type(response.body) is not bytes or len(response.body) > MAX_BYTES:
            raise ValueError
        result = json.loads(response.body, parse_float=str, object_pairs_hook=unique,
                            parse_constant=lambda _: (_ for _ in ()).throw(ValueError()))
        if type(result) is not dict:
            raise ValueError
        count = 0
        def inspect(node, depth=0):
            nonlocal count
            if depth > 32:
                raise ValueError
            if isinstance(node, (dict, list)):
                entries = node.items() if isinstance(node, dict) else enumerate(node)
                for name, value in entries:
                    count += 1
                    if count > 50_000:
                        raise ValueError
                    for text in (name, value):
                        if isinstance(text, str) and (len(text.encode('utf-8')) > 16_000 or (key and key in text)):
                            raise ValueError
                    inspect(value, depth + 1)
        inspect(result)
        return result
    except (ValueError, TypeError, UnicodeError, RecursionError):
        _fail()


def _currency(row, currency):
    if 'currency' in row and row['currency'] != currency:
        _fail('currency_mismatch')


def _tiers(rows, provider):
    if rows is None:
        return ()
    if type(rows) is not list or len(rows) > 100:
        _fail()
    tiers = []
    for row in rows:
        if type(row) is not dict:
            _fail()
        minimum = _int(_first(row, 'min', 'min_qty'), 1, MAX_QUANTITY)
        maximum = _first(row, 'max', 'max_qty')
        if maximum is not None:
            maximum = _int(maximum, minimum, 1_000_000_000)
        tiers.append((minimum, maximum, _money(_first(row, 'price', 'unit_price'))))
    tiers.sort(key=lambda tier: tier[0])
    result, previous = [], 0
    for minimum, maximum, price in tiers:
        if minimum <= previous:
            if provider != 'aivault':
                _fail()
            if maximum is not None and maximum <= previous:
                continue
            minimum = previous + 1
        result.append((minimum, maximum, price))
        previous = maximum if maximum is not None else 2**63 - 1
    return tuple(result)


def _discounts(rows):
    if type(rows) is not list or len(rows) > 100:
        _fail()
    result = {}
    for row in rows:
        if type(row) is not dict:
            _fail()
        percent = _money(row.get('discount_pct'))
        minimum = _int(row.get('min_qty'), 1, MAX_QUANTITY)
        if percent > 100_000_000 or percent % 10_000 or minimum in result:
            _fail()
        result[minimum] = percent // 10_000
    return tuple(sorted(result.items()))


def _product(provider, row, identity):
    if type(row) is not dict:
        _fail()
    row = dict(row)
    if provider == 'toolorax':
        if 'price' not in row:
            row['price'] = row.get('unit_price')
        if 'stock' not in row:
            row['stock'] = row.get('stock_count')
    name = _text(row.get('name'), 500)
    description = row.get('description')
    if description not in (None, ''):
        description = _text(description, 16_000)
    else:
        description = None
    stock = row.get('stock')
    if stock is not None:
        stock = _int(stock, 0, 1_000_000_000)
    elif 'stock' not in row:
        _fail()
    requires = _bool(row, 'requiresEmailActivation') or _bool(row, 'requires_input')
    if row.get('delivery') is not None:
        delivery = row['delivery']
        if type(delivery) is not dict or type(delivery.get('type')) is not str:
            _fail()
        requires = requires or delivery['type'] not in ('instant', 'key', 'account', 'link')
    tiers = _tiers(_first(row, 'price_tiers', 'pricing_tiers') or [], provider)
    discounts = _discounts(row.get('bulk_discounts', []))
    price = _money(row['price']) if row.get('price') is not None else next((t[2] for t in tiers if t[0] == 1), None)
    orderable = (_bool(row, 'orderable', True) and _bool(row, 'in_stock', True)
                 and _bool(row, 'inStock', True) and not requires and stock is not None
                 and stock > 0 and price is not None and row.get('pricing') != 'unavailable')
    return Product(identity, name, description, price, stock, orderable, requires, tiers, discounts)



class SupplierClient:
    def __init__(self, config, transport=None, budget=None):
        self.config = config
        self.transport = transport
        self.budget = budget if budget is not None else RedisAdmission()

    def _ready(self, recovery=False):
        c = self.config
        if c.provider not in BASE_URLS:
            _fail('unsupported_provider')
        if not recovery and not c.active:
            _fail('inactive')
        expected = 'VND' if c.provider in ('nastele', 'miraiapi') else ('USDT' if c.provider == 'prodseller' else 'USD')
        if c.provider in ('arrs', 'warzone'):
            if c.currency not in ('USD', 'USDT') or not c.currency_confirmed:
                _fail('currency_mismatch')
        elif c.currency != expected:
            _fail('currency_mismatch')
        if type(c.api_key) is not str or not 1 <= len(c.api_key) <= 512 or re.fullmatch(r'[!-~]+', c.api_key) is None:
            _fail('invalid_key')
        if any(connection.in_atomic_block for connection in connections.all(initialized_only=True)):
            _fail('financial_lock')
        if self.transport is None:
            _fail('transport_disabled')

    def _send(self, method, path, body=None, headers=None, recovery=False, deadline=None):
        self._ready(recovery)
        c = self.config
        auth = {} if c.provider == 'canboso' else ({'X-Shop-API-Key': c.api_key} if c.provider == 'toolorax' else {'Authorization': 'Bearer ' + c.api_key} if c.provider == 'miraiapi' else {'X-API-Key': c.api_key})
        auth.update(headers or {})
        if c.provider == 'canboso' and method == 'GET':
            path += ('&' if '?' in path else '?') + urlencode({'key': c.api_key})
        timeout = 90 if method == 'POST' else 12
        if deadline is not None:
            timeout = min(timeout, deadline - time.monotonic())
            if timeout <= 0:
                _fail('snapshot_timeout')
        maximum, window, reads = _BUDGETS[c.provider]
        prefix = 'candidate:supplier:budget:' + c.provider
        try:
            admitted = self.budget.eval(_RESERVE, 3, prefix, prefix + ':reads',
                                        prefix + ':cooldown', maximum, reads, window,
                                        int(method == 'GET'))
            if type(admitted) is not int or admitted != 1:
                _fail('rate_limited')
        except SupplierUnavailable:
            raise
        except Exception:
            _fail('rate_limited')
        if deadline is not None:
            timeout = min(timeout, deadline - time.monotonic())
            if timeout <= 0:
                _fail('snapshot_timeout')
        try:
            response = self.transport(method=method, url=BASE_URLS[c.provider] + path,
                                      headers=auth, json=body, timeout=timeout,
                                      connect_timeout=min(4, timeout), max_bytes=MAX_BYTES, allow_redirects=False)
            if (not isinstance(response, HttpResponse) or type(response.status) is not int
                    or not 100 <= response.status <= 599 or type(response.body) is not bytes
                    or len(response.body) > MAX_BYTES):
                raise ValueError
            lengths = [v for k, v in response.headers.items() if k.lower() == 'content-length']
            if lengths and (len(lengths) != 1 or not str(lengths[0]).isdigit() or int(lengths[0]) > MAX_BYTES):
                raise ValueError
        except Exception:
            _fail('transport')
        if response.status == 429:
            try:
                observed = self.budget.eval(_COOLDOWN, 1, prefix + ':cooldown',
                                            _retry_after(c.provider, response.headers))
                if type(observed) is not int or observed != 1:
                    raise ValueError
            except Exception:
                _fail('transport' if method == 'POST' else 'rate_limited')
        return response

    def _read(self, path, recovery=False, deadline=None):
        response = self._send('GET', path, recovery=recovery, deadline=deadline)
        if not 200 <= response.status < 300:
            _fail(_http_code(response.status))
        return _payload(response, self.config.api_key)

    def snapshot(self):
        self._ready()
        deadline = time.monotonic() + 40
        p, currency = self.config.provider, self.config.currency
        account = self._read('/balance' if p in ('prodseller', 'canboso', 'nastele', 'miraiapi') else '/me', deadline=deadline)
        catalog = self._read('/packages' if p == 'miraiapi' else '/products', deadline=deadline)
        if p in ('nastele', 'miraiapi'):
            if account.get('success') is not True or catalog.get('success') is not True or type(account.get('data')) is not dict:
                _fail()
            if p == 'nastele' and account['data'].get('currency') != 'VND':
                _fail('currency_mismatch')
            balance = _money(account['data'].get('balance' if p == 'nastele' else 'balance_vnd'), False)
            rows = catalog.get('data')
        elif p == 'canboso':
            if any(data.get('success') is not True or data.get('walletCurrency') != currency for data in (account, catalog)):
                _fail('currency_mismatch')
            balance, rows = _money(account.get('balance'), False), catalog.get('products')
        else:
            _currency(account, currency)
            _currency(catalog, currency)
            balance = _money(_first(account, 'balance', 'wallet_balance'), False)
            rows = catalog.get('products' if p in ('prodseller', 'aivault', 'toolorax') else 'services')
        if type(rows) is not list or len(rows) > MAX_PRODUCTS:
            _fail()
        products, seen, details = [], set(), 0
        for row in rows:
            if type(row) is not dict:
                _fail()
            if p == 'nastele':
                identity = str(_int(row.get('id'), 1, 2_147_483_647))
            else:
                identity = _identifier(row.get('code' if p == 'miraiapi' else 'productId' if p == 'canboso' else 'id' if p in ('prodseller', 'toolorax') else 'service_id'))
            if identity in seen:
                _fail()
            seen.add(identity)
            if p == 'miraiapi':
                minimum = _int(row.get('min_quantity'), 1, MAX_QUANTITY)
                maximum = _int(row.get('max_quantity'), minimum, MAX_QUANTITY)
                unlimited = _bool(row, 'unlimited')
                remaining = _int(row.get('remaining_today'), 0, 1_000_000_000)
                price = _money(row.get('reseller_price_vnd'))
                stock = (maximum if unlimited else min(remaining, maximum)) if minimum == 1 else 0
                products.append(Product(identity, _text(row.get('name'), 500), None, price, stock, minimum == 1 and price > 0 and (unlimited or remaining > 0), False))
                continue
            if p == 'canboso':
                price, availability = row.get('price'), row.get('availability')
                if type(price) is not dict or type(availability) is not dict or price.get('currency') != currency:
                    _fail('currency_mismatch')
                row = {'name': row.get('name'), 'description': row.get('description'), 'price': price.get('amount'), 'stock': availability.get('available'), 'requires_input': row.get('productType') != 'account' or bool(row.get('purchaseRequirements'))}
            elif p == 'prodseller' and 'stock' not in row:
                details += 1
                if details > 100:
                    _fail('catalog_limit')
                detail = self._read('/products/' + quote(identity, safe=''), deadline=deadline)
                if _identifier(detail.get('id')) != identity:
                    _fail()
                row = {**row, **detail}
            _currency(row, currency)
            product = _product(p, row, identity)
            if p == 'nastele':
                tiers = row.get('wholesale_tiers', [])
                if type(tiers) is not list or len(tiers) > 100:
                    _fail()
                entries = []
                for tier in tiers:
                    if type(tier) is not dict:
                        _fail()
                    entries.append((_int(tier.get('minQuantity'), 1, MAX_QUANTITY), _money(tier.get('unitPrice'))))
                entries.sort()
                if len({entry[0] for entry in entries}) != len(entries):
                    _fail()
                normalized = tuple((minimum, entries[i + 1][0] - 1 if i + 1 < len(entries) else None, price) for i, (minimum, price) in enumerate(entries))
                product = Product(product.external_id, product.name, product.description, product.unit_price_micros, product.stock, product.orderable, product.requires_input, normalized, product.discounts)
            products.append(product)
        if time.monotonic() >= deadline:
            _fail('snapshot_timeout')
        return CatalogSnapshot(balance, currency, tuple(products))

    def purchase(self, product_id, quantity, reference):
        try:
            self._ready()
            p = self.config.provider
            identity = _identifier(product_id)
            reference = _identifier(reference, 100)
            quantity = _int(quantity, 1, MAX_QUANTITY)
            if p in ('toolorax', 'nastele'):
                if not identity.isascii() or not identity.isdigit() or (p == 'nastele' and not 1 <= int(identity) <= 2_147_483_647):
                    _fail('unsupported_product')
            body = ({'productId': identity, 'quantity': quantity} if p == 'prodseller' else
                    {'product_id': int(identity), 'quantity': quantity} if p == 'toolorax' else
                    {'productId': int(identity), 'qty': quantity} if p == 'nastele' else
                    {'key': self.config.api_key, 'product_id': identity, 'quantity': quantity} if p == 'canboso' else
                    {'package_code': identity, 'quantity': quantity, 'external_order_id': reference} if p == 'miraiapi' else
                    {'service_id': identity, 'quantity': quantity})
            if p == 'nystore':
                body.update(precheck=False, include_proofs=False)
            elif p == 'aivault':
                body['external_order_id'] = reference
            headers = {'Idempotency-Key': reference} if p in ('prodseller', 'nystore', 'canboso', 'miraiapi') else {}
            path = '/orders' if p in ('prodseller', 'toolorax', 'nastele', 'miraiapi') else '/purchase' if p == 'canboso' else '/order'
            response = self._send('POST', path, body, headers)
        except SupplierUnavailable as exc:
            return OrderResult('ambiguous' if exc.code == 'transport' else 'rejected', error_code=exc.code)
        if not 200 <= response.status < 300:
            if p == 'nystore':
                try:
                    order_id = _identifier(_payload(response, self.config.api_key).get('order_id'))
                    return OrderResult('ambiguous', order_id, error_code=_http_code(response.status), identity_trusted=True)
                except SupplierUnavailable:
                    pass
            return OrderResult('rejected' if _no_purchase(p, response, self.config.api_key) else 'ambiguous',
                               error_code=_http_code(response.status))
        try:
            result = _order_result(p, self.config.currency, _payload(response, self.config.api_key), quantity, None, identity, reference)
            if p == 'toolorax' and result.order_id and result.error_code == 'invalid_order_status':
                try:
                    detail = self.order(result.order_id, identity, reference)
                    if (detail.error_code == 'payload_mismatch' or detail.quantity != quantity or detail.cost_micros is None
                            or (result.quantity is not None and result.quantity != detail.quantity)
                            or (result.cost_micros is not None and result.cost_micros != detail.cost_micros)
                            or (result.deliveries and set(result.deliveries) != set(detail.deliveries))):
                        return OrderResult('ambiguous', result.order_id, error_code='payload_mismatch', identity_trusted=True)
                    if detail.identity_trusted:
                        return detail
                except SupplierUnavailable:
                    pass
                return OrderResult('ambiguous', result.order_id, error_code='delivery_pending', identity_trusted=True)
            return result
        except (SupplierUnavailable, ValueError, TypeError, OverflowError):
            return OrderResult('ambiguous', error_code='invalid_payload')

    def order(self, order_id, product_id=None, reference=None):
        self._ready(True)
        p = self.config.provider
        order_id = _identifier(order_id)
        if product_id is not None:
            product_id = _identifier(product_id)
        if reference is not None:
            reference = _identifier(reference, 100)
        if p == 'canboso':
            payload = self._read('/orders?' + urlencode({'orderCode': order_id}), True)
            rows = payload.get('orders')
            if payload.get('success') is not True or type(rows) is not list or len(rows) != 1 or type(rows[0]) is not dict or rows[0].get('orderCode') != order_id:
                _fail()
            return OrderResult('ambiguous', order_id, error_code='delivery_unavailable', identity_trusted=True)
        path = '/orders/' if p in ('prodseller', 'toolorax', 'nastele', 'miraiapi') else '/order/'
        return _order_result(p, self.config.currency, self._read(path + quote(order_id, safe=''), True), None, order_id, product_id, reference)

    def order_history(self, timezone):
        provider = self.config.provider
        if provider not in ('csxstore', 'toolorax'):
            _fail('order_history_unsupported')
        zone = None
        if provider == 'toolorax':
            try:
                zone = ZoneInfo(timezone)
            except (ZoneInfoNotFoundError, ValueError, TypeError):
                _fail('invalid_history_timezone')
        payload = self._read('/orders' if provider == 'csxstore' else '/orders?limit=100',
                             recovery=True, deadline=time.monotonic() + 12)
        rows = payload.get('orders')
        if type(rows) is not list or len(rows) > 100:
            _fail('invalid_order_history')
        if provider == 'csxstore':
            _currency(payload, self.config.currency)
            count, limit, pages = payload.get('total_orders'), payload.get('limit'), payload.get('total_pages')
            if (payload.get('success') is not True or type(payload.get('page')) is not int
                    or payload['page'] != 1 or type(limit) is not int or limit < 1
                    or type(count) is not int or not 0 <= count <= 2_147_483_647
                    or type(pages) is not int or pages < 0 or len(rows) > min(limit, count)):
                _fail('invalid_order_history')
        else:
            count = payload.get('count')
            if payload.get('ok') is not True or type(count) is not int or count < len(rows):
                _fail('invalid_order_history')
        orders, digest_rows, seen = [], [], set()
        for row in rows:
            if type(row) is not dict:
                _fail('invalid_order_history')
            if provider == 'csxstore':
                _currency(row, self.config.currency)
            identity = _identifier(row.get('order_id' if provider == 'csxstore' else 'order_code'),
                                   128 if provider == 'csxstore' else 160)
            if identity in seen:
                _fail('invalid_order_history')
            seen.add(identity)
            product = row.get('service_id' if provider == 'csxstore' else 'product_id')
            if provider == 'csxstore':
                product = _identifier(product)
            else:
                if type(product) is str and product.isascii() and product.isdigit() and len(product) <= 10:
                    product = int(product)
                product = _int(product, 1, 2_147_483_647)
            quantity = _int(row.get('quantity'), 1, MAX_QUANTITY)
            cost = _money(row.get('total_cost' if provider == 'csxstore' else 'total'), False)
            status = _text(row.get('status'), 40)
            raw_time = _text(row.get('created_at'), 32)
            fmt = '%Y-%m-%d %H:%M' if provider == 'toolorax' and len(raw_time) == 16 else '%Y-%m-%d %H:%M:%S'
            try:
                created = datetime.strptime(raw_time, fmt)
                if created.strftime(fmt) != raw_time:
                    raise ValueError
            except ValueError:
                _fail('invalid_order_history')
            entry = {'order_id': identity, 'product_id': product, 'quantity': quantity,
                     'total_micros': cost}
            if provider == 'csxstore':
                entry.update(currency=self.config.currency,
                             status=status if status in ('completed', 'pending', 'paid', 'queued', 'undelivered', 'processing', 'failed', 'cancelled', 'refunded') else 'unrecognized',
                             created_at=None, created_at_raw=raw_time, timezone_qualification='unknown_offset_free')
                digest_rows.append(entry)
            else:
                entry.update(status=status, created_at=created.replace(tzinfo=zone),
                             timestamp_precision='minute' if len(raw_time) == 16 else 'second')
                digest_rows.append({**entry, 'created_at': created.strftime('%Y-%m-%d %H:%M:%S')})
            orders.append(entry)
        encoded = json.dumps(digest_rows, ensure_ascii=True, separators=(',', ':')).replace('/', '\\/')
        digest = hashlib.sha256(encoded.encode('utf-8')).hexdigest()
        coverage = ('truncated' if len(rows) >= 100 or count > len(rows) or pages > 1 else 'first_page') if provider == 'csxstore' else 'first_page'
        return OrderHistorySnapshot(100, count, tuple(orders), 'unknown' if provider == 'csxstore' else timezone, digest, coverage)


def _no_purchase(provider, response, key):
    status = response.status
    documented = {
        'nastele': (401, 402, 403, 404, 422, 429),
        'miraiapi': (400, 401, 403, 404, 422, 429),
        'canboso': (400, 401, 404, 429),
        'warzone': (400, 401, 403, 404, 405, 413, 429, 503),
        'aivault': (400, 401, 402, 404, 429),
        'nystore': (400, 401, 403, 404, 415, 429),
        'toolorax': (400, 401, 402, 403, 404, 429),
        'prodseller': (400, 401, 402, 404, 429),
        'csxstore': (400, 401, 404, 429),
        'arrs': (400, 429),
    }
    if status in documented.get(provider, ()):
        return True
    if (provider, status) not in (('nastele', 409), ('miraiapi', 409),
                                  ('aivault', 409), ('aivault', 500), ('toolorax', 409)):
        return False
    try:
        data = _payload(response, key)
    except SupplierUnavailable:
        return False
    if provider == 'nastele':
        return data.get('success') is False and data.get('message', data.get('error')) in (
            'Insufficient stock', 'Insufficient flash sale quantity', 'Product price changed')
    if provider == 'miraiapi':
        return data.get('message') in ('insufficient wallet balance', 'package is not available')
    if provider == 'aivault':
        return (status == 409 and data.get('error') == 'Buffer Stock Conflict') or (status == 500 and data.get('error') == 'internal_error')
    return data.get('error') in ('Insufficient balance.', 'Quantity is outside the available range.')


def _http_code(status):
    if 300 <= status < 400:
        return 'redirect'
    if status in (401, 403):
        return 'authentication'
    if status == 429:
        return 'rate_limited'
    if status == 409:
        return 'conflict'
    return 'upstream' if status >= 500 else 'request_failed'


def _order_result(provider, currency, payload, expected_quantity, expected_id, expected_product, reference):
    if provider == 'canboso':
        row, payment = payload.get('order'), payload.get('payment')
        if payload.get('success') is not True or type(row) is not dict or type(payment) is not dict:
            _fail()
        if (payment.get('currency') != currency or row.get('productId') != expected_product
                or type(row.get('quantity')) is not int or row['quantity'] != expected_quantity
                or row.get('productType') != 'account'):
            return OrderResult('ambiguous', error_code='payload_mismatch')
        identity, cost = _identifier(row.get('orderCode')), _money(payment.get('amount'))
        delivery = payload.get('delivery', {})
        if type(delivery) is not dict or type(delivery.get('accounts', [])) is not list or len(delivery.get('accounts', [])) > MAX_QUANTITY:
            _fail()
        units, users = [], set()
        for account in delivery.get('accounts', []):
            if type(account) is not dict:
                _fail()
            user = _text(account.get('user'), 1000)
            users.add(user)
            lines = [user, _text(account.get('password'), 4000)]
            for field in ('verifyEmail', 'expiryText', 'otherInfo'):
                if account.get(field) not in (None, ''):
                    lines.append(field + ': ' + _text(account[field], 4000))
            units.append(_text('\n'.join(lines), 16_000))
        quantity_matches = (type(row.get('bonusQuantity')) is int and row['bonusQuantity'] == 0
                            and type(row.get('finalQuantity')) is int and row['finalQuantity'] == expected_quantity)
        complete = row.get('status') == 'completed' and quantity_matches and len(units) == expected_quantity and len(users) == expected_quantity
        return OrderResult('delivered' if complete else 'ambiguous', identity, expected_quantity, cost, tuple(units), None if complete else 'incomplete_delivery' if quantity_matches else 'unexpected_delivery_quantity', True, complete)
    if provider == 'nastele':
        row = payload.get('data')
        if payload.get('success') is not True or type(row) is not dict:
            _fail()
        identity = str(_int(row.get('orderId'), 1, 2**63 - 1))
        if expected_id is not None and identity != expected_id:
            return OrderResult('ambiguous', error_code='payload_mismatch')
        try:
            _currency(row, 'VND')
            items = row.get('items')
            if type(items) is not list or len(items) != 1 or type(items[0]) is not dict:
                _fail()
            item = items[0]
            product = _first(item, 'productId', 'product_id')
            if expected_product is not None and product is not None and _identifier(product) != expected_product:
                return OrderResult('ambiguous', error_code='payload_mismatch')
            quantity, cost = _int(item.get('quantity'), 1, MAX_QUANTITY), _money(row.get('totalAmount'))
            if (expected_quantity is not None and quantity != expected_quantity) or _money(item.get('unitPrice')) * quantity != cost:
                _fail('payload_mismatch')
            units = item.get('accounts', [])
            if type(units) is not list or len(units) > quantity:
                _fail()
            units = tuple(_text(unit, 16_000) for unit in units)
            status = row.get('status')
            if status != 'completed' and not (expected_id is None and status is None):
                return OrderResult('ambiguous', identity, deliveries=units, error_code='invalid_order_status', identity_trusted=True)
            complete = len(units) == quantity and len(set(units)) == quantity
            return OrderResult('delivered' if complete else 'ambiguous', identity, quantity, cost, units, None if complete else 'incomplete_delivery', True, complete)
        except SupplierUnavailable as exc:
            return OrderResult('ambiguous', identity, error_code=exc.code, identity_trusted=True)
    mirai = provider == 'miraiapi'
    if mirai:
        if payload.get('success') is not True:
            _fail()
        row = payload.get('data', payload)
        if type(row) is not dict:
            _fail()
        if type(row.get('order')) is dict:
            row = {**row, **row['order']}
    else:
        _currency(payload, currency)
        row = payload.get('order', payload)
        if type(row) is not dict:
            _fail()
        _currency(row, currency)
    names = ('order_id', 'orderId', 'id', 'order_code', 'orderCode', 'order_no', 'orderNo') if mirai else ('orderId', 'order_id', 'order_code')
    raw_id = _first(row, *names)
    if raw_id is None:
        raw_id = _first(payload, *names)
    identity = _identifier(raw_id) if raw_id is not None else None
    quantity = _first(row, 'quantity', 'qty' if mirai else 'requested_quantity')
    if quantity is not None:
        quantity = _int(quantity, 1, MAX_QUANTITY)
    cost_names = ('total_vnd', 'total_amount_vnd', 'charged_vnd', 'amount_vnd', 'total_price_vnd', 'total_price', 'total', 'amount', 'total_amount', 'charged_amount') if mirai else ('total_cost', 'charged_amount', 'amount', 'total')
    raw_cost = _first(row, *cost_names)
    if raw_cost is None:
        raw_cost = _first(payload, *cost_names)
    cost = _money(raw_cost) if raw_cost is not None else None
    if mirai:
        single = _first(row, 'redeem_code') or _first(payload, 'redeem_code')
        units = _first(row, 'redeem_codes')
        if units is None:
            units = _first(payload, 'redeem_codes')
        if units is None or units == []:
            units = [] if single is None else [single]
        product = _first(row, 'package_code', 'packageCode') or payload.get('package_code')
        external = _first(row, 'external_order_id', 'externalOrderId') or payload.get('external_order_id')
    else:
        units = _first(row, 'deliveredKeys', 'delivered_products', 'delivered_keys', 'products')
        if units is None:
            units = _first(payload, 'deliveredKeys', 'delivered_products', 'delivered_keys', 'products')
        if units is None:
            single = _first(row, 'deliveredKey') or _first(payload, 'deliveredKey')
            units = [] if single is None else [single]
        product = _first(row, 'service_id', 'product_id')
        if product is None and type(row.get('product')) is dict:
            product = row['product'].get('id')
        if product is None:
            product = payload.get('product_id')
        external = row.get('external_order_id')
    if type(units) is not list or len(units) > MAX_QUANTITY:
        _fail()
    units = tuple(_text(unit, 16_000) for unit in units)
    count = quantity if quantity is not None else expected_quantity
    mismatch = ((expected_id is not None and identity != expected_id)
                or (expected_product is not None and product is not None and _identifier(product) != expected_product)
                or (reference is not None and external is not None and external != reference)
                or (provider in ('toolorax', 'csxstore') and expected_id is not None and expected_product is not None and product is None)
                or (expected_quantity is not None and quantity is not None and quantity != expected_quantity)
                or (count is not None and len(units) > count))
    if mismatch or identity is None:
        return OrderResult('ambiguous', identity, quantity, cost, units, 'payload_mismatch')
    if mirai and single is not None and (not units or single != units[0]):
        return OrderResult('ambiguous', identity, quantity, cost, units, 'payload_mismatch', True)
    status = _first(row, 'status', 'order_status') or _first(payload, 'status', 'order_status')
    success = status in (('fulfilled', 'completed', 'delivered', 'success') if mirai else ('delivered', 'success'))
    if not mirai:
        success = success or (status is None and (row.get('success', payload.get('success')) is True)) or (provider == 'csxstore' and status == 'completed' and row.get('success', payload.get('success')) is True) or (provider == 'toolorax' and status == 'fulfilled')
    pending = status in (('pending', 'processing', 'paid', 'queued', 'awaiting_delivery', 'in_progress') if mirai else ('pending', 'paid', 'queued', 'undelivered', 'processing'))
    if not success and not pending:
        if mirai:
            return OrderResult('ambiguous', identity, error_code='invalid_order_status', identity_trusted=True)
        return OrderResult('ambiguous', identity, quantity, cost, units, 'invalid_order_status')
    if len(set(units)) != len(units):
        return OrderResult('ambiguous', identity, quantity, cost, units, 'duplicate_delivery', True)
    if pending:
        return OrderResult('pending', identity, quantity, cost, units, identity_trusted=True)
    complete = bool(units) and (count is None or len(units) == count)
    metadata = mirai or expected_quantity is None or (quantity == expected_quantity and cost is not None)
    delivered = complete and metadata
    return OrderResult('delivered' if delivered else 'ambiguous', identity, quantity, cost, units, None if delivered else 'incomplete_delivery', True, complete)
