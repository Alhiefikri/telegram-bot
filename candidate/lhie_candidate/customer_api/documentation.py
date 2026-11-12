"""Public documentation generated from the Python Customer API contract."""

import json

VERSION = '1.0.0'
API_PREFIX = '/api/v1'

ERRORS = {
    'invalid_input': (422, 'A field, value, body, or query parameter is malformed or outside its bounds.',
                      'Field, nilai, body, atau parameter query tidak sesuai format atau batas.'),
    'unauthenticated': (401, 'A valid Customer API bearer key is required.',
                        'Key bearer Customer API yang valid wajib digunakan.'),
    'backend_only': (403, 'Browser Origin requests are not accepted.',
                     'Permintaan browser dengan header Origin tidak diterima.'),
    'not_found': (404, 'The Product or Customer-owned API order was not found.',
                  'Product atau pesanan API milik akun ini tidak ditemukan.'),
    'method_not_allowed': (405, 'The HTTP method is not supported for this resource.',
                           'Metode HTTP tidak didukung oleh resource ini.'),
    'insufficient_funds': (402, 'No eligible balance wallet covers the order.',
                           'Tidak ada dompet saldo yang dapat membayar pesanan ini.'),
    'idempotency_conflict': (409, 'The Idempotency-Key was previously used with another request body.',
                             'Idempotency-Key sudah dipakai dengan body permintaan yang berbeda.'),
    'price_changed': (409, 'The authorized total changed; fetch a current quote and authorize it.',
                      'Total yang Anda setujui berubah; ambil kutipan terbaru sebelum membuat pesanan.'),
    'stock_conflict': (409, 'The requested quantity is no longer available.',
                       'Kuantitas yang diminta tidak lagi tersedia.'),
    'in_progress': (409, 'An identical request is still being processed; retry the same key and body.',
                    'Permintaan identik masih diproses; ulangi dengan key dan body yang sama.'),
    'payload_too_large': (413, 'The request body exceeds 32 KiB.',
                          'Body permintaan melebihi 32 KiB.'),
    'rate_limited': (429, 'An admission budget is exhausted; use Retry-After before retrying.',
                     'Batas admission habis; tunggu sesuai Retry-After sebelum mencoba lagi.'),
    'api_maintenance': (503, 'The Customer API is temporarily disabled by its owner.',
                        'Pemilik toko sedang menonaktifkan Customer API.'),
    'temporarily_unavailable': (503, 'Admission, checkout, or delivery is temporarily unavailable.',
                                'Admission, checkout, atau pengiriman sementara tidak tersedia.'),
    'currency_unavailable': (422, 'The Product has no price in the requested currency.',
                             'Product tidak memiliki harga dalam mata uang yang diminta.'),
    'quote_unavailable': (422, 'A safe quote cannot be produced for this Product and quantity.',
                          'Kutipan aman tidak dapat dibuat untuk Product dan kuantitas ini.'),
}

_ERROR_SCHEMA = {
    'type': 'object', 'required': ['error'], 'additionalProperties': False,
    'properties': {'error': {'type': 'object', 'required': ['code', 'message', 'request_id'],
        'properties': {'code': {'type': 'string', 'enum': list(ERRORS)}, 'message': {'type': 'string'},
                       'request_id': {'type': 'string', 'format': 'uuid'},
                       'quotes': {'type': 'array', 'items': {'type': 'object'}}}}},
}

