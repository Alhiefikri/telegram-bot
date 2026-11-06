"""Canonical USDT purchase postings inside caller-owned PostgreSQL transactions."""
from dataclasses import dataclass
from uuid import UUID

from lhie_candidate.checkout.db import Database
from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.checkout.refunds import _fingerprint
from lhie_candidate.domain.money import MAX_UNITS, require_integer
from .reconciliation import account_health


@dataclass(frozen=True, slots=True)
class ApprovedUsdtReversal:
    """Trusted caller's existing approved reference; this is not an approval workflow."""
    customer_id: int
    amount_micro: int
    original_entry_id: int
    authorization_reference: str

    def __post_init__(self):
        require_integer(self.customer_id, minimum=1)
        require_integer(self.amount_micro, minimum=1)
        require_integer(self.original_entry_id, minimum=1)
        if (type(self.authorization_reference) is not str or not self.authorization_reference.strip()
                or len(self.authorization_reference) > 64):
            raise ValueError('Invalid reversal authorization reference')

class UsdtLedger:
    def __init__(self, using="default"):
        self.db = Database(using)

    def _lock_account(self, customer_id):
        self.db.require_transaction()
        customer_id = require_integer(customer_id, minimum=1)
        account = self.db.one("""SELECT id,customer_id,currency,balance_micro,version,last_entry_id,frozen_reason
            FROM public.customer_usdt_balance_accounts WHERE customer_id=%s FOR UPDATE""", (customer_id,))
        if account is None:
            self.db.execute("""INSERT INTO public.customer_usdt_balance_accounts
                (customer_id,currency,balance_micro,version,created_at,updated_at)
                VALUES (%s,'USDT',0,0,CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                    CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta') ON CONFLICT (customer_id) DO NOTHING""", (customer_id,))
            account = self.db.one("""SELECT id,customer_id,currency,balance_micro,version,last_entry_id,frozen_reason
                FROM public.customer_usdt_balance_accounts WHERE customer_id=%s FOR UPDATE""", (customer_id,))
        if account is None:
            raise CheckoutError("usdt_balance_account_unavailable")
        return account

    def lock_for_purchase(self, customer_id):
        account = self._lock_account(customer_id)
        self._assert_mutable(account)
        return account

    def _assert_mutable(self, account):
        if account['frozen_reason'] is not None:
            raise CheckoutError('usdt_balance_frozen')
        health = account_health(self.db, account_id=account['id'])
        if health is None or health['issues']:
            raise CheckoutError('usdt_balance_projection_mismatch')

    def debit_purchase(self, customer_id, order_id):
        """Debit the locked, snapshotted Order/Payment, or resolve its exact replay."""
        account = self._lock_account(customer_id)
        try:
            if not isinstance(order_id, (str, UUID)):
                raise ValueError()
            order_id = str(UUID(str(order_id)))
        except ValueError as error:
            raise CheckoutError('usdt_order_identity_invalid') from error
        order = self.db.one("""SELECT id,customer_id,currency,order_status,payment_status
            FROM public.orders WHERE id=%s FOR UPDATE""", (order_id,))
        if order is None or order['customer_id'] != customer_id or order['currency'] != 'USDT':
            raise CheckoutError('usdt_order_identity_invalid')
        payments = self.db.rows("""SELECT id,order_id,provider,method,amount,currency,status,paid_at
            FROM public.payments WHERE order_id=%s ORDER BY id FOR UPDATE""", (order_id,))
        if len(payments) != 1:
            raise CheckoutError('usdt_payment_snapshot_invalid')
        payment = payments[0]
        if (str(payment['order_id']) != order_id or payment['provider'] != 'usdt_balance'
                or payment['method'] != 'USDT_BALANCE' or payment['currency'] != 'USDT'):
            raise CheckoutError('usdt_payment_snapshot_invalid')
        amount = require_integer(payment['amount'], minimum=1)
        items = self.db.rows("""SELECT quantity,usdt_unit_price,usdt_subtotal FROM public.order_items
            WHERE order_id=%s ORDER BY id FOR UPDATE""", (order_id,))
        total = 0
        if not items:
            raise CheckoutError('usdt_payment_snapshot_invalid')
        for item in items:
            try:
                quantity = require_integer(item['quantity'], minimum=1)
                unit = require_integer(item['usdt_unit_price'])
                subtotal = require_integer(item['usdt_subtotal'])
            except ValueError as error:
                raise CheckoutError('usdt_payment_snapshot_invalid') from error
            if unit > MAX_UNITS // quantity or unit * quantity != subtotal or subtotal > MAX_UNITS - total:
                raise CheckoutError('usdt_payment_snapshot_invalid')
            total += subtotal
        if total != amount:
            raise CheckoutError('usdt_payment_snapshot_invalid')
        payment_id = str(payment['id'])
        fingerprint = _fingerprint('purchase', 'purchase_debit', customer_id, amount,
                                   order_id=order_id, payment_id=payment_id)
        key = f'order:{order_id}:usdt:debit'
        claims = self.db.rows("""SELECT * FROM public.customer_usdt_balance_entries
            WHERE (customer_usdt_balance_account_id=%s AND idempotency_key=%s)
               OR (source_type='order' AND source_id=%s AND kind='purchase_debit') ORDER BY id""",
            (account['id'], key, order_id))
        if len(claims) > 1:
            raise CheckoutError('usdt_balance_identity_conflict')
        pending = (order['order_status'] == 'pending_payment' and order['payment_status'] == 'pending'
                   and payment['status'] == 'pending' and payment['paid_at'] is None)
        settled = (payment['paid_at'] is not None and
                   ((order['payment_status'] == 'paid' and payment['status'] == 'paid'
                     and order['order_status'] in ('processing', 'completed')) or
                    (order['payment_status'] == 'refunded' and payment['status'] == 'refunded'
                     and order['order_status'] in ('cancelled', 'completed'))))
        if claims:
            entry = claims[0]
            event = self.db.one("""SELECT currency,amount_micro,debit_account,credit_account,source_fingerprint
                FROM public.customer_usdt_balance_accounting_events WHERE customer_usdt_balance_entry_id=%s""", (entry['id'],))
            if (entry['customer_usdt_balance_account_id'] != account['id'] or entry['kind'] != 'purchase_debit'
                    or entry['amount_micro'] != amount or entry['source_type'] != 'order'
                    or entry['source_id'] != order_id or entry['request_fingerprint'] != fingerprint
                    or entry['original_entry_id'] is not None or entry['refund_order_id'] is not None
                    or event is None or event['currency'] != 'USDT' or event['amount_micro'] != amount
                    or event['debit_account'] != 'customer_usdt_liability' or event['credit_account'] != 'usdt_sale'
                    or event['source_fingerprint'] != fingerprint):
                raise CheckoutError('usdt_balance_identity_conflict')
            if not (pending or settled):
                raise CheckoutError('usdt_purchase_state_invalid')
            return entry
        if not pending:
            raise CheckoutError('usdt_purchase_state_invalid')
        self._assert_mutable(account)
        balance = require_integer(account['balance_micro'])
        version = require_integer(account['version'])
        if version == MAX_UNITS:
            raise CheckoutError('balance_overflow')
        if amount > balance:
            raise CheckoutError('insufficient_usdt_balance')
        entry = self.db.one("""INSERT INTO public.customer_usdt_balance_entries
            (customer_usdt_balance_account_id,account_sequence,kind,amount_micro,balance_after_micro,
             source_type,source_id,idempotency_key,request_fingerprint,created_at)
            VALUES (%s,%s,'purchase_debit',%s,%s,'order',%s,%s,%s,
                    CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta') ON CONFLICT DO NOTHING RETURNING *""",
            (account['id'], version + 1, amount, balance - amount, order_id, key, fingerprint))
        if entry is None:
            raise CheckoutError('usdt_balance_identity_conflict')
        self.db.execute("""INSERT INTO public.customer_usdt_balance_accounting_events
            (customer_usdt_balance_entry_id,currency,amount_micro,debit_account,credit_account,source_fingerprint,created_at)
            VALUES (%s,'USDT',%s,'customer_usdt_liability','usdt_sale',%s,
                    CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')""", (entry['id'], amount, fingerprint))
        self.db.execute("""UPDATE public.customer_usdt_balance_accounts SET balance_micro=%s,version=%s,
            last_entry_id=%s,updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s""",
            (balance - amount, version + 1, entry['id'], account['id']))
        return entry

    def reverse_approved_entry(self, source, idempotency_key):
        """Post a linked full reversal; the caller owns approval and transaction boundaries."""
        if not isinstance(source, ApprovedUsdtReversal):
            raise ValueError('A typed approved reversal is required')
        if type(idempotency_key) is not str or not idempotency_key.strip() or len(idempotency_key) > 191:
            raise ValueError('Invalid idempotency key')
        account = self._lock_account(source.customer_id)
        fingerprint = _fingerprint('approved_reversal', 'reversal_debit', source.customer_id,
            source.amount_micro, original_entry_id=source.original_entry_id,
            authorization_reference=source.authorization_reference)
        source_id = str(source.original_entry_id)
        claims = self.db.rows("""SELECT * FROM public.customer_usdt_balance_entries
            WHERE (customer_usdt_balance_account_id=%s AND idempotency_key=%s)
               OR (source_type='approved_reversal' AND source_id=%s AND kind='reversal_debit')
            ORDER BY id""", (account['id'], idempotency_key, source_id))
        if len(claims) > 1:
            raise CheckoutError('usdt_balance_identity_conflict')
        if claims:
            entry = claims[0]
            if (entry['customer_usdt_balance_account_id'] != account['id']
                    or entry['kind'] != 'reversal_debit' or entry['amount_micro'] != source.amount_micro
                    or entry['source_type'] != 'approved_reversal' or entry['source_id'] != source_id
                    or entry['original_entry_id'] != source.original_entry_id
                    or entry['request_fingerprint'] != fingerprint):
                raise CheckoutError('usdt_balance_identity_conflict')
            return entry
        self._assert_mutable(account)
        original = self.db.one("""SELECT id,customer_usdt_balance_account_id,kind,amount_micro
            FROM public.customer_usdt_balance_entries WHERE id=%s""", (source.original_entry_id,))
        if (original is None or original['customer_usdt_balance_account_id'] != account['id']
                or original['kind'] not in ('funding_credit', 'refund_credit')
                or original['amount_micro'] != source.amount_micro):
            raise CheckoutError('usdt_reversal_original_invalid')
        if self.db.one("""SELECT id FROM public.customer_usdt_balance_entries
            WHERE original_entry_id=%s AND kind='reversal_debit'""", (source.original_entry_id,)):
            raise CheckoutError('usdt_reversal_already_posted')
        event = self.db.one("""SELECT debit_account,credit_account FROM public.customer_usdt_balance_accounting_events
            WHERE customer_usdt_balance_entry_id=%s""", (source.original_entry_id,))
        if event is None:
            raise CheckoutError('usdt_reversal_accounting_missing')
        balance, version = require_integer(account['balance_micro']), require_integer(account['version'])
        if version == MAX_UNITS:
            raise CheckoutError('balance_overflow')
        if balance < source.amount_micro:
            raise CheckoutError('insufficient_usdt_balance')
        entry = self.db.one("""INSERT INTO public.customer_usdt_balance_entries
            (customer_usdt_balance_account_id,account_sequence,kind,amount_micro,balance_after_micro,
             source_type,source_id,idempotency_key,request_fingerprint,original_entry_id,created_at)
            VALUES (%s,%s,'reversal_debit',%s,%s,'approved_reversal',%s,%s,%s,%s,
                CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta') ON CONFLICT DO NOTHING RETURNING *""",
            (account['id'], version + 1, source.amount_micro, balance - source.amount_micro,
             source_id, idempotency_key, fingerprint, source.original_entry_id))
        if entry is None:
            raise CheckoutError('usdt_balance_identity_conflict')
        self.db.execute("""INSERT INTO public.customer_usdt_balance_accounting_events
            (customer_usdt_balance_entry_id,currency,amount_micro,debit_account,credit_account,source_fingerprint,created_at)
            VALUES (%s,'USDT',%s,%s,%s,%s,CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')""",
            (entry['id'], source.amount_micro, event['credit_account'], event['debit_account'], fingerprint))
        self.db.execute("""UPDATE public.customer_usdt_balance_accounts SET balance_micro=%s,version=%s,
            last_entry_id=%s,updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s""",
            (balance - source.amount_micro, version + 1, entry['id'], account['id']))
        return entry
