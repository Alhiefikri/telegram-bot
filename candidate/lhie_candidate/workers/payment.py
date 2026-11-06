"""Payment family: checkout navigation, callback hints, and reconciliation.

Provider observations happen outside database transactions; settlement
relocks and revalidates the original payment snapshot.
"""

import uuid
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

from . import wakeup
from .contract import queue_spec


def _now():
    return datetime.now(ZoneInfo("Asia/Jakarta")).replace(tzinfo=None)


class PaymentHandler:
    def __init__(self, lifecycle, db, *, processor=None, funding_klik=None, funding=None):
        self.lifecycle = lifecycle
        self.db = db
        self.processor = processor
        self.funding_klik = funding_klik
        self.funding = funding or (funding_klik.funding if funding_klik is not None else None)
        self.funding_providers = tuple(
            provider for provider, enabled in (
                ('klikqris', funding_klik is not None),
                ('sakurupiah', self.funding is not None and getattr(self.funding, 'provider', None) is not None),
                ('manual', self.funding is not None and 'manual' in getattr(self.funding, 'rails', {})),
            ) if enabled)
        self._funding_cursor = None
        self._funding_recovery_cursor = None
        self.spec = queue_spec("payment")
        self._sweep_start = 0
        self._payment_cursors = {"hints": None, "ambiguous": None, "expiry": None}
        self._payment_cursors.update(active=None)
        self.external_methods = tuple(getattr(lifecycle, 'external_lifecycles', {}))
        self._payment_cursors['external'] = None
        self.providers = tuple(getattr(lifecycle, 'lifecycles', {'sakurupiah': lifecycle}))
        self.cancellations = None
        pakasir = getattr(lifecycle, 'lifecycles', {}).get('pakasir')
        if pakasir is not None:
            from ..payments.pakasir import PakasirCancellationAttempts
            self.cancellations = PakasirCancellationAttempts(pakasir)

    def _payment_for_event(self, event_id):
        rows = self.db.rows(
            """SELECT p.id FROM public.payment_events e
               JOIN public.payments p ON p.merchant_ref=e.merchant_ref
                 AND (p.provider_reference IS NULL OR p.provider_reference=e.provider_reference
                      OR e.provider_reference=p.merchant_ref)
                 AND p.provider=e.provider
               WHERE e.id=%s AND p.provider=ANY(%s)
               ORDER BY p.id LIMIT 2""",
            (event_id, list(self.providers)),
        )
        return rows[0]["id"] if len(rows) == 1 else None

    def handle(self, raw):
        kind, identity = wakeup.decode(self.spec.wakeup_queue, raw)
        if kind == "payment.navigation":
            if self.processor is None:
                raise wakeup.InvalidWakeup("Navigation processor unavailable")
            return self.processor.process(identity)
        if kind == "funding.klikqris":
            if self.funding_klik is None:
                return "ignored"
            return self.funding_klik.consume_callback(identity).state
        if kind != "payment.event":
            raise wakeup.InvalidWakeup("Invalid wakeup family")
        payment_id = self._payment_for_event(identity)
        if payment_id is None:
            return "ignored"
        return self.lifecycle.consume_callbacks(payment_id).state

    def _payment_page(self, key, query, params, limit):
        cursor = self._payment_cursors[key]
        if cursor is not None:
            rows = self.db.rows(query + " AND p.id > %s ORDER BY p.id LIMIT %s",
                                (*params, cursor, limit))
            if rows:
                return [row["id"] for row in rows]
            self._payment_cursors[key] = None
        return [row["id"] for row in self.db.rows(
            query + " ORDER BY p.id LIMIT %s", (*params, limit))]

    def _pending_hint_payments(self, limit):
        return self._payment_page(
            "hints",
            """SELECT DISTINCT p.id FROM public.payment_events e
               JOIN public.payments p ON p.merchant_ref=e.merchant_ref
                 AND (p.provider_reference=e.provider_reference OR p.provider_reference IS NULL
                      OR e.provider_reference=p.merchant_ref) AND p.provider=e.provider
               WHERE e.event_type='callback' AND e.processed_at IS NULL
                 AND p.provider=ANY(%s)
                 AND p.status NOT IN ('paid', 'refunded')""", (list(self.providers),), limit)

    def _ambiguous_payments(self, limit):
        return self._payment_page(
            "ambiguous",
            """SELECT p.id FROM public.payments p JOIN public.orders o ON o.id=p.order_id
               WHERE p.provider=ANY(%s) AND p.status NOT IN ('paid','refunded')
                 AND ((p.provider_create_state IN ('creating','outcome_unknown','recovery_unconfirmed',
                       'recovery_pending','create_failed','transient_qris_image','provider_total_pending')
                       AND p.provider_create_attempted_at IS NOT NULL AND p.provider_create_attempted_at < %s
                       AND o.order_status='pending_payment' AND o.payment_status='pending')
                      OR p.provider_create_state IN ('provider_berhasil','provider_SUCCESS'))
                 AND NOT EXISTS (SELECT 1 FROM public.manual_fulfillment_reviews r
                     WHERE r.order_id=o.id AND r.state IN ('pending','refund_uncertain'))""",
            (list(self.providers), _now() - timedelta(seconds=10)), limit)

    def _active_payments(self, limit):
        return self._payment_page(
            'active',
            """SELECT p.id FROM public.payments p JOIN public.orders o ON o.id=p.order_id
               WHERE p.provider=ANY(%s) AND p.provider<>'manual'
                 AND ((p.status='pending' AND o.order_status='pending_payment'
                       AND o.payment_status='pending' AND o.expires_at>%s
                       AND (p.provider_reference IS NOT NULL OR p.provider IN ('klikqris','pakasir')))
                      OR (p.provider='pakasir' AND p.status IN ('expired','failed')))
                 AND NOT EXISTS (SELECT 1 FROM public.payment_events e WHERE e.provider=p.provider
                     AND e.merchant_ref=p.merchant_ref AND e.provider_status='candidate_poll'
                     AND e.received_at>%s - interval '24 seconds')
                 AND NOT EXISTS (SELECT 1 FROM public.manual_fulfillment_reviews r
                     WHERE r.order_id=o.id AND r.state IN ('pending','refund_uncertain'))""",
            (list(self.providers), _now(), _now()), limit)

    def _external_payments(self, limit):
        if not self.external_methods:
            return []
        return self._payment_page(
            'external',
            """SELECT p.id FROM public.payments p JOIN public.orders o ON o.id=p.order_id
               LEFT JOIN public.payment_bsc_details d ON d.payment_id=p.id
               WHERE p.provider='manual' AND p.method=ANY(%s) AND p.currency='USDT'
                 AND p.status='pending' AND o.order_status='pending_payment' AND o.payment_status='pending'
                 AND ((p.method='BINANCE_PAY_ID' AND p.binance_auto_verification_enabled
                   AND p.provider_reference IS NULL
                   AND ((p.binance_verification_mode='amount_unique' AND p.expired_at>%s)
                     OR (p.binance_verification_mode='order_id' AND p.binance_order_id IS NOT NULL
                       AND p.binance_verification_status IN ('awaiting_submission','checking','not_found','provider_error')
                       AND p.binance_verification_grace_until>%s))
                   AND (p.binance_last_verified_at IS NULL OR p.binance_last_verified_at<=%s - interval '30 seconds'))
                  OR (p.method='USDT_BEP20'
                   AND (d.verification_mode='amount_unique'
                     OR (d.verification_mode='transaction_hash' AND d.current_tx_hash IS NOT NULL))
                   AND d.verification_status NOT IN ('confirmed','expired','manual_review','mismatch','transaction_already_used')
                   AND (d.last_verified_at IS NULL OR d.last_verified_at<=%s -
                     CASE WHEN d.verification_status='provider_error' THEN interval '5 minutes' ELSE interval '1 minute' END)))
                 AND NOT EXISTS (SELECT 1 FROM public.manual_fulfillment_reviews r
                     WHERE r.order_id=o.id AND r.state IN ('pending','refund_uncertain'))""",
            (list(self.external_methods), _now(), _now(), _now(), _now()), limit)

    def _external_reconcile(self, payment_id):
        return getattr(self.lifecycle, 'refresh_background', self.lifecycle.refresh)(payment_id)

    def _reconcile(self, payment_id):
        payment = self.db.one('SELECT provider,merchant_ref FROM public.payments WHERE id=%s', (payment_id,))
        if payment is None:
            return
        self.db.execute("""INSERT INTO public.payment_events
            (provider,event_type,merchant_ref,provider_status,raw_payload,received_at,last_received_at,received_count)
            VALUES (%s,'reconciliation',%s,'candidate_poll','{}'::jsonb,%s,%s,1)""",
            (payment['provider'], payment['merchant_ref'], _now(), _now()))
        return getattr(self.lifecycle, 'refresh_background', self.lifecycle.refresh)(payment_id)

    def _cancellation_attempts(self, limit):
        if self.cancellations is None:
            return []
        return self.db.rows("""SELECT id FROM public.provider_cancellation_attempts
            WHERE provider='pakasir' AND state IN ('pending','retryable_failure','processing')
              AND (state='pending' OR started_at IS NULL
                   OR (state='processing' AND started_at<=%s)
                   OR (state='retryable_failure' AND started_at<=%s -
                       CASE WHEN attempt_count=1 THEN interval '30 seconds' ELSE interval '120 seconds' END))
            ORDER BY updated_at,id LIMIT %s""",
            (_now() - timedelta(seconds=120), _now(), limit))

    def _due_payments(self, limit):
        return self._payment_page(
            "expiry",
            """SELECT p.id FROM public.payments p JOIN public.orders o ON o.id=p.order_id
               WHERE o.order_status='pending_payment' AND o.payment_status='pending'
                 AND p.status='pending' AND o.expires_at<=%s""", (_now(),), limit)

    def _expire_due(self, payment_id):
        order = self.db.one('SELECT order_id FROM public.payments WHERE id=%s', (payment_id,))
        if order is not None:
            return self.lifecycle.orders.apply_pending_expiry(str(order['order_id']))

    def _next_navigation(self, attempted):
        rows = self.db.rows(
            "SELECT updates.update_id FROM django_candidate.navigation_updates updates "
            "WHERE updates.state='pending' " + self.processor.family_filter +
            " AND NOT (updates.update_id=ANY(%s))"
            " AND NOT EXISTS (SELECT 1 FROM django_candidate.navigation_updates earlier "
            "WHERE earlier.chat_id=updates.chat_id AND "
            "(earlier.state='processing' OR "
            "(earlier.state='pending' AND earlier.update_id<updates.update_id))) "
            "ORDER BY updates.update_id LIMIT 1", (attempted,),
        )
        return rows[0]["update_id"] if rows else None

    def _funding_events(self, limit):
        query = """SELECT e.id FROM public.store_balance_top_up_events e
            WHERE e.provider='klikqris' AND e.event_type='callback' AND e.processed_at IS NULL
            AND NOT EXISTS (SELECT 1 FROM public.store_balance_top_up_events p
                WHERE p.store_balance_top_up_id=e.store_balance_top_up_id
                AND p.event_type='reconciliation' AND p.provider_status='candidate_poll'
                AND p.received_at>%s - interval '24 seconds')"""
        if self._funding_cursor is not None:
            rows = self.db.rows(query + " AND e.id>%s ORDER BY e.id LIMIT %s",
                                (_now(), self._funding_cursor, limit))
            if rows:
                return [row['id'] for row in rows]
            self._funding_cursor = None
        return [row['id'] for row in self.db.rows(query + " ORDER BY e.id LIMIT %s", (_now(), limit))]
    def _funding_recoveries(self, limit):
        query = """SELECT t.id FROM public.store_balance_top_ups t
            WHERE t.provider=ANY(%s) AND t.provider IN ('klikqris','sakurupiah')
              AND t.status IN ('pending','payable','expired')
              AND t.provider_create_attempted_at IS NOT NULL
              AND t.provider_create_attempted_at<=%s
              AND NOT EXISTS (SELECT 1 FROM public.store_balance_top_up_events e
                  WHERE e.store_balance_top_up_id=t.id AND e.event_type='reconciliation'
                  AND e.provider_status='candidate_poll' AND e.received_at>%s - interval '24 seconds')"""
        cutoff = _now() - timedelta(seconds=10)
        if self._funding_recovery_cursor is not None:
            rows = self.db.rows(query + " AND t.id>%s ORDER BY t.id LIMIT %s",
                                (list(self.funding_providers), cutoff, _now(), self._funding_recovery_cursor, limit))
            if rows:
                return [row['id'] for row in rows]
            self._funding_recovery_cursor = None
        return [row['id'] for row in self.db.rows(query + " ORDER BY t.id LIMIT %s",
                                                  (list(self.funding_providers), cutoff, _now(), limit))]

    def _funding_expiries(self, limit):
        return [row['id'] for row in self.db.rows(
            """SELECT id FROM public.store_balance_top_ups WHERE provider=ANY(%s)
               AND status IN ('pending','payable','awaiting_confirmation')
               AND (expires_at<=%s OR provider_expires_at<=%s)
               ORDER BY LEAST(expires_at,provider_expires_at),id LIMIT %s""",
            (list(self.funding_providers), _now(), _now(), limit))]

    def _mark_funding_poll(self, identity):
        topup = self.funding.top_up_by_id(identity)
        if topup is None or topup['provider'] not in self.funding_providers:
            return
        now = _now()
        key = uuid.uuid4().hex
        self.db.execute("""INSERT INTO public.store_balance_top_up_events
            (store_balance_top_up_id,provider,event_type,merchant_ref,provider_status,raw_payload,
             idempotency_key,received_count,received_at,last_received_at,processed_at)
            VALUES (%s,%s,'reconciliation',%s,'candidate_poll','{}'::jsonb,%s,1,%s,%s,%s)""",
            (identity, topup['provider'], topup['merchant_ref'], key, now, now, now))

    def _recover_funding(self, identity):
        topup = self.funding.top_up_by_id(identity)
        if topup is None or topup['provider'] not in self.funding_providers:
            return
        if self.funding.expire_if_due(identity):
            return
        self._mark_funding_poll(identity)
        if topup['provider'] == 'klikqris':
            return self.funding_klik.recover(identity)
        if topup['provider'] == 'sakurupiah':
            return self.funding.check(topup)


    def sweep(self, limit):
        return self.sweep_bounded(limit, lambda function, *args: function(*args), lambda: False)

    def sweep_bounded(self, limit, bounded, stopped):
        result = {"hints": 0, "ambiguous": 0, "active": 0, "expiry": 0, "cancellations": 0, "failures": 0}
        expired_funding = set()
        if self.processor:
            result.update(navigation=0, recovered=0)

        def recover_navigation():
            if self.processor:
                result["recovered"] = bounded(self.processor.recover, limit)
            yield

        def navigation():
            if not self.processor:
                return
            attempted = []
            while len(attempted) < limit and not stopped():
                identity = bounded(self._next_navigation, attempted)
                if identity is None or stopped():
                    break
                attempted.append(identity)
                bounded(self.processor.process, identity)
                result["navigation"] += 1
                yield

        def payments(key, select, operation):
            identities = bounded(select, limit)
            result[key] = len(identities)
            for identity in identities:
                if stopped():
                    break
                self._payment_cursors[key] = identity
                try:
                    bounded(operation, identity)
                except Exception:
                    result["failures"] += 1
                yield

        def cancellations():
            for row in bounded(self._cancellation_attempts, limit):
                if stopped():
                    break
                try:
                    bounded(self.cancellations.process, row['id'])
                    result['cancellations'] += 1
                except Exception:
                    result['failures'] += 1
                yield

        def funding_events():
            for identity in bounded(self._funding_events, limit):
                if stopped():
                    break
                self._funding_cursor = identity
                try:
                    event = self.db.one('SELECT store_balance_top_up_id FROM public.store_balance_top_up_events WHERE id=%s', (identity,))
                    if event is not None:
                        bounded(self._mark_funding_poll, event['store_balance_top_up_id'])
                    bounded(self.funding_klik.consume_callback, identity)
                    result['funding_hints'] += 1
                except Exception:
                    result['failures'] += 1
                yield

        def funding_recoveries():
            for identity in bounded(self._funding_recoveries, limit):
                if stopped():
                    break
                self._funding_recovery_cursor = identity
                try:
                    if identity not in expired_funding:
                        bounded(self._recover_funding, identity)
                    result['funding_recoveries'] += 1
                except Exception:
                    result['failures'] += 1
                yield

        def funding_expiries():
            for identity in bounded(self._funding_expiries, limit):
                if stopped():
                    break
                try:
                    if bounded(self.funding.expire_if_due, identity):
                        expired_funding.add(identity)
                    result['funding_expiries'] += 1
                except Exception:
                    result['failures'] += 1
                yield

        phases = [
            iter(recover_navigation()),
            iter(navigation()),
            iter(payments("hints", self._pending_hint_payments, self.lifecycle.consume_callbacks)),
            iter(payments("ambiguous", self._ambiguous_payments,
                          getattr(self.lifecycle, 'recover_background', self.lifecycle.recover))),
            iter(payments("expiry", self._due_payments, self._expire_due)),
            iter(payments("active", self._active_payments, self._reconcile)),
            iter(cancellations()),
        ]
        if self.external_methods:
            phases.append(iter(payments("external", self._external_payments, self._external_reconcile)))
        if self.funding_klik is not None:
            result['funding_hints'] = 0
            phases.append(iter(funding_events()))
        if self.funding_providers:
            result['funding_recoveries'] = 0
            result['funding_expiries'] = 0
            phases.append(iter(funding_recoveries()))
            phases.append(iter(funding_expiries()))
        start = self._sweep_start
        self._sweep_start = (start + 1) % len(phases)
        phases = phases[start:] + phases[:start]
        while phases and not stopped():
            remaining = []
            for phase in phases:
                if stopped():
                    break
                try:
                    next(phase)
                except StopIteration:
                    continue
                remaining.append(phase)
            phases = remaining
        return result
