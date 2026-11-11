"""Credential email: encrypted recipient snapshot, no content in work or logs.

SMTP DATA acceptance can be ambiguous just like Telegram. A stopped in-flight
attempt is retained for review, not automatically sent a second time.
"""
from datetime import timedelta
from html import escape
from functools import lru_cache
from pathlib import Path
import smtplib
from urllib.parse import urlsplit

from django.core.mail import EmailMultiAlternatives
from django.core.mail.backends.smtp import EmailBackend
from django.db import connections, transaction
from django.template import Context, Engine

from ..branding import APP_NAME

from ..checkout.db import Database
from ..receipt.protocol import _now
from ..receipt.render import _legacy
from ..receipt.source import ReceiptUnavailable
from .policy import NotEligible, TestRecipients


class DefiniteMailFailure(Exception):
    """Only a transport which proves no acceptance may authorize retry."""


class SinkMail:
    """Fixed P01 SMTP sink. Never consult EMAIL_BACKEND (console/file are unsafe)."""
    def send(self, recipient, subject, text, html=None):
        TestRecipients().mail(recipient)
        connection = EmailBackend(host='127.0.0.1', port=1025, username='', password='',
                                  use_tls=False, use_ssl=False, timeout=5, fail_silently=False)
        message = EmailMultiAlternatives(subject, text, 'LhieStore <test@example.invalid>',
                                         [recipient], connection=connection)
        if html is not None:
            message.attach_alternative(html, 'text/html')
        try:
            accepted = message.send(fail_silently=False)
        except (ConnectionRefusedError, smtplib.SMTPConnectError, smtplib.SMTPRecipientsRefused,
                smtplib.SMTPSenderRefused, smtplib.SMTPDataError):
            raise DefiniteMailFailure() from None
        if accepted != 1:
            raise DefiniteMailFailure()


DEFAULT_INSTRUCTION = ('Login dari perangkat pribadi, ganti password setelah berhasil masuk, lalu pindahkan '
                       'MFA/Authenticator ke perangkat Anda sendiri jika produk mendukungnya.')
WARNING = ('Jangan bagikan data akun, email ini, atau Recovery Key kepada siapa pun, termasuk pihak yang '
           'mengaku support. Ganti password akun setelah login pertama bila produk mendukungnya.')


def credential_context(source, *, app_name=None):
    """Match OrderCredentialMailService's channel projection, not Telegram copy."""
    app_name = APP_NAME if app_name is None else app_name
    groups, guides = [], []
    for group in source['groups']:
        accounts = []
        default = group.get('default_tutorial')
        for account in group['accounts']:
            row = {'label': f"Data akun {account['number']}",
                   'tutorial': account.get('tutorial') if account.get('tutorial') != default else None,
                   'access': None}
            if account['format'] == 'legacy':
                text, parts, _ = _legacy(account['secret'])
                row.update(kind='legacy', text=text, parts=(
                    dict(zip(('email', 'password', 'mfa'), parts)) if parts else None))
            else:
                row.update(kind='structured', email=account['email'], password=account['password'])
                method, value = account['access_method'], account['access_value']
                reader = account.get('reader_mail_link')
                if reader:
                    row['access'] = {'label': 'Akses Token', 'value': value, 'url': reader,
                                     'domain': urlsplit(reader).hostname}
                elif method == 'otp_mail_link':
                    row['access'] = {'label': 'OTP Mail', 'value': None, 'url': value,
                                     'domain': urlsplit(value).hostname}
                elif method == 'auth_token':
                    row['access'] = {'label': 'Auth Token', 'value': value, 'url': None, 'domain': None}
            accounts.append(row)
        guide = group.get('guide')
        if guide and guide not in guides:
            guides.append(guide)
        groups.append({'product_name': group['product_name'], 'accounts': accounts,
                       'delivery_note': default, 'usage_guide': guide})
    invoice_url = source.get('invoice_url', '')
    parsed = urlsplit(invoice_url)
    base = f'{parsed.scheme}://{parsed.netloc}' if parsed.netloc else 'http://127.0.0.1:18801'
    return {'invoice_number': source['invoice_number'], 'groups': groups, 'usage_guides': guides,
            'invoice_url': invoice_url, 'order_access_url': base + '/akses-pesanan',
            'default_instruction': DEFAULT_INSTRUCTION, 'app_name': app_name}


