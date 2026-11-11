"""Called by the shared P25 disposable PostgreSQL/SMTP exercise, never live."""
from datetime import timedelta
from html import escape
import json
from unittest.mock import patch
from uuid import uuid4


def exercise_waitlist(fixtures=None):
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.compat.identity import waitlist_email_hash
    from lhie_candidate.notifications.mail import DefiniteMailFailure, SinkMail
    from lhie_candidate.notifications.policy import TestRecipients
    from lhie_candidate.notifications.waitlist import CONFIRMATION, RESTOCK, WaitlistMail, mail_content
    from lhie_candidate.receipt.protocol import _now
    from lhie_candidate.runtime import application_key
    from tests.test_checkout_postgres import admin_connection

    admin = admin_connection()
    key = application_key()
    cipher = LaravelEncrypter(key)
    captured = []

    class Mail:
        outcome = 'success'

        def send(self, recipient, subject, text, html):
            captured.append((recipient, subject, text, html))
            if self.outcome == 'definite':
                raise DefiniteMailFailure()
            if self.outcome == 'unknown':
                raise TimeoutError('Synthetic lost SMTP ACK, never persist this private text')
            SinkMail().send(recipient, subject, text, html)

    transport = Mail()
    mail = WaitlistMail(cipher, signing_key=key, base_url='http://127.0.0.1:18801',
                        recipients=TestRecipients(frozenset({'1'})), transport=transport)


    confirmation_context = fixtures['p25']['waitlist_confirmation_context']
    assert mail_content(CONFIRMATION, confirmation_context) == fixtures['p25']['waitlist_confirmation_mail']
    parity_context = {**fixtures['p25']['waitlist_restock_context'], 'app_name': fixtures['p25']['app_name']}
    assert mail_content(RESTOCK, parity_context) == fixtures['p25']['waitlist_restock_mail']
    db = mail.db
    suffix = str(uuid4())
    try:
        product = admin.execute('''INSERT INTO public.products
            (name,description,price_amount,currency,stock,reserved_stock,is_active,created_at,updated_at)
            VALUES (%s,'Synthetic waitlist',1000,'IDR',0,0,true,now(),now()) RETURNING id''',
            ('P25 Waitlist ' + suffix,)).fetchone()[0]
        assert mail.subscribe(product, ' Snapshot@Example.Invalid ')
        assert not mail.subscribe(product, 'snapshot@example.invalid')
        subscription = db.one('SELECT * FROM public.product_waitlist_subscriptions WHERE product_id=%s', (product,))
        sid = subscription['id']
        assert subscription['email'] != 'snapshot@example.invalid'
        assert subscription['email_hash'] == waitlist_email_hash('snapshot@example.invalid', key)
        assert subscription['status'] == 'pending_confirmation'
        # Discover without a Redis wakeup; confirmation is encrypted ledger work.
        for _ in range(20):
            mail.materialize(10)
            confirmation = db.one('''SELECT * FROM public.telegram_outbound_effects
                WHERE effect_type=%s AND source_id=%s''', (CONFIRMATION, str(sid)))
            if confirmation:
                break
        assert confirmation
        assert confirmation['queue'] == 'mail'
        metadata = confirmation['outcome_metadata']
        if isinstance(metadata, str):
            metadata = json.loads(metadata)
        assert 'snapshot@example.invalid' not in json.dumps(metadata)
        snapshot = json.loads(cipher.decrypt_string(metadata['mail_snapshot']))
        assert '<!doctype html>' in snapshot['html'] and 'Konfirmasi permintaan' in snapshot['text']
        url = mail.signed_url('confirm', sid, subscription['confirmation_expires_at'])
        assert mail.confirm(sid, url + '&changed=1') == 'expired_link'
        assert mail.confirm(sid, mail.signed_url('confirm', sid, _now() - timedelta(seconds=1))) == 'expired_link'
        # Definite no-acceptance is retried by ReceiptProtocol; address and copy
        # remain frozen even if the retained source email/name change meanwhile.
        transport.outcome = 'definite'
        assert mail.deliver(confirmation['id']) == 'failed'
        admin.execute('UPDATE public.products SET name=%s WHERE id=%s', ('Changed synthetic product', product))
        admin.execute('UPDATE public.product_waitlist_subscriptions SET email=%s WHERE id=%s',
                      (cipher.encrypt_string('redirect@example.invalid'), sid))
        admin.execute("UPDATE public.telegram_outbound_effects SET next_attempt_at=now()-interval '1 day' WHERE id=%s", (confirmation['id'],))
        transport.outcome = 'success'
        assert mail.deliver(confirmation['id']) == 'sent'
        assert captured[-1] == ('snapshot@example.invalid', snapshot['subject'], snapshot['text'], snapshot['html'])
        assert mail.deliver(confirmation['id']) == 'not_ready'
        admin.execute('UPDATE public.product_waitlist_subscriptions SET email=%s WHERE id=%s',
                      (cipher.encrypt_string('snapshot@example.invalid'), sid))
        assert mail.confirm(sid, url) == 'success'
        assert mail.confirm(sid, url) == 'already_requested'
        # Consent is one-shot: a notified or unsubscribed identity is not rearmed.
        assert not mail.subscribe(product, 'snapshot@example.invalid')
        others = []
        for index in range(4):
            email = f'bounded-{index}@example.invalid'
            others.append(admin.execute('''INSERT INTO public.product_waitlist_subscriptions
                (product_id,email,email_hash,status,confirmed_at,created_at,updated_at)
                VALUES (%s,%s,%s,'active',now(),now(),now()) RETURNING id''',
                (product, cipher.encrypt_string(email), waitlist_email_hash(email, key))).fetchone()[0])
        movement = admin.execute('''INSERT INTO public.inventory_movements
            (product_id,type,quantity,available_before,available_after,notes,created_at)
            VALUES (%s,'adjustment',2,0,2,'Synthetic waitlist restock',now()) RETURNING id''', (product,)).fetchone()[0]
        admin.execute('UPDATE public.products SET stock=2 WHERE id=%s', (product,))
        event = admin.execute('''INSERT INTO public.product_waitlist_events
            (inventory_movement_id,product_id,available_stock,status,created_at,updated_at)
            VALUES (%s,%s,2,'pending',now(),now()) RETURNING id''', (movement, product)).fetchone()[0]
        # Drain older synthetic events, but every individual slice stays bounded.
        for _ in range(100):
            mail._materialize_restock(1)
            count = db.one('SELECT count(*) AS count FROM public.product_waitlist_notifications WHERE product_waitlist_event_id=%s', (event,))['count']
            if count:
                break
        assert count == 1
        state = db.one('SELECT * FROM public.product_waitlist_events WHERE id=%s', (event,))
        assert state['status'] == 'pending' and state['materialized_at'] is None
        assert state['materialization_cursor'] == sid
        mail._materialize_restock(1)
        assert db.one('SELECT count(*) AS count FROM public.product_waitlist_notifications WHERE product_waitlist_event_id=%s', (event,))['count'] == 2
        old_materialization_time = _now() - timedelta(days=31)
        with patch('lhie_candidate.notifications.waitlist._now', return_value=old_materialization_time):
            for _ in range(20):
                mail.materialize(10)
                restock = db.one('''SELECT e.* FROM public.telegram_outbound_effects e
                    JOIN public.product_waitlist_notifications n ON n.id::text=e.source_id
                    WHERE e.effect_type=%s AND n.product_waitlist_event_id=%s AND n.subscription_id=%s''',
                    (RESTOCK, event, sid))
                if restock:
                    break
        assert restock
        assert restock['queue'] == 'mail'
        transport.outcome = 'success'
        assert mail.deliver(restock['id']) == 'sent'
        restock_message = captured[-1]
        assert restock_message[0] == 'snapshot@example.invalid'
        assert '__LHIE_WAITLIST_UNSUBSCRIBE_URL__' not in restock_message[2] + restock_message[3]
        unsubscribe_url = restock_message[2].split(
            'Berhenti menerima notifikasi produk ini: ', 1)[1].strip()
        assert mail.valid_url(unsubscribe_url, 'unsubscribe', sid)
        assert f'href="{escape(unsubscribe_url, quote=True)}"' in restock_message[3]
        delivered = db.one('SELECT outcome_metadata FROM public.telegram_outbound_effects WHERE id=%s',
                           (restock['id'],))['outcome_metadata']
        if isinstance(delivered, str):
            delivered = json.loads(delivered)
        attempt = json.loads(cipher.decrypt_string(delivered['mail_attempt_snapshot']))
        assert attempt == {'recipient': restock_message[0], 'subject': restock_message[1],
                           'text': restock_message[2], 'html': restock_message[3]}
        # Pending opt-out prevents delivery, repeated old confirmation cannot undo it.
        target = others[0]
        unsub = mail.signed_url('unsubscribe', target, _now() + timedelta(days=30))
        assert mail.unsubscribe(target, unsub) == 'unsubscribed'
        assert mail.unsubscribe(target, unsub) == 'unsubscribed'
        assert not mail.subscribe(product, 'bounded-0@example.invalid')
        assert db.one('SELECT status FROM public.product_waitlist_notifications WHERE subscription_id=%s', (target,))['status'] == 'skipped'
        # A different admitted recipient gets the real HTML/text multipart SMTP
        # delivery. Another loses its ACK and remains unknown across recovery.
        for _ in range(20):
            mail.materialize(10)
            effects = db.rows('''SELECT e.*,n.subscription_id FROM public.telegram_outbound_effects e
                JOIN public.product_waitlist_notifications n ON n.id::text=e.source_id
                WHERE e.effect_type=%s AND n.product_waitlist_event_id=%s
                  AND n.subscription_id=ANY(%s) ORDER BY n.subscription_id''', (RESTOCK, event, others[1:]))
            if len(effects) == 3:
                break
        # Repair a sent projection without invoking SMTP.
        claim = mail.protocol.claim(effects[0]['id'])
        assert claim
        sent = mail.protocol.finalize_sent(effects[0]['id'], claim['claim_token'], 1)
        before = len(captured)
        assert mail.project(sent) >= 1
        assert mail.project(sent) == 0
        assert len(captured) == before
        assert mail.deliver(effects[0]['id']) == 'not_ready'
        transport.outcome = 'success'
        assert mail.deliver(effects[1]['id']) == 'sent'
        retry_link = captured[-1][2].split(
            'Berhenti menerima notifikasi produk ini: ', 1)[1].strip()
        assert mail.valid_url(retry_link, 'unsubscribe', effects[1]['subscription_id'])
        transport.outcome = 'unknown'
        assert mail.deliver(effects[2]['id']) == 'unknown'
        assert mail.deliver(effects[2]['id']) == 'not_ready'
        unknown = db.one('SELECT * FROM public.product_waitlist_notifications WHERE id=%s', (effects[2]['source_id'],))
        assert unknown['status'] == 'failed' and unknown['last_error'] == 'mail_delivery_unknown'
        assert unknown['delivery_started_at'] is not None
        assert db.one('SELECT count(*) AS count FROM public.product_waitlist_notifications WHERE product_waitlist_event_id=%s', (event,))['count'] == 5
        assert db.one('SELECT stock,reserved_stock FROM public.products WHERE id=%s', (product,)) == {'stock': 2, 'reserved_stock': 0}
        from lhie_candidate.admin.reviews import DeliveryReviewOperations, DeliveryReviewReads
        from lhie_candidate.checkout.errors import CheckoutError
        actor = fixtures['auth']['admin']['id']
        accepted_before_review = len(captured)
        reviews = DeliveryReviewOperations()
        summary = DeliveryReviewReads().show(effects[2]['id'])
        assert summary['is_mail'] and 'mail_snapshot' not in summary['outcome']
        try:
            reviews.mark_sent(effects[2]['id'], message_id='', actor_id=actor, mail_confirmed=True)
        except CheckoutError:
            pass
        else:
            raise AssertionError('SMTP confirmation evidence was not required')
        reviewed = reviews.mark_sent(effects[2]['id'], message_id='', actor_id=actor, mail_confirmed=True,
                                     evidence_reference='smtp-audit:waitlist-2026-11')
        assert reviewed['state'] == 'sent'
        assert db.one('SELECT status FROM public.product_waitlist_notifications WHERE id=%s',
                      (effects[2]['source_id'],))['status'] == 'sent'
        assert len(captured) == accepted_before_review
        projection_product = admin.execute('''INSERT INTO public.products
            (name,description,price_amount,currency,stock,reserved_stock,is_active,created_at,updated_at)
            VALUES (%s,'Synthetic confirmation projection',1000,'IDR',0,0,true,now(),now()) RETURNING id''',
            ('P25 confirmation projection ' + suffix,)).fetchone()[0]
        projection_email = 'confirmation-projection@example.invalid'
        assert mail.subscribe(projection_product, projection_email)
        projection_subscription = db.one(
            'SELECT * FROM public.product_waitlist_subscriptions WHERE product_id=%s',
            (projection_product,))
        projection_effect = None
        for _ in range(20):
            mail.materialize(10)
            projection_effect = db.one('''SELECT * FROM public.telegram_outbound_effects
                WHERE effect_type=%s AND source_id=%s''',
                (CONFIRMATION, str(projection_subscription['id'])))
            if projection_effect:
                break
        assert projection_effect
        transport.outcome = 'unknown'
        assert mail.deliver(projection_effect['id']) == 'unknown'
        unknown_projection_effect = db.one(
            'SELECT * FROM public.telegram_outbound_effects WHERE id=%s',
            (projection_effect['id'],))
        unknown_metadata = unknown_projection_effect['outcome_metadata']
        if isinstance(unknown_metadata, str):
            unknown_metadata = json.loads(unknown_metadata)
        retry = reviews.resend(
            projection_effect['id'], actor_id=actor, confirm_duplicate_risk=True)
        retry_metadata = db.one(
            'SELECT outcome_metadata FROM public.telegram_outbound_effects WHERE id=%s',
            (retry['id'],))['outcome_metadata']
        if isinstance(retry_metadata, str):
            retry_metadata = json.loads(retry_metadata)
        assert retry_metadata['mail_snapshot'] == unknown_metadata['mail_snapshot']
        transport.outcome = 'success'
        assert mail.deliver(retry['id']) == 'sent'
        sent_projection = db.one('''SELECT confirmation_sent_at,confirmation_attempts,
            confirmation_delivery_started_at,confirmation_last_error
            FROM public.product_waitlist_subscriptions WHERE id=%s''',
            (projection_subscription['id'],))
        assert sent_projection['confirmation_sent_at'] is not None
        before_stale_projection = len(captured)
        assert mail.project(unknown_projection_effect) == 0
        assert len(captured) == before_stale_projection
        assert db.one('''SELECT confirmation_sent_at,confirmation_attempts,
            confirmation_delivery_started_at,confirmation_last_error
            FROM public.product_waitlist_subscriptions WHERE id=%s''',
            (projection_subscription['id'],)) == sent_projection
        return {'waitlist_double_opt_in': 'passed', 'waitlist_bounded_materialization': 'passed',
                'waitlist_snapshot_multipart_sink': 'passed', 'waitlist_unsubscribe': 'passed',
                'waitlist_unknown_no_resend': 'passed', 'waitlist_sent_projection_no_transport': 'passed',
                'waitlist_stale_confirmation_projection': 'passed',
                'waitlist_php_mailable_template_parity': 'passed'}
    finally:
        admin.close()
