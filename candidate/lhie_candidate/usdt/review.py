"""Owner-confirmed USDT exception resolutions over existing receipt and ledger owners."""
import hashlib
import json
import re
from dataclasses import asdict
from datetime import timedelta
from uuid import UUID, uuid4

from django.db import transaction

from ..adjustments.password import AdjustmentRejected, PasswordConfirmation, owner
from ..auth.types import legacy_now
from ..checkout.db import Database
from ..compat.identity import sha256_digest
from ..domain.money import MAX_UNITS, Money, require_integer
from ..payments.binance import _ms, normalize_order_id
from ..payments.bsc import normalize_hash
from ..payments.receipts import claim_usdt_binance_top_up, claim_usdt_bsc_top_up
from .funding import _json
from .funding_settlement import FundingSettlement, datetime_from_epoch
from .ledger import UsdtLedger
from .operations import _outside
from .outbound import record_topup_receipt


def _identity(value):
    try:
        return str(UUID(str(value)))
    except (ValueError, TypeError, AttributeError):
        raise ValueError('Invalid USDT review identity') from None


def _digest(value):
    encoded = json.dumps(value, sort_keys=True, ensure_ascii=True, separators=(',', ':'))
    return hashlib.sha256(encoded.encode()).hexdigest()


def _static_transfer(observation):
    transfer = asdict(observation.transfer)
    transfer.pop('confirmations', None)
    return transfer


