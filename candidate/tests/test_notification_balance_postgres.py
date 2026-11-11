"""Notification-only retained top-up fixtures; no provider or ledger settlement."""
from uuid import uuid4


def exercise_balance(admin, customer_id):
    from lhie_candidate.notifications.balance import BalanceNotices, INSTRUCTIONS, RECEIPT, OWNER_ALERT, PAYMENT_PROOF, PAID, CHANNEL_PROOF
    from lhie_candidate.notifications.policy import TestRecipients
    from lhie_candidate.notifications.service import Notifications
    from lhie_candidate.notifications.transport import NotificationTransport
    from lhie_candidate.payments.presentation import QrPresentation
    service = Notifications(NotificationTransport(), recipients=TestRecipients(frozenset({'1'})),
        bot_username='LhieStoreTestBot',
        effect_types=(INSTRUCTIONS, RECEIPT, OWNER_ALERT, PAYMENT_PROOF, PAID, CHANNEL_PROOF),
        queue='notifications', qr_presentation=QrPresentation(public_storage='/run/harness/public-assets'))
    adapter = BalanceNotices(service)
    assert admin.execute('SELECT id FROM public.customers WHERE telegram_id=1').fetchone()[0] == customer_id
    rows = []
    intents = []
    for status in ('awaiting_confirmation','paid','recovery_pending'):
        nonce = uuid4().hex
        intent = admin.execute("INSERT INTO public.store_balance_funding_intents(customer_id,chat_id,canonical_message_id,policy_version,amount,nonce,status,expires_at,created_at,updated_at) VALUES (%s,'1',1,1,1000,%s,'claimed',now()+interval '1 hour',now(),now()) RETURNING id", (customer_id,nonce)).fetchone()[0]
        intents.append(intent)
        row = admin.execute("INSERT INTO public.store_balance_top_ups(customer_id,store_balance_funding_intent_id,chat_id,canonical_message_id,locale,provider,merchant_ref,idempotency_key,currency,amount,status,provider_fee,provider_total_payment,provider_payment_method,expires_at,provider_expires_at,paid_at,settled_at,payment_proof_file_id,payment_proof_submitted_at,payment_proof_admin_chat_id,created_at,updated_at) VALUES (%s,%s,'1',1,'id','manual',%s,%s,'IDR',1000,%s,10,1010,'QR_MANUAL',(CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')+interval '1 hour',(CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')+interval '1 hour',CASE WHEN %s='paid' THEN now() ELSE NULL END,CASE WHEN %s='paid' THEN now() ELSE NULL END,CASE WHEN %s='awaiting_confirmation' THEN 'synthetic_proof' ELSE NULL END,CASE WHEN %s='awaiting_confirmation' THEN now() ELSE NULL END,'1',now(),now()) RETURNING id", (customer_id,intent,nonce,nonce,status,status,status,status,status)).fetchone()[0]
        rows.append(row)
    assert adapter.materialize(100) >= 6
    effects = service.db.rows(
        'SELECT * FROM public.telegram_outbound_effects WHERE effect_type=ANY(%s) AND source_id=ANY(%s)',
        (list(service.protocol.effect_types), [str(row) for row in rows]),
    )
    actual_types = {e['effect_type'] for e in effects}
    expected_types = set(service.protocol.effect_types)
    assert actual_types == expected_types, {
        'actual_types': sorted(actual_types), 'expected_types': sorted(expected_types),
        'effect_count': len(effects),
    }
    assert {e['queue'] for e in effects} == {'notifications'}
    from lhie_candidate.workers import wakeup
    from lhie_candidate.workers.notifications import NotificationHandler, notification_services
    from lhie_candidate.workers.services import build_handler
    notifications = NotificationHandler(notification_services('notifications',
        recipients=TestRecipients(frozenset({'1'}))), 'notifications')
    receipt = build_handler('receipt')
    for effect in effects:
        assert receipt.handle(wakeup.encode('effect', effect['id'])) == 'not_ready', effect['effect_type']
        assert notifications.handle(wakeup.encode('notification.effect', effect['id'])) == 'sent'
        assert notifications.handle(wakeup.encode('notification.effect', effect['id'])) == 'not_ready'
    proof = next(e for e in effects if e['effect_type']==PAYMENT_PROOF)
    assert service.db.one('SELECT payment_proof_forwarded_at FROM public.store_balance_top_ups WHERE id=%s', (proof['source_id'],))['payment_proof_forwarded_at']
    admin.execute('DELETE FROM public.telegram_outbound_effects WHERE effect_type=ANY(%s) AND source_id=ANY(%s)',
                  (list(service.protocol.effect_types), [str(row) for row in rows]))
    admin.execute('DELETE FROM public.store_balance_top_ups WHERE id=ANY(%s)', (rows,))
    admin.execute('DELETE FROM public.store_balance_funding_intents WHERE id=ANY(%s)', (intents,))
    return {'retained_idr_topup_instruction_receipt_admin_channel': 'passed'}
