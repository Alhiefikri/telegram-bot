"""P25 changed flow on P01 fake-only PostgreSQL/Redis/SMTP; no live targets."""
from concurrent.futures import ThreadPoolExecutor
import json
from threading import Barrier
from types import SimpleNamespace
from uuid import uuid4


def normalized_html(value):
    """Compare DOM content/attributes, ignoring Blade's layout indentation only."""
    from html.parser import HTMLParser
    class Canonical(HTMLParser):
        def __init__(self):
            super().__init__(convert_charrefs=True)
            self.parts, self.pre = [], False
        def handle_starttag(self, tag, attrs):
            self.parts.append(('start', tag, tuple(sorted(attrs))))
            if tag == 'pre':
                self.pre = True
        def handle_endtag(self, tag):
            self.parts.append(('end', tag))
            if tag == 'pre':
                self.pre = False
        def handle_data(self, data):
            text = data if self.pre else ' '.join(data.split())
            if text:
                self.parts.append(('text', text))
    parser = Canonical()
    parser.feed(value)
    parser.close()
    return parser.parts


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
        floor = fixtures['p25_counter_floor']
        assert proof.handoff_counter(floor, producers_stopped=True) == floor
        assert proof.handoff_counter(floor - 1, producers_stopped=True) == floor
        public = Notifications(NotificationTransport(), recipients=recipients, proof=proof, families=(PROOF,))
        assert proof.discover(public.record, 10) >= 1
        effect = db.one('''SELECT * FROM public.telegram_outbound_effects
            WHERE effect_type=%s AND source_id=%s''', (PROOF, oid))
        assert effect and effect['template_version'] == 'sales-proof-post-v2'
        snapshot = proof.eligibility.load(oid)
        assert snapshot['buyer_name'].endswith('***')
        assert not any(name in snapshot for name in ('email', 'password', 'credentials', 'telegram_id'))
        # Exhaust definite failures, then one explicit authorized retry. Keep
        # attempt history; an unknown effect cannot use this retry boundary.
        from lhie_candidate.checkout.errors import CheckoutError
        public.transport = SimpleNamespace(execute=lambda *args: ReceiptResult(None, 'definite', error_code='telegram_http_429'))
        for _ in range(3):
            admin.execute("UPDATE public.telegram_outbound_effects SET next_attempt_at=now()-interval '1 day' WHERE id=%s", (effect['id'],))
            assert public.deliver(effect['id']) == 'failed'
        actor = admin.execute("INSERT INTO public.users(name,email,password,role,created_at,updated_at) VALUES ('P25 operator','p25-operator@example.invalid','disabled-synthetic-password','owner',now(),now()) RETURNING id").fetchone()[0]
        try:
            proof.retry(oid, -1, public.protocol)
        except CheckoutError:
            pass
        else:
            raise AssertionError('Foreign operator accepted')
        retried = proof.retry(oid, actor, public.protocol)
        assert retried['attempts'] == 3 and retried['state'] == 'pending'
        public.transport = NotificationTransport()
        outcome = public.deliver(effect['id'])
        assert outcome == 'sent', (outcome, db.one('SELECT state,last_error_code FROM public.telegram_outbound_effects WHERE id=%s', (effect['id'],)))
        assert public.deliver(effect['id']) == 'not_ready'
        post = db.one('SELECT * FROM public.sales_proof_posts WHERE order_id=%s', (oid,))
        assert post['status'] == 'sent' and post['attempts'] == 4 and post['telegram_message_id']
        rollback_floor = proof.handoff_counter(0, producers_stopped=True)
        assert rollback_floor > floor
        # Repair after a lost projection is not another public send.
        admin.execute("UPDATE public.sales_proof_posts SET status='pending',sent_at=NULL WHERE order_id=%s", (oid,))
        sent_proof = db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (effect['id'],))
        assert public.project(sent_proof) == 1
        assert public.project(sent_proof) == 0
        assert admin.execute("SELECT count(*) FROM public.store_balance_entries WHERE source_id=%s AND kind='purchase_debit'", (oid,)).fetchone() == (1,)
        assert admin.execute('SELECT count(*) FROM public.product_stock_items s JOIN public.order_items i ON i.id=s.order_item_id WHERE i.order_id=%s', (oid,)).fetchone() == (2,)
        from tests.test_waitlist_postgres import exercise_waitlist
        waitlist = exercise_waitlist()
        auth_mail = exercise_auth_mail(fixtures)
        milestone = exercise_milestone_mail(oid, cipher, source, recipients)
        stock = exercise_stock_notifications(fixtures)
        balance = exercise_balance_notifications(fixtures)
        workers = exercise_notification_workers(fixtures)
        notices = exercise_order_notices(fixtures)
        prices = exercise_price_notifications(fixtures)
        broadcasts = exercise_broadcast_creation(fixtures)
        # Export only after the opt-in supervisor has drained. Automatic proof
        # publication may have reserved more numbers than the earlier manual
        # publication; an earlier snapshot cannot be used for rollback.
        from pathlib import Path
        rollback_floor = proof.handoff_counter(0, producers_stopped=True)
        Path('/evidence/counter-handoff.json').write_text(json.dumps({'php_floor': floor, 'rollback_floor': rollback_floor}))
        return {**waitlist, **workers, **notices, **auth_mail, **milestone, **prices, **broadcasts, **stock, **balance, 'sales_proof_publication': 'passed',
                'sales_proof_definite_manual_retry': 'passed', 'credential_html_mail': 'passed',
                'notification_claim_fencing': 'passed', 'snapshot_mail_sink_retry': 'passed',
                'unknown_no_resend': 'passed', 'sent_projection_no_resend': 'passed',
                'fake_relay': 'passed', 'financial_history_unchanged': 'passed'}
    finally:
        admin.close()


