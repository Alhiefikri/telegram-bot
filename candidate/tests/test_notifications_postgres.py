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
    privileges = admin.execute("""SELECT
        has_table_privilege('candidate_runtime','public.users','SELECT'),
        has_column_privilege('candidate_runtime','public.users','password','SELECT'),
        has_column_privilege('candidate_runtime','public.users','remember_token','SELECT')""").fetchone()
    assert privileges == (False, True, False), privileges
    db = Database()
    recipients = TestRecipients(frozenset({'1'}))
    transport = SimpleNamespace(execute=lambda *args: ReceiptResult(701, 'success'))
    service = Notifications(transport, recipients=recipients, rate=SimpleNamespace(acquire=lambda: True),
                            effect_types=(BROADCAST,), queue='bulk')
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
                protocol = Notifications(transport, recipients=recipients, effect_types=(BROADCAST,),
                                         queue='bulk').protocol
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
        assert service.protocol.recover_expired_leases(25, chats=['1'], queue='bulk') == 1
        assert service.deliver(expired['id']) == 'not_ready'
        assert db.one('SELECT state FROM public.telegram_outbound_effects WHERE id=%s', (expired['id'],))['state'] == 'unknown'
        # Actual local fake relay, isolated from real Telegram.
        real = NotificationTransport().execute('sendMessage', {'chat_id': '1', 'text': 'P25 synthetic notice'})
        assert real.outcome == 'success' and real.message_id > 0
        source = ReceiptSource(cipher, signing_key=application_key(), invoice_base_url='http://127.0.0.1:18801')
        captured = []
        class Mail:
            fail = True
            def send(self, address, subject, text, html=None):
                captured.append((address, subject, text, html))
                if self.fail:
                    raise DefiniteMailFailure()
                SinkMail().send(address, subject, text, html)
        mail_transport = Mail()
        mail = CredentialMail(source, cipher, recipients=recipients, transport=mail_transport)
        event = mail.record(oid)
        assert event and event['recipient_email'] != 'snapshot@example.invalid'
        admin.execute('UPDATE public.orders SET notification_email=%s WHERE id=%s',
                      (cipher.encrypt_string('changed@example.invalid'), oid))
        assert mail.record(oid)['recipient_email'] == event['recipient_email']
        failure_result = mail.deliver(event['id'])
        assert failure_result == 'failed', {
            'result': failure_result,
            'event': db.one('SELECT status,attempts,last_error FROM public.order_notification_events WHERE id=%s',
                            (event['id'],)),
        }
        assert captured[-1][0] == 'snapshot@example.invalid'
        admin.execute("UPDATE public.order_notification_events SET next_attempt_at=now()-interval '1 day' WHERE id=%s", (event['id'],))
        mail_transport.fail = False
        assert mail.deliver(event['id']) == 'sent'
        assert captured[-1][0] == 'snapshot@example.invalid'
        presentation = source.load_customer(oid, fixture['customer_id'], receipt=True)
        assert captured[-1][1] == (
            'Data akun pesanan ' + presentation['invoice_number'] + ' | ' + fixtures['p25']['app_name'])
        assert presentation['groups'][0]['product_name'] in captured[-1][2]
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
        # A replay may replace only untouched order-created work. The stored
        # event contains ciphertext, while delivery materializes the B snapshot.
        from lhie_candidate.notifications.guest_mail import GuestMail, record_guest_event, refresh_pending_order_created
        admin.execute("""UPDATE public.orders SET order_status='pending_payment',payment_status='pending',
            notification_email=%s WHERE id=%s""", (cipher.encrypt_string('a@example.invalid'), oid))
        with transaction.atomic():
            record_guest_event(db, oid, 'order_created')
        created = db.one("SELECT id,recipient_email FROM public.order_notification_events WHERE order_id=%s AND event_type='order_created'", (oid,))
        with transaction.atomic():
            admin.execute('UPDATE public.orders SET notification_email=%s WHERE id=%s',
                       (cipher.encrypt_string('b@example.invalid'), oid))
            refresh_pending_order_created(db, oid)
        assert cipher.decrypt_string(db.one('SELECT recipient_email FROM public.order_notification_events WHERE id=%s',
                                            (created['id'],))['recipient_email']) == 'b@example.invalid'
        guest_attempts = []
        def guest_lost_ack(address, *args):
            guest_attempts.append(address)
            lost_ack(*args)
        guest_mail = GuestMail(cipher, recipients=recipients, invoice_base_url='http://127.0.0.1:18801',
                               signing_key=application_key(), transport=SimpleNamespace(send=guest_lost_ack))
        assert guest_mail.deliver(created['id']) == 'unknown'
        assert guest_attempts == ['b@example.invalid']
        frozen = db.one('SELECT recipient_email,status,attempts FROM public.order_notification_events WHERE id=%s',
                        (created['id'],))
        with transaction.atomic():
            admin.execute('UPDATE public.orders SET notification_email=%s WHERE id=%s',
                       (cipher.encrypt_string('c@example.invalid'), oid))
            refresh_pending_order_created(db, oid)
        after_unknown = db.one('SELECT recipient_email,status,attempts FROM public.order_notification_events WHERE id=%s',
                               (created['id'],))
        assert after_unknown == frozen
        frozen_snapshot = json.loads(cipher.decrypt_string(frozen['recipient_email']))
        assert frozen_snapshot['recipient'] == 'b@example.invalid'
        # Recovery scans only currently eligible credential work, bounded by PHP's 72h default.
        admin.execute("DELETE FROM public.order_notification_events WHERE order_id=%s AND event_type='credentials_ready'", (oid,))
        admin.execute("""UPDATE public.orders SET order_status='processing',payment_status='paid',
            created_at=timezone('Asia/Jakarta',now())-interval '72 hours'+interval '5 minutes' WHERE id=%s""", (oid,))
        previous_order = admin.execute(
            'SELECT id FROM public.orders WHERE id<%s::uuid ORDER BY id DESC LIMIT 1', (oid,),
        ).fetchone()
        recovery_cursor = str(previous_order[0]) if previous_order else '00000000-0000-0000-0000-000000000000'
        eligible_order = admin.execute('''SELECT id FROM public.orders
            WHERE notification_email IS NOT NULL AND payment_status='paid'
              AND order_status IN ('processing','completed')
              AND created_at >= timezone('Asia/Jakarta',now())-interval '72 hours' AND id>%s::uuid
              AND NOT EXISTS (SELECT 1 FROM public.order_notification_events e
                WHERE e.order_id=public.orders.id AND e.event_type='credentials_ready')
            ORDER BY id LIMIT 1''', (recovery_cursor,)).fetchone()
        assert eligible_order and str(eligible_order[0]) == str(oid), eligible_order
        recovery_outcomes = mail.recover(limit=1)
        recovered_event = db.one(
            "SELECT id FROM public.order_notification_events WHERE order_id=%s AND event_type='credentials_ready'",
            (oid,),
        )
        if recovered_event is None:
            try:
                source.load_customer(oid, fixture['customer_id'], receipt=True)
                source_gate = 'eligible'
            except Exception as exc:
                source_gate = type(exc).__name__
            order_state = admin.execute("""SELECT o.order_status,o.payment_status,
                o.created_at >= timezone('Asia/Jakarta',now())-interval '72 hours',o.notification_email IS NOT NULL,
                p.status,p.paid_at IS NOT NULL FROM public.orders o
                LEFT JOIN LATERAL (SELECT status,paid_at FROM public.payments
                    WHERE order_id=o.id ORDER BY created_at DESC,id DESC LIMIT 1) p ON true
                WHERE o.id=%s""", (oid,)).fetchone()
            assert recovered_event, {
                'recovery_outcomes': recovery_outcomes,
                'recovery_cursor': mail._order_after,
                'order_state': order_state,
                'receipt_source_gate': source_gate,
            }
        admin.execute("DELETE FROM public.order_notification_events WHERE order_id=%s AND event_type='credentials_ready'", (oid,))
        admin.execute("UPDATE public.orders SET created_at=timezone('Asia/Jakarta',now())-interval '72 hours'-interval '5 minutes' WHERE id=%s", (oid,))
        mail._order_after = recovery_cursor
        mail.recover(limit=1)
        assert db.one("SELECT id FROM public.order_notification_events WHERE order_id=%s AND event_type='credentials_ready'", (oid,)) is None
        # Restore fixture state for later checks.
        admin.execute("UPDATE public.orders SET created_at=timezone('Asia/Jakarta',now()),order_status='processing',payment_status='paid' WHERE id=%s", (oid,))
        admin.execute("DELETE FROM public.order_notification_events WHERE order_id=%s AND event_type='order_created'", (oid,))
        admin.execute("DELETE FROM public.order_notification_events WHERE order_id=%s AND event_type='credentials_ready'", (oid,))
        admin.execute("UPDATE public.orders SET notification_email=%s WHERE id=%s",
                      (cipher.encrypt_string('snapshot@example.invalid'), oid))
        from tests.test_notifications_lifecycle_postgres import exercise_lifecycle_notifications
        from tests.test_waitlist_postgres import exercise_waitlist
        outcomes = {'notification_claim_fencing': 'passed', 'snapshot_mail_sink_retry': 'passed',
                    'unknown_no_resend': 'passed', 'sent_projection_no_resend': 'passed',
                    'fake_relay': 'passed', 'financial_history_unchanged': 'passed'}
        outcomes.update(exercise_lifecycle_notifications(admin, fixture['customer_id'], fixture['product_id']))
        outcomes.update(exercise_waitlist(fixtures))
        from tests.test_notifications_families_postgres import exercise_families
        outcomes.update(exercise_families(fixtures, admin, oid))
        from tests.test_notification_balance_postgres import exercise_balance
        outcomes.update(exercise_balance(admin, fixture['customer_id']))
        from tests.test_auth_postgres import exercise_auth_protocol
        outcomes.update(exercise_auth_protocol(fixtures, include_http=False))
        from tests.test_notification_isolation_fixture import exercise_isolation
        outcomes.update(exercise_isolation(fixtures))
        outcomes.update(exercise_isolation(fixtures, slow_queue='notifications'))
        import os
        from pathlib import Path
        if os.environ.get('P21_BROWSER_SMOKE') == '1':
            from tests.test_storefront_postgres import browser_wait_app
            from lhie_candidate.auth.mail import MailWork
            from lhie_candidate.auth.types import AuthError, Browser
            from tests.test_auth_postgres import core as auth_core
            product_id = admin.execute("""INSERT INTO public.products
                (name,description,price_amount,currency,stock,reserved_stock,is_active,created_at,updated_at)
                VALUES ('Synthetic browser waitlist','Disposable unavailable product',1000,'IDR',0,0,true,now(),now())
                RETURNING id""").fetchone()[0]
            review_email = f'p25-browser-auth-review-{uuid4().hex}@example.invalid'
            review_auth = auth_core()
            review_auth.request_magic(review_email, Browser('b' * 64, '127.0.11.19'))
            auth_work_id = admin.execute('''SELECT w.id FROM public.customer_auth_mail_works w
                JOIN public.customer_login_links l ON l.id=w.login_link_id
                WHERE l.email=%s ORDER BY w.id DESC LIMIT 1''', (review_email,)).fetchone()[0]

            class AmbiguousBrowserMail:
                def send(self, *_):
                    raise TimeoutError('synthetic private SMTP diagnostic')

            try:
                MailWork(cipher, app_key=review_auth.app_key,
                         transport=AmbiguousBrowserMail()).deliver(auth_work_id)
            except AuthError:
                pass
            else:
                raise AssertionError('Synthetic auth review work did not become unknown')
            assert admin.execute(
                'SELECT status,payload IS NOT NULL,last_error_code FROM public.customer_auth_mail_works WHERE id=%s',
                (auth_work_id,)).fetchone() == ('unknown', True, 'mail_delivery_unknown')

            browser_review_order_id = oid

            eligibility = admin.execute('''SELECT order_status,payment_status,notification_email IS NOT NULL
                FROM public.orders WHERE id=%s''', (oid,)).fetchone()
            assert eligibility == ('processing', 'paid', True), eligibility
            admin.execute("DELETE FROM public.order_notification_events WHERE order_id=%s AND event_type='payment_confirmed'",
                          (oid,))
            with transaction.atomic():
                record_guest_event(db, oid, 'payment_confirmed')
            browser_mail_event_id = str(db.one('''SELECT id FROM public.order_notification_events
                WHERE order_id=%s AND event_type='payment_confirmed' ''', (oid,))['id'])

            class AcceptedThenUnknown:
                accepted = False
                def send(self, address, subject, text, html=None):
                    SinkMail().send(address, subject, text, html)
                    self.accepted = True
                    raise TimeoutError('synthetic lost application acknowledgement')

            accepted_then_unknown = AcceptedThenUnknown()
            browser_guest_mail = GuestMail(cipher, recipients=recipients,
                invoice_base_url='http://127.0.0.1:18801', signing_key=application_key(),
                transport=accepted_then_unknown)
            assert browser_guest_mail.deliver(browser_mail_event_id) == 'unknown'
            assert accepted_then_unknown.accepted
            assert admin.execute('''SELECT status,last_error,delivery_started_at IS NOT NULL
                FROM public.order_notification_events WHERE id=%s''',
                (browser_mail_event_id,)).fetchone() == ('failed', 'mail_delivery_unknown', True)
            smtp_evidence_reference = 'P25-SMTP-SINK-' + uuid4().hex
            evidence_path = Path('/evidence/p25-smtp-sink-ack.json')
            evidence_path.write_text(json.dumps({
                'kind': 'synthetic_local_smtp_sink_acceptance',
                'reference': smtp_evidence_reference,
                'accepted': True,
                'message_content_logged': False,
            }))
            evidence_path.chmod(0o600)

            browser_product = admin.execute('''SELECT id FROM public.products
                WHERE name LIKE 'P25 broadcast button %' AND is_active=TRUE
                  AND stock>reserved_stock ORDER BY id DESC LIMIT 1''').fetchone()
            assert browser_product
            browser_fixture = {
                'product_id': product_id, 'order_id': browser_review_order_id, 'auth': fixtures['auth'],
                'auth_mail_work_id': auth_work_id, 'mail_event_id': browser_mail_event_id,
                'smtp_evidence_reference': smtp_evidence_reference,
                'broadcast_product_id': browser_product[0],
            }
            browser_wait_app(browser_fixture)
            assert admin.execute('''SELECT status,last_error,sent_at IS NOT NULL
                FROM public.order_notification_events WHERE id=%s''',
                (browser_mail_event_id,)).fetchone() == ('sent', 'operator_marked_sent', True)
            assert admin.execute('''SELECT new_values->>'evidence_reference'
                FROM public.audit_logs WHERE action='mail_delivery.marked_sent'
                  AND subject_id=%s ORDER BY id DESC LIMIT 1''',
                (browser_mail_event_id,)).fetchone() == (smtp_evidence_reference,)
            auth_retry = admin.execute('''SELECT status,payload IS NOT NULL,last_error_code,attempts
                FROM public.customer_auth_mail_works WHERE id=%s''', (auth_work_id,)).fetchone()
            assert auth_retry == ('pending', True, 'manual_retry_authorized', 1), auth_retry
            assert admin.execute('''SELECT new_values->>'duplicate_risk_acknowledged'
                FROM public.audit_logs WHERE action='mail_delivery.resent'
                  AND subject_type='App\\Models\\CustomerAuthMailWork' AND subject_id=%s
                ORDER BY id DESC LIMIT 1''', (str(auth_work_id),)).fetchone() == ('true',)
            assert MailWork(cipher, app_key=review_auth.app_key).deliver(auth_work_id) is True
            assert admin.execute('''SELECT status,payload IS NOT NULL,last_error_code,attempts
                FROM public.customer_auth_mail_works WHERE id=%s''',
                (auth_work_id,)).fetchone() == ('sent', False, None, 2)
            button_broadcast = admin.execute('''SELECT reply_markup FROM public.telegram_broadcasts
                WHERE message='P25 browser-authored <b>日本語</b> &amp; 50%' ''').fetchone()
            assert button_broadcast
            markup = button_broadcast[0]
            if isinstance(markup, str):
                markup = json.loads(markup)
            assert markup['id']['inline_keyboard'][0][0]['callback_data'] == \
                'product:' + str(browser_product[0])
            assert admin.execute('''SELECT photo_file_id,reply_markup FROM public.telegram_broadcasts
                WHERE message='' AND photo_file_id='p25-browser-photo-reference' ''').fetchone() == \
                ('p25-browser-photo-reference', None)
            outcomes['mail_review_sink_and_auth_retry'] = 'passed'
            outcomes['browser_changed_surfaces'] = 'passed'
        return outcomes
    finally:
        admin.close()
