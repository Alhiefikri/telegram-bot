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
        financial_entry = admin.execute('SELECT * FROM public.store_balance_entries WHERE source_id=%s ORDER BY id', (oid,)).fetchall()
        ownership = admin.execute('''SELECT o.customer_id,p.id,p.order_id FROM public.orders o
            JOIN public.payments p ON p.order_id=o.id WHERE o.id=%s ORDER BY p.id''', (oid,)).fetchall()
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
        from html import unescape
        from lhie_candidate.notifications.proof_publication import caption
        for order_id, expected in ((fixtures['php']['order_id'], fixtures['p25_sales_proof_copy']),
                                   (fixtures['p25_sales_proof_long']['order_id'], fixtures['p25_sales_proof_long'])):
            historical = proof.eligibility.load(order_id)
            for version in ('v1', 'v2'):
                actual = unescape(caption(historical, 37, 2, legacy=version == 'v1'))
                assert actual == expected[version], 'Historical Sales Proof ' + version + ' copy differs'
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
        import bcrypt
        password_hash = bcrypt.hashpw(fixtures['p25_admin_password'].encode(), bcrypt.gensalt(rounds=4)).decode()
        admin.execute('UPDATE public.users SET password=%s WHERE id=%s', (password_hash, actor))
        retry_order = str(fixtures['php']['order_id'])
        unknown_order = str(fixtures['p25_sales_proof_long']['order_id'])
        retry_effect = db.one('SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s AND source_id=%s',
                              (PROOF, retry_order))
        unknown_effect = db.one('SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s AND source_id=%s',
                                (PROOF, unknown_order))
        assert retry_effect and unknown_effect
        public.transport = SimpleNamespace(execute=lambda *args: ReceiptResult(None, 'definite', error_code='telegram_http_429'))
        for _ in range(3):
            admin.execute("UPDATE public.telegram_outbound_effects SET next_attempt_at=now()-interval '1 day' WHERE id=%s",
                          (retry_effect['id'],))
            assert public.deliver(retry_effect['id']) == 'failed'
        public.transport = SimpleNamespace(execute=lambda *args: ReceiptResult(None, 'ambiguous'))
        assert public.deliver(unknown_effect['id']) == 'unknown'
        fixtures['p25_browser_retry_order'] = retry_order
        fixtures['p25_browser_unknown_order'] = unknown_order
        from tests.test_waitlist_postgres import exercise_waitlist
        waitlist = exercise_waitlist(fixtures)
        retried = db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (retry_effect['id'],))
        assert retried['state'] == 'pending' and retried['attempts'] == 3
        assert db.one('SELECT state,attempts FROM public.telegram_outbound_effects WHERE id=%s',
                      (unknown_effect['id'],)) == {'state': 'unknown', 'attempts': 1}
        assert admin.execute("SELECT count(*) FROM public.audit_logs WHERE action='sales_proof.retry_requested' AND user_id=%s",
                             (actor,)).fetchone()[0] == 2
        public.transport = NotificationTransport()
        assert public.deliver(retry_effect['id']) == 'sent'
        assert public.deliver(retry_effect['id']) == 'not_ready'
        owner_alert = exercise_owner_alert(fixtures)
        compensation = exercise_owner_compensation(fixtures)
        auth_mail = exercise_auth_mail(fixtures)
        milestone = exercise_milestone_mail(oid, cipher, source, recipients)
        stock = exercise_stock_notifications(fixtures)
        balance = exercise_balance_notifications(fixtures)
        channel_proof = exercise_topup_channel_proof(fixtures)
        instruction = exercise_topup_instruction(fixtures)
        balance_proof = exercise_balance_proof(fixtures)
        supplier_health = exercise_supplier_health(fixtures)
        workers = exercise_notification_workers(fixtures)
        notices = exercise_order_notices(fixtures)
        from tests.test_supplier_notice_postgres import exercise_supplier_outcome
        supplier_outcome = exercise_supplier_outcome(fixtures)
        prices = exercise_price_notifications(fixtures)
        broadcasts = exercise_broadcast_creation(fixtures)
        assert admin.execute('SELECT * FROM public.store_balance_entries WHERE source_id=%s ORDER BY id', (oid,)).fetchall() == financial_entry
        assert admin.execute('''SELECT o.customer_id,p.id,p.order_id FROM public.orders o
            JOIN public.payments p ON p.order_id=o.id WHERE o.id=%s ORDER BY p.id''', (oid,)).fetchall() == ownership
        # Export only after the opt-in supervisor has drained. Automatic proof
        # publication may have reserved more numbers than the earlier manual
        # publication; an earlier snapshot cannot be used for rollback.
        from pathlib import Path
        rollback_floor = proof.handoff_counter(0, producers_stopped=True)
        Path('/evidence/counter-handoff.json').write_text(json.dumps({'php_floor': floor, 'rollback_floor': rollback_floor}))
        return {**waitlist, **workers, **notices, **auth_mail, **milestone, **prices, **broadcasts, **stock, **balance, **balance_proof, **channel_proof, **instruction, **supplier_health, **supplier_outcome, **compensation, **owner_alert, 'sales_proof_publication': 'passed',
                'sales_proof_definite_manual_retry': 'passed', 'sales_proof_historical_php_v1_v2_copy': 'passed',
                'sales_proof_admin_browser_retry_csrf_authorization_unknown': 'passed', 'credential_html_mail': 'passed',
                'notification_claim_fencing': 'passed', 'snapshot_mail_sink_retry': 'passed',
                'unknown_no_resend': 'passed', 'sent_projection_no_resend': 'passed',
                'fake_relay': 'passed', 'sales_proof_order_financial_history_and_ownership': 'passed'}
    finally:
        admin.close()