def exercise_balance_notifications(fixtures):
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.notifications.balance import RECEIPT, PAID
    from lhie_candidate.notifications.policy import TestRecipients
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from tests.test_checkout_postgres import admin_connection
    db, admin = Database(), admin_connection()
    service = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})), families=(RECEIPT, PAID))
    identity = fixtures['p25_balance']['top_up_id']
    try:
        assert fixtures['p25_balance']['status'] == 'paid'
        assert service.balance_notifications.load(identity)['id'] == identity
        admin.execute("UPDATE public.manual_payment_settings SET admin_telegram_chat_id='1' WHERE is_active=true")
        service.balance_notifications.recover(service.record, 100)
        effects = db.rows('''SELECT * FROM public.telegram_outbound_effects WHERE effect_type=ANY(%s)
            AND source_id=%s''', ([RECEIPT, PAID], str(identity)))
        assert any(effect['effect_type'] == RECEIPT for effect in effects)
        assert any(effect['effect_type'] == PAID for effect in effects)
        assert len(effects) == 2
        snapshot = admin.execute('''SELECT e.id,e.amount,e.balance_after,e.source_type,e.source_id FROM public.store_balance_entries e
            WHERE e.source_type='balance_top_up' AND e.source_id=%s''', (str(identity),)).fetchall()
        assert len(snapshot) == 1
        for effect in effects:
            result = service.deliver(effect['id'])
            assert result == 'sent', (result, effect['effect_type'])
            assert service.deliver(effect['id']) == 'not_ready'
        assert admin.execute('''SELECT e.id,e.amount,e.balance_after,e.source_type,e.source_id FROM public.store_balance_entries e
            WHERE e.source_type='balance_top_up' AND e.source_id=%s''', (str(identity),)).fetchall() == snapshot
        return {'idr_balance_receipt_owner_notice_immutable_posting': 'passed'}
    finally:
        admin.close()


def exercise_stock_notifications(fixtures):
    from pathlib import Path
    from unittest.mock import patch
    from PIL import Image
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.navigation.repository import CatalogRepository
    from lhie_candidate.notifications.stock import render
    from lhie_candidate.notifications.policy import STOCK, TestRecipients
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from tests.test_checkout_postgres import admin_connection
    db, admin = Database(), admin_connection()
    service = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})), families=(STOCK,))
    try:
        identity = fixtures['p25_stock']
        event = db.one('SELECT * FROM public.stock_notification_events WHERE inventory_movement_id=%s', (identity['movement_id'],))
        assert event
        admin.execute("UPDATE public.store_channel_settings SET last_test_success=true WHERE singleton_key='store_channel'")
        service.stock_notifications.materialize(event['id'], 1)
        product = CatalogRepository().product(identity['product_id'])
        movement = db.one('SELECT * FROM public.inventory_movements WHERE id=%s', (identity['movement_id'],))
        event = db.one('SELECT * FROM public.stock_notification_events WHERE id=%s', (event['id'],))
        for locale, expected in identity['messages'].items():
            text, _, _ = render(product, movement, event, locale)
            from html import unescape
            assert unescape(text) == expected, 'Stock notification differs from PHP for ' + locale
            assert '<Product>' not in text and '&lt;Product&gt;' in text
        admin.execute("UPDATE public.store_channel_settings SET last_test_success=true WHERE singleton_key='store_channel'")
        service.stock_notifications.materialize(event['id'], 1)
        service.materialize_recipients(100)
        effects = db.rows('''SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s
            AND source_id IN (SELECT id::text FROM public.stock_notification_recipients WHERE stock_notification_event_id=%s)
            ORDER BY id''', (STOCK, event['id']))
        # The legacy unique event/chat audience deduplicates channel and DM
        # when the synthetic target is the same chat; channel wins as in PHP.
        assert len(effects) == 1 and effects[0]['target_type'] == 'store_channel'
        Image.new('RGB', (100, 100), 'gold').save('/evidence/synthetic-stock.png')
        with patch('lhie_candidate.storefront.public_media.PUBLIC_ROOT', Path('/evidence')):
            for effect in effects:
                result = service.deliver(effect['id'])
                assert result in ('sent', 'not_ready'), (result, effect['target_type'])
                if effect['target_type'] == 'store_channel':
                    assert effect['telegram_method'] == 'sendPhoto'
                    assert service.payload(effect)['photo'].startswith(b'\x89PNG')
        before = len(effects)
        service.stock_notifications.materialize(event['id'], 1)
        service.materialize_recipients(100)
        assert db.one('SELECT count(*) AS count FROM public.stock_notification_recipients WHERE stock_notification_event_id=%s', (event['id'],))['count'] == before
        return {'stock_php_locale_tier_differential': 'passed', 'stock_channel_photo_materialization': 'passed'}
    finally:
        admin.execute("UPDATE public.store_channel_settings SET last_test_success=false WHERE singleton_key='store_channel'")
        admin.close()


