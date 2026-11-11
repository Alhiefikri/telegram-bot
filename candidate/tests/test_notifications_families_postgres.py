"""Additional family flows called only by the existing fake-only P25 smoke."""
import json
from types import SimpleNamespace
from uuid import uuid4


def exercise_families(fixtures, admin, paid_order_id):
    from django.db import transaction
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.notifications.families import BALANCE_TYPES, BULK_TYPES, LIMITS
    from lhie_candidate.notifications.bulk import BulkNotices, PRICE_CHANNEL
    from lhie_candidate.notifications.fake_proof import FakeProofPublication
    from lhie_candidate.notifications.guest_mail import GuestMail, record_guest_event
    from lhie_candidate.notifications.policy import BROADCAST, PRICE, PROOF, STOCK, TestRecipients, NotEligible
    from lhie_candidate.notifications.proof_publication import SalesProofPublication
    from lhie_candidate.notifications.publishing import BroadcastPublishing
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from lhie_candidate.receipt.transport import ReceiptResult
    from lhie_candidate.receipt.protocol import EFFECT_TYPE
    from lhie_candidate.runtime import application_key

    db = Database()
    recipients = TestRecipients(frozenset({'1'}))
    notification_types = tuple(kind for kind in LIMITS if kind not in BULK_TYPES | BALANCE_TYPES)
    bulk_types = tuple(kind for kind in LIMITS if kind in BULK_TYPES)
    service = Notifications(NotificationTransport(), recipients=recipients,
                            bot_username='LhieStoreTestBot', bot_scope='synthetic',
                            effect_types=notification_types, queue='notifications')
    bulk_service = Notifications(NotificationTransport(), recipients=recipients,
                                 bot_username='LhieStoreTestBot', bot_scope='synthetic',
                                 effect_types=bulk_types, queue='bulk',
                                 rate=SimpleNamespace(acquire=lambda: True))
    admin.execute("INSERT INTO public.store_channel_settings(singleton_key,telegram_chat_id,sales_proof_enabled,last_test_success,created_at,updated_at) VALUES ('store_channel','1',true,true,now(),now()) ON CONFLICT(singleton_key) DO UPDATE SET telegram_chat_id='1',sales_proof_enabled=true,last_test_success=true")
    from tests.test_notifications_php_parity import exercise_php_notification_parity
    php_parity = exercise_php_notification_parity(fixtures, admin)
    owner = int(fixtures['auth']['admin']['id'])
    product = fixtures['foreign']['product_id']
    publishing = BroadcastPublishing(bulk_service)
    authored = '<b>Admin-authored synthetic notice</b>\n日本語 &amp; 50%'
    draft = publishing.draft(authored)
    try:
        publishing.publish(draft, 'foreign-confirmation')
    except ValueError:
        pass
    else:
        raise AssertionError('Foreign confirmation accepted')
    broadcast_id = publishing.publish(draft, draft['key'])
    assert publishing.publish(draft, draft['key']) == broadcast_id
    bulk = BulkNotices(bulk_service)
    bulk.materialize(100)
    effect = db.one("SELECT e.* FROM public.telegram_outbound_effects e JOIN public.telegram_broadcast_recipients r ON r.id::text=e.source_id WHERE e.effect_type=%s AND r.broadcast_id=%s", (BROADCAST,broadcast_id))
    assert authored in bulk_service.payload(effect)['text']
    assert bulk_service.deliver(effect['id']) == 'sent'
    assert db.one('SELECT sent_at FROM public.telegram_broadcast_recipients WHERE id=%s', (effect['source_id'],))['sent_at']
    assert bulk_service.deliver(effect['id']) == 'not_ready'
    photo_reference = 'p25-fake-relay-photo-reference'
    photo_draft = publishing.draft('', photo_file_id=photo_reference)
    photo_broadcast_id = publishing.publish(photo_draft, photo_draft['key'])
    bulk.materialize(100)
    photo_effect = db.one(
        "SELECT e.* FROM public.telegram_outbound_effects e "
        "JOIN public.telegram_broadcast_recipients r ON r.id::text=e.source_id "
        "WHERE e.effect_type=%s AND r.broadcast_id=%s",
        (BROADCAST, photo_broadcast_id),
    )
    photo_payload = bulk_service.payload(photo_effect)
    assert photo_payload['photo'] == photo_reference
    assert 'caption' not in photo_payload and 'parse_mode' not in photo_payload
    assert 'reply_markup' not in photo_payload
    assert bulk_service.deliver(photo_effect['id']) == 'sent'
    assert db.one(
        'SELECT sent_at FROM public.telegram_broadcast_recipients WHERE id=%s',
        (photo_effect['source_id'],),
    )['sent_at']

    purchasable_id = admin.execute(
        '''INSERT INTO public.products
           (name,description,price_amount,currency,icon,custom_emoji_id,stock,reserved_stock,
            is_active,created_at,updated_at)
           VALUES (%s,'Synthetic admin broadcast product',1000,'IDR','📦','700707',1,0,true,now(),now())
           RETURNING id''',
        ('P25 broadcast button ' + str(uuid4()),),
    ).fetchone()[0]
    purchasable = publishing.eligible_product(purchasable_id)
    assert purchasable
    purchase_draft = publishing.draft('Synthetic button notice', product_id=purchasable['id'])
    assert set(purchase_draft['reply_markup']) == {'id', 'en', 'ms'}
    localized_buttons = {
        locale: value['inline_keyboard'][0][0]
        for locale, value in purchase_draft['reply_markup'].items()
    }
    assert {button['callback_data'] for button in localized_buttons.values()} == {
        f"product:{purchasable['id']}",
    }
    assert all('tg-emoji' not in button['text'] and '🛍️' in button['text']
               for button in localized_buttons.values())
    purchase_broadcast_id = publishing.publish(purchase_draft, purchase_draft['key'])
    assert publishing.publish(purchase_draft, purchase_draft['key']) == purchase_broadcast_id
    stored_markup = admin.execute(
        'SELECT reply_markup FROM public.telegram_broadcasts WHERE id=%s',
        (purchase_broadcast_id,),
    ).fetchone()[0]
    if isinstance(stored_markup, str):
        stored_markup = json.loads(stored_markup)
    assert stored_markup == purchase_draft['reply_markup']

    stale_draft = publishing.draft('Synthetic stale button notice', product_id=purchasable['id'])
    admin.execute('UPDATE public.products SET is_active=false WHERE id=%s', (purchasable['id'],))
    try:
        try:
            publishing.publish(stale_draft, stale_draft['key'])
        except ValueError:
            pass
        else:
            raise AssertionError('Unavailable product purchase button was published')
    finally:
        admin.execute('UPDATE public.products SET is_active=true WHERE id=%s', (purchasable['id'],))
    assert admin.execute(
        'SELECT count(*) FROM public.telegram_broadcasts WHERE broadcast_key=%s',
        (stale_draft['key'],),
    ).fetchone()[0] == 0
    changed_draft = publishing.draft('Synthetic changed button notice', product_id=purchasable['id'])
    updated_name = 'Changed after preview ' + purchasable['name']
    admin.execute('UPDATE public.products SET name=%s,updated_at=now() WHERE id=%s',
                  (updated_name, purchasable['id']))
    try:
        try:
            publishing.publish(changed_draft, changed_draft['key'])
        except ValueError as error:
            assert 'berubah' in str(error)
        else:
            raise AssertionError('Broadcast published a product label changed after preview')
    finally:
        admin.execute('UPDATE public.products SET name=%s,updated_at=now() WHERE id=%s',
                      (purchasable['name'], purchasable['id']))
    assert admin.execute(
        'SELECT count(*) FROM public.telegram_broadcasts WHERE broadcast_key=%s',
        (changed_draft['key'],),
    ).fetchone()[0] == 0
    price_id = admin.execute("INSERT INTO public.product_price_change_events(event_key,product_id,product_name,product_icon,old_price_amount,new_price_amount,old_usdt_price_amount,new_usdt_price_amount,created_at,updated_at) VALUES (%s,%s,'Synthetic price plan','📦',2000,1000,2000001,1000001,now(),now()) RETURNING id", (str(uuid4()),product)).fetchone()[0]
    assert bulk.materialize_price(price_id) >= 1
    price_effects = db.rows("SELECT * FROM public.telegram_outbound_effects WHERE effect_type=ANY(%s) AND (source_id=%s OR source_id IN (SELECT id::text FROM public.product_price_change_notification_recipients WHERE event_id=%s))", ([PRICE,PRICE_CHANNEL],str(price_id),price_id))
    assert {e['effect_type'] for e in price_effects} == {PRICE, PRICE_CHANNEL}
    for row in price_effects:
        assert '2.000001 USDT' in bulk_service.payload(row)['text']
        assert bulk_service.deliver(row['id']) == 'sent'
    assert db.one('SELECT store_channel_sent_at FROM public.product_price_change_events WHERE id=%s', (price_id,))['store_channel_sent_at']
    movement = admin.execute("INSERT INTO public.inventory_movements(product_id,type,quantity,available_before,available_after,stock_before,notes,created_at) VALUES (%s,'adjustment',2,0,2,0,'Synthetic P25 stock notification',now()) RETURNING id", (product,)).fetchone()[0]
    assert bulk.materialize_stock(movement) >= 1
    stock_effects = db.rows("SELECT e.* FROM public.telegram_outbound_effects e JOIN public.stock_notification_recipients r ON r.id::text=e.source_id JOIN public.stock_notification_events n ON n.id=r.stock_notification_event_id WHERE e.effect_type=%s AND n.inventory_movement_id=%s", (STOCK,movement))
    assert stock_effects
    for row in stock_effects:
        assert bulk_service.deliver(row['id']) == 'sent'
    admin.execute('UPDATE public.customers SET first_name=%s,username=%s WHERE id=%s',
                  ('Private Synthetic Buyer', 'private_synthetic_identity', fixtures['python']['customer_id']))
    proof = SalesProofPublication(service, bot_username='LhieStoreTestBot')
    effect = proof.materialize_order(paid_order_id, 'LhieStoreTestBot')
    before = admin.execute('SELECT count(*) FROM public.orders').fetchone()[0]
    payload = proof.payload(effect)
    full_name = admin.execute('SELECT first_name FROM public.customers WHERE id=%s', (fixtures['python']['customer_id'],)).fetchone()[0]
    assert full_name not in payload['caption'] and '***' in payload['caption']
    assert type(payload['photo']) is bytes
    assert service.deliver(effect['id']) == 'sent'
    assert db.one('SELECT status FROM public.sales_proof_posts WHERE order_id=%s', (paid_order_id,))['status'] == 'sent'
    assert proof.materialize_order(paid_order_id, 'LhieStoreTestBot')['id'] == effect['id']
    fake = FakeProofPublication(service, bot_username='LhieStoreTestBot')
    request = str(uuid4())
    fake_effect = fake.manual(product, owner, request)
    assert fake.manual(product, owner, request)['id'] == fake_effect['id']
    assert service.deliver(fake_effect['id']) == 'sent'
    assert admin.execute('SELECT count(*) FROM public.orders').fetchone()[0] == before
    assert admin.execute('SELECT count(*) FROM public.sales_proof_posts WHERE order_id=%s', (paid_order_id,)).fetchone()[0] == 1
    # An explicit retry keeps its source presentation snapshot and the original unknown.
    with transaction.atomic():
        uncertain = bulk_service.record(key='p25-review:' + str(uuid4()), kind=BROADCAST,
            model='TelegramBroadcastRecipient', source=db.one('SELECT id FROM public.telegram_broadcast_recipients WHERE broadcast_id=%s',(broadcast_id,))['id'],
            chat='1',target='customer',locale='id',template='php-bulk-snapshot-v1')
    original = bulk_service.transport
    bulk_service.transport = SimpleNamespace(execute=lambda *a: ReceiptResult(None,'ambiguous'))
    assert bulk_service.deliver(uncertain['id']) == 'unknown'
    resend = bulk_service.protocol.resend_review(uncertain['id'], duplicate_risk_acknowledged=True)
    bulk_service.transport = original
    assert resend['supersedes_effect_id'] == uncertain['id']
    assert bulk_service.deliver(resend['id']) == 'sent'
    assert db.one('SELECT state FROM public.telegram_outbound_effects WHERE id=%s',(uncertain['id'],))['state'] == 'unknown'
    key = application_key()
    cipher = LaravelEncrypter(key)
    with transaction.atomic():
        record_guest_event(db, paid_order_id, 'payment_confirmed')
    guest = GuestMail(cipher, signing_key=key, invoice_base_url='http://127.0.0.1:18801', recipients=recipients)
    event = db.one("SELECT id FROM public.order_notification_events WHERE order_id=%s AND event_type='payment_confirmed'", (paid_order_id,))
    assert guest.deliver(event['id']) == 'sent'
    assert guest.deliver(event['id']) == 'not_ready'
    from lhie_candidate.admin.reviews import DeliveryReviewOperations
    from lhie_candidate.receipt.runtime import receipt_service
    receipts = receipt_service()
    first = receipts.materialize(paid_order_id)
    claimed = receipts.protocol.claim(first['id'])
    receipts.protocol.finalize_unknown(first['id'], claimed['claim_token'])
    from django.db import DatabaseError
    resend_count = db.one('''SELECT count(*) AS n FROM public.telegram_outbound_effects
        WHERE effect_type=%s AND source_type='App\\Models\\Order' AND source_id=%s''',
        (EFFECT_TYPE, paid_order_id))['n']
    trigger = 'p25_resend_audit_fault_' + uuid4().hex[:12]
    admin.execute(f"""CREATE FUNCTION public.{trigger}() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            IF NEW.action='telegram_outbound_effect.resent' AND NEW.subject_id='{first['id']}' THEN
                RAISE EXCEPTION 'synthetic P25 resend audit fault';
            END IF;
            RETURN NEW;
        END $$""")
    try:
        admin.execute(f"""CREATE TRIGGER {trigger} BEFORE INSERT ON public.audit_logs
            FOR EACH ROW EXECUTE FUNCTION public.{trigger}()""")
        try:
            DeliveryReviewOperations().resend(
                first['id'], actor_id=owner, confirm_duplicate_risk=True)
        except DatabaseError as error:
            assert 'synthetic P25 resend audit fault' in str(error)
        else:
            raise AssertionError('Receipt resend committed without its audit record')
    finally:
        admin.execute(f'DROP TRIGGER IF EXISTS {trigger} ON public.audit_logs')
        admin.execute(f'DROP FUNCTION IF EXISTS public.{trigger}()')
    assert db.one('''SELECT count(*) AS n FROM public.telegram_outbound_effects
        WHERE effect_type=%s AND source_type='App\\Models\\Order' AND source_id=%s''',
        (EFFECT_TYPE, paid_order_id))['n'] == resend_count
    assert db.one('''SELECT count(*) AS n FROM public.audit_logs
        WHERE action='telegram_outbound_effect.resent' AND subject_id::text=%s''', (str(first['id']),))['n'] == 0
    replacement = DeliveryReviewOperations().resend(
        first['id'], actor_id=owner, confirm_duplicate_risk=True)
    assert replacement['effect_group_key'] != first['effect_group_key']
    while current := receipts.protocol.next_receipt_effect(replacement['effect_group_key']):
        assert receipts.deliver(current['id']) == 'sent'
    assert receipts.protocol.is_receipt_group_complete(replacement['effect_group_key'])
    assert db.one('SELECT state FROM public.telegram_outbound_effects WHERE id=%s', (first['id'],))['state'] == 'unknown'
    return {
        **php_parity,
        'confirmed_broadcast_snapshot': 'passed',
        'stock_price_customer_channel': 'passed',
        'sales_proof_publication_masking': 'passed',
        'retained_fake_proof_no_economic_writes': 'passed',
        'explicit_resend_unknown_retained': 'passed',
        'guest_multipart_sink': 'passed',
        'authorized_receipt_group_resend': 'passed',
    }
