"""Operational notices; ReceiptProtocol alone owns transport delivery state."""
import re
from datetime import timedelta
from html import escape
from urllib.parse import urlsplit

from django.conf import settings
from django.db import transaction

from ..admin.reporting import _canonical, _net
from ..receipt.protocol import _now
from .bulk_content import icon, icons
from .policy import NotEligible
from .snapshot import frozen_payload, metadata, record_snapshot
SUPPLIER = 'admin.supplier_health'
RECEIVER = 'admin.bsc_receiver_mutation'
DAILY = 'admin.daily_stats'
MODELS = {SUPPLIER: 'SupplierHealthEvent', RECEIVER: 'AuditLog', DAILY: 'ManualPaymentSetting'}
LIMITS = {SUPPLIER: 3, RECEIVER: 3, DAILY: 3}
ACTIONS = {'bsc_receiver.added': 'added', 'bsc_receiver.created': 'added',
           'bsc_receiver.verified': 'verified', 'bsc_receiver.manual_verified': 'manual_verified',
           'bsc_receiver.activated': 'activated', 'bsc_receiver.deactivated': 'deactivated'}


def legacy_icons(text, rows):
    sources = {row['source_emoji']: row for row in rows if row.get('source_emoji')}
    if not sources:
        return text
    pattern = '|'.join(re.escape(value) for value in sorted(sources, key=len, reverse=True))
    return re.sub(pattern, lambda match: icon(sources[match.group()].get('fallback_emoji'),
                  sources[match.group()].get('custom_emoji_id')), text)


def receiver_message(snapshot, rows=()):
    if (not isinstance(snapshot, dict) or snapshot.get('action') not in set(ACTIONS.values())
            or any(not isinstance(snapshot.get(key), str) or not snapshot[key]
                   for key in ('label', 'masked_address', 'actor', 'at'))
            or re.search(r'0x[0-9a-fA-F]{40}', snapshot['masked_address'])):
        raise NotEligible()
    return '\n'.join((legacy_icons('⚠️ Perubahan BSC Receiving Address', rows), '━━━━━━━━━━━━━━',
                      'Aksi: ' + escape(snapshot['action']), 'Label: ' + escape(snapshot['label']),
                      'Address: ' + escape(snapshot['masked_address']), 'Actor: ' + escape(snapshot['actor']),
                      'Waktu: ' + escape(snapshot['at'])))


def daily_message(stats, rows=()):
    money = lambda value: 'Rp' + format(int(value), ',').replace(',', '.')
    receipts = stats['receipts']
    lines = [legacy_icons('📊 Rekap Kemarin', rows), '━━━━━━━━━━━━━━',
             'Order Baru: ' + str(stats['new_orders']), 'Order Dibayar: ' + str(stats['paid_orders']),
             'Produk Terjual: ' + str(stats['sold_products']),
             'Nilai Penjualan: ' + money(stats['sales_value']), '', 'Pembayaran Diterima:']
    if receipts.get('IDR', 0) > 0:
        lines.append('• Rupiah: ' + money(receipts['IDR']))
    if receipts.get('USDT', 0) > 0:
        whole, fraction = divmod(receipts['USDT'], 1_000_000)
        amount = str(whole) + (('.' + f'{fraction:06d}'.rstrip('0')) if fraction else '')
        lines.append('• USDT: ' + amount + ' USDT')
    for currency, amount in receipts.items():
        if currency not in ('IDR', 'USDT') and amount > 0:
            lines.append('• ' + escape(currency) + ': ' + str(amount) + ' ' + escape(currency))
    if len(lines) == 8:
        lines.append('• Belum ada')
    return '\n'.join(lines)


def dashboard_button(base_url, rows, at):
    url = base_url.rstrip('/') + '/admin?v=' + str(int(at.timestamp()))
    parts = urlsplit(url)
    host = (parts.hostname or '').lower()
    if (parts.scheme.lower() != 'https' or not host or host in ('localhost', '127.0.0.1')
            or host.endswith(('.test', '.local'))):
        return None
    key = 'url' if host.endswith(('.ngrok-free.app', '.ngrok-free.dev')) else 'web_app'
    return {'text': legacy_icons('🌐 Mini App Dashboard', rows),
            key: url if key == 'url' else {'url': url}}


