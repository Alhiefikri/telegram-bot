"""Guest milestone mail, separate from the credentials_ready delivery owner."""
import json
from datetime import timedelta
from html import escape
from zoneinfo import ZoneInfo

from django.db import connections, transaction

from ..branding import APP_NAME

from ..checkout.db import Database
from ..compat.identity import signed_url_digest
from ..receipt.protocol import _now
from .mail import DefiniteMailFailure, SinkMail
from .policy import NotEligible, TestRecipients

COPY = {
    'order_created': ('Pesanan dibuat', 'Pesanan website berhasil dibuat',
                      'Pesanan Anda tercatat. Gunakan invoice untuk melihat instruksi dan status terbaru.'),
    'payment_confirmed': ('Pembayaran dikonfirmasi', 'Pembayaran pesanan sudah dikonfirmasi',
                          'Pembayaran Anda sudah diterima dan pesanan masuk ke tahap pemrosesan.'),
    'fulfillment_completed': ('Fulfillment selesai', 'Fulfillment pesanan sudah selesai',
                              'Pesanan Anda ditandai selesai oleh sistem atau admin.'),
    'order_expired': ('Pesanan kedaluwarsa', 'Pesanan website sudah kedaluwarsa',
                      'Batas pembayaran pesanan telah lewat dan pesanan tidak lagi menunggu pembayaran.'),
    'order_cancelled': ('Pesanan dibatalkan', 'Pesanan website sudah dibatalkan',
                        'Pesanan Anda tidak lagi aktif dan tidak akan diproses sebagai pesanan baru.'),
}
STATUS = {'draft': 'Draft', 'pending_payment': 'Menunggu pembayaran', 'processing': 'Sedang diproses',
          'completed': 'Selesai', 'cancelled': 'Dibatalkan', 'expired': 'Kedaluwarsa'}
FOOTER = ('Email status ini tidak memuat data akun. Data akun dikirim lewat email terpisah setelah '
          'pembayaran terverifikasi bila email diisi saat checkout, dan tetap bisa dibuka dari halaman '
          'invoice sesuai kebijakan pesanan yang berlaku.')


def record_guest_event(db, order_id, event_type):
    """Durable transaction handoff; never inspect or change credential events."""
    db.require_transaction()
    if event_type not in COPY:
        raise ValueError('Invalid guest milestone')
    return db.execute('''INSERT INTO public.order_notification_events
        (order_id,event_type,recipient_email,status,attempts,created_at,updated_at)
        SELECT id,%s,notification_email,'pending',0,%s,%s FROM public.orders
        WHERE id=%s AND notification_email IS NOT NULL
        ON CONFLICT (order_id,event_type) DO NOTHING''', (event_type, _now(), _now(), order_id))


def refresh_pending_order_created(db, order_id):
    """Reauthorize only an untouched order-created event to the locked order contact."""
    db.require_transaction()
    return db.execute('''UPDATE public.order_notification_events e
        SET recipient_email=o.notification_email,updated_at=%s
        FROM public.orders o
        WHERE e.order_id=o.id AND e.order_id=%s AND e.event_type='order_created'
          AND e.status='pending' AND e.attempts=0 AND e.delivery_started_at IS NULL
          AND o.notification_email IS NOT NULL''', (_now(), order_id))


def milestone_content(source, *, app_name=None):
    app_name = APP_NAME if app_name is None else app_name
    kind = source['event_type']
    if kind not in COPY or source['order_status'] not in STATUS:
        raise NotEligible()
    subject, headline, intro = COPY[kind]
    subject += ' ' + source['invoice_number'] + ' | ' + app_name
    label, status = STATUS[source['order_status']], source['order_status']
    items = source['items']
    e = lambda value: escape(str(value), quote=True).replace('&#x27;', '&#039;')
    lines = [headline, '', intro, '', 'Invoice: ' + e(source['invoice_number']),
             'Status saat ini: ' + e(label) + ' (' + e(status) + ')', '', 'Ringkasan pesanan:']
    lines.extend(('- ' + e(item['name']) + ' x ' + str(item['quantity'])) for item in items)
    if not items:
        lines.append('- Detail produk tersedia di halaman invoice.')
    if source.get('payment_deadline'):
        lines.extend(('', 'Batas pembayaran: ' + e(source['payment_deadline'])))
    lines.extend(('', 'Buka invoice dan status: ' + e(source['invoice_url']),
                  'Akses pesanan: ' + e(source['order_access_url']), '', FOOTER))
    list_html = ''.join('<li>' + e(item['name']) + ' × ' + str(item['quantity']) + '</li>' for item in items)
    if not items:
        list_html = '<li>Detail produk tersedia di halaman invoice.</li>'
    deadline = ('<p><strong>Batas pembayaran:</strong> ' + e(source['payment_deadline']) + '</p>') if source.get('payment_deadline') else ''
    html = f'''<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{e(headline)}</title>
</head>
<body style="margin:0;background:#f4f1e8;color:#161616;font-family:Arial,sans-serif;line-height:1.6">
    <main style="max-width:620px;margin:0 auto;padding:32px 20px">
        <section style="background:#fff;border:2px solid #161616;padding:24px;box-shadow:5px 5px 0 #161616">
            <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">{e(app_name)}</p>
            <h1 style="margin:0 0 16px;font-size:25px;line-height:1.2">{e(headline)}</h1>
            <p>{e(intro)}</p>
            <dl>
                <dt style="font-weight:700">Invoice</dt>
                <dd style="margin:0 0 12px">{e(source['invoice_number'])}</dd>
                <dt style="font-weight:700">Status saat ini</dt>
                <dd style="margin:0 0 12px">{e(label)} ({e(status)})</dd>
            </dl>
            <h2 style="font-size:17px">Ringkasan pesanan</h2>
            <ul>{list_html}</ul>
            {deadline}
            <p style="margin-top:24px">
                <a href="{e(source['invoice_url'])}" style="display:inline-block;background:#f3d34a;border:2px solid #161616;color:#161616;padding:11px 16px;font-weight:700;text-decoration:none">Buka invoice dan status</a>
            </p>
            <p><a href="{e(source['order_access_url'])}">Akses pesanan</a></p>
            <p style="margin-top:24px;font-size:13px;color:#555">{FOOTER}</p>
        </section>
    </main>
</body>
</html>'''
    return {'subject': subject, 'text': '\n'.join(lines), 'html': html}


