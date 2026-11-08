"""Closed family policy and explicit disposable recipient admission."""
from dataclasses import dataclass
import re


BROADCAST = 'admin.broadcast_recipient'
STOCK = 'stock.notification_recipient'
PRICE = 'price_change.notification_recipient'
PROOF = 'sales_proof.post'
MANUAL = 'admin.manual_payment_proof'
ADJUSTMENT = 'balance.adjustment_notice'
ORDER_TYPES = ('admin.order_pending_notification', 'admin.order_paid_notification',
               'admin.order_expired_notification', 'admin.order_review_notification')
LIMITS = {BROADCAST: 5, STOCK: 5, PRICE: 5, PROOF: 3, MANUAL: 3, ADJUSTMENT: 3,
          **{kind: 3 for kind in ORDER_TYPES}}
TERMINAL_CODES = frozenset({'notification_not_eligible', 'notification_render_invalid',
                           'legacy_notification_terminal'})


class NotEligible(ValueError):
    def __init__(self):
        super().__init__('Notification is not eligible')


@dataclass(frozen=True, slots=True)
class TestRecipients:
    """Constructed by trusted composition, never from an HTTP/job payload.

    An empty set is disabled, not an unrestricted audience. Local SMTP never
    leaves the P01 sink, but addresses must additionally be reserved test names.
    """
    telegram: frozenset[str] = frozenset()

    def __post_init__(self):
        if (type(self.telegram) is not frozenset or len(self.telegram) > 100
                or any(not isinstance(chat, str) or not re.fullmatch(r'-?[1-9][0-9]{0,18}', chat)
                       for chat in self.telegram)):
            raise ValueError('Invalid disposable recipient admission')

    def permits(self, chat):
        return str(chat) in self.telegram

    def mail(self, address):
        if (not isinstance(address, str) or len(address) > 254
                or not re.fullmatch(r'[A-Za-z0-9._+\-]+@(?:[A-Za-z0-9\-]+\.)+invalid', address)):
            raise NotEligible()
        return address