def exercise_broadcast_creation(fixtures):
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.navigation.repository import CatalogRepository
    from lhie_candidate.navigation.types import TelegramResult
    from lhie_candidate.notifications.broadcast_intents import BroadcastIntents
    from lhie_candidate.notifications.policy import BROADCAST, TestRecipients
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from tests.test_checkout_postgres import admin_connection
    db, admin, repository = Database(), admin_connection(), CatalogRepository()
    sent = []
    class Capture:
        def execute(self, method, payload):
            sent.append((method, payload))
            return TelegramResult(707, 'success')
    recipients = TestRecipients(frozenset({'1'}))
    owner = BroadcastIntents(repository, Capture(), recipients)
    customer = repository.customer(1)
    try:
        count = db.one('SELECT count(*) AS count FROM public.telegram_broadcasts')['count']
        assert owner.handle(customer, '1', {'text': '/broadcast Verbatim <b>admin</b> 😀'}) == 'broadcast'
        draft = repository.session_state(customer['id'])['broadcast']
        assert db.one('SELECT count(*) AS count FROM public.telegram_broadcasts')['count'] == count
        owner.handle(customer, '1', {'data': f"broadcast:select:{draft['id']}:{fixtures['preflight']['product_id']}"}, callback=True)
        owner.handle(customer, '1', {'data': f"broadcast:send:{draft['id']}"}, callback=True)
        owner.handle(customer, '1', {'data': f"broadcast:send:{draft['id']}"}, callback=True)
        assert db.one('SELECT count(*) AS count FROM public.telegram_broadcasts')['count'] == count + 1
        row = db.one('SELECT * FROM public.telegram_broadcasts ORDER BY id DESC LIMIT 1')
        assert row['message'] == 'Verbatim <b>admin</b> 😀'
        assert row['recipients_materialized_at'] is None
        service = Notifications(NotificationTransport(), recipients=recipients, families=(BROADCAST,))
        from lhie_candidate.notifications.broadcast import materialize
        assert materialize(db, recipients, row['id'], service.record) == 1
        effect = db.one('''SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s
            AND source_id IN (SELECT id::text FROM public.telegram_broadcast_recipients WHERE broadcast_id=%s)''', (BROADCAST, row['id']))
        assert service.deliver(effect['id']) == 'sent'
        assert 'Verbatim <b>admin</b> 😀' in service.payload(effect)['text']
        assert service.payload(effect)['reply_markup']['inline_keyboard'][0][0]['callback_data'] == 'product:' + str(fixtures['preflight']['product_id'])
        # Photo-only message, cancellation and expired/foreign confirmations
        # never create delivery intent before the authorized confirmation.
        owner.handle(customer, '1', {'caption': '/broadcast', 'photo': [{'file_id': 'synthetic-p25-photo'}], 'media_kind': 'photo'})
        draft = repository.session_state(customer['id'])['broadcast']
        owner.handle(customer, '1', {'data': f"broadcast:cancel:{draft['id']}"}, callback=True)
        owner.handle(customer, '1', {'data': f"broadcast:send:{draft['id']}"}, callback=True)
        assert db.one('SELECT count(*) AS count FROM public.telegram_broadcasts')['count'] == count + 1
        foreign = dict(customer, telegram_id=2)
        owner.handle(foreign, '2', {'text': '/broadcast foreign'})
        assert db.one('SELECT count(*) AS count FROM public.telegram_broadcasts')['count'] == count + 1
        return {'confirmed_broadcast_creation_replay_cancel': 'passed'}
    finally:
        admin.close()