def exercise_topup_instruction(fixtures):
    import hashlib
    from concurrent.futures import ThreadPoolExecutor
    from threading import Barrier
    from types import SimpleNamespace
    from django.db import connections
    from pathlib import Path
    from tempfile import TemporaryDirectory
    from PIL import Image
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.notifications.policy import TOPUP_INSTRUCTIONS, TestRecipients
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from lhie_candidate.payments.presentation import QrPresentation
    from lhie_candidate.receipt.transport import ReceiptResult

    source = fixtures['p25_topup_instruction']
    with TemporaryDirectory() as directory:
        Image.new('RGB', (48, 48), 'white').save(Path(directory) / 'qr-lhie.jpg')
        service = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})),
                                families=(TOPUP_INSTRUCTIONS,), qris=QrPresentation(public_storage=directory))
        effect = Database().one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (source['effect_id'],))
        for locale, expected in source['captions'].items():
            payload = service.payload(dict(effect, locale=locale))
            assert hashlib.sha256(payload['caption'].encode()).hexdigest() == expected
            assert payload['photo'] == (Path(directory) / 'qr-lhie.jpg').read_bytes()
            assert payload['reply_markup']['inline_keyboard'][0][0]['callback_data'] == (
                'balance:topup:' + str(source['top_up_id']) + ':check')
        assert service.deliver(effect['id']) == 'sent'
        assert service.deliver(effect['id']) == 'not_ready'
        from copy import deepcopy
        from threading import Event
        from lhie_candidate.funding.manual import ManualFunding
        from lhie_candidate.funding.service import FundingService
        from lhie_candidate.navigation.transport import RelayTransport
        funding_db = deepcopy(connections.databases['default'])
        funding_db.update(USER='candidate_p25_funding', PASSWORD=fixtures['p25_funding_db_password'])
        connections.databases['p25_funding'] = funding_db
        manual = ManualFunding(FundingService(using='p25_funding'), admin_user_id='1')
        assert manual.decide(topup_id=source['top_up_id'], operator_customer_id=fixtures['python']['customer_id'],
                             operator_chat_id='1', approve=True) == 'confirmed'
        entered, release, deletes = Event(), Event(), []
        class PausedDeletion:
            def execute(self, method, payload):
                assert not connections['default'].in_atomic_block
                assert method == 'deleteMessage'
                deletes.append(payload['message_id'])
                entered.set()
                assert release.wait(timeout=10)
                return RelayTransport().execute(method, payload)
        def clean():
            try:
                return service.topup_instructions.cleanup(source['top_up_id'], service.recipients, PausedDeletion())
            finally:
                connections.close_all()
        with ThreadPoolExecutor(max_workers=2) as pool:
            first = pool.submit(clean)
            assert entered.wait(timeout=10)
            second = pool.submit(clean)
            try:
                assert not second.result(timeout=10)
            finally:
                release.set()
            assert first.result(timeout=10)
        assert len(deletes) == 1
        assert Database().one('SELECT telegram_message_deleted_at FROM public.telegram_outbound_effects WHERE id=%s',
                              (effect['id'],))['telegram_message_deleted_at'] is not None
        assert not service.topup_instructions.cleanup(source['top_up_id'], service.recipients, PausedDeletion())
        fetched = []
        def image_fetcher(url, *, timeout, max_bytes):
            assert url == 'https://sakurupiah.id/assets/qr/synthetic.png'
            fetched.append((timeout, max_bytes))
            return (Path(directory) / 'qr-lhie.jpg').read_bytes()
        provider = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})),
            families=(TOPUP_INSTRUCTIONS,), qris=QrPresentation(public_storage=directory, image_fetcher=image_fetcher))
        provider_effect = Database().one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s',
                                         (source['provider_effect_id'],))
        for locale, expected in source['provider_captions'].items():
            payload = provider.payload(dict(provider_effect, locale=locale))
            assert hashlib.sha256(payload['caption'].encode()).hexdigest() == expected
            assert payload['photo'] == (Path(directory) / 'qr-lhie.jpg').read_bytes()
        assert provider.deliver(provider_effect['id']) == 'sent' and fetched
        assert provider.deliver(provider_effect['id']) == 'not_ready'
        from io import BytesIO
        import zxingcpp
        payload_effect = Database().one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s',
                                        (source['payload_effect_id'],))
        for locale, expected in source['payload_captions'].items():
            image_payload = provider.payload(dict(payload_effect, locale=locale))
            assert hashlib.sha256(image_payload['caption'].encode()).hexdigest() == expected
            barcode = zxingcpp.read_barcode(Image.open(BytesIO(image_payload['photo'])))
            assert barcode and barcode.valid and barcode.text == '0002010102125303360540820450.005802ID63045B6D'
        before_fetches = len(fetched)
        assert provider.deliver(payload_effect['id']) == 'sent' and len(fetched) == before_fetches
        assert provider.deliver(payload_effect['id']) == 'not_ready'
        funding = provider.topup_instructions.funding
        source_row = Database().one('''SELECT id,provider,status,expires_at,provider_expires_at,provider_qris_url,
            provider_qris_payload,provider_total_payment,provider_fee,provider_create_state,amount
            FROM public.store_balance_top_ups WHERE id=%s''', (payload_effect['source_id'],))
        bad_amount = dict(source_row, provider_total_payment=20451,
                          provider_qris_url=funding.encrypter.encrypt_string('https://sakurupiah.id/assets/qr/synthetic.png'))
        assert not funding.presentation(bad_amount)['payable']
        assert not funding.presentation(dict(source_row, provider='unknown'))['payable']
        old_json = dict(source_row, provider_qris_payload=None,
                        provider_qris_url=funding.encrypter.encrypt_json(
                            {'qris_image_url': 'https://sakurupiah.id/assets/qr/synthetic.png'}))
        assert funding.presentation(old_json)['payable']
        barrier = Barrier(2)
        def recover():
            try:
                worker = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})),
                                       families=(TOPUP_INSTRUCTIONS,), qris=QrPresentation(public_storage=directory))
                barrier.wait(timeout=10)
                worker.topup_instructions.recover(worker.recipients, 100)
            finally:
                connections.close_all()
        with ThreadPoolExecutor(max_workers=2) as pool:
            list(pool.map(lambda _: recover(), range(2)))
        rows = Database().rows('''SELECT * FROM public.telegram_outbound_effects
            WHERE effect_type=%s AND source_type=%s AND source_id=%s''',
            (TOPUP_INSTRUCTIONS, 'App\\Models\\StoreBalanceTopUp', str(source['lost_top_up_id'])))
        assert len(rows) == 1
        assert rows[0]['queue'] == effect['queue'] == 'telegram-outbound'
        assert rows[0]['effect_key'] == f"balance-top-up:{source['lost_top_up_id']}:manual-instructions:v1"
        assert rows[0]['template_version'] == effect['template_version'] == 'balance-top-up-manual-v1'
        service.transport = SimpleNamespace(execute=lambda *args: ReceiptResult(None, 'ambiguous'))
        assert service.deliver(rows[0]['id']) == 'unknown'
        service.topup_instructions.recover(service.recipients, 100)
        assert service.deliver(rows[0]['id']) == 'not_ready'
        assert Database().one('''SELECT count(*) AS n FROM public.telegram_outbound_effects
            WHERE effect_type=%s AND source_type=%s AND source_id=%s''',
            (TOPUP_INSTRUCTIONS, 'App\\Models\\StoreBalanceTopUp', str(source['lost_top_up_id'])))['n'] == 1
    return {'topup_instruction_php_locales_fake_relay': 'passed',
            'topup_instruction_php_sakurupiah_image_and_payload': 'passed',
            'topup_instruction_handoff_race_unknown': 'passed'}


