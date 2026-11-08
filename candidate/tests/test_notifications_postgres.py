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
            def send(self, address, subject, text, *, html):
                captured.append((address, subject, text))
                if self.fail:
                    raise DefiniteMailFailure()
                assert '<!doctype html>' in html and 'Simpan email ini di tempat aman.' in html
                SinkMail().send(address, subject, text, html=html)
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
        def lost_ack(*args, **kwargs):
            raise TimeoutError('private SMTP text must not be persisted')
        mail.transport = SimpleNamespace(send=lost_ack)
        assert mail.deliver(event['id']) == 'unknown'
        assert mail.deliver(event['id']) == 'not_ready'
        stored = db.one('SELECT * FROM public.order_notification_events WHERE id=%s', (event['id'],))
        assert stored['status'] == 'failed' and stored['last_error'] == 'mail_delivery_unknown'
        assert stored['delivery_started_at'] is not None
        assert 'private' not in stored['last_error']
        from lhie_candidate.notifications.policy import PROOF
        from lhie_candidate.notifications.proof_publication import SalesProofPublication
        admin.execute('''INSERT INTO public.store_channel_settings
            (singleton_key,sales_proof_enabled,telegram_chat_id,created_at,updated_at)
            VALUES ('store_channel',true,'1',now(),now()) ON CONFLICT (singleton_key)
            DO UPDATE SET sales_proof_enabled=true,telegram_chat_id='1' ''')
        proof = SalesProofPublication(recipients)
        public = Notifications(NotificationTransport(), recipients=recipients, proof=proof, families=(PROOF,))
        assert proof.discover(public.record, 10) >= 1
        effect = db.one('''SELECT * FROM public.telegram_outbound_effects
            WHERE effect_type=%s AND source_id=%s''', (PROOF, oid))
        assert effect and effect['template_version'] == 'sales-proof-post-v2'
        snapshot = proof.eligibility.load(oid)
        assert snapshot['buyer_name'].endswith('***')
        assert not any(name in snapshot for name in ('email', 'password', 'credentials', 'telegram_id'))
        outcome = public.deliver(effect['id'])
        assert outcome == 'sent', (outcome, db.one('SELECT state,last_error_code FROM public.telegram_outbound_effects WHERE id=%s', (effect['id'],)))
        assert public.deliver(effect['id']) == 'not_ready'
        post = db.one('SELECT * FROM public.sales_proof_posts WHERE order_id=%s', (oid,))
        assert post['status'] == 'sent' and post['attempts'] == 1 and post['telegram_message_id']
        # Repair after a lost projection is not another public send.
        admin.execute("UPDATE public.sales_proof_posts SET status='pending',sent_at=NULL WHERE order_id=%s", (oid,))
        sent_proof = db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (effect['id'],))
        assert public.project(sent_proof) == 1
        assert public.project(sent_proof) == 0
        assert admin.execute("SELECT count(*) FROM public.store_balance_entries WHERE source_id=%s AND kind='purchase_debit'", (oid,)).fetchone() == (1,)
        assert admin.execute('SELECT count(*) FROM public.product_stock_items s JOIN public.order_items i ON i.id=s.order_item_id WHERE i.order_id=%s', (oid,)).fetchone() == (2,)
        from tests.test_waitlist_postgres import exercise_waitlist
        waitlist = exercise_waitlist()
        workers = exercise_notification_workers(fixtures)
        notices = exercise_order_notices(fixtures)
        return {**waitlist, **workers, **notices, 'sales_proof_publication': 'passed', 'credential_html_mail': 'passed',
                'notification_claim_fencing': 'passed', 'snapshot_mail_sink_retry': 'passed',
                'unknown_no_resend': 'passed', 'sent_projection_no_resend': 'passed',
                'fake_relay': 'passed', 'financial_history_unchanged': 'passed'}
    finally:
        admin.close()


