"""Owner-bound IDR adjustment previews and linked full reversals."""
import hashlib
import json
import re
from datetime import timedelta
from uuid import UUID, uuid4

from django.db import DatabaseError, connections, transaction

from ..auth.types import legacy_now
from ..checkout.balance import StoreBalanceLedger
from ..checkout.db import Database
from ..checkout.errors import CheckoutError
from ..compat.identity import sha256_digest
from ..domain.money import MAX_UNITS
from .password import AdjustmentRejected, PasswordConfirmation, bound_request, owner, record_notice


_CATEGORIES = {'owner_attested', 'promotional_grant', 'correction'}
_COUNTERPART = {'owner_attested': 'owner_attested_adjustment_suspense',
                'promotional_grant': 'promotional_expense', 'correction': 'correction_suspense'}
_KINDS = {**{category: 'admin_adjustment_credit' for category in _CATEGORIES},
          'funded_transfer': 'admin_adjustment_credit', 'top_up_recovery': 'owner_compensation_credit',
          'refund_recovery': 'refund_credit'}


def _uuid(value):
    try:
        return str(UUID(str(value)))
    except (ValueError, TypeError, AttributeError):
        raise AdjustmentRejected('invalid_original_adjustment') from None


def _positive(value, code):
    if type(value) not in (int, str) or not re.fullmatch(r'[1-9][0-9]{0,18}', str(value)) or int(value) > MAX_UNITS:
        raise AdjustmentRejected(code)
    return int(value)