def exercise_price_notifications(fixtures):
    from django.template.loader import render_to_string
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.notifications.milestones import COPY, FOOTER
    from lhie_candidate.notifications.policy import PRICE, PRICE_CHANNEL, TestRecipients
    from lhie_candidate.notifications.price import payload
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from tests.test_checkout_postgres import admin_connection
    import re
    db, admin = Database(), admin_connection()
    recipients = TestRecipients(frozenset({'1'}))
    service = Notifications(NotificationTransport(), recipients=recipients, families=(PRICE, PRICE_CHANNEL))
    try:
        source = fixtures['p25_price']
        event = db.one('SELECT * FROM public.product_price_change_events WHERE id=%s', (source['event_id'],))
        for locale, expected in source['messages'].items():
            rendered = payload(event, {'locale': locale, 'telegram_chat_id': '1', 'effect_type': PRICE})
            assert rendered['text'] == expected, 'Price content differs from PHP for ' + locale
        # Materialized PHP audience is immutable, including the original locale.
        customer = db.one('''SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s
            AND source_id IN (SELECT id::text FROM public.product_price_change_notification_recipients
                WHERE event_id=%s AND telegram_id='1')''', (PRICE, event['id']))
        assert customer
        assert service.deliver(customer['id']) in ('sent', 'not_ready')
        service.price_notifications.recover(service.record, 100)
        channel = db.one('SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s AND source_id=%s', (PRICE_CHANNEL, str(event['id'])))
        assert channel and channel['locale'] == 'en'
        assert service.payload(channel)['text'] == source['messages']['en']
        result = service.deliver(channel['id'])
        assert result in ('sent', 'not_ready'), result
        sent = db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (channel['id'],))
        assert sent['state'] == 'sent'
        admin.execute('UPDATE public.product_price_change_events SET store_channel_sent_at=NULL WHERE id=%s', (event['id'],))
        assert service.project(sent) == 1 and service.project(sent) == 0
        assert service.deliver(sent['id']) == 'not_ready'
        _, headline, intro = COPY['payment_confirmed']
        html = render_to_string('notifications/milestone.html', {
            'headline': headline, 'intro': intro, 'invoice_number': 'INV-P25-ORACLE',
            'order_status': 'processing', 'status_label': 'Sedang diproses',
            'items': [{'name': '<Product>', 'quantity': 2}], 'payment_deadline': None,
            'invoice_url': 'http://127.0.0.1:18801/invoice',
            'order_access_url': 'http://127.0.0.1:18801/akses-pesanan', 'footer': FOOTER})
        normalize = lambda value: re.sub(r'>\s+<', '><', value.strip())
        assert normalize(html) == normalize(fixtures['p25_milestone_html']), 'Milestone template differs from PHP'
        from pathlib import Path
        from lhie_candidate.auth.mail import auth_copy
        from lhie_candidate.notifications.credential_render import presentation
        # Only redacted preview inputs may leave memory for browser evidence.
        # These aren't copies of a real sink message, grant or credential.
        previews = {
            'milestone': html, 'php-milestone': fixtures['p25_milestone_html'],
            'php-credential': fixtures['p25_credential_html'],
            'auth': render_to_string('notifications/auth.html', auth_copy('password_reset', '[REDACTED]')),
            'waitlist': render_to_string('notifications/waitlist_confirmation.html', {
                'product_name': '<Product>', 'confirmation_url': 'http://127.0.0.1:18801/waitlist/confirm/redacted'}),
            'credential': render_to_string('notifications/credential.html', presentation({
                'invoice_number': 'INV-P25-REDACTED', 'invoice_url': 'http://127.0.0.1:18801/invoice',
                'groups': [{'product_name': '<Product>', 'default_tutorial': 'Petunjuk penggunaan produk.',
                    'accounts': [{'format': 'structured_account', 'email': '[REDACTED]', 'password': '[REDACTED]',
                        'access_method': 'auth_token', 'access_value': '[REDACTED]', 'number': 1,
                        'reader_mail_link': 'https://reader.example.invalid/redacted', 'tutorial': 'Petunjuk akun.'}]}]})),
        }
        for name, content in previews.items():
            Path('/evidence/preview-' + name + '.html').write_text(content)
        assert normalized_html(previews['credential']) == normalized_html(previews['php-credential'])
        return {'price_locale_php_differential': 'passed', 'price_channel_delivery_repair': 'passed',
                'milestone_php_template_differential': 'passed', 'credential_php_template_differential': 'passed'}
    finally:
        admin.close()