def exercise_order_notices(fixtures):
    from datetime import timedelta
    from django.db import connections
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.checkout.lifecycle import OrderLifecycle
    from lhie_candidate.checkout.manual import ManualQrisPreparation
    from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
    from lhie_candidate.notifications.order_notices import OrderNotices, PENDING, PAID, EXPIRED
    from lhie_candidate.notifications.policy import ORDER_TYPES, TestRecipients
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from lhie_candidate.receipt.protocol import _now
    from tests.test_checkout_postgres import admin_connection
    admin, db = admin_connection(), Database()
    captured = []

    class Capture:
        def execute(self, method, payload):
            captured.append(payload)
            return NotificationTransport().execute(method, payload)

    try:
        admin.execute("UPDATE public.manual_payment_settings SET admin_telegram_chat_id='1' WHERE is_active=true")
        recipients = TestRecipients(frozenset({'1'}))
        owner = OrderNotices(recipients)
        service = Notifications(Capture(), recipients=recipients, order_notices=owner, families=ORDER_TYPES)
        fixture = fixtures['last_stock'][0]
        checkout = CheckoutService(preparation=ManualQrisPreparation(ready=lambda: True))
        pending = checkout.checkout(CheckoutRequest(fixture['customer_id'], fixture['cart_id'], 'p25-notice-pending',
            checkout.quote_cart(fixture['customer_id'], fixture['cart_id'])))
        owner.discover(service.record, 100)
        effect = db.one('SELECT * FROM public.telegram_outbound_effects WHERE source_id=%s AND effect_type=%s',
                        (str(pending.order_id), PENDING))
        assert effect, 'Pending admin notice was not discovered'
        service.payload(effect)  # Exercise the real renderer directly for redacted private diagnostics.
        result = service.deliver(effect['id'])
        assert result == 'sent', (result, db.one('SELECT state,last_error_code FROM public.telegram_outbound_effects WHERE id=%s', (effect['id'],)))
        assert 'Checkout belum bayar' in captured[-1]['text'] and 'QR Manual' in captured[-1]['text']
        assert db.one('SELECT admin_pending_order_notification_message_id FROM public.orders WHERE id=%s',
                      (str(pending.order_id),))['admin_pending_order_notification_message_id']
        assert service.deliver(effect['id']) == 'not_ready'
        OrderLifecycle(clock=lambda: _now() + timedelta(days=1)).apply_pending_expiry(str(pending.order_id))
        owner.discover(service.record, 100)
        owner.discover(service.record, 100)  # Rotate the keyset after the same Order changes state.
        expired = db.one('SELECT * FROM public.telegram_outbound_effects WHERE source_id=%s AND effect_type=%s',
                         (str(pending.order_id), EXPIRED))
        assert expired and service.deliver(expired['id']) == 'sent'
        assert 'Stok sudah dilepas kembali.' in captured[-1]['text']
        assert db.one('SELECT admin_pending_order_notification_deleted_at FROM public.orders WHERE id=%s',
                      (str(pending.order_id),))['admin_pending_order_notification_deleted_at']
        oid = admin.execute("SELECT id FROM public.orders WHERE customer_id=%s AND payment_status='paid' ORDER BY id LIMIT 1",
                            (fixtures['python']['customer_id'],)).fetchone()[0]
        owner.discover(service.record, 100)
        paid = db.one('SELECT * FROM public.telegram_outbound_effects WHERE source_id=%s AND effect_type=%s', (str(oid), PAID))
        assert paid and service.deliver(paid['id']) == 'sent'
        assert 'Hasil order' in captured[-1]['text'] and 'Order selesai. Tidak perlu proses ulang.' in captured[-1]['text']
        assert 'unit terkirim' in captured[-1]['text']
        sent = db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (paid['id'],))
        admin.execute('UPDATE public.orders SET admin_paid_order_notified_at=NULL WHERE id=%s', (oid,))
        before = len(captured)
        assert service.project(sent) == 1 and service.project(sent) == 0
        assert len(captured) == before
        return {'order_notice_pending_expired_cleanup': 'passed', 'order_notice_paid_delivery_evidence': 'passed',
                'order_notice_projection_no_resend': 'passed'}
    finally:
        connections.close_all()
        admin.close()


def slow_mail_worker():
    """Harness-only transport injection; never a production/CLI configuration."""
    from pathlib import Path
    import signal
    import time
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.workers.consumer import BoundedConsumer
    from lhie_candidate.workers.contract import queue_spec
    from lhie_candidate.workers.metrics import WorkerMetrics
    from lhie_candidate.workers.services import build_handler
    from lhie_candidate.notifications.mail import SinkMail
    from lhie_candidate.readiness import verify_database
    verify_database()
    handler = build_handler('mail')

    class DelayedSink:
        def send(self, *args, **kwargs):
            Path('/evidence/p25-mail-started').touch(mode=0o600)
            deadline = time.monotonic() + 45
            while not Path('/evidence/p25-mail-release').exists():
                if time.monotonic() >= deadline:
                    raise TimeoutError()
                time.sleep(0.05)
            return SinkMail().send(*args, **kwargs)

    handler.credential.transport = DelayedSink()
    consumer = BoundedConsumer(queue_spec('mail'), handler, WorkerMetrics('mail'))
    signal.signal(signal.SIGTERM, consumer.request_stop)
    signal.signal(signal.SIGINT, consumer.request_stop)
    consumer.run()


