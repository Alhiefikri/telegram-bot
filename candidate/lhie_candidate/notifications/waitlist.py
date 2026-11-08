"""Local-only waitlist owner over the existing subscription/event/mail tables.

No broker state authorizes a send. An interrupted SMTP attempt is retained for
review, never reclaimed as a new send. Transport runs outside transactions.
"""
from datetime import timedelta
import hmac
import re
from urllib.parse import parse_qsl, urlsplit
from zoneinfo import ZoneInfo

from django.db import connections, transaction
from django.template.loader import render_to_string

from ..checkout.db import Database
from ..compat.identity import normalize_customer_email, signed_url_digest, waitlist_email_hash
from ..navigation.repository import CatalogRepository
from ..receipt.protocol import _now
from .mail import DefiniteMailFailure, SinkMail
from .policy import NotEligible, TestRecipients


def batch_size(value):
    if type(value) is not int or not 1 <= value <= 100:
        raise ValueError('Invalid waitlist batch')
    return value


class WaitlistLinks:
    """Laravel-compatible absolute signatures, fixed trusted origin and purpose."""
    def __init__(self, key, *, origin='http://127.0.0.1:18801'):
        if not key or origin != 'http://127.0.0.1:18801':
            raise ValueError('Waitlist requires the local storefront origin')
        self.key, self.origin = key, origin

    def create(self, purpose, subscription_id, expires):
        if purpose not in ('confirm', 'unsubscribe') or type(subscription_id) is not int or subscription_id < 1:
            raise ValueError('Invalid waitlist link')
        if expires.tzinfo is None:
            expires = expires.replace(tzinfo=ZoneInfo('Asia/Jakarta'))
        unsigned = f'{self.origin}/waitlist/{purpose}/{subscription_id}?expires={int(expires.timestamp())}'
        return unsigned + '&signature=' + signed_url_digest(unsigned, self.key)

    def valid(self, url, purpose, subscription_id, now):
        if purpose not in ('confirm', 'unsubscribe') or type(subscription_id) is not int or subscription_id < 1:
            return False
        if not isinstance(url, str) or len(url) > 2048:
            return False
        try:
            parsed = urlsplit(url)
        except ValueError:
            return False
        expected = f'{self.origin}/waitlist/{purpose}/{subscription_id}'
        if url.split('?', 1)[0] != expected or parsed.fragment:
            return False
        # Reject duplicate/extra/encoded parameters rather than interpreting a
        # signed capability differently from its producer.
        pairs = parse_qsl(parsed.query, keep_blank_values=True)
        if len(pairs) != 2 or [name for name, _ in pairs] != ['expires', 'signature']:
            return False
        expires, signature = pairs[0][1], pairs[1][1]
        if not re.fullmatch(r'[1-9][0-9]{0,10}', expires) or not re.fullmatch(r'[a-f0-9]{64}', signature):
            return False
        if now.tzinfo is None:
            now = now.replace(tzinfo=ZoneInfo('Asia/Jakarta'))
        unsigned = expected + '?expires=' + expires
        return (int(expires) >= int(now.timestamp()) and url == unsigned + '&signature=' + signature
                and hmac.compare_digest(signature, signed_url_digest(unsigned, self.key)))


