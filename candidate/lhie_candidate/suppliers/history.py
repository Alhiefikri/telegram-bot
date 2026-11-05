"""Read-only provider history evidence for uncertain supplier purchases."""
from datetime import timedelta
from zoneinfo import ZoneInfo

from django.db import connections, transaction

from ..checkout.errors import CheckoutError
from .transport import OrderHistorySnapshot, SupplierUnavailable


def _version(value):
    return value.strftime('%Y-%m-%d %H:%M:%S')


def _local(value):
    return value.astimezone(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)


def _classify(snapshot, provider, purchase, sku, requested, observed, automatic):
    rows = snapshot.orders
    if provider == 'csxstore':
        facts = []
        for row in rows:
            if str(row['product_id']) != str(sku['external_id']):
                continue
            fact = dict(row)
            fact.pop('created_at', None)
            fact['match_reasons'] = ['sku_matches',
                'quantity_matches' if row['quantity'] == purchase['quantity'] else 'quantity_differs',
                'expected_cost_matches' if row['total_micros'] == purchase['expected_cost_micros'] else 'expected_cost_differs']
            fact['limitations'] = ['identity_not_proven', 'timezone_unknown', 'first_page_only', 'exact_get_required']
            facts.append(fact)
        error = 'history_limit_reached' if snapshot.coverage == 'truncated' else 'history_coverage_unverified'
        return ('candidate_orders' if facts else 'inconclusive', error, None, None,
                [row['order_id'] for row in facts], facts)
    times = [_local(row['created_at']) for row in rows]
    start, end = (min(times), max(times)) if times else (None, None)
    if automatic and rows:
        return 'inconclusive', 'history_timezone_unverified', None, None, [], []
    if requested < purchase['dispatched_at']:
        return 'inconclusive', 'dispatch_outside_scan_window', start, end, [], []
    if end is not None and end > observed:
        return 'inconclusive', 'provider_clock_inconsistent', start, end, [], []
    if len(rows) >= snapshot.limit or snapshot.count >= snapshot.limit:
        return 'limit_reached', 'history_limit_reached', start, end, [], []
    if snapshot.count != len(rows):
        return 'inconclusive', 'history_count_mismatch', start, end, [], []
    candidates = [row['order_id'] for row, at in zip(rows, times)
                  if str(row['product_id']) == str(sku['external_id'])
                  and (at + timedelta(minutes=1) > purchase['dispatched_at']
                       if row.get('timestamp_precision') == 'minute' else at >= purchase['dispatched_at'])]
    if candidates:
        return 'candidate_orders', 'possible_purchase_after_dispatch', start, end, candidates, []
    if any(row.get('timestamp_precision') == 'minute' for row in rows):
        return 'inconclusive', 'history_time_precision_unverified', start, end, [], []
    if start is None or start > purchase['dispatched_at']:
        return 'inconclusive', 'dispatch_outside_history_window', start, end, [], []
    return 'no_order', None, start, end, [], []