def exercise_topup_channel_proof(fixtures):
    import hashlib
    from io import BytesIO
    from concurrent.futures import ThreadPoolExecutor
    from threading import Barrier
    from types import SimpleNamespace
    from django.db import connections
    from PIL import Image
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.notifications.policy import TOPUP_CHANNEL_PROOF, TestRecipients
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from lhie_candidate.receipt.transport import ReceiptResult
    from tests.test_checkout_postgres import admin_connection

    source = fixtures['p25_topup_channel_proof']
    admin = admin_connection()
    try:
        setting = admin.execute('''SELECT sales_proof_enabled,telegram_chat_id,channel_username
            FROM public.store_channel_settings WHERE singleton_key='store_channel' ''').fetchone()
        admin.execute('''UPDATE public.store_channel_settings SET sales_proof_enabled=true,
            telegram_chat_id='1',channel_username=NULL WHERE singleton_key='store_channel' ''')
        service = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})),
                                families=(TOPUP_CHANNEL_PROOF,))
        effect = Database().one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (source['effect_id'],))
        payload = service.payload(effect)
        assert hashlib.sha256(payload['caption'].encode()).hexdigest() == source['caption_sha256']
        with Image.open(BytesIO(payload['photo'])) as image:
            assert image.format == 'PNG' and image.size == (720, 720)
        assert service.deliver(effect['id']) == 'sent'
        assert service.deliver(effect['id']) == 'not_ready'
        barrier = Barrier(2)
        def recover():
            try:
                worker = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})),
                                       families=(TOPUP_CHANNEL_PROOF,))
                barrier.wait(timeout=10)
                worker.balance_notifications.recover_channel_proofs(worker.record, 100)
            finally:
                connections.close_all()
        with ThreadPoolExecutor(max_workers=2) as pool:
            list(pool.map(lambda _: recover(), range(2)))
        rows = Database().rows('''SELECT * FROM public.telegram_outbound_effects
            WHERE effect_type=%s AND source_type=%s AND source_id=%s''',
            (TOPUP_CHANNEL_PROOF, 'App\\Models\\StoreBalanceTopUp', str(fixtures['p25_topup_channel_lost'])))
        assert len(rows) == 1
        assert rows[0]['queue'] == effect['queue'] == 'notifications'
        service.transport = SimpleNamespace(execute=lambda *args: ReceiptResult(None, 'ambiguous'))
        assert service.deliver(rows[0]['id']) == 'unknown'
        service.balance_notifications.recover_channel_proofs(service.record, 100)
        assert service.deliver(rows[0]['id']) == 'not_ready'
        assert Database().one('''SELECT count(*) AS n FROM public.telegram_outbound_effects
            WHERE effect_type=%s AND source_type=%s AND source_id=%s''',
            (TOPUP_CHANNEL_PROOF, 'App\\Models\\StoreBalanceTopUp', str(fixtures['p25_topup_channel_lost'])))['n'] == 1
        assert Database().one('''SELECT count(*) AS n FROM public.telegram_outbound_effects
            WHERE effect_type=%s AND source_type=%s AND source_id=%s''',
            (TOPUP_CHANNEL_PROOF, 'App\\Models\\StoreBalanceTopUp', str(fixtures['p25_topup_channel_old'])))['n'] == 0
        return {'topup_channel_proof_php_caption_fake_relay': 'passed',
                'topup_channel_proof_handoff_race_unknown': 'passed'}
    finally:
        if setting:
            admin.execute('''UPDATE public.store_channel_settings SET sales_proof_enabled=%s,
                telegram_chat_id=%s,channel_username=%s WHERE singleton_key='store_channel' ''', setting)
        admin.close()


def exercise_owner_alert(fixtures):
    import hashlib
    import os
    from unittest.mock import patch
    from concurrent.futures import ThreadPoolExecutor
    from threading import Barrier
    from types import SimpleNamespace
    from django.db import connections
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.notifications.policy import OWNER_ALERT, TestRecipients
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    source = fixtures['p25_owner_alert']
    service = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})),
                            families=(OWNER_ALERT,), admin_target='1')
    effect = Database().one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (source['effect_id'],))
    with patch.dict(os.environ, {'TELEGRAM_BOT_TOKEN': 'fixture-' + 's' * 24}):
        payload = service.payload(effect)
    assert hashlib.sha256(payload['text'].encode()).hexdigest() == source['message_sha256']
    from tests.test_checkout_postgres import admin_connection
    admin = admin_connection()
    icon_id = source['custom_icon']['id']
    original = admin.execute('SELECT custom_emoji_id FROM public.telegram_ui_icons WHERE id=%s', (icon_id,)).fetchone()[0]
    try:
        admin.execute('UPDATE public.telegram_ui_icons SET custom_emoji_id=%s WHERE id=%s',
                      ('1234567890123456789', icon_id))
        with patch.dict(os.environ, {'TELEGRAM_BOT_TOKEN': 'fixture-' + 's' * 24}):
            custom = service.payload(effect)
        assert hashlib.sha256(custom['text'].encode()).hexdigest() == source['custom_icon']['text_sha256']
        assert custom.get('entities', []) == source['custom_icon']['entities']
        with patch.dict(os.environ, {'TELEGRAM_BOT_TOKEN': 'fixture-' + 's' * 24}):
            assert service.deliver(effect['id']) == 'sent'
    finally:
        admin.execute('UPDATE public.telegram_ui_icons SET custom_emoji_id=%s WHERE id=%s', (original, icon_id))
        admin.close()
    assert service.deliver(effect['id']) == 'not_ready'
    barrier = Barrier(2)
    def recover():
        try:
            owner = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})),
                                  families=(OWNER_ALERT,), admin_target='1')
            barrier.wait(timeout=10)
            owner.balance_notifications.recover_alerts(owner.record, owner.admin_target, 100)
        finally:
            connections.close_all()
    with ThreadPoolExecutor(max_workers=2) as pool:
        list(pool.map(lambda _: recover(), range(2)))
    rows = Database().rows('''SELECT * FROM public.telegram_outbound_effects
        WHERE effect_type=%s AND source_type=%s AND source_id=%s''',
        (OWNER_ALERT, 'App\\Models\\StoreBalanceTopUp', str(source['lost_top_up_id'])))
    assert len(rows) == 1
    from lhie_candidate.receipt.transport import ReceiptResult
    service.transport = SimpleNamespace(execute=lambda *args: ReceiptResult(None, 'ambiguous'))
    assert service.deliver(rows[0]['id']) == 'unknown'
    service.balance_notifications.recover_alerts(service.record, service.admin_target, 100)
    assert service.deliver(rows[0]['id']) == 'not_ready'
    assert Database().one('''SELECT count(*) AS n FROM public.telegram_outbound_effects
        WHERE effect_type=%s AND source_type=%s AND source_id=%s''',
        (OWNER_ALERT, 'App\\Models\\StoreBalanceTopUp', str(source['lost_top_up_id'])))['n'] == 1
    return {'balance_owner_alert_php_copy_fake_relay': 'passed',
            'balance_owner_alert_missing_handoff_race_unknown': 'passed'}


