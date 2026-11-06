"""Durable customer USDT funding, using the existing PostgreSQL authorities.

Readiness is an explicit local configuration dependency, never inferred from a
fixture or from the existence of an adapter. Provider calls happen after leases
have committed and results are fenced again before any monetary effect.
"""
import hashlib
import json
import re
import secrets
from datetime import datetime, timedelta
from uuid import uuid4
from zoneinfo import ZoneInfo

from django.db import connections, transaction

from lhie_candidate.checkout.db import Database
from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.domain.money import MAX_UNITS, require_integer
from lhie_candidate.payments.binance import _ms, normalize_order_id
from lhie_candidate.payments.bsc import CHAIN_ID, USDT_CONTRACT, normalize_hash
from lhie_candidate.payments.receipts import claim_usdt_binance_top_up, claim_usdt_bsc_top_up
from .ledger import UsdtLedger


def _now():
    return datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)


def _json(value):
    return json.loads(value) if isinstance(value, str) else value


def _hash(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=True, separators=(',', ':')).replace('/', '\\/').encode()).hexdigest()


class FundingService:
    def __init__(self, using='default', readiness=None, *, binance=None, bsc=None, clock=None):
        self.db = Database(using)
        self.using = using
        self.readiness = readiness or {}
        self.binance = binance
        self.bsc = bsc
        self.clock = clock or _now
        self.ledger = UsdtLedger(using)

    def _policy(self, lock=False):
        return self.db.one("SELECT * FROM public.customer_usdt_funding_policies WHERE singleton_key='customer_usdt_funding_policy'" + (' FOR UPDATE' if lock else ''))

    def availability(self, rail):
        if rail not in ('binance_pay', 'bsc_usdt') or self.readiness.get(rail) is not True:
            return False
        policy = self._policy()
        if not policy or not policy['funding_enabled']:
            return False
        restore = self.db.one('SELECT state FROM public.balance_restore_gates WHERE id=1' +
                              (' FOR SHARE' if connections[self.using].in_atomic_block else ''))
        if not restore or restore['state'] != 'cleared':
            return False
        return not bool(self.db.one('''SELECT id FROM public.customer_usdt_funding_pauses
            WHERE scope IN ('funding', %s) LIMIT 1''', ('rail_' + rail,)))

    def presets(self):
        policy = self._policy()
        if not policy:
            return (None, [])
        values = _json(policy['preset_amounts_micro'])
        if not isinstance(values, list) or len(values) > 8 or any(type(v) is not int or v < 1 or v > MAX_UNITS for v in values):
            return (policy['version'], [])
        return (policy['version'], values)

    def _root(self, customer_id, chat_id, root_id, message_id):
        if type(chat_id) is not str or re.fullmatch(r'[1-9][0-9]*', chat_id) is None:
            return None
        customer = self.db.one('SELECT id,telegram_id FROM public.customers WHERE id=%s FOR UPDATE', (customer_id,))
        if not customer or str(customer['telegram_id']) != chat_id:
            return None
        candidate = self.db.one('''SELECT * FROM django_candidate.navigation_roots
            WHERE bot_asset_scope=%s AND customer_id=%s AND chat_id=%s AND message_id=%s
              AND rendered_at IS NOT NULL AND render_token IS NULL FOR UPDATE''',
            (root_id, customer_id, chat_id, message_id))
        if not candidate or not candidate['media_kind'] or not candidate['screen']:
            return None
        now = self.clock()
        return self.db.one('''INSERT INTO public.telegram_navigation_roots
            (customer_id,chat_id,message_id,media_kind,screen,locale,banner_reference,generation,rendered_at,created_at,updated_at)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            ON CONFLICT (customer_id) DO UPDATE SET chat_id=EXCLUDED.chat_id,message_id=EXCLUDED.message_id,
              media_kind=EXCLUDED.media_kind,screen=EXCLUDED.screen,locale=EXCLUDED.locale,
              banner_reference=EXCLUDED.banner_reference,generation=EXCLUDED.generation,
              rendered_at=EXCLUDED.rendered_at,updated_at=EXCLUDED.updated_at
            WHERE public.telegram_navigation_roots.render_token IS NULL RETURNING id,generation''',
            (customer_id, chat_id, message_id, candidate['media_kind'], candidate['screen'], candidate['locale'],
             candidate['banner_reference'], candidate['generation'], candidate['rendered_at'], now, now))

    def open_confirmed(self, customer_id, chat_id, root_id, message_id, amount_micro, rail, policy_version=None):
        require_integer(customer_id, minimum=1)
        require_integer(amount_micro, minimum=1)
        with transaction.atomic(using=self.using):
            self.db.require_transaction()
            root = self._root(customer_id, chat_id, root_id, message_id)
            if root is None:
                return {'status': 'stale'}
            active = self.db.one('''SELECT t.* FROM public.customer_usdt_funding_reservations r
                LEFT JOIN public.customer_usdt_top_ups t ON t.reservation_id=r.id
                WHERE r.customer_id=%s AND r.resolved_at IS NULL FOR UPDATE OF r''', (customer_id,))
            if active:
                return {'status': 'active_target', 'target': active if active['id'] else None}
            account = self.db.one('''SELECT id,frozen_reason FROM public.customer_usdt_balance_accounts
                WHERE customer_id=%s FOR UPDATE''', (customer_id,))
            if account is not None:
                from .reconciliation import account_health
                health = account_health(self.db, account_id=account['id'])
                if account['frozen_reason'] is not None or health is None or health['issues']:
                    return {'status': 'unavailable'}
            policy = self._policy(True)
            if not policy or not self.availability(rail):
                return {'status': 'unavailable'}
            if policy_version is not None and (type(policy_version) is not int or policy_version != policy['version']):
                return {'status': 'stale_policy'}
            settings = self.db.one('''SELECT id,binance_pay_enabled,binance_payment_ttl_minutes,
                bsc_payment_ttl_minutes,bsc_min_confirmations
                FROM public.manual_payment_settings WHERE is_active=true ORDER BY id LIMIT 1 FOR UPDATE''')
            if not settings:
                return {'status': 'unavailable'}
            fee = 0 if rail == 'binance_pay' else policy['application_fee_micro']
            if type(fee) is not int or fee < 0 or fee > MAX_UNITS - amount_micro:
                return {'status': 'unavailable'}
            receiver_id = None
            if rail == 'binance_pay':
                if not settings['binance_pay_enabled'] or self.binance is None:
                    return {'status': 'unavailable'}
                pay_id, uid = self.binance.pay_id, self.binance.pay_uid
                if not pay_id and not uid:
                    return {'status': 'unavailable'}
                ttl = settings['binance_payment_ttl_minutes'] * 60
                snapshot = {'receiver_pay_id': pay_id, 'receiver_uid': uid, 'payment_ttl_seconds': ttl}
            else:
                receiver = self.db.one('''SELECT * FROM public.customer_usdt_deposit_receivers WHERE customer_id=%s
                    AND verified_at IS NOT NULL AND retired_at IS NULL ORDER BY id LIMIT 1 FOR UPDATE''', (customer_id,))
                if (not receiver or receiver['chain_id'] != CHAIN_ID or receiver['token_contract'].lower() != USDT_CONTRACT
                        or receiver['token_decimals'] != 18 or self.bsc is None
                        or self.db.one('SELECT receiver_id FROM public.usdt_bsc_scan_checkpoints WHERE receiver_id=%s AND review_reason IS NOT NULL', (receiver['id'],))):
                    return {'status': 'unavailable'}
                receiver_id = receiver['id']
                ttl = settings['bsc_payment_ttl_minutes'] * 60
                snapshot = {'receiver_address': receiver['address_normalized'], 'chain_id': receiver['chain_id'],
                    'token_contract': receiver['token_contract'], 'token_decimals': receiver['token_decimals'],
                    'custody_namespace': receiver['custody_namespace'], 'minimum_confirmations': max(3, settings['bsc_min_confirmations']),
                    'requires_finalized_block': True, 'payment_ttl_seconds': ttl}
            if type(ttl) is not int or ttl <= 0 or ttl > 86400:
                return {'status': 'unavailable'}
            now = self.clock().replace(microsecond=0)
            deadline = now + timedelta(seconds=ttl)
            self.db.execute("UPDATE public.customer_usdt_funding_intents SET state=CASE WHEN expires_at<=%s THEN 'expired' ELSE 'superseded' END,version=version+1,claimed_at=%s WHERE customer_id=%s AND state='pending'", (now, now, customer_id))
            nonce = secrets.token_hex(16)
            identity = {'customer_id': customer_id, 'telegram_navigation_root_id': root['id'], 'chat_id': chat_id,
                'canonical_message_id': message_id, 'currency': 'USDT', 'rail': rail, 'amount_micro': amount_micro,
                'fee_micro': fee, 'payable_micro': amount_micro + fee, 'policy_version': policy['version'],
                'terms_version': policy['terms_version'], 'receiver_id': receiver_id, 'shared_receiver_id': None,
                'payment_snapshot': snapshot}
            fingerprint = _hash({'v': 1, 'identity': identity, 'nonce': nonce,
                                 'expires_at_epoch': int((now + timedelta(seconds=policy['confirmation_ttl_seconds'])).replace(tzinfo=ZoneInfo('Asia/Jakarta')).timestamp())})
            intent = self.db.one('''INSERT INTO public.customer_usdt_funding_intents
                (customer_id,telegram_navigation_root_id,chat_id,canonical_message_id,root_generation,nonce,currency,rail,
                 amount_micro,fee_micro,payable_micro,policy_version,terms_version,receiver_id,payment_snapshot,
                 request_fingerprint,state,version,expires_at,created_at,claimed_at)
                VALUES (%s,%s,%s,%s,%s,%s,'USDT',%s,%s,%s,%s,%s,%s,%s,%s,%s,'confirmed',2,%s,%s,%s) RETURNING id''',
                (customer_id, root['id'], chat_id, message_id, root['generation'], nonce, rail, amount_micro, fee,
                 amount_micro + fee, policy['version'], policy['terms_version'], receiver_id, json.dumps(snapshot), fingerprint,
                 now + timedelta(seconds=policy['confirmation_ttl_seconds']), now, now))
            target_id = str(uuid4())
            reservation_fp = _hash(dict(version=1, variant='customer_usdt_funding_reservation', customer_id=customer_id,
                rail=rail, amount_micro=amount_micro, fee_micro=fee, policy_version=policy['version'],
                terms_version=policy['terms_version'], source_type='customer_usdt_top_up', source_id=target_id))
            reservation = self.db.one('''INSERT INTO public.customer_usdt_funding_reservations
                (customer_id,source_type,source_id,rail,amount_micro,fee_micro,policy_version,terms_version,
                 request_fingerprint,state,reserved_at,expires_at,created_at,updated_at)
                VALUES (%s,'customer_usdt_top_up',%s,%s,%s,%s,%s,%s,%s,'reserved',%s,%s,%s,%s) RETURNING id''',
                (customer_id, target_id, rail, amount_micro, fee, policy['version'], policy['terms_version'], reservation_fp, now, deadline, now, now))
            target = self.db.one('''INSERT INTO public.customer_usdt_top_ups
                (id,intent_id,reservation_id,customer_id,reference,currency,rail,amount_micro,fee_micro,payable_micro,
                 policy_version,terms_version,receiver_id,payment_snapshot,request_fingerprint,expires_at,created_at)
                VALUES (%s,%s,%s,%s,%s,'USDT',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING *''',
                (target_id, intent['id'], reservation['id'], customer_id, 'usdt:' + target_id, rail, amount_micro, fee,
                 amount_micro + fee, policy['version'], policy['terms_version'], receiver_id, json.dumps(snapshot), fingerprint, deadline, now))
            self.db.execute('''INSERT INTO public.customer_usdt_top_up_work
                (top_up_id,state,version,attempts,next_attempt_at,updated_at) VALUES (%s,%s,1,0,%s,%s)''',
                (target_id, 'ready' if rail == 'binance_pay' else 'pending', now, now))
            self.db.execute('''INSERT INTO public.customer_usdt_top_up_settlement_work
                (top_up_id,next_check_at,updated_at) VALUES (%s,%s,%s)''', (target_id, now, now))
            target['payment_snapshot'] = snapshot
            return {'status': 'confirmed', 'target': target}

    def status(self, customer_id, top_up_id):
        row = self.db.one('''SELECT t.*, w.state AS work_state, p.state AS preparation_state,
            r.kind AS resolution_kind, e.id AS entry_id FROM public.customer_usdt_top_ups t
            LEFT JOIN public.customer_usdt_top_up_work p ON p.top_up_id=t.id
            LEFT JOIN public.customer_usdt_top_up_settlement_work w ON w.top_up_id=t.id
            LEFT JOIN public.customer_usdt_top_up_resolutions r ON r.top_up_id=t.id
            LEFT JOIN public.customer_usdt_balance_entries e ON e.id=r.entry_id
              AND e.kind='funding_credit' AND e.source_type='customer_usdt_top_up' AND e.source_id=t.id::text
              AND EXISTS (SELECT 1 FROM public.customer_usdt_balance_accounts a
                          WHERE a.id=e.customer_usdt_balance_account_id AND a.customer_id=t.customer_id)
            WHERE t.id=%s AND t.customer_id=%s''', (str(top_up_id), customer_id))
        if row:
            row['payment_snapshot'] = _json(row['payment_snapshot'])
            if row['work_state'] == 'manual_review' and row['resolution_kind'] in (None, 'expired_unpaid', 'cancelled_by_customer'):
                row['state'] = 'manual_review'
            elif row['resolution_kind']:
                row['state'] = row['resolution_kind']
                if row['state'] in ('settled', 'resolved_by_compensation') and not row['entry_id']:
                    row['state'] = 'manual_review'
            elif row['work_state'] == 'settled':
                row['state'] = 'manual_review'
            elif row['preparation_state'] == 'expired_unresolved' or row['work_state'] == 'expired_unresolved' or row['expires_at'] <= self.clock():
                row['state'] = 'expired_unresolved'
            elif row['preparation_state'] in (None, 'pending', 'creating', 'creation_unknown'):
                row['state'] = 'preparing'
            else:
                row['state'] = 'awaiting_payment'
        return row

    def cancel(self, customer_id, top_up_id):
        return self._close(customer_id, top_up_id, expired=False)

    def cancel_open(self, customer_id):
        allowed = self.db.one("SELECT has_column_privilege(current_user,'public.customer_usdt_top_ups','id','SELECT') AS allowed")
        if not allowed['allowed']:
            return False
        row = self.db.one('''SELECT t.id FROM public.customer_usdt_top_ups t
            JOIN public.customer_usdt_funding_reservations r ON r.id=t.reservation_id
            WHERE t.customer_id=%s AND r.resolved_at IS NULL ORDER BY t.created_at,t.id LIMIT 1''', (customer_id,))
        return bool(row) and self.cancel(customer_id, row['id'])

    def _close(self, customer_id, top_up_id, *, expired):
        now = self.clock()
        with transaction.atomic(using=self.using):
            self.db.require_transaction()
            if not self.db.one('SELECT id FROM public.customers WHERE id=%s FOR UPDATE', (customer_id,)):
                return False
            self.db.one('SELECT id FROM public.customer_usdt_balance_accounts WHERE customer_id=%s FOR UPDATE', (customer_id,))
            target = self.db.one('SELECT * FROM public.customer_usdt_top_ups WHERE id=%s AND customer_id=%s FOR UPDATE', (str(top_up_id), customer_id))
            if not target:
                return False
            works = [self.db.one(f'SELECT * FROM public.{table} WHERE top_up_id=%s FOR UPDATE', (str(top_up_id),))
                     for table in ('customer_usdt_top_up_work', 'customer_usdt_top_up_settlement_work')]
            reservation = self.db.one('SELECT * FROM public.customer_usdt_funding_reservations WHERE id=%s FOR UPDATE', (target['reservation_id'],))
            if not reservation or reservation['resolved_at'] is not None or self.db.one('SELECT top_up_id FROM public.customer_usdt_top_up_resolutions WHERE top_up_id=%s', (str(top_up_id),)):
                return False
            if any(w and (w['state'] in ('settled', 'manual_review', 'externally_refunded', 'resolved_by_compensation', 'rejected') or (w['lease_until'] and w['lease_until'] > now)) for w in works):
                return False
            if expired and target['expires_at'] + timedelta(minutes=60) > now:
                if reservation['state'] == 'reserved':
                    self.db.execute("""UPDATE public.customer_usdt_funding_reservations
                        SET state='expired_unresolved',updated_at=%s WHERE id=%s""",
                        (now, reservation['id']))
                return False
            kind = 'expired_unpaid' if expired else 'cancelled_by_customer'
            self.db.execute('''INSERT INTO public.customer_usdt_top_up_resolutions
                (top_up_id,kind,late,created_at) VALUES (%s,%s,false,%s)''', (str(top_up_id), kind, now))
            self.db.execute('''UPDATE public.customer_usdt_funding_reservations SET state='released',resolved_at=%s,
                resolution_kind=%s,resolution_reference=%s,resolution_actor=%s,updated_at=%s WHERE id=%s''',
                (now, kind, ('expiry-sweep:' if expired else 'customer-cancel:') + str(top_up_id),
                 'usdt-funding:release-expired' if expired else 'customer:' + str(customer_id), now, reservation['id']))
            return True

    def release_expired(self, limit=100):
        require_integer(limit, minimum=1)
        if limit > 1000:
            raise ValueError('Funding expiry batch exceeds limit')
        rows = self.db.rows('''SELECT t.id,t.customer_id FROM public.customer_usdt_top_ups t
            JOIN public.customer_usdt_funding_reservations r ON r.id=t.reservation_id
            WHERE r.resolved_at IS NULL AND t.expires_at<=%s
              AND (r.state='reserved' OR t.expires_at<=%s)
              AND NOT EXISTS (SELECT 1 FROM public.customer_usdt_top_up_settlement_work w
                  WHERE w.top_up_id=t.id AND (w.state IN ('settled','manual_review','externally_refunded',
                    'resolved_by_compensation','rejected') OR w.lease_until>%s))
              AND NOT EXISTS (SELECT 1 FROM public.customer_usdt_top_up_work p
                  WHERE p.top_up_id=t.id AND (p.state IN ('settled','manual_review','externally_refunded',
                    'resolved_by_compensation','rejected') OR p.lease_until>%s))
            ORDER BY t.expires_at,t.id LIMIT %s''',
            (self.clock(), self.clock() - timedelta(minutes=60), self.clock(), self.clock(), limit))
        return sum(self._close(row['customer_id'], row['id'], expired=True) for row in rows)