def exercise_auth_mail(fixtures):
    from datetime import timedelta
    from threading import Event
    from unittest.mock import Mock
    from django.db import connections, transaction
    from lhie_candidate.auth.mail import LocalMailSink, MailWork, auth_copy
    from lhie_candidate.auth.types import AuthError, legacy_now
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.compat.identity import sha256_digest
    from lhie_candidate.notifications.mail import DefiniteMailFailure
    from lhie_candidate.runtime import application_key
    from tests.test_checkout_postgres import admin_connection
    key, base = application_key(), legacy_now()
    cipher = LaravelEncrypter(key)
    owner = MailWork(cipher, app_key=key, clock=lambda: base)
    admin = admin_connection()

    def create(email='p25-auth@example.invalid', expires=None):
        capability = uuid4().hex + uuid4().hex
        expires = expires or base + timedelta(minutes=15)
        with transaction.atomic():
            grant = owner.db.one('''INSERT INTO public.customer_login_links
                (email,token_hash,expires_at,created_at,updated_at) VALUES (%s,%s,%s,%s,%s) RETURNING id''',
                (email, sha256_digest(capability), expires, base, base))
            return owner.persist(grant['id'], email, capability, expires, legacy=True), grant['id']

    try:
        from urllib.parse import urlsplit
        assert set(fixtures['p25_auth_copy']) == {'magic', 'email_verification', 'email_otp', 'password_reset'}
        for purpose, expected in fixtures['p25_auth_copy'].items():
            copy = auth_copy(purpose, '[REDACTED]')
            assert copy['subject'] == expected['subject'] and copy['greeting'] == expected['greeting']
            assert copy['action'] == expected['action']
            if copy['action']:
                assert copy['lines'] == expected['intro']
                assert list(filter(None, (copy['expiry'], copy['ignore']))) == expected['outro']
                # Disposable oracle/candidate origins intentionally differ;
                # the route, query and fragment must still match exactly.
                assert urlsplit(copy['url'])[2:] == urlsplit(expected['url'])[2:]
            else:
                assert [*copy['lines'], copy['ignore']] == expected['intro']
                assert not expected['outro'] and expected['url'] is None
        work, grant = create()
        assert owner.deliver(work)
        assert admin.execute('SELECT status,payload,attempts FROM public.customer_auth_mail_works WHERE id=%s', (work,)).fetchone() == ('sent', None, 1)
        assert admin.execute('SELECT used_at FROM public.customer_login_links WHERE id=%s', (grant,)).fetchone() == (None,)
        for invalid in ('expired', 'consumed', 'recipient'):
            work, grant = create('p25-auth@example.com' if invalid == 'recipient' else 'p25-auth@example.invalid',
                                 base - timedelta(seconds=1) if invalid == 'expired' else None)
            if invalid == 'consumed':
                admin.execute('UPDATE public.customer_login_links SET used_at=%s WHERE id=%s', (base, grant))
            transport = SimpleNamespace(send=Mock())
            assert not MailWork(cipher, app_key=key, clock=lambda: base, transport=transport).deliver(work)
            transport.send.assert_not_called()
        # Competing claims plus stale successful completion after lease fencing.
        work, _ = create()
        entered, release = Event(), Event()
        sends = []
        class Held:
            def send(self, *args):
                sends.append(True)
                entered.set()
                assert release.wait(10)
                LocalMailSink().send(*args)
        def run():
            try:
                return MailWork(cipher, app_key=key, clock=lambda: base, transport=Held()).deliver(work)
            finally:
                connections.close_all()
        with ThreadPoolExecutor(max_workers=1) as pool:
            future = pool.submit(run)
            try:
                assert entered.wait(10)
                assert not owner.deliver(work)
                stale = MailWork(cipher, app_key=key, clock=lambda: base + timedelta(seconds=121))
                assert not stale.deliver(work)
                assert not stale.deliver(work)
            finally:
                release.set()
            assert not future.result(10)
        assert len(sends) == 1
        assert admin.execute('SELECT status,attempts,payload,last_error_code FROM public.customer_auth_mail_works WHERE id=%s', (work,)).fetchone() == ('failed', 1, None, 'mail_delivery_unknown')
        work, _ = create()
        def fail(*args):
            raise DefiniteMailFailure()
        try:
            MailWork(cipher, app_key=key, clock=lambda: base, transport=SimpleNamespace(send=fail)).deliver(work)
        except AuthError:
            pass
        else:
            raise AssertionError('Expected definite mail failure')
        assert MailWork(cipher, app_key=key, clock=lambda: base + timedelta(seconds=61)).deliver(work)
        assert admin.execute('SELECT status,attempts,payload FROM public.customer_auth_mail_works WHERE id=%s', (work,)).fetchone() == ('sent', 2, None)
        work, _ = create()
        def unknown(*args):
            raise TimeoutError('private diagnostic')
        try:
            MailWork(cipher, app_key=key, clock=lambda: base, transport=SimpleNamespace(send=unknown)).deliver(work)
        except AuthError:
            pass
        assert not owner.deliver(work)
        assert admin.execute('SELECT status,attempts,payload,last_error_code FROM public.customer_auth_mail_works WHERE id=%s', (work,)).fetchone() == ('failed', 1, None, 'mail_delivery_unknown')
        return {'auth_mail_smtp_grant_expiry_privacy': 'passed', 'auth_mail_claim_unknown_fencing': 'passed',
                'auth_all_purposes_php_copy_routes': 'passed'}
    finally:
        admin.close()


