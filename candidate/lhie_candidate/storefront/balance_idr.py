"""Read the customer-facing IDR activity page without changing ledger state."""
from datetime import datetime, timedelta, timezone
import json
from types import SimpleNamespace
from zoneinfo import ZoneInfo

from django.conf import settings

from ..checkout.db import Database


_ZONE = ZoneInfo('Asia/Jakarta')
_EPOCH = datetime(1970, 1, 1, tzinfo=timezone.utc)
_LIMIT = 25
_KINDS = {
    'admin_adjustment_credit': ('Penyesuaian', 'Dikreditkan', '+'),
    'admin_adjustment_reversal': ('Pembalikan penyesuaian', 'Didebit', '−'),
    'top_up_credit': ('Top-up', 'Dikreditkan', '+'),
    'owner_compensation_credit': ('Kompensasi', 'Dikompensasi', '+'),
    'purchase_debit': ('Pembelian', 'Didebit', '−'),
    'refund_credit': ('Pengembalian', 'Dikembalikan', '+'),
}
_STATES = {'credited': 'Dikreditkan', 'recovered': 'Dipulihkan',
           'compensated': 'Dikompensasi', 'pending': 'Menunggu',
           'uncertain': 'Perlu pemeriksaan', 'expired': 'Kedaluwarsa',
           'review': 'Sedang ditinjau', 'failed': 'Gagal'}


def _local(value):
    return value.replace(tzinfo=_ZONE) if value.tzinfo is None else value.astimezone(_ZONE)


def _micros(value):
    delta = _local(value).astimezone(timezone.utc) - _EPOCH
    return (delta.days * 86400 + delta.seconds) * 1000000 + delta.microseconds


def _cursor(row):
    return (_micros(row['created_at']), row['rank'], row['id'])


def _amount(amount):
    return 'Rp ' + f'{amount:,}'.replace(',', '.')


def _klik_quote_valid(row, owner):
    """Validate a persisted quote locally, using only an explicitly admitted rail."""
    from ..payments.klikqris import KlikQrisTransaction, deadline, valid_qr_url

    try:
        rail = owner.lifecycles['klikqris']
        config = rail.adapter.config
        if config.environment not in ('sandbox', 'production') or owner.encrypter is None:
            return False
        if (str(row['currency']).strip().upper() != 'IDR'
                or str(row['provider_payment_method']).strip().upper() != 'QRIS'
                or type(row['amount']) is not int or row['amount'] <= 0
                or type(row['provider_fee']) is not int or row['provider_fee'] < 0
                or type(row['provider_total_payment']) is not int
                or row['provider_total_payment'] < row['amount']
                or row['expires_at'] is None or row['provider_expires_at'] is None):
            return False
        url = owner.encrypter.decrypt_string(row['provider_qris_url'])
        if not valid_qr_url(url, config.environment, config.allowed_hosts):
            return False
        payload = row['provider_payload']
        if isinstance(payload, str):
            payload = json.loads(payload)
        if not isinstance(payload, dict):
            return False
        if payload.get('environment', config.environment) != config.environment:
            return False
        if set(payload) == {'environment'}:
            from ..funding.klikqris import KlikFunding

            if row['provider_total_payment'] - row['amount'] != row['provider_fee']:
                return False
            snapshot = SimpleNamespace(one=lambda sql, params: {'provider_payload': payload})
            funding = SimpleNamespace(db=snapshot, using='default',
                                      clock=lambda: datetime.now(_ZONE).replace(tzinfo=None))
            presentation = KlikFunding(funding, rail.adapter, owner.encrypter).presentation(row)
            return presentation['payable']
        data = payload.get('data', payload)
        if not isinstance(data, dict):
            return False
        data = dict(data)
        if data.get('qris_url') not in (None, '', url):
            return False
        data['qris_url'] = url
        if not any(data.get(key) is not None for key in ('fee', 'provider_fee', 'fee_amount', 'qris_fee')):
            data['provider_fee'] = row['provider_fee']
        if data.get('expired_at') is None:
            data['expired_at'] = row['provider_expires_at'].isoformat()
        quote = KlikQrisTransaction.parse(data, environment=config.environment)
        quote.assert_matches(dict(row, method='QRIS'), config.environment)
        return (quote.status == 'PENDING' and quote.expiry is not None
                and quote.expiry == deadline(row['provider_expires_at'])
                and quote.total - quote.amount == row['provider_fee'])
    except (AttributeError, KeyError, TypeError, ValueError, OverflowError):
        return False


