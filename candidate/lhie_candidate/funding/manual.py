"""Explicit manual top-up operations; no transport or runtime activation."""
import hashlib
import json
from datetime import timedelta

from django.db import transaction

from lhie_candidate.domain.money import require_integer
from lhie_candidate.payments.receipts import claim_provider_reference
from .outbound import record_effect, record_paid
from .service import FundingError, TopUpOutcome, _deadline, _value


class ManualFunding:
    def __init__(self, funding, *, admin_user_id=''):
        self.funding = funding
        self.db = funding.db
        self.using = funding.using
        self.admin_user_id = str(admin_user_id).strip()

    def create(self, customer, root, intent_id):
        customer_id = require_integer(customer['id'], minimum=1)
        chat_id = str(_value(root, 'chat_id') or '')
        message_id = _value(root, 'message_id')
        with transaction.atomic(using=self.using):
            buyer = self.funding._lock_customer(customer_id)
            if not self.funding._root_matches(root, customer_id=customer_id, chat_id=chat_id,
                                              message_id=message_id, lock=True):
                return TopUpOutcome('stale')
            intent = self.funding._locked_confirmed_intent(customer_id, intent_id, chat_id, message_id)
            existing = self.funding._topup_by_intent(intent_id, lock=True)
            if existing is not None:
                return TopUpOutcome('replayed', existing)
            setting = self.db.one('''SELECT id,is_active,payment_mode,payment_provider_version,
                qris_payment_ttl_minutes FROM public.manual_payment_settings
                WHERE is_active=true ORDER BY id LIMIT 1 FOR UPDATE''')
            policy = self.funding._policy(lock=True)
            if (setting is None or (setting['payment_mode'] or '').strip().lower() != 'manual'
                    or policy is None or policy.version != intent['policy_version']):
                return TopUpOutcome('unavailable')
            amount = require_integer(intent['amount'], minimum=policy.minimum_amount)
            ttl = setting['qris_payment_ttl_minutes']
            if type(ttl) is not int or ttl < 1:
                return TopUpOutcome('unavailable')
            now = self.funding.clock()
            deadline = min(_deadline(intent['expires_at']), now + timedelta(minutes=ttl))
            if deadline <= now:
                return TopUpOutcome('expired')
            topup = self.db.one('''INSERT INTO public.store_balance_top_ups
                (customer_id,store_balance_funding_intent_id,telegram_navigation_root_id,chat_id,
                 canonical_message_id,locale,provider,payment_setting_id,payment_provider_version,
                 merchant_ref,idempotency_key,currency,amount,status,provider_payment_method,
                 provider_fee,provider_total_payment,provider_created_at,provider_expires_at,expires_at,
                 provider_create_state,created_at,updated_at)
                VALUES (%s,%s,NULL,%s,%s,%s,'manual',%s,%s,%s,%s,'IDR',%s,'awaiting_confirmation',
                    'QR_MANUAL',0,%s,%s,%s,%s,'awaiting_confirmation',%s,%s)
                RETURNING ''' + ','.join(self.funding.TOPUP_COLUMNS),
                (customer_id, intent_id, chat_id, message_id, customer.get('locale'), setting['id'],
                 setting['payment_provider_version'], self.funding._merchant_reference(intent),
                 self.funding._idempotency_key(intent), amount, amount, now, deadline, deadline, now, now))
            if str(buyer['telegram_id'] or '') == chat_id:
                record_effect(self.db, topup['id'], 'balance.top_up_instructions', 'manual-instructions', 'customer',
                              chat_id, 'sendPhoto', 'balance-top-up-manual-v1', now, locale=topup['locale'])
            return TopUpOutcome('awaiting_confirmation', topup)

    def check(self, topup):
        if topup['provider'] != 'manual':
            return topup
        self.funding.expire_if_due(topup['id'])
        return self.funding.top_up_by_id(topup['id'])

    def presentation(self, topup):
        deadline = _deadline(topup['expires_at']) if topup['expires_at'] else None
        expired = topup['status'] == 'expired' or (deadline is not None and deadline <= self.funding.clock())
        payable = topup['status'] == 'awaiting_confirmation' and not expired
        paid = topup['status'] == 'paid'
        return {'state': 'paid' if paid else 'payable' if payable else 'expired' if expired else 'manual_review',
                'paid': paid, 'payable': payable, 'expired': expired, 'retryable': False,
                'manual_review': not (paid or payable or expired), 'recovered': paid,
                'amount': topup['amount'], 'fee': topup['provider_fee'], 'total': topup['provider_total_payment'],
                'expires': deadline, 'qris_url': None, 'topup_id': str(topup['id'])}

    def submit_proof(self, *, customer_id, topup_id, photo_file_id):
        require_integer(customer_id, minimum=1)
        require_integer(topup_id, minimum=1)
        if (type(photo_file_id) is not str or not 0 < len(photo_file_id) <= 191
                or photo_file_id.strip() != photo_file_id):
            raise FundingError('invalid_payment_proof')
        with transaction.atomic(using=self.using):
            self.funding._lock_customer(customer_id)
            topup = self.db.one('''SELECT id,provider,status,expires_at,provider_expires_at,
                payment_proof_submitted_at FROM public.store_balance_top_ups
                WHERE id=%s AND customer_id=%s FOR UPDATE''', (topup_id, customer_id))
            if topup is None or topup['provider'] != 'manual':
                return 'not_eligible'
            if topup['payment_proof_submitted_at'] is not None:
                return 'duplicate'
            now = self.funding.clock()
            if (topup['status'] != 'awaiting_confirmation' or any(
                    value is None or _deadline(value) <= now
                    for value in (topup['expires_at'], topup['provider_expires_at']))):
                return 'expired'
            setting = self.db.one('''SELECT admin_telegram_chat_id FROM public.manual_payment_settings
                WHERE is_active=true ORDER BY id LIMIT 1''')
            chat = str(setting['admin_telegram_chat_id'] or '').strip() if setting else ''
            if not chat:
                return 'admin_unavailable'
            self.db.execute('''UPDATE public.store_balance_top_ups SET payment_proof_file_id=%s,
                payment_proof_submitted_at=%s,payment_proof_admin_chat_id=%s,updated_at=%s WHERE id=%s''',
                (photo_file_id, now, chat, now, topup_id))
            record_effect(self.db, topup_id, 'admin.balance_top_up_payment_proof', 'admin-payment-proof',
                          'admin', chat, 'sendPhoto', 'balance-top-up-payment-proof-v1', now)
            return 'accepted'

    def decide(self, *, topup_id, operator_customer_id, operator_chat_id, approve):
        require_integer(topup_id, minimum=1)
        require_integer(operator_customer_id, minimum=1)
        if type(approve) is not bool or not isinstance(operator_chat_id, str):
            raise FundingError('operator_forbidden')
        with transaction.atomic(using=self.using):
            operator = self.db.one('SELECT id,telegram_id FROM public.customers WHERE id=%s', (operator_customer_id,))
            setting = self.db.one('''SELECT admin_telegram_chat_id FROM public.manual_payment_settings
                WHERE is_active=true ORDER BY id LIMIT 1''')
            telegram = str(operator['telegram_id'] or '') if operator else ''
            admin_chat = str(setting['admin_telegram_chat_id'] or '').strip() if setting else ''
            if not telegram or operator_chat_id != telegram or not (
                    (self.admin_user_id and telegram == self.admin_user_id)
                    or (admin_chat and operator_chat_id == admin_chat and telegram == admin_chat)):
                raise FundingError('operator_forbidden')
            initial = self.funding.top_up_by_id(topup_id)
            if initial is None:
                return 'not_eligible'
            self.funding._lock_customer(initial['customer_id'])
            self.funding.ledger.lock_for_top_up(initial['customer_id'])
            topup = self.db.one('SELECT ' + ','.join(self.funding.TOPUP_COLUMNS) +
                ' FROM public.store_balance_top_ups WHERE id=%s FOR UPDATE', (topup_id,))
            if topup['status'] == 'expired':
                return 'expired'
            if topup['status'] == 'paid':
                return 'already_confirmed'
            if topup['status'] == 'failed':
                return 'already_rejected'
            if topup['provider'] != 'manual' or topup['status'] != 'awaiting_confirmation':
                return 'not_eligible'
            now = self.funding.clock()
            if approve and any(value is None or _deadline(value) <= now
                               for value in (topup['expires_at'], topup['provider_expires_at'])):
                self.db.execute("UPDATE public.store_balance_top_ups SET status='expired',provider_create_state='provider_expired',updated_at=%s WHERE id=%s", (now, topup_id))
                return 'expired'
            status = 'paid' if approve else 'failed'
            if approve:
                claim = claim_provider_reference(self.db, provider='manual', reference=topup['merchant_ref'],
                    beneficiary='customer:' + str(topup['customer_id']), currency='IDR', amount=topup['amount'],
                    target_type='balance_top_up', target_id=str(topup_id), now=now, using=self.using,
                    account_namespace='setting:' + str(topup['payment_setting_id']) if topup['payment_setting_id'] else 'default')
                if claim.held:
                    return 'receipt_' + claim.status
                self.funding.ledger.credit_top_up(topup['customer_id'], str(topup_id), topup['amount'],
                                                  'balance_top_up:' + str(topup_id) + ':credit')
            self.db.execute('''UPDATE public.store_balance_top_ups SET status=%s,provider_create_state=%s,
                provider_create_last_error=%s,paid_at=%s,settled_at=%s,updated_at=%s WHERE id=%s''',
                (status, 'manual_confirmed' if approve else 'manual_rejected', None if approve else 'manual_rejected',
                 now if approve else None, now if approve else None, now, topup_id))
            decision = 'CONFIRMED' if approve else 'REJECTED'
            event_type = 'manual_confirmation' if approve else 'manual_rejection'
            operator_ref = hashlib.sha256(operator_chat_id.strip().encode()).hexdigest()[:16]
            event_key = hashlib.sha256(f'{topup_id}|{event_type}'.encode()).hexdigest()
            self.db.execute('''INSERT INTO public.store_balance_top_up_events
                (store_balance_top_up_id,provider,event_type,merchant_ref,provider_reference,provider_status,
                 raw_payload,idempotency_key,received_count,received_at,last_received_at,processed_at)
                VALUES (%s,'manual',%s,%s,NULL,%s,%s::jsonb,%s,1,%s,%s,%s)
                ON CONFLICT (provider,event_type,idempotency_key) DO NOTHING''',
                (topup_id, event_type, topup['merchant_ref'], decision,
                 json.dumps({'outcome': decision.lower(), 'amount': topup['amount'], 'operator': operator_ref}),
                 event_key, now, now, now))
            self.db.execute('''INSERT INTO public.audit_logs(action,subject_type,subject_id,new_values,created_at)
                VALUES (%s,'App\\Models\\StoreBalanceTopUp',%s,%s,%s)''',
                ('store_balance.top_up.manually_confirmed' if approve else 'store_balance.top_up.manually_rejected',
                 str(topup_id), json.dumps({'status': status, 'amount': topup['amount'],
                     'operator_customer_id': operator_customer_id,
                     'operator_reference': hashlib.sha256(operator_chat_id.strip().encode()).hexdigest()[:16]}), now))
            if approve:
                buyer = self.db.one('SELECT telegram_id,locale FROM public.customers WHERE id=%s', (topup['customer_id'],))
                record_paid(self.db, topup, buyer, now)
            return 'confirmed' if approve else 'rejected'

