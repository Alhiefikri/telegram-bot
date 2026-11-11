"""PostgreSQL receipt-effect ledger; rendered content never crosses this boundary.

Materialization must run inside the caller's atomic source-authorization
transaction. All other mutations open their own transaction. A returned row is
an ordinary dictionary, not a transport payload.
"""

import json
import re
import secrets
import uuid
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

from django.db import connections, transaction

from ..checkout.db import Database


EFFECT_TYPE = 'receipt.credentials'
ACTION_TYPE = 'receipt.order_actions'
TXT_TYPE = 'receipt.txt_download'
REFUND_TYPE = 'usdt.refund_receipt'
TOPUP_TYPE = 'usdt.top_up_receipt'
BALANCE_ALERT_TYPE = 'admin.balance_operations_alert'
RECEIPT_TYPES = (EFFECT_TYPE, ACTION_TYPE, TXT_TYPE, REFUND_TYPE, TOPUP_TYPE, BALANCE_ALERT_TYPE)
QUEUE = 'telegram-receipts'
PHOTO_TEMPLATE = 'receipt-photo-caption-v1'
TEXT_TEMPLATE = 'receipt-text-fallback-v1'
LEGACY_INVOICE_TEMPLATE = 'receipt-invoice-v2'
_NON_RETRYABLE = frozenset({
    'receipt_not_eligible', 'receipt_render_changed', 'telegram_http_400',
    'telegram_http_403', 'receipt_actions_not_eligible', 'receipt_actions_render_failed',
    'receipt_txt_credentials_missing', 'receipt_txt_render_failed',
})
_FAILURE_CODES = _NON_RETRYABLE | frozenset({
    'mail_delivery_failed', 'telegram_delivery_failed', 'telegram_render_failed',
    'telegram_response_missing_message_id',
})


def _now():
    # Legacy timestamp(0) columns round fractions up, delaying immediately due work.
    return datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None, microsecond=0)

def receipt_group_key(order_id):
    return f'order:{order_id}:{EFFECT_TYPE}:v2'


def legacy_receipt_group_key(order_id):
    return f'order:{order_id}:{EFFECT_TYPE}:v1'


def _legacy_resolved(row):
    return (row['sequence'] == 1 and row['state'] in ('failed', 'unknown')
            and row['telegram_method'] == 'sendPhoto'
            and row['template_version'] == LEGACY_INVOICE_TEMPLATE)


def _safe_units(units, text_fallback):
    """Accept only the information necessary to reconstruct unit identity."""
    if not isinstance(units, (list, tuple)) or not units:
        raise ValueError('Receipt group must contain at least one unit')
    result = []
    for index, unit in enumerate(units):
        if not isinstance(unit, dict) or set(unit) != {'method', 'contains_credentials'}:
            raise ValueError('Receipt unit must contain only safe metadata')
        method = unit['method']
        if method not in ('sendMessage', 'sendPhoto', 'sendDocument') or type(unit['contains_credentials']) is not bool:
            raise ValueError('Invalid receipt unit metadata')
        if index == 0 and method != ('sendMessage' if text_fallback else 'sendPhoto'):
            raise ValueError('Invalid first receipt unit')
        if index > 0 and method not in ('sendMessage', 'sendDocument'):
            raise ValueError('Invalid continuation receipt unit')
        if method == 'sendDocument' and index != 1:
            raise ValueError('Invalid receipt document position')
        result.append((method, unit['contains_credentials']))
    return result


