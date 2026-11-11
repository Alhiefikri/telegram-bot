"""Retained waitlist consent and mail, fenced by the shared outbound protocol."""
from datetime import timedelta
from html import escape
import hmac
import json
import re
from urllib.parse import urlsplit
from uuid import uuid4
from zoneinfo import ZoneInfo

from django.db import connections, transaction
from django.template.loader import render_to_string

from ..branding import APP_NAME

from ..compat.identity import normalize_customer_email, signed_url_digest, waitlist_email_hash
from ..navigation.repository import CatalogRepository
from ..receipt.protocol import ReceiptProtocol, _now
from .mail import DefiniteMailFailure, SinkMail
from .policy import NotEligible, TestRecipients


CONFIRMATION = 'waitlist.confirmation_mail'
RESTOCK = 'waitlist.restock_mail'
LIMITS = {CONFIRMATION: 3, RESTOCK: 3}
MODELS = {CONFIRMATION: 'ProductWaitlistSubscription', RESTOCK: 'ProductWaitlistNotification'}
TERMINAL_CODES = frozenset({'waitlist_not_eligible', 'waitlist_render_invalid', 'waitlist_legacy_terminal'})


def safe_product_name(value):
    return re.sub(r'[\r\n]+', ' ', (value or '').strip())[:160] or 'Produk'


def mail_content(kind, context):
    if kind not in LIMITS:
        raise NotEligible()
    name = 'confirmation' if kind == CONFIRMATION else 'restocked'
    title = 'Konfirmasi notifikasi stok' if kind == CONFIRMATION else 'Produk tersedia kembali'
    return {'subject': title + ' | ' + context['app_name'],
            'text': render_to_string('emails/product-waitlist-' + name + '-text.txt', context),
            'html': render_to_string('emails/product-waitlist-' + name + '.html', context)}


