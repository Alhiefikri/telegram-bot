"""Read-only Laravel lifecycle and historical Order status projections."""
from collections.abc import Mapping
from dataclasses import dataclass


@dataclass(frozen=True)
class OrderLifecycleProjection:
    order_status: str
    payment_status: str | None
    is_paid: bool
    is_pending_payment: bool
    is_terminal: bool
    terminal_cause: str

    @classmethod
    def from_rows(cls, order: Mapping, payment: Mapping | None = None):
        """Match OrderLifecycleProjection::from without inferring a Payment."""
        order_status = order['order_status']
        payment_status = payment.get('status') if payment is not None else None
        expired = payment_status == 'expired' or order_status == 'expired'
        cancelled = order_status == 'cancelled'
        failed = payment_status == 'failed'
        return cls(
            order_status=order_status,
            payment_status=(payment_status if payment_status is not None
                            else order.get('payment_status')),
            is_paid=(payment_status == 'paid'
                     and order_status in ('processing', 'completed')),
            is_pending_payment=(payment_status == 'pending'
                                and order_status == 'pending_payment'),
            is_terminal=expired or cancelled or failed,
            terminal_cause=('expired' if expired else 'cancelled' if cancelled
                            else 'provider_failed' if failed else 'terminal'),
        )

    def history_status(self, *, receipt_sent=False):
        """Telegram history uses Order status, not a provider or latest Payment."""
        if self.payment_status == 'paid' and (
                self.order_status == 'completed' or receipt_sent):
            return 'delivered'
        return self.order_status


def paid_order_status(payment: Mapping, *, supplier_pending=False,
                      has_open_review=False, local_credentials_complete=False):
    """Status for a normal paid transition, never for a released/late Order.

    OrderStateMachine preserves Pakasir's historical completion rule. Other
    automatic rails need complete local credentials and no open review; manual
    completion remains an explicit operation. Pending suppliers take precedence.
    """
    if supplier_pending:
        return 'processing'
    provider = str(payment.get('provider') or '').lower()
    if provider == 'pakasir' or (provider != 'manual' and not has_open_review
                                 and local_credentials_complete):
        return 'completed'
    return 'processing'
