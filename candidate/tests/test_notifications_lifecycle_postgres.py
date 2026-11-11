"""Callable disposable-PostgreSQL lifecycle scenarios for the shared P25 smoke."""
from types import SimpleNamespace
from uuid import uuid4


def exercise_lifecycle_notifications(admin, customer_id, product_id):
    from django.db import transaction
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.notifications.lifecycle import LifecycleNotices, PENDING, PAID, EXPIRED, REVIEW, MANUAL
    from lhie_candidate.notifications.policy import TestRecipients
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.snapshot import record_snapshot, metadata
    from lhie_candidate.receipt.protocol import _now
    from lhie_candidate.receipt.transport import ReceiptResult

    db, sent = Database(), []
    def execute(method, payload):
        sent.append((method, payload))
        return ReceiptResult(1700 + len(sent), 'success')
    service = Notifications(SimpleNamespace(execute=execute), recipients=TestRecipients(frozenset({'1'})),
                            effect_types=(PENDING, PAID, EXPIRED, REVIEW, MANUAL), queue='notifications')
    adapter = LifecycleNotices(service)
    suffix = uuid4().hex[:10]
    admin.execute('UPDATE public.manual_payment_settings SET admin_telegram_chat_id=%s WHERE is_active=true', ('1',))
    sources = {}
    for kind, status, payment_status in ((PENDING, 'pending_payment', 'pending'),
                                         (EXPIRED, 'expired', 'expired'), (PAID, 'completed', 'paid')):
        oid, pid = str(uuid4()), str(uuid4())
        invoice = 'P25-LIFE-' + kind.split('.')[1][:8] + '-' + suffix
        admin.execute('''INSERT INTO public.orders(id,customer_id,invoice_number,order_status,payment_status,
            total_amount,currency,expires_at,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,100,'IDR',now()+interval '1 hour',now(),now())''',
            (oid, customer_id, invoice, status, payment_status))
        admin.execute('''INSERT INTO public.order_items(order_id,product_id,product_name,product_icon,quantity,
            unit_price,subtotal,created_at,updated_at) VALUES (%s,%s,'Synthetic lifecycle plan','📦',1,100,100,now(),now())''', (oid, product_id))
        admin.execute('''INSERT INTO public.payments(id,order_id,provider,merchant_ref,method,amount,currency,status,
            paid_at,created_at,updated_at) VALUES (%s,%s,'manual',%s,'QR_MANUAL',100,'IDR',%s,
            CASE WHEN %s='paid' THEN now()-interval '3 minutes' ELSE NULL END,now(),now())''',
            (pid, oid, invoice, payment_status, payment_status))
        sources[kind] = (oid, pid)
    def api_order(suffix_part, *, sale=None, released=False, review=False):
        oid, pid = str(uuid4()), str(uuid4())
        invoice = 'P25-LIFE-API-' + suffix_part + '-' + suffix
        admin.execute('''INSERT INTO public.orders(id,customer_id,invoice_number,order_status,payment_status,
            total_amount,currency,expires_at,created_at,updated_at)
            VALUES (%s,%s,%s,'completed','paid',100,'IDR',now()+interval '1 hour',now(),now())''',
            (oid, customer_id, invoice))
        item_id = admin.execute('''INSERT INTO public.order_items(order_id,product_id,product_name,product_icon,quantity,
            unit_price,subtotal,created_at,updated_at) VALUES (%s,%s,'Synthetic API plan','📦',1,100,100,now(),now())
            RETURNING id''', (oid, product_id)).fetchone()[0]
        admin.execute('''INSERT INTO public.payments(id,order_id,provider,merchant_ref,method,amount,currency,status,
            paid_at,created_at,updated_at) VALUES (%s,%s,'manual',%s,'QR_MANUAL',100,'IDR','paid',now(),now(),now())''',
            (pid, oid, invoice))
        if sale is not None:
            admin.execute('''INSERT INTO public.inventory_movements(product_id,order_id,type,quantity,notes,created_at)
                VALUES (%s,%s,'sale',%s,'Synthetic API sale',now())''', (product_id, oid, sale))
        if released:
            admin.execute('''INSERT INTO public.inventory_movements(product_id,order_id,type,quantity,notes,created_at)
                VALUES (%s,%s,'release',-1,'Synthetic API release',now())''', (product_id, oid))
        if review:
            admin.execute('''INSERT INTO public.manual_fulfillment_reviews(order_id,payment_id,amount,currency,
                reason,state,created_at,updated_at) VALUES (%s,%s,100,'IDR','late_payment','pending',now(),now())''',
                (oid, pid))
        admin.execute('''INSERT INTO public.product_stock_items(product_id,order_item_id,secret,created_at,updated_at)
            VALUES (%s,%s,'synthetic-api-credential',now(),now())''', (product_id, item_id))
        order, _, items = adapter._load(oid)
        order['api_order_claim_id'] = 1
        return order, items

    for order, items in (api_order('missing-sale'), api_order('mismatching-sale', sale=-2),
                         api_order('released-review', sale=-1, released=True, review=True)):
        lines = adapter._fulfillment_lines(order, items, [])
        assert lines[0] == 'Pengiriman pelanggan: Belum selesai; periksa dashboard'
    order, items = api_order('eligible', sale=-1)
    assert adapter._fulfillment_lines(order, items, [])[0] == (
        'Pengiriman pelanggan: Fulfillment API selesai · hasil tersedia melalui API')

    adapter.materialize(100)
    effects = {}
    for kind, (oid, _) in sources.items():
        effect = db.one('''SELECT * FROM public.telegram_outbound_effects
            WHERE effect_type=%s AND source_id=%s ORDER BY sequence DESC LIMIT 1''', (kind, oid))
        assert effect and metadata(effect).get('notification_payload')
        effects[kind] = effect
    # Retrying discovery retains the same content even after the live name changes.
    snapshot = metadata(effects[PENDING])['notification_payload']
    admin.execute('UPDATE public.order_items SET product_name=%s WHERE order_id=%s', ('Changed after snapshot', sources[PENDING][0]))
    adapter.materialize(100)
    assert adapter.payload(effects[PENDING]) == snapshot
    # Crash after ledger ACK, before projection. Repair transports nothing and
    # preserves a pending cleanup marker already committed by another worker.
    effect = effects[PENDING]
    claimed = service.protocol.claim(effect['id'])
    finalized = service.protocol.finalize_sent(effect['id'], claimed['claim_token'], 1801)
    admin.execute('UPDATE public.orders SET admin_pending_order_notification_deleted_at=%s WHERE id=%s', (_now(), effect['source_id']))
    assert adapter.project(finalized) == 1
    assert adapter.project(finalized) == 0
    assert not sent
    retained = db.one('SELECT admin_pending_order_notification_deleted_at FROM public.orders WHERE id=%s', (effect['source_id'],))
    assert retained['admin_pending_order_notification_deleted_at'] is not None
    # A newer explicitly versioned send alone can repair the source projection.
    with transaction.atomic():
        newer = record_snapshot(service, key=effect['effect_key'] + ':synthetic-version', kind=PENDING, model='Order',
            source=effect['source_id'], chat='1', template=effect['template_version'], payload=snapshot,
            sequence=2, supersedes=effect['id'])
    claimed = service.protocol.claim(newer['id'])
    latest = service.protocol.finalize_sent(newer['id'], claimed['claim_token'], 1802)
    assert adapter.project(latest) == 1
    assert adapter.project(finalized) == 0
    assert db.one('SELECT admin_pending_order_notification_message_id AS mid FROM public.orders WHERE id=%s',
                  (effect['source_id'],))['mid'] == 1802
    # Late evidence is retained, but its automatic approval actions disappear.
    oid, pid = sources[EXPIRED]
    admin.execute('''UPDATE public.payments SET method='BINANCE_PAY_ID',payment_proof_file_id='synthetic-file',
        payment_proof_submitted_at=now(),payment_proof_admin_chat_id='1' WHERE id=%s''', (pid,))
    adapter.materialize(100)
    proof = db.one('SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s AND source_id=%s', (MANUAL, pid))
    payload = adapter.payload(proof)
    assert 'Late Binance evidence' in payload['caption'] and 'reply_markup' not in payload
    service.transport = SimpleNamespace(execute=lambda *args: ReceiptResult(None, 'ambiguous'))
    assert service.deliver(proof['id']) == 'unknown'
    assert service.deliver(proof['id']) == 'not_ready'
    assert db.one('SELECT payment_proof_forwarded_at FROM public.payments WHERE id=%s', (pid,))['payment_proof_forwarded_at'] is None
    # Paid notices surface uncertainty; an ACK never says a receipt was sent.
    service.transport = SimpleNamespace(execute=execute)
    assert service.deliver(effects[PAID]['id']) == 'sent'
    assert 'Order perlu ditinjau' in sent[-1][1]['text']
    assert 'jangan kirim ulang' in sent[-1][1]['text']
    assert service.deliver(effects[EXPIRED]['id']) == 'sent'
    return {'lifecycle_durable_discovery': 'passed', 'lifecycle_immutable_snapshot': 'passed',
            'lifecycle_latest_sent_projection': 'passed', 'lifecycle_pending_cleanup_preserved': 'passed',
            'manual_late_proof_unknown_no_resend': 'passed', 'paid_outcome_truth': 'passed'}
