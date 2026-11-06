"""Owned, bounded reads of the existing paid credential allocation."""
import json
import re

from django.db import transaction

from lhie_candidate.checkout.db import Database
from lhie_candidate.receipt.render import _legacy
from lhie_candidate.receipt.source import ReceiptSource, ReceiptUnavailable, _account
from .admission import ApiFailure
from .reads import owned_order, order_summaries


_LIMIT = 1_047_552


def _invalid():
    return ApiFailure(422, 'invalid_input', 'The request contains unsupported or malformed inputs.')


def _unavailable():
    return ApiFailure(503, 'temporarily_unavailable', 'Delivery is temporarily unavailable.')


def _decode(cursor, cipher):
    if not isinstance(cursor, str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,512}', cursor):
        raise _invalid()
    try:
        envelope = cursor.translate(str.maketrans('-_', '+/')) + '=' * (-len(cursor) % 4)
        value = cipher.decrypt_string(envelope)
    except (ValueError, UnicodeError):
        raise _invalid() from None
    if not isinstance(value, str) or not re.fullmatch(r'[1-9][0-9]{0,17}', value):
        raise _invalid()
    return int(value)


def _encode(unit_id, cipher):
    return cipher.encrypt_string(str(unit_id)).translate(str.maketrans('+/', '-_')).rstrip('=')


def _json_size(value):
    return len(json.dumps(value, ensure_ascii=True, separators=(',', ':'), allow_nan=False).encode('utf-8'))


def _unit(row, cipher, number, tutorial):
    account = _account(row, cipher, row['unit_number'] or number, tutorial)
    if account['format'] == 'legacy':
        text, parts, _ = _legacy(account['secret'])
        email, password = (parts[0], parts[1]) if parts else (None, None)
        method = access = reader = None
    else:
        email, password = account['email'], account['password']
        method, access, reader = account['access_method'], account['access_value'], account['reader_mail_link']
        text = 'email : ' + email + '\npassword : ' + password
        if method == 'auth_token' and access is not None:
            text += '\nAuth Token : ' + access
    return {'id': str(row['id']), 'product_id': str(row['product_id']), 'number': account['number'],
            'format': account['format'], 'text': text, 'email': email, 'password': password,
            'access_method': method, 'access_value': access, 'reader_mail_link': reader,
            'tutorial': account['tutorial']}


def delivery(principal, order_id, cipher, *, limit=100, cursor=None, using='default', base_url=''):
    if type(limit) is not int or not 1 <= limit <= 100:
        raise _invalid()
    db = Database(using)
    source = ReceiptSource(cipher, using=using, invoice_base_url=base_url)
    with transaction.atomic(using=using):
        order = owned_order(principal, order_id, using=using, lock=True)
        cursor_id = _decode(cursor, cipher) if cursor is not None else None
        summary = order_summaries([order], using=using)[0]
        result = {'id': str(order_id), 'state': 'not_ready', 'progress': summary['fulfillment'],
                  'units': [], 'usage_guides': [], 'pagination': {'next_cursor': None, 'has_more': False}}
        items = db.rows('''SELECT i.id,i.product_id,i.quantity,i.delivery_note,i.delivery_note_snapshotted,
            i.usage_guide_title,i.usage_guide_url,i.usage_guide_snapshotted,
            p.delivery_note AS product_delivery_note,p.usage_guide_title AS product_guide_title,
            p.usage_guide_url AS product_guide_url FROM public.order_items i
            LEFT JOIN public.products p ON p.id=i.product_id WHERE i.order_id=%s ORDER BY i.id''', (order_id,))
        item = items[0] if len(items) == 1 else None
        if cursor_id is not None and (not item or not db.one(
                'SELECT id FROM public.product_stock_items WHERE id=%s AND order_item_id=%s', (cursor_id, item['id']))):
            raise _invalid()
        if not summary['delivery']['available']:
            return {'data': result}
        if not item or not 1 <= summary['quantity'] <= 100:
            raise _unavailable()
        try:
            authorized, payment = source.authorize_customer(order_id, principal.customer_id)
        except ReceiptUnavailable:
            return {'data': result}
        allocated = db.rows('''SELECT s.id,s.order_item_id,s.product_id,s.retired_at FROM public.product_stock_items s
            WHERE s.order_item_id=%s ORDER BY s.id''', (item['id'],))
        try:
            active = source.validate_allocation(authorized, payment, items, allocated)
        except ReceiptUnavailable:
            raise _unavailable() from None
        released = summary['fulfillment']['released_units']
        if released > summary['quantity']:
            raise _unavailable()
        result['state'] = 'ready' if released == summary['quantity'] else 'partial'
        tutorial = item['delivery_note'] if item['delivery_note_snapshotted'] else item['product_delivery_note']
        tutorial = tutorial.strip() if isinstance(tutorial, str) and tutorial.strip() else None
        title = item['usage_guide_title'] if item['usage_guide_snapshotted'] else item['product_guide_title']
        url = item['usage_guide_url'] if item['usage_guide_snapshotted'] else item['product_guide_url']
        if title and url and title.strip() and url.strip():
            url = url.strip()
            result['usage_guides'] = [{'title': title.strip(), 'url': base_url.rstrip('/') + url if url.startswith('/') else url}]
        after = cursor_id or 0
        offset = sum(row['id'] <= after for row in active)
        # Fetch only the requested chunk; the look-ahead contains an id, not a secret.
        selected = [row for row in active if row['id'] > after][:limit + 1]
        ids = [row['id'] for row in selected[:limit]]
        if not ids:
            return {'data': result}
        rows = db.rows('''SELECT s.id,s.product_id,s.credential_format,s.secret,s.credential_payload,u.unit_number
            FROM public.product_stock_items s LEFT JOIN public.supplier_account_units u ON u.product_stock_item_id=s.id
            WHERE s.id = ANY(%s) ORDER BY s.id''', (ids,))
        if len(rows) != len(ids):
            raise _unavailable()
        size = _json_size(result)
        try:
            for index, row in enumerate(rows):
                unit = _unit(row, cipher, offset + index + 1, tutorial)
                extra = _json_size(unit) + (1 if result['units'] else 0)
                if size + extra > _LIMIT:
                    if not result['units']:
                        raise _unavailable()
                    break
                result['units'].append(unit)
                size += extra
        except ApiFailure:
            raise
        except Exception:
            raise _unavailable() from None
        if len(result['units']) < len(selected):
            result['pagination'] = {'next_cursor': _encode(result['units'][-1]['id'], cipher), 'has_more': True}
        return {'data': result}
