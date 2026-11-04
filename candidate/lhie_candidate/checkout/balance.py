"""IDR postings on the canonical Store Balance ledger, inside caller transactions."""

from decimal import Decimal
from uuid import UUID

from lhie_candidate.domain.money import MAX_UNITS, require_integer

from .db import Database
from .errors import CheckoutError


class StoreBalanceLedger:
    def __init__(self, using="default"):
        self.db = Database(using)

    def lock_for_purchase(self, customer_id):
        self.db.require_transaction()
        customer_id = require_integer(customer_id, minimum=1)
        account = self.db.one(
            "SELECT id,balance FROM public.customer_balance_accounts WHERE customer_id = %s FOR UPDATE",
            (customer_id,),
        )
        if account is None:
            self.db.execute(
                """INSERT INTO public.customer_balance_accounts
                       (customer_id, balance, created_at, updated_at)
                   VALUES (%s, 0, CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta', CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')
                   ON CONFLICT (customer_id) DO NOTHING""",
                (customer_id,),
            )
            account = self.db.one(
                "SELECT id,balance FROM public.customer_balance_accounts WHERE customer_id = %s FOR UPDATE",
                (customer_id,),
            )
        if account is None:
            raise CheckoutError("balance_account_unavailable")
        history = self.db.one(
            """SELECT COALESCE(SUM(CASE
                         WHEN kind IN ('purchase_debit', 'admin_adjustment_reversal')
                         THEN -amount ELSE amount END), 0) AS ledger_balance,
                      (SELECT balance_after FROM public.store_balance_entries
                       WHERE customer_balance_account_id = %s ORDER BY id DESC LIMIT 1)
                          AS cursor_balance
               FROM public.store_balance_entries
               WHERE customer_balance_account_id = %s""",
            (account["id"], account["id"]),
        )
        balance = require_integer(account["balance"])
        ledger_balance = history["ledger_balance"]
        if isinstance(ledger_balance, Decimal):
            if ledger_balance != ledger_balance.to_integral_value():
                raise CheckoutError("balance_projection_mismatch")
            ledger_balance = int(ledger_balance)
        cursor_balance = history["cursor_balance"]
        if cursor_balance is None:
            cursor_balance = 0
        if (type(ledger_balance) is not int or not 0 <= ledger_balance <= MAX_UNITS
                or type(cursor_balance) is not int or not 0 <= cursor_balance <= MAX_UNITS
                or balance != ledger_balance or balance != cursor_balance):
            raise CheckoutError("balance_projection_mismatch")
        return account

    def debit_purchase(self, customer_id, order_id):
        account = self.lock_for_purchase(customer_id)
        order, payment, amount = self._snapshot(customer_id, order_id)
        source_id = str(order["id"])
        key = f"order:{source_id}:debit"
        existing = self._find_entry(account, "purchase_debit", amount, source_id, key)
        pending = (order["order_status"] == "pending_payment"
                   and order["payment_status"] == "pending"
                   and payment["status"] == "pending" and payment["paid_at"] is None)
        settled = self._refund_state(order, payment)
        if existing is not None:
            if not (pending or settled):
                raise CheckoutError("balance_purchase_state_invalid")
            return existing
        if not pending:
            raise CheckoutError("balance_purchase_state_invalid")
        return self._post(account, "purchase_debit", amount, source_id, key)

    def credit_top_up(self, customer_id, top_up_id, amount, key):
        """Credit one verified top-up under the canonical account lock."""
        self.db.require_transaction()
        source_id = str(top_up_id)
        if not source_id:
            raise CheckoutError("balance_top_up_identity_invalid")
        account = self.lock_for_top_up(customer_id)
        existing = self._find_source_entry(
            account, "top_up_credit", require_integer(amount, minimum=1),
            "balance_top_up", source_id, key)
        if existing is not None:
            return existing
        return self._post(account, "top_up_credit",
                          require_integer(amount, minimum=1), source_id, key,
                          source_type="balance_top_up")

    def lock_for_top_up(self, customer_id):
        """Lock the account and require only the newest cursor to agree.

        PHP ``creditTopUp`` intentionally skips the full-history scan; a
        mismatched cursor still blocks mutation, while independent operator
        reconciliation remains the full-history check.
        """
        self.db.require_transaction()
        customer_id = require_integer(customer_id, minimum=1)
        account = self.db.one(
            "SELECT id,balance FROM public.customer_balance_accounts WHERE customer_id = %s FOR UPDATE",
            (customer_id,))
        if account is None:
            self.db.execute(
                """INSERT INTO public.customer_balance_accounts
                       (customer_id, balance, created_at, updated_at)
                   VALUES (%s, 0, CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                           CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')
                   ON CONFLICT (customer_id) DO NOTHING""",
                (customer_id,))
            account = self.db.one(
                "SELECT id,balance FROM public.customer_balance_accounts WHERE customer_id = %s FOR UPDATE",
                (customer_id,))
        if account is None:
            raise CheckoutError("balance_account_unavailable")
        latest = self.db.one(
            """SELECT balance_after FROM public.store_balance_entries
               WHERE customer_balance_account_id = %s ORDER BY id DESC LIMIT 1""",
            (account["id"],))
        balance = require_integer(account["balance"])
        expected = 0 if latest is None else require_integer(latest["balance_after"])
        if balance != expected:
            raise CheckoutError("balance_projection_mismatch")
        return account

    def refund_order(self, customer_id, order_id):
        """Post the original IDR debit remainder through the refund authority."""
        from .refunds import OrderRefunds

        account = dict(self.lock_for_purchase(customer_id),
                       customer_id=customer_id, provider="store_balance")
        order, payment, _ = self._snapshot(customer_id, order_id)
        return OrderRefunds(self.db.using).apply_locked(
            order, payment, account=account).entry

    def _snapshot(self, customer_id, order_id):
        if not isinstance(order_id, (str, UUID)):
            raise CheckoutError("balance_order_identity_invalid")
        try:
            source_id = str(UUID(str(order_id)))
        except ValueError as error:
            raise CheckoutError("balance_order_identity_invalid") from error
        order = self.db.one(
            """SELECT id, customer_id, total_amount, currency, order_status, payment_status
               FROM public.orders WHERE id = %s FOR UPDATE""",
            (source_id,),
        )
        if order is None or order["customer_id"] != customer_id:
            raise CheckoutError("balance_order_identity_invalid")
        payments = self.db.rows(
            """SELECT id, order_id, provider, method, amount, currency, status, paid_at
               FROM public.payments WHERE order_id = %s ORDER BY id FOR UPDATE""",
            (source_id,),
        )
        if len(payments) != 1:
            raise CheckoutError("balance_payment_snapshot_invalid")
        payment = payments[0]
        amount = require_integer(order["total_amount"], minimum=1)
        payment_amount = require_integer(payment["amount"], minimum=1)
        if (order["currency"] != "IDR" or payment["currency"] != "IDR"
                or payment["provider"] != "store_balance"
                or payment["method"] != "STORE_BALANCE"
                or str(payment["order_id"]) != source_id or payment_amount != amount):
            raise CheckoutError("balance_payment_snapshot_invalid")
        return order, payment, amount

    @staticmethod
    def _refund_state(order, payment):
        paid = (order["payment_status"] == "paid" and payment["status"] == "paid"
                and order["order_status"] in ("processing", "completed"))
        refunded = (order["payment_status"] == "refunded" and payment["status"] == "refunded"
                    and order["order_status"] in ("cancelled", "completed"))
        return paid or refunded


    def _find_entry(self, account, kind, amount, source_id, key):
        return self._find_source_entry(account, kind, amount, "order", source_id, key)

    def _find_source_entry(self, account, kind, amount, source_type, source_id, key,
                           refund_order_id=None):
        entries = self.db.rows(
            """SELECT * FROM public.store_balance_entries
               WHERE (customer_balance_account_id = %s AND idempotency_key = %s)
                  OR (source_type = %s AND source_id = %s AND kind = %s)
               ORDER BY id""",
            (account["id"], key, source_type, source_id, kind),
        )
        if len(entries) > 1:
            raise CheckoutError("balance_identity_conflict")
        if not entries:
            return None
        entry = entries[0]
        if (entry["customer_balance_account_id"] != account["id"]
                or entry["kind"] != kind or entry["amount"] != amount
                or entry["source_type"] != source_type or entry["source_id"] != source_id
                or str(entry["refund_order_id"] or "") != str(refund_order_id or "")):
            raise CheckoutError("balance_identity_conflict")
        require_integer(entry["amount"], minimum=1)
        require_integer(entry["balance_after"])
        return entry

    def _post(self, account, kind, amount, source_id, key, source_type="order",
              refund_order_id=None):
        amount = require_integer(amount, minimum=1)
        balance = require_integer(account["balance"])
        if kind == "purchase_debit":
            if amount > balance:
                raise CheckoutError("insufficient_store_balance")
            balance_after = balance - amount
        else:
            if amount > MAX_UNITS - balance:
                raise CheckoutError("balance_overflow")
            balance_after = balance + amount
        entry = self.db.one(
            """INSERT INTO public.store_balance_entries
                   (customer_balance_account_id, kind, amount, balance_after,
                    source_type, source_id, idempotency_key, created_at, refund_order_id)
               VALUES (%s, %s, %s, %s, %s, %s, %s, CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta', %s)
               ON CONFLICT DO NOTHING RETURNING *""",
            (account["id"], kind, amount, balance_after, source_type, source_id, key,
             refund_order_id),
        )
        if entry is None:
            existing = self._find_source_entry(account, kind, amount, source_type, source_id, key,
                                               refund_order_id=refund_order_id)
            if existing is None:
                raise CheckoutError("balance_identity_conflict")
            return existing
        self.db.execute(
            """UPDATE public.customer_balance_accounts
               SET balance = %s, updated_at = CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id = %s""",
            (balance_after, account["id"]),
        )
        return entry