def exercise_milestone_mail(order_id, cipher, source, recipients):
    from lhie_candidate.notifications.milestones import MilestoneMail
    from lhie_candidate.notifications.mail import SinkMail
    from tests.test_checkout_postgres import admin_connection
    captured = []
    class Capture:
        def send(self, address, subject, text, *, html):
            captured.append((address, subject, text, html))
            return SinkMail().send(address, subject, text, html=html)
    owner = MilestoneMail(source, cipher, recipients=recipients, transport=Capture())
    admin = admin_connection()
    try:
        event = owner.record(order_id, 'payment_confirmed')
        assert event
        original = cipher.decrypt_string(event['recipient_email'])
        admin.execute('UPDATE public.orders SET notification_email=%s WHERE id=%s', (cipher.encrypt_string('other-milestone@example.invalid'), order_id))
        assert owner.record(order_id, 'payment_confirmed')['recipient_email'] == event['recipient_email']
        assert owner.deliver(event['id']) == 'sent'
        assert captured[-1][0] == original
        assert 'Pembayaran pesanan sudah dikonfirmasi' in captured[-1][2]
        assert 'Email status ini tidak memuat data akun.' in captured[-1][3]
        assert owner.deliver(event['id']) == 'not_ready'
        event = owner.record(order_id, 'fulfillment_completed')
        def unknown(*args, **kwargs):
            raise TimeoutError('private diagnostic')
        owner.transport = SimpleNamespace(send=unknown)
        assert owner.deliver(event['id']) == 'unknown'
        assert owner.deliver(event['id']) == 'not_ready'
        return {'milestone_snapshot_html_smtp_unknown': 'passed'}
    finally:
        admin.close()


