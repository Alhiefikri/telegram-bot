"""P25 fixture adapter for independently delayed SMTP and notice workers."""
import json
from uuid import uuid4


def _pending_notice(connection, customer_id, product_id):
    order, payment = str(uuid4()), str(uuid4())
    invoice = 'P25-ISOLATION-' + order[:8]
    connection.execute("""INSERT INTO public.orders
        (id,customer_id,invoice_number,order_status,payment_status,total_amount,currency,expires_at,created_at,updated_at)
        VALUES (%s,%s,%s,'pending_payment','pending',100,'IDR',
        (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')+interval '1 hour',now(),now())""",
        (order, customer_id, invoice))
    connection.execute("""INSERT INTO public.order_items
        (order_id,product_id,product_name,quantity,unit_price,subtotal,created_at,updated_at)
        VALUES (%s,%s,'Synthetic isolated notice',1,100,100,now(),now())""", (order, product_id))
    connection.execute("""INSERT INTO public.payments
        (id,order_id,provider,merchant_ref,method,amount,currency,status,expired_at,created_at,updated_at)
        VALUES (%s,%s,'manual',%s,'QR_MANUAL',100,'IDR','pending',
        (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')+interval '1 hour',now(),now())""",
        (payment, order, invoice))
    return order, invoice


def exercise_isolation(fixtures, *, slow_queue='mail'):
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.runtime import application_key
    from tests.test_checkout_postgres import admin_connection
    from tests.test_notification_isolation_postgres import exercise_slow_delivery_isolation
    from tests.test_workers_postgres import _checkout, _pending_update
    if slow_queue == 'notifications':
        fixtures = {**fixtures, 'retry_success': fixtures['retry_exhausted'],
                    'duplicate': fixtures['restore'], 'release': fixtures['pause']}
    admin = admin_connection()
    mail = _checkout(fixtures['retry_success'], 'p25-slow-' + slow_queue)
    cipher = LaravelEncrypter(application_key())
    admin.execute('UPDATE public.orders SET notification_email=%s WHERE id=%s',
                  (cipher.encrypt_string('slow@example.invalid'), str(mail.order_id)))
    slow_effect_id, sentinel = None, ''
    if slow_queue == 'notifications':
        from lhie_candidate.notifications.lifecycle import PENDING, LifecycleNotices
        from lhie_candidate.notifications.policy import TestRecipients
        from lhie_candidate.notifications.service import Notifications
        order, sentinel = _pending_notice(admin, fixtures['foreign']['customer_id'], fixtures['foreign']['product_id'])
        service = Notifications(None, recipients=TestRecipients(frozenset({'1'})),
                                effect_types=(PENDING,), queue='notifications')
        LifecycleNotices(service)._materialize_source(PENDING, order, '1')
        slow_effect_id = str(service.db.one(
            'SELECT id FROM public.telegram_outbound_effects WHERE effect_type=%s AND source_id=%s',
            (PENDING, order))['id'])

    def seed(connection, *, mail_event_id, auth_work_id):
        receipt = _checkout(fixtures['p25']['isolation_receipt_' + slow_queue],
                            'p25-isolated-receipt-' + slow_queue)
        update_id = 970001 if slow_queue == 'mail' else 970002
        update = _pending_update(update_id, 1, '/start')
        connection.execute('INSERT INTO django_candidate.navigation_updates(update_id,chat_id,payload) VALUES (%s,%s,%s::jsonb)',
                           (update_id, 1, json.dumps(update)))
        connection.execute("UPDATE public.manual_payment_settings SET payment_mode='sakurupiah',sakurupiah_channel='QRIS' WHERE is_active=true")
        expiring = _checkout(fixtures['release'], 'p25-isolated-expiry-' + slow_queue, sakurupiah=True)
        expiring_order = str(expiring.order_id)
        connection.execute("UPDATE public.orders SET expires_at=(CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')-interval '1 minute' WHERE id=%s", (expiring_order,))
        pending_order, _ = _pending_notice(connection, fixtures['foreign']['customer_id'], fixtures['foreign']['product_id'])
        if slow_queue == 'mail':
            from lhie_candidate.notifications.lifecycle import PENDING, LifecycleNotices
            from lhie_candidate.notifications.policy import TestRecipients
            from lhie_candidate.notifications.service import Notifications
            admin_chat = connection.execute(
                'SELECT admin_telegram_chat_id FROM public.manual_payment_settings WHERE is_active=true'
            ).fetchone()[0]
            assert str(admin_chat) == '1'
            notices = Notifications(None, recipients=TestRecipients(frozenset({'1'})),
                bot_username='LhieStoreTestBot', effect_types=(PENDING,), queue='notifications')
            assert LifecycleNotices(notices)._materialize_source(PENDING, pending_order, str(admin_chat)) == 1
            effect = notices.db.one(
                'SELECT id,state FROM public.telegram_outbound_effects WHERE effect_type=%s AND source_id=%s',
                (PENDING, pending_order),
            )
            assert effect and effect['state'] == 'pending'
            from lhie_candidate.workers import wakeup
            assert wakeup.enqueue('notification.effect', effect['id'])
        broadcast = connection.execute("INSERT INTO public.telegram_broadcasts(broadcast_key,source,message,status,created_at,updated_at) VALUES (%s,'admin','Synthetic independent bulk notice','queued',now(),now()) RETURNING id", (str(uuid4()),)).fetchone()[0]
        checks = {
            'interaction': ("SELECT state='processed' FROM django_candidate.navigation_updates WHERE update_id=%s", (update_id,)),
            'payment': ("SELECT order_status='expired' FROM public.orders WHERE id=%s", (expiring_order,)),
            'receipt': ('SELECT telegram_receipt_sent_at IS NOT NULL FROM public.orders WHERE id=%s', (str(receipt.order_id),)),
            'notifications': ('SELECT admin_pending_order_notified_at IS NOT NULL FROM public.orders WHERE id=%s', (pending_order,)),
            'bulk': ('SELECT sent_at IS NOT NULL FROM public.telegram_broadcast_recipients WHERE broadcast_id=%s AND telegram_id=%s', (broadcast,'1')),
            'mail': ('SELECT sent_at IS NOT NULL FROM public.order_notification_events WHERE id=%s', (mail_event_id,)),
            'auth-mail': ("SELECT status='sent' FROM public.customer_auth_mail_works WHERE id=%s", (auth_work_id,)),
        }
        if slow_queue == 'mail':
            del checks['mail']
        if slow_queue == 'notifications':
            del checks['notifications']
        return checks
    return exercise_slow_delivery_isolation(str(mail.order_id), seed, slow_queue=slow_queue,
                                            slow_effect_id=slow_effect_id, sentinel=sentinel)
