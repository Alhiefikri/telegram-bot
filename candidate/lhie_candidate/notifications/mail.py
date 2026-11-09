"""Credential email: encrypted recipient snapshot, no content in work or logs.

SMTP DATA acceptance can be ambiguous just like Telegram. A stopped in-flight
attempt is retained for review, not automatically sent a second time.
"""
from datetime import timedelta

from django.core.mail import EmailMultiAlternatives
from django.core.mail.backends.smtp import EmailBackend
from django.db import connections, transaction
from django.template.loader import render_to_string

from ..checkout.db import Database
from ..receipt.protocol import _now
from ..receipt.source import ReceiptUnavailable
from .policy import NotEligible, TestRecipients
from .credential_render import credential_text, presentation


class DefiniteMailFailure(Exception):
    """Only a transport which proves no acceptance may authorize retry."""


class SinkMail:
    """Fixed P01 SMTP sink. Never consult EMAIL_BACKEND (console/file are unsafe)."""
    def send(self, recipient, subject, text, *, html=None):
        connection = EmailBackend(host='127.0.0.1', port=1025, username='', password='',
                                  use_tls=False, use_ssl=False, timeout=5, fail_silently=False)
        message = EmailMultiAlternatives(subject, text, 'LhieStore <test@example.invalid>',
                                          [recipient], connection=connection)
        if html is not None:
            message.attach_alternative(html, 'text/html')
        if message.send(fail_silently=False) != 1:
            raise DefiniteMailFailure()