def exercise_order_notices(fixtures):
    from datetime import timedelta
    from django.db import connections
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.checkout.lifecycle import OrderLifecycle
    from lhie_candidate.checkout.manual import ManualQrisPreparation
    from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
    from lhie_candidate.notifications.order_notices import OrderNotices, PENDING, PAID, EXPIRED, REVIEW
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
        from lhie_candidate.payments.manual import ManualQrisLifecycle
        from lhie_candidate.notifications.policy import MANUAL
        manual = ManualQrisLifecycle(None, clock=_now)
        proof_fixture = fixtures['release']
        proof_order = checkout.checkout(CheckoutRequest(proof_fixture['customer_id'], proof_fixture['cart_id'], 'p25-proof',
            checkout.quote_cart(proof_fixture['customer_id'], proof_fixture['cart_id'])))
        invoice = db.one('SELECT invoice_number FROM public.orders WHERE id=%s', (str(proof_order.order_id),))['invoice_number']
        assert manual.submit_proof(customer_id=proof_fixture['customer_id'], invoice=invoice, photo_file_id='synthetic-p25-proof') == 'accepted'
        manual_effect = db.one('SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s AND source_id=%s',
                               (MANUAL, str(proof_order.payment_id)))
        proof_service = Notifications(Capture(), recipients=recipients, families=(MANUAL,))
        assert manual_effect
        proof_service.payload(manual_effect)
        result = proof_service.deliver(manual_effect['id'])
        if result != 'sent':
            from pathlib import Path
            safe = db.one('SELECT state,last_error_code FROM public.telegram_outbound_effects WHERE id=%s', (manual_effect['id'],))
            Path('/evidence/p25-manual-proof-diagnostic.json').write_text(json.dumps({'result': result, **safe}))
        assert result == 'sent'
        assert 'Bukti pembayaran masuk' in captured[-1]['caption']
        assert 'Cek pembayaran QR manual dulu.' in captured[-1]['caption']
        assert 'Setujui & Kirim Produk' in captured[-1]['reply_markup']['inline_keyboard'][0][0]['text']
        assert proof_service.deliver(manual_effect['id']) == 'not_ready'
        OrderLifecycle(clock=lambda: _now() + timedelta(days=1)).apply_pending_expiry(str(pending.order_id))
        # Force the state change behind the keyset, independent of random UUID
        # ordering. Two sweeps are not a full rotation when other unnotified
        # (including ineligible) Orders occupy more than one bounded page.
        owner._cursors[EXPIRED] = str(pending.order_id)
        owner.discover(service.record, 7)
        assert db.one('SELECT id FROM public.telegram_outbound_effects WHERE source_id=%s AND effect_type=%s',
                      (str(pending.order_id), EXPIRED)) is None
        order_count = db.one('SELECT count(*) AS count FROM public.orders')['count']
        for _ in range((order_count + 6) // 7 + 2):
            owner.discover(service.record, 7)
            if db.one('SELECT id FROM public.telegram_outbound_effects WHERE source_id=%s AND effect_type=%s',
                      (str(pending.order_id), EXPIRED)):
                break
        expired = db.one('SELECT * FROM public.telegram_outbound_effects WHERE source_id=%s AND effect_type=%s',
                         (str(pending.order_id), EXPIRED))
        from pathlib import Path
        if not expired:
            Path('/evidence/p25-expired-diagnostic.json').write_text(json.dumps({
                'order': db.one('SELECT order_status,payment_status FROM public.orders WHERE id=%s', (str(pending.order_id),)),
                'effect_missing': True}))
        assert expired
        service.payload(expired)
        result = service.deliver(expired['id'])
        if result != 'sent':
            Path('/evidence/p25-expired-diagnostic.json').write_text(json.dumps({'result': result,
                **db.one('SELECT state,last_error_code FROM public.telegram_outbound_effects WHERE id=%s', (expired['id'],))}))
        assert result == 'sent'
        assert 'Stok sudah dilepas kembali.' in captured[-1]['text']
        assert db.one('SELECT admin_pending_order_notification_deleted_at FROM public.orders WHERE id=%s',
                      (str(pending.order_id),))['admin_pending_order_notification_deleted_at']
        oid = admin.execute("SELECT id FROM public.orders WHERE customer_id=%s AND payment_status='paid' ORDER BY id LIMIT 1",
                            (fixtures['python']['customer_id'],)).fetchone()[0]
        owner.discover(service.record, 100)
        paid = db.one('SELECT * FROM public.telegram_outbound_effects WHERE source_id=%s AND effect_type=%s ORDER BY sequence DESC LIMIT 1', (str(oid), PAID))
        assert paid
        if paid['state'] == 'sent':
            captured.append(service.payload(paid))
            assert service.deliver(paid['id']) == 'not_ready'
        else:
            assert service.deliver(paid['id']) == 'sent'
        assert 'Hasil order' in captured[-1]['text'] and 'Order selesai. Tidak perlu proses ulang.' in captured[-1]['text']
        assert 'unit terkirim' in captured[-1]['text']
        sent = db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (paid['id'],))
        admin.execute('UPDATE public.orders SET admin_paid_order_notified_at=NULL WHERE id=%s', (oid,))
        before = len(captured)
        assert service.project(sent) == 1 and service.project(sent) == 0
        assert len(captured) == before
        review_fixture = fixtures['p25_review']
        # This is retained PHP-created numeric-stock history, not permission to
        # weaken the candidate's credential-backed checkout inventory guard.
        review = db.one("SELECT id,reason FROM public.manual_fulfillment_reviews WHERE order_id=%s", (review_fixture['order_id'],))
        assert review and review['reason'] == 'local_credentials_missing'
        owner.discover(service.record, 100)
        notice = db.one('SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s AND source_id=%s', (REVIEW, str(review['id'])))
        assert notice
        if notice['state'] == 'sent':
            assert int(notice['telegram_message_id']) > 0
            captured.append(service.payload(notice))
            assert service.deliver(notice['id']) == 'not_ready'
        else:
            assert service.deliver(notice['id']) == 'sent'
        assert 'Review fulfillment' in captured[-1]['text'] and 'Review operator: diperlukan' in captured[-1]['text']
        assert service.deliver(notice['id']) == 'not_ready'
        return {'order_notice_pending_expired_cleanup': 'passed', 'order_notice_paid_delivery_evidence': 'passed',
                'order_notice_projection_no_resend': 'passed', 'local_fulfillment_review_notice': 'passed',
                'manual_payment_proof_caption_photo': 'passed'}
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
    wait_for = _wait_for
    def _wait_for(admin, stage, sql, params, **options):
        # Static scenario labels only, no query parameters or rendered bodies.
        Path('/evidence/p25-current-stage').write_text(stage)
        try:
            return wait_for(admin, stage, sql, params, **options)
        except AssertionError:
            states = admin.execute('''SELECT effect_type,state,sequence,
                outcome_metadata->'paid_outcome'->>'resolved' AS resolved,count(*)
                FROM public.telegram_outbound_effects GROUP BY effect_type,state,sequence,resolved
                ORDER BY effect_type,state,sequence''').fetchall()
            Path('/evidence/p25-failed-work-state.json').write_text(json.dumps(states))
            raise
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
        from datetime import timedelta
        from unittest.mock import patch
        from lhie_candidate.notifications.order_notices import OrderNotices, PAID
        from lhie_candidate.notifications.policy import TestRecipients
        from lhie_candidate.notifications.service import Notifications
        from lhie_candidate.receipt.protocol import _now
        from lhie_candidate.receipt.transport import ReceiptResult
        recipients = TestRecipients(frozenset({'1'}))
        admin.execute("UPDATE public.manual_payment_settings SET admin_telegram_chat_id='1' WHERE is_active=true")
        notices = OrderNotices(recipients)
        notice_service = Notifications(SimpleNamespace(execute=lambda *args: ReceiptResult(None, 'ambiguous')),
                                       recipients=recipients, order_notices=notices, families=(PAID,))
        # The notice reaches its escalation deadline before receipt ACKs. A
        # lost Owner ACK remains unknown; later completion owes a successor,
        # never a retry of that original notice.
        with patch('lhie_candidate.notifications.order_notices._now', return_value=_now() + timedelta(seconds=121)):
            from django.db import transaction
            with transaction.atomic():
                notice_service.record(key=f'order:{oid}:{PAID}:v1', kind=PAID, model='Order', source=oid,
                                      chat='1', target='admin', locale='id', template='admin-order-paid-v1')
            initial = notices.db.one('''SELECT * FROM public.telegram_outbound_effects
                WHERE source_id=%s AND effect_type=%s ORDER BY sequence LIMIT 1''', (str(oid), PAID))
            assert initial and notice_service.deliver(initial['id']) == 'unknown'
        admin.execute("UPDATE public.telegram_outbound_effects SET next_attempt_at=now()+interval '1 day' WHERE source_id=%s AND effect_type='receipt.credentials' AND state='pending'", (str(oid),))
        other = admin.execute("SELECT id FROM public.orders WHERE customer_id=%s AND payment_status='paid' ORDER BY id LIMIT 1",
                              (fixtures['php']['customer_id'],)).fetchone()[0]
        cipher = LaravelEncrypter(application_key())
        admin.execute('UPDATE public.orders SET notification_email=%s WHERE id=%s',
                      (cipher.encrypt_string('slow-mail@example.invalid'), other))
        from lhie_candidate.auth.mail import MailWork
        from lhie_candidate.auth.types import legacy_now
        from lhie_candidate.compat.identity import sha256_digest
        from django.db import transaction
        from datetime import timedelta
        at, capability = legacy_now(), uuid4().hex + uuid4().hex
        auth = MailWork(cipher, app_key=application_key())
        with transaction.atomic():
            grant = auth.db.one('''INSERT INTO public.customer_login_links
                (email,token_hash,expires_at,created_at,updated_at) VALUES
                ('p25-worker-auth@example.invalid',%s,%s,%s,%s) RETURNING id''',
                (sha256_digest(capability), at + timedelta(minutes=15), at, at))
            auth_id = auth.persist(grant['id'], 'p25-worker-auth@example.invalid', capability,
                                   at + timedelta(minutes=15), legacy=True)
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
        class AdminRead:
            using = 'default'
            def rows(self, sql, params=()):
                cursor = admin.execute(sql, params)
                names = [column.name for column in cursor.description]
                return [dict(zip(names, row)) for row in cursor.fetchall()]
            def one(self, sql, params=()):
                rows = self.rows(sql, params)
                return rows[0] if rows else None
        diagnostic_owner = OrderNotices(recipients)
        diagnostic_owner.db = diagnostic_owner.protocol.db = AdminRead()
        diagnostic_order = diagnostic_owner._order(str(oid))
        diagnostic_outcome = diagnostic_owner.outcome(diagnostic_order, diagnostic_owner._items(str(oid)), diagnostic_owner._payments(str(oid)))
        # Owner outcome contains only operational counts/labels, not accounts.
        Path('/evidence/p25-successor-diagnostic.json').write_text(json.dumps(diagnostic_outcome))
        _wait_for(admin, 'paid outcome successor recovery',
            "SELECT EXISTS (SELECT 1 FROM public.telegram_outbound_effects WHERE supersedes_effect_id=%s AND state='sent' AND sequence=2)",
            (initial['id'],), timeout=25)
        assert admin.execute('SELECT state,attempts FROM public.telegram_outbound_effects WHERE id=%s', (initial['id'],)).fetchone() == ('unknown', 1)
        _webhook(update_id + 1, 1, '/broadcast P25 HTTP confirmed broadcast')
        _wait_for(admin, 'private broadcast draft',
            "SELECT EXISTS (SELECT 1 FROM django_candidate.navigation_updates WHERE update_id=%s AND state='processed')",
            (update_id + 1,), timeout=15)
        state = admin.execute('SELECT s.state FROM django_candidate.navigation_sessions s JOIN public.customers c ON c.id=s.customer_id WHERE c.telegram_id=1').fetchone()[0]
        if isinstance(state, str):
            state = json.loads(state)
        draft = state['broadcast']
        import http.client
        from lhie_candidate.navigation.webhook import webhook_secret
        callback = {'update_id': update_id + 2, 'callback_query': {'id': 'p25-broadcast-confirm',
            'from': {'id': 1, 'is_bot': False}, 'data': 'broadcast:send:' + draft['id'],
            'message': {'message_id': 707, 'chat': {'id': 1, 'type': 'private'}}}}
        client = http.client.HTTPConnection('127.0.0.1', 8080, timeout=10)
        client.request('POST', '/webhook/telegram', json.dumps(callback),
            {'Content-Type': 'application/json', 'X-Telegram-Bot-Api-Secret-Token': webhook_secret()})
        response = client.getresponse()
        assert response.status == 200
        response.read()
        client.close()
        _wait_for(admin, 'confirmed broadcast bulk worker',
            "SELECT EXISTS (SELECT 1 FROM public.telegram_broadcasts b JOIN public.telegram_broadcast_recipients r ON r.broadcast_id=b.id WHERE b.message='P25 HTTP confirmed broadcast' AND r.sent_at IS NOT NULL)", (), timeout=30)
        _wait_for(admin, 'SMTP after gate released',
            "SELECT EXISTS (SELECT 1 FROM public.order_notification_events WHERE order_id=%s AND event_type='credentials_ready' AND status='sent')",
            (other,), timeout=15)
        _wait_for(admin, 'automatic auth mail on isolated worker',
                  "SELECT status='sent' AND payload IS NULL FROM public.customer_auth_mail_works WHERE id=%s",
                  (auth_id,), timeout=15)
        return {'notification_process_isolation': 'passed', 'smtp_held_navigation_receipt_progress': 'passed',
                'notification_runtime_connections': connections_count, 'automatic_auth_mail': 'passed',
                'paid_outcome_unknown_successor_recovery': 'passed', 'broadcast_http_confirm_bulk_delivery': 'passed'}
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