class UsdtTopUpReview:
    def __init__(self, *, admin_chat_id, binance=None, bsc=None, using='default'):
        self.using, self.db = using, Database(using)
        self.binance, self.bsc = binance, bsc
        self.ledger = UsdtLedger(using)
        self.passwords = PasswordConfirmation(admin_chat_id=admin_chat_id, using=using)

    def authorize_return(self, customer_id, chat_id, message_id, bot_scope, top_up_id, amount_micro, destination, nonce):
        _outside(self.using)
        require_integer(customer_id, minimum=1)
        require_integer(amount_micro, minimum=1)
        top_up_id = _identity(top_up_id)
        if (type(chat_id) is not str or not re.fullmatch(r'[1-9][0-9]{0,18}', chat_id)
                or type(message_id) is not int or message_id < 1 or type(bot_scope) is not str
                or type(destination) is not str or re.fullmatch(r'0x[0-9a-f]{40}', destination) is None
                or destination == '0x' + '0' * 40 or type(nonce) is not str
                or re.fullmatch(r'[A-Za-z0-9_-]{16,64}', nonce) is None):
            raise ValueError('Invalid customer return authorization')
        with transaction.atomic(using=self.using):
            customer = self.db.one('SELECT id,telegram_id FROM public.customers WHERE id=%s FOR UPDATE', (customer_id,))
            root = self.db.one('''SELECT message_id FROM django_candidate.navigation_roots
                WHERE bot_asset_scope=%s AND customer_id=%s AND chat_id=%s AND message_id=%s
                  AND render_token IS NULL AND rendered_at IS NOT NULL FOR UPDATE''',
                (bot_scope, customer_id, chat_id, message_id))
            if not customer or str(customer['telegram_id']) != chat_id or not root:
                raise ValueError('Customer navigation authority unavailable')
            target = self.db.one('SELECT * FROM public.customer_usdt_top_ups WHERE id=%s AND customer_id=%s FOR UPDATE', (top_up_id, customer_id))
            if not target or target['rail'] != 'bsc_usdt':
                raise ValueError('Customer funding target unavailable')
            previous = self.db.one('SELECT * FROM public.usdt_top_up_external_return_authorizations WHERE customer_id=%s AND nonce=%s', (customer_id, nonce))
            if previous:
                if (str(previous['top_up_id']) != top_up_id or previous['amount_micro'] != amount_micro
                        or previous['destination_address'] != destination):
                    raise ValueError('Return authorization identity conflict')
                return previous
            if self.db.one('SELECT top_up_id FROM public.customer_usdt_top_up_resolutions WHERE top_up_id=%s', (top_up_id,)):
                raise ValueError('Funding target already resolved')
            snapshot = _json(target['payment_snapshot'])
            if destination == snapshot.get('receiver_address'):
                raise ValueError('Return must leave the receiving address')
            return self.db.one('''INSERT INTO public.usdt_top_up_external_return_authorizations
                (id,top_up_id,customer_id,amount_micro,chain_id,token_contract,destination_address,nonce,created_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING *''',
                (str(uuid4()), top_up_id, customer_id, amount_micro, snapshot['chain_id'],
                 snapshot['token_contract'], destination, nonce, legacy_now()))

    def _inspect(self, target, hint, amount_micro, decision, authorization=None, return_hash=None):
        now = legacy_now()
        if target['rail'] == 'binance_pay':
            hint = normalize_order_id(hint)
            if self.binance is None or hint is None or decision not in ('settled', 'rejected') or authorization or return_hash:
                raise ValueError('Binance review unavailable')
            history = self.binance.history(_ms(target['created_at']), _ms(now))
            matches = [item for item in history.transactions if item.matches_hint(hint)] if history.complete else []
            if len(matches) != 1:
                raise ValueError('Complete Binance evidence required')
            item = matches[0]
            snapshot = _json(target['payment_snapshot'])
            receiver_type = 'pay_id' if snapshot.get('receiver_pay_id') else 'uid'
            receiver = snapshot.get('receiver_pay_id') or snapshot.get('receiver_uid')
            claimable = (item.status in ('SUCCESS', 'COMPLETED', 'PAID') and item.currency == 'USDT'
                         and item.incoming(receiver_type, receiver) and _ms(target['created_at']) <= item.time_ms <= _ms(now))
            if item.amount != amount_micro or (decision == 'settled' and (not claimable or amount_micro <= target['fee_micro'])):
                raise ValueError('Binance evidence does not authorize the decision')
            if claimable and self.db.one('''SELECT id FROM public.customer_usdt_top_ups WHERE customer_id=%s
                AND rail='binance_pay' AND intent_id>%s AND created_at<=%s LIMIT 1''',
                (target['customer_id'], target['intent_id'], datetime_from_epoch(item.time_ms // 1000))):
                raise ValueError('Receipt belongs to a newer funding target')
            evidence = {'rail': 'binance_pay', 'order_identity': _digest(item.order_id),
                        'transaction_identity': _digest(item.transaction_id), 'amount_micro': item.amount,
                        'currency': item.currency, 'time_ms': item.time_ms, 'status': item.status,
                        'receiver_identity': _digest(receiver), 'claimable': claimable}
            claim = dict(top_up_id=str(target['id']), customer_id=target['customer_id'], receiver_type=receiver_type,
                receiver_value=receiver, transaction_id=item.transaction_id, order_id=item.order_id,
                amount_micro=item.amount, evidence=item.evidence(), using=self.using)
            return evidence, claim if claimable else None, None, item.time_ms // 1000
        if target['rail'] != 'bsc_usdt' or self.bsc is None or decision not in ('settled','resolved_by_compensation','externally_refunded'):
            raise ValueError('BSC review unavailable')
        hint = normalize_hash(hint)
        if not hint:
            raise ValueError('Invalid BSC transaction hash')
        if decision != 'externally_refunded' and (authorization is not None or return_hash is not None):
            raise ValueError('Outgoing evidence is only valid for external return')
        invoice = self.db.one('SELECT * FROM public.usdt_bsc_rail_invoices WHERE reference=%s', (target['reference'],))
        if not FundingSettlement._invoice_matches(target, invoice):
            raise ValueError('Funding invoice snapshot mismatch')
        observation = self.bsc.inspect(target['customer_id'], target['reference'], hint, allow_late=True)
        transfer = observation.transfer
        if (not transfer or not observation.finality_verified or not observation.window_matches
                or observation.reason not in ('canonical_final_transfer', 'canonical_final_amount_mismatch')
                or transfer.amount_units != str(amount_micro * 10**12)):
            raise ValueError('Canonical finalized incoming transfer required')
        if decision != 'externally_refunded' and amount_micro <= target['fee_micro']:
            raise ValueError('Deposit does not cover the immutable fee')
        if self.db.one('''SELECT id FROM public.customer_usdt_top_ups WHERE receiver_id=%s AND intent_id>%s
            AND created_at<=%s LIMIT 1''', (target['receiver_id'], target['intent_id'], datetime_from_epoch(transfer.block_timestamp))):
            raise ValueError('Receipt belongs to a newer funding target')
        incoming = _static_transfer(observation)
        claim = dict(top_up_id=str(target['id']), customer_id=target['customer_id'], chain_id=56,
                     tx_hash=transfer.tx_hash, log_index=transfer.log_index, amount_units=transfer.amount_units,
                     decimals=18, evidence={'transfer': incoming}, using=self.using)
        outgoing, out_claim = None, None
        if decision == 'externally_refunded':
            if (not authorization or authorization['customer_id'] != target['customer_id']
                    or str(authorization['top_up_id']) != str(target['id']) or authorization['amount_micro'] != amount_micro
                    or authorization['chain_id'] != invoice['chain_id'] or authorization['token_contract'] != invoice['token_contract']):
                raise ValueError('Authenticated customer return authorization required')
            return_hash = normalize_hash(return_hash)
            if not return_hash or return_hash == hint:
                raise ValueError('Distinct outgoing transfer required')
            result = self.bsc.inspect_return(target['customer_id'], target['reference'], return_hash,
                authorization['destination_address'], amount_micro, transfer.block_timestamp)
            if result.state != 'verified' or not result.finality_verified or not result.transfer:
                raise ValueError('Canonical finalized return transfer required')
            outgoing = _static_transfer(result)
            out_claim = dict(top_up_id=str(target['id']), customer_id=target['customer_id'], chain_id=56,
                tx_hash=result.transfer.tx_hash, log_index=result.transfer.log_index,
                amount_units=result.transfer.amount_units, decimals=18,
                evidence={'transfer': outgoing, 'direction': 'external_refund'}, using=self.using)
        return {'rail': 'bsc_usdt', 'incoming': incoming, 'outgoing': outgoing}, claim, out_claim, transfer.block_timestamp

    @staticmethod
    def _bound(db, request, actor, request_id, fingerprint, version):
        row = db.one('SELECT * FROM public.usdt_top_up_review_requests WHERE id=%s', (str(request_id),))
        if (not row or row['owner_user_id'] != actor['id'] or version != row['version']
                or row['fingerprint'] != fingerprint or row['expires_at'] <= legacy_now()
                or row['session_binding'] != sha256_digest(request.auth_session.token)
                or row['credential_binding'] != sha256_digest(actor['password'])):
            raise AdjustmentRejected('preview_binding_mismatch')
        return row

    def _locked_target(self, target, version, checkpoint_version):
        locked = self.db.one('SELECT * FROM public.customer_usdt_top_ups WHERE id=%s FOR UPDATE', (str(target['id']),))
        if not locked or locked['request_fingerprint'] != target['request_fingerprint']:
            raise ValueError('Funding target changed')
        if locked['receiver_id']:
            receiver = self.db.one('SELECT * FROM public.customer_usdt_deposit_receivers WHERE id=%s FOR UPDATE', (locked['receiver_id'],))
            snapshot = _json(locked['payment_snapshot'])
            if (not receiver or receiver['customer_id'] != locked['customer_id'] or receiver['verified_at'] is None
                    or receiver['address_normalized'] != snapshot['receiver_address']):
                raise ValueError('Permanent receiver binding unavailable')
        work = self.db.one('SELECT * FROM public.customer_usdt_top_up_settlement_work WHERE top_up_id=%s FOR UPDATE', (str(target['id']),))
        reservation = self.db.one('SELECT * FROM public.customer_usdt_funding_reservations WHERE id=%s FOR UPDATE', (target['reservation_id'],))
        checkpoint = (self.db.one('SELECT * FROM public.usdt_bsc_scan_checkpoints WHERE receiver_id=%s FOR UPDATE', (target['receiver_id'],))
                      if target['receiver_id'] else None)
        if (not work or work['version'] != version or work['state'] not in ('manual_review','expired_unresolved')
                or (work['lease_until'] and work['lease_until'] > legacy_now()) or not reservation
                or reservation['resolved_at'] is not None or (target['receiver_id'] and checkpoint is None)
                or (checkpoint or {}).get('version') != checkpoint_version
                or (checkpoint or {}).get('review_reason') is not None
                or self.db.one('SELECT top_up_id FROM public.customer_usdt_top_up_resolutions WHERE top_up_id=%s', (str(target['id']),))):
            raise ValueError('Funding review changed or is already resolved')
        return locked, work, reservation

    def _check_attribution(self, target, transfer_epoch):
        stamp = datetime_from_epoch(transfer_epoch)
        if target['rail'] == 'binance_pay':
            newer = self.db.one('''SELECT id FROM public.customer_usdt_top_ups WHERE customer_id=%s
                AND rail='binance_pay' AND intent_id>%s AND created_at<=%s LIMIT 1''',
                (target['customer_id'], target['intent_id'], stamp))
        else:
            newer = self.db.one('''SELECT id FROM public.customer_usdt_top_ups WHERE receiver_id=%s
                AND intent_id>%s AND created_at<=%s LIMIT 1''', (target['receiver_id'], target['intent_id'], stamp))
        if newer:
            raise ValueError('Receipt belongs to a newer funding target')

    def request(self, request, top_up_id, decision, amount_micro, hint, reason, *, return_hash=None, authorization_id=None):
        _outside(self.using)
        actor = owner(request, self.db)
        top_up_id = _identity(top_up_id)
        require_integer(amount_micro, minimum=1)
        if not isinstance(reason, str) or not 1 <= len(reason.strip()) <= 1000:
            raise ValueError('Review reason required')
        target = self.db.one('SELECT * FROM public.customer_usdt_top_ups WHERE id=%s', (top_up_id,))
        if not target:
            raise ValueError('Funding target unavailable')
        work = self.db.one('SELECT * FROM public.customer_usdt_top_up_settlement_work WHERE top_up_id=%s', (top_up_id,))
        checkpoint = (self.db.one('SELECT version FROM public.usdt_bsc_scan_checkpoints WHERE receiver_id=%s', (target['receiver_id'],))
                      if target['receiver_id'] else None)
        authorization = (self.db.one('SELECT * FROM public.usdt_top_up_external_return_authorizations WHERE id=%s', (_identity(authorization_id),))
                         if authorization_id else None)
        evidence, _, _, transfer_epoch = self._inspect(target, hint, amount_micro, decision, authorization, return_hash)
        if not work:
            raise ValueError('Funding work unavailable')
        now = legacy_now()
        identity = {'top_up_id': top_up_id, 'owner_user_id': actor['id'], 'decision': decision,
                    'amount_micro': amount_micro, 'hint': hint, 'return_hash': return_hash,
                    'authorization_id': str(authorization_id) if authorization_id else None,
                    'reason': reason.strip(), 'work_version': work['version'],
                    'checkpoint_version': (checkpoint or {}).get('version'),
                    'target_fingerprint': target['request_fingerprint'], 'evidence': evidence,
                    'session_binding': sha256_digest(request.auth_session.token),
                    'credential_binding': sha256_digest(actor['password'])}
        fingerprint = _digest(identity)
        with transaction.atomic(using=self.using):
            actor = owner(request, self.db, lock=True)
            if actor['id'] != identity['owner_user_id'] or sha256_digest(actor['password']) != identity['credential_binding']:
                raise ValueError('Owner authority changed')
            self.db.one('SELECT id FROM public.customers WHERE id=%s FOR UPDATE', (target['customer_id'],))
            self._locked_target(target, work['version'], identity['checkpoint_version'])
            self._check_attribution(target, transfer_epoch)
            return self.db.one('''INSERT INTO public.usdt_top_up_review_requests
                (id,top_up_id,owner_user_id,decision,amount_micro,transaction_hash,provider_reference,
                 refund_transaction_hash,refund_address,refund_authorization_id,reason,fingerprint,version,
                 work_version,checkpoint_version,target_fingerprint,session_binding,credential_binding,
                 chain_evidence,refund_evidence,expires_at,created_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,1,%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING *''',
                (str(uuid4()), top_up_id, actor['id'], decision, amount_micro,
                 hint if target['rail'] == 'bsc_usdt' else None, hint if target['rail'] == 'binance_pay' else None,
                 return_hash, authorization['destination_address'] if authorization else None, str(authorization_id) if authorization else None,
                 reason.strip(), fingerprint, work['version'], identity['checkpoint_version'], target['request_fingerprint'],
                 identity['session_binding'], identity['credential_binding'], json.dumps(evidence), None,
                 now + timedelta(minutes=5), now))

    def confirm(self, request, request_id, fingerprint, version, password):
        _outside(self.using)
        request_id = _identity(request_id)
        actor = owner(request, self.db)
        preview = self._bound(self.db, request, actor, request_id, fingerprint, version)
        existing = self.db.one('SELECT * FROM public.customer_usdt_top_up_resolutions WHERE review_request_id=%s', (request_id,))
        if existing:
            return existing
        target = self.db.one('SELECT * FROM public.customer_usdt_top_ups WHERE id=%s', (str(preview['top_up_id']),))
        if not target:
            raise ValueError('Funding target unavailable')
        authorization = (self.db.one('SELECT * FROM public.usdt_top_up_external_return_authorizations WHERE id=%s', (preview['refund_authorization_id'],))
                         if preview['refund_authorization_id'] else None)
        hint = preview['provider_reference'] if target['rail'] == 'binance_pay' else preview['transaction_hash']
        evidence, incoming, outgoing, transfer_epoch = self._inspect(target, hint, preview['amount_micro'], preview['decision'], authorization, preview['refund_transaction_hash'])
        if evidence != _json(preview['chain_evidence']):
            raise ValueError('Authoritative evidence changed')
        token = self.passwords.verify(request, request_id, fingerprint, version, password, binder=self._bound)
        try:
            with transaction.atomic(using=self.using):
                actor = owner(request, self.db, lock=True)
                preview = self._bound(self.db, request, actor, request_id, fingerprint, version)
                self.db.one('SELECT id FROM public.customers WHERE id=%s FOR UPDATE', (target['customer_id'],))
                if preview['decision'] != 'externally_refunded' and preview['decision'] != 'rejected':
                    self.ledger._lock_account(target['customer_id'])
                locked, work, reservation = self._locked_target(target, preview['work_version'], preview['checkpoint_version'])
                self._check_attribution(locked, transfer_epoch)
                column = 'provider_reference' if target['rail'] == 'binance_pay' else 'tx_hash'
                self.db.execute(f'''INSERT INTO public.customer_usdt_top_up_inbox
                    (top_up_id,{column},state,attempts,next_check_at,created_at)
                    VALUES (%s,%s,'review',0,%s,%s) ON CONFLICT DO NOTHING''', (str(target['id']), hint, legacy_now(), legacy_now()))
                inbox = self.db.one(f'SELECT id FROM public.customer_usdt_top_up_inbox WHERE top_up_id=%s AND {column}=%s ORDER BY id LIMIT 1', (str(target['id']), hint))
                now = legacy_now()
                evidence_id = self.db.one('''INSERT INTO public.customer_usdt_top_up_evidence
                    (top_up_id,inbox_id,version,status,reason,chain_evidence,created_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s) RETURNING id''',
                    (str(target['id']), inbox['id'], work['evidence_version'] + 1,
                     'review' if preview['decision'] == 'rejected' else 'verified', 'owner_verified_resolution', json.dumps(evidence), now))['id']
                claim = ((claim_usdt_binance_top_up if target['rail'] == 'binance_pay' else claim_usdt_bsc_top_up)(self.db, **incoming)
                         if incoming else None)
                if incoming and (not claim or not claim.allowed):
                    raise ValueError('External receipt already claimed')
                returned = claim_usdt_bsc_top_up(self.db, **outgoing) if outgoing else None
                if outgoing and (not returned or not returned.allowed):
                    raise ValueError('External return already claimed')
                entry = None
                if preview['decision'] in ('settled', 'resolved_by_compensation'):
                    if not claim:
                        raise ValueError('Verified receipt missing')
                    entry = self.ledger.credit_verified_top_up(target['customer_id'], str(target['id']), claim.receipt_id,
                        evidence_id, review_id=request_id, authority_token=token)
                self.passwords.consume(actor['id'], token, request_id, fingerprint)
                late = now > target['expires_at'] or datetime_from_epoch(transfer_epoch) > target['expires_at']
                resolution = self.db.one('''INSERT INTO public.customer_usdt_top_up_resolutions
                    (top_up_id,kind,receipt_id,entry_id,evidence_id,review_request_id,refund_receipt_id,resolved_amount_micro,late,created_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING *''',
                    (str(target['id']), preview['decision'], claim.receipt_id if claim else None,
                     entry['id'] if entry else None, evidence_id, request_id, returned.receipt_id if returned else None,
                     preview['amount_micro'], late, now))
                if entry:
                    if target['fee_micro']:
                        self.db.execute('''INSERT INTO public.customer_usdt_top_up_fee_events
                            (top_up_id,receipt_id,amount_micro,created_at) VALUES (%s,%s,%s,%s)''',
                            (str(target['id']), claim.receipt_id, target['fee_micro'], now))
                    self.db.execute('''UPDATE public.customer_usdt_funding_reservations SET state='settled',resolved_at=%s,
                        resolution_kind='settled',resolution_reference=%s,resolution_actor=%s,settled_entry_id=%s,updated_at=%s WHERE id=%s''',
                        (now, request_id, 'owner:' + str(actor['id']), entry['id'], now, reservation['id']))
                    record_topup_receipt(self.db, target, entry, late=late, using=self.using)
                else:
                    self.db.execute('''UPDATE public.customer_usdt_funding_reservations SET state='released',resolved_at=%s,
                        resolution_kind=%s,resolution_reference=%s,resolution_actor=%s,updated_at=%s WHERE id=%s''',
                        (now, 'externally_returned' if returned else 'rejected', request_id, 'owner:' + str(actor['id']), now, reservation['id']))
                self.db.execute('''UPDATE public.customer_usdt_top_up_settlement_work SET state=%s,version=version+1,
                    evidence_version=evidence_version+1,lease_token=NULL,lease_until=NULL,last_error=NULL,next_check_at=%s,updated_at=%s WHERE top_up_id=%s''',
                    (preview['decision'], now + timedelta(minutes=1), now, str(target['id'])))
                self.db.execute('''INSERT INTO public.audit_logs(user_id,action,subject_type,subject_id,new_values,created_at)
                    VALUES (%s,'usdt_top_up.review_resolved','App\\Models\\CustomerUsdtTopUp',%s,%s,%s)''',
                    (actor['id'], str(target['id']), json.dumps({'request_id': request_id, 'decision': preview['decision'],
                     'amount_micro': str(preview['amount_micro']), 'entry_id': entry['id'] if entry else None,
                     'receipt_id': claim.receipt_id if claim else None}), now))
                return resolution
        finally:
            self.passwords.release(actor['id'], token)