class Operations:
    def __init__(self, service):
        self.service, self.db, self.recipients = service, service.db, service.recipients

    def _target(self):
        row = self.db.one('''SELECT id,admin_telegram_chat_id FROM public.manual_payment_settings
            ORDER BY is_active DESC,id LIMIT 1''')
        chat = str((row or {}).get('admin_telegram_chat_id') or '').strip()
        return (row, chat) if re.fullmatch(r'[1-9][0-9]*', chat) and self.recipients.permits(chat) else (None, None)

    def _record(self, kind, source, chat, payload, *, key=None):
        return record_snapshot(self.service, key=key or f'{kind}:{source}:v1', kind=kind,
                               model=MODELS[kind], source=source, chat=chat,
                               template={SUPPLIER: 'supplier-health-v1', RECEIVER: 'bsc-receiver-mutation-v1',
                                         DAILY: 'daily-admin-stats-v1'}[kind], payload=payload)

    def materialize(self, limit=25):
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError('Invalid operations batch')
        if not self.recipients.telegram:
            return 0
        setting, chat = self._target()
        if chat is None:
            return 0
        count = 0
        active = set(self.service.protocol.effect_types)
        if SUPPLIER in active:
            from .supplier_health import SupplierHealth
            base_url = getattr(settings, 'CANDIDATE_LEGACY_APP_URL', '').rstrip('/')
            # No synthesized dead monitor link and no partial episode consumption.
            if base_url:
                count += SupplierHealth(self, base_url + '/admin/suppliers/purchases').materialize(limit, chat)
            with transaction.atomic(using=self.db.using):
                events = self.db.rows('''SELECT h.id,h.message FROM public.supplier_health_events h
                    WHERE NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e
                        WHERE e.effect_type=%s AND e.source_type='App\\Models\\SupplierHealthEvent'
                          AND e.source_id=h.id::text) ORDER BY h.created_at,h.id LIMIT %s''',
                    (SUPPLIER, limit))
                for event in events:
                    self._record(SUPPLIER, event['id'], chat,
                                 {'chat_id': chat, 'text': event['message'], 'parse_mode': 'HTML'})
                    count += 1
        if RECEIVER in active:
            with transaction.atomic(using=self.db.using):
                events = self.db.rows('''SELECT a.id,a.action,a.new_values FROM public.audit_logs a
                    WHERE a.action=ANY(%s) AND a.subject_type='App\\Models\\BscPaymentReceiver'
                      AND a.new_values::jsonb ? 'notification'
                      AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e
                        WHERE e.effect_type=%s AND e.source_type='App\\Models\\AuditLog'
                          AND e.source_id=a.id::text) ORDER BY a.id LIMIT %s''',
                    (list(ACTIONS), RECEIVER, limit))
                rows = icons(self.db) if events else ()
                for event in events:
                    snapshot = metadata({'outcome_metadata': event['new_values']}).get('notification')
                    if not isinstance(snapshot, dict) or snapshot.get('action') != ACTIONS[event['action']]:
                        raise NotEligible()
                    self._record(RECEIVER, event['id'], chat, {'chat_id': chat,
                                 'text': receiver_message(snapshot, rows), 'parse_mode': 'HTML'})
                    count += 1
        if DAILY in active:
            with transaction.atomic(using=self.db.using):
                # Source row serializes date identity and recipient/content snapshot.
                self.db.one('SELECT id FROM public.manual_payment_settings WHERE id=%s FOR UPDATE', (setting['id'],))
                end = _now().replace(hour=0, minute=0, second=0, microsecond=0)
                key = 'admin:daily-stats:' + (end - timedelta(days=1)).date().isoformat()
                existing = self.db.one('SELECT id FROM public.telegram_outbound_effects WHERE effect_key=%s', (key,))
                if not existing:
                    rows = icons(self.db)
                    payload = {'chat_id': chat, 'text': daily_message(self._stats(end - timedelta(days=1), end), rows),
                               'parse_mode': 'HTML'}
                    button = dashboard_button(getattr(settings, 'CANDIDATE_LEGACY_APP_URL', ''), rows, _now())
                    if button:
                        payload['reply_markup'] = {'inline_keyboard': [[button]]}
                    self._record(DAILY, setting['id'], chat, payload, key=key)
                    count += 1
        return count

    def _stats(self, start, end):
        # TelegramStoreStatsReport intentionally does not apply successful-order
        # filtering: refunded payments are excluded by canonical payment status.
        result = self.db.one(f'''WITH canonical AS (SELECT p.* FROM public.payments p
            WHERE {_canonical()} AND p.paid_at >= %s AND p.paid_at < %s)
            SELECT (SELECT count(*) FROM public.orders WHERE created_at >= %s AND created_at < %s) AS new_orders,
              (SELECT count(*) FROM canonical) AS paid_orders,
              (SELECT COALESCE(sum(quantity),0) FROM public.order_items
                WHERE order_id IN (SELECT order_id FROM canonical)) AS sold_products,
              (SELECT COALESCE(sum(total_amount),0) FROM public.orders
                WHERE currency='IDR' AND id IN (SELECT order_id FROM canonical)) AS sales_value''',
            (start, end, start, end))
        receipts = self.db.rows(f'''SELECT UPPER(COALESCE(NULLIF(p.currency,''),'IDR')) AS currency,
            sum({_net()}) AS amount FROM public.payments p WHERE {_canonical()}
            AND p.paid_at >= %s AND p.paid_at < %s GROUP BY UPPER(COALESCE(NULLIF(p.currency,''),'IDR'))
            ORDER BY min(p.paid_at),min(p.id::text)''', (start, end))
        result['receipts'] = {row['currency']: int(row['amount']) for row in receipts}
        return result

    def payload(self, effect):
        kind = effect.get('effect_type')
        if (kind not in MODELS or effect.get('source_type') != 'App\\Models\\' + MODELS[kind]
                or effect.get('target_type') != 'admin' or effect.get('telegram_method') != 'sendMessage'
                or not self.recipients.permits(effect.get('telegram_chat_id'))):
            raise NotEligible()
        payload = frozen_payload(effect)
        if payload is None and kind == SUPPLIER:
            # PHP's SupplierHealthEvent.message is already an authored immutable snapshot.
            row = self.db.one('SELECT message FROM public.supplier_health_events WHERE id=%s', (effect['source_id'],))
            if row and isinstance(row['message'], str) and row['message']:
                return {'chat_id': effect['telegram_chat_id'], 'text': row['message'], 'parse_mode': 'HTML'}
        if payload is None:
            raise NotEligible()
        return payload

    def project(self, effect):
        # These families have no mutable sent marker. Their durable effect is the
        # delivery projection; never mutate supplier economics or append-only audit.
        return 0

    def review_summary(self, effect):
        kind = effect.get('effect_type')
        if kind not in MODELS or effect.get('source_type') != 'App\\Models\\' + MODELS[kind]:
            raise NotEligible()
        return {'family': kind, 'source_id': str(effect['source_id']), 'state': effect['state'],
                'attempts': effect['attempts'], 'requires_review': effect['state'] in ('unknown', 'manual_review')}