def isolated_test_stack():
    import sys
    from unittest.mock import patch
    from lhie_candidate.workers import supervisor
    planned = supervisor.commands('/evidence', include_http=True, notifications=True)
    planned['mail'] = [sys.executable, '-c', 'from tests.test_notifications_postgres import slow_mail_worker; slow_mail_worker()']
    with patch.object(supervisor, 'commands', return_value=planned):
        return supervisor.run('/evidence', notifications=True)


def exercise_notification_workers(fixtures):
    from pathlib import Path
    import subprocess
    import sys
    import time
    from django.db import connections
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.receipt.runtime import receipt_service
    from lhie_candidate.runtime import application_key
    from tests.test_checkout_postgres import admin_connection
    from tests.test_workers_postgres import _CgroupSampler, _webhook, _wait_http, _wait_for
    admin = admin_connection()
    root = Path('/evidence')
    started, release = root / 'p25-mail-started', root / 'p25-mail-release'
    started.unlink(missing_ok=True)
    release.unlink(missing_ok=True)
    process = None
    sampler = None
    try:
        oid = admin.execute("SELECT id FROM public.orders WHERE customer_id=%s AND payment_status='paid' ORDER BY id LIMIT 1",
                            (fixtures['python']['customer_id'],)).fetchone()[0]
        service = receipt_service()
        service.materialize(str(oid))
        admin.execute("UPDATE public.telegram_outbound_effects SET next_attempt_at=now()+interval '1 day' WHERE source_id=%s AND effect_type='receipt.credentials' AND state='pending'", (str(oid),))
        other = admin.execute("SELECT id FROM public.orders WHERE customer_id=%s AND payment_status='paid' ORDER BY id LIMIT 1",
                              (fixtures['php']['customer_id'],)).fetchone()[0]
        cipher = LaravelEncrypter(application_key())
        admin.execute('UPDATE public.orders SET notification_email=%s WHERE id=%s',
                      (cipher.encrypt_string('slow-mail@example.invalid'), other))
        connections.close_all()  # The probe does not consume an eighth runtime connection.
        sampler = _CgroupSampler()
        sampler.start()
        process = subprocess.Popen([sys.executable, '-c',
            'from tests.test_notifications_postgres import isolated_test_stack; isolated_test_stack()'],
            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        _wait_http()
        deadline = time.monotonic() + 30
        while not started.exists():
            assert process.poll() is None, 'Notification stack exited before the SMTP gate'
            if time.monotonic() >= deadline:
                raise AssertionError('Mail worker did not reach the bounded SMTP gate')
            time.sleep(0.05)
        update_id = 1900000025
        _webhook(update_id, 1, '/start')
        admin.execute("UPDATE public.telegram_outbound_effects SET next_attempt_at=now()-interval '1 day' WHERE source_id=%s AND effect_type='receipt.credentials' AND state='pending'", (str(oid),))
        _wait_for(admin, 'navigation while SMTP held',
                  "SELECT EXISTS (SELECT 1 FROM django_candidate.navigation_updates WHERE update_id=%s AND state='processed')",
                  (update_id,), timeout=15)
        _wait_for(admin, 'receipt while SMTP held',
                  'SELECT telegram_receipt_sent_at IS NOT NULL FROM public.orders WHERE id=%s', (oid,), timeout=15)
        assert not release.exists() and process.poll() is None
        connections_count = admin.execute("SELECT count(*) FROM pg_stat_activity WHERE usename='candidate_runtime'").fetchone()[0]
        assert connections_count <= 7
        release.touch(mode=0o600)
        _wait_for(admin, 'SMTP after gate released',
            "SELECT EXISTS (SELECT 1 FROM public.order_notification_events WHERE order_id=%s AND event_type='credentials_ready' AND status='sent')",
            (other,), timeout=15)
        return {'notification_process_isolation': 'passed', 'smtp_held_navigation_receipt_progress': 'passed',
                'notification_runtime_connections': connections_count}
    finally:
        release.touch(mode=0o600)
        if process is not None:
            process.terminate()
            try:
                process.wait(timeout=80)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
                raise AssertionError('Notification stack did not drain within its bound')
        if sampler is not None:
            resources = sampler.stop()
            (root / 'notification-resources.json').write_text(json.dumps(resources))
        started.unlink(missing_ok=True)
        release.unlink(missing_ok=True)
        admin.close()