class WaitlistMail:
    def __init__(self, encrypter, *, signing_key, base_url, recipients=None, transport=None,
                 using='default', waitlist_key='', confirmation_minutes=60,
                 unsubscribe_days=30, app_name=None):
        parsed = urlsplit(base_url)
        if (parsed.scheme not in ('http', 'https') or not parsed.netloc or parsed.username
                or parsed.password or parsed.query or parsed.fragment or parsed.path not in ('', '/')):
            raise ValueError('Invalid waitlist URL origin')
        self.protocol = ReceiptProtocol(using, lease_seconds=180, family_limits=LIMITS,
                                        terminal_codes=TERMINAL_CODES)
        self.db = self.protocol.db
        self.encrypter, self.signing_key = encrypter, signing_key
        self.base_url, self.waitlist_key = base_url.rstrip('/'), waitlist_key
        self.recipients = recipients if recipients is not None else TestRecipients()
        self.transport = transport if transport is not None else SinkMail()
        self.confirmation_minutes = max(1, int(confirmation_minutes))
        self.unsubscribe_days = max(1, int(unsubscribe_days))
        self.app_name = APP_NAME if app_name is None else app_name
        self._cursors = {}

    @property
    def enabled(self):
        return bool(self.recipients.telegram)

    def _outside(self):
        if (connections[self.db.using].in_atomic_block
                or not connections[self.db.using].get_autocommit()):
            raise RuntimeError('Waitlist mail requires an outermost boundary')

    @staticmethod
    def _limit(limit):
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError('Invalid waitlist batch')
        return limit

    def signed_url(self, action, subscription_id, expires_at):
        if action not in ('confirm', 'unsubscribe'):
            raise ValueError('Invalid waitlist action')
        stamp = int(expires_at.replace(tzinfo=ZoneInfo('Asia/Jakarta')).timestamp())
        unsigned = f'{self.base_url}/waitlist/{action}/{int(subscription_id)}?expires={stamp}'
        return unsigned + '&signature=' + signed_url_digest(unsigned, self.signing_key)

    def valid_url(self, url, action, subscription_id):
        parsed = urlsplit(url)
        if (action not in ('confirm', 'unsubscribe')
                or parsed.scheme + '://' + parsed.netloc != self.base_url
                or parsed.path != f'/waitlist/{action}/{int(subscription_id)}' or parsed.fragment):
            return False
        # Laravel signs the exact query bytes, not a parsed/re-encoded query.
        parts = parsed.query.split('&')
        signatures = [part[10:] for part in parts if part.startswith('signature=')]
        unsigned_parts = [part for part in parts if not part.startswith('signature=')]
        expiry = [part[8:] for part in unsigned_parts if part.startswith('expires=')]
        if (len(signatures) != 1 or len(expiry) != 1 or not re.fullmatch(r'[0-9]{1,12}', expiry[0])
                or int(expiry[0]) < int(_now().replace(tzinfo=ZoneInfo('Asia/Jakarta')).timestamp())):
            return False
        unsigned = parsed.scheme + '://' + parsed.netloc + parsed.path + '?' + '&'.join(unsigned_parts)
        return hmac.compare_digest(signatures[0], signed_url_digest(unsigned, self.signing_key))

    def _address(self, subscription):
        email = normalize_customer_email(self.encrypter.decrypt_string(subscription['email']))
        self.recipients.mail(email)
        if not hmac.compare_digest(subscription['email_hash'], waitlist_email_hash(
                email, self.signing_key, waitlist_key=self.waitlist_key)):
            raise NotEligible()
        return email

    def _product(self, product_id, *, lock=False):
        if lock:
            self.db.one('SELECT id FROM public.products WHERE id=%s FOR UPDATE', (product_id,))
        return CatalogRepository(self.db.using).product(product_id)

    def subscribe(self, product_id, email):
        if not self.enabled:
            return False
        email = normalize_customer_email(email)
        self.recipients.mail(email)
        digest = waitlist_email_hash(email, self.signing_key, waitlist_key=self.waitlist_key)
        with transaction.atomic(using=self.db.using):
            product = self._product(product_id, lock=True)
            if not product or product['available_stock'] > 0:
                return False
            row = self.db.one('''SELECT * FROM public.product_waitlist_subscriptions
                WHERE product_id=%s AND email_hash=%s FOR UPDATE''', (product_id, digest))
            at = _now()
            expires = at + timedelta(minutes=self.confirmation_minutes)
            if row:
                if (row['status'] != 'pending_confirmation'
                        or (row['confirmation_expires_at'] and row['confirmation_expires_at'] > at)):
                    return False
                self.db.execute('''UPDATE public.product_waitlist_subscriptions SET email=%s,
                    confirmation_expires_at=%s,confirmation_sent_at=NULL,
                    confirmation_delivery_started_at=NULL,confirmation_last_error=NULL,updated_at=%s WHERE id=%s''',
                    (self.encrypter.encrypt_string(email), expires, at, row['id']))
            else:
                row = self.db.one('''INSERT INTO public.product_waitlist_subscriptions
                    (product_id,email,email_hash,status,confirmation_expires_at,created_at,updated_at)
                    VALUES (%s,%s,%s,'pending_confirmation',%s,%s,%s) RETURNING *''',
                    (product_id, self.encrypter.encrypt_string(email), digest, expires, at, at))
            return True

    def confirm(self, subscription_id, url):
        if not self.enabled or not self.valid_url(url, 'confirm', subscription_id):
            return 'expired_link'
        with transaction.atomic(using=self.db.using):
            row = self.db.one('SELECT * FROM public.product_waitlist_subscriptions WHERE id=%s FOR UPDATE',
                              (subscription_id,))
            if not row:
                return 'expired_link'
            self._address(row)
            if row['status'] == 'unsubscribed':
                return 'unsubscribed'
            if row['status'] != 'pending_confirmation':
                return 'already_requested'
            at = _now()
            if not row['confirmation_expires_at'] or row['confirmation_expires_at'] < at:
                return 'expired_link'
            self.db.execute('''UPDATE public.product_waitlist_subscriptions SET status='active',
                confirmed_at=%s,confirmation_expires_at=NULL,confirmation_delivery_started_at=NULL,
                updated_at=%s WHERE id=%s''', (at, at, subscription_id))
            return 'success'

    def unsubscribe(self, subscription_id, url):
        if not self.enabled or not self.valid_url(url, 'unsubscribe', subscription_id):
            return 'expired_link'
        with transaction.atomic(using=self.db.using):
            row = self.db.one('SELECT * FROM public.product_waitlist_subscriptions WHERE id=%s FOR UPDATE',
                              (subscription_id,))
            if not row:
                return 'expired_link'
            self._address(row)
            at = _now()
            self.db.execute('''UPDATE public.product_waitlist_subscriptions SET status='unsubscribed',
                unsubscribed_at=COALESCE(unsubscribed_at,%s),confirmation_delivery_started_at=NULL,
                updated_at=%s WHERE id=%s AND status<>'unsubscribed' ''', (at, at, subscription_id))
            self.db.execute('''UPDATE public.product_waitlist_notifications SET status='skipped',
                next_attempt_at=NULL,updated_at=%s WHERE subscription_id=%s AND status IN ('pending','failed')''',
                (at, subscription_id))
            return 'unsubscribed'

    def _record(self, kind, row, subscription, product):
        self.db.require_transaction()
        email = self._address(subscription)
        at = _now()
        context = {'app_name': self.app_name, 'product_name': safe_product_name(product['name'])}
        if kind == CONFIRMATION:
            expires = subscription['confirmation_expires_at']
            context['confirmation_url'] = self.signed_url('confirm', subscription['id'], expires)
            version = str(int(expires.replace(tzinfo=ZoneInfo('Asia/Jakarta')).timestamp()))
            sent, started = subscription['confirmation_sent_at'], subscription['confirmation_delivery_started_at']
            attempts, failed, error = subscription['confirmation_attempts'], None, subscription['confirmation_last_error']
        else:
            context.update(available_stock=product['available_stock'],
                product_url=f"{self.base_url}/produk/{product['id']}",
                unsubscribe_url='__LHIE_WAITLIST_UNSUBSCRIBE_URL__')
            version = '1'
            sent, started = row['sent_at'], row['delivery_started_at']
            attempts, failed, error = row['attempts'], row['failed_at'], row['last_error']
        key = f"waitlist:{kind}:{row['id']}:{version}"
        if self.db.one('SELECT id FROM public.telegram_outbound_effects WHERE effect_key=%s', (key,)):
            return None
        state = 'sent' if sent else 'unknown' if started else 'failed' if failed else 'pending'
        terminal = error == 'mail_delivery_unknown' or attempts >= LIMITS[kind]
        if terminal and not sent:
            state = 'unknown' if error == 'mail_delivery_unknown' else 'failed'
        snapshot = dict(recipient=email, **mail_content(kind, context))
        metadata = {'delivery_context': 'waitlist_mail',
                    'mail_snapshot': self.encrypter.encrypt_string(json.dumps(snapshot, ensure_ascii=False)),
                    'confirmation_expires_at': version if kind == CONFIRMATION else None}
        return self.db.one('''INSERT INTO public.telegram_outbound_effects
            (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
             telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,
             next_attempt_at,sent_at,unknown_at,failed_at,last_error_code,outcome_metadata,created_at,updated_at)
            VALUES (%s,%s,%s,1,%s,%s,%s,'mailbox',%s,'mail','smtp.send','id',
                    'waitlist-mail-v1',%s,%s,%s,%s,%s,%s,%s,%s::json,%s,%s)
            ON CONFLICT (effect_key) DO NOTHING RETURNING *''',
            (str(uuid4()), key, key, kind, 'App\\Models\\' + MODELS[kind], str(row['id']),
             subscription['email_hash'], state, attempts, at if state in ('pending', 'failed') and not terminal else None,
             sent, at if state == 'unknown' else None, failed,
             'waitlist_legacy_terminal' if terminal else None, json.dumps(metadata), at, at))

    def _scan(self, name, sql, params, limit):
        cursor = self._cursors.get(name, 0)
        rows = self.db.rows(sql, (*params, cursor, limit))
        self._cursors[name] = rows[-1]['id'] if rows else 0
        return rows

    def materialize(self, limit=25):
        self._limit(limit)
        if not self.enabled:
            return 0
        count = 0
        rows = self._scan('confirmations', '''SELECT * FROM public.product_waitlist_subscriptions s
            WHERE status='pending_confirmation' AND confirmation_expires_at>%s
              AND id>%s ORDER BY id LIMIT %s''', (_now(),), limit)
        for candidate in rows:
            with transaction.atomic(using=self.db.using):
                row = self.db.one('SELECT * FROM public.product_waitlist_subscriptions WHERE id=%s FOR UPDATE', (candidate['id'],))
                if row['status'] != 'pending_confirmation' or not row['confirmation_expires_at'] or row['confirmation_expires_at'] <= _now():
                    continue
                product = self.db.one('SELECT id,name FROM public.products WHERE id=%s', (row['product_id'],))
                if product:
                    try:
                        count += int(self._record(CONFIRMATION, row, row, product) is not None)
                    except (NotEligible, ValueError):
                        continue
        count += self._materialize_restock(limit)
        rows = self._scan('notifications', '''SELECT n.* FROM public.product_waitlist_notifications n
            WHERE n.id>%s ORDER BY n.id LIMIT %s''', (), limit)
        for candidate in rows:
            with transaction.atomic(using=self.db.using):
                row = self.db.one('SELECT * FROM public.product_waitlist_notifications WHERE id=%s FOR UPDATE', (candidate['id'],))
                subscription = self.db.one('SELECT * FROM public.product_waitlist_subscriptions WHERE id=%s FOR UPDATE', (row['subscription_id'],))
                product = self._product(subscription['product_id']) if subscription else None
                event = self.db.one('SELECT * FROM public.product_waitlist_events WHERE id=%s', (row['product_waitlist_event_id'],))
                if not subscription or not product or not event or event['product_id'] != subscription['product_id']:
                    continue
                if row['status'] == 'skipped' or event['status'] == 'skipped':
                    continue
                if row['status'] != 'sent' and (subscription['status'] != 'active' or product['available_stock'] <= 0):
                    continue
                try:
                    count += int(self._record(RESTOCK, row, subscription, product) is not None)
                except (NotEligible, ValueError):
                    continue
        return count

    def _materialize_restock(self, limit):
        count = 0
        movements = self._scan('movements', '''SELECT m.id FROM public.inventory_movements m
            WHERE (m.type='adjustment' OR (m.type='availability' AND m.available_before=0))
              AND m.quantity>0 AND m.available_after>0 AND NOT EXISTS (
                SELECT 1 FROM public.product_waitlist_events e WHERE e.inventory_movement_id=m.id)
              AND m.id>%s ORDER BY m.id LIMIT %s''', (), limit)
        with transaction.atomic(using=self.db.using):
            for movement in movements:
                self.db.execute('''INSERT INTO public.product_waitlist_events
                    (inventory_movement_id,product_id,available_stock,status,created_at,updated_at)
                    SELECT id,product_id,available_after,'pending',%s,%s FROM public.inventory_movements
                    WHERE id=%s ON CONFLICT (inventory_movement_id) DO NOTHING''', (_now(), _now(), movement['id']))
        # One locked event and one bounded recipient slice per invocation.
        with transaction.atomic(using=self.db.using):
            event = self.db.one('''SELECT * FROM public.product_waitlist_events WHERE status='pending'
                AND materialized_at IS NULL ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED''')
            if not event:
                return count
            product = self._product(event['product_id'], lock=True)
            at = _now()
            if not product or product['available_stock'] <= 0:
                self.db.execute('''UPDATE public.product_waitlist_events SET status='skipped',
                    materialized_at=%s,skipped_at=%s,updated_at=%s WHERE id=%s''', (at, at, at, event['id']))
                return count
            rows = self.db.rows('''SELECT s.* FROM public.product_waitlist_subscriptions s
                WHERE s.product_id=%s AND s.status='active' AND s.id>%s AND NOT EXISTS (
                    SELECT 1 FROM public.product_waitlist_notifications n WHERE n.subscription_id=s.id
                    AND n.status IN ('pending','sending','failed')) ORDER BY s.id LIMIT %s''',
                (product['id'], event['materialization_cursor'] or 0, limit + 1))
            batch = rows[:limit]
            for subscription in batch:
                try:
                    self._address(subscription)
                except (NotEligible, ValueError):
                    continue
                count += self.db.execute('''INSERT INTO public.product_waitlist_notifications
                    (product_waitlist_event_id,subscription_id,status,created_at,updated_at)
                    VALUES (%s,%s,'pending',%s,%s) ON CONFLICT (product_waitlist_event_id,subscription_id) DO NOTHING''',
                    (event['id'], subscription['id'], at, at))
            more = len(rows) > limit
            self.db.execute('''UPDATE public.product_waitlist_events SET materialization_cursor=%s,
                status=%s,materialized_at=%s,updated_at=%s WHERE id=%s''',
                (batch[-1]['id'] if batch else event['materialization_cursor'],
                 'pending' if more else 'complete', None if more else at, at, event['id']))
        return count

    def payload(self, effect):
        kind = effect['effect_type']
        if (kind not in MODELS or effect['source_type'] != 'App\\Models\\' + MODELS[kind]
                or effect['target_type'] != 'mailbox' or effect['telegram_method'] != 'smtp.send'):
            raise NotEligible()
        if kind == CONFIRMATION:
            subscription = self.db.one('SELECT * FROM public.product_waitlist_subscriptions WHERE id=%s', (effect['source_id'],))
            if (not subscription or subscription['status'] != 'pending_confirmation'
                    or not subscription['confirmation_expires_at'] or subscription['confirmation_expires_at'] <= _now()):
                raise NotEligible()
            if self.db.one('SELECT id FROM public.products WHERE id=%s', (subscription['product_id'],)) is None:
                raise NotEligible()
        else:
            row = self.db.one('SELECT * FROM public.product_waitlist_notifications WHERE id=%s', (effect['source_id'],))
            if not row or row['status'] in ('sent', 'skipped'):
                raise NotEligible()
            subscription = self.db.one('SELECT * FROM public.product_waitlist_subscriptions WHERE id=%s', (row['subscription_id'],))
            event = self.db.one('SELECT * FROM public.product_waitlist_events WHERE id=%s', (row['product_waitlist_event_id'],))
            product = self._product(subscription['product_id']) if subscription else None
            if (not subscription or subscription['status'] != 'active' or not event or event['status'] == 'skipped'
                    or event['product_id'] != subscription['product_id'] or not product or product['available_stock'] <= 0):
                raise NotEligible()
        if effect['telegram_chat_id'] != subscription['email_hash']:
            raise NotEligible()
        metadata = effect.get('outcome_metadata') or {}
        if isinstance(metadata, str):
            metadata = json.loads(metadata)
        if kind == CONFIRMATION:
            current = str(int(subscription['confirmation_expires_at'].replace(tzinfo=ZoneInfo('Asia/Jakarta')).timestamp()))
            if metadata.get('confirmation_expires_at') != current:
                raise NotEligible()
        if metadata.get('delivery_context') != 'waitlist_mail':
            raise NotEligible()
        snapshot = json.loads(self.encrypter.decrypt_string(metadata['mail_snapshot']))
        if set(snapshot) != {'recipient', 'subject', 'text', 'html'} or any(
                not isinstance(value, str) for value in snapshot.values()):
            raise NotEligible()
        self.recipients.mail(snapshot['recipient'])
        if not hmac.compare_digest(waitlist_email_hash(snapshot['recipient'], self.signing_key,
                waitlist_key=self.waitlist_key), subscription['email_hash']):
            raise NotEligible()
        marker = '__LHIE_WAITLIST_UNSUBSCRIBE_URL__'
        if kind == RESTOCK:
            if snapshot['text'].count(marker) != 1 or snapshot['html'].count(marker) != 1:
                raise NotEligible()
            url = self.signed_url('unsubscribe', subscription['id'],
                                  _now() + timedelta(days=self.unsubscribe_days))
            snapshot['text'] = snapshot['text'].replace(marker, url)
            snapshot['html'] = snapshot['html'].replace(marker, escape(url, quote=True))
        elif marker in snapshot['text'] or marker in snapshot['html']:
            raise NotEligible()
        return snapshot

    def _capture_attempt(self, effect, payload):
        encrypted = self.encrypter.encrypt_string(json.dumps(payload, ensure_ascii=False))
        return self.db.execute('''UPDATE public.telegram_outbound_effects SET outcome_metadata=
            (COALESCE(outcome_metadata::jsonb,'{}'::jsonb) ||
             jsonb_build_object('mail_attempt_snapshot',%s))::json
            WHERE id=%s AND state='sending' AND claim_token=%s''',
            (encrypted, effect['id'], effect['claim_token'])) == 1

    def deliver(self, effect_id):
        self._outside()
        if not self.enabled:
            return 'disabled'
        effect = self.protocol.claim(effect_id)
        if effect is None:
            return 'not_ready'
        token = effect['claim_token']
        try:
            payload = self.payload(effect)
        except Exception:
            row = self.protocol.finalize_failed(effect_id, token, 'waitlist_not_eligible')
        else:
            try:
                if not self._capture_attempt(effect, payload):
                    return 'fenced'
                self.transport.send(payload['recipient'], payload['subject'], payload['text'], payload['html'])
            except DefiniteMailFailure:
                row = self.protocol.finalize_failed(effect_id, token, 'mail_delivery_failed', retry_after_seconds=60)
            except Exception:
                row = self.protocol.finalize_unknown(effect_id, token)
            else:
                # SMTP acceptance has no Telegram message id. A constant positive
                # acceptance marker satisfies the shared protocol ACK contract.
                row = self.protocol.finalize_sent(effect_id, token, 1)
        if not row:
            return 'fenced'
        self.project(row)
        return row['state']

    def project(self, effect):
        kind = effect['effect_type']
        if kind not in MODELS or effect['source_type'] != 'App\\Models\\' + MODELS[kind]:
            return 0
        if effect['state'] not in ('sent', 'failed', 'unknown', 'manual_review'):
            return 0
        at = _now()
        error = ('mail_delivery_unknown' if effect['state'] in ('unknown', 'manual_review') else
                 'mail_delivery_failed' if effect['state'] == 'failed' else None)
        with transaction.atomic(using=self.db.using):
            if kind == CONFIRMATION:
                # Old expiry generations must never overwrite the current request.
                row = self.db.one('SELECT * FROM public.product_waitlist_subscriptions WHERE id=%s FOR UPDATE', (effect['source_id'],))
                if not row or row['email_hash'] != effect['telegram_chat_id'] or not row['confirmation_expires_at']:
                    return 0
                metadata = effect['outcome_metadata']
                if isinstance(metadata, str):
                    metadata = json.loads(metadata)
                expires = str(int(row['confirmation_expires_at'].replace(tzinfo=ZoneInfo('Asia/Jakarta')).timestamp()))
                if metadata.get('confirmation_expires_at') != expires:
                    return 0
                if row['confirmation_sent_at'] is not None and (
                        effect['state'] != 'sent' or effect['sent_at'] is None
                        or effect['sent_at'] <= row['confirmation_sent_at']):
                    return 0
                return self.db.execute('''UPDATE public.product_waitlist_subscriptions SET confirmation_sent_at=%s,
                    confirmation_delivery_started_at=%s,confirmation_attempts=%s,confirmation_last_error=%s,
                    updated_at=%s WHERE id=%s AND (confirmation_sent_at IS DISTINCT FROM %s
                    OR confirmation_attempts<>%s OR confirmation_last_error IS DISTINCT FROM %s)''',
                    (effect['sent_at'], at if error == 'mail_delivery_unknown' else None, effect['attempts'], error,
                     at, row['id'], effect['sent_at'], effect['attempts'], error))
            row = self.db.one('SELECT * FROM public.product_waitlist_notifications WHERE id=%s FOR UPDATE', (effect['source_id'],))
            if not row:
                return 0
            subscription = self.db.one('SELECT * FROM public.product_waitlist_subscriptions WHERE id=%s FOR UPDATE', (row['subscription_id'],))
            if not subscription or subscription['email_hash'] != effect['telegram_chat_id']:
                return 0
            status = ('sent' if effect['state'] == 'sent' else 'skipped'
                      if effect.get('last_error_code') == 'waitlist_not_eligible' else 'failed')
            changed = self.db.execute('''UPDATE public.product_waitlist_notifications SET status=%s,sent_at=%s,
                delivery_started_at=%s,failed_at=%s,next_attempt_at=%s,attempts=%s,last_error=%s,updated_at=%s
                WHERE id=%s AND status<>'sent' AND (status IS DISTINCT FROM %s OR attempts<>%s
                  OR last_error IS DISTINCT FROM %s OR sent_at IS DISTINCT FROM %s)''',
                (status, effect['sent_at'], at if error == 'mail_delivery_unknown' else None, effect['failed_at'],
                 effect['next_attempt_at'], effect['attempts'], error, at, row['id'], status, effect['attempts'], error, effect['sent_at']))
            if status == 'sent':
                changed += self.db.execute('''UPDATE public.product_waitlist_subscriptions SET status='notified',
                    notified_at=%s,updated_at=%s WHERE id=%s AND status='active' ''',
                    (effect['sent_at'], at, subscription['id']))
            return changed

    def recover(self, limit=25):
        self._outside()
        self._limit(limit)
        if not self.enabled:
            return {'disabled': True}
        expired = self.protocol.recover_expired_leases(limit)
        count = self.materialize(limit)
        cursor = self._cursors.get('projections')
        rows = self.db.rows('''SELECT e.* FROM public.telegram_outbound_effects e
            WHERE e.effect_type=ANY(%s) AND e.state IN ('sent','failed','unknown','manual_review')
              AND (%s::uuid IS NULL OR e.id>%s::uuid)
              AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects newer
                WHERE newer.effect_type=e.effect_type AND newer.source_type=e.source_type
                  AND newer.source_id=e.source_id AND newer.state='sent'
                  AND (newer.created_at,newer.id)>(e.created_at,e.id))
            ORDER BY e.id LIMIT %s''', (list(LIMITS), cursor, cursor, limit))
        self._cursors['projections'] = str(rows[-1]['id']) if rows else None
        repaired = sum(self.project(effect) for effect in rows)
        outcomes = {}
        for effect in self.protocol.ready_effects(limit):
            outcome = self.deliver(effect['id'])
            outcomes[outcome] = outcomes.get(outcome, 0) + 1
        return {'expired_unknown': expired, 'materialized': count, 'projections_repaired': repaired,
                'outcomes': outcomes}