def _topup_state(row, credit):
    status, provider = row['status'], row['provider']
    state = (row['provider_create_state'] or '').strip()
    matched = credit is not None and credit['amount'] == row['amount'] and credit['amount'] > 0
    if status == 'compensated':
        return 'compensated', matched
    if status == 'paid':
        if not matched:
            return 'review', False
        recovered = ((provider == 'manual' and state in ('manual_confirmed', 'reconciled_manual_confirmed'))
                     or (provider == 'sakurupiah' and state in ('provider_berhasil', 'reconciled_provider_berhasil'))
                     or (provider == 'klikqris' and state == 'reconciled_SUCCESS'))
        return ('recovered' if recovered else 'credited'), True
    if status == 'invalid_provider_result' or (provider == 'klikqris' and status != 'expired' and state == 'reconciliation_review'):
        return 'review', matched
    if provider == 'klikqris' and state == 'provider_unavailable' and status != 'expired':
        return ('failed' if status == 'failed' else 'pending'), matched
    if provider != 'manual':
        now = datetime.now(_ZONE)
        if status == 'expired' or any(row[k] is not None and _local(row[k]) <= now for k in ('expires_at', 'provider_expires_at')):
            return 'expired', matched
    elif status == 'expired':
        return 'expired', matched
    if status == 'failed' and state in ('create_failed', 'provider_unavailable'):
        return 'failed', matched
    if provider == 'manual':
        return ('pending' if status == 'awaiting_confirmation' else 'review'), matched
    if state in ('creating', 'outcome_unknown') or (provider == 'klikqris' and state == 'recovery_unconfirmed'):
        return 'uncertain', matched
    if state in ('provider_expired', 'provider_EXPIRED'):
        return 'expired', matched
    if provider == 'sakurupiah':
        return ('review' if state == 'provider_qris_unusable' else 'pending'), matched
    if state in ('provider_total_pending', 'provider_fee_missing'):
        return 'pending', matched
    if status == 'payable':
        if state != 'created' or not row.get('quote_valid', False):
            return 'review', matched
        return 'pending', matched
    if state in ('', 'create_failed', 'created', 'provider_unavailable', 'provider_total_pending', 'provider_fee_missing'):
        return 'pending', matched
    return 'review', matched


