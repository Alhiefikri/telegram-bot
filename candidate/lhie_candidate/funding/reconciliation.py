"""Read-only inspection of the canonical IDR projection and immutable entries."""
from dataclasses import dataclass
from decimal import Decimal

from lhie_candidate.checkout.db import Database
from lhie_candidate.domain.money import require_integer


@dataclass(frozen=True, slots=True)
class ProjectionMismatch:
    customer_id: int
    projected_balance: int
    ledger_balance: int
    cursor_balance: int | None
    unknown_entries: int


@dataclass(frozen=True, slots=True)
class ReconciliationReport:
    accounts_inspected: int
    mismatches: tuple[ProjectionMismatch, ...]

    @property
    def consistent(self):
        return not self.mismatches


class StoreBalanceReconciliation:
    def __init__(self, using='default'):
        self.db = Database(using)

    def inspect(self, customer_id=None, limit=100):
        require_integer(limit, minimum=1)
        if customer_id is not None:
            require_integer(customer_id, minimum=1)
            if self.db.one('SELECT id FROM public.customers WHERE id = %s', (customer_id,)) is None:
                raise ValueError('Customer not found')
        rows = self.db.rows(
            """SELECT a.customer_id, a.balance,
                      COALESCE(h.ledger_balance, 0) AS ledger_balance,
                      COALESCE(h.unknown_entries, 0) AS unknown_entries,
                      (SELECT e.balance_after FROM public.store_balance_entries e
                       WHERE e.customer_balance_account_id = a.id ORDER BY e.id DESC LIMIT 1)
                          AS cursor_balance
               FROM (SELECT id, customer_id, balance FROM public.customer_balance_accounts
                     WHERE (%s::bigint IS NULL OR customer_id = %s)
                     ORDER BY customer_id LIMIT %s) a
               LEFT JOIN LATERAL (
                   SELECT SUM(CASE
                       WHEN kind IN ('purchase_debit', 'admin_adjustment_reversal') THEN -amount
                       WHEN kind IN ('admin_adjustment_credit', 'top_up_credit', 'refund_credit',
                                     'owner_compensation_credit') THEN amount
                       ELSE 0 END) AS ledger_balance,
                       COUNT(*) FILTER (WHERE kind NOT IN
                           ('purchase_debit', 'admin_adjustment_reversal', 'admin_adjustment_credit',
                            'top_up_credit', 'refund_credit', 'owner_compensation_credit')) AS unknown_entries
                   FROM public.store_balance_entries WHERE customer_balance_account_id = a.id
               ) h ON TRUE ORDER BY a.customer_id""",
            (customer_id, customer_id, limit),
        )
        mismatches = []
        for row in rows:
            ledger = row['ledger_balance']
            if isinstance(ledger, Decimal) and ledger == ledger.to_integral_value():
                ledger = int(ledger)
            if type(ledger) is not int:
                raise ValueError('Invalid ledger sum')
            cursor = row['cursor_balance']
            if (row['balance'] != ledger or row['balance'] != (cursor if cursor is not None else 0)
                    or row['unknown_entries']):
                mismatches.append(ProjectionMismatch(
                    row['customer_id'], row['balance'], ledger, cursor, row['unknown_entries']))
        return ReconciliationReport(len(rows), tuple(mismatches))
