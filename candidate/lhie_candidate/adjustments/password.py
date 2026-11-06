"""Durable request-bound owner password confirmation for IDR adjustments."""
import json
import re
from datetime import timedelta
from uuid import uuid4
from zoneinfo import ZoneInfo
from django.db import connections, transaction
from django.utils import timezone

from ..auth.admin import allowed
from ..auth.service import hash_algorithm
from ..auth.types import legacy_now
from ..checkout.db import Database
from ..compat.identity import sha256_digest, verify_legacy_password


class AdjustmentRejected(ValueError):
    def __init__(self, code):
        self.code = code
        super().__init__(code)


def owner(request, db, *, lock=False):
    session = getattr(request, 'auth_session', None)
    if session is None or not session.exists or not session.token:
        raise AdjustmentRejected('authenticated_session_required')
    marker = session.data.get('admin')
    if not isinstance(marker, dict) or type(marker.get('id')) is not int:
        raise AdjustmentRejected('owner_required')
    suffix = ' FOR SHARE' if lock else ''
    actor = db.one('SELECT id,email,name,password,role FROM public.users WHERE id=%s' + suffix,
                   (marker['id'],))
    if (not allowed(actor) or actor['role'] != 'owner'
            or marker.get('password_digest') != sha256_digest(actor['password'])):
        raise AdjustmentRejected('owner_required')
    session_row = db.one(
        'SELECT metadata FROM django_candidate.browser_sessions WHERE session_digest=%s AND expires_at>%s'
        + (' FOR SHARE' if lock else ''),
        (sha256_digest(session.token), timezone.now()),
    )
    metadata = session_row['metadata'] if session_row else None
    if isinstance(metadata, str):
        metadata = json.loads(metadata)
    if not isinstance(metadata, dict) or metadata.get('admin') != marker:
        raise AdjustmentRejected('authenticated_session_required')
    return actor


def bound_request(db, request, actor, request_id, fingerprint, version, *, lock=False):
    row = db.one('SELECT * FROM public.balance_adjustment_requests WHERE id=%s' +
                 (' FOR UPDATE' if lock else ''), (request_id,))
    if (row is None or row['owner_user_id'] != actor['id'] or type(version) is not int
            or row['version'] != version or row['fingerprint'] != fingerprint
            or row['session_binding'] != sha256_digest(request.auth_session.token)
            or row['credential_binding'] != sha256_digest(actor['password'])):
        raise AdjustmentRejected('preview_binding_mismatch')
    return row


def record_notice(db, source_id, target, chat_id, message, *, locale='id'):
    db.require_transaction()
    if (target not in ('customer', 'owner') or not isinstance(chat_id, str)
            or not re.fullmatch(r'-?[1-9][0-9]{0,18}', chat_id) or not message):
        raise AdjustmentRejected('notification_unavailable')
    key = f'balance-adjustment:{source_id}:{target}:v1'
    now = legacy_now()
    db.execute('''INSERT INTO public.telegram_outbound_effects
        (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
         telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,
         next_attempt_at,outcome_metadata,created_at,updated_at)
        VALUES (%s,%s,%s,1,'balance.adjustment_notice','App\\Models\\BalanceAdjustmentRequest',
                %s,%s,%s,'telegram-outbound','sendMessage',%s,'balance-adjustment-v1','pending',0,%s,%s,%s,%s)
        ON CONFLICT (effect_key) DO NOTHING''',
        (str(uuid4()), key, key, str(source_id), 'admin' if target == 'owner' else 'customer',
         chat_id, locale, now, json.dumps({'delivery_context': 'balance_adjustment', 'message': message}), now, now))
    existing = db.one('SELECT effect_type,source_id,target_type,telegram_chat_id,outcome_metadata FROM public.telegram_outbound_effects WHERE effect_key=%s', (key,))
    metadata = existing['outcome_metadata']
    if isinstance(metadata, str):
        metadata = json.loads(metadata)
    if (existing['effect_type'] != 'balance.adjustment_notice' or existing['source_id'] != str(source_id)
            or existing['target_type'] != ('admin' if target == 'owner' else 'customer')
            or existing['telegram_chat_id'] != chat_id or metadata.get('message') != message):
        raise AdjustmentRejected('notification_identity_conflict')