class BalanceAdjustments:
    def __init__(self, *, admin_chat_id, using='default', encrypter=None):
        self.using = using
        self.db = Database(using)
        self.encrypter = encrypter
        self.ledger = StoreBalanceLedger(using)
        self.passwords = PasswordConfirmation(admin_chat_id=admin_chat_id, using=using)
        self.admin_chat_id = admin_chat_id

    def _run(self, operation):
        connection = connections[self.using]
        if connection.vendor != 'postgresql' or connection.in_atomic_block or not connection.get_autocommit():
            raise AdjustmentRejected('independent_transaction_required')
        for attempt in range(5):
            try:
                with transaction.atomic(using=self.using):
                    return operation()
            except DatabaseError as error:
                if getattr(error.__cause__, 'sqlstate', None) not in ('40001', '40P01') or attempt == 4:
                    raise

    def _customer(self, telegram_id):
        if not isinstance(telegram_id, str):
            raise AdjustmentRejected('invalid_telegram_id')
        _positive(telegram_id, 'invalid_telegram_id')
        customer = self.db.one('SELECT id,telegram_id,username,locale FROM public.customers WHERE telegram_id=%s', (telegram_id,))
        if customer is None:
            raise AdjustmentRejected('customer_not_registered')
        return customer

    def _audit(self, action, actor_id, preview, extra=None):
        values = {key: str(preview[key]) if key in ('id', 'source_id') and preview[key] is not None else preview[key]
                  for key in ('customer_id', 'owner_user_id', 'currency', 'amount', 'reason', 'category', 'source_id', 'fingerprint', 'state', 'ledger_entry_id')}
        if extra:
            values.update(extra)
        self.db.execute('''INSERT INTO public.audit_logs(user_id,action,subject_type,subject_id,new_values,created_at)
            VALUES (%s,%s,'App\\Models\\BalanceAdjustmentRequest',%s,%s,%s)''',
            (actor_id, action, str(preview['id']), json.dumps(values), legacy_now()))

    def _persist(self, request, customer, amount, reason, category, source_id=None, receipt=None):
        actor = owner(request, self.db)
        now = legacy_now()
        attrs = dict(id=str(uuid4()), customer_id=customer['id'], telegram_id=str(customer['telegram_id']),
                     username=customer['username'], owner_user_id=actor['id'],
                     session_binding=sha256_digest(request.auth_session.token), credential_binding=sha256_digest(actor['password']),
                     mode='single_owner', currency='IDR', amount=amount, reason=reason, category=category,
                     source_id=source_id, receipt_fingerprint=None, version=1, state='pending',
                     expires_at=(now + timedelta(minutes=5)).isoformat())
        if receipt is not None:
            attrs['receipt_fingerprint'] = receipt.bind(customer['id'], amount, attrs['id']).fingerprint()
        fingerprint = hashlib.sha256(json.dumps(attrs, ensure_ascii=True, separators=(',', ':')).encode()).hexdigest()
        def persist():
            fresh = owner(request, self.db, lock=True)
            if fresh['password'] != actor['password']:
                raise AdjustmentRejected('credentials_changed')
            row = self.db.one('''INSERT INTO public.balance_adjustment_requests
                (id,customer_id,telegram_id,username,owner_user_id,session_binding,credential_binding,mode,currency,
                 amount,reason,category,source_id,receipt_fingerprint,version,state,expires_at,fingerprint,created_at,updated_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING *''',
                (*attrs.values(), fingerprint, now, now))
            self._audit('balance.adjustment.requested', actor['id'], row)
            return row
        return self._run(persist)

    def request(self, request, telegram_id, amount, reason, category='owner_attested', source_id=None, receipt=None):
        owner(request, self.db)
        customer = self._customer(telegram_id)
        amount = _positive(amount, 'invalid_amount_or_reason')
        if not isinstance(reason, str):
            raise AdjustmentRejected('invalid_amount_or_reason')
        reason = reason.strip()
        if not reason or len(reason) > 500 or re.search(r'[\x00-\x1f\x7f]', reason):
            raise AdjustmentRejected('invalid_amount_or_reason')
        from .receipt import VerifiedAdjustmentReceipt, VerifiedBscAdjustmentReceipt
        if category == 'funded_transfer':
            if type(receipt) not in (VerifiedAdjustmentReceipt, VerifiedBscAdjustmentReceipt) or source_id is not None:
                raise AdjustmentRejected('verified_receipt_required')
        elif receipt is not None:
            raise AdjustmentRejected('unexpected_receipt')
        if category == 'top_up_recovery':
            if not isinstance(source_id, str):
                raise AdjustmentRejected('invalid_original_top_up')
            _positive(source_id, 'invalid_original_top_up')
            from .compensation import TOPUP_COLUMNS, eligible
            topup = self.db.one('SELECT ' + TOPUP_COLUMNS + ' FROM public.store_balance_top_ups WHERE id=%s', (source_id,))
            if not eligible(self.db, topup, customer['id'], amount):
                raise AdjustmentRejected('invalid_original_top_up')
        elif category == 'refund_recovery':
            source_id = _uuid(source_id)
            from .refund import REFUND_COLUMNS, eligible
            refund = self.db.one('SELECT ' + REFUND_COLUMNS + ' FROM public.order_refunds WHERE id=%s', (source_id,))
            if not eligible(refund, customer['id'], amount):
                raise AdjustmentRejected('invalid_original_refund')
        elif category not in _CATEGORIES and category != 'funded_transfer' or source_id is not None:
            raise AdjustmentRejected('invalid_source')
        return self._persist(request, customer, amount, reason, category, source_id, receipt)

    def _original(self, identity, customer_id, *, lock=False):
        original = self.db.one('SELECT * FROM public.balance_adjustment_requests WHERE id=%s' +
                               (' FOR UPDATE' if lock else ''), (identity,))
        if (original is None or original['state'] != 'applied' or original['currency'] != 'IDR'
                or original['customer_id'] != customer_id or original['category'] not in _KINDS):
            raise AdjustmentRejected('invalid_original_adjustment')
        entry = self.db.one('''SELECT e.* FROM public.store_balance_entries e
            JOIN public.customer_balance_accounts a ON a.id=e.customer_balance_account_id
            WHERE e.id=%s AND a.customer_id=%s''', (original['ledger_entry_id'], customer_id))
        if entry is None or entry['kind'] != _KINDS[original['category']] or entry['amount'] != original['amount']:
            raise AdjustmentRejected('invalid_original_adjustment')
        return original

    def request_reversal(self, request, telegram_id, original_id):
        owner(request, self.db)
        customer = self._customer(telegram_id)
        original_id = _uuid(original_id)
        original = self._original(original_id, customer['id'])
        if self.db.one("SELECT id FROM public.store_balance_entries WHERE source_type='balance_adjustment' AND source_id=%s AND kind='admin_adjustment_reversal'", (original_id,)):
            raise AdjustmentRejected('original_already_reversed')
        if self.db.one("""SELECT id FROM public.balance_adjustment_requests WHERE category='reversal' AND source_id=%s
            AND (state IN ('approved','applied') OR (state='pending' AND expires_at>%s)) LIMIT 1""", (original_id, legacy_now())):
            raise AdjustmentRejected('reversal_already_open')
        return self._persist(request, customer, original['amount'], ('Reversal penuh: ' + original['reason'])[:500], 'reversal', original_id)

    def confirm(self, request, request_id, fingerprint, version, password, receipt=None):
        actor = owner(request, self.db)
        identity = bound_request(self.db, request, actor, request_id, fingerprint, version)
        token = self.passwords.verify(request, request_id, fingerprint, version, password)
        def post():
            fresh = owner(request, self.db, lock=True)
            if fresh['password'] != actor['password']:
                raise AdjustmentRejected('credentials_changed')
            self.passwords.consume(fresh['id'], token, request_id, fingerprint)
            customer = self.db.one('SELECT id,telegram_id,username,locale FROM public.customers WHERE id=%s FOR UPDATE', (identity['customer_id'],))
            account = self.ledger.lock_for_purchase(identity['customer_id'])
            preview = bound_request(self.db, request, fresh, request_id, fingerprint, version, lock=True)
            if customer is None or str(customer['telegram_id']) != preview['telegram_id']:
                raise AdjustmentRejected('recipient_changed')
            from .receipt import VerifiedAdjustmentReceipt, VerifiedBscAdjustmentReceipt
            if preview['category'] == 'funded_transfer':
                if type(receipt) not in (VerifiedAdjustmentReceipt, VerifiedBscAdjustmentReceipt):
                    raise AdjustmentRejected('verified_receipt_required')
                verified = receipt.bind(customer['id'], preview['amount'], str(preview['id']))
                if verified.fingerprint() != preview['receipt_fingerprint']:
                    raise AdjustmentRejected('receipt_changed')
            elif receipt is not None:
                raise AdjustmentRejected('unexpected_receipt')
            if preview['state'] == 'applied':
                return self.db.one('SELECT * FROM public.store_balance_entries WHERE id=%s', (preview['ledger_entry_id'],))
            if preview['state'] != 'pending' or legacy_now() >= preview['expires_at']:
                raise AdjustmentRejected('preview_unavailable')
            if preview['category'] == 'funded_transfer' and verified.claim(self.db).held:
                return AdjustmentRejected('receipt_unavailable')
            category = preview['category']
            if category == 'reversal':
                original = self._original(preview['source_id'], customer['id'], lock=True)
                if preview['amount'] != original['amount']:
                    raise AdjustmentRejected('invalid_original_adjustment')
                source_id = str(original['id'])
                kind, key = 'admin_adjustment_reversal', f'balance-adjustment:{source_id}:reversal:v1'
                if self.ledger._find_source_entry(account, kind, preview['amount'], 'balance_adjustment', source_id, key):
                    raise AdjustmentRejected('original_already_reversed')
                if preview['amount'] > account['balance']:
                    raise AdjustmentRejected('reversal_insufficient_funds')
                if original['category'] == 'funded_transfer':
                    counterpart = 'adjustment_reversal_gain'
                else:
                    event = self.db.one('SELECT debit_account FROM public.balance_adjustment_accounting_events WHERE request_id=%s', (original['id'],))
                    if event is None:
                        raise AdjustmentRejected('original_accounting_missing')
                    counterpart = event['debit_account']
            elif category in _CATEGORIES or category == 'funded_transfer':
                kind, source_id = 'admin_adjustment_credit', str(preview['id'])
                key = f'balance-adjustment:{source_id}:v1'
                counterpart = 'verified_settlement_asset' if category == 'funded_transfer' else _COUNTERPART[category]
            elif category in ('top_up_recovery', 'refund_recovery'):
                counterpart = 'sale_refund' if category == 'refund_recovery' else 'owner_attested_adjustment_suspense'
            else:
                raise AdjustmentRejected('invalid_source')
            self.db.execute("UPDATE public.balance_adjustment_requests SET state='approved',updated_at=%s WHERE id=%s", (legacy_now(), preview['id']))
            if category == 'top_up_recovery':
                from .compensation import apply
                entry = apply(self.db, self.ledger, account, customer, preview)
            elif category == 'refund_recovery':
                from .refund import apply
                entry = apply(self.db, self.ledger, account, customer, preview, encrypter=self.encrypter)
            else:
                entry = self.ledger._post(account, kind, preview['amount'], source_id, key, source_type='balance_adjustment')
            now = legacy_now()
            preview = self.db.one("""UPDATE public.balance_adjustment_requests SET state='applied',ledger_entry_id=%s,
                resolved_at=%s,updated_at=%s WHERE id=%s RETURNING *""", (entry['id'], now, now, preview['id']))
            debit, credit = ('customer_idr_liability', counterpart) if category == 'reversal' else (counterpart, 'customer_idr_liability')
            self.db.execute('''INSERT INTO public.balance_adjustment_accounting_events
                (request_id,ledger_entry_id,currency,amount,debit_account,credit_account,source_fingerprint,created_at)
                VALUES (%s,%s,'IDR',%s,%s,%s,%s,%s)''', (preview['id'], entry['id'], preview['amount'], debit, credit, fingerprint, now))
            self._audit('balance.adjustment.applied', fresh['id'], preview)
            amount = 'Rp ' + format(preview['amount'], ',').replace(',', '.')
            locale = customer['locale'] if customer['locale'] in ('id', 'en', 'ms') else 'id'
            messages = {'id': ('Saldo IDR bertambah ', 'Saldo IDR berkurang '),
                        'en': ('Your IDR balance increased by ', 'Your IDR balance decreased by '),
                        'ms': ('Baki IDR anda bertambah ', 'Baki IDR anda berkurang ')}
            if category != 'top_up_recovery':
                record_notice(self.db, str(preview['id']), 'customer', str(customer['telegram_id']),
                              messages[locale][category == 'reversal'] + amount + '.', locale=locale)
            notice = (f'Reversal IDR {preview["id"]} diterapkan: {amount} dari adjustment {source_id}.'
                      if category == 'reversal' else f'Adjustment IDR {preview["id"]} diterapkan: {amount}.')
            record_notice(self.db, str(preview['id']), 'owner', self.admin_chat_id, notice)
            return entry
        try:
            result = self._run(post)
            if isinstance(result, AdjustmentRejected):
                raise result
            return result
        except AdjustmentRejected as error:
            if error.code == 'reversal_insufficient_funds':
                def review():
                    fresh = owner(request, self.db, lock=True)
                    preview = bound_request(self.db, request, fresh, request_id, fingerprint, version, lock=True)
                    self._audit('balance.adjustment.reversal_review', fresh['id'], preview, {'reason': error.code})
                self._run(review)
            raise
        finally:
            self.passwords.release(actor['id'], token)

    def close(self, request, request_id, fingerprint, version, decision):
        if decision not in ('rejected', 'cancelled', 'expired'):
            raise AdjustmentRejected('invalid_decision')
        def close_locked():
            actor = owner(request, self.db, lock=True)
            preview = bound_request(self.db, request, actor, request_id, fingerprint, version, lock=True)
            if preview['state'] == decision:
                return preview
            if preview['state'] != 'pending' or (decision == 'expired' and legacy_now() < preview['expires_at']):
                raise AdjustmentRejected('preview_unavailable')
            now = legacy_now()
            preview = self.db.one('UPDATE public.balance_adjustment_requests SET state=%s,resolved_at=%s,updated_at=%s WHERE id=%s RETURNING *',
                                  (decision, now, now, preview['id']))
            self._audit('balance.adjustment.' + decision, actor['id'], preview)
            return preview
        return self._run(close_locked)
