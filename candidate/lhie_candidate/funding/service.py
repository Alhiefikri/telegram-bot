"""Store Balance funding over the existing IDR ledger and receipt registry.

Manual and KlikQRIS lifecycles require explicit injection and readiness;
default composition activates no provider. Provider calls occur outside locks.
"""

from dataclasses import dataclass
from datetime import datetime, timedelta
import hashlib
import json
import re
import secrets
from urllib.parse import urlsplit
from zoneinfo import ZoneInfo

from django.db import DatabaseError, connections, transaction

from lhie_candidate.checkout.balance import StoreBalanceLedger
from lhie_candidate.checkout.db import Database
from lhie_candidate.compat.crypto import CryptoError
from lhie_candidate.domain.money import MAX_UNITS, require_integer
from lhie_candidate.payments.receipts import claim_provider_reference
from lhie_candidate.payments.sakurupiah import CreateRequest, InvoiceIdentity, OutcomeState

from .policy import PolicyError, SINGLETON_KEY, parse_amount, parse_preset_callback, policy_from_row


INTENT_TTL = timedelta(minutes=10)
CHANNELS = ('QRIS', 'QRISC', 'QRISMU', 'QRIS2')
_NONCE = re.compile(r'[a-f0-9]{32}\Z')
_TOPUP_ID = re.compile(r'[1-9][0-9]{0,18}\Z')
_MAX_REFERENCE = 191


class FundingError(ValueError):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


@dataclass(frozen=True, slots=True)
class IntentOutcome:
    state: str
    intent: dict | None = None


@dataclass(frozen=True, slots=True)
class TopUpOutcome:
    state: str
    topup: dict | None = None


def _now():
    return datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None, microsecond=0)


def _value(root, name):
    return root.get(name) if isinstance(root, dict) else getattr(root, name, None)


def _secure_qris_url(value):
    if not isinstance(value, str) or len(value) > 2048 or value.strip() != value:
        return None
    if any(ord(char) <= 32 or ord(char) >= 127 or char == '\\' for char in value):
        return None
    try:
        parts = urlsplit(value)
        if (parts.scheme != 'https' or parts.hostname != 'sakurupiah.id'
                or parts.netloc != 'sakurupiah.id' or parts.fragment
                or parts.username is not None or parts.password is not None or parts.port is not None):
            return None
        return value
    except ValueError:
        return None


def _deadline(value):
    if isinstance(value, str):
        value = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if not isinstance(value, datetime):
        raise ValueError('invalid deadline')
    return value.astimezone(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None) if value.tzinfo else value


def _event_key(*parts):
    return hashlib.sha256('|'.join(str(part) for part in parts).encode()).hexdigest()