SCHEMAS = {
    'PaymentPreference': {'type': 'string', 'enum': ['idr_only', 'usd_only', 'idr_first', 'usd_first']},
    'Wallet': {'type': 'object', 'required': ['currency', 'available', 'frozen', 'amount'],
        'properties': {'currency': {'type': 'string', 'enum': ['IDR', 'USD']}, 'available': {'type': 'boolean'},
                       'frozen': {'type': 'boolean'}, 'amount': {'type': ['string', 'null']}}},
    'Balance': {'type': 'object', 'required': ['payment_preference', 'wallets'], 'properties': {
        'payment_preference': {'$ref': '#/components/schemas/PaymentPreference'},
        'wallets': {'type': 'array', 'items': {'$ref': '#/components/schemas/Wallet'}}}},
    'Quote': {'type': 'object', 'required': ['currency', 'eligible', 'regular_unit', 'effective_unit', 'regular_total', 'total'],
        'properties': {'currency': {'type': 'string', 'enum': ['IDR', 'USD']}, 'eligible': {'type': 'boolean'},
            'regular_unit': {'type': ['string', 'null']}, 'effective_unit': {'type': ['string', 'null']},
            'regular_total': {'type': ['string', 'null']}, 'total': {'type': ['string', 'null']}}},
    'Category': {'type': 'object', 'required': ['id', 'name', 'slug', 'description'], 'properties': {
        'id': {'type': 'string'}, 'name': {'type': 'string'}, 'slug': {'type': 'string'},
        'description': {'type': ['string', 'null']}}},
    'Family': {'type': 'object', 'required': ['id', 'name', 'slug', 'description', 'icon', 'custom_emoji_id'], 'properties': {
        'id': {'type': 'string'}, 'name': {'type': 'string'}, 'slug': {'type': 'string'},
        'description': {'type': ['string', 'null']}, 'icon': {'type': ['string', 'null']},
        'custom_emoji_id': {'type': ['string', 'null']}}},
    'ProductMetadata': {'type': 'object', 'properties': {key: {'type': ['string', 'null']} for key in (
        'product_type', 'access_duration', 'region', 'account_type', 'device_limit', 'included_items',
        'limitations', 'fulfillment_expectation', 'warranty_policy', 'warranty_policy_ms',
        'warranty_policy_en', 'support_policy', 'delivery_note', 'usage_guide_title', 'usage_guide_url')}},
    'Product': {'type': 'object', 'required': ['id', 'name', 'plan_label', 'description', 'description_ms', 'description_en',
        'icon', 'custom_emoji_id', 'category', 'family', 'stock', 'quantity', 'quotes', 'input_requirements', 'metadata', 'informational'],
        'properties': {'id': {'type': 'string', 'pattern': '^[1-9][0-9]*$'}, 'name': {'type': 'string'},
            'plan_label': {'type': ['string', 'null']}, 'description': {'type': ['string', 'null']},
            'description_ms': {'type': ['string', 'null']}, 'description_en': {'type': ['string', 'null']},
            'icon': {'type': ['string', 'null']}, 'custom_emoji_id': {'type': ['string', 'null']},
            'category': {'oneOf': [{'$ref': '#/components/schemas/Category'}, {'type': 'null'}]},
            'family': {'oneOf': [{'$ref': '#/components/schemas/Family'}, {'type': 'null'}]},
            'stock': {'type': 'object', 'required': ['available', 'is_available', 'quantity_available'],
                'properties': {'available': {'type': 'integer', 'minimum': 0}, 'is_available': {'type': 'boolean'}, 'quantity_available': {'type': 'boolean'}}},
            'quantity': {'type': 'integer', 'minimum': 1, 'maximum': 100},
            'quotes': {'type': 'array', 'items': {'$ref': '#/components/schemas/Quote'}},
            'input_requirements': {'type': 'array', 'maxItems': 0}, 'metadata': {'$ref': '#/components/schemas/ProductMetadata'},
            'informational': {'type': 'boolean', 'const': True}}},
    'Pagination': {'type': 'object', 'required': ['next_cursor', 'has_more'], 'properties': {
        'next_cursor': {'type': ['string', 'null']}, 'has_more': {'type': 'boolean'}}},
    'OrderProduct': {'type': 'object', 'required': ['id', 'name'], 'properties': {
        'id': {'type': 'string', 'pattern': '^[1-9][0-9]*$'}, 'name': {'type': 'string'}}},
    'OrderItem': {'type': 'object', 'required': ['product_id', 'name', 'quantity'], 'properties': {
        'product_id': {'type': 'string', 'pattern': '^[1-9][0-9]*$'}, 'name': {'type': 'string'},
        'quantity': {'type': 'integer', 'minimum': 1}}},
    'FulfillmentProgress': {'type': 'object', 'required': ['state', 'ordered_units', 'allocated_units', 'released_units', 'processing', 'review_required'],
        'properties': {'state': {'type': 'string'}, 'ordered_units': {'type': 'integer'}, 'allocated_units': {'type': 'integer'},
            'released_units': {'type': 'integer'}, 'processing': {'type': 'boolean'}, 'review_required': {'type': 'boolean'}}},
    'OrderDeliveryStatus': {'type': 'object', 'required': ['available', 'state'], 'properties': {
        'available': {'type': 'boolean'}, 'state': {'type': 'string', 'enum': ['not_ready', 'ready', 'partial']}}},
    'RefundStatus': {'type': 'object', 'required': ['state', 'currency', 'confirmed_amount', 'pending_amount'], 'properties': {
        'state': {'type': 'string', 'enum': ['none', 'pending', 'partial', 'refunded']},
        'currency': {'type': 'string', 'enum': ['IDR', 'USD']},
        'confirmed_amount': {'type': ['string', 'null']}, 'pending_amount': {'type': ['string', 'null']}}},
    'Order': {'type': 'object', 'required': ['id', 'invoice_number', 'origin', 'status', 'payment_status', 'currency',
        'amount', 'quantity', 'items', 'fulfillment', 'delivery', 'refund', 'created_at'],
        'properties': {'id': {'type': 'string', 'format': 'uuid'}, 'invoice_number': {'type': 'string'},
            'origin': {'type': 'string', 'const': 'api'}, 'status': {'type': 'string'}, 'payment_status': {'type': 'string'},
            'currency': {'type': 'string', 'enum': ['IDR', 'USD']}, 'amount': {'type': ['string', 'null'],
                'description': 'Whole rupiah or decimal USD string.'}, 'quantity': {'type': 'integer'},
            'items': {'type': 'array', 'items': {'$ref': '#/components/schemas/OrderItem'}},
            'fulfillment': {'$ref': '#/components/schemas/FulfillmentProgress'},
            'delivery': {'$ref': '#/components/schemas/OrderDeliveryStatus'},
            'refund': {'$ref': '#/components/schemas/RefundStatus'},
            'created_at': {'type': ['string', 'null'], 'format': 'date-time'}}},
    'DeliveryUnit': {'type': 'object', 'required': ['id', 'product_id', 'number', 'format', 'text', 'email', 'password',
        'access_method', 'access_value', 'reader_mail_link', 'tutorial'], 'properties': {
        'id': {'type': 'string'}, 'product_id': {'type': 'string'}, 'number': {'type': 'integer'},
        'format': {'type': 'string'}, 'text': {'type': 'string'},
        'email': {'type': ['string', 'null'], 'description': 'Purchased credential. Treat as secret.'},
        'password': {'type': ['string', 'null'], 'description': 'Purchased credential. Treat as secret.'},
        'access_method': {'type': ['string', 'null']},
        'access_value': {'type': ['string', 'null'], 'description': 'Purchased access credential. Treat as secret.'},
        'reader_mail_link': {'type': ['string', 'null'], 'description': 'Customer-specific access link. Treat as secret.'},
        'tutorial': {'type': ['string', 'null']}}},
    'UsageGuide': {'type': 'object', 'required': ['title', 'url'], 'properties': {
        'title': {'type': 'string'}, 'url': {'type': 'string', 'format': 'uri-reference'}}},
    'Delivery': {'type': 'object', 'required': ['id', 'state', 'progress', 'units', 'usage_guides', 'pagination'], 'properties': {
        'id': {'type': 'string', 'format': 'uuid'}, 'state': {'type': 'string', 'enum': ['not_ready', 'partial', 'ready']},
        'progress': {'$ref': '#/components/schemas/FulfillmentProgress'},
        'units': {'type': 'array', 'items': {'$ref': '#/components/schemas/DeliveryUnit'}},
        'usage_guides': {'type': 'array', 'items': {'$ref': '#/components/schemas/UsageGuide'}},
        'pagination': {'$ref': '#/components/schemas/Pagination'}}},
    'CreateOrderRequest': {'type': 'object', 'additionalProperties': False, 'required': ['product_id', 'quantity'], 'properties': {
        'product_id': {'type': 'string', 'pattern': '^[1-9][0-9]*$'}, 'quantity': {'type': 'integer', 'minimum': 1, 'maximum': 100},
        'preference': {'$ref': '#/components/schemas/PaymentPreference'},
        'expected_totals': {'type': 'object', 'additionalProperties': False,
            'properties': {'IDR': {'type': 'string', 'pattern': '^[1-9][0-9]*$'},
                           'USD': {'type': 'string', 'maxLength': 32, 'pattern': '^(?:0|[1-9][0-9]*)(?:\\.[0-9]{1,6})?$'}}}}},
    'OrderCreate': {'type': 'object', 'required': ['id', 'invoice_number', 'origin', 'status', 'payment_status', 'currency',
        'amount', 'quantity', 'product', 'created_at', 'request_id'], 'properties': {
        'id': {'type': 'string', 'format': 'uuid'}, 'invoice_number': {'type': 'string'},
        'origin': {'type': 'string', 'const': 'api'}, 'status': {'type': 'string'}, 'payment_status': {'type': 'string'},
        'currency': {'type': 'string', 'enum': ['IDR', 'USD']}, 'amount': {'type': ['string', 'null']},
        'quantity': {'type': 'integer'}, 'product': {'oneOf': [{'$ref': '#/components/schemas/OrderProduct'}, {'type': 'null'}]},
        'created_at': {'type': ['string', 'null'], 'format': 'date-time'}, 'request_id': {'type': 'string', 'format': 'uuid'}}},
}


