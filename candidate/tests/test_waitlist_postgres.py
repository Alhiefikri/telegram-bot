"""Changed waitlist lifecycle in the existing P25 disposable proof, never live."""
from concurrent.futures import ThreadPoolExecutor
from datetime import timedelta
from threading import Barrier
from types import SimpleNamespace


def exercise_waitlist():
    from django.db import connections
    from django.test import Client
    import re
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.notifications.mail import DefiniteMailFailure, SinkMail
    from lhie_candidate.notifications.waitlist import Waitlist
    from lhie_candidate.receipt.protocol import _now
    from lhie_candidate.runtime import application_key
    from tests.test_checkout_postgres import admin_connection

    db, admin = Database(), admin_connection()
    key, at = application_key(), _now()
    cipher = LaravelEncrypter(key)
    captured = []

    class Mail:
        failure = None

        def send(self, address, subject, text, *, html):
            captured.append((address, subject, text, html))
            if self.failure:
                raise self.failure
            SinkMail().send(address, subject, text, html=html)

    mail = Mail()
    owner = Waitlist(cipher, key, enabled=True, transport=mail)
    try:
        pid = admin.execute('''INSERT INTO public.products
            (name,description,price_amount,currency,stock,reserved_stock,is_active,created_at,updated_at)
            VALUES ('Synthetic waitlist <Product>','Synthetic fixture',15000,'IDR',0,0,true,now(),now()) RETURNING id''').fetchone()[0]
        client = Client(enforce_csrf_checks=True, HTTP_HOST='127.0.0.1:18801', REMOTE_ADDR='127.0.0.1')
        url = f'/produk/{pid}'
        response = client.get(url)
        assert response.status_code == 200, response.status_code
        csrf = re.search(r'name="csrfmiddlewaretoken" value="([^"]+)"', response.content.decode()).group(1)
        assert client.post(url + '/waitlist', {'email': 'first@example.invalid'}).status_code == 403
        response = client.post(url + '/waitlist', {'email': 'first@example.invalid', 'csrfmiddlewaretoken': csrf},
                               HTTP_ORIGIN='http://127.0.0.1:18801')
        assert response.status_code == 302 and response['Location'] == url + '#waitlist', (response.status_code, response.content)
        assert 'first@example.invalid' not in response['Location']
        for address in ('second@example.invalid', 'unknown@example.invalid', 'stop@example.invalid'):
            assert owner.subscribe(pid, address)
            assert not owner.subscribe(pid, address)
        assert not owner.subscribe(pid, 'real@example.com')
        subscriptions = db.rows('SELECT * FROM public.product_waitlist_subscriptions WHERE product_id=%s ORDER BY id', (pid,))
        assert len(subscriptions) == 4
        assert all('@' not in s['email'] and len(s['email_hash']) == 64 for s in subscriptions)
        for subscription in subscriptions:
            sid = subscription['id']
            assert owner.confirm_mail(sid) == 'sent'
            assert owner.confirm_mail(sid) == 'not_ready'
            assert captured[-1][0].endswith('.invalid')
            assert '&lt;Product&gt;' in captured[-1][3]
            url = owner.links.create('confirm', sid, subscription['confirmation_expires_at'])
            assert owner.resolve(sid, 'unsubscribe', url) == 'expired_link'
            response = client.get(url)
            assert response.status_code == 200 and 'Permintaan dikonfirmasi'.encode() in response.content
            assert response['Cache-Control'] == 'no-store, private'
            assert response['Referrer-Policy'] == 'no-referrer'
            assert owner.resolve(sid, 'confirm', url) == 'already_requested'
        # Coherent synthetic restock: projection, units and immutable adjustment
        # are created together, never by weakening guards or changing history.
        with admin.transaction():
            admin.execute('UPDATE public.products SET stock=3 WHERE id=%s', (pid,))
            for index in range(3):
                admin.execute('''INSERT INTO public.product_stock_items (product_id,secret,created_at,updated_at)
                    VALUES (%s,%s,now(),now())''', (pid, cipher.encrypt_string('Synthetic waitlist unit ' + str(index))))
            mid = admin.execute('''INSERT INTO public.inventory_movements
                (product_id,type,quantity,stock_before,available_before,available_after,became_unavailable,created_at)
                VALUES (%s,'adjustment',3,0,0,3,false,now()) RETURNING id''', (pid,)).fetchone()[0]
        # Bounded fan-out resumes from its persisted cursor, no duplicate work.
        assert owner.materialize(mid, 1) == 1
        assert owner.materialize(mid, 1) == 1
        assert owner.materialize(mid, 1) == 1
        assert owner.materialize(mid, 1) == 1
        assert owner.materialize(mid, 1) == 0
        rows = db.rows('''SELECT n.* FROM public.product_waitlist_notifications n
            JOIN public.product_waitlist_events e ON e.id=n.product_waitlist_event_id
            WHERE e.inventory_movement_id=%s ORDER BY n.id''', (mid,))
        assert len(rows) == 4
        stop = owner.links.create('unsubscribe', subscriptions[-1]['id'], at + timedelta(days=1))
        assert owner.resolve(subscriptions[-1]['id'], 'unsubscribe', stop) == 'unsubscribed'
        before = len(captured)
        assert owner.deliver(rows[-1]['id']) == 'not_ready'
        assert len(captured) == before
        # Real PostgreSQL claim race, one SMTP operation, no per-family copy of
        # the shared Telegram ledger race suite.
        barrier = Barrier(2)

        def competing():
            try:
                concurrent = Waitlist(cipher, key, enabled=True, transport=mail)
                barrier.wait(timeout=10)
                return concurrent.deliver(rows[0]['id'])
            finally:
                connections.close_all()

        before = len(captured)
        with ThreadPoolExecutor(max_workers=2) as pool:
            outcomes = list(pool.map(lambda _: competing(), range(2)))
        assert sorted(outcomes) == ['not_ready', 'sent'], outcomes
        assert len(captured) == before + 1
        assert db.one('SELECT status FROM public.product_waitlist_subscriptions WHERE id=%s',
                      (subscriptions[0]['id'],))['status'] == 'notified'
        # Only a proven pre-acceptance failure is retryable; changed address is
        # not accepted by subscribe once active, so retries retain ownership.
        mail.failure = DefiniteMailFailure()
        assert owner.deliver(rows[1]['id']) == 'failed'
        assert owner.deliver(rows[1]['id']) == 'not_ready'
        admin.execute("UPDATE public.product_waitlist_notifications SET next_attempt_at=now()-interval '1 day' WHERE id=%s", (rows[1]['id'],))
        mail.failure = None
        assert owner.deliver(rows[1]['id']) == 'sent'
        assert captured[-1][0] == 'second@example.invalid'
        mail.failure = TimeoutError('private SMTP response')
        assert owner.deliver(rows[2]['id']) == 'unknown'
        before = len(captured)
        assert owner.deliver(rows[2]['id']) == 'not_ready'
        unknown = db.one('SELECT * FROM public.product_waitlist_notifications WHERE id=%s', (rows[2]['id'],))
        assert unknown['last_error'] == 'mail_delivery_unknown' and unknown['delivery_started_at']
        assert len(captured) == before
        # Lost confirmation ACK/expired sending marker never creates new mail.
        empty = admin.execute('''INSERT INTO public.products
            (name,description,price_amount,currency,stock,reserved_stock,is_active,created_at,updated_at)
            VALUES ('Synthetic lost ACK','Synthetic fixture',100,'IDR',0,0,true,now(),now()) RETURNING id''').fetchone()[0]
        assert owner.subscribe(empty, 'interrupted@example.invalid')
        interrupted = db.one('SELECT id FROM public.product_waitlist_subscriptions WHERE product_id=%s', (empty,))['id']
        admin.execute('''UPDATE public.product_waitlist_subscriptions
            SET confirmation_delivery_started_at=%s,confirmation_attempts=3 WHERE id=%s''', (at - timedelta(seconds=181), interrupted))
        assert owner.confirm_mail(interrupted) == 'unknown'
        assert owner.confirm_mail(interrupted) == 'not_ready'
        assert not owner.subscribe(empty, 'interrupted@example.invalid')
        mail.failure = None
        owner.recover(2)
        assert len(captured) == before
        # Readiness accepts only the new narrow grants, never UPDATE on history.
        assert not db.one("SELECT has_table_privilege(current_user,'public.inventory_movements','UPDATE') AS allowed")['allowed']
        return {'waitlist_http_csrf_opt_in': 'passed', 'waitlist_opt_in_signed_links': 'passed', 'waitlist_bounded_fanout': 'passed',
                'waitlist_smtp_claim_race': 'passed', 'waitlist_unsubscribe': 'passed',
                'waitlist_unknown_no_resend': 'passed'}
    finally:
        admin.close()