def exercise_owner_compensation(fixtures):
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.notifications.policy import TestRecipients, NotEligible
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from tests.test_checkout_postgres import admin_connection

    kind = 'balance.owner_compensation_notification'
    admin = admin_connection()
    try:
        topup_id = fixtures['p25_compensation']['top_up_id']
        row = admin.execute('''SELECT c.id,c.ledger_entry_id FROM public.store_balance_compensations c
            JOIN public.store_balance_top_ups t ON t.id=c.store_balance_top_up_id
            WHERE t.id=%s AND t.status='compensated' ''', (topup_id,)).fetchone()
        assert row is not None
        before = admin.execute('''SELECT id,amount,balance_after,source_type,source_id
            FROM public.store_balance_entries WHERE id=%s''', (row[1],)).fetchone()
        service = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})), families=(kind,))
        effect = Database().one('''SELECT * FROM public.telegram_outbound_effects
            WHERE source_type='App\\Models\\StoreBalanceCompensation' AND source_id=%s AND effect_type=%s''',
            (str(row[0]), kind))
        assert effect and effect['telegram_chat_id'] == '1'
        payload = service.payload(effect)
        assert '5.000' in payload['text'] and payload['chat_id'] == '1'
        try:
            service.payload({**effect, 'target_type': 'admin'})
        except NotEligible:
            pass
        else:
            raise AssertionError('Compensation accepted a foreign target')
        assert service.deliver(effect['id']) == 'sent'
        assert service.deliver(effect['id']) == 'not_ready'
        assert admin.execute('''SELECT id,amount,balance_after,source_type,source_id
            FROM public.store_balance_entries WHERE id=%s''', (row[1],)).fetchone() == before
        from django.db import connections
        missing = admin.execute('''SELECT id FROM public.store_balance_compensations
            WHERE store_balance_top_up_id=%s''', (fixtures['p25_compensation']['missing_top_up_id'],)).fetchone()[0]
        assert Database().one('SELECT count(*) AS n FROM public.telegram_outbound_effects WHERE source_type=%s AND source_id=%s AND effect_type=%s',
            ('App\\Models\\StoreBalanceCompensation', str(missing), kind))['n'] == 0
        barrier = Barrier(2)
        def recover():
            try:
                owner = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})), families=(kind,))
                barrier.wait(timeout=10)
                owner.balance_notifications.recover_compensations(owner.record, 100)
            finally:
                connections.close_all()
        with ThreadPoolExecutor(max_workers=2) as pool:
            list(pool.map(lambda _: recover(), range(2)))
        recovered = Database().rows('''SELECT * FROM public.telegram_outbound_effects
            WHERE source_type=%s AND source_id=%s AND effect_type=%s''',
            ('App\\Models\\StoreBalanceCompensation', str(missing), kind))
        assert len(recovered) == 1
        from lhie_candidate.receipt.transport import ReceiptResult
        service.transport = SimpleNamespace(execute=lambda *args: ReceiptResult(None, 'ambiguous'))
        assert service.deliver(recovered[0]['id']) == 'unknown'
        service.balance_notifications.recover_compensations(service.record, 100)
        assert service.deliver(recovered[0]['id']) == 'not_ready'
        assert Database().one('''SELECT count(*) AS n FROM public.telegram_outbound_effects
            WHERE source_type=%s AND source_id=%s AND effect_type=%s''',
            ('App\\Models\\StoreBalanceCompensation', str(missing), kind))['n'] == 1
        return {'owner_compensation_php_owner_fake_relay': 'passed',
                'owner_compensation_missing_handoff_race_unknown': 'passed'}
    finally:
        admin.close()