def _envelope(schema):
    return {'type': 'object', 'required': ['data'], 'properties': {'data': {'$ref': f'#/components/schemas/{schema}'}}}


_LOCALIZED_SUMMARIES = {
    'getBalance': 'Baca snapshot dompet IDR dan USD milik akun ini.',
    'listProducts': 'Telusuri Product publik dengan kutipan harga informasional.',
    'getProduct': 'Baca satu Product publik dan kutipan IDR serta USD.',
    'createOrder': 'Buat atau pulihkan satu pesanan yang dibayar dari saldo.',
    'listOrders': 'Telusuri pesanan API milik akun ini.',
    'getOrder': 'Baca status satu pesanan API milik akun ini.',
    'getOrderDelivery': 'Ambil kembali unit yang sudah dilepas untuk pesanan milik akun ini.',
}


def _op(operation_id, method, path, summary, parameters, schema, statuses=(200,), body=None, errors=()):
    return {'operationId': operation_id, 'method': method, 'path': path, 'summary': summary,
            'summary_id': _LOCALIZED_SUMMARIES[operation_id],
            'parameters': parameters, 'schema': schema, 'statuses': statuses, 'body': body,
            'errors': tuple(dict.fromkeys((*errors, 'unauthenticated', 'backend_only', 'invalid_input',
                                           'method_not_allowed', 'payload_too_large', 'api_maintenance',
                                           'rate_limited', 'temporarily_unavailable')))}