def credential_text(source):
    data = credential_context(source)
    e = lambda value: escape('' if value is None else str(value), quote=True).replace('&#x27;', '&#039;')
    lines = [f"Data akun pesanan {e(data['invoice_number'])}", '',
             'Pembayaran pesanan ini sudah terverifikasi. Data akun untuk pesanan Anda ada di email ini. '
             'Simpan email ini di tempat aman.', '']
    for group in data['groups']:
        lines.append(e(group['product_name']))
        for account in group['accounts']:
            lines.append(e(account['label']))
            if account['kind'] == 'legacy':
                lines.append(e(account['text']))
                if account['parts']:
                    lines.extend(key + ' : ' + e(value) for key, value in account['parts'].items() if value)
            else:
                lines.extend(('email : ' + e(account['email']), 'password : ' + e(account['password'])))
                access = account['access']
                if access:
                    lines.append(e(access['label'].lower()) + ' : ' + e(access['value']))
                    if access['url']:
                        lines.append('link : ' + e(access['url']))
            if account['tutorial']:
                lines.append('instruksi akun : ' + e(account['tutorial']))
            lines.append('')
        if group['delivery_note']:
            lines.append('instruksi produk : ' + e(group['delivery_note']))
        if group['usage_guide']:
            lines.append(e(group['usage_guide']['title']) + ' : ' + e(group['usage_guide']['url']))
        lines.append('')
    if data['groups']:
        lines.append('Instruksi penggunaan aman: ' + e(data['default_instruction']))
    if data['usage_guides']:
        lines.append('Panduan produk:')
        lines.extend('- ' + e(guide['title']) + ' : ' + e(guide['url']) for guide in data['usage_guides'])
    lines.extend(('', 'Halaman invoice: ' + e(data['invoice_url']),
                  'Akses pesanan (nomor invoice + Recovery Key): ' + e(data['order_access_url']), '', WARNING))
    return '\n'.join(lines)


@lru_cache(maxsize=1)
def _html_template():
    return Engine(dirs=[str(Path(__file__).resolve().parents[2] / 'templates')]).get_template(
        'emails/order-credential.html')


def credential_html(source):
    return _html_template().render(Context(credential_context(source), use_l10n=False, use_tz=False))


class CredentialMail:
    def __init__(self, source, encrypter, *, using='default', transport=None, recipients=None):
        self.db = Database(using)
        self.source, self.encrypter = source, encrypter
        self.transport = transport if transport is not None else SinkMail()
        self.recipients = recipients if recipients is not None else TestRecipients()
        self._order_after = '00000000-0000-0000-0000-000000000000'

    def _outside(self):
        if (connections[self.db.using].in_atomic_block
                or not connections[self.db.using].get_autocommit()):
            raise RuntimeError('Mail delivery requires an outermost boundary')

    def record(self, order_id):
        """Atomic source lock; retries cannot redirect an existing recipient."""
        if not self.recipients.telegram:
            return None
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

    def _presentation(self, order_id, customer_id):
        source = self.source.load_customer(str(order_id), customer_id, receipt=True)
        rows = self.db.rows('''SELECT s.order_item_id,u.unit_number
            FROM public.order_items i JOIN public.product_stock_items s ON s.order_item_id=i.id
            LEFT JOIN public.supplier_account_units u ON u.product_stock_item_id=s.id
            WHERE i.order_id=%s AND s.retired_at IS NULL ORDER BY i.id,s.id''', (order_id,))
        numbers = {}
        for row in rows:
            group = numbers.setdefault(row['order_item_id'], [])
            group.append(row['unit_number'] if row['unit_number'] is not None else len(group) + 1)
        if len(numbers) != len(source['groups']):
            raise ReceiptUnavailable()
        for group, labels in zip(source['groups'], numbers.values()):
            if len(group['accounts']) != len(labels):
                raise ReceiptUnavailable()
            for account, number in zip(group['accounts'], labels):
                account['number'] = number
        return source

    def _claim(self, event_id):
        with transaction.atomic(using=self.db.using):
            row = self.db.one('''SELECT * FROM public.order_notification_events
                WHERE id=%s AND event_type='credentials_ready' FOR UPDATE''', (event_id,))
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
        if not self.recipients.telegram:
            return 'disabled'
        claim = self._claim(event_id)
        if claim is None:
            return 'not_ready'
        try:
            # There is intentionally no fallback to Order.notification_email.
            recipient = self.recipients.mail(self.encrypter.decrypt_string(claim['recipient_email']))
            owner = self.db.one('SELECT customer_id FROM public.orders WHERE id=%s', (claim['order_id'],))
            if not owner:
                raise ReceiptUnavailable()
            source = self._presentation(claim['order_id'], owner['customer_id'])
            text = credential_text(source)
            html = credential_html(source)
        except Exception:
            self._finish(claim, 'cancelled', 'credentials_unavailable')
            return 'ineligible'
        try:
            self.transport.send(recipient,
                f"Data akun pesanan {source['invoice_number']} | {APP_NAME}", text, html)
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
        if not self.recipients.telegram:
            return {}
        orders = self.db.rows('''SELECT o.id FROM public.orders o
            WHERE o.notification_email IS NOT NULL AND o.payment_status='paid' AND o.id>%s::uuid
              AND o.order_status IN ('processing','completed') AND o.created_at >= %s
              AND NOT EXISTS (
                SELECT 1 FROM public.order_notification_events e
                WHERE e.order_id=o.id AND e.event_type='credentials_ready')
            ORDER BY o.id LIMIT %s''', (self._order_after, _now() - timedelta(hours=72), limit))
        self._order_after = str(orders[-1]['id']) if orders else '00000000-0000-0000-0000-000000000000'
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