def exercise_supplier_health(fixtures):
    import hashlib
    import re
    from datetime import timedelta
    from lhie_candidate.auth.types import legacy_now
    from concurrent.futures import ThreadPoolExecutor
    from threading import Barrier, Event
    from django.db import connections
    from tests.test_checkout_postgres import admin_connection
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.notifications.policy import SUPPLIER_HEALTH, TestRecipients
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from lhie_candidate.receipt.transport import ReceiptResult
    from lhie_candidate.workers.notifications import build_notification_handler
    from lhie_candidate.workers.wakeup import encode
    db = Database()
    service = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})),
                            families=(SUPPLIER_HEALTH,), admin_target='1')
    sent, uncertain = fixtures['p25_supplier_health']
    effect = db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (sent['effect_id'],))
    assert service.payload(effect)['text'] == sent['message']
    handler = build_notification_handler('notice')
    wakeup = encode('notification.effect', effect['id'])
    assert handler.handle(wakeup) == 'sent'
    assert handler.handle(wakeup) == 'not_ready'
    effect = db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (uncertain['effect_id'],))
    assert service.payload(effect)['text'] == uncertain['message']
    service.transport = SimpleNamespace(execute=lambda *args: ReceiptResult(None, 'ambiguous'))
    assert service.deliver(effect['id']) == 'unknown'
    assert service.deliver(effect['id']) == 'not_ready'
    assert db.one('SELECT state FROM public.telegram_outbound_effects WHERE id=%s', (effect['id'],))['state'] == 'unknown'
    source = fixtures['p25_health_episode']
    admin = admin_connection()
    try:
        admin.execute('UPDATE public.supplier_health_scans SET supplier_cursor=%s WHERE id=1',
                      (source['supplier_id'] - 1,))
        barrier = Barrier(2)
        def scan():
            try:
                worker = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})),
                                       families=(SUPPLIER_HEALTH,), admin_target='1')
                barrier.wait(timeout=10)
                return worker.supplier_health.materialize(worker.record, '1', 1)
            finally:
                connections.close_all()
        with ThreadPoolExecutor(max_workers=2) as pool:
            assert sum(pool.map(lambda _: scan(), range(2))) == 2
        event = db.one('SELECT id,message FROM public.supplier_health_events WHERE supplier_id=%s',
                       (source['supplier_id'],))
        assert hashlib.sha256(event['message'].encode()).hexdigest() == source['message_sha256']
        assert db.one('SELECT low_active FROM public.supplier_health_states WHERE supplier_id=%s',
                      (source['supplier_id'],))['low_active']
        effect = db.one('''SELECT * FROM public.telegram_outbound_effects WHERE source_type=%s
            AND source_id=%s AND effect_type=%s''',
            ('App\\Models\\SupplierHealthEvent', str(event['id']), SUPPLIER_HEALTH))
        assert effect and effect['state'] == 'pending'
        service.transport = NotificationTransport()
        assert service.deliver(effect['id']) == 'sent'
        assert service.deliver(effect['id']) == 'not_ready'
        review = source['review']
        review_event = db.one('SELECT id,message FROM public.supplier_health_events WHERE supplier_id=%s',
                              (review['supplier_id'],))
        assert review_event and 'Review/ambigu baru: 1 obligation' in review_event['message']
        assert 'dispatch_outcome_unknown' in review_event['message']
        assert 'Purchase #' + str(review['purchase_id']) in review_event['message']
        assert review['invoice'] in review_event['message']
        normalized = re.sub(r'Invoice: [^\n]+', 'Invoice: [invoice]', review_event['message'])
        normalized = re.sub(r'Purchase #[0-9]+; SKU #[0-9]+', 'Purchase #[purchase]; SKU #[sku]', normalized)
        normalized = re.sub(r'Waktu: [^\n]+', 'Waktu: [time]', normalized)
        assert hashlib.sha256(normalized.encode()).hexdigest() == review['message_sha256']
        assert db.one('SELECT active_identities FROM public.supplier_health_reviews WHERE supplier_fulfillment_id=%s',
                      (review['fulfillment_id'],))['active_identities']
        at = legacy_now()
        service.supplier_health.clock = lambda: at
        def scan_one():
            admin.execute('''UPDATE public.supplier_health_scans SET supplier_cursor=%s,
                fulfillment_cursor=%s WHERE id=1''', (source['supplier_id'] - 1, review['fulfillment_id'] - 1))
            return service.supplier_health.materialize(service.record, '1', 1)
        assert scan_one() == 0
        admin.execute('''UPDATE public.suppliers SET balance_micros=2000000,balance_observed_at=%s,
            synced_at=%s,sync_expires_at=%s,last_error=NULL WHERE id=%s''',
            (at, at, at + timedelta(hours=1), source['supplier_id']))
        assert scan_one() == 0
        assert not db.one('SELECT low_active FROM public.supplier_health_states WHERE supplier_id=%s',
                          (source['supplier_id'],))['low_active']
        admin.execute('UPDATE public.suppliers SET balance_micros=1000000 WHERE id=%s', (source['supplier_id'],))
        assert scan_one() == 1
        admin.execute("UPDATE public.suppliers SET last_error='transport',sync_failures=2 WHERE id=%s", (source['supplier_id'],))
        assert scan_one() == 0
        admin.execute('UPDATE public.suppliers SET sync_failures=3 WHERE id=%s', (source['supplier_id'],))
        assert scan_one() == 1
        admin.execute("UPDATE public.suppliers SET last_error='rate_limited' WHERE id=%s", (source['supplier_id'],))
        assert scan_one() == 0
        admin.execute('UPDATE public.suppliers SET last_error=NULL WHERE id=%s', (source['supplier_id'],))
        assert scan_one() == 0
        at += timedelta(minutes=16)
        admin.execute('''UPDATE public.suppliers SET synced_at=%s,sync_expires_at=%s,
            balance_observed_at=%s WHERE id=%s''', (at, at + timedelta(hours=1), at, source['supplier_id']))
        assert scan_one() == 1
        hold = db.one('''SELECT message FROM public.supplier_health_events WHERE supplier_id=%s
            AND message LIKE %s''', (review['supplier_id'], '%Hold menua%'))
        assert hold and 'Purchase #' + str(review['purchase_id']) in hold['message']
        assert db.one('SELECT error_codes FROM public.supplier_health_states WHERE supplier_id=%s',
                      (source['supplier_id'],))['error_codes'] == []
        admin.execute("UPDATE public.suppliers SET last_error='transport' WHERE id=%s", (source['supplier_id'],))
        assert scan_one() == 1
        admin.execute('''UPDATE public.suppliers SET last_error=NULL,balance_observed_at=%s,
            sync_next_attempt_at=%s WHERE id=%s''',
            (at - timedelta(minutes=16), at, source['supplier_id']))
        assert scan_one() == 0
        admin.execute('UPDATE public.suppliers SET sync_next_attempt_at=%s WHERE id=%s',
                      (at - timedelta(minutes=3), source['supplier_id']))
        assert scan_one() == 1
        assert scan_one() == 0
        assert db.one('SELECT balance_stale_active FROM public.supplier_health_states WHERE supplier_id=%s',
                      (source['supplier_id'],))['balance_stale_active']
        admin.execute('''UPDATE public.supplier_health_scans SET supplier_cursor=%s,
            fulfillment_cursor=%s WHERE id=1''', (source['supplier_id'] - 1, review['fulfillment_id'] - 1))
        entered, finished = Event(), Event()
        def scan_locked():
            try:
                worker = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})),
                                       families=(SUPPLIER_HEALTH,), admin_target='1')
                worker.supplier_health.clock = lambda: at
                entered.set()
                return worker.supplier_health.materialize(worker.record, '1', 1)
            finally:
                connections.close_all()
                finished.set()
        admin.execute('BEGIN')
        try:
            admin.execute('''UPDATE public.suppliers SET balance_micros=2000000,balance_observed_at=%s,
                sync_next_attempt_at=NULL WHERE id=%s''', (at, source['supplier_id']))
            with ThreadPoolExecutor(max_workers=1) as pool:
                pending = pool.submit(scan_locked)
                assert entered.wait(5)
                assert not finished.wait(0.2)
                admin.execute('COMMIT')
                assert pending.result(timeout=10) == 0
        finally:
            admin.execute('ROLLBACK')
        assert not db.one('SELECT low_active FROM public.supplier_health_states WHERE supplier_id=%s',
                          (source['supplier_id'],))['low_active']
    finally:
        admin.close()
    return {'supplier_health_retained_delivery_unknown': 'passed',
            'supplier_health_php_low_episode_concurrency': 'passed',
            'supplier_health_low_error_stale_recovery': 'passed',
            'supplier_health_supplier_lock_state_recheck': 'passed'}