OPERATIONS = (
    _op('getBalance', 'GET', f'{API_PREFIX}/balance', 'Read this account’s IDR and USD wallet snapshot.', [], 'BalanceEnvelope', errors=()),
    _op('listProducts', 'GET', f'{API_PREFIX}/products', 'List public Products and informational price quotes.', [
        {'name': 'limit', 'in': 'query', 'schema': {'type': 'integer', 'minimum': 1, 'maximum': 100, 'default': 20}},
        {'name': 'cursor', 'in': 'query', 'schema': {'type': 'string', 'pattern': '^(?:0|[1-9][0-9]*)$', 'default': '0'}},
        {'name': 'quantity', 'in': 'query', 'schema': {'type': 'integer', 'minimum': 1, 'maximum': 100, 'default': 1}},
        *[{'name': key, 'in': 'query', 'schema': {'type': 'string', 'pattern': '^[1-9][0-9]*$'}} for key in ('category_id', 'family_id')],
        {'name': 'available', 'in': 'query', 'schema': {'type': 'string', 'enum': ['true', 'false']}}], 'ProductPage'),
    _op('getProduct', 'GET', f'{API_PREFIX}/products/{{id}}', 'Read a visible Product and its IDR and USD quotes.', [
        {'name': 'id', 'in': 'path', 'required': True, 'schema': {'type': 'string', 'pattern': '^[1-9][0-9]*$'}},
        {'name': 'quantity', 'in': 'query', 'schema': {'type': 'integer', 'minimum': 1, 'maximum': 100, 'default': 1}},
        {'name': 'currency', 'in': 'query', 'schema': {'type': 'string', 'enum': ['IDR', 'USD']}}], 'ProductEnvelope', errors=('not_found', 'currency_unavailable', 'quote_unavailable')),
    _op('createOrder', 'POST', f'{API_PREFIX}/orders', 'Create or replay one balance-funded Product order.', [
        {'name': 'Idempotency-Key', 'in': 'header', 'required': True, 'schema': {'type': 'string', 'pattern': '^[\\x20-\\x7E]{1,128}$'}}],
        'OrderCreateEnvelope', statuses=(201, 200), body='CreateOrderRequest', errors=('not_found', 'insufficient_funds', 'price_changed', 'idempotency_conflict', 'stock_conflict', 'in_progress')),
    _op('listOrders', 'GET', f'{API_PREFIX}/orders', 'List this account’s API-origin orders.', [
        {'name': 'limit', 'in': 'query', 'schema': {'type': 'integer', 'minimum': 1, 'maximum': 100, 'default': 20}},
        {'name': 'cursor', 'in': 'query', 'schema': {'type': 'string', 'format': 'uuid'}},
        {'name': 'status', 'in': 'query', 'schema': {'type': 'string', 'enum': ['draft', 'pending_payment', 'processing', 'completed', 'cancelled', 'expired']}}], 'OrderPage'),
    _op('getOrder', 'GET', f'{API_PREFIX}/orders/{{id}}', 'Read one API-origin order owned by this account.', [
        {'name': 'id', 'in': 'path', 'required': True, 'schema': {'type': 'string', 'format': 'uuid'}}], 'OrderEnvelope', errors=('not_found',)),
    _op('getOrderDelivery', 'GET', f'{API_PREFIX}/orders/{{id}}/delivery', 'Recover units already released for an owned order.', [
        {'name': 'id', 'in': 'path', 'required': True, 'schema': {'type': 'string', 'format': 'uuid'}},
        {'name': 'limit', 'in': 'query', 'schema': {'type': 'integer', 'minimum': 1, 'maximum': 100, 'default': 100}},
        {'name': 'cursor', 'in': 'query', 'schema': {'type': 'string', 'pattern': '^[A-Za-z0-9_-]{1,512}$'}}], 'DeliveryEnvelope', errors=('not_found',)),
)

