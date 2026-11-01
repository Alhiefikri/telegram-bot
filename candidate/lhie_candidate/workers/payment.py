"""Payment family: callback hints and ambiguous-create reconciliation only.

No worker creates a second invoice, expires an order, or settles from a
callback alone. Provider observations happen outside database transactions and
every settlement relocks/revalidates the original snapshot in the lifecycle.
"""

from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

from . import wakeup
from .contract import queue_spec


def _now():
    return datetime.now(ZoneInfo("Asia/Jakarta")).replace(tzinfo=None)


class PaymentHandler:
    def __init__(self, lifecycle, db):
        self.lifecycle = lifecycle
        self.db = db
        self.spec = queue_spec("payment")

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
        if kind != "payment.event":
            raise wakeup.InvalidWakeup("Invalid wakeup family")
        payment_id = self._payment_for_event(identity)
        if payment_id is None:
            return "ignored"
        return self.lifecycle.consume_callbacks(payment_id).state

    def _pending_hint_payments(self, limit):
        return [row["id"] for row in self.db.rows(
            """SELECT DISTINCT p.id FROM public.payment_events e
               JOIN public.payments p ON p.merchant_ref=e.merchant_ref
                 AND p.provider_reference=e.provider_reference
               WHERE e.provider='sakurupiah' AND e.event_type='callback'
                 AND e.processed_at IS NULL AND p.provider='sakurupiah' AND p.status='pending'
               ORDER BY p.id LIMIT %s""",
            (limit,),
        )]

    def _ambiguous_payments(self, limit):
        return [row["id"] for row in self.db.rows(
            """SELECT p.id FROM public.payments p JOIN public.orders o ON o.id=p.order_id
               WHERE p.provider='sakurupiah' AND p.status='pending'
                 AND p.provider_create_state='outcome_unknown'
                 AND p.provider_total_payment IS NOT NULL AND p.provider_total_payment > 0
                 AND p.provider_create_attempted_at IS NOT NULL AND p.provider_create_attempted_at < %s
                 AND o.order_status='pending_payment' AND o.payment_status='pending'
                 AND NOT EXISTS (SELECT 1 FROM public.manual_fulfillment_reviews r
                     WHERE r.order_id=o.id AND r.state IN ('pending','refund_uncertain'))
               ORDER BY p.provider_create_attempted_at,p.id LIMIT %s""",
            (_now() - timedelta(seconds=10), limit),
        )]

    def sweep(self, limit):
        hints = self._pending_hint_payments(limit)
        ambiguous = self._ambiguous_payments(limit)
        failures = 0
        for payment_id in hints:
            try:
                self.lifecycle.consume_callbacks(payment_id)
            except Exception:
                failures += 1
        for payment_id in ambiguous:
            try:
                self.lifecycle.recover(payment_id)
            except Exception:
                failures += 1
        return {"hints": len(hints), "ambiguous": len(ambiguous), "failures": failures}