class GuestMail:
    def __init__(self, encrypter, *, using='default', transport=None, recipients=None,
                 invoice_base_url='', signing_key=None, app_name=None):
        self.db = Database(using)
        self.encrypter, self.signing_key = encrypter, signing_key
        self.transport = transport if transport is not None else SinkMail()
        self.recipients = recipients if recipients is not None else TestRecipients()
        self.invoice_base_url = invoice_base_url.rstrip('/')
        self.app_name = APP_NAME if app_name is None else app_name

    def _outside(self):
        if connections[self.db.using].in_atomic_block or not connections[self.db.using].get_autocommit():
            raise RuntimeError('Guest mail requires an outermost boundary')

    def _snapshot(self, event):
        if event.get('recipient_email'):
            plaintext = self.encrypter.decrypt_string(event['recipient_email'])
            try:
                snapshot = json.loads(plaintext)
            except ValueError:
                snapshot = None
            if isinstance(snapshot, dict):
                if (snapshot.get('event_type') != event['event_type'] or snapshot.get('order_id') != str(event['order_id'])
                        or set(snapshot) != {'event_type', 'order_id', 'recipient', 'content'}):
                    raise NotEligible()
                self.recipients.mail(snapshot['recipient'])
                return snapshot
            recipient = self.recipients.mail(plaintext.strip().lower())
        else:
            recipient = None
        order = self.db.one('''SELECT id,invoice_number,order_status,notification_email,expires_at,created_at
            FROM public.orders WHERE id=%s FOR UPDATE''', (event['order_id'],))
        if not order or not self.invoice_base_url or not self.signing_key:
            raise NotEligible()
        if recipient is None:
            recipient = self.recipients.mail(self.encrypter.decrypt_string(order['notification_email']).strip().lower())
        created = order['created_at']
        if created.tzinfo is None:
            created = created.replace(tzinfo=ZoneInfo('Asia/Jakarta'))
        expires = int((created + timedelta(days=7)).timestamp())
        unsigned = f'/shop/orders/{order["id"]}?expires={expires}'
        url = self.invoice_base_url + unsigned + '&signature=' + signed_url_digest(unsigned, self.signing_key)
        items = self.db.rows('SELECT product_name,quantity FROM public.order_items WHERE order_id=%s ORDER BY id', (order['id'],))
        source = {'event_type': event['event_type'], 'invoice_number': order['invoice_number'],
                  'order_status': order['order_status'] if order['order_status'] in STATUS else 'pending_payment',
                  'items': [{'name': str(item['product_name']).strip()[:160], 'quantity': int(item['quantity'])} for item in items],
                  'payment_deadline': (order['expires_at'].strftime('%Y-%m-%d %H:%M') + ' WIB')
                      if event['event_type'] == 'order_created' and order['order_status'] == 'pending_payment' and order['expires_at'] else None,
                  'invoice_url': url, 'order_access_url': self.invoice_base_url + '/akses-pesanan'}
        snapshot = {'event_type': event['event_type'], 'order_id': str(order['id']), 'recipient': recipient,
                    'content': milestone_content(source, app_name=self.app_name)}
        self.db.execute('UPDATE public.order_notification_events SET recipient_email=%s,updated_at=%s WHERE id=%s',
                        (self.encrypter.encrypt_json(snapshot), _now(), event['id']))
        return snapshot

    def _claim(self, event_id):
        with transaction.atomic(using=self.db.using):
            # Match Order -> event ordering used by transition/capture writers.
            source = self.db.one('SELECT order_id FROM public.order_notification_events WHERE id=%s AND event_type=ANY(%s)', (event_id, list(COPY)))
            if not source:
                return None
            self.db.one('SELECT id FROM public.orders WHERE id=%s FOR UPDATE', (source['order_id'],))
            event = self.db.one('SELECT * FROM public.order_notification_events WHERE id=%s AND event_type=ANY(%s) FOR UPDATE', (event_id, list(COPY)))
            at = _now()
            if not event or event['status'] not in ('pending', 'sending'):
                return None
            if event['status'] == 'sending' or event['delivery_started_at']:
                if event['next_attempt_at'] and event['next_attempt_at'] > at:
                    return None
                self.db.execute('''UPDATE public.order_notification_events SET status='failed',failed_at=%s,
                    last_error='mail_delivery_unknown',next_attempt_at=NULL,updated_at=%s WHERE id=%s''', (at, at, event_id))
                return None
            if (event['next_attempt_at'] and event['next_attempt_at'] > at) or event['attempts'] >= 3:
                return None
            snapshot = self._snapshot(event)
            claim = self.db.one('''UPDATE public.order_notification_events SET status='sending',attempts=attempts+1,
                delivery_started_at=%s,next_attempt_at=%s,last_error=NULL,updated_at=%s WHERE id=%s RETURNING *''',
                (at, at + timedelta(seconds=180), at, event_id))
            return claim, snapshot

    def _finish(self, claim, status, error=None, *, unknown=False, retry=False):
        at = _now()
        return self.db.execute('''UPDATE public.order_notification_events SET status=%s,last_error=%s,
            sent_at=CASE WHEN %s='sent' THEN %s ELSE sent_at END,
            failed_at=CASE WHEN %s='failed' THEN %s ELSE NULL END,
            delivery_started_at=CASE WHEN %s THEN delivery_started_at ELSE NULL END,
            next_attempt_at=%s,updated_at=%s WHERE id=%s AND event_type=ANY(%s) AND status='sending'
              AND attempts=%s AND delivery_started_at=%s AND next_attempt_at>%s''',
            (status, error, status, at, status, at, unknown,
             at + timedelta(seconds=60 if claim['attempts'] == 1 else 300) if retry else None,
             at, claim['id'], list(COPY), claim['attempts'], claim['delivery_started_at'], at))

    def deliver(self, event_id):
        self._outside()
        if not self.recipients.telegram:
            return 'disabled'
        try:
            work = self._claim(event_id)
        except (NotEligible, ValueError, TypeError):
            return 'ineligible'
        if work is None:
            return 'not_ready'
        claim, snapshot = work
        content = snapshot['content']
        try:
            self.transport.send(snapshot['recipient'], content['subject'], content['text'], content['html'])
        except DefiniteMailFailure:
            retry = claim['attempts'] < 3
            return 'failed' if self._finish(claim, 'pending' if retry else 'failed', 'mail_delivery_failed', retry=retry) else 'fenced'
        except Exception:
            return 'unknown' if self._finish(claim, 'failed', 'mail_delivery_unknown', unknown=True) else 'fenced'
        return 'sent' if self._finish(claim, 'sent') else 'fenced'

    def recover(self, limit=25):
        self._outside()
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError('Invalid guest mail batch')
        if not self.recipients.telegram:
            return {'disabled': True}
        # Current durable lifecycle can reconstruct missed queue/source handoffs.
        orders = self.db.rows('''SELECT o.id,o.order_status,o.payment_status FROM public.orders o
            WHERE o.notification_email IS NOT NULL AND EXISTS (
              SELECT 1 FROM (VALUES ('order_created'),('payment_confirmed'),('fulfillment_completed'),
                ('order_expired'),('order_cancelled')) AS kinds(kind)
              WHERE (kind='order_created' OR kind='payment_confirmed' AND o.payment_status='paid'
                OR kind='fulfillment_completed' AND o.order_status='completed'
                OR kind='order_expired' AND o.order_status='expired'
                OR kind='order_cancelled' AND o.order_status='cancelled')
              AND NOT EXISTS(SELECT 1 FROM public.order_notification_events e
                WHERE e.order_id=o.id AND e.event_type=kind)) ORDER BY o.id LIMIT %s''', (limit,))
        for order in orders:
            with transaction.atomic(using=self.db.using):
                current = self.db.one('SELECT id,order_status,payment_status FROM public.orders WHERE id=%s FOR UPDATE', (order['id'],))
                if not current:
                    continue
                record_guest_event(self.db, current['id'], 'order_created')
                if current['payment_status'] == 'paid':
                    record_guest_event(self.db, current['id'], 'payment_confirmed')
                milestone = {'completed': 'fulfillment_completed', 'expired': 'order_expired', 'cancelled': 'order_cancelled'}.get(current['order_status'])
                if milestone:
                    record_guest_event(self.db, current['id'], milestone)
        rows = self.db.rows('''SELECT id FROM public.order_notification_events WHERE event_type=ANY(%s)
            AND (status='pending' AND (next_attempt_at IS NULL OR next_attempt_at<=%s)
              OR status='sending' AND (next_attempt_at IS NULL OR next_attempt_at<=%s))
            ORDER BY id LIMIT %s''', (list(COPY), _now(), _now(), limit))
        counts = {}
        for row in rows:
            result = self.deliver(row['id'])
            counts[result] = counts.get(result, 0) + 1
        return counts
