"""Read-only customer USDT activity, ordered like the Laravel web account page."""
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo

from ..navigation.repository import CatalogRepository


_PAGE_SIZE = 25
_JAKARTA = ZoneInfo('Asia/Jakarta')
_EPOCH = datetime(1970, 1, 1, tzinfo=timezone.utc)
_KINDS = {
    'funding_credit': ('Saldo masuk', 'Dikreditkan', '+'),
    'purchase_debit': ('Pembelian', 'Didebit', '−'),
    'refund_credit': ('Pengembalian', 'Dikembalikan', '+'),
    'reversal_debit': ('Pembalikan', 'Dibalik', '−'),
}


def _micros(value):
    instant = value.replace(tzinfo=_JAKARTA).astimezone(timezone.utc)
    delta = instant - _EPOCH
    return (delta.days * 86400 + delta.seconds) * 1_000_000 + delta.microseconds


def _timestamp(micros):
    return (_EPOCH + timedelta(microseconds=micros)).astimezone(_JAKARTA).replace(tzinfo=None)


def _cursor(row):
    return (_micros(row['created_at']), row['rank'], row['item_id'])


def _amount(micro):
    whole, fraction = divmod(int(micro), 1_000_000)
    decimal = f'{fraction:06d}'.rstrip('0')
    return f'{whole}.{decimal}' if decimal else str(whole)


def _entry(row):
    if row['rank'] == 1:
        kind, state, prefix = 'Top-up', 'Menunggu', 'Nominal '
        reference = row['reference']
    else:
        kind, state, prefix = _KINDS[row['kind']]
        reference = row['reference']
    return {
        'kind': kind,
        'state': state,
        'amount_display': f"{prefix}{_amount(row['amount_micro'])} USDT",
        'reference': reference,
        'occurred_at': row['created_at'].strftime('%d %b %Y %H:%M'),
    }


def read_page(customer_id, *, direction=None, cursor=None):
    if direction not in (None, 'older', 'newer') or (direction is None) != (cursor is None):
        return None
    repo = CatalogRepository()
    timestamp = None
    if cursor is not None:
        if (not isinstance(cursor, tuple) or len(cursor) != 3
                or any(type(value) is not int for value in cursor)
                or cursor[0] < 1 or cursor[1] not in (1, 2)
                or not 1 <= cursor[2] <= 9223372036854775807):
            return None
        try:
            timestamp = _timestamp(cursor[0])
        except (OverflowError, ValueError):
            return None
        if cursor[1] == 1:
            exists = repo._one('''SELECT 1 AS present FROM public.customer_usdt_top_ups
                WHERE customer_id=%s AND intent_id=%s AND created_at=%s''',
                (customer_id, cursor[2], timestamp))
        else:
            exists = repo._one('''SELECT 1 AS present FROM public.customer_usdt_balance_entries e
                JOIN public.customer_usdt_balance_accounts a ON a.id=e.customer_usdt_balance_account_id
                WHERE a.customer_id=%s AND e.id=%s AND e.created_at=%s''',
                (customer_id, cursor[2], timestamp))
        if not exists:
            return None

    newer = direction == 'newer'
    order = 'ASC' if newer else 'DESC'
    operator = '>' if newer else '<'
    target_predicate = entry_predicate = ''
    target_params = [customer_id]
    entry_params = [customer_id]
    if cursor is not None:
        target_predicate = f' AND (t.created_at,1,t.intent_id) {operator} (%s,%s,%s)'
        entry_predicate = f' AND (e.created_at,2,e.id) {operator} (%s,%s,%s)'
        target_params.extend((timestamp, cursor[1], cursor[2]))
        entry_params.extend((timestamp, cursor[1], cursor[2]))
    targets = repo._rows('''SELECT t.created_at,1 AS rank,t.intent_id AS item_id,
            t.amount_micro,'top_up' AS kind,t.reference
        FROM public.customer_usdt_top_ups t
        WHERE t.customer_id=%s AND NOT EXISTS
            (SELECT 1 FROM public.customer_usdt_top_up_resolutions r WHERE r.top_up_id=t.id)'''
        + target_predicate + f' ORDER BY t.created_at {order},t.intent_id {order} LIMIT 26', target_params)
    entries = repo._rows('''SELECT e.created_at,2 AS rank,e.id AS item_id,e.amount_micro,e.kind,
            CASE WHEN e.source_type='customer_usdt_top_up' AND e.source_id<>'' THEN e.source_id
                 WHEN o.id IS NOT NULL THEN '#' || o.invoice_number
                 ELSE '#' || e.id::text END AS reference
        FROM (SELECT e.id,e.created_at,e.amount_micro,e.kind,e.source_type,e.source_id,
                     e.refund_order_id,a.customer_id
              FROM public.customer_usdt_balance_entries e
              JOIN public.customer_usdt_balance_accounts a ON a.id=e.customer_usdt_balance_account_id
              WHERE a.customer_id=%s''' + entry_predicate
        + f' ORDER BY e.created_at {order},e.id {order} LIMIT 26) e '
        + '''LEFT JOIN public.orders o ON o.customer_id=e.customer_id AND
            ((e.source_type='order' AND o.id::text=e.source_id AND e.source_id<>'') OR
             (e.source_type='order_refund' AND o.id=e.refund_order_id))''', entry_params)
    items = sorted(targets + entries, key=_cursor, reverse=not newer)
    more = len(items) > _PAGE_SIZE
    visible = items[:_PAGE_SIZE]
    if newer:
        visible.reverse()
    if not visible:
        return {'entries': [], 'older': cursor if newer else None,
                'newer': cursor if direction == 'older' else None}
    return {
        'entries': [_entry(row) for row in visible],
        'older': _cursor(visible[-1]) if (newer or more) else None,
        'newer': _cursor(visible[0]) if (more if newer else direction == 'older') else None,
    }
