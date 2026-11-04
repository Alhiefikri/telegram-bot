"""Manual proof is durable evidence, never an authoritative paid observation."""
from datetime import datetime
import re
from uuid import uuid4
import json
from zoneinfo import ZoneInfo

from ..checkout.db import Database
from ..checkout.errors import CheckoutError
from ..checkout.lifecycle import OrderLifecycle
from ..domain.money import require_integer
from .lifecycle import LifecycleResult


def _now():
    return datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)


class ManualQrisLifecycle:
    def __init__(self, presentation, *, using='default', clock=None):
        self.presentation = presentation
        self.using = using
        self.db = Database(using)
        self.clock = clock or _now
        self.orders = OrderLifecycle(using=using, clock=self.clock)

    def _locked(self, payment_id):
        order, payment, payments, account = self.orders.lock_payment(payment_id)
        if (payment['provider'], payment['method'], payment['currency']) != ('manual', 'QR_MANUAL', 'IDR'):
            raise CheckoutError('invalid_payment_rail')
        return order, payment

    def classify(self, payment_id):
        def operation():
            order, payment = self._locked(payment_id)
            if self.orders._open_review(order['id']):
                return LifecycleResult('review')
            if payment['status'] in ('paid', 'refunded', 'failed', 'expired'):
                return LifecycleResult(payment['status'])
            if order['order_status'] != 'pending_payment' or order['payment_status'] != 'pending':
                return LifecycleResult('not_payable')
            if payment['payment_proof_submitted_at'] is not None:
                return LifecycleResult('proof_submitted', total=payment['amount'])
            deadline = min(order['expires_at'], payment['expired_at'])
            if deadline <= self.clock():
                return LifecycleResult('expired')
            try:
                photo = self.presentation.manual()
            except (ValueError, OSError):
                return LifecycleResult('provider_unavailable')
            return LifecycleResult('created', {'mode': 'manual', 'photo': photo,
                'effective_expires_at': deadline.isoformat()}, 0, payment['amount'], False)
        return self.orders._run(operation)

    def refresh(self, payment_id):
        return self.classify(payment_id)

    def recover(self, payment_id):
        return self.classify(payment_id)

    def consume_callbacks(self, payment_id):
        return self.classify(payment_id)

    def submit_proof(self, *, customer_id, invoice, photo_file_id):
        require_integer(customer_id, minimum=1)
        if (type(invoice) is not str or (invoice and not re.fullmatch(r'INV-[A-Za-z0-9-]{1,60}', invoice))
                or type(photo_file_id) is not str or not 0 < len(photo_file_id.encode()) <= 512
                or photo_file_id.strip() != photo_file_id):
            raise CheckoutError('invalid_payment_proof')
        initial = self.db.one('''SELECT p.id FROM public.payments p JOIN public.orders o ON o.id=p.order_id
            WHERE o.customer_id=%s AND (%s='' OR o.invoice_number=%s) AND p.provider='manual'
            AND p.method='QR_MANUAL' AND (%s<>'' OR (p.status='pending' AND o.order_status='pending_payment'))
            ORDER BY p.created_at DESC,p.id DESC LIMIT 1''', (customer_id, invoice, invoice, invoice))
        if initial is None:
            raise CheckoutError('foreign_payment')
        def operation():
            order, payment = self._locked(initial['id'])
            if order['customer_id'] != customer_id:
                raise CheckoutError('foreign_payment')
            if payment['payment_proof_submitted_at'] is not None:
                return 'duplicate'
            if (payment['status'] != 'pending' or order['order_status'] != 'pending_payment'
                    or order['payment_status'] != 'pending'
                    or min(order['expires_at'], payment['expired_at']) <= self.clock()):
                return 'expired'
            setting = self.db.one('''SELECT admin_telegram_chat_id FROM public.manual_payment_settings
                WHERE is_active=true ORDER BY id LIMIT 1''')
            admin_chat = setting['admin_telegram_chat_id'] if setting else None
            if not admin_chat:
                return 'admin_unavailable'
            self.db.execute('''UPDATE public.payments SET payment_proof_file_id=%s,
                payment_proof_submitted_at=%s,payment_proof_admin_chat_id=%s,updated_at=%s WHERE id=%s''',
                (photo_file_id, self.clock(), str(admin_chat), self.clock(), payment['id']))
            effect_key = f"payment:{payment['id']}:admin.manual_payment_proof:v1"
            self.db.execute('''INSERT INTO public.telegram_outbound_effects
                (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
                 telegram_chat_id,queue,telegram_method,template_version,state,attempts,next_attempt_at,
                 outcome_metadata,created_at,updated_at)
                VALUES (%s,%s,%s,1,'admin.manual_payment_proof','App\\Models\\Payment',%s,'admin',%s,
                    'notifications','sendPhoto','manual-payment-proof-v1','pending',0,%s,%s::jsonb,%s,%s)
                ON CONFLICT (effect_key) DO NOTHING''',
                (str(uuid4()), effect_key, effect_key, str(payment['id']), str(admin_chat), self.clock(),
                 json.dumps({'delivery_context': 'manual_payment_proof', 'attachment_type': 'telegram_file_reference',
                             'attachment_present': True, 'payment_method': 'QR_MANUAL',
                             'late_evidence': False, 'proof_status': 'pending'}), self.clock(), self.clock()))
            self.orders._audit('payment.proof_submitted', order, payment)
            return 'accepted'
        return self.orders._run(operation)
