"""Payment family: checkout navigation, callback hints, and reconciliation.

Provider observations happen outside database transactions; settlement
relocks and revalidates the original payment snapshot.
"""

from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

from . import wakeup
from .contract import queue_spec


def _now():
    return datetime.now(ZoneInfo("Asia/Jakarta")).replace(tzinfo=None)


class PaymentHandler:
    def __init__(self, lifecycle, db, *, processor=None):
        self.lifecycle = lifecycle
        self.db = db
        self.processor = processor
        self.spec = queue_spec("payment")
        self._sweep_start = 0
        self._payment_cursors = {"hints": None, "ambiguous": None, "expiry": None}

    def _payment_for_event(self, event_id):
        rows = self.db.rows(
            """SELECT p.id FROM public.payment_events e
               JOIN public.payments p ON p.merchant_ref=e.merchant_ref
                 AND (p.provider_reference IS NULL OR p.provider_reference=e.provider_reference)
               WHERE e.id=%s AND e.provider='sakurupiah' AND p.provider='sakurupiah'
               ORDER BY p.id LIMIT 2""",
            (event_id,),
        )
        return rows[0]["id"] if len(rows) == 1 else None

    def handle(self, raw):
        kind, identity = wakeup.decode(self.spec.wakeup_queue, raw)
        if kind == "payment.navigation":
            if self.processor is None:
                raise wakeup.InvalidWakeup("Navigation processor unavailable")
            return self.processor.process(identity)
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
                 AND p.provider_reference=e.provider_reference
               WHERE e.provider='sakurupiah' AND e.event_type='callback'
                 AND e.processed_at IS NULL AND p.provider='sakurupiah'
                 AND p.status NOT IN ('paid', 'refunded')""", (), limit)

    def _ambiguous_payments(self, limit):
        return self._payment_page(
            "ambiguous",
            """SELECT p.id FROM public.payments p JOIN public.orders o ON o.id=p.order_id
               WHERE p.provider='sakurupiah' AND p.status='pending'
                 AND p.provider_create_state='outcome_unknown'
                 AND p.provider_total_payment IS NOT NULL AND p.provider_total_payment > 0
                 AND p.provider_create_attempted_at IS NOT NULL AND p.provider_create_attempted_at < %s
                 AND o.order_status='pending_payment' AND o.payment_status='pending'
                 AND NOT EXISTS (SELECT 1 FROM public.manual_fulfillment_reviews r
                     WHERE r.order_id=o.id AND r.state IN ('pending','refund_uncertain'))""",
            (_now() - timedelta(seconds=10),), limit)

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

    def sweep(self, limit):
        return self.sweep_bounded(limit, lambda function, *args: function(*args), lambda: False)

    def sweep_bounded(self, limit, bounded, stopped):
        result = {"hints": 0, "ambiguous": 0, "expiry": 0, "failures": 0}
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

        phases = [
            iter(recover_navigation()),
            iter(navigation()),
            iter(payments("hints", self._pending_hint_payments, self.lifecycle.consume_callbacks)),
            iter(payments("ambiguous", self._ambiguous_payments, self.lifecycle.recover)),
            iter(payments("expiry", self._due_payments, self._expire_due)),
        ]
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
