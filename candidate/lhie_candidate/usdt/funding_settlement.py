"""Leased preparation and authoritative settlement of existing funding targets."""
import json
import secrets
from dataclasses import asdict
from datetime import timedelta
from urllib.parse import urlsplit

from django.db import connections, transaction

from lhie_candidate.checkout.db import Database
from lhie_candidate.domain.money import require_integer
from lhie_candidate.payments.binance import _ms, normalize_order_id
from lhie_candidate.payments.bsc import normalize_hash
from lhie_candidate.payments.receipts import claim_usdt_binance_top_up, claim_usdt_bsc_top_up
from .funding import _json, _now
from .ledger import UsdtLedger
from .outbound import record_topup_receipt


class FundingSettlement:
    def __init__(self, *, using='default', binance=None, bsc=None, clock=None):
        self.using, self.db = using, Database(using)
        self.binance, self.bsc = binance, bsc
        self.clock = clock or _now
        self.ledger = UsdtLedger(using)

    def _outside(self):
        if any(c.in_atomic_block or not c.get_autocommit() for c in connections.all(initialized_only=True)):
            raise RuntimeError('Provider I/O requires a committed transaction')

    @staticmethod
    def _invoice_matches(target, invoice):
        snapshot = _json(target['payment_snapshot'])
        return (invoice is not None and str(invoice['reference']) == target['reference']
            and invoice['customer_id'] == target['customer_id'] and invoice['receiver_id'] == target['receiver_id']
            and invoice['amount_micro'] == target['payable_micro'] and invoice['fee_micro'] == 0
            and invoice['payable_micro'] == target['payable_micro'] and invoice['currency'] == 'USDT'
            and invoice['requires_finalized_block'] is True
            and all(invoice[k] == snapshot.get(k) for k in ('chain_id', 'token_contract', 'token_decimals',
                'receiver_address', 'custody_namespace', 'minimum_confirmations')))

    def prepare(self, top_up_id):
        self._outside()
        now = self.clock()
        with transaction.atomic(using=self.using):
            target = self.db.one('SELECT * FROM public.customer_usdt_top_ups WHERE id=%s FOR UPDATE SKIP LOCKED', (str(top_up_id),))
            if not target or target['rail'] != 'bsc_usdt' or self.bsc is None:
                return False
            work = self.db.one('SELECT * FROM public.customer_usdt_top_up_work WHERE top_up_id=%s FOR UPDATE SKIP LOCKED', (str(top_up_id),))
            if (not work or work['state'] not in ('pending', 'creating', 'creation_unknown')
                or work['next_attempt_at'] > now or (work['lease_until'] and work['lease_until'] > now)):
                return False
            token = secrets.token_hex(16)
            version = work['version'] + 1
            self.db.execute('''UPDATE public.customer_usdt_top_up_work SET state='creating',version=%s,
                attempts=attempts+1,lease_token=%s,lease_until=%s,updated_at=%s WHERE top_up_id=%s''',
                (version, token, now + timedelta(minutes=2), now, str(top_up_id)))
        invoice = None
        try:
            snapshot = _json(target['payment_snapshot'])
            invoice = self.bsc.create_or_recover(target['customer_id'], target['reference'], target['payable_micro'],
                target['expires_at'], receiver_id=target['receiver_id'], target_fingerprint=target['request_fingerprint'],
                minimum_confirmations=snapshot['minimum_confirmations'])
        except Exception:
            pass
        now = self.clock()
        with transaction.atomic(using=self.using):
            self.db.one('SELECT id FROM public.customer_usdt_top_ups WHERE id=%s FOR UPDATE', (str(top_up_id),))
            work = self.db.one('SELECT * FROM public.customer_usdt_top_up_work WHERE top_up_id=%s FOR UPDATE', (str(top_up_id),))
            if not work or work['version'] != version or work['lease_token'] != token or not work['lease_until'] or work['lease_until'] <= now:
                return True
            valid = self._invoice_matches(target, invoice)
            state = 'ready' if valid else ('creation_unknown' if target['expires_at'] > now else 'expired_unresolved')
            self.db.execute('''UPDATE public.customer_usdt_top_up_work SET state=%s,version=version+1,
                lease_token=NULL,lease_until=NULL,rail_invoice_id=%s,last_error=%s,next_attempt_at=%s,updated_at=%s
                WHERE top_up_id=%s''', (state, invoice['id'] if valid else None,
                None if valid else 'rail_preparation_unresolved', now + timedelta(seconds=min(300, 5 * 2 ** min(6, work['attempts']))), now, str(top_up_id)))
        return True

    def submit(self, customer_id, top_up_id, hint):
        now = self.clock()
        with transaction.atomic(using=self.using):
            target = self.db.one('SELECT * FROM public.customer_usdt_top_ups WHERE id=%s AND customer_id=%s FOR UPDATE', (str(top_up_id), customer_id))
            if not target:
                raise ValueError('Funding target unavailable')
            if target['rail'] == 'bsc_usdt':
                if isinstance(hint, str) and hint.lower().startswith('https://'):
                    url = urlsplit(hint)
                    if (url.hostname != 'bscscan.com' or url.port not in (None, 443)
                            or url.username or url.password or url.query or url.fragment
                            or not url.path.startswith('/tx/')):
                        raise ValueError('Invalid funding lookup hint')
                    hint = url.path.removeprefix('/tx/')
                hint = normalize_hash(hint)
                column = 'tx_hash'
            elif target['rail'] == 'binance_pay':
                hint = normalize_order_id(hint)
                column = 'provider_reference'
            else:
                raise ValueError('Unsupported funding rail')
            if not hint:
                raise ValueError('Invalid funding lookup hint')
            if self.db.one(f'SELECT id FROM public.customer_usdt_top_up_inbox WHERE top_up_id=%s AND {column}=%s', (str(top_up_id), hint)):
                return
            count = self.db.one('SELECT count(*) AS n FROM public.customer_usdt_top_up_inbox WHERE top_up_id=%s', (str(top_up_id),))['n']
            if count >= 100:
                raise ValueError('Funding lookup budget exhausted')
            self.db.execute(f'''INSERT INTO public.customer_usdt_top_up_inbox
                (top_up_id,{column},state,attempts,next_check_at,created_at) VALUES (%s,%s,'pending',0,%s,%s)''',
                (str(top_up_id), hint, now, now))

    def check(self, top_up_id):
        self._outside()
        now = self.clock()
        with transaction.atomic(using=self.using):
            target = self.db.one('SELECT * FROM public.customer_usdt_top_ups WHERE id=%s FOR UPDATE SKIP LOCKED', (str(top_up_id),))
            if not target or target['rail'] not in ('binance_pay', 'bsc_usdt'):
                return False
            work = self.db.one('SELECT * FROM public.customer_usdt_top_up_settlement_work WHERE top_up_id=%s FOR UPDATE SKIP LOCKED', (str(top_up_id),))
            if not work or work['next_check_at'] > now or (work['lease_until'] and work['lease_until'] > now):
                return False
            inbox = self.db.one('''SELECT * FROM public.customer_usdt_top_up_inbox WHERE top_up_id=%s
                AND state='pending' AND next_check_at<=%s ORDER BY next_check_at,id LIMIT 1 FOR UPDATE''', (str(top_up_id), now))
            if not inbox:
                self.db.execute('''UPDATE public.customer_usdt_top_up_settlement_work SET version=version+1,
                    next_check_at=%s,updated_at=%s WHERE top_up_id=%s''', (now + timedelta(minutes=1), now, str(top_up_id)))
                return False
            if target['rail'] == 'binance_pay' and work['attempts'] >= 100:
                state = work['state'] if work['state'] in ('settled','externally_refunded','resolved_by_compensation','rejected') else 'manual_review'
                self.db.execute('''UPDATE public.customer_usdt_top_up_settlement_work SET version=version+1,state=%s,
                    last_error='binance_inspection_budget_exhausted',next_check_at=%s,updated_at=%s WHERE top_up_id=%s''',
                    (state, now + timedelta(minutes=5), now, str(top_up_id)))
                return False
            checkpoint = None
            if target['rail'] == 'bsc_usdt':
                checkpoint = self.db.one('SELECT version,review_reason FROM public.usdt_bsc_scan_checkpoints WHERE receiver_id=%s', (target['receiver_id'],))
            token, version = secrets.token_hex(16), work['version'] + 1
            self.db.execute('''UPDATE public.customer_usdt_top_up_settlement_work SET version=%s,lease_token=%s,
                lease_until=%s,attempts=attempts+1,updated_at=%s WHERE top_up_id=%s''',
                (version, token, now + timedelta(minutes=2), now, str(top_up_id)))
        observation = None
        try:
            if target['rail'] == 'binance_pay' and self.binance:
                history = self.binance.history(_ms(target['created_at']), _ms(now))
                if history.complete:
                    matches = [v for v in history.transactions if v.matches_hint(inbox['provider_reference'])]
                    if len(matches) == 1:
                        observation = matches[0]
            elif target['rail'] == 'bsc_usdt' and self.bsc and checkpoint and not checkpoint['review_reason']:
                observation = self.bsc.inspect(target['customer_id'], target['reference'], inbox['tx_hash'], allow_late=True)
        except Exception:
            observation = None
        self._apply(target, inbox, token, version, checkpoint, observation)
        return True

    def _apply(self, target, inbox, token, version, checkpoint, observation):
        now = self.clock()
        top_up_id = str(target['id'])
        with transaction.atomic(using=self.using):
            self.db.one('SELECT id FROM public.customers WHERE id=%s FOR UPDATE', (target['customer_id'],))
            self.ledger._lock_account(target['customer_id'])
            locked = self.db.one('SELECT * FROM public.customer_usdt_top_ups WHERE id=%s FOR UPDATE', (top_up_id,))
            prep = self.db.one('SELECT * FROM public.customer_usdt_top_up_work WHERE top_up_id=%s FOR UPDATE', (top_up_id,))
            work = self.db.one('SELECT * FROM public.customer_usdt_top_up_settlement_work WHERE top_up_id=%s FOR UPDATE', (top_up_id,))
            if (not locked or not work or work['version'] != version or work['lease_token'] != token
                    or not work['lease_until'] or work['lease_until'] <= now):
                return
            reservation = self.db.one('SELECT * FROM public.customer_usdt_funding_reservations WHERE id=%s FOR UPDATE', (target['reservation_id'],))
            resolution = self.db.one('SELECT * FROM public.customer_usdt_top_up_resolutions WHERE top_up_id=%s', (top_up_id,))
            valid, reason, evidence, claim_args = False, 'observation_unavailable', {}, None
            if target['rail'] == 'binance_pay' and observation is not None:
                snap = _json(target['payment_snapshot'])
                receiver_type = 'pay_id' if snap.get('receiver_pay_id') else 'uid'
                receiver_value = snap.get('receiver_pay_id') or snap.get('receiver_uid')
                claimable = (observation.successful and observation.currency == 'USDT'
                    and observation.incoming(receiver_type, receiver_value)
                    and _ms(target['created_at']) <= observation.time_ms <= _ms(now))
                valid = claimable and observation.amount == target['payable_micro']
                reason = 'verified' if valid else 'binance_snapshot_or_amount_mismatch'
                evidence = observation.evidence()
                if claimable:
                    newer = self.db.one('''SELECT id FROM public.customer_usdt_top_ups
                        WHERE customer_id=%s AND rail='binance_pay' AND intent_id>%s
                          AND created_at<=%s ORDER BY intent_id LIMIT 1''',
                        (target['customer_id'], target['intent_id'], datetime_from_epoch(observation.time_ms // 1000)))
                    if newer:
                        claimable, valid, reason = False, False, 'newer_target_attribution'
                if claimable:
                    claim_args = dict(top_up_id=top_up_id, customer_id=target['customer_id'], receiver_type=receiver_type,
                        receiver_value=receiver_value, transaction_id=observation.transaction_id, order_id=observation.order_id,
                        amount_micro=observation.amount, evidence=evidence, now=now, using=self.using)
            elif target['rail'] == 'bsc_usdt' and observation is not None:
                current = self.db.one('SELECT * FROM public.usdt_bsc_scan_checkpoints WHERE receiver_id=%s FOR UPDATE', (target['receiver_id'],))
                invoice = self.db.one('SELECT * FROM public.usdt_bsc_rail_invoices WHERE id=%s', (prep['rail_invoice_id'],)) if prep and prep['rail_invoice_id'] else None
                if not current or not checkpoint or current['version'] != checkpoint['version'] or current['review_reason'] or not self._invoice_matches(target, invoice):
                    observation = None
                    reason = 'receiver_or_invoice_changed'
                else:
                    transfer = observation.transfer
                    claimable = bool(transfer and observation.finality_verified and observation.window_matches
                        and (observation.state == 'verified' or observation.reason == 'canonical_final_amount_mismatch'))
                    valid = bool(claimable and observation.state == 'verified'
                        and transfer.amount_units == str(target['payable_micro'] * 10 ** 12))
                    reason = observation.reason
                    evidence = {'state': observation.state, 'reason': observation.reason,
                        'transfer': asdict(transfer) if transfer else None, 'chain_facts': observation.chain_facts}
                    if claimable:
                        newer = self.db.one('''SELECT t.id FROM public.customer_usdt_top_ups t
                            WHERE t.receiver_id=%s AND t.intent_id>%s AND t.created_at<=%s
                            ORDER BY t.intent_id LIMIT 1''', (target['receiver_id'], target['intent_id'],
                            datetime_from_epoch(transfer.block_timestamp)))
                        if newer:
                            valid, reason = False, 'newer_target_attribution'
                        else:
                            claim_args = dict(top_up_id=top_up_id, customer_id=target['customer_id'], chain_id=56,
                                tx_hash=transfer.tx_hash, log_index=transfer.log_index, amount_units=transfer.amount_units,
                                decimals=18, evidence=evidence, now=now, using=self.using)
            evidence_id = None
            ev_version = work['evidence_version']
            if observation is not None:
                ev_version += 1
                row = self.db.one('''INSERT INTO public.customer_usdt_top_up_evidence
                    (top_up_id,inbox_id,version,status,reason,chain_evidence,created_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s) RETURNING id''', (top_up_id, inbox['id'], ev_version,
                    'verified' if valid else 'review', reason[:64], json.dumps(evidence), now))
                evidence_id = row['id']
            receipt = None
            if claim_args:
                receipt = (claim_usdt_binance_top_up if target['rail'] == 'binance_pay' else claim_usdt_bsc_top_up)(self.db, **claim_args)
            state = work['state']
            terminal = state in ('settled','externally_refunded','resolved_by_compensation','rejected')
            if receipt and receipt.allowed and not resolution and reservation and reservation['resolved_at'] is None and valid:
                if target['rail'] == 'bsc_usdt' and not observation.window_matches:
                    reason, state = 'late_transfer', 'manual_review'
                else:
                    entry = self.ledger.credit_verified_top_up(target['customer_id'], top_up_id, receipt.receipt_id, evidence_id)
                    late = target['expires_at'] < now
                    self.db.execute('''INSERT INTO public.customer_usdt_top_up_resolutions
                        (top_up_id,kind,receipt_id,entry_id,evidence_id,late,created_at)
                        VALUES (%s,'settled',%s,%s,%s,%s,%s)''', (top_up_id, receipt.receipt_id, entry['id'], evidence_id, late, now))
                    if target['fee_micro']:
                        self.db.execute('''INSERT INTO public.customer_usdt_top_up_fee_events
                            (top_up_id,receipt_id,currency,amount_micro,debit_leg,credit_leg,created_at)
                            VALUES (%s,%s,'USDT',%s,'settlement_asset','funding_fee_revenue',%s)''',
                            (top_up_id, receipt.receipt_id, target['fee_micro'], now))
                    self.db.execute('''UPDATE public.customer_usdt_funding_reservations SET state='settled',resolved_at=%s,
                        resolution_kind='settled',resolution_reference=%s,resolution_actor='settlement',settled_entry_id=%s,updated_at=%s
                        WHERE id=%s''', (now, top_up_id, entry['id'], now, reservation['id']))
                    record_topup_receipt(self.db, target, entry, late=late, using=self.using)
                    state, reason = 'settled', None
            elif receipt and receipt.allowed and resolution:
                reason = 'receipt_after_resolution'
                if not terminal:
                    state = 'manual_review'
            elif observation is not None or receipt:
                reason = 'receipt_conflict' if receipt and not receipt.allowed else reason
                if not terminal:
                    state = 'manual_review'
            attempts = inbox['attempts'] + 1
            inbox_state = 'settled' if state == 'settled' and reason is None else ('review' if observation is not None else 'pending')
            self.db.execute('''UPDATE public.customer_usdt_top_up_inbox SET state=%s,attempts=%s,next_check_at=%s WHERE id=%s''',
                (inbox_state, attempts, now + timedelta(seconds=min(300, 5 * 2 ** min(6, attempts))), inbox['id']))
            self.db.execute('''UPDATE public.customer_usdt_top_up_settlement_work SET state=%s,version=version+1,
                evidence_version=%s,lease_token=NULL,lease_until=NULL,next_check_at=%s,last_error=%s,updated_at=%s WHERE top_up_id=%s''',
                (state, ev_version, now + timedelta(minutes=1), reason, now, top_up_id))
    def discover(self, top_up_id):
        """Materialize bounded scanner observations as durable inspection hints."""
        now = self.clock()
        with transaction.atomic(using=self.using):
            target = self.db.one('SELECT * FROM public.customer_usdt_top_ups WHERE id=%s FOR UPDATE', (str(top_up_id),))
            if not target or target['rail'] != 'bsc_usdt':
                return 0
            work = self.db.one('SELECT * FROM public.customer_usdt_top_up_settlement_work WHERE top_up_id=%s FOR UPDATE', (str(top_up_id),))
            if not work or (work['lease_until'] and work['lease_until'] > now):
                return 0
            observations = self.db.rows('''SELECT id,tx_hash FROM public.usdt_bsc_transfer_observations
                WHERE receiver_id=%s AND id>%s ORDER BY id LIMIT 25''',
                (target['receiver_id'], work['observation_cursor']))
            for item in observations:
                self.db.execute('''INSERT INTO public.customer_usdt_top_up_inbox
                    (top_up_id,tx_hash,state,attempts,next_check_at,created_at)
                    VALUES (%s,%s,'pending',0,%s,%s) ON CONFLICT (top_up_id,tx_hash) DO NOTHING''',
                    (str(top_up_id), item['tx_hash'], now, now))
            if observations:
                self.db.execute('''UPDATE public.customer_usdt_top_up_settlement_work
                    SET observation_cursor=%s,version=version+1,updated_at=%s WHERE top_up_id=%s''',
                    (observations[-1]['id'], now, str(top_up_id)))
            return len(observations)


    def recover(self, limit=25):
        self._outside()
        require_integer(limit, minimum=1)
        if limit > 100:
            raise ValueError('Funding recovery batch exceeds limit')
        if self.bsc:
            preparations = self.db.rows('''SELECT w.top_up_id FROM public.customer_usdt_top_up_work w
                JOIN public.customer_usdt_top_ups t ON t.id=w.top_up_id
                WHERE t.rail='bsc_usdt' AND w.state IN ('pending','creating','creation_unknown')
                  AND w.next_attempt_at<=%s AND (w.lease_until IS NULL OR w.lease_until<=%s)
                ORDER BY w.next_attempt_at,w.top_up_id LIMIT %s''', (self.clock(), self.clock(), limit))
            for item in preparations:
                self.prepare(item['top_up_id'])
            checkpoints = self.db.rows('''SELECT receiver_id,first_block FROM public.usdt_bsc_scan_checkpoints
                ORDER BY last_attempt_at NULLS FIRST,receiver_id LIMIT %s''', (limit,))
            budget = 32
            for checkpoint in checkpoints:
                if budget <= 0:
                    break
                self.db.execute('UPDATE public.usdt_bsc_scan_checkpoints SET last_attempt_at=%s WHERE receiver_id=%s',
                                (self.clock(), checkpoint['receiver_id']))
                report = self.bsc.scan(checkpoint['receiver_id'], checkpoint['first_block'], request_budget=budget)
                budget -= report['requests']
            targets = self.db.rows('''SELECT t.id FROM public.customer_usdt_top_ups t
                JOIN public.customer_usdt_top_up_settlement_work w ON w.top_up_id=t.id
                WHERE t.rail='bsc_usdt' AND EXISTS (
                    SELECT 1 FROM public.usdt_bsc_transfer_observations o
                    WHERE o.receiver_id=t.receiver_id AND o.id>w.observation_cursor)
                ORDER BY w.next_check_at,t.id LIMIT %s''', (limit,))
            for target in targets:
                self.discover(target['id'])
        rails = [name for name, adapter in (('binance_pay', self.binance), ('bsc_usdt', self.bsc)) if adapter is not None]
        if not rails:
            return 0
        rows = self.db.rows('''SELECT w.top_up_id FROM public.customer_usdt_top_up_settlement_work w
            JOIN public.customer_usdt_top_ups t ON t.id=w.top_up_id
            WHERE t.rail=ANY(%s) AND w.next_check_at<=%s AND (w.lease_until IS NULL OR w.lease_until<=%s)
            ORDER BY w.next_check_at,w.top_up_id LIMIT %s''', (rails, self.clock(), self.clock(), limit))
        return sum(self.check(row['top_up_id']) for row in rows)


def datetime_from_epoch(value):
    from datetime import datetime
    from zoneinfo import ZoneInfo
    return datetime.fromtimestamp(value, ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)
