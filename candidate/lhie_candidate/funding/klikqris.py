"""Explicit KlikQRIS top-up lifecycle over the shared provider and ledger."""
import hashlib
import hmac
import json

from django.db import transaction

from lhie_candidate.domain.money import require_integer
from lhie_candidate.payments.klikqris import authenticate_callback, callback_identity, valid_qr_url
from lhie_candidate.payments.receipts import claim_provider_reference
from .outbound import record_paid
from .service import TopUpOutcome, _deadline, _value


class KlikFunding:
    def __init__(self, funding, adapter, encrypter):
        self.funding = funding
        self.db = funding.db
        self.using = funding.using
        self.adapter = adapter
        self.encrypter = encrypter

    def create(self, customer, root, intent_id):
        self.funding._outside()
        if self.adapter.readiness() or self.encrypter is None:
            return TopUpOutcome('unavailable')
        customer_id = require_integer(customer['id'], minimum=1)
        chat_id = str(_value(root, 'chat_id') or '')
        message_id = _value(root, 'message_id')
        with transaction.atomic(using=self.using):
            self.funding._lock_customer(customer_id)
            if not self.funding._root_matches(root, customer_id=customer_id, chat_id=chat_id,
                                              message_id=message_id, lock=True):
                return TopUpOutcome('stale')
            intent = self.funding._locked_confirmed_intent(customer_id, intent_id, chat_id, message_id)
            existing = self.funding._topup_by_intent(intent_id, lock=True)
            if existing:
                return TopUpOutcome('replayed', existing)
            setting = self.funding._setting(lock=True)
            policy = self.funding._policy(lock=True)
            if (not setting or not setting['is_active'] or (setting['payment_mode'] or '').strip().lower() != 'klikqris'
                    or policy is None or policy.version != intent['policy_version']):
                return TopUpOutcome('unavailable')
            now = self.funding.clock()
            if _deadline(intent['expires_at']) <= now:
                return TopUpOutcome('expired')
            amount = require_integer(intent['amount'], minimum=policy.minimum_amount)
            topup = self.db.one('''INSERT INTO public.store_balance_top_ups
                (customer_id,store_balance_funding_intent_id,telegram_navigation_root_id,chat_id,
                 canonical_message_id,locale,provider,payment_setting_id,payment_provider_version,
                 merchant_ref,idempotency_key,currency,amount,status,provider_payment_method,
                 expires_at,provider_create_state,provider_create_attempted_at,provider_payload,created_at,updated_at)
                VALUES (%s,%s,NULL,%s,%s,%s,'klikqris',%s,%s,%s,%s,'IDR',%s,'pending','QRIS',
                    %s,'creating',%s,%s::jsonb,%s,%s) RETURNING ''' + ','.join(self.funding.TOPUP_COLUMNS),
                (customer_id, intent_id, chat_id, message_id, customer.get('locale'), setting['id'],
                 setting['payment_provider_version'], self.funding._merchant_reference(intent),
                 self.funding._idempotency_key(intent), amount, _deadline(intent['expires_at']), now,
                 json.dumps({'environment': self.adapter.config.environment}), now, now))
        return self._observe(topup, creating=True)

    def receive_callback(self, body, *, environment):
        """Authenticate and persist a hint. The returned event ID is not payment authority."""
        self.funding._outside()
        if self.encrypter is None or environment != self.adapter.config.environment:
            raise ValueError('unavailable environment')
        ref = callback_identity(body)
        topup = self.db.one('''SELECT id,provider,merchant_ref,amount,provider_total_payment,
            provider_reference,provider_signature,provider_payload FROM public.store_balance_top_ups
            WHERE provider='klikqris' AND merchant_ref=%s''', (ref,))
        if topup is None:
            return None
        payload = topup['provider_payload']
        if isinstance(payload, str):
            try:
                payload = json.loads(payload)
            except ValueError:
                payload = None
        if not isinstance(payload, dict) or payload.get('environment') != environment:
            raise ValueError('environment mismatch')
        payment = dict(topup, klikqris_environment=environment,
                       klikqris_transaction_signature=topup['provider_signature'])
        facts = authenticate_callback(body, environment=environment, payment=payment, encrypter=self.encrypter)
        # Limit the persisted evidence to authenticated facts, never the signature or raw body.
        canonical = json.dumps(facts, sort_keys=True, ensure_ascii=True, separators=(',', ':'))
        key = hashlib.sha256(('klikqris|callback|' + canonical).encode()).hexdigest()
        now = self.funding.clock()
        event = self.db.one('''INSERT INTO public.store_balance_top_up_events
            (store_balance_top_up_id,provider,event_type,merchant_ref,provider_reference,provider_status,
             raw_payload,idempotency_key,received_count,received_at,last_received_at)
            VALUES (%s,'klikqris','callback',%s,%s,%s,%s::jsonb,%s,1,%s,%s)
            ON CONFLICT (provider,event_type,idempotency_key) DO UPDATE SET
                received_count=public.store_balance_top_up_events.received_count+1,
                last_received_at=EXCLUDED.last_received_at
            RETURNING id''', (topup['id'], ref, facts['provider_reference'], facts['status'],
                               canonical, key, now, now))
        return event['id']

    def consume_callback(self, event_id):
        """Recheck a persisted hint independently; failures leave the event retryable."""
        self.funding._outside()
        event = self.db.one('''SELECT id,store_balance_top_up_id,provider_status,processed_at
            FROM public.store_balance_top_up_events
            WHERE id=%s AND provider='klikqris' AND event_type='callback' ''', (event_id,))
        if event is None:
            return TopUpOutcome('stale')
        topup = self.funding.top_up_by_id(event['store_balance_top_up_id'])
        if topup is None or topup['provider'] != 'klikqris':
            return TopUpOutcome('stale', topup)
        if event['processed_at'] is not None:
            return TopUpOutcome('replayed', topup)
        if event['provider_status'] not in ('PAID', 'SUCCESS'):
            result = TopUpOutcome('ignored', topup)
        else:
            result = self.recover(topup['id'])
        if result.state in ('ignored', 'paid', 'compensated', 'replayed'):
            self.db.execute('''UPDATE public.store_balance_top_up_events SET processed_at=%s
                WHERE id=%s AND processed_at IS NULL''', (self.funding.clock(), event_id))
        return result

    def recover(self, topup_id):
        """Observe the original provider transaction without issuing another create."""
        self.funding._outside()
        topup = self.funding.top_up_by_id(topup_id)
        if topup is None or topup['provider'] != 'klikqris':
            return TopUpOutcome('stale', topup)
        if topup['status'] == 'paid':
            return TopUpOutcome('replayed', topup)
        if self.adapter.readiness() or self.encrypter is None:
            return TopUpOutcome('recovery_pending', topup)
        return self._observe(topup, creating=False)

    def check(self, topup):
        self.funding._outside()
        if topup is None or topup['provider'] != 'klikqris' or self.adapter.readiness():
            return topup
        if topup['status'] == 'paid':
            return topup
        return self._observe(topup, creating=False).topup

    def presentation(self, topup):
        if topup is None:
            return {'state': 'missing'}
        deadline = None
        try:
            values = [value for value in (topup['expires_at'], topup['provider_expires_at']) if value]
            deadline = min(_deadline(value) for value in values) if values else None
        except (TypeError, ValueError):
            pass
        expired = topup['status'] == 'expired' or (deadline is not None and deadline <= self.funding.clock())
        url = None
        if topup['status'] == 'payable' and not expired and topup['provider_qris_url']:
            try:
                row = self.db.one('SELECT provider_payload FROM public.store_balance_top_ups WHERE id=%s',
                                  (topup['id'],))
                payload = row['provider_payload'] if row else None
                if isinstance(payload, str):
                    payload = json.loads(payload)
                if not isinstance(payload, dict) or payload.get('environment') != self.adapter.config.environment:
                    raise ValueError('provider environment mismatch')
                candidate = self.encrypter.decrypt_string(topup['provider_qris_url'])
                if valid_qr_url(candidate, self.adapter.config.environment, self.adapter.config.allowed_hosts):
                    url = candidate
            except Exception:
                pass
        paid = topup['status'] == 'paid'
        return {'state': 'paid' if paid else 'payable' if url else 'expired' if expired else 'recovery_pending',
                'paid': paid, 'payable': bool(url), 'expired': expired, 'retryable': False,
                'manual_review': False, 'recovered': paid and topup['provider_create_state'] == 'reconciled_SUCCESS',
                'amount': topup['amount'], 'fee': topup['provider_fee'], 'total': topup['provider_total_payment'],
                'expires': deadline, 'qris_url': url, 'topup_id': str(topup['id'])}

    def _observe(self, topup, *, creating):
        lock = 'klikqris:top-up:' + str(topup['id'])
        if not self.db.one('SELECT pg_try_advisory_lock(hashtextextended(%s,0)) AS acquired', (lock,))['acquired']:
            return TopUpOutcome('busy', topup)
        try:
            current = self.funding.top_up_by_id(topup['id'])
            if current is None or current['provider'] != 'klikqris':
                return TopUpOutcome('stale', current)
            snapshot = self.db.one('SELECT provider_payload FROM public.store_balance_top_ups WHERE id=%s',
                                   (current['id'],))
            payload = snapshot['provider_payload'] if snapshot else None
            if isinstance(payload, str):
                try:
                    payload = json.loads(payload)
                except ValueError:
                    payload = None
            if not isinstance(payload, dict) or payload.get('environment') != self.adapter.config.environment:
                with transaction.atomic(using=self.using):
                    self.funding._lock_customer(current['customer_id'])
                    locked = self.db.one('SELECT ' + ','.join(self.funding.TOPUP_COLUMNS) +
                        ' FROM public.store_balance_top_ups WHERE id=%s FOR UPDATE', (current['id'],))
                    if locked is not None:
                        self._evidence(locked, 'reconciliation', 'environment_unbound')
                return TopUpOutcome('review', current)
            if creating and (current['status'] != 'pending' or current['provider_create_state'] != 'creating'):
                return TopUpOutcome('replayed', current)
            payment = dict(current, method='QRIS')
            result = self.adapter.create(payment) if creating else self.adapter.status(payment)
            observed = result.transaction
            if observed is None:
                if creating:
                    with transaction.atomic(using=self.using):
                        self.funding._lock_customer(current['customer_id'])
                        self.db.execute('''UPDATE public.store_balance_top_ups SET provider_create_state='outcome_unknown',
                            provider_create_last_error='create_outcome_unknown',updated_at=%s
                            WHERE id=%s AND provider_create_state='creating' AND status='pending' ''',
                            (self.funding.clock(), current['id']))
                return TopUpOutcome('recovery_pending', self.funding.top_up_by_id(current['id']))
            if creating:
                return self._persist(current, observed)
            return self._settle(current, observed)
        finally:
            self.db.one('SELECT pg_advisory_unlock(hashtextextended(%s,0)) AS released', (lock,))

    def _persist(self, topup, observed):
        if (observed.status != 'PENDING' or observed.expiry is None
                or _deadline(observed.expiry) <= self.funding.clock()
                or not valid_qr_url(observed.qr_url, self.adapter.config.environment, self.adapter.config.allowed_hosts)
                or (self.adapter.config.environment == 'production' and not observed.signature)):
            return TopUpOutcome('recovery_pending', topup)
        encrypted_url = self.encrypter.encrypt_string(observed.qr_url)
        encrypted_signature = self.encrypter.encrypt_string(observed.signature) if observed.signature else None
        with transaction.atomic(using=self.using):
            self.funding._lock_customer(topup['customer_id'])
            now = self.funding.clock()
            updated = self.db.one('''UPDATE public.store_balance_top_ups SET status='payable',
                provider_reference=%s,provider_qris_url=%s,provider_signature=%s,provider_fee=%s,
                provider_total_payment=%s,provider_created_at=%s,provider_expires_at=%s,
                provider_create_state='created',provider_create_last_error=NULL,updated_at=%s
                WHERE id=%s AND status='pending' AND provider_create_state='creating'
                RETURNING ''' + ','.join(self.funding.TOPUP_COLUMNS),
                (observed.reference, encrypted_url, encrypted_signature, observed.total - observed.amount,
                 observed.total, now, _deadline(observed.expiry), now, topup['id']))
            return TopUpOutcome('payable' if updated else 'replayed', updated or self.funding.top_up_by_id(topup['id']))

    def _trusted_signature(self, encrypted, observed):
        try:
            stored = self.encrypter.decrypt_string(encrypted) if encrypted else None
            incoming = observed.signature
            return bool(stored and incoming and hmac.compare_digest(stored.encode(), incoming.encode()))
        except Exception:
            return False

    def _evidence(self, topup, event_type, reason, reference=None):
        now = self.funding.clock()
        key = hashlib.sha256(f"klikqris|{topup['id']}|{event_type}|{reason}|{reference or ''}".encode()).hexdigest()
        event = self.db.one('''INSERT INTO public.store_balance_top_up_events
            (store_balance_top_up_id,provider,event_type,merchant_ref,provider_reference,provider_status,
             raw_payload,idempotency_key,received_count,received_at,last_received_at,processed_at)
            VALUES (%s,'klikqris',%s,%s,%s,%s,%s::jsonb,%s,1,%s,%s,%s)
            ON CONFLICT (provider,event_type,idempotency_key) DO NOTHING RETURNING id''',
            (topup['id'], event_type, topup['merchant_ref'], reference, reason,
             json.dumps({'provider_status': reason}), key, now, now, now))
        if event is not None:
            action = ('store_balance.top_up.compensation_provider_evidence'
                      if event_type == 'compensation_evidence' else 'store_balance.top_up.reconciliation_review')
            self.funding._audit(topup, action,
                {'status': topup['status'], 'reason': reason, 'provider_event_id': event['id'],
                 'provider_reference_hash': self.funding._reference_hash(reference)}, now)

    def _settle(self, topup, observed):
        with transaction.atomic(using=self.using):
            self.funding._lock_customer(topup['customer_id'])
            self.funding.ledger.lock_for_top_up(topup['customer_id'])
            locked = self.db.one('SELECT ' + ','.join(self.funding.TOPUP_COLUMNS) +
                ' FROM public.store_balance_top_ups WHERE id=%s FOR UPDATE', (topup['id'],))
            if locked is None or locked['status'] == 'paid':
                return TopUpOutcome('replayed', locked)
            payment = dict(locked, method='QRIS')
            try:
                observed.assert_matches(payment, self.adapter.config.environment)
            except ValueError:
                self._evidence(locked, 'reconciliation', 'identity_mismatch')
                return TopUpOutcome('review', locked)
            signature_row = self.db.one('SELECT provider_signature FROM public.store_balance_top_ups WHERE id=%s',
                                        (locked['id'],))
            encrypted_signature = signature_row['provider_signature'] if signature_row else None
            if encrypted_signature:
                if not self._trusted_signature(encrypted_signature, observed):
                    self._evidence(locked, 'reconciliation', 'signature_mismatch')
                    return TopUpOutcome('review', locked)
            elif not observed.signature or self.encrypter is None:
                self._evidence(locked, 'reconciliation', 'signature_missing')
                return TopUpOutcome('review', locked)
            else:
                self.db.execute('''UPDATE public.store_balance_top_ups SET provider_signature=%s,updated_at=%s
                    WHERE id=%s AND provider_signature IS NULL''',
                    (self.encrypter.encrypt_string(observed.signature), self.funding.clock(), locked['id']))
            if locked['provider_total_payment'] is None and locked['provider_fee'] is None:
                if locked['status'] != 'compensated':
                    self.db.execute('''UPDATE public.store_balance_top_ups SET provider_total_payment=%s,
                        provider_fee=%s,provider_reference=COALESCE(provider_reference,%s),updated_at=%s
                        WHERE id=%s''', (observed.total, observed.total - locked['amount'],
                                         observed.reference, self.funding.clock(), locked['id']))
                    locked = dict(locked, provider_total_payment=observed.total,
                                  provider_fee=observed.total - locked['amount'],
                                  provider_reference=locked['provider_reference'] or observed.reference)
            if observed.status != 'SUCCESS':
                if (observed.status == 'PENDING' and locked['status'] == 'pending'
                        and locked['provider_create_state'] in ('creating', 'outcome_unknown')
                        and observed.expiry is not None
                        and _deadline(observed.expiry) > self.funding.clock()
                        and valid_qr_url(observed.qr_url, self.adapter.config.environment,
                                         self.adapter.config.allowed_hosts)):
                    now = self.funding.clock()
                    updated = self.db.one('''UPDATE public.store_balance_top_ups SET status='payable',
                        provider_qris_url=%s,provider_expires_at=%s,provider_create_state='created',
                        provider_create_last_error=NULL,updated_at=%s WHERE id=%s
                        RETURNING ''' + ','.join(self.funding.TOPUP_COLUMNS),
                        (self.encrypter.encrypt_string(observed.qr_url), _deadline(observed.expiry),
                         now, locked['id']))
                    return TopUpOutcome('payable', updated)
                return TopUpOutcome('pending', locked)
            if (locked['status'] != 'compensated'
                    and (type(locked['provider_fee']) is not int
                         or type(locked['provider_total_payment']) is not int
                         or observed.total != locked['provider_total_payment'])):
                self._evidence(locked, 'reconciliation', 'quote_mismatch')
                return TopUpOutcome('review', locked)
            if observed.total < locked['amount']:
                self._evidence(locked, 'reconciliation', 'quote_mismatch')
                return TopUpOutcome('review', locked)
            now = self.funding.clock()
            reference = observed.reference or locked['provider_reference'] or locked['merchant_ref']
            claim = claim_provider_reference(self.db, provider='klikqris', reference=reference,
                beneficiary='customer:' + str(locked['customer_id']), currency='IDR', amount=locked['amount'],
                target_type='balance_top_up', target_id=str(locked['id']), now=now, using=self.using,
                account_namespace='setting:' + str(locked['payment_setting_id']) if locked['payment_setting_id'] else 'default')
            if claim.held:
                self._evidence(locked, 'reconciliation', 'receipt_' + claim.status)
                return TopUpOutcome('review', locked)
            if locked['status'] == 'compensated':
                self.db.execute('''UPDATE public.external_money_receipt_reservations SET resolved_at=%s,updated_at=%s
                    WHERE legacy_type='store_balance_top_up' AND legacy_id=%s
                    AND reason='compensated_unresolved_money' AND resolved_at IS NULL''',
                    (now, now, str(locked['id'])))
                self._evidence(locked, 'compensation_evidence', 'SUCCESS', reference)
                return TopUpOutcome('compensated', locked)
            entry = self.funding.ledger.credit_top_up(locked['customer_id'], str(locked['id']), locked['amount'],
                                                      'balance_top_up:' + str(locked['id']) + ':credit')
            late = locked['status'] not in ('pending', 'payable', 'awaiting_confirmation') or any(
                value is None or _deadline(value) <= now for value in (locked['expires_at'], locked['provider_expires_at']))
            updated = self.db.one('''UPDATE public.store_balance_top_ups SET status='paid',paid_at=%s,settled_at=%s,
                provider_create_state=%s,updated_at=%s WHERE id=%s RETURNING ''' + ','.join(self.funding.TOPUP_COLUMNS),
                (now, now, 'reconciled_SUCCESS' if late else 'provider_SUCCESS', now, locked['id']))
            event_key = hashlib.sha256(f"klikqris|{locked['id']}|settlement".encode()).hexdigest()
            event = self.db.one('''INSERT INTO public.store_balance_top_up_events
                (store_balance_top_up_id,provider,event_type,merchant_ref,provider_reference,provider_status,
                 raw_payload,idempotency_key,received_count,received_at,last_received_at,processed_at)
                VALUES (%s,'klikqris','settlement',%s,%s,'SUCCESS',%s::jsonb,%s,1,%s,%s,%s)
                ON CONFLICT (provider,event_type,idempotency_key) DO NOTHING RETURNING id''',
                (locked['id'], locked['merchant_ref'], reference, json.dumps({'provider_status': 'SUCCESS'}),
                 event_key, now, now, now))
            if event is None:
                raise ValueError('settlement event conflict')
            self.funding._audit(locked, 'store_balance.top_up.late_settled' if late
                                else 'store_balance.top_up.settled',
                                {'status': 'paid', 'amount': locked['amount'], 'late_settlement': late,
                                 'provider_event_id': event['id'], 'ledger_entry_id': entry['id']}, now)
            buyer = self.db.one('SELECT telegram_id,locale FROM public.customers WHERE id=%s', (locked['customer_id'],))
            record_paid(self.db, locked, buyer, now)
            return TopUpOutcome('paid', updated)
