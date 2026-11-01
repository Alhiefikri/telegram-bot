"""Disposable P07 receipt proof, invoked by smoke_checkout.py --receipt only."""
from concurrent.futures import ThreadPoolExecutor
import json
from uuid import uuid4
from threading import Barrier



def exercise_receipt_protocol(fixtures):
    from django.db import connection, connections, transaction
    from lhie_candidate.checkout.inventory import InventoryLedger
    from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
    from lhie_candidate.receipt.jobs import decode_job, take, wake_after_commit
    from lhie_candidate.receipt.protocol import ReceiptProtocol
    from lhie_candidate.receipt.runtime import receipt_service
    from lhie_candidate.receipt.source import ReceiptUnavailable
    from lhie_candidate.receipt.transport import ReceiptResult
    from tests.test_checkout_postgres import admin_connection

    admin = admin_connection()
    checkout = CheckoutService()
    service = receipt_service()
    protocol = service.protocol

    def paid(name):
        fixture = fixtures[name]
        request = CheckoutRequest(fixture['customer_id'], fixture['cart_id'], 'receipt-' + name,
            checkout.quote_cart(fixture['customer_id'], fixture['cart_id']))
        result = checkout.checkout(request)
        assert result.payment_status == 'paid'
        assert checkout.checkout(request).order_id == result.order_id
        return fixture, result

    def rows(order):
        return admin.execute('''SELECT id,sequence,state,telegram_method FROM public.telegram_outbound_effects
            WHERE source_id=%s AND effect_type='receipt.credentials' ORDER BY sequence''', (str(order),)).fetchall()

    def refused(order, chat):
        try:
            service.source.load(order, chat)
        except ReceiptUnavailable:
            return
        raise AssertionError('Unauthorized or ineligible receipt was readable')

    try:
        primary, order = paid('python')
        oid = str(order.order_id)
        assert admin.execute('''SELECT count(*) FROM public.product_stock_items s
            JOIN public.order_items i ON i.id=s.order_item_id WHERE i.order_id=%s''', (oid,)).fetchone() == (2,)
        assert admin.execute('''SELECT count(*) FROM public.store_balance_entries
            WHERE source_type='order' AND source_id=%s AND kind='purchase_debit' ''', (oid,)).fetchone() == (1,)
        assert admin.execute('SELECT telegram_id FROM public.customers WHERE id=%s', (primary['customer_id'],)).fetchone() == (1,)
        refused(oid, 2)
        retired_fixture, retired_order = paid('maintenance')
        retired_chat = admin.execute('SELECT telegram_id FROM public.customers WHERE id=%s', (retired_fixture['customer_id'],)).fetchone()[0]
        item_id = admin.execute('SELECT id FROM public.order_items WHERE order_id=%s', (retired_order.order_id,)).fetchone()[0]
        with transaction.atomic():
            InventoryLedger().retire(item_id)
        refused(retired_order.order_id, retired_chat)
        failed = service.materialize(str(retired_order.order_id))
        assert failed is not None and failed['state'] == 'failed'
        review_fixture, review_order = paid('rollback')
        review_chat = admin.execute('SELECT telegram_id FROM public.customers WHERE id=%s', (review_fixture['customer_id'],)).fetchone()[0]
        admin.execute('''INSERT INTO public.manual_fulfillment_reviews(order_id,payment_id,amount,currency,reason,state,created_at,updated_at)
            VALUES (%s,%s,1,'IDR','late_payment','pending',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)''',
            (review_order.order_id, review_order.payment_id))
        refused(review_order.order_id, review_chat)
        assert service.materialize(str(review_order.order_id)) is None
        partial_fixture, partial_order = paid('receipt_partial')
        partial_chat = admin.execute('SELECT telegram_id FROM public.customers WHERE id=%s', (partial_fixture['customer_id'],)).fetchone()[0]
        partial_item = admin.execute('SELECT id FROM public.order_items WHERE order_id=%s ORDER BY id LIMIT 1',
                                     (partial_order.order_id,)).fetchone()[0]
        with transaction.atomic():
            InventoryLedger().retire(partial_item)
        refused(partial_order.order_id, partial_chat)
        assert service.materialize(str(partial_order.order_id))['state'] == 'failed'

        # A rolled-back materialization leaves neither durable rows nor an on-commit wakeup.
        while take() is not None:
            pass
        secret = service.source.load(oid, 1)['groups'][0]['accounts'][0]['secret']
        with transaction.atomic():
            protocol.materialize(oid, 1, 'en', [{'method':'sendPhoto','contains_credentials':False}])
            wake_after_commit('order', oid)
            transaction.set_rollback(True)
        assert not rows(oid) and take() is None

        first = service.materialize(oid)
        assert first is not None
        group = rows(oid)
        assert [row[3] for row in group] == ['sendPhoto', 'sendDocument']
        assert protocol.claim(group[1][0]) is None
        assert group[1][0] not in {row['id'] for row in protocol.ready_effects(100)}
        assert service.deliver(group[0][0]) == 'sent'
        assert not any(row['effect_group_key'] == first['effect_group_key']
                       for row in protocol.completed_receipts_needing_projection(100))
        # Crash after the second send was durably finalized, before projection.
        after_sent = service._after_sent
        service._after_sent = lambda effect: None
        try:
            assert service.deliver(group[1][0]) == 'sent'
        finally:
            service._after_sent = after_sent
        assert admin.execute('SELECT telegram_receipt_sent_at FROM public.orders WHERE id=%s', (oid,)).fetchone() == (None,)
        assert any(row['id'] == group[1][0] for row in protocol.completed_receipts_needing_projection(100))
        class NoSend:
            def execute(self, method, payload):
                raise AssertionError('Recovery attempted a send')
        original_transport = service.transport
        service.transport = NoSend()
        assert service.recover()['projections_repaired'] >= 1
        assert admin.execute('SELECT telegram_receipt_sent_at IS NOT NULL FROM public.orders WHERE id=%s', (oid,)).fetchone() == (True,)
        assert service.materialize(oid) is None
        assert service.deliver(group[1][0]) == 'not_ready'
        service.transport = original_transport

        panel = admin.execute('''SELECT id FROM public.telegram_outbound_effects
            WHERE source_id=%s AND effect_type='receipt.order_actions' ''', (oid,)).fetchone()
        assert panel is not None and service.deliver(panel[0]) == 'sent'
        assert service.request_download(oid, primary['customer_id'], 2, 910001) is None
        download = service.request_download(oid, primary['customer_id'], 1, 910001)
        assert download is not None and service.deliver(download['id']) == 'sent'
        assert service.request_download(oid, primary['customer_id'], 1, 910001)['id'] == download['id']
        assert service.request_download(oid, primary['customer_id'], 1, 910002) is not None
        assert service.request_download(oid, primary['customer_id'], 1, 910003) is not None
        assert service.request_download(oid, primary['customer_id'], 1, 910004) is None

        # Competing database sessions can hold only one live token; expiry fences it.
        other, claimed_order = paid('duplicate')
        effect = service.materialize(str(claimed_order.order_id))
        barrier = Barrier(2)
        def claim():
            try:
                barrier.wait(timeout=10)
                return ReceiptProtocol().claim(effect['id'])
            finally:
                connections.close_all()
        with ThreadPoolExecutor(max_workers=2) as pool:
            claims = list(pool.map(lambda _: claim(), range(2)))
        winners = [item for item in claims if item is not None]
        assert len(winners) == 1
        admin.execute("UPDATE public.telegram_outbound_effects SET lease_until=CURRENT_TIMESTAMP - INTERVAL '1 day' WHERE id=%s", (effect['id'],))
        assert protocol.recover_expired_leases() >= 1
        assert protocol.finalize_sent(effect['id'], winners[0]['claim_token'], 1) is None
        assert protocol.claim(effect['id']) is None

        # Definite rejection permits text fallback; the failed photo is never reclaimed.
        _, fallback_order = paid('release')
        failed_photo = service.materialize(str(fallback_order.order_id))
        class Rejected:
            def execute(self, method, payload):
                return ReceiptResult(None, 'definite', 'telegram_http_400')
        service.transport = Rejected()
        assert service.deliver(failed_photo['id']) == 'failed'
        assert protocol.claim(failed_photo['id']) is None
        replacement = protocol.next_receipt_effect(failed_photo['effect_group_key'] + ':text-fallback')
        assert replacement is not None and replacement['telegram_method'] == 'sendMessage'
        service.transport = original_transport

        # Forward to the admitted fake relay, then discard its acknowledgement.
        lost_fixture, lost_order = paid('receipt_lost')
        lost_chat = admin.execute('SELECT telegram_id FROM public.customers WHERE id=%s',
                                  (lost_fixture['customer_id'],)).fetchone()[0]
        admin.execute('UPDATE public.customers SET telegram_id=700002 WHERE id=%s', (primary['customer_id'],))
        admin.execute('UPDATE public.customers SET telegram_id=1 WHERE id=%s', (lost_fixture['customer_id'],))
        lost = service.materialize(str(lost_order.order_id))
        lost_group = rows(lost_order.order_id)
        assert [row[3] for row in lost_group] == ['sendPhoto', 'sendDocument']
        assert service.deliver(lost_group[0][0]) == 'sent'
        class LostAck:
            def __init__(self, relay):
                self.relay, self.calls = relay, 0
            def execute(self, method, payload):
                self.calls += 1
                assert self.relay.execute(method, payload).outcome == 'success'
                return ReceiptResult(None, 'ambiguous')
        transport = LostAck(original_transport)
        service.transport = transport
        assert service.deliver(lost_group[1][0]) == 'unknown'
        assert transport.calls == 1
        service.recover()
        assert service.deliver(lost_group[1][0]) == 'not_ready' and transport.calls == 1
        assert service.materialize(str(lost_order.order_id)) is None
        assert admin.execute('SELECT state FROM public.telegram_outbound_effects WHERE id=%s', (lost_group[1][0],)).fetchone() == ('unknown',)

        # Existing v1 invoice groups continue after an ambiguous photo without replaying it.
        historical_fixture, historical_order = paid('receipt_historical')
        admin.execute('UPDATE public.customers SET telegram_id=%s WHERE id=%s', (lost_chat, lost_fixture['customer_id']))
        admin.execute('UPDATE public.customers SET telegram_id=1 WHERE id=%s', (historical_fixture['customer_id'],))
        from lhie_candidate.receipt.historical import render_product_detail
        historical_source = service.source.load(str(historical_order.order_id), 1)
        detail_units = render_product_detail(historical_source, support_url=service.source.support_url())
        historical_group = f'order:{historical_order.order_id}:receipt.credentials:v1'
        total_units = len(detail_units) + 1
        for sequence in range(1, total_units + 1):
            metadata = {'delivery_context': 'receipt', 'unit_role': 'invoice' if sequence == 1 else 'product_detail',
                        'receipt_chunk_index': sequence - 1, 'receipt_chunk_count': total_units}
            admin.execute('''INSERT INTO public.telegram_outbound_effects
                (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
                 telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,next_attempt_at,
                 outcome_metadata,created_at,updated_at)
                VALUES (%s,%s,%s,%s,'receipt.credentials','App\\Models\\Order',%s,'customer','1',
                        'telegram-receipts',%s,%s,'receipt-invoice-v2','pending',0,NULL,%s::json,
                        CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')''',
                (str(uuid4()), f'{historical_group}:unit:{sequence}', historical_group, sequence,
                 str(historical_order.order_id), 'sendPhoto' if sequence == 1 else 'sendMessage',
                 historical_source['locale'], json.dumps(metadata)))
        historical_rows = rows(historical_order.order_id)
        historical_lost = LostAck(original_transport)
        service.transport = historical_lost
        assert service.deliver(historical_rows[0][0]) == 'unknown'
        assert service.deliver(historical_rows[0][0]) == 'not_ready' and historical_lost.calls == 1
        service.transport = original_transport
        for unit in historical_rows[1:]:
            assert service.deliver(unit[0]) == 'sent'
        assert protocol.is_receipt_group_complete(historical_group)
        assert admin.execute('SELECT telegram_receipt_sent_at IS NOT NULL FROM public.orders WHERE id=%s',
                             (historical_order.order_id,)).fetchone() == (True,)

        count = 0
        while (raw := take()) is not None:
            kind, identity = decode_job(raw)
            assert secret.encode() not in raw
            assert kind in ('order', 'effect') and len(identity) == 36
            count += 1
        assert count > 0
        ledger = admin.execute('''SELECT row_to_json(e)::text FROM public.telegram_outbound_effects e
            WHERE e.source_id=%s''', (oid,)).fetchall()
        assert ledger and all(secret not in row[0] for row in ledger)
        return {'receipt_delivery':'photo_document_fake_relay', 'receipt_recovery':'fenced_unknown_and_projection_repair',
                'receipt_guards':'ownership_retired_review_rollback', 'receipt_wakeups':'id_only'}
    finally:
        admin.close()
        connection.close()
