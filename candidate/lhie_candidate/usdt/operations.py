"""USDT incident containment and owner-bound recovery authorization."""
import hashlib
import json
from datetime import timedelta
from uuid import UUID, uuid4

from django.db import connections, transaction

from lhie_candidate.adjustments.password import AdjustmentRejected, PasswordConfirmation, owner
from lhie_candidate.auth.types import legacy_now
from lhie_candidate.checkout.db import Database
from lhie_candidate.compat.identity import sha256_digest
from lhie_candidate.payments.bsc import _block, _quantity, normalize_hash

_REORG_REASONS = frozenset(('checkpoint_reorg', 'log_block_reorg', 'page_boundary_reorg', 'finalized_block_changed'))


def _outside(using):
    connection = connections[using]
    if connection.vendor != 'postgresql' or connection.in_atomic_block:
        raise RuntimeError('Operation requires PostgreSQL outside a transaction')


def _json(value):
    return json.loads(value) if isinstance(value, str) else value


class ReorgIncidents:
    def __init__(self, rpc, *, admin_chat_id, using='default'):
        self.rpc, self.using = rpc, using
        self.admin_chat_id = admin_chat_id
        self.db = Database(using)
        self.passwords = PasswordConfirmation(admin_chat_id=admin_chat_id, using=using)

    def check(self, top_up_id):
        _outside(self.using)
        target = self.db.one('''SELECT t.id,t.customer_id,t.receiver_id,r.entry_id,r.receipt_id,
            e.evidence_payload FROM public.customer_usdt_top_ups t
            JOIN public.customer_usdt_top_up_resolutions r ON r.top_up_id=t.id
            JOIN public.external_money_receipts e ON e.id=r.receipt_id
            WHERE t.id=%s AND r.entry_id IS NOT NULL AND NOT EXISTS
            (SELECT 1 FROM public.usdt_reorg_incidents i WHERE i.top_up_id=t.id)''', (top_up_id,))
        if not target or target['receiver_id'] is None:
            return False
        original = _json(target['evidence_payload'])
        if not isinstance(original, dict):
            original = {}
        checkpoint = self.db.one('SELECT * FROM public.usdt_bsc_scan_checkpoints WHERE receiver_id=%s', (target['receiver_id'],))
        reason = checkpoint['review_reason'] if checkpoint and checkpoint['review_reason'] in _REORG_REASONS else None
        canonical = None
        if not reason:
            transfer = original.get('transfer')
            chain = transfer if isinstance(transfer, dict) else original
            number, old_hash = chain.get('block_number'), normalize_hash(chain.get('block_hash'))
            if type(number) is not int or number < 0 or old_hash is None:
                return False
            if _quantity(self.rpc.call('eth_chainId')) != 56:
                return False
            first = _block(self.rpc.call('eth_getBlockByNumber', [hex(number), False]))
            if first is None or first[0] != number or first[2] == old_hash:
                return False
            if _block(self.rpc.call('eth_getBlockByNumber', [hex(number), False])) != first:
                return False
            canonical = {'number': first[0], 'timestamp': first[1], 'hash': first[2]}
            reason = 'settled_receipt_block_reorg'
        with transaction.atomic(using=self.using):
            self.db.one('SELECT id FROM public.customers WHERE id=%s FOR UPDATE', (target['customer_id'],))
            account = self.db.one('''SELECT id,customer_id,currency,balance_micro,version,last_entry_id,frozen_reason
                FROM public.customer_usdt_balance_accounts WHERE customer_id=%s FOR UPDATE''', (target['customer_id'],))
            if not account:
                raise ValueError('Missing credited account')
            self.db.one('SELECT id FROM public.customer_usdt_top_ups WHERE id=%s FOR UPDATE', (target['id'],))
            self.db.one('SELECT id FROM public.customer_usdt_deposit_receivers WHERE id=%s FOR UPDATE', (target['receiver_id'],))
            current = self.db.one('SELECT * FROM public.usdt_bsc_scan_checkpoints WHERE receiver_id=%s FOR UPDATE', (target['receiver_id'],))
            if ((current or {}).get('version') != (checkpoint or {}).get('version') or
                    (current or {}).get('review_reason') != (checkpoint or {}).get('review_reason')):
                return False
            resolution = self.db.one('SELECT entry_id,receipt_id FROM public.customer_usdt_top_up_resolutions WHERE top_up_id=%s', (target['id'],))
            if (not resolution or resolution['entry_id'] != target['entry_id'] or resolution['receipt_id'] != target['receipt_id'] or
                    self.db.one('SELECT id FROM public.usdt_reorg_incidents WHERE top_up_id=%s', (target['id'],))):
                return False
            entry = self.db.one('''SELECT amount_micro FROM public.customer_usdt_balance_entries
                WHERE id=%s AND customer_usdt_balance_account_id=%s AND kind='funding_credit' ''', (target['entry_id'], account['id']))
            if not entry:
                raise ValueError('Missing credited entry')
            previous = self.db.one('SELECT COALESCE(SUM(available_exposure_micro::numeric),0) AS amount FROM public.usdt_reorg_incidents WHERE account_id=%s', (account['id'],))
            exposure = entry['amount_micro']
            available = min(exposure, max(0, account['balance_micro'] - int(previous['amount'])))
            receiver_evidence = self.db.one('SELECT evidence FROM public.usdt_reorg_receiver_evidence WHERE receiver_id=%s AND checkpoint_version=%s', (target['receiver_id'], current['version'] if current else None))
            evidence = {'original_receipt': original, 'canonical_block': canonical,
                        'receiver_detection': _json(receiver_evidence['evidence']) if receiver_evidence else None,
                        'checkpoint': {k: current[k] for k in ('last_block', 'last_block_hash', 'review_reason')} if current else None}
            now = legacy_now()
            incident = self.db.one('''INSERT INTO public.usdt_reorg_incidents
                (top_up_id,customer_id,account_id,receipt_id,entry_id,receiver_id,reason,exposure_micro,
                 available_exposure_micro,spent_exposure_micro,account_version,checkpoint_version,evidence,created_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING id''',
                (target['id'], target['customer_id'], account['id'], target['receipt_id'], target['entry_id'],
                 target['receiver_id'], reason, exposure, available, exposure-available, account['version'],
                 current['version'] if current else None, json.dumps(evidence), now))
            self.db.execute('UPDATE public.customer_usdt_balance_accounts SET frozen_reason=%s,updated_at=%s WHERE id=%s AND frozen_reason IS NULL',
                            ('usdt_reorg_incident:' + str(incident['id']), now, account['id']))
            self.db.execute("INSERT INTO public.audit_logs(action,subject_type,subject_id,new_values,created_at) VALUES ('usdt_reorg.detected','App\\Models\\UsdtReorgIncident',%s,%s,%s)",
                            (incident['id'], json.dumps({'incident_id': incident['id'], 'currency': 'USDT', 'exposure_micro': str(exposure), 'spent_exposure_micro': str(exposure-available), 'reason': reason}), now))
            return True

    def recover(self, limit=25):
        _outside(self.using)
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError('Invalid monitor limit')
        monitor = self.db.one('SELECT * FROM public.usdt_reorg_monitor WHERE id=1')
        if not monitor:
            raise ValueError('Missing monitor')
        rows = self.db.rows('SELECT top_up_id,entry_id FROM public.customer_usdt_top_up_resolutions WHERE entry_id>%s ORDER BY entry_id LIMIT %s', (monitor['cursor'], limit))
        detected, errors = 0, 0
        for row in rows:
            try:
                detected += bool(self.check(row['top_up_id']))
            except Exception:
                errors += 1
        self.db.execute('UPDATE public.usdt_reorg_monitor SET cursor=%s,version=version+1,updated_at=%s WHERE id=1 AND version=%s',
                        (rows[-1]['entry_id'] if rows else 0, legacy_now(), monitor['version']))
        from .operations_controls import OperationalControls
        OperationalControls(self.using).evaluate(admin_chat_id=self.admin_chat_id)
        return {'checked': len(rows), 'detected': detected, 'unavailable': errors}

    def request_recovery(self, request, incident_id, nonce, reason):
        _outside(self.using)
        try:
            nonce = str(UUID(str(nonce)))
        except (ValueError, TypeError, AttributeError):
            raise ValueError('Invalid nonce') from None
        if not isinstance(reason, str) or not 1 <= len(reason.strip()) <= 500:
            raise ValueError('Invalid reason')
        reason = reason.strip()
        with transaction.atomic(using=self.using):
            actor = owner(request, self.db, lock=True)
            if not self.db.one('SELECT id FROM public.usdt_reorg_incidents WHERE id=%s', (incident_id,)):
                raise ValueError('Incident missing')
            session = sha256_digest(request.auth_session.token)
            credential = sha256_digest(actor['password'])
            fingerprint = hashlib.sha256(json.dumps([incident_id, actor['id'], nonce, reason, session, credential], ensure_ascii=True, separators=(',', ':')).encode()).hexdigest()
            existing = self.db.one('SELECT * FROM public.usdt_reorg_recovery_requests WHERE owner_user_id=%s AND nonce=%s', (actor['id'], nonce))
            if existing:
                if existing['fingerprint'] != fingerprint:
                    raise ValueError('Nonce conflict')
                return existing
            now = legacy_now()
            return self.db.one('''INSERT INTO public.usdt_reorg_recovery_requests
                (id,incident_id,owner_user_id,nonce,reason,fingerprint,credential_binding,session_binding,expires_at,created_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING *''',
                (str(uuid4()), incident_id, actor['id'], nonce, reason, fingerprint, credential, session, now + timedelta(minutes=10), now))

    @staticmethod
    def _bound(db, request, actor, request_id, fingerprint, version):
        row = db.one('SELECT * FROM public.usdt_reorg_recovery_requests WHERE id=%s', (request_id,))
        if (not row or row['owner_user_id'] != actor['id'] or row['fingerprint'] != fingerprint or version != 1
                or row['expires_at'] <= legacy_now() or row['session_binding'] != sha256_digest(request.auth_session.token)
                or row['credential_binding'] != sha256_digest(actor['password'])):
            raise AdjustmentRejected('preview_binding_mismatch')
        return row

    def confirm_recovery(self, request, request_id, fingerprint, password):
        _outside(self.using)
        actor = owner(request, self.db)
        self._bound(self.db, request, actor, request_id, fingerprint, 1)
        token = self.passwords.verify(request, request_id, fingerprint, 1, password, binder=self._bound)
        try:
            with transaction.atomic(using=self.using):
                actor = owner(request, self.db, lock=True)
                preview = self._bound(self.db, request, actor, request_id, fingerprint, 1)
                self.db.one('SELECT id FROM public.usdt_reorg_incidents WHERE id=%s FOR UPDATE', (preview['incident_id'],))
                self.passwords.consume(actor['id'], token, request_id, fingerprint)
                existing = self.db.one('SELECT * FROM public.usdt_reorg_recovery_cases WHERE incident_id=%s', (preview['incident_id'],))
                if existing:
                    if str(existing['request_id']) != str(request_id):
                        raise ValueError('Incident already authorized')
                    return existing
                now = legacy_now()
                case = self.db.one("INSERT INTO public.usdt_reorg_recovery_cases(incident_id,request_id,authorized_by,status,created_at) VALUES (%s,%s,%s,'authorized_unresolved',%s) RETURNING *", (preview['incident_id'], request_id, actor['id'], now))
                self.db.execute("INSERT INTO public.audit_logs(user_id,action,subject_type,subject_id,new_values,created_at) VALUES (%s,'usdt_reorg.recovery_authorized','App\\Models\\UsdtReorgRecoveryCase',%s,%s,%s)", (actor['id'], case['id'], json.dumps({'incident_id': preview['incident_id'], 'request_id': str(request_id), 'status': 'authorized_unresolved', 'currency': 'USDT'}), now))
                return case
        finally:
            self.passwords.release(actor['id'], token)