class Waitlist:
    def __init__(self, cipher, key, *, using='default', enabled=False, transport=None, clock=_now,
                 waitlist_key=''):
        self.db = Database(using)
        self.catalog = CatalogRepository(using)
        self.cipher, self.key, self.waitlist_key = cipher, key, waitlist_key
        self.enabled, self.clock = enabled is True, clock
        self.links = WaitlistLinks(key)
        self.transport = transport if transport is not None else SinkMail()
        self.recipients = TestRecipients()
        self._cursors = {}

    def _outside(self):
        connection = connections[self.db.using]
        if connection.in_atomic_block or not connection.get_autocommit():
            raise RuntimeError('Waitlist delivery requires an outermost boundary')

    def _product(self, product_id):
        return self.catalog.product(product_id)

    def subscribe(self, product_id, address):
        if not self.enabled:
            return False
        if not isinstance(address, str):
            return False
        try:
            address = self.recipients.mail(normalize_customer_email(address))
        except NotEligible:
            return False
        digest = waitlist_email_hash(address, self.key, waitlist_key=self.waitlist_key)
        with transaction.atomic(using=self.db.using):
            if not self.db.one('SELECT id FROM public.products WHERE id=%s FOR UPDATE', (product_id,)):
                return False
            product = self._product(product_id)
            if not product or product['available_stock'] > 0:
                return False
            row = self.db.one('''SELECT * FROM public.product_waitlist_subscriptions
                WHERE product_id=%s AND email_hash=%s FOR UPDATE''', (product_id, digest))
            at = self.clock()
            if row:
                if (row['status'] != 'pending_confirmation' or row['confirmation_delivery_started_at'] is not None
                        or row['confirmation_last_error'] == 'mail_delivery_unknown'
                        or (row['confirmation_expires_at'] and row['confirmation_expires_at'] > at)):
                    return False
                self.db.execute('''UPDATE public.product_waitlist_subscriptions SET email=%s,
                    confirmation_expires_at=%s,confirmation_sent_at=NULL,confirmation_attempts=0,
                    confirmation_last_error=NULL,updated_at=%s WHERE id=%s''',
                    (self.cipher.encrypt_string(address), at + timedelta(hours=1), at, row['id']))
            else:
                self.db.execute('''INSERT INTO public.product_waitlist_subscriptions
                    (product_id,email,email_hash,status,confirmation_expires_at,created_at,updated_at)
                    VALUES (%s,%s,%s,'pending_confirmation',%s,%s,%s)''',
                    (product_id, self.cipher.encrypt_string(address), digest, at + timedelta(hours=1), at, at))
            return True

    def resolve(self, subscription_id, purpose, url):
        if not self.enabled or not self.links.valid(url, purpose, subscription_id, self.clock()):
            return 'expired_link'
        with transaction.atomic(using=self.db.using):
            row = self.db.one('SELECT * FROM public.product_waitlist_subscriptions WHERE id=%s FOR UPDATE',
                              (subscription_id,))
            if not row:
                return 'expired_link'
            at = self.clock()
            if purpose == 'unsubscribe':
                # Lock order is subscription -> notification throughout this
                # owner. In-flight sends may already have reached SMTP; retain
                # their markers and fence their completion rather than resend.
                self.db.execute('''UPDATE public.product_waitlist_subscriptions SET status='unsubscribed',
                    unsubscribed_at=COALESCE(unsubscribed_at,%s),updated_at=%s WHERE id=%s''',
                    (at, at, subscription_id))
                self.db.execute('''UPDATE public.product_waitlist_notifications SET status='skipped',
                    next_attempt_at=NULL,updated_at=%s WHERE subscription_id=%s
                    AND status IN ('pending','failed') AND delivery_started_at IS NULL''', (at, subscription_id))
                return 'unsubscribed'
            if row['status'] == 'unsubscribed':
                return 'unsubscribed'
            if row['status'] != 'pending_confirmation':
                return 'already_requested'
            if not row['confirmation_expires_at'] or row['confirmation_expires_at'] <= at:
                return 'expired_link'
            self.db.execute('''UPDATE public.product_waitlist_subscriptions SET status='active',
                confirmed_at=%s,confirmation_expires_at=NULL,updated_at=%s WHERE id=%s''',
                (at, at, subscription_id))
            return 'success'

    def confirm_mail(self, subscription_id):
        self._outside()
        if not self.enabled:
            return 'disabled'
        with transaction.atomic(using=self.db.using):
            row = self.db.one('SELECT * FROM public.product_waitlist_subscriptions WHERE id=%s FOR UPDATE',
                              (subscription_id,))
            at = self.clock()
            if (not row or row['status'] != 'pending_confirmation' or row['confirmation_sent_at']
                    or not row['confirmation_expires_at'] or row['confirmation_expires_at'] <= at
                    or row['confirmation_last_error'] == 'mail_delivery_unknown'):
                return 'not_ready'
            if row['confirmation_delivery_started_at']:
                if row['confirmation_delivery_started_at'] <= at - timedelta(seconds=180):
                    self.db.execute('''UPDATE public.product_waitlist_subscriptions
                        SET confirmation_last_error='mail_delivery_unknown',updated_at=%s WHERE id=%s''',
                        (at, row['id']))
                    return 'unknown'
                return 'not_ready'
            if row['confirmation_attempts'] >= 3:
                return 'not_ready'
            if (row['confirmation_last_error'] == 'mail_delivery_failed'
                    and row['updated_at'] > at - timedelta(seconds=60)):
                return 'not_ready'
            try:
                address = self.recipients.mail(self.cipher.decrypt_string(row['email']))
            except (ValueError, NotEligible):
                return 'ineligible'
            product = self._product(row['product_id'])
            if not product:
                return 'ineligible'
            row = self.db.one('''UPDATE public.product_waitlist_subscriptions
                SET confirmation_delivery_started_at=%s,confirmation_attempts=confirmation_attempts+1,
                    updated_at=%s WHERE id=%s RETURNING *''', (at, at, row['id']))
        context = {'product_name': safe_name(product['name']), 'confirmation_url': self.links.create(
            'confirm', row['id'], row['confirmation_expires_at'])}
        text = (f"Konfirmasi permintaan stok\n\n"
                f"Klik tautan berikut untuk mengaktifkan notifikasi saat produk tersedia kembali:\n{context['product_name']}\n\n"
                f"Konfirmasi permintaan: {context['confirmation_url']}\n\n"
                'Tautan ini hanya untuk konfirmasi permintaan notifikasi dan akan kedaluwarsa. '
                'Kami tidak mengirim Recovery Key, kredensial, atau data pesanan melalui email ini.')
        outcome = self._send(address, 'Konfirmasi notifikasi stok | LhieStore', text,
                             'notifications/waitlist_confirmation.html', context)
        finished = self.clock()
        changed = self.db.execute('''UPDATE public.product_waitlist_subscriptions
            SET confirmation_sent_at=CASE WHEN %s='sent' THEN %s ELSE confirmation_sent_at END,
                confirmation_last_error=%s,confirmation_delivery_started_at=CASE WHEN %s='unknown'
                THEN confirmation_delivery_started_at ELSE NULL END,updated_at=%s
            WHERE id=%s AND confirmation_attempts=%s AND confirmation_delivery_started_at=%s
              AND confirmation_last_error IS DISTINCT FROM 'mail_delivery_unknown'
              AND confirmation_delivery_started_at>%s''',
            (outcome, finished, None if outcome == 'sent' else 'mail_delivery_' + outcome,
             outcome, finished, row['id'], row['confirmation_attempts'], row['confirmation_delivery_started_at'],
             finished - timedelta(seconds=180)))
        return outcome if changed else 'fenced'

    def _send(self, address, subject, text, template, context):
        # Rendering failure precedes transport and cannot be an unknown send.
        try:
            html = render_to_string(template, context)
        except Exception:
            return 'failed'
        try:
            self.transport.send(address, subject, text, html=html)
        except DefiniteMailFailure:
            return 'failed'
        except Exception:
            return 'unknown'
        return 'sent'

    def materialize(self, movement_id, limit=25):
        batch_size(limit)
        if not self.enabled:
            return 0
        with transaction.atomic(using=self.db.using):
            # Inventory history is immutable/read-only. Use a transaction-scoped
            # advisory mutex, not an UPDATE grant just to lock its source row.
            lock = self.db.one("SELECT pg_try_advisory_xact_lock(hashtextextended(%s,0)) AS acquired",
                               ('waitlist:movement:' + str(movement_id),))
            if not lock['acquired']:
                return 0
            movement = self.db.one('SELECT * FROM public.inventory_movements WHERE id=%s', (movement_id,))
            if (not movement or movement['quantity'] <= 0 or not movement['available_after']
                    or not (movement['type'] == 'adjustment'
                            or (movement['type'] == 'availability' and movement['available_before'] == 0))):
                return 0
            at = self.clock()
            self.db.execute('''INSERT INTO public.product_waitlist_events
                (inventory_movement_id,product_id,available_stock,status,created_at,updated_at)
                VALUES (%s,%s,%s,'pending',%s,%s) ON CONFLICT (inventory_movement_id) DO NOTHING''',
                (movement_id, movement['product_id'], movement['available_after'], at, at))
            event = self.db.one('''SELECT * FROM public.product_waitlist_events
                WHERE inventory_movement_id=%s FOR UPDATE''', (movement_id,))
            if event['status'] != 'pending' or event['materialized_at']:
                return 0
            # Serialize distinct restocks for one Product before discovering
            # subscriptions, so one active subscription has one open mail work.
            self.db.one('SELECT id FROM public.products WHERE id=%s FOR UPDATE', (movement['product_id'],))
            product = self._product(movement['product_id'])
            if not product or product['available_stock'] <= 0:
                self.db.execute('''UPDATE public.product_waitlist_events SET status='skipped',
                    skipped_at=%s,materialized_at=%s,updated_at=%s WHERE id=%s''', (at, at, at, event['id']))
                return 0
            rows = self.db.rows('''SELECT s.id FROM public.product_waitlist_subscriptions s
                WHERE s.product_id=%s AND s.status='active' AND s.id>%s AND NOT EXISTS (
                    SELECT 1 FROM public.product_waitlist_notifications n WHERE n.subscription_id=s.id
                    AND (n.status IN ('pending','sending','failed','unknown') OR n.delivery_started_at IS NOT NULL))
                ORDER BY s.id LIMIT %s''', (movement['product_id'], event['materialization_cursor'] or 0, limit + 1))
            for subscription in rows[:limit]:
                self.db.execute('''INSERT INTO public.product_waitlist_notifications
                    (product_waitlist_event_id,subscription_id,status,created_at,updated_at)
                    VALUES (%s,%s,'pending',%s,%s) ON CONFLICT DO NOTHING''',
                    (event['id'], subscription['id'], at, at))
            more = len(rows) > limit
            self.db.execute('''UPDATE public.product_waitlist_events SET materialization_cursor=%s,
                status=%s,materialized_at=%s,updated_at=%s WHERE id=%s''',
                (rows[min(len(rows), limit) - 1]['id'] if rows else event['materialization_cursor'],
                 'pending' if more else 'complete', None if more else at, at, event['id']))
            return min(len(rows), limit)

    def deliver(self, notification_id):
        self._outside()
        if not self.enabled:
            return 'disabled'
        preview = self.db.one('SELECT subscription_id FROM public.product_waitlist_notifications WHERE id=%s', (notification_id,))
        if not preview:
            return 'not_ready'
        with transaction.atomic(using=self.db.using):
            subscription = self.db.one('SELECT * FROM public.product_waitlist_subscriptions WHERE id=%s FOR UPDATE',
                                       (preview['subscription_id'],))
            row = self.db.one('SELECT * FROM public.product_waitlist_notifications WHERE id=%s FOR UPDATE', (notification_id,))
            at = self.clock()
            if not row or row['status'] not in ('pending', 'failed', 'sending'):
                return 'not_ready'
            if row['delivery_started_at'] is not None:
                if row['delivery_started_at'] <= at - timedelta(seconds=180):
                    self.db.execute('''UPDATE public.product_waitlist_notifications SET status='unknown',
                        last_error='mail_delivery_unknown',next_attempt_at=NULL,updated_at=%s WHERE id=%s''', (at, row['id']))
                    return 'unknown'
                return 'not_ready'
            if row['attempts'] >= 3 or (row['next_attempt_at'] and row['next_attempt_at'] > at):
                return 'not_ready'
            event = self.db.one('SELECT product_id,status FROM public.product_waitlist_events WHERE id=%s',
                                (row['product_waitlist_event_id'],))
            product = self._product(subscription['product_id']) if subscription else None
            if (not subscription or subscription['status'] != 'active' or not event
                    or event['status'] == 'skipped' or event['product_id'] != subscription['product_id']
                    or not product or product['available_stock'] <= 0):
                self.db.execute('''UPDATE public.product_waitlist_notifications SET status='skipped',
                    next_attempt_at=NULL,updated_at=%s WHERE id=%s''', (at, row['id']))
                return 'ineligible'
            try:
                address = self.recipients.mail(self.cipher.decrypt_string(subscription['email']))
            except (ValueError, NotEligible):
                self.db.execute('''UPDATE public.product_waitlist_notifications SET status='skipped',
                    next_attempt_at=NULL,last_error='recipient_not_admitted',updated_at=%s WHERE id=%s''', (at, row['id']))
                return 'ineligible'
            row = self.db.one('''UPDATE public.product_waitlist_notifications SET status='sending',
                attempts=attempts+1,delivery_started_at=%s,next_attempt_at=NULL,updated_at=%s
                WHERE id=%s RETURNING *''', (at, at, row['id']))
        context = {'product_name': safe_name(product['name']), 'available_stock': product['available_stock'],
                   'product_url': self.links.origin + '/produk/' + str(product['id']),
                   'unsubscribe_url': self.links.create('unsubscribe', subscription['id'], at + timedelta(days=30))}
        text = (f"Produk tersedia kembali\n\nProduk: {context['product_name']}\nStok tersedia: {context['available_stock']}\n\n"
                f"Buka produk: {context['product_url']}\nBerhenti menerima notifikasi produk ini: {context['unsubscribe_url']}")
        outcome = self._send(address, 'Produk tersedia kembali | LhieStore', text,
                             'notifications/waitlist_restocked.html', context)
        with transaction.atomic(using=self.db.using):
            self.db.one('SELECT id FROM public.product_waitlist_subscriptions WHERE id=%s FOR UPDATE', (subscription['id'],))
            finished = self.clock()
            changed = self.db.execute('''UPDATE public.product_waitlist_notifications SET status=%s,
                sent_at=CASE WHEN %s='sent' THEN %s ELSE NULL END,
                failed_at=CASE WHEN %s='failed' THEN %s ELSE NULL END,
                last_error=%s,delivery_started_at=CASE WHEN %s='unknown' THEN delivery_started_at ELSE NULL END,
                next_attempt_at=%s,updated_at=%s WHERE id=%s AND status='sending' AND attempts=%s
                  AND delivery_started_at=%s AND delivery_started_at>%s''',
                (outcome, outcome, finished, outcome, finished, None if outcome == 'sent' else 'mail_delivery_' + outcome,
                 outcome, finished + timedelta(seconds=60) if outcome == 'failed' and row['attempts'] < 3 else None,
                 finished, row['id'], row['attempts'], row['delivery_started_at'], finished - timedelta(seconds=180)))
            if changed and outcome == 'sent':
                self.db.execute('''UPDATE public.product_waitlist_subscriptions SET status='notified',
                    notified_at=%s,updated_at=%s WHERE id=%s AND status='active' ''', (finished, finished, subscription['id']))
        return outcome if changed else 'fenced'

    def _page(self, name, table, predicate, params, limit):
        # Closed internal callers supply SQL identifiers/clauses. Rotating
        # keysets prevent one ineligible first page from starving later work.
        cursor = self._cursors.get(name, 0)
        rows = self.db.rows(f'SELECT id FROM public.{table} WHERE ({predicate}) AND id>%s ORDER BY id LIMIT %s',
                            (*params, cursor, limit))
        self._cursors[name] = rows[-1]['id'] if rows else 0
        return rows

    def recover(self, limit=25):
        self._outside()
        batch_size(limit)
        if not self.enabled:
            return {'disabled': True}
        at = self.clock()
        confirmations = self._page('confirm', 'product_waitlist_subscriptions',
            "status='pending_confirmation' AND confirmation_sent_at IS NULL AND confirmation_expires_at>%s "
            "AND confirmation_last_error IS DISTINCT FROM 'mail_delivery_unknown' "
            'AND (confirmation_attempts<3 OR confirmation_delivery_started_at IS NOT NULL)', (at,), limit)
        outcomes = {}
        for row in confirmations:
            result = self.confirm_mail(row['id'])
            outcomes[result] = outcomes.get(result, 0) + 1
        events = self._page('movement', 'inventory_movements',
            "quantity>0 AND available_after>0 AND (type='adjustment' OR (type='availability' AND available_before=0)) "
            'AND NOT EXISTS (SELECT 1 FROM public.product_waitlist_events e WHERE e.inventory_movement_id=inventory_movements.id '
            "AND e.status<>'pending')", (), limit)
        for row in events:
            self.materialize(row['id'], limit)
        rows = self._page('delivery', 'product_waitlist_notifications',
            "status IN ('pending','failed','sending') AND (next_attempt_at IS NULL OR next_attempt_at<=%s) "
            'AND (attempts<3 OR delivery_started_at IS NOT NULL)', (at,), limit)
        for row in rows:
            result = self.deliver(row['id'])
            outcomes[result] = outcomes.get(result, 0) + 1
        return outcomes


def safe_name(value):
    return re.sub(r'[\r\n]+', ' ', str(value or '').strip())[:160] or 'Produk'