SCHEMAS.update({
    'BalanceEnvelope': _envelope('Balance'), 'ProductEnvelope': _envelope('Product'),
    'ProductPage': {'type': 'object', 'required': ['data', 'pagination'], 'properties': {
        'data': {'type': 'array', 'items': {'$ref': '#/components/schemas/Product'}},
        'pagination': {'$ref': '#/components/schemas/Pagination'}}},
    'OrderEnvelope': _envelope('Order'), 'OrderPage': {'type': 'object', 'required': ['data', 'pagination'], 'properties': {
        'data': {'type': 'array', 'items': {'$ref': '#/components/schemas/Order'}},
        'pagination': {'$ref': '#/components/schemas/Pagination'}}},
    'OrderCreateEnvelope': {'type': 'object', 'required': ['data', 'idempotent_replay'], 'properties': {
        'data': {'$ref': '#/components/schemas/OrderCreate'}, 'idempotent_replay': {'type': 'boolean'}}},
    'DeliveryEnvelope': _envelope('Delivery'), 'ErrorEnvelope': _ERROR_SCHEMA,
})


def openapi_document(origin):
    paths = {}
    for operation in OPERATIONS:
        path = operation['path'].replace(API_PREFIX, '')
        parameters = [dict(parameter) for parameter in operation['parameters']]
        tags = ('Balance',) if operation['operationId'] == 'getBalance' else (
            'Catalog',) if operation['operationId'] in ('listProducts', 'getProduct') else (
            'Delivery',) if operation['operationId'] == 'getOrderDelivery' else ('Orders',)
        request = {'operationId': operation['operationId'], 'summary': operation['summary'],
                   'tags': list(tags), 'parameters': parameters, 'responses': {}}
        if operation['body']:
            request['requestBody'] = {'required': True, 'content': {'application/json': {
                'schema': {'$ref': '#/components/schemas/' + operation['body']},
                'examples': {'synthetic': {'summary': 'Synthetic example, not Customer data', 'value': {
                    'product_id': '123', 'quantity': 1, 'preference': 'idr_first',
                    'expected_totals': {'IDR': '25000'}}}}}}}
        response_schema = operation['schema']
        for status in operation['statuses']:
            request['responses'][str(status)] = {'description': 'Successful response', 'content': {'application/json': {'schema': {'$ref': f'#/components/schemas/{response_schema}'}}}}
        for code in operation['errors']:
            status = ERRORS[code][0]
            request['responses'].setdefault(str(status), {'description': code, 'content': {'application/json': {'schema': {'$ref': '#/components/schemas/ErrorEnvelope'}}}})
        paths.setdefault(path, {})[operation['method'].lower()] = request
    return {'openapi': '3.1.0', 'info': {'title': 'Lhie Store Supplier API', 'version': VERSION,
            'description': 'Customer-owned, server-to-server API. Product quotes are informational; order creation revalidates price and stock.'},
        'servers': [{'url': origin.rstrip('/') + API_PREFIX}], 'paths': paths, 'components': {'securitySchemes': {'bearerAuth': {
            'type': 'http', 'scheme': 'bearer', 'description': 'Customer API key issued through the private Store Bot.'}},
            'schemas': SCHEMAS}, 'security': [{'bearerAuth': []}]}


