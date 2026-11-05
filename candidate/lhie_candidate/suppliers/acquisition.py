"""Supplier purchase claims and evidence, on the existing fulfillment tables.

The client is injected: creating this service never enables network access. Supplier
HTTP runs after the claim and dispatch episode commit, outside all transactions.
"""
from datetime import timedelta
import hmac
import re
from uuid import uuid4

from django.db import connections, transaction

from lhie_candidate.checkout.db import Database
from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.checkout.lifecycle import OrderLifecycle, now as default_clock
from lhie_candidate.receipt.jobs import wake_after_commit
from .pricing import cost_idr, cost_micros
from .transport import OrderResult


_IDEMPOTENT = frozenset(('prodseller', 'aivault', 'nystore', 'canboso', 'miraiapi'))
_TRANSIENT = frozenset(('transport', 'transport_unavailable', 'upstream', 'rate_limited', 'snapshot_timeout'))


class SupplierAcquisition:
    def __init__(self, encrypter, client_factory, *, using='default', clock=None):
        self.db = Database(using)
        self.using = using
        self.cipher = encrypter
        self.client_factory = client_factory
        self.clock = clock or default_clock
        self.lifecycle = OrderLifecycle(using=using, encrypter=encrypter, clock=self.clock)

    def due_ids(self, limit=25):
        """Bounded durable discovery; processing each identity remains token-fenced."""
        if type(limit) is not int or not 1 <= limit <= 500:
            raise ValueError('invalid supplier recovery limit')
        at = self.clock()
        rows = self.db.rows('''SELECT f.id FROM public.supplier_fulfillments f
            LEFT JOIN public.supplier_purchases p ON p.id=f.current_purchase_id
            WHERE (f.state IN ('pending','purchasing','awaiting_delivery')
              AND (f.next_attempt_at IS NULL OR f.next_attempt_at<=%s
                   OR (p.lease_expires_at IS NOT NULL AND p.lease_expires_at<=%s)))
               OR (f.state='review' AND (f.next_attempt_at IS NULL OR f.next_attempt_at<=%s) AND p.state IN ('ambiguous','review')
                   AND p.dispatched_at IS NOT NULL AND p.supplier_order_id IS NULL AND p.committed_at IS NULL
                   AND (p.lease_expires_at IS NULL OR p.lease_expires_at<=%s)
                   AND EXISTS (SELECT 1 FROM public.suppliers s WHERE s.id=p.supplier_id AND s.provider='toolorax')
                   AND (SELECT count(*) FROM public.supplier_order_history_reconciliations h
                        WHERE h.supplier_purchase_id=p.id AND h.actor_user_id IS NULL)<3)
            ORDER BY f.next_attempt_at NULLS FIRST,f.id LIMIT %s''', (at, at, at, at, limit))
        return [row['id'] for row in rows]

    def _lock(self, fulfillment_id):
        initial = self.db.one('SELECT order_id FROM public.supplier_fulfillments WHERE id=%s', (fulfillment_id,))
        if initial is None:
            return None
        order, payments, _ = self.lifecycle.lock_order(initial['order_id'])
        self.lifecycle.fulfillment.lock(order['id'])
        fulfillment = self.db.one('SELECT * FROM public.supplier_fulfillments WHERE id=%s AND order_id=%s', (fulfillment_id, order['id']))
        return order, payments, fulfillment

    def _review(self, order, payments, fulfillment, error):
        at = self.clock()
        self.db.execute("""UPDATE public.supplier_fulfillments SET
            state=CASE WHEN state='delivered' THEN state ELSE 'review' END,
            last_error=%s,next_attempt_at=NULL,updated_at=%s WHERE id=%s""", (error, at, fulfillment['id']))
        paid = next((p for p in payments if p['status'] == 'paid' and p['paid_at'] is not None), None)
        if paid:
            self.lifecycle.review_locked(order, paid, 'supplier_fulfillment')

    def _snapshots(self, fulfillment):
        value = self.cipher.decrypt_json(fulfillment['sources_snapshot'])
        if type(value) is not list or not all(type(row) is dict for row in value):
            raise CheckoutError('supplier_snapshot_invalid')
        return value

    def _claim(self, fulfillment_id):
        locked = self._lock(fulfillment_id)
        if locked is None:
            return None
        order, payments, f = locked
        at = self.clock()
        if (order['payment_status'] != 'paid' or order['order_status'] not in ('processing', 'completed')
                or not any(p['status'] == 'paid' and p['paid_at'] is not None for p in payments)
                or f['state'] not in ('pending', 'purchasing', 'awaiting_delivery')):
            return None
        p = self.db.one('SELECT * FROM public.supplier_purchases WHERE id=%s', (f['current_purchase_id'],)) if f['current_purchase_id'] else None
        if p and p['lease_expires_at'] and p['lease_expires_at'] > at:
            return None
        if f['next_attempt_at'] and f['next_attempt_at'] > at and not (p and p['lease_expires_at'] and p['lease_expires_at'] <= at):
            return None
        if p and p['state'] == 'sending' and not p['supplier_order_id']:
            self.db.execute("""UPDATE public.supplier_purchase_dispatches SET finished_at=%s,outcome='unknown',
                error_code='dispatch_outcome_unknown' WHERE claim_token=%s AND finished_at IS NULL""", (at, p['claim_token']))
            supplier = self.db.one('SELECT * FROM public.suppliers WHERE id=%s', (p['supplier_id'],))
            replay = (p['dispatched_at'] is not None and supplier['is_active'] and supplier['provider'] in _IDEMPOTENT
                      and p['replay_count'] == 0 and p['dispatched_at'] >= at - timedelta(minutes=10))
            if p['dispatched_at'] is None or replay:
                p['state'] = 'reserved'
                self.db.execute("UPDATE public.supplier_purchases SET state='reserved',replay_count=replay_count+%s,updated_at=%s WHERE id=%s", (int(replay), at, p['id']))
            else:
                self.db.execute("""UPDATE public.supplier_purchases SET state='ambiguous',last_error='dispatch_outcome_unknown',
                    claim_token=NULL,lease_expires_at=NULL,updated_at=%s WHERE id=%s""", (at, p['id']))
                self._review(order, payments, f, 'dispatch_outcome_unknown')
                return None
        if p is None:
            supplier = self.db.one('SELECT * FROM public.suppliers WHERE id=%s', (f['reserved_supplier_id'],))
            sku = self.db.one('SELECT * FROM public.supplier_catalog_items WHERE id=%s', (f['reserved_catalog_item_id'],))
            candidate = next((s for s in self._snapshots(f) if s.get('supplier_id') == f['reserved_supplier_id'] and s.get('catalog_item_id') == f['reserved_catalog_item_id']), None)
            if (not candidate or not supplier or not sku or not supplier['is_active'] or not sku['orderable']
                    or sku['requires_input'] or supplier['currency'] != candidate['currency'] or sku['external_id'] != candidate['external_id']):
                self._review(order, payments, f, 'source_unavailable')
                return None
            quantity = f['reserved_quantity']
            cost = cost_micros(sku, quantity) if quantity > 0 else None
            spent = self._spent(f['id'])
            if cost is None or cost > f['reserved_cost_micros'] or cost_idr(cost, candidate['currency'], candidate['exchange_rate_idr']) > max(0, f['maximum_cost_idr'] - spent):
                self._review(order, payments, f, 'cost_changed')
                return None
            p = self.db.one("""INSERT INTO public.supplier_purchases
                (supplier_fulfillment_id,supplier_id,supplier_catalog_item_id,reference,quantity,expected_cost_micros,
                 exchange_rate_idr,state,created_at,updated_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,'reserved',%s,%s) RETURNING *""",
                (f['id'], supplier['id'], sku['id'], str(uuid4()), quantity, f['reserved_cost_micros'], candidate['exchange_rate_idr'], at, at))
            self.db.execute('UPDATE public.supplier_fulfillments SET current_purchase_id=%s,updated_at=%s WHERE id=%s', (p['id'], at, f['id']))
        read = p['supplier_order_id'] is not None
        if not read and p['state'] != 'reserved':
            return None
        supplier = self.db.one('SELECT * FROM public.suppliers WHERE id=%s', (p['supplier_id'],))
        sku = self.db.one('SELECT * FROM public.supplier_catalog_items WHERE id=%s', (p['supplier_catalog_item_id'],))
        if not read and not supplier['is_active']:
            self._review(order, payments, f, 'source_unavailable')
            return None
        token, deadline = str(uuid4()), at + timedelta(minutes=2)
        state = 'awaiting_delivery' if read else 'sending'
        self.db.execute('UPDATE public.supplier_purchases SET state=%s,claim_token=%s,lease_expires_at=%s,updated_at=%s WHERE id=%s', (state, token, deadline, at, p['id']))
        self.db.execute('UPDATE public.supplier_fulfillments SET state=%s,next_attempt_at=%s,updated_at=%s WHERE id=%s', ('awaiting_delivery' if read else 'purchasing', deadline, at, f['id']))
        return p, supplier, sku, token, read

    def _spent(self, fulfillment_id):
        rows = self.db.rows('''SELECT p.actual_cost_micros,p.exchange_rate_idr,s.currency FROM public.supplier_purchases p
            JOIN public.suppliers s ON s.id=p.supplier_id WHERE p.supplier_fulfillment_id=%s
            AND p.warranty_claim_id IS NULL AND p.committed_at IS NOT NULL''', (fulfillment_id,))
        return sum(cost_idr(row['actual_cost_micros'], row['currency'], row['exchange_rate_idr']) for row in rows)

    def _dispatch(self, fulfillment_id, purchase_id, token):
        at = self.clock()
        locked = self._lock(fulfillment_id)
        if not locked:
            return False
        order, payments, fulfillment = locked
        p = self.db.one('SELECT * FROM public.supplier_purchases WHERE id=%s', (purchase_id,))
        if (not p or fulfillment['current_purchase_id'] != purchase_id or str(p['claim_token']) != token
                or not p['lease_expires_at'] or p['lease_expires_at'] <= at):
            return False
        supplier = self.db.one('SELECT is_active FROM public.suppliers WHERE id=%s', (p['supplier_id'],))
        if not supplier or not supplier['is_active']:
            self.db.execute("""UPDATE public.supplier_purchases SET state='review',claim_token=NULL,
                lease_expires_at=NULL,last_error='source_unavailable',updated_at=%s WHERE id=%s""", (at, purchase_id))
            self._review(order, payments, fulfillment, 'source_unavailable')
            return False
        self.db.execute("INSERT INTO public.supplier_purchase_dispatches (supplier_purchase_id,claim_token,started_at,outcome) VALUES (%s,%s,%s,'started')", (purchase_id, token, at))
        self.db.execute('UPDATE public.supplier_purchases SET dispatched_at=COALESCE(dispatched_at,%s),updated_at=%s WHERE id=%s', (at, at, purchase_id))
        return True

    def _client(self, supplier):
        key = self.cipher.decrypt_string(supplier['api_key'])
        client = self.client_factory(supplier, key)
        config = getattr(client, 'config', None)
        if (config is None or config.provider != supplier['provider'] or config.currency != supplier['currency']
                or config.active != supplier['is_active'] or config.currency_confirmed != supplier['currency_confirmed']
                or not isinstance(key, str) or not isinstance(config.api_key, str)
                or not hmac.compare_digest(key.encode(), config.api_key.encode())):
            raise CheckoutError('supplier_connection_mismatch')
        return client

    def process(self, fulfillment_id):
        if connections[self.using].in_atomic_block or not connections[self.using].get_autocommit():
            raise RuntimeError('Supplier transport cannot run in a transaction')
        with transaction.atomic(using=self.using):
            claim = self._claim(fulfillment_id)
        if claim is None:
            from .history import SupplierHistory
            try:
                SupplierHistory(self).scan(fulfillment_id)
                return True
            except CheckoutError:
                return False
        purchase, supplier, sku, token, read = claim
        if not read:
            with transaction.atomic(using=self.using):
                if not self._dispatch(fulfillment_id, purchase['id'], token):
                    return False
        try:
            client = self._client(supplier)
            result = (client.order(purchase['supplier_order_id'], sku['external_id'], str(purchase['reference'])) if read
                      else client.purchase(sku['external_id'], purchase['quantity'], str(purchase['reference'])))
            if not isinstance(result, OrderResult):
                raise ValueError('Invalid supplier response')
        except Exception:
            result = OrderResult('ambiguous', error_code='transport_unavailable')
        with transaction.atomic(using=self.using):
            self._finalize(fulfillment_id, purchase['id'], token, result, read)
        return True

    def recover_order(self, fulfillment_id, order_id, *, actor_user_id, expected_purchase_id, _debit=None, _history=None):
        """Use an exact supplier order read; an uncorrelated identity cannot settle."""
        if not isinstance(order_id, str) or re.fullmatch(r'[A-Za-z0-9._:-]{1,128}', order_id) is None:
            raise CheckoutError('supplier_order_identity_invalid')
        if connections[self.using].in_atomic_block or not connections[self.using].get_autocommit():
            raise RuntimeError('Supplier transport cannot run in a transaction')
        with transaction.atomic(using=self.using):
            self.lifecycle._authorize(actor_user_id, review=True)
            locked = self._lock(fulfillment_id)
            if locked is None:
                raise CheckoutError('supplier_fulfillment_missing')
            order, payments, f = locked
            p = self.db.one('SELECT * FROM public.supplier_purchases WHERE id=%s', (f['current_purchase_id'],))
            if _debit is not None and p and p['id'] == expected_purchase_id:
                existing = self.db.one("""SELECT actor_user_id,provider_reference,note,history_reconciliation_id FROM public.supplier_purchase_evidence
                    WHERE supplier_purchase_id=%s AND kind='debit_confirmed'""", (p['id'],))
                if existing is not None:
                    if (existing != {'actor_user_id': actor_user_id, 'provider_reference': order_id, 'note': _debit[2],
                                     'history_reconciliation_id': _history[0] if _history else None}
                            or p['supplier_order_id'] != order_id or p['observed_quantity'] != _debit[0]
                            or p['actual_cost_micros'] != _debit[1]):
                        raise CheckoutError('supplier_evidence_conflict')
                    if _history is not None:
                        from .history import SupplierHistory
                        supplier = self.db.one('SELECT * FROM public.suppliers WHERE id=%s', (p['supplier_id'],))
                        sku = self.db.one('SELECT * FROM public.supplier_catalog_items WHERE id=%s', (p['supplier_catalog_item_id'],))
                        SupplierHistory(self).assert_candidate(p, supplier, sku, order_id, _debit[0], _debit[1], *_history, replay=True)
                    return
            at = self.clock()
            if (order['payment_status'] != 'paid' or order['order_status'] not in ('processing', 'completed')
                    or not p or p['id'] != expected_purchase_id
                    or p['state'] not in ('ambiguous', 'review', 'awaiting_delivery')
                    or (p['lease_expires_at'] and p['lease_expires_at'] > at)):
                raise CheckoutError('supplier_recovery_unavailable')
            if _debit is not None and (p['supplier_order_id'] is not None or p['state'] == 'awaiting_delivery'):
                raise CheckoutError('supplier_recovery_unavailable')
            supplier = self.db.one('SELECT * FROM public.suppliers WHERE id=%s', (p['supplier_id'],))
            sku = self.db.one('SELECT * FROM public.supplier_catalog_items WHERE id=%s', (p['supplier_catalog_item_id'],))
            if _history is not None:
                from .history import SupplierHistory
                SupplierHistory(self).assert_candidate(p, supplier, sku, order_id, _debit[0], _debit[1], *_history)
            token = str(uuid4())
            self.db.execute('UPDATE public.supplier_purchases SET claim_token=%s,lease_expires_at=%s,updated_at=%s WHERE id=%s',
                            (token, at + timedelta(minutes=2), at, p['id']))
        try:
            client = self._client(supplier)
            result = client.order(order_id, sku['external_id'], str(p['reference']))
            if (not isinstance(result, OrderResult) or not result.identity_trusted or result.order_id != order_id
                    or (p['supplier_order_id'] and p['supplier_order_id'] != order_id)
                    or (not p['supplier_order_id'] and (result.quantity != p['quantity'] or result.cost_micros is None))):
                result = OrderResult('ambiguous', error_code='order_identity_mismatch')
            if _debit is not None and (result.order_id != order_id or result.quantity != _debit[0]
                                       or result.cost_micros != _debit[1]):
                result = OrderResult('ambiguous', error_code='operator_evidence_mismatch')
        except Exception:
            result = OrderResult('ambiguous', error_code='transport_unavailable')
        with transaction.atomic(using=self.using):
            if _debit is not None:
                fence = self._lock(fulfillment_id)
                current = self.db.one('SELECT claim_token FROM public.supplier_purchases WHERE id=%s', (p['id'],))
                if not fence or fence[2]['current_purchase_id'] != p['id'] or not current or str(current['claim_token']) != token:
                    raise CheckoutError('supplier_recovery_stale')
                if _history is not None:
                    from .history import SupplierHistory
                    SupplierHistory(self).assert_candidate(p, supplier, sku, order_id, _debit[0], _debit[1], *_history, replay=True)
            self._finalize(fulfillment_id, p['id'], token, result, True)
            confirmed = False
            if _debit is not None:
                locked = self._lock(fulfillment_id)
                current = self.db.one('SELECT * FROM public.supplier_purchases WHERE id=%s', (p['id'],))
                confirmed = bool(result.identity_trusted and result.order_id == order_id
                    and result.quantity == _debit[0] and result.cost_micros == _debit[1]
                    and locked and locked[2]['current_purchase_id'] == p['id'] and current
                    and current['supplier_order_id'] == order_id and current['observed_quantity'] == _debit[0]
                    and current['actual_cost_micros'] == _debit[1] and current['committed_at'] is not None)
                if confirmed:
                    at = self.clock()
                    self.db.execute('''INSERT INTO public.supplier_purchase_evidence
                        (supplier_purchase_id,actor_user_id,kind,evidence_source,provider_reference,note,history_reconciliation_id,observed_at,created_at,updated_at)
                        VALUES (%s,%s,'debit_confirmed','provider_exact_order',%s,%s,%s,%s,%s,%s)''',
                        (p['id'], actor_user_id, order_id, _debit[2], _history[0] if _history else None, at, at, at))
                    self.lifecycle._audit('supplier_purchase.debit_confirmed', locked[0], None, actor=actor_user_id)
        if _debit is not None and not confirmed:
            raise CheckoutError('supplier_debit_evidence_mismatch')

    def confirm_debit(self, fulfillment_id, order_id, quantity, cost_micros, note, *, actor_user_id, expected_purchase_id, _history=None):
        if (type(quantity) is not int or quantity < 1 or type(cost_micros) is not int or cost_micros < 1
                or not isinstance(note, str) or not note.strip() or len(note) > 4000):
            raise CheckoutError('supplier_debit_evidence_invalid')
        return self.recover_order(fulfillment_id, order_id, actor_user_id=actor_user_id,
            expected_purchase_id=expected_purchase_id, _debit=(quantity, cost_micros, note.strip()), _history=_history)

    def confirm_no_debit(self, fulfillment_id, *, actor_user_id, expected_purchase_id,
                         evidence_source, provider_reference, note, history_reconciliation_id=None):
        if (evidence_source not in ('provider_case', 'provider_statement', 'provider_history')
                or (evidence_source == 'provider_history' and (type(history_reconciliation_id) is not int or history_reconciliation_id < 1))
                or (evidence_source != 'provider_history' and (history_reconciliation_id is not None or not isinstance(provider_reference, str) or not provider_reference.strip()))
                or not isinstance(provider_reference, str)
                or len(provider_reference) > 255 or any(ord(c) < 32 or ord(c) == 127 for c in provider_reference)
                or not isinstance(note, str) or not note.strip() or len(note) > 4000):
            raise CheckoutError('supplier_evidence_invalid')
        provider_reference = ('history-scan#' + str(history_reconciliation_id)) if evidence_source == 'provider_history' else provider_reference.strip()
        note = note.strip()
        with transaction.atomic(using=self.using):
            self.lifecycle._authorize(actor_user_id, review=True)
            locked = self._lock(fulfillment_id)
            if locked is None:
                raise CheckoutError('supplier_fulfillment_missing')
            order, _, f = locked
            p = self.db.one('SELECT * FROM public.supplier_purchases WHERE id=%s', (f['current_purchase_id'],))
            if not p or p['id'] != expected_purchase_id:
                raise CheckoutError('supplier_recovery_unavailable')
            existing = self.db.one("""SELECT actor_user_id,evidence_source,provider_reference,note,history_reconciliation_id
                FROM public.supplier_purchase_evidence WHERE supplier_purchase_id=%s AND kind='no_debit'""", (p['id'],))
            expected = {'actor_user_id': actor_user_id, 'evidence_source': evidence_source,
                        'provider_reference': provider_reference, 'note': note,
                        'history_reconciliation_id': history_reconciliation_id}
            if existing is not None:
                if existing != expected:
                    raise CheckoutError('supplier_evidence_conflict')
                return
            at = self.clock()
            if (p['state'] not in ('ambiguous', 'review') or p['committed_at'] is not None
                    or p['supplier_order_id'] is not None or p['actual_cost_micros'] is not None
                    or (p['lease_expires_at'] and p['lease_expires_at'] > at)):
                raise CheckoutError('supplier_recovery_unavailable')
            if evidence_source == 'provider_history':
                history = self.db.one('''SELECT * FROM public.supplier_order_history_reconciliations
                    WHERE supplier_purchase_id=%s ORDER BY observed_at DESC,id DESC LIMIT 1 FOR UPDATE''', (p['id'],))
                supplier = self.db.one('SELECT provider FROM public.suppliers WHERE id=%s', (p['supplier_id'],))
                if (not history or history['id'] != history_reconciliation_id or supplier['provider'] != 'toolorax'
                        or history['outcome'] != 'no_order' or p['dispatched_at'] is None
                        or history['requested_at'] < p['dispatched_at'] or history['observed_at'] < history['requested_at']
                        or history['row_count'] >= history['requested_limit'] or history['reported_count'] != history['row_count']
                        or history['coverage_start'] is None or history['coverage_end'] is None
                        or history['coverage_end'] > history['observed_at'] or history['coverage_start'] > p['dispatched_at']):
                    raise CheckoutError('supplier_history_inconclusive')
            self.db.execute('''INSERT INTO public.supplier_purchase_evidence
                (supplier_purchase_id,actor_user_id,kind,evidence_source,provider_reference,note,history_reconciliation_id,observed_at,created_at,updated_at)
                VALUES (%s,%s,'no_debit',%s,%s,%s,%s,%s,%s,%s)''',
                (p['id'], actor_user_id, evidence_source, provider_reference, note, history_reconciliation_id, at, at, at))
            self._unhold(f, at)
            self.db.execute("""UPDATE public.supplier_purchases SET state='rejected',
                last_error='provider_no_debit_confirmed',next_attempt_at=NULL,updated_at=%s WHERE id=%s""", (at, p['id']))
            if f['state'] != 'delivered':
                self.db.execute("""UPDATE public.supplier_fulfillments SET state='review',
                    last_error='provider_no_debit_confirmed',next_attempt_at=NULL,updated_at=%s WHERE id=%s""", (at, f['id']))
            self.lifecycle._audit('supplier_purchase.no_debit_confirmed', order, None, actor=actor_user_id)

    def confirm_owner_no_debit(self, fulfillment_id, *, actor_user_id, expected_purchase_id):
        """Record an Owner attestation; it is never provider-confirmed evidence."""
        with transaction.atomic(using=self.using):
            actor = self.db.one("SELECT id FROM public.users WHERE id=%s AND role='owner'", (actor_user_id,))
            if actor is None:
                raise CheckoutError('operator_forbidden')
            locked = self._lock(fulfillment_id)
            if locked is None:
                raise CheckoutError('supplier_fulfillment_missing')
            order, payments, f = locked
            if (order['payment_status'] != 'paid' or order['order_status'] not in ('processing', 'completed')
                    or f['current_purchase_id'] != expected_purchase_id or f['state'] not in ('review', 'delivered')):
                raise CheckoutError('supplier_recovery_unavailable')
            p = self.db.one('SELECT * FROM public.supplier_purchases WHERE id=%s', (expected_purchase_id,))
            at = self.clock()
            if (not p or p['supplier_fulfillment_id'] != f['id'] or p['committed_at'] is not None
                    or p['supplier_order_id'] is not None or p['actual_cost_micros'] is not None
                    or (p['lease_expires_at'] and p['lease_expires_at'] > at)
                    or p['state'] not in ('ambiguous', 'review', 'rejected')):
                raise CheckoutError('supplier_recovery_unavailable')
            invoice = self.db.one('SELECT invoice_number FROM public.orders WHERE id=%s', (order['id'],))['invoice_number']
            self.db.execute('''INSERT INTO public.supplier_purchase_evidence
                (supplier_purchase_id,actor_user_id,kind,evidence_source,provider_reference,note,observed_at,created_at,updated_at)
                VALUES (%s,%s,'no_debit','owner_attestation',%s,%s,%s,%s,%s)
                ON CONFLICT (supplier_purchase_id,kind) DO NOTHING''',
                (p['id'], actor_user_id, invoice, 'Owner confirms the original purchase did not debit the supplier account.', at, at, at))
            evidence = self.db.one("SELECT actor_user_id,evidence_source,provider_reference FROM public.supplier_purchase_evidence WHERE supplier_purchase_id=%s AND kind='no_debit'", (p['id'],))
            if evidence != {'actor_user_id': actor_user_id, 'evidence_source': 'owner_attestation', 'provider_reference': invoice}:
                raise CheckoutError('supplier_evidence_conflict')
            self._unhold(f, at)
            self.db.execute("""UPDATE public.supplier_purchases SET state='rejected',last_error='owner_no_debit_confirmed',
                next_attempt_at=NULL,claim_token=NULL,lease_expires_at=NULL,updated_at=%s WHERE id=%s""", (at, p['id']))
            self.lifecycle._audit('supplier_fulfillment.owner_no_debit_confirmed', order, None, actor=actor_user_id)

    def _fallback(self, f, at):
        acquired = self.db.one('''SELECT count(*) AS n FROM public.product_stock_items s
            JOIN public.supplier_purchases p ON p.id=s.supplier_purchase_id
            WHERE s.order_item_id=%s AND p.supplier_fulfillment_id=%s AND p.warranty_claim_id IS NULL''',
            (f['order_item_id'], f['id']))['n']
        handed = self.db.one('SELECT COALESCE(sum(quantity),0) AS n FROM public.supplier_manual_handoffs WHERE supplier_fulfillment_id=%s', (f['id'],))['n']
        quantity = max(0, f['quantity'] - acquired - f['refunded_quantity'] - handed)
        if not quantity:
            return False
        attempted = {row['supplier_catalog_item_id'] for row in self.db.rows(
            'SELECT supplier_catalog_item_id FROM public.supplier_purchases WHERE supplier_fulfillment_id=%s', (f['id'],))}
        remaining = max(0, f['maximum_cost_idr'] - self._spent(f['id']))
        for candidate in self._snapshots(f):
            if candidate['catalog_item_id'] in attempted:
                continue
            sku = self.db.one('SELECT * FROM public.supplier_catalog_items WHERE id=%s', (candidate['catalog_item_id'],))
            supplier = self.db.one('SELECT * FROM public.suppliers WHERE id=%s', (candidate['supplier_id'],))
            source = self.db.one('SELECT is_active FROM public.product_supplier_sources WHERE id=%s', (candidate['source_id'],))
            if (not sku or not supplier or not source or not source['is_active'] or not supplier['is_active']
                    or not supplier['currency_confirmed'] or not sku['orderable'] or sku['requires_input']
                    or sku['supplier_id'] != supplier['id'] or sku['external_id'] != candidate['external_id']
                    or supplier['currency'] != candidate['currency']
                    or not supplier['sync_expires_at'] or supplier['sync_expires_at'] <= at
                    or not sku['synced_at'] or sku['synced_at'] < at - timedelta(minutes=5)):
                continue
            cost = cost_micros(sku, quantity)
            if (cost is None or cost_idr(cost, candidate['currency'], candidate['exchange_rate_idr']) > remaining
                    or (sku['stock'] or 0) - sku['reserved_quantity'] < quantity
                    or (supplier['balance_micros'] or 0) - supplier['reserved_balance_micros'] < cost):
                continue
            self.db.execute('UPDATE public.suppliers SET reserved_balance_micros=reserved_balance_micros+%s,version=version+1,updated_at=%s WHERE id=%s', (cost, at, supplier['id']))
            self.db.execute('UPDATE public.supplier_catalog_items SET reserved_quantity=reserved_quantity+%s,updated_at=%s WHERE id=%s', (quantity, at, sku['id']))
            self.db.execute('''UPDATE public.supplier_fulfillments SET reserved_supplier_id=%s,reserved_catalog_item_id=%s,
                reserved_cost_micros=%s,reserved_quantity=%s,state='pending',current_purchase_id=NULL,
                next_attempt_at=%s,updated_at=%s WHERE id=%s''',
                (supplier['id'], sku['id'], cost, quantity, at + timedelta(seconds=10), at, f['id']))
            from .capacity import refresh_shared_wallet_after_commit
            refresh_shared_wallet_after_commit([supplier['id']], using=self.using)
            return True
        return False

    def _unhold(self, f, at):
        if not f['reserved_quantity']:
            return
        changed = self.db.execute('''UPDATE public.suppliers SET reserved_balance_micros=reserved_balance_micros-%s,
            version=version+1,updated_at=%s WHERE id=%s AND reserved_balance_micros>=%s''',
            (f['reserved_cost_micros'], at, f['reserved_supplier_id'], f['reserved_cost_micros']))
        changed_sku = self.db.execute('''UPDATE public.supplier_catalog_items SET reserved_quantity=reserved_quantity-%s,updated_at=%s
            WHERE id=%s AND reserved_quantity>=%s''', (f['reserved_quantity'], at, f['reserved_catalog_item_id'], f['reserved_quantity']))
        if changed != 1 or changed_sku != 1:
            raise CheckoutError('supplier_hold_invariant')
        self.db.execute('UPDATE public.supplier_fulfillments SET reserved_quantity=0,reserved_cost_micros=0,updated_at=%s WHERE id=%s', (at, f['id']))
        from .capacity import refresh_shared_wallet_after_commit
        refresh_shared_wallet_after_commit([f['reserved_supplier_id']], using=self.using)
        f['reserved_quantity'] = f['reserved_cost_micros'] = 0

    def _finalize(self, fulfillment_id, purchase_id, token, result, read):
        locked = self._lock(fulfillment_id)
        if locked is None:
            return
        order, payments, f = locked
        p = self.db.one('SELECT * FROM public.supplier_purchases WHERE id=%s AND supplier_fulfillment_id=%s', (purchase_id, fulfillment_id))
        if not p or str(p['claim_token']) != token or f['current_purchase_id'] != purchase_id:
            return
        at = self.clock()
        if not read:
            self.db.execute('UPDATE public.supplier_purchase_dispatches SET finished_at=%s,outcome=%s,error_code=%s WHERE claim_token=%s AND finished_at IS NULL', (at, result.status, result.error_code, token))
        self.db.execute('UPDATE public.supplier_purchases SET claim_token=NULL,lease_expires_at=NULL,last_error=%s,updated_at=%s WHERE id=%s', (result.error_code, at, purchase_id))
        if read and p['supplier_order_id'] and result.error_code in _TRANSIENT:
            self._await(f, p, at)
            return
        supplier = self.db.one('SELECT * FROM public.suppliers WHERE id=%s', (p['supplier_id'],))
        if (not read and result.status == 'ambiguous' and result.error_code in _TRANSIENT and not result.identity_trusted
                and not result.order_id and not result.deliveries and result.cost_micros is None and supplier['is_active']
                and supplier['provider'] in _IDEMPOTENT and p['replay_count'] == 0 and p['dispatched_at']
                and p['dispatched_at'] >= at - timedelta(minutes=10)):
            self.db.execute("UPDATE public.supplier_purchases SET state='sending',next_attempt_at=%s,updated_at=%s WHERE id=%s", (at + timedelta(minutes=1), at, p['id']))
            self.db.execute("UPDATE public.supplier_fulfillments SET state='purchasing',next_attempt_at=%s,updated_at=%s WHERE id=%s", (at + timedelta(minutes=1), at, f['id']))
            return
        old_order_id = p['supplier_order_id']
        if result.identity_trusted and result.order_id:
            collision = self.db.one('SELECT id FROM public.supplier_purchases WHERE supplier_id=%s AND supplier_order_id=%s AND id<>%s', (p['supplier_id'], result.order_id, p['id']))
            if collision or (old_order_id and old_order_id != result.order_id):
                self._fail(order, payments, f, p, 'review', 'order_identity_mismatch')
                return
            p['supplier_order_id'] = result.order_id
        if p['committed_at'] and result.identity_trusted and ((result.cost_micros is not None and result.cost_micros != p['actual_cost_micros']) or (result.quantity is not None and result.quantity != p['observed_quantity'])):
            self._fail(order, payments, f, p, 'review', 'economic_evidence_conflict')
            return
        previous = self.cipher.decrypt_json(p['delivery_payload']) if p['delivery_payload'] else []
        had_previous = bool(previous)
        trusted = self.cipher.decrypt_json(p['trusted_delivery_payload']) if p['trusted_delivery_payload'] else []
        observed = list(result.deliveries)
        conflict = bool(previous and observed and set(observed) - set(previous) and set(previous) - set(observed))
        conflict = conflict or len(observed) != len(set(observed))
        if observed and (not previous or (not conflict and len(observed) > len(previous))):
            previous = observed
        if conflict:
            trusted = []
        elif result.identity_trusted and result.delivery_trusted and observed and len(observed) >= len(trusted):
            trusted = observed
        if result.identity_trusted and result.cost_micros is not None:
            p['actual_cost_micros'] = result.cost_micros
        if result.identity_trusted and result.quantity is not None:
            p['observed_quantity'] = result.quantity
        elif result.identity_trusted and result.delivery_trusted and p['observed_quantity'] is None and len(observed) == p['quantity']:
            p['observed_quantity'] = len(observed)
        self.db.execute('''UPDATE public.supplier_purchases SET supplier_order_id=%s,actual_cost_micros=%s,observed_quantity=%s,
            delivery_payload=%s,trusted_delivery_payload=%s,updated_at=%s WHERE id=%s''',
            (p['supplier_order_id'], p['actual_cost_micros'], p['observed_quantity'], self.cipher.encrypt_json(previous) if previous else None,
             self.cipher.encrypt_json(trusted) if trusted else None, at, p['id']))
        quantity_matches = p['observed_quantity'] == p['quantity']
        economic = result.identity_trusted and p['supplier_order_id'] and quantity_matches
        if not p['committed_at'] and p['actual_cost_micros'] is not None and economic:
            self._unhold(f, at)
            changed = self.db.execute('UPDATE public.suppliers SET balance_micros=balance_micros-%s,version=version+1,updated_at=%s WHERE id=%s', (p['actual_cost_micros'], at, p['supplier_id']))
            if changed != 1:
                raise CheckoutError('supplier_balance_invariant')
            self.db.execute('UPDATE public.supplier_catalog_items SET stock=CASE WHEN stock IS NULL THEN NULL ELSE GREATEST(0,stock-%s) END,updated_at=%s WHERE id=%s', (p['observed_quantity'], at, p['supplier_catalog_item_id']))
            self.db.execute('UPDATE public.supplier_purchases SET committed_at=%s,updated_at=%s WHERE id=%s', (at, at, p['id']))
            p['committed_at'] = at
        handoff = self.db.one('SELECT id FROM public.supplier_manual_handoffs WHERE supplier_fulfillment_id=%s LIMIT 1', (f['id'],))
        if (f['state'] == 'delivered' or handoff) and economic and p['committed_at']:
            self.db.execute('UPDATE public.supplier_purchases SET state=%s,next_attempt_at=NULL,updated_at=%s WHERE id=%s', ('delivered' if len(trusted) == p['quantity'] else 'settled', at, p['id']))
            if f['state'] != 'delivered':
                self._review(order, payments, f, 'external_handoff_remaining')
            return
        if (result.status == 'rejected' and not read and p['replay_count'] == 0 and not observed and not result.order_id
                and result.cost_micros in (None, 0) and not p['committed_at']):
            self.db.execute("UPDATE public.supplier_purchases SET state='rejected',updated_at=%s WHERE id=%s", (at, p['id']))
            self._unhold(f, at)
            if not self._fallback(f, at):
                self._review(order, payments, f, result.error_code or 'purchase_rejected')
        elif (result.identity_trusted and result.delivery_trusted and not conflict and quantity_matches and len(trusted) == p['quantity']
              and p['committed_at'] and order['payment_status'] == 'paid' and order['order_status'] in ('processing', 'completed')):
            if cost_idr(p['actual_cost_micros'], supplier['currency'], p['exchange_rate_idr']) > f['maximum_cost_idr']:
                self._fail(order, payments, f, p, 'review', 'cost_mismatch')
                return
            item = self.db.one('SELECT id,product_id FROM public.order_items WHERE id=%s', (f['order_item_id'],))
            for number, secret in enumerate(trusted, 1):
                self.db.execute('''INSERT INTO public.product_stock_items
                    (product_id,order_item_id,secret,supplier_purchase_id,supplier_unit_index,created_at,updated_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s) ON CONFLICT (supplier_purchase_id,supplier_unit_index) DO NOTHING''',
                    (item['product_id'], item['id'], self.cipher.encrypt_string(secret), p['id'], number, at, at))
            self.db.execute("UPDATE public.supplier_purchases SET state='delivered',next_attempt_at=NULL,updated_at=%s WHERE id=%s", (at, p['id']))
            self.db.execute("UPDATE public.supplier_fulfillments SET state='delivered',delivered_at=%s,next_attempt_at=NULL,updated_at=%s WHERE id=%s", (at, at, f['id']))
            if not self.lifecycle.fulfillment.pending(order['id']):
                self.db.execute("""UPDATE public.manual_fulfillment_reviews SET state='fulfilled',resolved_at=%s,updated_at=%s
                    WHERE order_id=%s AND reason IN ('supplier_fulfillment','late_payment') AND state='pending'""", (at, at, order['id']))
                self.db.execute("UPDATE public.orders SET order_status='completed',updated_at=%s WHERE id=%s", (at, order['id']))
                wake_after_commit('order', str(order['id']), using=self.using)
        elif result.identity_trusted and result.delivery_trusted and not conflict and quantity_matches and len(trusted) == p['quantity'] and p['actual_cost_micros'] is None and p['supplier_order_id']:
            self._await(f, p, at)
        elif conflict or (previous and not (result.status == 'pending' and (not had_previous or old_order_id))) or (p['observed_quantity'] is not None and not quantity_matches) or not result.identity_trusted or result.error_code == 'delivery_unavailable':
            self._fail(order, payments, f, p, 'ambiguous' if not p['supplier_order_id'] and not previous else 'review', 'delivery_evidence_conflict' if conflict else result.error_code or 'delivery_incomplete')
        elif p['supplier_order_id']:
            self._await(f, p, at)
        else:
            self._fail(order, payments, f, p, 'ambiguous', 'purchase_outcome_unknown')

    def _await(self, f, p, at):
        due = at + timedelta(minutes=1)
        self.db.execute("UPDATE public.supplier_purchases SET state='awaiting_delivery',next_attempt_at=%s,updated_at=%s WHERE id=%s", (due, at, p['id']))
        if f['state'] != 'delivered':
            self.db.execute("UPDATE public.supplier_fulfillments SET state='awaiting_delivery',next_attempt_at=%s,updated_at=%s WHERE id=%s", (due, at, f['id']))

    def _fail(self, order, payments, f, p, state, error):
        self.db.execute('UPDATE public.supplier_purchases SET state=%s,last_error=%s,updated_at=%s WHERE id=%s', (state, error, self.clock(), p['id']))
        self._review(order, payments, f, error)