class SupplierHistory:
    def __init__(self, acquisition):
        self.acquisition = acquisition
        self.db = acquisition.db
        self.using = acquisition.using
        self.clock = acquisition.clock
        self.cipher = acquisition.cipher

    def scan(self, fulfillment_id, *, actor_user_id=None, timezone='UTC', expected_purchase_id=None):
        if not isinstance(timezone, str) or not 0 < len(timezone) <= 64:
            raise CheckoutError('supplier_history_timezone_invalid')
        if connections[self.using].in_atomic_block or not connections[self.using].get_autocommit():
            raise RuntimeError('History transport cannot run in a transaction')
        with transaction.atomic(using=self.using):
            if actor_user_id is not None:
                self.acquisition.lifecycle._authorize(actor_user_id, review=True)
            locked = self.acquisition._lock(fulfillment_id)
            if not locked:
                raise CheckoutError('supplier_fulfillment_missing')
            order, _, fulfillment = locked
            purchase = self.db.one('SELECT * FROM public.supplier_purchases WHERE id=%s', (fulfillment['current_purchase_id'],))
            at = self.clock().replace(microsecond=0)
            if (not purchase or (expected_purchase_id is not None and purchase['id'] != expected_purchase_id)
                    or purchase['state'] not in ('ambiguous', 'review') or purchase['committed_at'] is not None
                    or purchase['supplier_order_id'] is not None or purchase['dispatched_at'] is None
                    or (purchase['lease_expires_at'] and purchase['lease_expires_at'] > at)):
                raise CheckoutError('supplier_history_unavailable')
            supplier = self.db.one('SELECT * FROM public.suppliers WHERE id=%s', (purchase['supplier_id'],))
            sku = self.db.one('SELECT * FROM public.supplier_catalog_items WHERE id=%s', (purchase['supplier_catalog_item_id'],))
            if supplier['provider'] not in ('toolorax', 'csxstore') or (actor_user_id is None and supplier['provider'] != 'toolorax'):
                raise CheckoutError('supplier_history_unavailable')
            latest = self.db.one('''SELECT * FROM public.supplier_order_history_reconciliations
                WHERE supplier_purchase_id=%s ORDER BY id DESC LIMIT 1''', (purchase['id'],))
            if latest and latest['error_code'] == 'scan_in_progress' and latest['requested_at'] > at - timedelta(minutes=3):
                raise CheckoutError('supplier_history_busy')
            if actor_user_id is None:
                count = self.db.one('''SELECT count(*) AS n FROM public.supplier_order_history_reconciliations
                    WHERE supplier_purchase_id=%s AND actor_user_id IS NULL''', (purchase['id'],))['n']
                if fulfillment['state'] != 'review' or (fulfillment['next_attempt_at'] and fulfillment['next_attempt_at'] > at) or count >= 3:
                    raise CheckoutError('supplier_history_unavailable')
                self.db.execute('UPDATE public.supplier_fulfillments SET next_attempt_at=%s,updated_at=%s WHERE id=%s',
                                (at + timedelta(minutes=5), at, fulfillment_id))
            scan = self.db.one('''INSERT INTO public.supplier_order_history_reconciliations
                (supplier_purchase_id,actor_user_id,outcome,error_code,requested_limit,row_count,dispatch_at,requested_at,
                 observed_at,history_timezone,purchase_version,purchase_claim_token,coverage_kind,created_at,updated_at)
                VALUES (%s,%s,'inconclusive','scan_in_progress',100,0,%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING id''',
                (purchase['id'], actor_user_id, purchase['dispatched_at'], at, at,
                 'unknown' if supplier['provider'] == 'csxstore' else timezone, _version(purchase['updated_at']),
                 purchase['claim_token'], 'first_page' if supplier['provider'] == 'csxstore' else 'qualified_window', at, at))
        try:
            snapshot = self.acquisition._client(supplier).order_history(timezone)
            if not isinstance(snapshot, OrderHistorySnapshot):
                raise ValueError('Invalid history snapshot')
            observed = self.clock().replace(microsecond=0)
            outcome, error, start, end, ids, facts = _classify(snapshot, supplier['provider'], purchase, sku, at, observed, actor_user_id is None)
            fields = (outcome, error, snapshot.limit, snapshot.count, len(snapshot.orders), start, end,
                      snapshot.timezone, snapshot.digest, self.cipher.encrypt_json(ids), self.cipher.encrypt_json(facts), snapshot.coverage)
        except Exception as exc:
            observed = self.clock().replace(microsecond=0)
            error = exc.code if isinstance(exc, SupplierUnavailable) else 'history_unavailable'
            fields = ('unavailable', error, 100, None, 0, None, None,
                      'unknown' if supplier['provider'] == 'csxstore' else timezone, None, None, None, None)
        with transaction.atomic(using=self.using):
            locked = self.acquisition._lock(fulfillment_id)
            current = self.db.one('SELECT * FROM public.supplier_purchases WHERE id=%s', (purchase['id'],))
            latest = self.db.one('SELECT id FROM public.supplier_order_history_reconciliations WHERE supplier_purchase_id=%s ORDER BY id DESC LIMIT 1', (purchase['id'],))
            if (not locked or locked[2]['current_purchase_id'] != purchase['id'] or not current or not latest
                    or latest['id'] != scan['id'] or _version(current['updated_at']) != _version(purchase['updated_at'])
                    or current['claim_token'] != purchase['claim_token'] or current['committed_at'] is not None
                    or current['supplier_order_id'] is not None or current['state'] not in ('ambiguous', 'review')
                    or (current['lease_expires_at'] and current['lease_expires_at'] > observed)):
                fields = ('invalid', 'stale_purchase_scan', *fields[2:9], self.cipher.encrypt_json([]), self.cipher.encrypt_json([]), fields[11])
            self.db.execute('''UPDATE public.supplier_order_history_reconciliations SET outcome=%s,error_code=%s,
                requested_limit=%s,reported_count=%s,row_count=%s,coverage_start=%s,coverage_end=%s,history_timezone=%s,
                response_sha256=%s,candidate_order_ids=%s,candidate_facts=%s,coverage_kind=%s,observed_at=%s,updated_at=%s
                WHERE id=%s''', (*fields, observed, observed, scan['id']))
            if actor_user_id is None and locked and locked[2]['current_purchase_id'] == purchase['id'] and locked[2]['state'] == 'review':
                count = self.db.one('SELECT count(*) AS n FROM public.supplier_order_history_reconciliations WHERE supplier_purchase_id=%s AND actor_user_id IS NULL', (purchase['id'],))['n']
                self.db.execute('UPDATE public.supplier_fulfillments SET next_attempt_at=%s,updated_at=%s WHERE id=%s',
                                (None if count >= 3 else observed + timedelta(minutes=5), observed, fulfillment_id))
            if locked:
                self.acquisition.lifecycle._audit('supplier_purchase.order_history_reconciled', locked[0], None, actor=actor_user_id)
        return scan['id']

    def assert_candidate(self, purchase, supplier, sku, order_id, quantity, cost, history_id, version, *, replay=False):
        self.db.require_transaction()
        scan = self.db.one('SELECT * FROM public.supplier_order_history_reconciliations WHERE id=%s FOR UPDATE', (history_id,))
        latest = self.db.one('SELECT id FROM public.supplier_order_history_reconciliations WHERE supplier_purchase_id=%s ORDER BY id DESC LIMIT 1', (purchase['id'],))
        facts = self.cipher.decrypt_json(scan['candidate_facts']) if scan and scan['candidate_facts'] else []
        candidate = next((row for row in facts if row.get('order_id') == order_id), None)
        conflict = self.db.one('SELECT id FROM public.supplier_purchases WHERE supplier_id=%s AND supplier_order_id=%s AND id<>%s',
                               (purchase['supplier_id'], order_id, purchase['id']))
        at = self.clock()
        if (not scan or not latest or latest['id'] != history_id or scan['supplier_purchase_id'] != purchase['id']
                or supplier['provider'] != 'csxstore' or scan['outcome'] != 'candidate_orders'
                or scan['observed_at'] < at - timedelta(minutes=15) or scan['observed_at'] > at
                or version != scan['purchase_version']
                or (not replay and (_version(purchase['updated_at']) != version or purchase['claim_token'] != scan['purchase_claim_token']))
                or not candidate or candidate['quantity'] != quantity or candidate['total_micros'] != cost
                or str(candidate['product_id']) != str(sku['external_id']) or candidate['currency'] != supplier['currency']
                or conflict):
            raise CheckoutError('supplier_history_candidate_stale')

    def confirm_candidate(self, fulfillment_id, history_id, order_id, note, *, actor_user_id, expected_purchase_id, expected_purchase_version):
        if type(history_id) is not int or type(expected_purchase_id) is not int or not isinstance(expected_purchase_version, str):
            raise CheckoutError('supplier_history_candidate_invalid')
        scan = self.db.one('SELECT candidate_facts FROM public.supplier_order_history_reconciliations WHERE id=%s', (history_id,))
        facts = self.cipher.decrypt_json(scan['candidate_facts']) if scan and scan['candidate_facts'] else []
        candidate = next((row for row in facts if row.get('order_id') == order_id), None)
        if candidate is None:
            raise CheckoutError('supplier_history_candidate_invalid')
        return self.acquisition.confirm_debit(fulfillment_id, order_id, candidate['quantity'], candidate['total_micros'], note,
            actor_user_id=actor_user_id, expected_purchase_id=expected_purchase_id,
            _history=(history_id, expected_purchase_version))