def openapi_json(origin):
    return json.dumps(openapi_document(origin), ensure_ascii=False, indent=2) + '\n'


def reference_markdown(origin):
    rows = [
        '# Lhie Store Supplier API', '',
        'Customer-owned server-to-server API. All API calls require `Authorization: Bearer <secret>` and reject browser `Origin` requests. Never send a key from browser code.',
        '', f'Base URL: `{origin}{API_PREFIX}`', '',
        'Quotes are informational. Order creation recomputes price and checks stock before charging one balance wallet. Product input requirements are always empty. The API accepts one Product and quantity per order.',
        'The delivery endpoint returns credentials from an authorized purchase. Treat those responses as secret and do not log their contents.',
        '', '## Operations', '',
    ]
    for op in OPERATIONS:
        rows.extend(['### `' + op['method'] + ' ' + op['path'] + '`', '', op['summary'], '',
                     'Success response schema: `' + op['schema'] + '`.'])
        if op['body']:
            rows.extend(['', 'Synthetic request example, with no Customer data:', '', '```json',
                         json.dumps({'product_id': '123', 'quantity': 1, 'preference': 'idr_first',
                                     'expected_totals': {'IDR': '25000'}}, indent=2), '```'])
            rows.append('Request JSON schema: `' + op['body'] + '`. Maximum body: 32 KiB. Unknown fields are rejected.')
        if op['parameters']:
            rows.extend(['', '| Parameter | In | Contract |', '| --- | --- | --- |'])
            for p in op['parameters']:
                rows.append(f"| `{p['name']}` | {p['in']} | `{json.dumps(p['schema'], ensure_ascii=False)}` |")
        rows.extend(['', 'Errors: ' + ', '.join(f"`{code}` ({ERRORS[code][0]})" for code in op['errors']) + '.', ''])
    rows.extend(['## Error envelope', '', 'Errors use `{\"error\":{\"code\":\"...\",\"message\":\"...\",\"request_id\":\"<uuid>\"}}`. Responses include `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, and `X-Request-ID`.', '', '## Limits', '', '- Request body: 32 KiB.', '- Read/list page size: 1 to 100; products and orders default to 20; delivery defaults to 100.', '- Delivery JSON response payload: at most 1,047,552 bytes; results that cannot fit safely return `temporarily_unavailable`.', '- Order quantity: 1 to 100.', '- Idempotency-Key: 1 to 128 printable ASCII characters. Reuse the same key and body when retrying.', '- Per account: 120 reads per 60 seconds, 10 order attempts per 60 seconds, and 10 delivery reads per 60 seconds. Per IP: 600 total requests and 30 missing/invalid authentication attempts per 60 seconds. Global: 300 reads per second and 20 orders per second. At most 2 concurrent order permits per account.', '', '## Schemas', ''])
    for name, schema in SCHEMAS.items():
        rows.extend([f'### `{name}`', '', '```json', json.dumps(schema, ensure_ascii=False, indent=2), '```', ''])
    return '\n'.join(rows)