class CredentialMail:
    owned_types = ('credentials_ready',)
    def __init__(self, source, encrypter, *, using='default', transport=None, recipients=None):
        self.db = Database(using)
        self.source, self.encrypter = source, encrypter
        self.transport = transport if transport is not None else SinkMail()
        self.recipients = recipients if recipients is not None else TestRecipients()
        self._source_cursor = None

    def _outside(self):
        if (connections[self.db.using].in_atomic_block
                or not connections[self.db.using].get_autocommit()):
            raise RuntimeError('Mail delivery requires an outermost boundary')

    def record(self, order_id):
        """Atomic source lock; retries cannot redirect an existing recipient."""
        with transaction.atomic(using=self.db.using):
            order = self.db.one('''SELECT id,customer_id,notification_email FROM public.orders
                WHERE id=%s FOR UPDATE''', (order_id,))
            if not order or not order['notification_email']:
                return None
            existing = self.db.one('''SELECT * FROM public.order_notification_events
                WHERE order_id=%s AND event_type='credentials_ready' ''', (order_id,))
            if existing:
                return existing
            try:
                self.source.load_customer(str(order_id), order['customer_id'], receipt=True)
                # Authenticate the stored Laravel envelope. Persist the original
                # encrypted address, not current Customer.email or caller input.
                email = self.encrypter.decrypt_string(order['notification_email']).strip().lower()
                self.recipients.mail(email)
            except (ReceiptUnavailable, NotEligible, ValueError):
                return None
            at = _now()
            return self.db.one('''INSERT INTO public.order_notification_events
                (order_id,event_type,recipient_email,status,attempts,next_attempt_at,created_at,updated_at)
                VALUES (%s,'credentials_ready',%s,'pending',0,%s,%s,%s)
                ON CONFLICT (order_id,event_type) DO NOTHING RETURNING *''',
                (order_id, self.encrypter.encrypt_string(email), at, at, at))

    def _claim(self, event_id):
        with transaction.atomic(using=self.db.using):
            row = self.db.one('''SELECT * FROM public.order_notification_events
                WHERE id=%s AND event_type=ANY(%s) FOR UPDATE''', (event_id, list(self.owned_types)))
            at = _now()
            if (not row or row['status'] != 'pending'
                    or (row['next_attempt_at'] and row['next_attempt_at'] > at)):
                return None
            if row['delivery_started_at'] is not None:
                # A crash may have happened after SMTP accepted DATA. Preserve
                # the in-flight marker so existing resend UIs also refuse it.
                self.db.execute('''UPDATE public.order_notification_events SET status='failed',
                    failed_at=%s,last_error='mail_delivery_unknown',next_attempt_at=NULL,updated_at=%s
                    WHERE id=%s''', (at, at, event_id))
                return None
            if row['attempts'] >= 5:
                return None
            return self.db.one('''UPDATE public.order_notification_events SET attempts=attempts+1,
                delivery_started_at=%s,next_attempt_at=%s,updated_at=%s WHERE id=%s RETURNING *''',
                (at, at + timedelta(seconds=180), at, event_id))

    def _finish(self, claim, status, error=None, *, unknown=False, retry=False):
        at = _now()
        return self.db.execute('''UPDATE public.order_notification_events SET status=%s,last_error=%s,
            sent_at=CASE WHEN %s='sent' THEN %s ELSE sent_at END,
            failed_at=CASE WHEN %s='failed' THEN %s ELSE NULL END,
            delivery_started_at=CASE WHEN %s THEN delivery_started_at ELSE NULL END,
            next_attempt_at=%s,updated_at=%s WHERE id=%s AND status='pending' AND attempts=%s
              AND delivery_started_at=%s AND next_attempt_at>%s''',
            (status, error, status, at, status, at, unknown,
             at + timedelta(seconds=60 if claim['attempts'] == 1 else 120 if claim['attempts'] == 2 else 300)
             if retry else None, at, claim['id'], claim['attempts'], claim['delivery_started_at'], at))

    def deliver(self, event_id):
        self._outside()
        claim = self._claim(event_id)
        if claim is None:
            return 'not_ready'
        try:
            # There is intentionally no fallback to Order.notification_email.
            recipient = self.recipients.mail(self.encrypter.decrypt_string(claim['recipient_email']))
            owner = self.db.one('SELECT customer_id FROM public.orders WHERE id=%s', (claim['order_id'],))
            if not owner:
                raise ReceiptUnavailable()
            source = self.source.load_customer(str(claim['order_id']), owner['customer_id'], receipt=True)
            text = credential_text(source)
            html = render_to_string('notifications/credential.html', presentation(source))
        except Exception:
            self._finish(claim, 'cancelled', 'credentials_unavailable')
            return 'ineligible'
        try:
            self.transport.send(recipient, f"Data akun pesanan {source['invoice_number']} | LhieStore", text, html=html)
        except DefiniteMailFailure:
            retry = claim['attempts'] < 5
            self._finish(claim, 'pending' if retry else 'failed', 'mail_delivery_failed', retry=retry)
            return 'failed'
        except Exception:
            self._finish(claim, 'failed', 'mail_delivery_unknown', unknown=True)
            return 'unknown'
        return 'sent' if self._finish(claim, 'sent') else 'fenced'

    def recover(self, limit=25):
        self._outside()
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError('Invalid credential mail batch')
        orders = self.db.rows('''SELECT o.id FROM public.orders o
            WHERE o.notification_email IS NOT NULL AND o.payment_status='paid'
              AND o.order_status IN ('processing','completed') AND NOT EXISTS (
                SELECT 1 FROM public.order_notification_events e
                WHERE e.order_id=o.id AND e.event_type='credentials_ready')
              AND (%s::uuid IS NULL OR o.id>%s::uuid)
            ORDER BY o.id LIMIT %s''', (self._source_cursor, self._source_cursor, limit))
        self._source_cursor = str(orders[-1]['id']) if orders else None
        for order in orders:
            self.record(order['id'])
        rows = self.db.rows('''SELECT id FROM public.order_notification_events
            WHERE event_type='credentials_ready' AND status='pending'
              AND (attempts<5 OR delivery_started_at IS NOT NULL)
              AND (next_attempt_at IS NULL OR next_attempt_at<=%s) ORDER BY id LIMIT %s''', (_now(), limit))
        counts = {}
        for row in rows:
            result = self.deliver(row['id'])
            counts[result] = counts.get(result, 0) + 1
        return counts