class FundingService:
    TOPUP_COLUMNS = (
        'id customer_id store_balance_funding_intent_id telegram_navigation_root_id chat_id '
        'canonical_message_id locale provider payment_setting_id payment_provider_version merchant_ref idempotency_key currency amount status '
        'provider_reference provider_qris_url provider_fee provider_total_payment provider_payment_method '
        'sakurupiah_channel provider_created_at provider_expires_at expires_at provider_create_state '
        'provider_create_attempted_at provider_create_last_error paid_at settled_at created_at updated_at'
    ).split()
    INTENT_COLUMNS = (
        'id customer_id telegram_navigation_root_id chat_id canonical_message_id policy_version '
        'amount nonce status expires_at claimed_at created_at updated_at'
    ).split()

    def __init__(self, *, provider=None, request_factory=None, encrypter=None,
                 callback_key=None, ledger=None, using='default', clock=None):
        self.db = Database(using)
        self.using = using
        self.provider = provider
        self.request_factory = request_factory
        self.encrypter = encrypter
        self.callback_key = callback_key
        self.ledger = ledger or StoreBalanceLedger(using)
        self.clock = clock or _now
        self.rails = {}

    def register_rail(self, provider, rail, *, ready):
        if provider not in ('manual', 'klikqris') or not callable(ready):
            raise ValueError('invalid funding rail')
        self.rails[provider] = (rail, ready)

    # -- reads ---------------------------------------------------------

    def _outside(self):
        connection = connections[self.using]
        if connection.vendor != 'postgresql' or connection.in_atomic_block or not connection.get_autocommit():
            raise RuntimeError('Funding provider I/O requires an outermost transaction boundary')

    def _policy(self, *, lock=False):
        row = self.db.one(
            'SELECT id,singleton_key,minimum_amount,preset_amounts,version '
            'FROM public.store_balance_funding_policies WHERE singleton_key=%s'
            + (' FOR UPDATE' if lock else ''), (SINGLETON_KEY,))
        try:
            return policy_from_row(row)
        except PolicyError:
            return None

    def _setting(self, *, lock=False):
        return self.db.one(
            'SELECT id,is_active,payment_mode,sakurupiah_channel,payment_provider_version '
            'FROM public.manual_payment_settings ORDER BY is_active DESC,id LIMIT 1'
            + (' FOR UPDATE' if lock else ''))

    def _available(self, policy, setting):
        if policy is None or setting is None or not setting['is_active']:
            return False
        provider = (setting['payment_mode'] or '').strip().lower()
        if provider in self.rails:
            try:
                return bool(self.rails[provider][1]())
            except Exception:
                return False
        return bool(provider == 'sakurupiah' and setting['sakurupiah_channel'] in CHANNELS
                    and self.provider is not None and self.request_factory is not None
                    and self.encrypter is not None)

    def chooser(self):
        """Return the current DB policy and selected rail's readiness."""
        policy = self._policy()
        setting = self._setting()
        return {'policy': policy, 'available': self._available(policy, setting),
                'channel': setting['sakurupiah_channel'] if setting else None}

    def policy_for(self):
        return self._policy()

    def parse_preset(self, data):
        return parse_preset_callback(self.callback_key, self._policy(), data)

    # -- intent --------------------------------------------------------

    def _root_matches(self, root, *, customer_id, chat_id, message_id, lock=False):
        scope = _value(root, 'bot_asset_scope')
        if (not isinstance(scope, str) or not scope or type(customer_id) is not int
                or customer_id < 1 or not isinstance(chat_id, str) or not chat_id
                or type(message_id) is not int or message_id < 1):
            return False
        return self.db.one(
            'SELECT 1 AS present FROM django_candidate.navigation_roots '
            'WHERE bot_asset_scope=%s AND customer_id=%s AND chat_id=%s AND message_id=%s'
            + (' FOR SHARE' if lock else ''),
            (scope, customer_id, chat_id, message_id)) is not None

    def _lock_customer(self, customer_id):
        require_integer(customer_id, minimum=1)
        customer = self.db.one('SELECT id,telegram_id,locale FROM public.customers WHERE id=%s FOR UPDATE',
                               (customer_id,))
        if customer is None:
            raise FundingError('unknown_customer')
        return customer

    def open_intent(self, customer, root, *, preset=None, raw_amount=None):
        """Open or replay one exact amount target. No provider call, no money."""
        customer_id = require_integer(customer.get('id') if isinstance(customer, dict)
                                      else customer.id, minimum=1)
        chat_id = str(_value(root, 'chat_id') or '')
        message_id = _value(root, 'message_id')
        if not chat_id or type(message_id) is not int or message_id < 1:
            return IntentOutcome('stale')
        try:
            policy = self._policy()
            setting = self._setting()
        except Exception:
            return IntentOutcome('unavailable')
        if not self._available(policy, setting):
            return IntentOutcome('unavailable')
        amount = preset.amount if preset is not None else parse_amount(raw_amount)
        if amount is None:
            return IntentOutcome('invalid')
        if amount < policy.minimum_amount:
            return IntentOutcome('below_minimum')
        if preset is not None and (preset.policy_version != policy.version
                                   or type(preset.index) is not int
                                   or not 0 <= preset.index < len(policy.preset_amounts)
                                   or policy.preset_amounts[preset.index] != preset.amount):
            return IntentOutcome('stale_policy')

        for attempt in range(3):
            try:
                return self._open_intent_once(customer_id, chat_id, message_id, root, policy, amount)
            except DatabaseError as error:
                state = getattr(error.__cause__, 'sqlstate', None)
                if state not in ('23505', '40001', '40P01') or attempt == 2:
                    if state == '23505':
                        return IntentOutcome('stale')
                    raise
        return IntentOutcome('stale')

    def _open_intent_once(self, customer_id, chat_id, message_id, root, policy, amount):
        with transaction.atomic(using=self.using):
            self._lock_customer(customer_id)
            if not self._root_matches(root, customer_id=customer_id, chat_id=chat_id, message_id=message_id, lock=True):
                return IntentOutcome('stale')
            locked_policy = self._policy(lock=True)
            if locked_policy is None or locked_policy.version != policy.version:
                return IntentOutcome('stale_policy')
            policy = locked_policy
            current = self.db.one(
                'SELECT ' + ','.join(self.INTENT_COLUMNS) + ' FROM public.store_balance_funding_intents '
                'WHERE customer_id=%s AND status=%s ORDER BY id DESC LIMIT 1 FOR UPDATE',
                (customer_id, 'pending'))
            now = self.clock()
            if current is not None and _deadline(current['expires_at']) <= now:
                self.db.execute(
                    'UPDATE public.store_balance_funding_intents SET status=%s,claimed_at=%s,updated_at=%s '
                    'WHERE id=%s AND status=%s', ('expired', now, now, current['id'], 'pending'))
                current = None
            if current is not None:
                if (current['chat_id'] == chat_id and current['canonical_message_id'] == message_id
                        and current['policy_version'] == policy.version and current['amount'] == amount):
                    return IntentOutcome('replayed', current)
                self.db.execute(
                    'UPDATE public.store_balance_funding_intents SET status=%s,claimed_at=%s,updated_at=%s '
                    'WHERE id=%s AND status=%s', ('superseded', now, now, current['id'], 'pending'))
            intent = self.db.one(
                '''INSERT INTO public.store_balance_funding_intents
                   (customer_id,telegram_navigation_root_id,chat_id,canonical_message_id,policy_version,
                    amount,nonce,status,expires_at,claimed_at,created_at,updated_at)
                   VALUES (%s,NULL,%s,%s,%s,%s,%s,'pending',%s,NULL,%s,%s)
                   RETURNING ''' + ','.join(self.INTENT_COLUMNS),
                (customer_id, chat_id, message_id, policy.version, amount,
                 secrets.token_hex(16), now + INTENT_TTL, now, now))
            return IntentOutcome('opened', intent)

    def claim(self, customer, root, *, nonce, action):
        customer_id = require_integer(customer.get('id') if isinstance(customer, dict)
                                      else customer.id, minimum=1)
        chat_id = str(_value(root, 'chat_id') or '')
        message_id = _value(root, 'message_id')
        if (action not in ('confirm', 'cancel', 'back') or type(nonce) is not str
                or _NONCE.fullmatch(nonce) is None or not chat_id
                or type(message_id) is not int or message_id < 1):
            return IntentOutcome('stale')
        with transaction.atomic(using=self.using):
            self._lock_customer(customer_id)
            if not self._root_matches(root, customer_id=customer_id, chat_id=chat_id, message_id=message_id, lock=True):
                return IntentOutcome('stale')
            intent = self.db.one(
                'SELECT ' + ','.join(self.INTENT_COLUMNS) + ' FROM public.store_balance_funding_intents '
                'WHERE customer_id=%s AND nonce=%s FOR UPDATE', (customer_id, nonce))
            if (intent is None or intent['chat_id'] != chat_id
                    or intent['canonical_message_id'] != message_id):
                return IntentOutcome('stale')
            if intent['status'] == 'confirmed':
                return IntentOutcome('already_confirmed', intent)
            if intent['status'] == 'cancelled':
                return IntentOutcome('already_cancelled', intent)
            if intent['status'] != 'pending':
                return IntentOutcome(intent['status'], intent)
            now = self.clock()
            policy = self._policy(lock=True)
            if policy is None or policy.version != intent['policy_version']:
                self.db.execute(
                    'UPDATE public.store_balance_funding_intents SET status=%s,claimed_at=%s,updated_at=%s '
                    'WHERE id=%s AND status=%s', ('superseded', now, now, intent['id'], 'pending'))
                return IntentOutcome('stale_policy', intent)
            if _deadline(intent['expires_at']) <= now:
                self.db.execute(
                    'UPDATE public.store_balance_funding_intents SET status=%s,claimed_at=%s,updated_at=%s '
                    'WHERE id=%s AND status=%s', ('expired', now, now, intent['id'], 'pending'))
                return IntentOutcome('expired', intent)
            status = 'confirmed' if action == 'confirm' else 'cancelled'
            intent = self.db.one(
                'UPDATE public.store_balance_funding_intents SET status=%s,claimed_at=%s,updated_at=%s '
                'WHERE id=%s AND status=%s RETURNING ' + ','.join(self.INTENT_COLUMNS),
                (status, now, now, intent['id'], 'pending'))
            if intent is None:
                return IntentOutcome('stale')
            return IntentOutcome('confirmed' if action == 'confirm' else 'cancelled', intent)

    def _topup_by_intent(self, intent_id, *, lock=False):
        return self.db.one(
            'SELECT ' + ','.join(self.TOPUP_COLUMNS) + ' FROM public.store_balance_top_ups '
            'WHERE store_balance_funding_intent_id=%s' + (' FOR UPDATE' if lock else ''),
            (intent_id,))

    def _locked_confirmed_intent(self, customer_id, intent_id, chat_id, message_id):
        intent = self.db.one(
            'SELECT ' + ','.join(self.INTENT_COLUMNS) + ' FROM public.store_balance_funding_intents '
            'WHERE id=%s AND customer_id=%s FOR UPDATE', (intent_id, customer_id))
        if (intent is None or intent['status'] != 'confirmed' or intent['chat_id'] != chat_id
                or intent['canonical_message_id'] != message_id):
            raise FundingError('intent_not_confirmed')
        return intent

    def create_top_up(self, customer, root, intent_id):
        """Create/reuse one top-up for a confirmed intent, then call the provider."""
        self._outside()
        customer_id = require_integer(customer.get('id') if isinstance(customer, dict)
                                      else customer.id, minimum=1)
        chat_id = str(_value(root, 'chat_id') or '')
        message_id = _value(root, 'message_id')
        if not chat_id or type(message_id) is not int or message_id < 1:
            return TopUpOutcome('stale')
        existing = self._topup_by_intent(intent_id)
        setting = self._setting()
        provider = existing['provider'] if existing is not None else (
            (setting['payment_mode'] or '').strip().lower() if setting else '')
        if provider in ('manual', 'klikqris'):
            registration = self.rails.get(provider)
            if registration is None or (existing is None and not self._available(self._policy(), setting)):
                return TopUpOutcome('unavailable')
            return registration[0].create(customer, root, intent_id)
        try:
            with transaction.atomic(using=self.using):
                self._lock_customer(customer_id)
                if not self._root_matches(root, customer_id=customer_id, chat_id=chat_id, message_id=message_id, lock=True):
                    return TopUpOutcome('stale')
                intent = self._locked_confirmed_intent(customer_id, intent_id, chat_id, message_id)
                existing = self._topup_by_intent(intent['id'], lock=True)
                if existing is not None:
                    return TopUpOutcome('replayed', existing)
                setting = self._setting(lock=True)
                policy = self._policy(lock=True)
                if (setting is None or (setting['payment_mode'] or '').strip().lower() != 'sakurupiah'
                        or not self._available(policy, setting) or policy.version != intent['policy_version']):
                    return TopUpOutcome('unavailable')
                amount = require_integer(intent['amount'], minimum=1)
                if amount < policy.minimum_amount or amount > MAX_UNITS:
                    return TopUpOutcome('invalid')
                now = self.clock()
                merchant_ref = self._merchant_reference(intent)
                topup = self.db.one(
                    '''INSERT INTO public.store_balance_top_ups
                       (customer_id,store_balance_funding_intent_id,telegram_navigation_root_id,chat_id,
                        canonical_message_id,locale,provider,payment_setting_id,payment_provider_version,
                        merchant_ref,idempotency_key,currency,amount,sakurupiah_channel,status,
                        provider_create_state,provider_create_attempted_at,expires_at,created_at,updated_at)
                       VALUES (%s,%s,NULL,%s,%s,%s,'sakurupiah',%s,%s,%s,%s,'IDR',%s,%s,'pending','creating',
                               %s,%s,%s,%s)
                       RETURNING ''' + ','.join(self.TOPUP_COLUMNS),
                    (customer_id, intent['id'], chat_id, message_id,
                     (customer.get('locale') if isinstance(customer, dict) else getattr(customer, 'locale', None)),
                     setting['id'], setting['payment_provider_version'], merchant_ref,
                     self._idempotency_key(intent), amount, setting['sakurupiah_channel'],
                     now, _deadline(intent['expires_at']), now, now))
        except FundingError:
            raise
        except DatabaseError as error:
            if getattr(error.__cause__, 'sqlstate', None) == '23505':
                existing = self._topup_by_intent(intent_id)
                if existing is not None:
                    return TopUpOutcome('replayed', existing)
            raise
        return self._start_provider(topup, chat_id=chat_id, message_id=message_id)

    @staticmethod
    def _merchant_reference(intent):
        # PHP StoreBalanceTopUpService parity: BAL-<intent>-<nonce hash prefix>.
        return 'BAL-' + str(intent['id']) + '-' + hashlib.sha256(intent['nonce'].encode()).hexdigest()[:16]

    @staticmethod
    def _idempotency_key(intent):
        return 'balance-top-up:intent:' + str(intent['id'])

    def _provider_identity(self, topup):
        return InvoiceIdentity(topup['merchant_ref'], int(topup['amount']), topup['sakurupiah_channel'])

    def _start_provider(self, topup, *, chat_id, message_id):
        """Call the injected sandbox provider after the local top-up committed."""
        self._outside()
        request = None
        outcome = None
        dispatched = False
        try:
            request = self.request_factory(topup)
            if not isinstance(request, CreateRequest) or request.identity != self._provider_identity(topup):
                raise FundingError('provider_unavailable')
            dispatched = True
            outcome = self.provider.create(request)
        except Exception:
            pass
        with transaction.atomic(using=self.using):
            self._lock_customer(topup['customer_id'])
            locked = self.db.one(
                'SELECT ' + ','.join(self.TOPUP_COLUMNS) + ' FROM public.store_balance_top_ups '
                'WHERE id=%s AND customer_id=%s FOR UPDATE', (topup['id'], topup['customer_id']))
            if (locked is None or locked['chat_id'] != chat_id
                    or locked['canonical_message_id'] != message_id):
                return TopUpOutcome('stale', locked)
            if locked['status'] in ('paid', 'compensated'):
                return TopUpOutcome('paid', locked)
            if locked['provider_create_state'] not in ('creating', 'create_failed', 'provider_unavailable'):
                return TopUpOutcome('replayed', locked)
            now = self.clock()
            if not dispatched or (outcome is not None and outcome.state is OutcomeState.REJECTED):
                self.db.execute(
                    '''UPDATE public.store_balance_top_ups SET status='failed',
                       provider_create_state='create_failed',provider_create_last_error='provider_rejected',
                       updated_at=%s WHERE id=%s AND status='pending'
                       AND provider_create_state='creating' ''', (now, locked['id']))
                return TopUpOutcome('retryable', self._topup_by_intent(locked['store_balance_funding_intent_id']))
            if outcome is None or outcome.state is OutcomeState.UNKNOWN:
                self.db.execute(
                    '''UPDATE public.store_balance_top_ups SET provider_create_state='outcome_unknown',
                       provider_create_last_error='create_outcome_unknown',updated_at=%s
                       WHERE id=%s AND provider_create_state='creating' ''', (now, locked['id']))
                return TopUpOutcome('recovery_pending', self._topup_by_intent(locked['store_balance_funding_intent_id']))
            if not self._valid_created(outcome, locked, request):
                self.db.execute(
                    '''UPDATE public.store_balance_top_ups
                       SET status='invalid_provider_result',provider_create_state='provider_invalid_result',
                           provider_create_last_error='invalid_invoice',updated_at=%s
                       WHERE id=%s AND status='pending' ''', (now, locked['id']))
                return TopUpOutcome('invalid', self._topup_by_intent(locked['store_balance_funding_intent_id']))
            payload = {'mode': 'qris_image', 'customer_pays_fee': True,
                       'effective_expires_at': _deadline(outcome.expiry).isoformat()}
            savepoint = transaction.savepoint(using=self.using)
            try:
                encrypted = self.encrypter.encrypt_json({'qris_image_url': outcome.qr})
                updated = self.db.one(
                    '''UPDATE public.store_balance_top_ups
                       SET status='payable',provider_reference=%s,provider_payload=%s::jsonb,
                           provider_qris_url=%s,provider_fee=%s,provider_total_payment=%s,
                           provider_payment_method=%s,provider_created_at=%s,provider_expires_at=%s,
                           provider_create_state='created',provider_create_last_error=NULL,updated_at=%s
                       WHERE id=%s AND status='pending' AND provider_create_state='creating'
                       RETURNING ''' + ','.join(self.TOPUP_COLUMNS),
                    (outcome.provider_ref, json.dumps(payload), encrypted, outcome.fee, outcome.total,
                     locked['sakurupiah_channel'], now, _deadline(outcome.expiry), now, locked['id']))
                transaction.savepoint_commit(savepoint, using=self.using)
            except Exception:
                transaction.savepoint_rollback(savepoint, using=self.using)
                updated = None
            if updated is None:
                self.db.execute(
                    '''UPDATE public.store_balance_top_ups SET provider_create_state='outcome_unknown',
                       provider_create_last_error='provider_persist_unconfirmed',updated_at=%s
                       WHERE id=%s AND provider_create_state='creating' ''', (now, locked['id']))
                return TopUpOutcome('recovery_pending', self._topup_by_intent(locked['store_balance_funding_intent_id']))
            self._log_event(updated, 'create', 'created', outcome.provider_ref, now)
            return TopUpOutcome('payable', updated)

    def _valid_created(self, outcome, topup, request):
        if (request.identity != self._provider_identity(topup)
                or outcome.identity != request.identity or outcome.state is not OutcomeState.CREATED
                or not isinstance(outcome.provider_ref, str) or not outcome.provider_ref
                or len(outcome.provider_ref) > _MAX_REFERENCE
                or type(outcome.fee) is not int or outcome.fee < 0
                or type(outcome.total) is not int
                or outcome.total != int(topup['amount']) + (outcome.fee if request.merchant_fee == 2 else 0)):
            return False
        try:
            if _deadline(outcome.expiry) <= self.clock():
                return False
        except (TypeError, ValueError):
            return False
        return _secure_qris_url(outcome.qr) is not None

    def retry(self, topup):
        """Definite create failures may reuse the same local top-up identity."""
        self._outside()
        with transaction.atomic(using=self.using):
            self._lock_customer(topup['customer_id'])
            locked = self.db.one(
                'SELECT ' + ','.join(self.TOPUP_COLUMNS) + ' FROM public.store_balance_top_ups '
                'WHERE id=%s AND customer_id=%s FOR UPDATE', (topup['id'], topup['customer_id']))
            if locked is None:
                return TopUpOutcome('stale')
            if (locked['status'] != 'failed'
                    or locked['provider_create_state'] not in ('create_failed', 'provider_unavailable')):
                return TopUpOutcome('replayed', locked)
            now = self.clock()
            locked = self.db.one(
                '''UPDATE public.store_balance_top_ups SET status='pending',
                   provider_create_state='creating',provider_create_last_error=NULL,
                   provider_create_attempted_at=%s,updated_at=%s
                   WHERE id=%s AND status='failed' RETURNING ''' + ','.join(self.TOPUP_COLUMNS),
                (now, now, locked['id']))
        if locked is None:
            return TopUpOutcome('stale')
        return self._start_provider(locked, chat_id=locked['chat_id'],
                                    message_id=locked['canonical_message_id'])

    # -- observation and settlement -----------------------------------

    def top_up_by_id(self, topup_id):
        if type(topup_id) is not int or topup_id < 1:
            raise ValueError('invalid top-up identity')
        return self.db.one(
            'SELECT ' + ','.join(self.TOPUP_COLUMNS) + ' FROM public.store_balance_top_ups WHERE id=%s',
            (topup_id,))

    def top_up_for_action(self, customer, root, topup_id):
        customer_id = require_integer(customer.get('id') if isinstance(customer, dict)
                                      else customer.id, minimum=1)
        chat_id = str(_value(root, 'chat_id') or '')
        message_id = _value(root, 'message_id')
        if (type(topup_id) is not str or _TOPUP_ID.fullmatch(topup_id) is None
                or not chat_id or type(message_id) is not int):
            return None
        topup = self.db.one(
            'SELECT ' + ','.join(self.TOPUP_COLUMNS) + ' FROM public.store_balance_top_ups '
            'WHERE id=%s AND customer_id=%s AND chat_id=%s AND canonical_message_id=%s',
            (topup_id, customer_id, chat_id, message_id))
        if topup is None:
            return None
        return topup if self._root_matches(
            root, customer_id=customer_id, chat_id=chat_id, message_id=message_id) else None

    def expire_if_due(self, topup_id):
        require_integer(topup_id, minimum=1)
        with transaction.atomic(using=self.using):
            topup = self.top_up_by_id(topup_id)
            if topup is None:
                return False
            self._lock_customer(topup['customer_id'])
            locked = self.db.one(
                'SELECT ' + ','.join(self.TOPUP_COLUMNS) + ' FROM public.store_balance_top_ups '
                'WHERE id=%s AND customer_id=%s FOR UPDATE', (topup_id, topup['customer_id']))
            if locked is None or locked['status'] in ('paid', 'compensated', 'expired', 'invalid_provider_result'):
                return False
            now = self.clock()
            if not any(value is not None and _deadline(value) <= now
                       for value in (locked['expires_at'], locked['provider_expires_at'])):
                return False
            self.db.execute(
                "UPDATE public.store_balance_top_ups SET status='expired', "
                "provider_create_state='provider_expired',provider_create_last_error=NULL,updated_at=%s "
                "WHERE id=%s", (now, topup_id))
            return True

    def check(self, topup):
        """Observe provider state outside the DB, then settle exactly once."""
        self._outside()
        if topup is None:
            raise FundingError('topup_missing')
        if topup['provider'] in self.rails:
            return self.rails[topup['provider']][0].check(topup)
        if topup['provider'] != 'sakurupiah' or self.provider is None:
            return topup
        outcome = None
        try:
            total = topup['provider_total_payment']
            identity = self._provider_identity(topup)
            if topup['status'] == 'paid':
                return topup
            if topup['provider_reference'] and (total is None or type(total) is int and total > 0):
                outcome = self.provider.status(identity, topup['provider_reference'], provider_total=total)
            elif topup['provider_create_state'] in ('creating', 'outcome_unknown', 'recovery_unconfirmed'):
                recovered = self.provider.recover(identity, provider_total=total)
                if recovered.provider_ref and recovered.state is not OutcomeState.UNKNOWN:
                    outcome = self.provider.status(identity, recovered.provider_ref, provider_total=total)
        except Exception:
            outcome = None
        if outcome is None or outcome.state is OutcomeState.UNKNOWN:
            return topup
        return self._settle_observation(topup, outcome)

    def _settle_observation(self, topup, outcome):
        with transaction.atomic(using=self.using):
            customer = self._lock_customer(topup['customer_id'])
            self.ledger.lock_for_top_up(topup['customer_id'])
            locked = self.db.one(
                'SELECT ' + ','.join(self.TOPUP_COLUMNS) + ' FROM public.store_balance_top_ups '
                'WHERE id=%s AND customer_id=%s FOR UPDATE', (topup['id'], topup['customer_id']))
            if locked is None:
                return TopUpOutcome('stale')
            if locked['status'] == 'paid':
                return locked
            now = self.clock()
            if locked['status'] == 'compensated':
                if not self._compensated_observation_matches(locked, outcome):
                    self._review_evidence(locked, 'identity_mismatch', now)
                    return locked
                claim = claim_provider_reference(
                    self.db, provider='sakurupiah', reference=outcome.provider_ref,
                    beneficiary='customer:' + str(locked['customer_id']), currency='IDR',
                    amount=int(locked['amount']), target_type='balance_top_up',
                    target_id=str(locked['id']), now=now, using=self.using,
                    account_namespace=('default' if locked['payment_setting_id'] is None
                                       else 'setting:' + str(locked['payment_setting_id'])))
                if not claim.held:
                    self.db.execute(
                        '''UPDATE public.external_money_receipt_reservations
                           SET resolved_at=%s,updated_at=%s
                           WHERE legacy_type='store_balance_top_up' AND legacy_id=%s
                           AND reason='compensated_unresolved_money' AND resolved_at IS NULL''',
                        (now, now, str(locked['id'])))
                    event, created = self._log_event(locked, 'compensation_evidence', 'berhasil', outcome.provider_ref, now)
                    if created:
                        self._audit(locked, 'store_balance.top_up.compensation_provider_evidence',
                                    {'status': 'compensated', 'provider_event_id': event['id'],
                                     'provider_reference_hash': self._reference_hash(outcome.provider_ref),
                                     'late_evidence': True}, now)
                else:
                    self._review_evidence(locked, 'receipt_' + claim.status, now)
                return locked
            if outcome.state is OutcomeState.SUCCESS and locked['provider_total_payment'] is None:
                self.db.execute(
                    """UPDATE public.store_balance_top_ups SET status='invalid_provider_result',
                       provider_create_state='reconciliation_review',
                       provider_create_last_error='quote_conflict',updated_at=%s
                       WHERE id=%s AND status NOT IN ('paid','compensated','expired')""", (now, locked['id']))
                self._review_evidence(locked, 'quote_conflict', now)
                return self._topup_by_intent(locked['store_balance_funding_intent_id'])
            if not self._observation_matches(locked, outcome):
                self.db.execute(
                    '''UPDATE public.store_balance_top_ups SET status='invalid_provider_result',
                       provider_create_state='reconciliation_review',
                       provider_create_last_error='provider_identity_mismatch',updated_at=%s
                       WHERE id=%s AND status NOT IN ('paid','compensated','expired') ''', (now, locked['id']))
                self._review_evidence(locked, 'identity_mismatch', now)
                return self._topup_by_intent(locked['store_balance_funding_intent_id'])
            if outcome.state is OutcomeState.SUCCESS:
                deadlines = (locked['expires_at'], locked['provider_expires_at'])
                late = (locked['status'] not in ('pending', 'payable', 'awaiting_confirmation')
                        or any(value is None or _deadline(value) <= now for value in deadlines))
                claim = claim_provider_reference(
                    self.db, provider='sakurupiah', reference=outcome.provider_ref,
                    beneficiary='customer:' + str(locked['customer_id']), currency='IDR',
                    amount=int(locked['amount']), target_type='balance_top_up',
                    target_id=str(locked['id']), now=now, using=self.using,
                    account_namespace=('default' if locked['payment_setting_id'] is None
                                       else 'setting:' + str(locked['payment_setting_id'])))
                if claim.held:
                    self.db.execute(
                        '''UPDATE public.store_balance_top_ups SET status='invalid_provider_result',
                           provider_create_state='reconciliation_review',
                           provider_create_last_error=%s,updated_at=%s
                           WHERE id=%s AND status NOT IN ('paid','compensated') ''',
                        ('receipt_' + claim.status, now, locked['id']))
                    self._review_evidence(locked, 'receipt_' + claim.status, now)
                    return self._topup_by_intent(locked['store_balance_funding_intent_id'])
                entry = self.ledger.credit_top_up(
                    locked['customer_id'], str(locked['id']), int(locked['amount']),
                    'balance_top_up:' + str(locked['id']) + ':credit')
                settled = self.db.one(
                    '''UPDATE public.store_balance_top_ups SET status='paid',paid_at=%s,settled_at=%s,
                       provider_create_state=%s,provider_create_last_error=NULL,updated_at=%s
                       WHERE id=%s AND status NOT IN ('paid','compensated')
                       RETURNING ''' + ','.join(self.TOPUP_COLUMNS),
                    (now, now, 'reconciled_berhasil' if late else 'provider_berhasil', now, locked['id']))
                if settled is None:
                    raise FundingError('settlement_state_conflict')
                event, _ = self._log_event(settled, 'settlement', 'berhasil', outcome.provider_ref, now)
                self._audit(locked, 'store_balance.top_up.late_settled' if late
                            else 'store_balance.top_up.settled',
                            {'status': 'paid', 'amount': locked['amount'],
                             'provider_total_payment': locked['provider_total_payment'],
                             'provider_reference_hash': self._reference_hash(outcome.provider_ref),
                             'provider_event_id': event['id'], 'credit_entry_id': entry['id'],
                             'balance_after': entry['balance_after'], 'late_settlement': late}, now)
                from .outbound import record_paid
                record_paid(self.db, settled, customer, now)
                return settled
            if outcome.state is OutcomeState.EXPIRED:
                expired = self.db.one(
                    '''UPDATE public.store_balance_top_ups SET status='expired',
                       provider_create_state='provider_expired',provider_create_last_error=NULL,updated_at=%s
                       WHERE id=%s AND status NOT IN ('paid','compensated','expired')
                       RETURNING ''' + ','.join(self.TOPUP_COLUMNS), (now, locked['id']))
                if expired is not None:
                    self._log_event(expired, 'status', 'expired', outcome.provider_ref, now)
                return expired or self._topup_by_intent(locked['store_balance_funding_intent_id'])
            self._log_event(locked, 'status', 'pending', outcome.provider_ref, now)
            return self._topup_by_intent(locked['store_balance_funding_intent_id'])

    def _compensated_observation_matches(self, topup, outcome):
        identity = outcome.identity
        return (topup['provider'] == 'sakurupiah' and topup['currency'] == 'IDR'
                and isinstance(identity, InvoiceIdentity)
                and identity.merchant_ref == topup['merchant_ref']
                and identity.amount == topup['amount']
                and identity.channel == topup['sakurupiah_channel']
                and outcome.state is OutcomeState.SUCCESS
                and isinstance(outcome.provider_ref, str) and bool(outcome.provider_ref)
                and len(outcome.provider_ref) <= _MAX_REFERENCE
                and (not topup['provider_reference'] or outcome.provider_ref == topup['provider_reference'])
                and type(outcome.total) is int and outcome.total >= topup['amount'])

    def _observation_matches(self, topup, outcome):
        if (topup['provider'] != 'sakurupiah' or not isinstance(outcome.identity, InvoiceIdentity)
                or outcome.identity.merchant_ref != topup['merchant_ref']
                or int(outcome.identity.amount) != int(topup['amount'])
                or outcome.identity.channel != topup['sakurupiah_channel']
                or not isinstance(outcome.provider_ref, str) or not outcome.provider_ref
                or len(outcome.provider_ref) > _MAX_REFERENCE):
            return False
        if topup['provider_reference'] and outcome.provider_ref != topup['provider_reference']:
            return False
        if topup['provider_payment_method'] != topup['sakurupiah_channel']:
            return False
        if (topup['currency'] != 'IDR' or type(topup['amount']) is not int
                or type(topup['provider_total_payment']) is not int
                or topup['provider_total_payment'] < topup['amount']
                or type(topup['provider_fee']) is not int or topup['provider_fee'] < 0):
            return False
        if outcome.state is OutcomeState.SUCCESS and outcome.total != topup['provider_total_payment']:
            return False
        if outcome.total is not None and outcome.total != topup['provider_total_payment']:
            return False
        if outcome.state in (OutcomeState.SUCCESS, OutcomeState.EXPIRED):
            return True
        return outcome.state is OutcomeState.PENDING

    @staticmethod
    def _reference_hash(reference):
        return hashlib.sha256(reference.strip().encode()).hexdigest() if reference and reference.strip() else None

    def _audit(self, topup, action, values, now):
        self.db.execute(
            '''INSERT INTO public.audit_logs(action,subject_type,subject_id,old_values,new_values,created_at)
               VALUES (%s,'App\\Models\\StoreBalanceTopUp',%s,%s::json,%s::json,%s)''',
            (action, str(topup['id']), json.dumps({'status': topup['status']}), json.dumps(values), now))

    def _review_evidence(self, topup, reason, now):
        event, created = self._log_event(topup, 'reconciliation', reason, None, now)
        if created:
            self._audit(topup, 'store_balance.top_up.reconciliation_review',
                        {'status': topup['status'] if topup['status'] in ('compensated', 'expired')
                         else 'invalid_provider_result',
                         'reason': reason, 'provider_event_id': event['id']}, now)

    def _log_event(self, topup, event_type, provider_status, provider_reference, now):
        key = _event_key(topup['id'], event_type, provider_reference, provider_status)
        event = self.db.one(
            '''INSERT INTO public.store_balance_top_up_events
               (store_balance_top_up_id,provider,event_type,merchant_ref,provider_reference,
                provider_status,raw_payload,idempotency_key,received_count,received_at,
                last_received_at,processed_at)
               VALUES (%s,'sakurupiah',%s,%s,%s,%s,%s::jsonb,%s,1,%s,%s,%s)
               ON CONFLICT (provider,event_type,idempotency_key) DO NOTHING RETURNING id''',
            (topup['id'], event_type, topup['merchant_ref'], provider_reference, provider_status,
             json.dumps({'provider_status': provider_status}), key, now, now, now))
        if event is not None:
            return event, True
        event = self.db.one(
            '''SELECT id FROM public.store_balance_top_up_events
               WHERE provider='sakurupiah' AND event_type=%s AND idempotency_key=%s
               AND store_balance_top_up_id=%s''', (event_type, key, topup['id']))
        if event is None:
            raise FundingError('event_identity_conflict')
        return event, False

    # -- presentation --------------------------------------------------

    def presentation(self, topup):
        if topup is None:
            return {'state': 'missing'}
        if topup['provider'] in self.rails:
            return self.rails[topup['provider']][0].presentation(topup)
        now = self.clock()
        paid = topup['status'] == 'paid'
        deadline = None
        try:
            candidates = [value for value in (topup['expires_at'], topup['provider_expires_at']) if value]
            deadline = min(_deadline(value) for value in candidates) if candidates else None
        except (TypeError, ValueError):
            deadline = None
        expired = topup['status'] == 'expired' or (deadline is not None and deadline <= now)
        qris_url = None
        if topup['status'] == 'payable' and topup['provider_qris_url'] and self.encrypter is not None:
            try:
                presentation = self.encrypter.decrypt_json(topup['provider_qris_url'])
                if isinstance(presentation, dict):
                    qris_url = _secure_qris_url(presentation.get('qris_image_url'))
            except (CryptoError, ValueError, TypeError):
                qris_url = None
        payable = bool(topup['status'] == 'payable' and qris_url and not expired)
        return {
            'state': ('paid' if paid else 'payable' if payable else 'expired' if expired
                      else 'manual_review' if topup['status'] == 'invalid_provider_result'
                      else 'retryable' if topup['status'] == 'failed'
                      else 'provider_unavailable' if topup['provider_create_state'] == 'provider_unavailable'
                      else 'recovery_pending' if topup['provider_create_state'] in (
                          'creating', 'outcome_unknown', 'recovery_unconfirmed')
                      else 'preparing'),
            'paid': paid, 'payable': payable, 'expired': expired,
            'retryable': bool(topup['status'] == 'failed' and topup['provider_create_state'] in (
                'create_failed', 'provider_unavailable')),
            'manual_review': topup['status'] == 'invalid_provider_result',
            'recovered': paid and topup['provider_create_state'] in ('provider_berhasil', 'reconciled_berhasil'),
            'amount': int(topup['amount']),
            'fee': topup['provider_fee'] if type(topup['provider_fee']) is int else None,
            'total': topup['provider_total_payment'] if type(topup['provider_total_payment']) is int else None,
            'expires': deadline, 'qris_url': qris_url,
            'topup_id': str(topup['id']),
        }