class ReceiptProtocol:
    def __init__(self, using='default', lease_seconds=600, *, family_limits=None, terminal_codes=()):
        self.using = using
        self.db = Database(using)
        self.lease_seconds = max(60, int(lease_seconds))
        # Family adapters select policy, never implement a second claim machine.
        self.family_limits = dict(family_limits if family_limits is not None else
                                  {kind: 3 for kind in RECEIPT_TYPES})
        if (not self.family_limits or any(not isinstance(kind, str) or not kind or
                type(limit) is not int or not 1 <= limit <= 5
                for kind, limit in self.family_limits.items())):
            raise ValueError('Invalid outbound family policy')
        self.effect_types = tuple(self.family_limits)
        self.terminal_codes = _NON_RETRYABLE | frozenset(terminal_codes)

    def _require_postgres(self):
        if connections[self.using].vendor != 'postgresql':
            raise RuntimeError('Receipt ledger requires PostgreSQL')

    def materialize(self, order_id, chat_id, locale, units, *, text_fallback=False, render_failed=False,
                    fallback_from=None, product_count=0, account_count=0,
                    credential_layout_version='fields-v1', caption_length_bucket='0-1024',
                    continuation_length_bucket='0-1024', resend_of=None):
        """Create a deterministic group, or return the ready row of existing history.

        Caller must lock and reauthorize the order in the surrounding atomic
        transaction before calling. Rendered units are represented exclusively
        by {'method': ..., 'contains_credentials': bool} dictionaries.
        """
        self.db.require_transaction()
        order_id, chat_id = str(order_id), str(chat_id)
        if not order_id or not chat_id:
            raise ValueError('Order and Telegram chat are required')
        if credential_layout_version not in ('fields-v1', 'copy-v1'):
            raise ValueError('Invalid credential layout')
        if any(type(count) is not int or count < 0 for count in (product_count, account_count)):
            raise ValueError('Invalid receipt counts')
        buckets = ('0-1024', '1025-2048', '2049-3072', '3073-4096')
        if caption_length_bucket not in buckets or continuation_length_bucket not in buckets:
            raise ValueError('Invalid receipt length bucket')
        if fallback_from is not None and (not text_fallback or render_failed):
            raise ValueError('Fallback requires a text plan')
        if render_failed:
            prepared = [('sendMessage', False)]
        else:
            prepared = _safe_units(units, text_fallback)
        group = receipt_group_key(order_id)
        keys = (group + ':text-fallback', group, legacy_receipt_group_key(order_id))
        # Serialize with PHP materializers and check the completion marker under
        # the same source lock. Eligibility remains the caller's responsibility.
        order = self.db.one('SELECT id, telegram_receipt_sent_at FROM public.orders WHERE id = %s FOR UPDATE', (order_id,))
        if order is None:
            raise ValueError('Receipt order does not exist')
        if resend_of is not None:
            if fallback_from is not None:
                raise ValueError('Explicit resend cannot be an automatic fallback')
            original = self.db.one('''SELECT * FROM public.telegram_outbound_effects
                WHERE id=%s AND effect_type=%s AND source_type='App\\Models\\Order'
                  AND source_id=%s AND telegram_chat_id=%s FOR UPDATE''',
                (str(resend_of), EFFECT_TYPE, order_id, chat_id))
            if original is None or original['state'] not in ('unknown', 'manual_review'):
                return None
            version = 2
            while self.db.one('SELECT id FROM public.telegram_outbound_effects WHERE effect_group_key=%s LIMIT 1',
                              (f'{group}:resend:v{version}',)):
                version += 1
            group = f'{group}:resend:v{version}'
        elif fallback_from is not None:
            predecessor = self.db.one('''SELECT * FROM public.telegram_outbound_effects
                WHERE id = %s AND effect_type = %s AND source_id = %s FOR UPDATE''',
                (fallback_from, EFFECT_TYPE, order_id))
            if (predecessor is None or predecessor['state'] != 'failed'
                    or predecessor['sequence'] != 1 or predecessor['telegram_method'] != 'sendPhoto'
                    or predecessor['template_version'] != PHOTO_TEMPLATE):
                return None
            group = predecessor['effect_group_key'] + ':text-fallback'
            existing = self.db.one('SELECT id FROM public.telegram_outbound_effects WHERE effect_group_key = %s LIMIT 1', (group,))
            if existing:
                return self.next_receipt_effect(group)
        else:
            existing = self.db.rows(
                'SELECT DISTINCT effect_group_key FROM public.telegram_outbound_effects WHERE effect_group_key IN (%s, %s, %s)', keys)
            present = {row['effect_group_key'] for row in existing}
            for key in keys:
                if key in present:
                    ready = self.next_receipt_effect(key)
                    if ready is not None:
                        return ready
            if present or order['telegram_receipt_sent_at'] is not None:
                return None
        template = TEXT_TEMPLATE if text_fallback or render_failed else PHOTO_TEMPLATE
        now = _now()
        first = None
        for index, (method, contains_credentials) in enumerate(prepared):
            sequence = index + 1
            metadata = {
                'delivery_context': 'receipt',
                'unit_role': 'receipt_render_failure' if render_failed else ('invoice_caption' if index == 0 and not text_fallback else 'receipt_chunk'),
                'receipt_chunk_index': index,
                'receipt_chunk_count': len(prepared),
                'has_photo': not text_fallback and not render_failed,
                'has_header': not render_failed,
                'has_footer': not render_failed,
                'render_mode': ('failed' if render_failed else
                                ('text_document' if text_fallback else 'photo_document') if any(item[0] == 'sendDocument' for item in prepared) else
                                'text_fallback' if text_fallback else 'photo_caption'),
            }
            if resend_of is not None:
                metadata.update(explicit_resend=True, duplicate_risk_acknowledged=True,
                                supersedes_effect_id=str(resend_of))
            if not render_failed:
                metadata.update({'contains_credentials': contains_credentials,
                                 'receipt_layout_version': 'v2', 'receipt_actions_version': 'v1',
                                 'credential_layout_version': credential_layout_version,
                                 'product_count': product_count, 'account_count': account_count,
                                 'caption_length_bucket': caption_length_bucket,
                                 'continuation_length_bucket': continuation_length_bucket})
                if method in ('sendPhoto', 'sendDocument'):
                    metadata.update({'attachment_type': 'photo' if method == 'sendPhoto' else 'document', 'attachment_present': True})
            row = self.db.one('''
                INSERT INTO public.telegram_outbound_effects
                    (id, effect_key, effect_group_key, supersedes_effect_id, sequence, effect_type, source_type,
                     source_id, target_type, telegram_chat_id, queue, telegram_method, locale,
                     template_version, state, attempts, next_attempt_at, failed_at,
                     last_error_code, last_error, outcome_metadata, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, 'customer', %s, %s, %s, %s,
                        %s, %s, 0, %s, %s, %s, %s, %s::json, %s, %s)
                RETURNING *
            ''', (str(uuid.uuid4()), f'{group}:unit:{sequence}', group,
                  str(resend_of or fallback_from) if index == 0 and (resend_of or fallback_from) else None,
                  sequence, EFFECT_TYPE,
                  'App\\Models\\Order', order_id, chat_id, QUEUE, method, locale, template,
                  'failed' if render_failed else 'pending', None if render_failed else now,
                  now if render_failed else None, 'receipt_render_changed' if render_failed else None,
                  'Telegram receipt rendering requires manual review.' if render_failed else None,
                  json.dumps(metadata), now, now))
            if first is None:
                first = row
        return first

    def _rows(self, group_key):
        return self.db.rows('''SELECT * FROM public.telegram_outbound_effects
            WHERE effect_type = %s AND effect_group_key = %s ORDER BY sequence''', (EFFECT_TYPE, group_key))

    def is_terminal_failure(self, row):
        return (row['effect_type'] in self.family_limits and row['state'] == 'failed'
                and (row.get('last_error_code') in self.terminal_codes
                     or row['attempts'] >= self.family_limits[row['effect_type']]))

    def _ready(self, row, at):
        if (row['effect_type'] not in self.family_limits or row['state'] not in ('pending', 'failed')
                or row['attempts'] >= self.family_limits[row['effect_type']]):
            return False
        if row['effect_type'] in (REFUND_TYPE, TOPUP_TYPE) and self.db.one(
                "SELECT id FROM public.customer_usdt_funding_pauses WHERE scope='delivery' LIMIT 1"):
            return False
        if (row['effect_type'] == EFFECT_TYPE and row['template_version'] == PHOTO_TEMPLATE
                and row['sequence'] == 1 and row['state'] == 'failed'):
            return False
        if row['last_error_code'] in self.terminal_codes:
            return False
        if row['next_attempt_at'] is not None and row['next_attempt_at'] > at:
            return False
        if row['effect_type'] == EFFECT_TYPE and row['sequence'] > 1 and row['effect_group_key']:
            predecessors = self.db.rows('''SELECT sequence, state, telegram_method, template_version
                FROM public.telegram_outbound_effects WHERE effect_group_key = %s AND sequence < %s''',
                (row['effect_group_key'], row['sequence']))
            if any(item['state'] != 'sent' and not _legacy_resolved(item) for item in predecessors):
                return False
        return True

    def next_receipt_effect(self, group_key, after_sequence=0):
        self._require_postgres()
        if not group_key:
            return None
        now = _now()
        for row in self._rows(group_key):
            if row['sequence'] > max(0, after_sequence) and not _legacy_resolved(row) and self._ready(row, now):
                return row
        return None

    def is_receipt_group_complete(self, group_key):
        self._require_postgres()
        if not group_key:
            return False
        rows = self._rows(group_key)
        return bool(rows) and all(row['state'] == 'sent' or _legacy_resolved(row) for row in rows)

    def claim(self, effect_id):
        self._require_postgres()
        with transaction.atomic(using=self.using):
            row = self.db.one('SELECT * FROM public.telegram_outbound_effects WHERE id = %s AND effect_type = ANY(%s) FOR UPDATE', (effect_id, list(self.effect_types)))
            now = _now()
            if row is None or not self._ready(row, now):
                return None
            return self.db.one('''UPDATE public.telegram_outbound_effects SET state = 'sending',
                attempts = attempts + 1, next_attempt_at = NULL, lease_until = %s,
                claim_token = %s, last_error_code = NULL, last_error = NULL,
                outcome_metadata = CASE WHEN outcome_metadata::jsonb ? 'first_pickup_at'
                    THEN outcome_metadata
                    ELSE (COALESCE(outcome_metadata::jsonb, '{}'::jsonb)
                          || jsonb_build_object('first_pickup_at', %s::text))::json END,
                updated_at = %s
                WHERE id = %s RETURNING *''',
                (now + timedelta(seconds=self.lease_seconds), secrets.token_hex(32), now.isoformat(), now, effect_id))

    def _locked_claim(self, effect_id, token):
        if not isinstance(token, str) or not token:
            return None
        row = self.db.one('SELECT * FROM public.telegram_outbound_effects WHERE id = %s AND effect_type = ANY(%s) FOR UPDATE', (effect_id, list(self.effect_types)))
        if (row is None or row['state'] != 'sending' or not row['claim_token']
                or row['lease_until'] is None or row['lease_until'] <= _now()
                or not secrets.compare_digest(row['claim_token'], token)):
            return None
        return row

    def finalize_sent(self, effect_id, token, message_id):
        self._require_postgres()
        if isinstance(message_id, bool) or not re.fullmatch(r'0*[1-9][0-9]*', str(message_id)):
            return None
        with transaction.atomic(using=self.using):
            if self._locked_claim(effect_id, token) is None:
                return None
            now = _now()
            return self.db.one('''UPDATE public.telegram_outbound_effects SET state = 'sent',
                lease_until = NULL, claim_token = NULL, telegram_message_id = %s, sent_at = %s,
                unknown_at = NULL, failed_at = NULL, manual_review_at = NULL, next_attempt_at = NULL,
                last_error_code = NULL, last_error = NULL,
                outcome_metadata = (COALESCE(outcome_metadata::jsonb, '{}'::jsonb) || '{"telegram_response":"ok"}'::jsonb)::json,
                updated_at = %s WHERE id = %s RETURNING *''', (str(message_id), now, now, effect_id))

    def finalize_failed(self, effect_id, token, code, retry_after_seconds=None):
        """Record a *definite* failure; ambiguous transport must use finalize_unknown."""
        self._require_postgres()
        http = re.fullmatch(r'telegram_http_([0-9]{3})', code) if isinstance(code, str) else None
        if code not in _FAILURE_CODES | self.terminal_codes and not (http and 400 <= int(http.group(1)) < 500 and int(http.group(1)) != 408):
            raise ValueError('Failure code is not a definite safe failure')
        if retry_after_seconds is not None:
            if type(retry_after_seconds) is not int:
                raise ValueError('Invalid retry delay')
            retry_after_seconds = min(300, max(1, retry_after_seconds))
        with transaction.atomic(using=self.using):
            row = self._locked_claim(effect_id, token)
            if row is None:
                return None
            now = _now()
            maximum = self.family_limits[row['effect_type']]
            terminal = code in self.terminal_codes or row['attempts'] >= maximum
            delay = retry_after_seconds if retry_after_seconds is not None else (5 if row['attempts'] <= 1 else 30 if row['attempts'] == 2 else 120)
            exhausted = {EFFECT_TYPE: 'receipt_attempts_exhausted', ACTION_TYPE: 'receipt_actions_attempts_exhausted',
                         TXT_TYPE: 'receipt_txt_download_attempts_exhausted',
                         REFUND_TYPE: 'usdt_refund_receipt_attempts_exhausted',
                         TOPUP_TYPE: 'usdt_top_up_receipt_attempts_exhausted',
                          BALANCE_ALERT_TYPE: 'balance_operations_alert_attempts_exhausted'}.get(
                              row['effect_type'], 'notification_attempts_exhausted')
            stored_code = code if code in self.terminal_codes or row['attempts'] < maximum else exhausted
            return self.db.one('''UPDATE public.telegram_outbound_effects SET state = 'failed',
                lease_until = NULL, claim_token = NULL, sent_at = NULL, failed_at = %s,
                unknown_at = NULL, manual_review_at = NULL, next_attempt_at = %s,
                last_error_code = %s, last_error = 'Telegram delivery failed before completion.',
                updated_at = %s WHERE id = %s RETURNING *''',
                (now, None if terminal else now + timedelta(seconds=delay), stored_code, now, effect_id))

    def finalize_unknown(self, effect_id, token):
        self._require_postgres()
        with transaction.atomic(using=self.using):
            if self._locked_claim(effect_id, token) is None:
                return None
            now = _now()
            return self.db.one('''UPDATE public.telegram_outbound_effects SET state = 'unknown',
                lease_until = NULL, claim_token = NULL, next_attempt_at = NULL, sent_at = NULL,
                failed_at = NULL, unknown_at = %s, manual_review_at = NULL,
                last_error_code = 'telegram_transport_ambiguous',
                last_error = 'Telegram delivery outcome is unknown and requires review.',
                updated_at = %s WHERE id = %s RETURNING *''', (now, now, effect_id))

    def request_manual_retry(self, effect_id):
        """An authorized family may reopen a *definitely failed* delivery."""
        self._require_postgres()
        with transaction.atomic(using=self.using):
            return self.db.one('''UPDATE public.telegram_outbound_effects SET state='pending',
                attempts=0,next_attempt_at=%s,lease_until=NULL,claim_token=NULL,
                failed_at=NULL,last_error_code=NULL,last_error=NULL,updated_at=%s
                WHERE id=%s AND effect_type=ANY(%s) AND state='failed' RETURNING *''',
                (_now(), _now(), str(effect_id), list(self.effect_types)))

    def resolve_sent(self, effect_id, message_id, actor_id):
        """Operator-confirmed ACK; it cannot race a worker's live claim."""
        self._require_postgres()
        if isinstance(message_id, bool) or not re.fullmatch(r'0*[1-9][0-9]{0,18}', str(message_id)):
            raise ValueError('Invalid Telegram message identifier')
        with transaction.atomic(using=self.using):
            row = self.db.one('''SELECT * FROM public.telegram_outbound_effects
                WHERE id=%s AND effect_type=ANY(%s) FOR UPDATE''',
                (str(effect_id), list(self.effect_types)))
            if row is None or row['state'] not in ('unknown', 'manual_review'):
                return None
            now = _now()
            metadata = row['outcome_metadata'] or {}
            if isinstance(metadata, str):
                metadata = json.loads(metadata)
            metadata = dict(metadata)
            metadata.update(resolution='operator_marked_sent', resolution_actor_id=int(actor_id),
                            resolution_at=now.isoformat())
            return self.db.one('''UPDATE public.telegram_outbound_effects SET state='sent',
                telegram_message_id=%s,sent_at=%s,unknown_at=NULL,failed_at=NULL,
                manual_review_at=NULL,next_attempt_at=NULL,lease_until=NULL,claim_token=NULL,
                last_error_code=NULL,last_error=NULL,outcome_metadata=%s::json,updated_at=%s
                WHERE id=%s RETURNING *''',
                (str(message_id), now, json.dumps(metadata), now, str(effect_id)))

    def resend_review(self, effect_id, *, duplicate_risk_acknowledged):
        """Create a new auditable non-credential attempt, retaining the unknown."""
        self._require_postgres()
        if duplicate_risk_acknowledged is not True:
            raise ValueError('Duplicate risk must be acknowledged')
        with transaction.atomic(using=self.using):
            row = self.db.one('''SELECT * FROM public.telegram_outbound_effects
                WHERE id=%s AND effect_type=ANY(%s) FOR UPDATE''',
                (str(effect_id), list(self.effect_types)))
            if row is None or row['state'] not in ('unknown', 'manual_review') or row['effect_type'] == EFFECT_TYPE:
                return None
            group = row['effect_group_key'] or row['effect_key']
            self.db.one('SELECT pg_advisory_xact_lock(hashtextextended(%s,0))', ('effect-resend:' + group,))
            latest = self.db.one('''SELECT max(sequence) AS sequence FROM public.telegram_outbound_effects
                WHERE effect_group_key=%s''', (group,))
            sequence = max(int(row['sequence'] or 1), int(latest['sequence'] or 0)) + 1
            metadata = row['outcome_metadata'] or {}
            if isinstance(metadata, str):
                metadata = json.loads(metadata)
            # Family presentation snapshots survive an explicit resend. Pickup
            # and prior-resolution observations do not describe the new attempt.
            metadata = {key: value for key, value in metadata.items()
                        if key not in ('first_pickup_at', 'telegram_response', 'resolution',
                                       'resolution_actor_id', 'resolution_at', 'reason',
                                       'mail_attempt_snapshot')}
            metadata.update(duplicate_risk_acknowledged=True, supersedes_effect_id=str(row['id']),
                            explicit_resend=True)
            now = _now()
            return self.db.one('''INSERT INTO public.telegram_outbound_effects
                (id,effect_key,effect_group_key,supersedes_effect_id,sequence,effect_type,
                 source_type,source_id,target_type,telegram_chat_id,queue,telegram_method,
                 locale,template_version,state,attempts,next_attempt_at,outcome_metadata,created_at,updated_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'pending',0,%s,%s::json,%s,%s)
                RETURNING *''',
                (str(uuid.uuid4()), f'{group}:resend:v{sequence}', group, str(row['id']), sequence,
                 row['effect_type'], row['source_type'], row['source_id'], row['target_type'],
                 row['telegram_chat_id'], row['queue'], row['telegram_method'], row['locale'],
                 row['template_version'], now, json.dumps(metadata), now, now))

    def recover_expired_leases(self, limit=100, *, chats=None, queue=None):
        self._require_postgres()
        limit = max(1, min(1000, int(limit)))
        with transaction.atomic(using=self.using):
            now = _now()
            rows = self.db.rows('''WITH expired AS (
                SELECT e.id FROM public.telegram_outbound_effects AS e
                WHERE e.effect_type = ANY(%s) AND e.state = 'sending'
                  AND e.lease_until IS NOT NULL AND e.lease_until <= %s
                  AND (%s::text[] IS NULL OR e.telegram_chat_id=ANY(%s))
                  AND (%s::text IS NULL OR e.queue=%s)
                  ORDER BY e.id LIMIT %s FOR UPDATE SKIP LOCKED
                ) UPDATE public.telegram_outbound_effects AS effects SET state = 'unknown', lease_until = NULL,
                  claim_token = NULL, next_attempt_at = NULL, unknown_at = %s, manual_review_at = NULL,
                  last_error_code = 'lease_expired',
                  last_error = 'Worker lease expired before Telegram delivery was finalized.',
                  outcome_metadata = (COALESCE(effects.outcome_metadata::jsonb, '{}'::jsonb) || '{"reason":"worker_lease_expired"}'::jsonb)::json,
                  updated_at = %s FROM expired WHERE effects.id = expired.id RETURNING effects.id''',
                (list(self.effect_types), now, chats, chats, queue, queue, limit, now, now))
            return len(rows)

    def completed_receipts_needing_projection(self, limit):
        """Limit only after applying receipt completion policy."""
        self._require_postgres()
        return self.db.rows('''SELECT e.* FROM public.telegram_outbound_effects e
            JOIN public.orders o ON o.id::text=e.source_id
            WHERE e.effect_type=%s AND e.source_type=%s AND e.state='sent'
              AND o.telegram_receipt_sent_at IS NULL AND e.effect_group_key IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects p
                  WHERE p.effect_type=e.effect_type AND p.effect_group_key=e.effect_group_key
                    AND p.state <> 'sent' AND NOT COALESCE((p.sequence=1 AND p.state IN ('failed','unknown')
                      AND p.telegram_method='sendPhoto' AND p.template_version=%s),false))
              AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects later
                  WHERE later.effect_type=e.effect_type AND later.effect_group_key=e.effect_group_key
                    AND later.sequence>e.sequence AND later.state='sent')
            ORDER BY e.id LIMIT %s''', (EFFECT_TYPE, 'App\\Models\\Order', LEGACY_INVOICE_TEMPLATE, limit))

    def ready_effects(self, limit, *, chats=None, queue=None):
        """Return only due units whose ordered predecessors are resolved."""
        self._require_postgres()
        return self.db.rows('''SELECT e.id FROM public.telegram_outbound_effects e
            WHERE e.effect_type=ANY(%s) AND e.state IN ('pending','failed')
              AND e.attempts < (%s::jsonb ->> e.effect_type)::int
              AND (%s::text[] IS NULL OR e.telegram_chat_id=ANY(%s))
              AND (%s::text IS NULL OR e.queue=%s)
              AND (e.effect_type NOT IN ('usdt.refund_receipt','usdt.top_up_receipt') OR NOT EXISTS (
                  SELECT 1 FROM public.customer_usdt_funding_pauses WHERE scope='delivery'))
              AND (e.next_attempt_at IS NULL OR e.next_attempt_at<=%s)
              AND (e.last_error_code IS NULL OR NOT (e.last_error_code=ANY(%s)))
              AND NOT COALESCE((e.effect_type=%s AND e.template_version=%s AND e.sequence=1 AND e.state='failed'),false)
              AND (e.effect_type<>%s OR NOT EXISTS (
                  SELECT 1 FROM public.telegram_outbound_effects p
                  WHERE p.effect_group_key=e.effect_group_key AND p.sequence<e.sequence
                    AND p.state <> 'sent' AND NOT COALESCE((p.sequence=1 AND p.state IN ('failed','unknown')
                      AND p.telegram_method='sendPhoto' AND p.template_version=%s),false)))
            ORDER BY e.id LIMIT %s''', (list(self.effect_types), json.dumps(self.family_limits), chats, chats,
                queue, queue, _now(), list(self.terminal_codes), EFFECT_TYPE, PHOTO_TEMPLATE, EFFECT_TYPE,
                LEGACY_INVOICE_TEMPLATE, limit))
