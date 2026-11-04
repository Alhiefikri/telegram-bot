"""P13 changed-flow proof on the existing disposable P08/P07 authority."""
from datetime import datetime, timedelta
from io import BytesIO
from zoneinfo import ZoneInfo
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
from unittest.mock import patch

from PIL import Image
import zxingcpp

from tests.test_checkout_postgres import admin_connection


def exercise_qris_rails(fixtures):
    from django.db import connections, transaction
    from django.test import Client
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.checkout.errors import CheckoutError
    from lhie_candidate.checkout.intents import PaymentPlan, PaymentPreflight
    from lhie_candidate.checkout.lifecycle import OrderLifecycle
    from lhie_candidate.checkout.manual import ManualQrisPreparation
    from lhie_candidate.checkout.navigation import NavigationCheckout, _lifecycle_view
    from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.navigation.presentation import CatalogPresenter
    from lhie_candidate.navigation.repository import CatalogRepository
    from lhie_candidate.navigation.roots import NavigationRoots
    from lhie_candidate.navigation.types import TelegramResult
    from lhie_candidate.payments.ingress import receive_klikqris, receive_pakasir
    from lhie_candidate.payments.klikqris import KlikQrisAdapter, KlikQrisConfig, KlikQrisLifecycle
    from lhie_candidate.payments.lifecycle import SakurupiahLifecycle
    from lhie_candidate.payments.manual import ManualQrisLifecycle
    from lhie_candidate.payments.pakasir import PakasirAdapter, PakasirLifecycle, PakasirCancellationAttempts
    from lhie_candidate.payments.presentation import QrPresentation, render_payload
    from lhie_candidate.payments.routing import QrisPayments
    from lhie_candidate.payments.sakurupiah import SakurupiahSandboxAdapter
    from lhie_candidate.payments.settings import ProviderSettings
    from lhie_candidate.readiness import verify_dependencies
    from lhie_candidate.receipt.runtime import receipt_service
    from lhie_candidate.receipt.transport import ReceiptResult
    from lhie_candidate.runtime import application_key
    from lhie_candidate.workers.payment import PaymentHandler
    from lhie_candidate.workers.wakeup import encode
    from p08_fixture import Sandbox

    admin, db = admin_connection(), Database()
    cipher = LaravelEncrypter(application_key())
    at = datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)
    tick = [at]
    received = []
    sandbox = Sandbox()
    directory = tempfile.TemporaryDirectory(prefix='p13-safe-assets-')
    root = Path(directory.name)
    # No live QR/receiver assets are copied into this fixture.
    qr = render_payload('Synthetic P13 non-payment asset')
    (root / 'qr-lhie.jpg').write_bytes(qr)
    (root / 'payment-qr-templates').mkdir()
    canvas = BytesIO()
    Image.new('RGB', (800, 800), 'white').save(canvas, format='PNG')
    (root / 'payment-qr-templates/local.png').write_bytes(canvas.getvalue())
    presentation = QrPresentation(public_storage=root, image_fetcher=lambda *args, **kwargs: qr)

    class KlikTransport:
        def __init__(self):
            self.rows, self.calls, self.uncertain, self.failure = {}, [], False, None
            self.on_create = None
        def request(self, method, url, **kwargs):
            assert not connections['default'].in_atomic_block
            self.calls.append((method, url))
            if self.failure:
                return self.failure, b'{}'
            if method == 'POST':
                fields = kwargs['json']
                ref, amount = fields['order_id'], fields['amount']
                self.rows.setdefault(ref, {'order_id': ref, 'amount': amount, 'total_amount': amount + 16,
                    'status': 'PENDING', 'signature': 'synthetic-' + ref,
                    'provider_reference': 'klik-' + ref, 'expired_at': (at + timedelta(minutes=15)).isoformat(),
                    'qris_url': 'https://klikqris.com/storage/sandbox/qris_P13.png'})
                if self.on_create is not None:
                    self.on_create()
                    self.on_create = None
                if self.uncertain:
                    self.uncertain = False
                    raise TimeoutError('synthetic lost create ACK')
            else:
                ref = url.rsplit('/', 1)[-1]
            return 200, json.dumps({'data': self.rows[ref]}).encode()

    class PakasirTransport:
        def __init__(self):
            self.rows, self.calls, self.failure, self.paid_on_cancel = {}, [], False, False
        def request(self, method, url, **kwargs):
            assert not connections['default'].in_atomic_block
            self.calls.append((method, url))
            if self.failure:
                return 503, b'{}'
            ref = kwargs['fields']['order_id']
            row = self.rows[ref]
            if method == 'POST':
                row['status'] = 'completed' if self.paid_on_cancel else 'cancelled'
                return 200, b'{"status":"cancelled"}'
            return 200, json.dumps({'transaction': row}).encode()

    kt, pt = KlikTransport(), PakasirTransport()
    klik = KlikQrisLifecycle(KlikQrisAdapter(transport=kt, config=KlikQrisConfig(
        enabled=True, operational_ready=True, api_key='synthetic', merchant_id='synthetic')), cipher, clock=lambda: tick[0])
    pakasir = PakasirLifecycle(PakasirAdapter(transport=pt, project='retained-synthetic'), cipher, clock=lambda: tick[0])
    sakura = SakurupiahLifecycle(SakurupiahSandboxAdapter(transport=sandbox), cipher, retry_seconds=0)
    rails = QrisPayments({'klikqris': klik, 'pakasir': pakasir, 'sakurupiah': sakura}, presentation=presentation)
    worker = PaymentHandler(rails, db)
    settings = ProviderSettings(readiness={'manual': presentation.manual_ready, 'klikqris': lambda: not klik.adapter.readiness(),
                                          'sakurupiah': lambda: bool(sakura.adapter.list_channels())})
    client = Client(HTTP_HOST='localhost')
    limiter = SimpleNamespace(reserve=lambda *args: ())

    def reject(call, code):
        try:
            call()
        except CheckoutError as error:
            assert error.code == code, (error.code, code)
        else:
            raise AssertionError('Expected refusal: ' + code)

    def begin(name, preparation=None):
        fixture = fixtures[name]
        preparation = preparation or rails.preparation(settings.current())
        service = CheckoutService(preparation=preparation)
        request = CheckoutRequest(fixture['customer_id'], fixture['cart_id'], 'p13-' + name,
                                  service.quote_cart(fixture['customer_id'], fixture['cart_id']))
        result = service.checkout(request)
        assert service.checkout(request).replayed
        return fixture, result

    def payment(identity):
        return db.one('SELECT provider,merchant_ref,provider_reference,amount,expired_at,provider_total_payment,provider_expires_at,status FROM public.payments WHERE id=%s', (identity,))

    def economic(result):
        return admin.execute('''SELECT o.order_status,o.payment_status,p.status,
            (SELECT count(*) FROM public.inventory_movements WHERE order_id=o.id AND type='sale'),
            (SELECT count(*) FROM public.external_money_receipts WHERE target_id=p.id::text),
            (SELECT count(*) FROM public.manual_fulfillment_reviews WHERE payment_id=p.id)
            FROM public.orders o JOIN public.payments p ON p.order_id=o.id WHERE o.id=%s''', (result.order_id,)).fetchone()

    def klik_callback(result, *, valid=True):
        row = payment(result.payment_id)
        body = dict(kt.rows[row['merchant_ref']])
        body.update(status='SUCCESS', signature=body['signature'] if valid else 'wrong', private='drop-me')
        with patch('lhie_candidate.payments.ingress.payment_services', return_value=rails), \
             patch('lhie_candidate.payments.ingress.RateLimits', return_value=limiter):
            response = client.post('/api/webhook/klikqris/sandbox', json.dumps(body), content_type='application/json')
        return response

    def pakasir_seed(name, *, method='QRIS'):
        class HistoricalPreparation:
            provider, method = 'pakasir', 'QRIS'
            def preflight(self, setting):
                return PaymentPreflight(self.provider, self.method, setting)
            def prepare(self, pricing, setting, preflight, now):
                return PaymentPlan(self.provider, self.method, pricing.idr_total, at + timedelta(minutes=15))
        fixture, result = begin(name, HistoricalPreparation())
        admin.execute("UPDATE public.payments SET method=%s,provider_payment_method='qris' WHERE id=%s", (method, result.payment_id))
        row = payment(result.payment_id)
        pt.rows[row['merchant_ref']] = {'project': 'retained-synthetic', 'order_id': row['merchant_ref'],
            'amount': row['amount'], 'status': 'pending', 'payment_method': 'qris'}
        return fixture, result, row['merchant_ref']

    try:
        verify_dependencies()
        actor = admin.execute("""INSERT INTO public.users(name,email,password,role,created_at,updated_at)
            VALUES ('Synthetic P13','p13@example.invalid','not-a-login','owner',now(),now()) RETURNING id""").fetchone()[0]
        version = settings.current().version
        version = settings.change('manual', expected_version=version, actor_user_id=actor)
        admin.execute("UPDATE public.manual_payment_settings SET qris_payment_ttl_minutes=13,admin_telegram_chat_id='1'")
        manual_fixture, manual = begin('duplicate')
        before = payment(manual.payment_id)
        manual_result = ManualQrisLifecycle(presentation).classify(manual.payment_id)
        assert manual_result.state == 'created'
        manual_view = _lifecycle_view(manual_result, order_id=manual.order_id, payment_id=manual.payment_id,
            invoice_number=manual.invoice_number, amount=manual.amount, provider='manual', presentation=presentation)
        customer = db.one('SELECT id,telegram_id,first_name,locale FROM public.customers WHERE id=%s', (manual_fixture['customer_id'],))
        class NavigationTransport:
            def execute(self, method, payload):
                assert not connections['default'].in_atomic_block
                if method == 'sendPhoto':
                    assert zxingcpp.read_barcode(Image.open(BytesIO(payload['photo']))).text == 'Synthetic P13 non-payment asset'
                return TelegramResult(900, 'success')
        surface = CatalogPresenter(CatalogRepository())._checkout_surface(customer, manual_view)
        assert surface.options['photo_bytes'] == qr and any(
            button.get('callback_data') == 'manual:paid:' + manual.order_id
            for row in surface.keyboard['inline_keyboard'] for button in row)
        roots = NavigationRoots(NavigationTransport())
        assert roots.establish(customer, str(customer['telegram_id']), surface) == 'established'
        checkout_navigation = NavigationCheckout(lifecycle=rails)
        prompt = checkout_navigation.manual_prompt(customer, roots.current_for_chat(customer, str(customer['telegram_id'])),
                                                  order_id=manual.order_id)
        assert prompt.state == 'manual_proof'
        assert '/payment' in CatalogPresenter(CatalogRepository())._checkout_surface(customer, prompt).caption
        manual_rail = rails.lifecycles['manual']
        reject(lambda: manual_rail.submit_proof(customer_id=fixtures['foreign']['customer_id'],
            invoice=manual.invoice_number, photo_file_id='synthetic-proof'), 'foreign_payment')
        from lhie_candidate.navigation.ingress import UpdateInbox
        from lhie_candidate.navigation.processor import NavigationProcessor
        update = UpdateInbox().receive({'update_id': 13001, 'message': {
            'message_id': 13001, 'from': {'id': customer['telegram_id'], 'first_name': customer['first_name']},
            'chat': {'id': customer['telegram_id'], 'type': 'private'},
            'caption': '/payment ' + manual.invoice_number,
            'photo': [{'file_id': 'synthetic-proof', 'width': 100, 'height': 100}]}})
        processor = NavigationProcessor(CatalogRepository(), CatalogPresenter(CatalogRepository()),
                                        roots, NavigationTransport())
        assert processor.process(update.update_id) == 'acknowledged'
        assert db.one('SELECT payment_proof_file_id FROM public.payments WHERE id=%s', (manual.payment_id,))['payment_proof_file_id'] == 'synthetic-proof'
        assert manual_rail.submit_proof(customer_id=manual_fixture['customer_id'], invoice=manual.invoice_number, photo_file_id='other-proof') == 'duplicate'
        assert economic(manual) == ('pending_payment', 'pending', 'pending', 0, 0, 0)
        assert db.one("SELECT count(*) AS n FROM public.telegram_outbound_effects WHERE source_id=%s AND effect_type='admin.manual_payment_proof'", (manual.payment_id,))['n'] == 1
        held = ManualQrisLifecycle(presentation, clock=lambda: datetime(2099, 1, 1)).orders.apply_pending_expiry(manual.order_id)
        assert not held.newly_applied and held.order['order_status'] == 'pending_payment'
        OrderLifecycle(encrypter=cipher).apply_manual_completion(manual.order_id, actor_user_id=actor)
        assert economic(manual) == ('completed', 'paid', 'paid', 1, 1, 0)
        version = settings.change('klikqris', expected_version=version, actor_user_id=actor)
        assert {key: payment(manual.payment_id)[key] for key in before if key != 'status'} == {key: value for key, value in before.items() if key != 'status'}
        reject(lambda: settings.change('pakasir', expected_version=version, actor_user_id=actor), 'provider_retired')
        reject(lambda: settings.change('manual', expected_version=version - 1, actor_user_id=actor), 'provider_switch_conflict')
        kfixture, kres = begin('stale')
        created = klik.create(kres.payment_id)
        assert created.state == 'created', created.state
        immutable = payment(kres.payment_id)
        assert klik_callback(kres, valid=False).status_code == 403
        assert klik_callback(kres).status_code == 200
        assert klik_callback(kres).status_code == 200
        hint = db.one("SELECT id,received_count,raw_payload FROM public.payment_events WHERE provider='klikqris' AND event_type='callback' AND merchant_ref=%s", (immutable['merchant_ref'],))
        assert hint['received_count'] == 2 and 'private' not in hint['raw_payload'] and 'signature' not in hint['raw_payload']
        assert worker.handle(encode('payment.event', hint['id'])) == 'created'
        assert economic(kres) == ('pending_payment','pending','pending',0,0,0)
        kt.rows[immutable['merchant_ref']]['status'] = 'SUCCESS'
        assert worker.handle(encode('payment.event', hint['id'])) == 'paid'
        assert worker.handle(encode('payment.event', hint['id'])) == 'paid'
        assert economic(kres) == ('completed','paid','paid',1,1,0)
        assert payment(kres.payment_id) == {**immutable, 'status': 'paid'}
        # Real receipt authorization/rendering; synthetic transport never contacts Telegram.
        service = receipt_service()
        service.source.load(kres.order_id, str(kfixture.get('telegram_id') or db.one(
            'SELECT telegram_id FROM public.customers WHERE id=%s', (kfixture['customer_id'],))['telegram_id']), receipt=True)
        class ReceiptTransport:
            def execute(self, method, payload):
                received.append((method, payload))
                return ReceiptResult(1000 + len(received), 'success')
        service.transport = ReceiptTransport()
        effect = service.materialize(kres.order_id)
        assert effect is not None, 'Receipt was not materialized'
        while effect is not None:
            delivered = service.deliver(str(effect['id']))
            assert delivered in ('sent', 'text_fallback'), delivered
            effect = service.db.one("SELECT * FROM public.telegram_outbound_effects WHERE source_id=%s AND state='pending' AND effect_type='receipt.credentials' ORDER BY sequence LIMIT 1", (kres.order_id,))
        assert any(b'Synthetic P07 stale credential' in data.get('document', b'')
                   or 'Synthetic P07 stale credential' in data.get('text', '')
                   or 'Synthetic P07 stale credential' in data.get('caption', '') for _, data in received)
        assert db.one('SELECT telegram_receipt_sent_at FROM public.orders WHERE id=%s', (kres.order_id,))['telegram_receipt_sent_at'] is not None
        tick[0] += timedelta(seconds=25)
        _, uncertain = begin('maintenance')
        kt.uncertain = True
        assert klik.create(uncertain.payment_id).state == 'outcome_unknown'
        count = len([method for method, _ in kt.calls if method == 'POST'])
        assert klik.create(uncertain.payment_id).state in ('status_deferred', 'outcome_unknown')
        tick[0] += timedelta(seconds=25)
        assert klik.recover(uncertain.payment_id).state == 'created'
        assert len([method for method, _ in kt.calls if method == 'POST']) == count
        snapshot = payment(uncertain.payment_id)
        kt.failure = 404
        tick[0] += timedelta(seconds=25)
        assert klik.refresh(uncertain.payment_id).state == 'recovery_unconfirmed'
        assert payment(uncertain.payment_id) == snapshot
        kt.failure = None
        klik.orders.apply_pending_cancellation(uncertain.order_id, customer_id=fixtures['maintenance']['customer_id'])
        assert db.one('SELECT state FROM public.provider_cancellation_attempts WHERE payment_id=%s', (uncertain.payment_id,))['state'] == 'unsupported'
        tick[0] += timedelta(minutes=6)
        kt.rows[snapshot['merchant_ref']]['status'] = 'SUCCESS'
        assert klik.refresh(uncertain.payment_id).state == 'review'
        assert economic(uncertain) == ('cancelled','paid','paid',0,1,1)
        # A cancellation during provider I/O must retain the returned invoice/signature.
        inflight_fixture, inflight = begin('retry_success')
        kt.on_create = lambda: klik.orders.apply_pending_cancellation(
            inflight.order_id, customer_id=inflight_fixture['customer_id'])
        assert klik.create(inflight.payment_id).state == 'failed'
        retained = db.one('SELECT provider_reference,klikqris_transaction_signature FROM public.payments WHERE id=%s', (inflight.payment_id,))
        assert retained['provider_reference'] and retained['klikqris_transaction_signature']
        kt.rows[payment(inflight.payment_id)['merchant_ref']]['status'] = 'SUCCESS'
        assert klik_callback(inflight).status_code == 200
        assert klik.consume_callbacks(inflight.payment_id).state == 'review'
        assert economic(inflight) == ('cancelled','paid','paid',0,1,1)
        _, historical, pref = pakasir_seed('rollback', method='HOSTED')
        assert pakasir.classify(historical.payment_id).state == 'created'
        pt.rows[pref]['status'] = 'completed'
        with patch('lhie_candidate.payments.ingress.payment_services', return_value=rails), \
             patch('lhie_candidate.payments.ingress.RateLimits', return_value=limiter):
            response = client.post('/api/webhook/pakasir', json.dumps({**pt.rows[pref], 'api_key': 'drop-me'}), content_type='application/json')
        assert response.status_code == 200
        event = db.one("SELECT id FROM public.payment_events WHERE provider='pakasir' AND event_type='callback' AND merchant_ref=%s", (pref,))
        assert worker.handle(encode('payment.event', event['id'])) == 'paid'
        assert economic(historical) == ('completed','paid','paid',1,1,0)
        _, cancelled, cref = pakasir_seed('release')
        pakasir.orders.apply_pending_cancellation(cancelled.order_id, customer_id=fixtures['release']['customer_id'])
        attempt = db.one('SELECT id FROM public.provider_cancellation_attempts WHERE payment_id=%s', (cancelled.payment_id,))['id']
        canceller = PakasirCancellationAttempts(pakasir)
        pt.failure = True
        assert canceller.process(attempt) == 'retryable_failure'
        assert economic(cancelled) == ('cancelled','failed','failed',0,0,0)
        pt.failure = False
        tick[0] += timedelta(minutes=3)
        pt.paid_on_cancel = True
        assert canceller.process(attempt) == 'already_paid'
        calls = len(pt.calls)
        assert canceller.process(attempt) == 'already_paid' and len(pt.calls) == calls
        assert economic(cancelled) == ('cancelled','paid','paid',0,1,1)
        # Sakurupiah unknown create recovers the same invoice with no persisted total.
        version = settings.change('sakurupiah', expected_version=version, actor_user_id=actor)
        admin.execute("UPDATE public.manual_payment_settings SET sakurupiah_channel='QRIS'")
        sf, sr = begin('pause')
        sandbox.ambiguous = True
        args = dict(phone='08000000000', callback_url='https://synthetic.invalid/callback', return_url='https://synthetic.invalid/return')
        assert sakura.create(sr.payment_id, **args).state == 'outcome_unknown'
        assert payment(sr.payment_id)['provider_total_payment'] is None
        assert sakura.recover(sr.payment_id).state == 'created'
        sref = payment(sr.payment_id)['merchant_ref']
        sandbox.rows[sref]['status'] = 'berhasil'
        assert sakura.refresh(sr.payment_id).state == 'paid'
        assert economic(sr) == ('completed','paid','paid',1,1,0)
        assert sum(path.endswith('/create.php') and fields['merchant_ref'] == sref for path, fields in sandbox.calls) == 1
        # Owner channel changes version only future checkout; old invoice stays QRIS.
        settings.sakurupiah = SimpleNamespace(list_channels=lambda: [
            SimpleNamespace(code='QRIS', active=True), SimpleNamespace(code='QRISC', active=True)])
        version = settings.change_channel('QRISC', expected_version=version, actor_user_id=actor)
        assert db.one('SELECT sakurupiah_channel FROM public.payments WHERE id=%s', (sr.payment_id,))['sakurupiah_channel'] == 'QRIS'
        try:
            settings.change_channel('QRIS', expected_version=version-1, actor_user_id=actor)
        except CheckoutError as error:
            assert error.code == 'provider_switch_conflict'
        else:
            raise AssertionError('Stale channel switch accepted')
        # Lost wakeups still reconcile, with a durable cadence marker.
        _, pending, _ = pakasir_seed('preflight')
        pt.failure = True
        worker.cancellations = canceller
        summary = worker.sweep(20)
        assert summary['failures'] == 0, summary
        assert db.one("SELECT id FROM public.payment_events WHERE merchant_ref=%s AND provider_status='candidate_poll'", (payment(pending.payment_id)['merchant_ref'],)) is not None
        count = len(pt.calls)
        assert worker.sweep(20)['failures'] == 0
        assert len(pt.calls) == count
        assert economic(pending) == ('pending_payment','pending','pending',0,0,0)
        pt.failure = False
        # QR template and actual checkout surface/root serialization without a live bot.
        admin.execute("UPDATE public.payment_qr_template_settings SET enabled=true,image_path='payment-qr-templates/local.png',qr_x=100,qr_y=100,qr_width=600,qr_height=600,version=1")
        surface_result = klik.classify(uncertain.payment_id)
        assert surface_result.state == 'review' and surface_result.presentation is None
        native = presentation.payload('Synthetic P13 decorated QR')
        assert zxingcpp.read_barcode(Image.open(BytesIO(native))).text == 'Synthetic P13 decorated QR'
        (Path('/evidence') / 'qr-template.png').write_bytes(native)
        # Actual checkout bridge selects KlikQRIS and replays it after a switch.
        from lhie_candidate.navigation.types import Surface
        version = settings.change('klikqris', expected_version=version, actor_user_id=actor)
        bridge_fixture = fixtures['empty_balance']
        bridge_customer = db.one('SELECT id,telegram_id,first_name,locale FROM public.customers WHERE id=%s', (bridge_fixture['customer_id'],))
        assert roots.establish(bridge_customer, str(bridge_customer['telegram_id']),
            Surface('Synthetic P13 catalog', {'inline_keyboard': []}, 'catalog')) == 'established'
        bridge_root = roots.current_for_chat(bridge_customer, str(bridge_customer['telegram_id']))
        bridge = NavigationCheckout(lifecycle=rails, key=application_key())
        arguments = dict(product_id=bridge_fixture['product_id'], quantity=1, token='p13-local-bridge', rail='qris')
        invoice = bridge.checkout(bridge_customer, bridge_root, **arguments)
        assert invoice.state == 'pending' and invoice.provider == 'klikqris' and invoice.photo_bytes
        assert zxingcpp.read_barcode(Image.open(BytesIO(invoice.photo_bytes))).text == 'Synthetic P13 non-payment asset'
        frozen = payment(invoice.payment_id)
        version = settings.change('manual', expected_version=version, actor_user_id=actor)
        replay = bridge.checkout(bridge_customer, bridge_root, **arguments)
        assert replay.payment_id == invoice.payment_id and replay.provider == 'klikqris'
        assert payment(invoice.payment_id) == frozen
        assert roots.render(bridge_customer, bridge_root, str(bridge_customer['telegram_id']),
            CatalogPresenter(CatalogRepository())._checkout_surface(bridge_customer, replay)) in ('applied', 'replaced')
        cancelled_view = bridge.cancel(bridge_customer, roots.current_for_chat(bridge_customer, str(bridge_customer['telegram_id'])),
                                       order_id=invoice.order_id)
        assert cancelled_view.state == 'cancelled' and economic(invoice) == ('cancelled','failed','failed',0,0,0)
        assert roots.render(bridge_customer, roots.current_for_chat(bridge_customer, str(bridge_customer['telegram_id'])),
            str(bridge_customer['telegram_id']),
            CatalogPresenter(CatalogRepository())._checkout_surface(bridge_customer, cancelled_view)) in ('applied', 'replaced')
        view = _lifecycle_view(manual_rail.classify(manual.payment_id), order_id=manual.order_id,
            payment_id=manual.payment_id, invoice_number=manual.invoice_number, amount=manual.amount, provider='manual', presentation=presentation)
        assert view.state == 'paid' and view.photo_bytes is None
        # Grants still deny immutable financial snapshots.
        from django.db import DatabaseError
        try:
            with transaction.atomic():
                db.execute('UPDATE public.payments SET amount=amount+1 WHERE id=%s', (kres.payment_id,))
        except DatabaseError:
            pass
        else:
            raise AssertionError('Runtime changed an immutable amount')
        verify_dependencies()
        return {'status': 'passed', 'dataset': 'synthetic-p13-qris-rails',
            'scope': ['Manual checkout/proof ownership/replay/reservation/approval and durable admin effect',
                      'Versioned provider switch, immutable previous Payment and rejected retired provider',
                      'KlikQRIS HTTP callback authentication/dedupe, authoritative settlement/receipt, uncertainty/recovery/404/late money',
                      'Historical HOSTED Pakasir callback settlement and cancellation failure/paid race/replay',
                      'Sakurupiah unknown-create same-invoice recovery and authoritative paid transition',
                      'Template QR decodes after composition and runtime immutable amount denial'],
            'limits': ['Synthetic transports and local-only disposable PostgreSQL/Redis; no external qualification',
                       'Manual admin proof notification consumer remains P25; durable effect is pending, never fake sent',
                       'P07/P12 own shared money/stock race proof; no production data or real-money assertions']}
    finally:
        admin.close()
        sandbox.close()
        directory.cleanup()
        connections.close_all()