class PasswordConfirmation:
    def __init__(self, *, admin_chat_id, using='default'):
        self.using = using
        self.db = Database(using)
        self.admin_chat_id = admin_chat_id

    def verify(self, request, request_id, fingerprint, version, password, *, binder=bound_request):
        connection = connections[self.using]
        if connection.vendor != 'postgresql' or connection.in_atomic_block:
            raise AdjustmentRejected('authentication_requires_independent_transaction')
        if not isinstance(self.admin_chat_id, str) or not re.fullmatch(r'[1-9][0-9]{0,18}', self.admin_chat_id):
            raise AdjustmentRejected('owner_notification_unavailable')
        actor = owner(request, self.db)
        preview = binder(self.db, request, actor, request_id, fingerprint, version)
        token = str(uuid4())
        with transaction.atomic(using=self.using):
            self.db.execute("INSERT INTO public.balance_adjustment_auth_states (user_id,failed_at) VALUES (%s,'[]') ON CONFLICT DO NOTHING", (actor['id'],))
            state = self.db.one('SELECT * FROM public.balance_adjustment_auth_states WHERE user_id=%s FOR UPDATE', (actor['id'],))
            now = legacy_now()
            if state['locked_until'] and now < state['locked_until']:
                raise AdjustmentRejected('owner_locked')
            if state['lease_until'] and now < state['lease_until']:
                raise AdjustmentRejected('confirmation_busy')
            self.db.execute('''UPDATE public.balance_adjustment_auth_states SET lease_token=%s,lease_until=%s,
                verified_request_id=NULL,verified_fingerprint=NULL WHERE user_id=%s''',
                (token, now + timedelta(seconds=30), actor['id']))
        algorithm = hash_algorithm(actor['password'])
        valid = (isinstance(password, str) and len(password.encode('utf-8')) <= 1024 and bool(algorithm)
                 and verify_legacy_password(password, actor['password'], algorithm=algorithm))
        with transaction.atomic(using=self.using):
            state = self.db.one('SELECT * FROM public.balance_adjustment_auth_states WHERE user_id=%s FOR UPDATE', (actor['id'],))
            now = legacy_now()
            if str(state['lease_token']) != token or not state['lease_until'] or now >= state['lease_until']:
                raise AdjustmentRejected('stale_authentication')
            failures = state['failed_at']
            if isinstance(failures, str):
                failures = json.loads(failures)
            timestamp = int(now.replace(tzinfo=ZoneInfo('Asia/Jakarta')).timestamp())
            failures = [t for t in failures if type(t) is int and t > timestamp - 900]
            if not valid:
                failures.append(timestamp)
            locked = now + timedelta(minutes=15) if len(failures) >= 5 else None
            episode = str(uuid4()) if locked else None
            if episode:
                values = json.dumps({'episode': episode, 'locked_until': locked.isoformat()})
                self.db.execute('''INSERT INTO public.audit_logs(user_id,action,new_values,created_at)
                    VALUES (%s,'balance.adjustment.lockout',%s,%s)''', (actor['id'], values, now))
                record_notice(self.db, 'lockout:' + episode, 'owner', self.admin_chat_id,
                              'Konfirmasi saldo Owner dikunci selama 15 menit setelah lima password salah.')
            self.db.execute('''UPDATE public.balance_adjustment_auth_states SET failed_at=%s,locked_until=%s,episode=%s,
                lease_token=%s,lease_until=%s,verified_request_id=%s,verified_fingerprint=%s WHERE user_id=%s''',
                (json.dumps(failures), locked, episode, token if valid else None,
                 state['lease_until'] if valid else None, preview['id'] if valid else None,
                 preview['fingerprint'] if valid else None, actor['id']))
        if not valid:
            raise AdjustmentRejected('invalid_password')
        return token

    def consume(self, actor_id, token, request_id, fingerprint):
        self.db.require_transaction()
        state = self.db.one('SELECT * FROM public.balance_adjustment_auth_states WHERE user_id=%s FOR UPDATE', (actor_id,))
        now = legacy_now()
        if (state is None or str(state['lease_token']) != token or not state['lease_until']
                or now >= state['lease_until'] or (state['locked_until'] and now < state['locked_until'])
                or str(state['verified_request_id']) != str(request_id) or state['verified_fingerprint'] != fingerprint):
            raise AdjustmentRejected('stale_authentication')
        self.db.execute('''UPDATE public.balance_adjustment_auth_states SET lease_token=NULL,lease_until=NULL,
            verified_request_id=NULL,verified_fingerprint=NULL WHERE user_id=%s''', (actor_id,))

    def release(self, actor_id, token):
        self.db.execute('''UPDATE public.balance_adjustment_auth_states SET lease_token=NULL,lease_until=NULL,
            verified_request_id=NULL,verified_fingerprint=NULL WHERE user_id=%s AND lease_token=%s''', (actor_id, token))