def read_page(customer_id, *, direction=None, cursor=None):
    """Return a 25-row page; cursors are (UTC epoch microseconds, source rank, id)."""
    if (direction is None) != (cursor is None) or direction not in (None, 'older', 'newer'):
        return None
    db = Database()
    account = db.one('SELECT id FROM public.customer_balance_accounts WHERE customer_id=%s', (customer_id,))
    account_id = account['id'] if account else None
    timestamp = None
    if cursor is not None:
        if (not isinstance(cursor, tuple) or len(cursor) != 3
                or any(type(x) is not int for x in cursor)
                or cursor[0] < 1 or cursor[1] not in (1, 2) or not 0 < cursor[2] <= 9223372036854775807):
            return None
        try:
            timestamp = (_EPOCH + timedelta(microseconds=cursor[0])).astimezone(_ZONE).replace(tzinfo=None)
        except (OverflowError, ValueError):
            return None
        if cursor[1] == 1:
            anchor = db.one('SELECT created_at FROM public.store_balance_top_ups WHERE customer_id=%s AND id=%s', (customer_id, cursor[2]))
        else:
            anchor = db.one('SELECT created_at FROM public.store_balance_entries WHERE customer_balance_account_id=%s AND id=%s', (account_id, cursor[2])) if account_id else None
        if anchor is None or _micros(anchor['created_at']) != cursor[0]:
            return None
    newer = direction == 'newer'
    ordering = 'ASC' if newer else 'DESC'
    op = '>' if newer else '<'

    def boundary(rank):
        if cursor is None:
            return '', []
        return f' AND (created_at, {rank}, id) {op} (%s, %s, %s)', [timestamp, cursor[1], cursor[2]]

    condition, params = boundary(1)
    topups = db.rows('''SELECT id,created_at,amount,status,provider,provider_create_state,
                        expires_at,provider_expires_at, 1 AS rank
                        FROM public.store_balance_top_ups WHERE customer_id=%s''' + condition +
                     f' ORDER BY created_at {ordering}, id {ordering} LIMIT 26', [customer_id, *params])
    entries = []
    if account_id is not None:
        condition, params = boundary(2)
        entries = db.rows('''SELECT id,created_at,kind,amount,source_type,source_id,refund_order_id, 2 AS rank
            FROM public.store_balance_entries WHERE customer_balance_account_id=%s
              AND (source_type NOT IN ('balance_top_up','owner_compensation') OR
                   (source_type='balance_top_up' AND NOT EXISTS
                    (SELECT 1 FROM public.store_balance_top_ups t WHERE t.customer_id=%s
                     AND CAST(t.id AS text)=store_balance_entries.source_id)))''' + condition +
            f' ORDER BY created_at {ordering}, id {ordering} LIMIT 26', [account_id, customer_id, *params])
    items = sorted(topups + entries, key=_cursor, reverse=True)
    more = len(items) > _LIMIT
    visible = items[-_LIMIT:] if newer else items[:_LIMIT]
    if not visible:
        return {'entries': [], 'older': cursor if newer else None,
                'newer': cursor if direction == 'older' else None}

    quote_ids = [row['id'] for row in visible if row['rank'] == 1
                 and row['provider'] == 'klikqris' and row['status'] == 'payable'
                 and (row['provider_create_state'] or '').strip() == 'created']
    factory = getattr(settings, 'CANDIDATE_STOREFRONT_PAYMENT_SERVICES', None)
    if quote_ids and callable(factory):
        owner = factory()
        quotes = db.rows('''SELECT id,currency,amount,provider,provider_payment_method,merchant_ref,
            provider_reference,provider_fee,provider_total_payment,provider_payload,provider_qris_url,
            expires_at,provider_expires_at,status,provider_create_state FROM public.store_balance_top_ups
            WHERE customer_id=%s AND id=ANY(%s)''', (customer_id, quote_ids))
        validity = {row['id']: _klik_quote_valid(row, owner) for row in quotes}
        for row in visible:
            if row['rank'] == 1 and row['id'] in validity:
                row['quote_valid'] = validity[row['id']]
    ids = [str(row['id']) for row in visible if row['rank'] == 1]
    credits = {}
    if account_id is not None and ids:
        for credit in db.rows('''SELECT kind,amount,source_type,source_id FROM public.store_balance_entries
            WHERE customer_balance_account_id=%s AND kind IN ('top_up_credit','owner_compensation_credit')
              AND ((source_type='balance_top_up' AND source_id=ANY(%s)) OR
                   (source_type='owner_compensation' AND source_id=ANY(%s))) ORDER BY id''',
                              (account_id, ids, ['top_up:' + x for x in ids])):
            key = credit['source_id'].removeprefix('top_up:') if credit['source_type'] == 'owner_compensation' else credit['source_id']
            credits[key] = credit
    order_ids = [str(row['source_id'] if row['source_type'] == 'order' else row['refund_order_id'])
                 for row in visible if row['rank'] == 2 and
                 ((row['source_type'] == 'order' and row['source_id']) or
                  (row['source_type'] == 'order_refund' and row['kind'] == 'refund_credit' and row['refund_order_id']))]
    orders = {}
    if order_ids:
        orders = {str(row['id']): row['invoice_number'] for row in db.rows(
            'SELECT id,invoice_number FROM public.orders WHERE customer_id=%s AND id::text=ANY(%s)', (customer_id, order_ids))}
    result = []
    for row in visible:
        amount = row['amount']
        reference = '#' + str(row['id'])
        if row['rank'] == 1:
            state, matched = _topup_state(row, credits.get(str(row['id'])))
            kind = 'Kompensasi' if row['status'] == 'compensated' else 'Top-up'
            label = _STATES[state]
            amount_display = ('+' if matched else 'Nominal ') + _amount(amount)
        else:
            kind, label, sign = _KINDS[row['kind']]
            amount_display = sign + _amount(amount)
            order_id = row['source_id'] if row['source_type'] == 'order' else row['refund_order_id'] if row['source_type'] == 'order_refund' and row['kind'] == 'refund_credit' else None
            if order_id is not None and str(order_id) in orders:
                reference = '#' + orders[str(order_id)]
        result.append({'kind': kind, 'state': label, 'amount_display': amount_display,
                       'reference': reference, 'occurred_at': _local(row['created_at']).strftime('%d %b %Y %H:%M')})
    return {'entries': result,
            'older': _cursor(visible[-1]) if newer or more else None,
            'newer': _cursor(visible[0]) if (newer and more) or direction == 'older' else None}