def exercise_balance_proof(fixtures):
    from html import unescape
    from django.db import connections
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.funding.manual import ManualFunding
    from lhie_candidate.funding.service import FundingService
    from lhie_candidate.notifications.balance import PAYMENT_PROOF
    from lhie_candidate.notifications.policy import TestRecipients
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from lhie_candidate.receipt.transport import ReceiptResult
    from tests.test_checkout_postgres import admin_connection

    db, admin = Database(), admin_connection()
    fixture = fixtures['p25_balance_proof']
    service = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})), families=(PAYMENT_PROOF,))
    funding = ManualFunding(FundingService())
    try:
        before = admin.execute('SELECT count(*) FROM public.store_balance_entries').fetchone()[0]
        admin.execute("UPDATE public.manual_payment_settings SET admin_telegram_chat_id='1' WHERE is_active=true")
        assert funding.submit_proof(customer_id=fixture['customer_id'], topup_id=fixture['top_up_id'],
                                    photo_file_id='synthetic-proof-file') == 'accepted'
        assert funding.submit_proof(customer_id=fixture['customer_id'], topup_id=fixture['top_up_id'],
                                    photo_file_id='synthetic-proof-file') == 'duplicate'
        effect = db.one('''SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s
            AND source_id=%s''', (PAYMENT_PROOF, str(fixture['top_up_id'])))
        payload = service.payload(effect)
        assert unescape(payload['caption']) == fixture['caption']
        assert payload['photo'] == 'synthetic-proof-file'
        assert service.deliver(effect['id']) == 'sent'
        assert service.deliver(effect['id']) == 'not_ready'
        admin.execute('UPDATE public.store_balance_top_ups SET payment_proof_forwarded_at=NULL WHERE id=%s', (fixture['top_up_id'],))
        sent = db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (effect['id'],))
        assert service.project(sent) == 1
        assert service.project(sent) == 0
        uncertain = db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (fixture['unknown_effect_id'],))
        service.transport = SimpleNamespace(execute=lambda *args: ReceiptResult(None, 'ambiguous'))
        assert service.deliver(uncertain['id']) == 'unknown'
        service.transport = NotificationTransport()
        lost_id = str(fixture['lost_top_up_id'])
        assert db.one('SELECT id FROM public.telegram_outbound_effects WHERE effect_type=%s AND source_id=%s',
                      (PAYMENT_PROOF, lost_id)) is None
        barrier = Barrier(2)
        def recover_concurrently():
            worker = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})),
                                   families=(PAYMENT_PROOF,))
            try:
                barrier.wait(timeout=10)
                worker.balance_notifications.recover_proofs(worker.record, 1)
            finally:
                connections.close_all()
        with ThreadPoolExecutor(max_workers=2) as pool:
            list(pool.map(lambda _: recover_concurrently(), range(2)))
        assert db.one('SELECT count(*) AS n FROM public.telegram_outbound_effects WHERE effect_type=%s AND source_id=%s',
                      (PAYMENT_PROOF, lost_id))['n'] == 1
        service.recover(1)
        recovered = db.one('SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s AND source_id=%s',
                           (PAYMENT_PROOF, lost_id))
        assert recovered['state'] == 'sent'
        assert service.deliver(recovered['id']) == 'not_ready'
        service.recover(1)
        assert db.one('SELECT count(*) AS n FROM public.telegram_outbound_effects WHERE effect_type=%s AND source_id=%s',
                      (PAYMENT_PROOF, lost_id))['n'] == 1
        assert db.one('SELECT state FROM public.telegram_outbound_effects WHERE id=%s', (uncertain['id'],))['state'] == 'unknown'
        assert db.one('SELECT count(*) AS n FROM public.telegram_outbound_effects WHERE effect_type=%s AND source_id=%s',
                      (PAYMENT_PROOF, uncertain['source_id']))['n'] == 1
        assert admin.execute('SELECT count(*) FROM public.store_balance_entries').fetchone()[0] == before
        return {'balance_payment_proof_php_copy_projection': 'passed', 'balance_proof_missing_handoff_recovery': 'passed',
                'balance_proof_unknown_not_rediscovered': 'passed'}
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
        from lhie_candidate.notifications.stock import STOCK_MOVEMENT_PREDICATE
        vectors = identity['eligibility']
        values = ','.join(['(%s::integer,%s::text,%s::integer,%s::integer,%s::integer,%s::boolean)'] * len(vectors))
        parameters = [value for index, vector in enumerate(vectors)
                      for value in (index, vector['type'], vector['quantity'], vector['before'],
                                    vector['after'], vector['unavailable'])]
        selected = {row['id'] for row in db.rows('''SELECT id FROM (VALUES ''' + values +
            ''') AS m(id,type,quantity,available_before,available_after,became_unavailable)
            WHERE ''' + STOCK_MOVEMENT_PREDICATE, parameters)}
        assert selected == {index for index, vector in enumerate(vectors) if vector['expected']}
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
            historical, _, _ = render(product, dict(movement, available_after=None), event, locale)
            assert unescape(historical) == identity['historical_messages'][locale], 'Historical stock fallback differs from PHP for ' + locale
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
        empty_id = fixtures['p25_empty_stock_movement']
        empty = db.one('''SELECT m.stock_notification_sent_at,e.recipients_snapshotted_at
            FROM public.inventory_movements m JOIN public.stock_notification_events e ON e.inventory_movement_id=m.id
            WHERE m.id=%s''', (empty_id,))
        assert empty['recipients_snapshotted_at'] and empty['stock_notification_sent_at'] is None
        assert event['recipients_snapshotted_at'] is None
        count = db.one('SELECT count(*) AS count FROM public.telegram_outbound_effects')['count']
        service.stock_notifications.recover(1)
        assert db.one('SELECT stock_notification_sent_at FROM public.inventory_movements WHERE id=%s',
                      (empty_id,))['stock_notification_sent_at'] is not None
        assert db.one('SELECT stock_notification_sent_at FROM public.inventory_movements WHERE id=%s',
                      (identity['movement_id'],))['stock_notification_sent_at'] is None
        service.stock_notifications.recover(1)
        assert db.one('SELECT count(*) AS count FROM public.telegram_outbound_effects')['count'] == count
        one = fixtures['p25_one_stock_audience']
        assert db.one('SELECT stock_notification_sent_at FROM public.inventory_movements WHERE id=%s',
                      (one['movement_id'],))['stock_notification_sent_at'] is None
        claimed = service.protocol.claim(one['effect_id'])
        assert claimed is not None
        assert service.protocol.finalize_unknown(one['effect_id'], claimed['claim_token'])['state'] == 'unknown'
        # No transport is invoked: this simulates a lost acknowledgement for the
        # PHP-owned one-recipient snapshot and verifies aggregate repair only.
        service.stock_notifications.repair_projections(100)
        service.stock_notifications.repair_projections(100)
        assert db.one('SELECT stock_notification_sent_at FROM public.inventory_movements WHERE id=%s',
                      (one['movement_id'],))['stock_notification_sent_at'] is None
        assert service.protocol.claim(one['effect_id']) is None
        assert db.one('SELECT count(*) AS count FROM public.telegram_outbound_effects')['count'] == count
        sent = fixtures['p25_sent_stock_audience']
        assert db.one('SELECT stock_notification_sent_at FROM public.inventory_movements WHERE id=%s',
                      (sent['movement_id'],))['stock_notification_sent_at'] is None
        assert service.deliver(sent['effect_id']) == 'sent'
        service.stock_notifications.repair_projections(100)
        service.stock_notifications.repair_projections(100)
        assert db.one('SELECT stock_notification_sent_at FROM public.inventory_movements WHERE id=%s',
                      (sent['movement_id'],))['stock_notification_sent_at'] is not None
        assert service.deliver(sent['effect_id']) == 'not_ready'
        assert db.one('SELECT count(*) AS count FROM public.telegram_outbound_effects')['count'] == count
        failed = fixtures['p25_failed_stock_audience']
        claim = service.protocol.claim(failed['effect_id'])
        assert claim is not None
        result = service.protocol.finalize_failed(failed['effect_id'], claim['claim_token'], 'telegram_http_403')
        assert result['state'] == 'failed' and result['next_attempt_at'] is None
        service.stock_notifications.repair_projections(100)
        service.stock_notifications.repair_projections(100)
        assert db.one('SELECT stock_notification_sent_at FROM public.inventory_movements WHERE id=%s',
                      (failed['movement_id'],))['stock_notification_sent_at'] is not None
        assert service.protocol.claim(failed['effect_id']) is None
        assert db.one('SELECT count(*) AS count FROM public.telegram_outbound_effects')['count'] == count
        for outcome, audience in fixtures['p25_many_stock_audience'].items():
            effects = sorted(audience['effects'], key=lambda item: int(item['chat']))
            assert [int(item['chat']) for item in effects] == [1, 2, 3, 4, 5]
            assert service.deliver(effects[0]['id']) == 'sent'
            for item in effects[1:-1]:
                claim = service.protocol.claim(item['id'])
                assert claim is not None
                assert service.protocol.finalize_failed(item['id'], claim['claim_token'], 'telegram_http_403')['state'] == 'failed'
            service.stock_notifications.repair_projections(100)
            assert db.one('SELECT stock_notification_sent_at FROM public.inventory_movements WHERE id=%s',
                          (audience['movement_id'],))['stock_notification_sent_at'] is None
            claim = service.protocol.claim(effects[-1]['id'])
            assert claim is not None
            if outcome == 'unknown':
                assert service.protocol.finalize_unknown(effects[-1]['id'], claim['claim_token'])['state'] == 'unknown'
            else:
                assert service.protocol.finalize_failed(effects[-1]['id'], claim['claim_token'], 'notification_not_eligible')['state'] == 'failed'
            service.stock_notifications.repair_projections(100)
            service.stock_notifications.repair_projections(100)
            assert db.one('SELECT stock_notification_sent_at FROM public.inventory_movements WHERE id=%s',
                          (audience['movement_id'],))['stock_notification_sent_at'] is None
            full = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1','2','3','4','5'})),
                                 families=(STOCK,))
            full.stock_notifications.repair_projections(100)
            full.stock_notifications.repair_projections(100)
            marker = db.one('SELECT stock_notification_sent_at FROM public.inventory_movements WHERE id=%s',
                            (audience['movement_id'],))['stock_notification_sent_at']
            assert (marker is None) == (outcome == 'unknown')
            assert service.deliver(effects[0]['id']) == 'not_ready'
            assert service.protocol.claim(effects[-1]['id']) is None
            assert db.one('SELECT count(*) AS count FROM public.telegram_outbound_effects')['count'] == count
        candidate = fixtures['p25_candidate_stock_audience']
        service.stock_notifications.materialize(candidate['event_id'], 2)
        assert db.one('SELECT recipients_snapshotted_at FROM public.stock_notification_events WHERE id=%s',
                      (candidate['event_id'],))['recipients_snapshotted_at'] is None
        chats = frozenset(str(item['telegram_id']) for item in db.rows(
            'SELECT telegram_id FROM public.customers WHERE telegram_id IS NOT NULL ORDER BY id'))
        assert len(chats) <= 100 and '1' in chats
        full = Notifications(NotificationTransport(), recipients=TestRecipients(chats), families=(STOCK,))
        for _ in range((len(chats) + 1) // 2 + 2):
            full.stock_notifications.materialize(candidate['event_id'], 2)
            snapshot = db.one('SELECT recipients_snapshotted_at FROM public.stock_notification_events WHERE id=%s',
                              (candidate['event_id'],))
            if snapshot['recipients_snapshotted_at'] is not None:
                break
        assert snapshot['recipients_snapshotted_at'] is not None
        recipients = db.rows('SELECT telegram_id FROM public.stock_notification_recipients WHERE stock_notification_event_id=%s',
                             (candidate['event_id'],))
        assert {item['telegram_id'] for item in recipients} == chats
        for _ in range((len(chats) + 99) // 100 + 2):
            full.materialize_recipients(100)
            missing = db.one('''SELECT count(*) AS n FROM public.stock_notification_recipients r
                WHERE r.stock_notification_event_id=%s AND NOT EXISTS (
                    SELECT 1 FROM public.telegram_outbound_effects e WHERE e.source_type=%s
                    AND e.source_id=r.id::text AND e.effect_type=%s)''',
                    (candidate['event_id'], 'App\\Models\\StockNotificationRecipient', STOCK))['n']
            if missing == 0:
                break
        assert missing == 0
        return {'stock_php_locale_tier_differential': 'passed', 'stock_channel_photo_materialization': 'passed',
                'stock_complete_empty_audience_projection_repair': 'passed',
                'stock_php_transition_eligibility_differential': 'passed',
                'stock_historical_missing_snapshot_php_copy': 'passed',
                'stock_filtered_audience_projection_incomplete': 'passed',
                'stock_complete_audience_unknown_not_projected': 'passed',
                'stock_complete_audience_sent_projection_repair': 'passed',
                'stock_complete_audience_terminal_projection_repair': 'passed',
                'stock_php_multi_recipient_handoff_and_projection': 'passed',
                'stock_candidate_bounded_complete_audience_handoff': 'passed'}
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
        audience = fixtures['p25_broadcast_audience']
        effects = sorted(audience['effects'], key=lambda item: int(item['chat']))
        assert [int(item['chat']) for item in effects] == [1, 2, 3, 4, 5]
        existing = db.one('SELECT state FROM public.telegram_outbound_effects WHERE id=%s', (effects[0]['id'],))
        if existing['state'] == 'sent':
            assert service.deliver(effects[0]['id']) == 'not_ready'
        else:
            assert existing['state'] == 'pending'
            assert service.deliver(effects[0]['id']) == 'sent'
        for item in effects[1:]:
            claim = service.protocol.claim(item['id'])
            assert claim is not None
            final = service.protocol.finalize_failed(item['id'], claim['claim_token'], 'telegram_http_403')
            assert final['state'] == 'failed'
        service._aggregate(final)
        projected = db.one('SELECT status,completed_at FROM public.telegram_broadcasts WHERE id=%s', (audience['id'],))
        assert projected['completed_at'] is None and projected['status'] == 'in_progress'
        full = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1','2','3','4','5'})),
                             families=(BROADCAST,))
        full._aggregate(final)
        projected = db.one('SELECT status,completed_at FROM public.telegram_broadcasts WHERE id=%s', (audience['id'],))
        assert projected['completed_at'] is not None and projected['status'] == 'partially_failed'
        service._aggregate(final)
        assert db.one('SELECT status,completed_at FROM public.telegram_broadcasts WHERE id=%s',
                      (audience['id'],)) == projected
        from datetime import timedelta
        from unittest.mock import patch
        with patch('lhie_candidate.notifications.broadcast._now', return_value=projected['completed_at'] + timedelta(minutes=1)):
            full._aggregate(final)
        assert db.one('SELECT status,completed_at FROM public.telegram_broadcasts WHERE id=%s',
                      (audience['id'],)) == projected
        assert full.deliver(effects[0]['id']) == 'not_ready'
        return {'confirmed_broadcast_creation_replay_cancel': 'passed',
                'broadcast_php_complete_audience_filtered_projection': 'passed'}
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
            'php-auth': fixtures['p25_auth_html']['password_reset'].replace(
                fixtures['p25_auth_copy']['password_reset']['url'], auth_copy('password_reset', '[REDACTED]')['url']),
            'waitlist': render_to_string('notifications/waitlist_confirmation.html', {
                'product_name': '<Product>', 'confirmation_url': 'http://127.0.0.1:18801/waitlist/confirm/redacted'}),
            'credential': render_to_string('notifications/credential.html', presentation({
                'invoice_number': 'INV-P25-REDACTED', 'invoice_url': 'http://127.0.0.1:18801/invoice',
                'groups': [{'product_name': '<Product>', 'default_tutorial': 'Petunjuk penggunaan produk.',
                    'accounts': [{'format': 'structured_account', 'email': '[REDACTED]', 'password': '[REDACTED]',
                        'access_method': 'auth_token', 'access_value': '[REDACTED]', 'number': 1,
                        'reader_mail_link': 'https://reader.example.invalid/redacted', 'tutorial': 'Petunjuk akun.'}]}]})),
        }
        guide = {'title': 'Panduan <aman>', 'url': 'https://docs.example.invalid/guide'}
        variant = {'invoice_number': 'INV-P25-REDACTED', 'invoice_url': 'http://127.0.0.1:18801/invoice',
            'groups': [
                {'product_name': 'Legacy & tutorial', 'default_tutorial': None, 'guide': guide,
                 'accounts': [{'format': 'legacy', 'secret': '[REDACTED]', 'number': 1, 'tutorial': None}]},
                {'product_name': 'OTP Mail', 'default_tutorial': 'Panduan bawaan', 'guide': guide,
                 'accounts': [
                     {'format': 'structured_account', 'email': '[REDACTED]', 'password': '[REDACTED]',
                      'access_method': 'otp_mail_link', 'access_value': 'https://reader.example.invalid/redacted',
                      'number': 1, 'tutorial': 'Panduan bawaan'},
                     {'format': 'structured_account', 'email': '[REDACTED]', 'password': '[REDACTED]',
                      'access_method': 'auth_token', 'access_value': '[REDACTED]',
                      'number': 2, 'tutorial': 'Petunjuk khusus'},
                 ]},
            ]}
        previews['credential-variant'] = render_to_string('notifications/credential.html', presentation(variant))
        previews['php-credential-variant'] = fixtures['p25_credential_variant_html']
        for purpose in ('magic', 'email_verification', 'email_otp'):
            copy = auth_copy(purpose, '[REDACTED]')
            php_html = fixtures['p25_auth_html'][purpose]
            if copy['url']:
                php_html = php_html.replace(fixtures['p25_auth_copy'][purpose]['url'], copy['url'])
            previews['auth-' + purpose] = render_to_string('notifications/auth.html', copy)
            previews['php-auth-' + purpose] = php_html
        for name, content in previews.items():
            Path('/evidence/preview-' + name + '.html').write_text(content)
        assert normalized_html(previews['credential']) == normalized_html(previews['php-credential'])
        assert normalized_html(previews['credential-variant']) == normalized_html(previews['php-credential-variant'])
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
