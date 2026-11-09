"""Guest status mail over the existing event ledger, never credential authority."""
from django.db import transaction
from django.template.loader import render_to_string

from ..receipt.protocol import _now
from .mail import CredentialMail, DefiniteMailFailure
from .policy import NotEligible

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
FOOTER = ('Email status ini tidak memuat data akun. Data akun dikirim lewat email terpisah setelah pembayaran '
          'terverifikasi bila email diisi saat checkout, dan tetap bisa dibuka dari halaman invoice sesuai kebijakan pesanan yang berlaku.')


def record_transition(db, order_id, kind):
    """Atomic source handoff, with no rendering, decryption or external I/O."""
    from django.conf import settings
    if not getattr(getattr(settings, 'RUNTIME', None), 'notifications', False):
        return
    if kind not in COPY:
        raise ValueError('Invalid milestone')
    db.require_transaction()
    at = _now()
    db.execute('''INSERT INTO public.order_notification_events
        (order_id,event_type,recipient_email,status,attempts,next_attempt_at,created_at,updated_at)
        SELECT id,%s,notification_email,'pending',0,%s,%s,%s FROM public.orders
        WHERE id=%s AND notification_email IS NOT NULL ON CONFLICT (order_id,event_type) DO NOTHING''',
        (kind, at, at, at, order_id))
    db.execute('''UPDATE public.order_notification_events e SET recipient_email=o.notification_email
        FROM public.orders o WHERE e.order_id=o.id AND o.id=%s AND e.event_type=%s
        AND e.recipient_email IS NULL AND e.status='pending' AND e.attempts=0 AND e.delivery_started_at IS NULL''',
        (order_id, kind))


class MilestoneMail(CredentialMail):
    owned_types = tuple(COPY)

    def record(self, order_id, kind):
        if kind not in self.owned_types:
            raise ValueError('Invalid milestone')
        with transaction.atomic(using=self.db.using):
            order = self.db.one('SELECT id,notification_email FROM public.orders WHERE id=%s FOR UPDATE', (order_id,))
            if not order or not order['notification_email']:
                return None
            recipient = self.recipients.mail(self.encrypter.decrypt_string(order['notification_email']).strip().lower())
            at = _now()
            self.db.execute('''INSERT INTO public.order_notification_events
                (order_id,event_type,recipient_email,status,attempts,next_attempt_at,created_at,updated_at)
                VALUES (%s,%s,%s,'pending',0,%s,%s,%s) ON CONFLICT (order_id,event_type) DO NOTHING''',
                (order_id, kind, self.encrypter.encrypt_string(recipient), at, at, at))
            # Adopt an original PHP/P21 pending handoff only before its first
            # attempt. A retry is never redirected to a changed Order address.
            self.db.execute('''UPDATE public.order_notification_events SET recipient_email=%s
                WHERE order_id=%s AND event_type=%s AND recipient_email IS NULL
                  AND status='pending' AND attempts=0 AND delivery_started_at IS NULL''',
                (self.encrypter.encrypt_string(recipient), order_id, kind))
            return self.db.one('SELECT * FROM public.order_notification_events WHERE order_id=%s AND event_type=%s', (order_id, kind))

    def deliver(self, event_id):
        self._outside()
        claim = self._claim(event_id)
        if claim is None:
            return 'not_ready'
        try:
            recipient = self.recipients.mail(self.encrypter.decrypt_string(claim['recipient_email']))
            order = self.db.one('SELECT id,invoice_number,order_status,expires_at,created_at FROM public.orders WHERE id=%s', (claim['order_id'],))
            if not order:
                raise ValueError('Missing order')
            subject, headline, intro = COPY[claim['event_type']]
            items = self.db.rows('SELECT product_name,quantity FROM public.order_items WHERE order_id=%s ORDER BY id', (order['id'],))
            invoice_url = self.source.invoice_url(str(order['id']), order['created_at'])
            data = {'headline': headline, 'intro': intro, 'invoice_number': order['invoice_number'],
                    'order_status': order['order_status'], 'status_label': STATUS[order['order_status']],
                    'items': [{'name': item['product_name'].strip()[:160], 'quantity': item['quantity']} for item in items],
                    'payment_deadline': order['expires_at'].strftime('%Y-%m-%d %H:%M WIB')
                        if claim['event_type'] == 'order_created' and order['order_status'] == 'pending_payment' and order['expires_at'] else None,
                    'invoice_url': invoice_url, 'order_access_url': 'http://127.0.0.1:18801/akses-pesanan', 'footer': FOOTER}
            text = render_to_string('notifications/milestone.txt', data)
            html = render_to_string('notifications/milestone.html', data)
        except Exception:
            self._finish(claim, 'cancelled', 'milestone_unavailable')
            return 'ineligible'
        try:
            self.transport.send(recipient, f"{subject} {order['invoice_number']} | LhieStore", text, html=html)
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
            raise ValueError('Invalid milestone batch')
        rows = self.db.rows('''SELECT id,order_status,payment_status FROM public.orders
            WHERE notification_email IS NOT NULL AND (%s::uuid IS NULL OR id>%s::uuid)
            ORDER BY id LIMIT %s''', (self._source_cursor, self._source_cursor, limit))
        self._source_cursor = str(rows[-1]['id']) if rows else None
        for order in rows:
            # Current committed state is a durable handoff; do not fabricate
            # historical payment/completion events for terminal/refunded Orders.
            kinds = ['order_created']
            if order['payment_status'] == 'paid' and order['order_status'] in ('processing', 'completed'):
                kinds.append('payment_confirmed')
                if order['order_status'] == 'completed':
                    kinds.append('fulfillment_completed')
            elif order['order_status'] in ('expired', 'cancelled'):
                kinds.append('order_' + order['order_status'])
            for kind in kinds:
                try:
                    self.record(order['id'], kind)
                except (NotEligible, ValueError):
                    # Local recipient admission never stalls unrelated allowed
                    # work, and never degrades to a real recipient transport.
                    continue
        rows = self.db.rows('''SELECT id FROM public.order_notification_events
            WHERE event_type=ANY(%s) AND status='pending'
            AND (next_attempt_at IS NULL OR next_attempt_at<=%s) ORDER BY id LIMIT %s''',
            (list(self.owned_types), _now(), limit))
        counts = {}
        for row in rows:
            result = self.deliver(row['id'])
            counts[result] = counts.get(result, 0) + 1
        return counts
