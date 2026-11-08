"""P25 changed flow on P01 fake-only PostgreSQL/Redis/SMTP; no live targets."""
from concurrent.futures import ThreadPoolExecutor
import json
from threading import Barrier
from types import SimpleNamespace
from uuid import uuid4


def exercise_notifications(fixtures):
    from django.db import connections, transaction
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.notifications.broadcast import materialize
    from lhie_candidate.notifications.mail import CredentialMail, DefiniteMailFailure, SinkMail
    from lhie_candidate.notifications.policy import BROADCAST, TestRecipients
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from lhie_candidate.readiness import verify_dependencies
    from lhie_candidate.receipt.protocol import ReceiptProtocol
    from lhie_candidate.receipt.source import ReceiptSource
    from lhie_candidate.receipt.transport import ReceiptResult
    from lhie_candidate.runtime import application_key
    from tests.test_checkout_postgres import admin_connection

    verify_dependencies()
    admin = admin_connection()
    db = Database()
    recipients = TestRecipients(frozenset({'1'}))
    transport = SimpleNamespace(execute=lambda *args: ReceiptResult(701, 'success'))
    service = Notifications(transport, recipients=recipients, rate=SimpleNamespace(acquire=lambda: True))
    cipher = LaravelEncrypter(application_key())
    key = str(uuid4())
    try:
        fixture = fixtures['python']
        checkout = CheckoutService()
        order = checkout.checkout(CheckoutRequest(fixture['customer_id'], fixture['cart_id'], 'p25-mail',
            checkout.quote_cart(fixture['customer_id'], fixture['cart_id'])))
        oid = str(order.order_id)
        assert order.payment_status == 'paid'
        admin.execute('UPDATE public.orders SET notification_email=%s WHERE id=%s',
                      (cipher.encrypt_string('snapshot@example.invalid'), oid))
        bcast = admin.execute('''INSERT INTO public.telegram_broadcasts
            (broadcast_key,message,created_at,updated_at) VALUES (%s,%s,now(),now()) RETURNING id''',
            (key, '<b>Verbatim</b> synthetic notice')).fetchone()[0]
        assert materialize(db, recipients, bcast, service.record) == 1
        assert materialize(db, recipients, bcast, service.record) == 0
        effect = db.one('SELECT * FROM public.telegram_outbound_effects WHERE effect_key LIKE %s',
                        ('broadcast:' + key + ':%',))
        assert effect['telegram_chat_id'] == '1'
        assert ReceiptProtocol().claim(effect['id']) is None
        # The generalized shared protocol is the changed concurrency boundary:
        # synchronize two connections, claim once, and token-fence the loser.
        barrier = Barrier(2)
        def competing():
            try:
                protocol = Notifications(transport, recipients=recipients).protocol
                barrier.wait(timeout=10)
                return protocol.claim(effect['id'])
            finally:
                connections.close_all()
        with ThreadPoolExecutor(max_workers=2) as pool:
            claims = list(pool.map(lambda _: competing(), range(2)))
        claimed = [row for row in claims if row]
        assert len(claimed) == 1
        claim = claimed[0]
        assert service.protocol.finalize_sent(effect['id'], 'wrong-token', 701) is None
        sent = service.protocol.finalize_sent(effect['id'], claim['claim_token'], 701)
        # Simulate death between ledger ACK and projection: repair, not resend.
        assert service.project(sent) == 1
        assert service.project(sent) == 0
        assert service.deliver(effect['id']) == 'not_ready'
        recipient = db.one('SELECT * FROM public.telegram_broadcast_recipients WHERE id=%s', (sent['source_id'],))
        assert recipient['sent_at'] is not None
        assert recipient['attempts'] == 1
        assert admin.execute('SELECT count(*) FROM public.telegram_broadcast_recipients WHERE broadcast_id=%s',
                             (bcast,)).fetchone() == (1,)
        # Lost ACK and a lost lease are terminal-reviewable across recovery.
        with transaction.atomic():
            ambiguous = service.record(key='p25-ambiguous:' + key, kind=BROADCAST,
                model='TelegramBroadcastRecipient', source=recipient['id'], chat='1', target='customer',
                locale='id', template='admin-broadcast-v1')
        service.transport = SimpleNamespace(execute=lambda *args: ReceiptResult(None, 'ambiguous'))
        assert service.deliver(ambiguous['id']) == 'unknown'
        assert service.deliver(ambiguous['id']) == 'not_ready'
        with transaction.atomic():
            expired = service.record(key='p25-expired:' + key, kind=BROADCAST,
                model='TelegramBroadcastRecipient', source=recipient['id'], chat='1', target='customer',
                locale='id', template='admin-broadcast-v1')
        service.protocol.claim(expired['id'])
        admin.execute("UPDATE public.telegram_outbound_effects SET lease_until=now()-interval '1 day' WHERE id=%s", (expired['id'],))
        assert service.protocol.recover_expired_leases(25, chats=['1']) == 1
        assert service.deliver(expired['id']) == 'not_ready'
        assert db.one('SELECT state FROM public.telegram_outbound_effects WHERE id=%s', (expired['id'],))['state'] == 'unknown'
        # Actual local fake relay, isolated from real Telegram.
        real = NotificationTransport().execute('sendMessage', {'chat_id': '1', 'text': 'P25 synthetic notice'})
        assert real.outcome == 'success' and real.message_id > 0
        source = ReceiptSource(cipher, signing_key=application_key(), invoice_base_url='http://127.0.0.1:18801')
        captured = []
        class Mail:
            fail = True
            def send(self, address, subject, text):
                captured.append((address, subject, text))
                if self.fail:
                    raise DefiniteMailFailure()
                SinkMail().send(address, subject, text)
        mail_transport = Mail()
        mail = CredentialMail(source, cipher, recipients=recipients, transport=mail_transport)
        event = mail.record(oid)
        assert event and event['recipient_email'] != 'snapshot@example.invalid'
        admin.execute('UPDATE public.orders SET notification_email=%s WHERE id=%s',
                      (cipher.encrypt_string('changed@example.invalid'), oid))
        assert mail.record(oid)['recipient_email'] == event['recipient_email']
        assert mail.deliver(event['id']) == 'failed'
        assert captured[-1][0] == 'snapshot@example.invalid'
        admin.execute("UPDATE public.order_notification_events SET next_attempt_at=now()-interval '1 day' WHERE id=%s", (event['id'],))
        mail_transport.fail = False
        assert mail.deliver(event['id']) == 'sent'
        assert captured[-1][0] == 'snapshot@example.invalid'
        assert 'INV-' in captured[-1][1]
        assert source.load_customer(oid, fixture['customer_id'], receipt=True)['groups'][0]['product_name'] in captured[-1][2]
        assert mail.deliver(event['id']) == 'not_ready'
        # Explicit resend followed by uncertain SMTP outcome is not retried.
        admin.execute("UPDATE public.order_notification_events SET status='pending',attempts=0,next_attempt_at=NULL WHERE id=%s", (event['id'],))
        def lost_ack(*args):
            raise TimeoutError('private SMTP text must not be persisted')
        mail.transport = SimpleNamespace(send=lost_ack)
        assert mail.deliver(event['id']) == 'unknown'
        assert mail.deliver(event['id']) == 'not_ready'
        stored = db.one('SELECT * FROM public.order_notification_events WHERE id=%s', (event['id'],))
        assert stored['status'] == 'failed' and stored['last_error'] == 'mail_delivery_unknown'
        assert stored['delivery_started_at'] is not None
        assert 'private' not in stored['last_error']
        assert admin.execute("SELECT count(*) FROM public.store_balance_entries WHERE source_id=%s AND kind='purchase_debit'", (oid,)).fetchone() == (1,)
        assert admin.execute('SELECT count(*) FROM public.product_stock_items s JOIN public.order_items i ON i.id=s.order_item_id WHERE i.order_id=%s', (oid,)).fetchone() == (2,)
        return {'notification_claim_fencing': 'passed', 'snapshot_mail_sink_retry': 'passed',
                'unknown_no_resend': 'passed', 'sent_projection_no_resend': 'passed',
                'fake_relay': 'passed', 'financial_history_unchanged': 'passed'}
    finally:
        admin.close()
