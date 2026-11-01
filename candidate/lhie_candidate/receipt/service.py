"""Receipt-family orchestration over the existing outbound-effect ledger."""
import json
import hashlib
import re
from datetime import timedelta
from datetime import datetime
from uuid import uuid4
from zoneinfo import ZoneInfo

from django.db import connections, transaction

from .jobs import decode_job, wake_after_commit
from .protocol import (ACTION_TYPE, EFFECT_TYPE, PHOTO_TEMPLATE, QUEUE, TEXT_TEMPLATE,
                       TXT_TYPE, ReceiptProtocol, _now, receipt_group_key)
from .historical import normalize_unit, render_legacy, render_product_detail
from .render import render_action_panel, render_receipt, render_txt
from .source import ReceiptUnavailable
from .transport import ReceiptPreSendError


def _metadata(row):
    value = row.get('outcome_metadata') or {}
    return json.loads(value) if isinstance(value, str) else value


def _unit_specs(plan, fallback):
    units = plan.text_units if fallback else plan.photo_units
    return [{'method': ('sendDocument' if plan.document and index == 1 else
                        'sendMessage' if fallback or index else 'sendPhoto'),
             'contains_credentials': unit.contains_credentials}
            for index, unit in enumerate(units)]


def _bucket(length):
    if length <= 1024:
        return '0-1024'
    if length <= 2048:
        return '1025-2048'
    if length <= 3072:
        return '2049-3072'
    return '3073-4096'


def _image_snapshot(source):
    payment = source['payment']
    paid = payment['paid_at']
    if isinstance(paid, datetime) and paid.tzinfo is None:
        paid = paid.replace(tzinfo=ZoneInfo('Asia/Jakarta'))
    return {'invoice_number': source['invoice_number'], 'buyer_name': source['buyer_name'],
            'chat_id': source['chat_id'], 'paid_at': paid, 'payment_method': source['payment_method'],
            'currency': payment['currency'], 'items': source['items'],
            'payment_subtotal': payment['amount'], 'provider_fee': payment['provider_fee'] or 0,
            'total_paid': payment['provider_total_payment'] if payment['provider_total_payment'] is not None else payment['amount']}


class ReceiptService:
    def __init__(self, source, transport, invoice_renderer, *, using='default'):
        self.source = source
        self.transport = transport
        self.invoice_renderer = invoice_renderer
        self.using = using
        self.protocol = ReceiptProtocol(using)
        self.db = self.protocol.db

    def _outside(self):
        if connections[self.using].in_atomic_block or not connections[self.using].get_autocommit():
            raise RuntimeError('Receipt delivery requires an outermost transaction boundary')

    def _owner(self, order_id):
        return self.db.one('''SELECT c.telegram_id FROM public.orders o JOIN public.customers c ON c.id=o.customer_id
            WHERE o.id=%s''', (order_id,))

    def materialize(self, order_id, *, fallback_from=None):
        self._outside()
        owner = self._owner(order_id)
        if not owner or owner['telegram_id'] is None:
            return None
        try:
            source = self.source.load(order_id, owner['telegram_id'], receipt=True)
            plan = render_receipt(source)
        except (ReceiptUnavailable, ValueError):
            if fallback_from is not None:
                return None
            with transaction.atomic(using=self.using):
                try:
                    order, _ = self.source.authorize(order_id, owner['telegram_id'], lock=True, receipt=True)
                except ReceiptUnavailable:
                    return None
                return self.protocol.materialize(order_id, owner['telegram_id'], order['locale'], (),
                                                 render_failed=True)
        fallback = fallback_from is not None
        if not fallback:
            try:
                self.invoice_renderer(_image_snapshot(source))
            except Exception:
                fallback = True
        units = _unit_specs(plan, fallback)
        selected = plan.text_units if fallback else plan.photo_units
        with transaction.atomic(using=self.using):
            try:
                current, _ = self.source.authorize(order_id, owner['telegram_id'], lock=True, receipt=True)
                if (current['customer_id'] != source['customer_id']
                        or self.source.allocation_signature(order_id) != source['_allocation_signature']):
                    raise ReceiptUnavailable()
            except (ReceiptUnavailable, ValueError):
                return None
            effect = self.protocol.materialize(order_id, source['chat_id'], source['locale'], units,
                text_fallback=fallback, fallback_from=fallback_from, product_count=plan.product_count,
                account_count=plan.account_count, credential_layout_version='copy-v1',
                caption_length_bucket=_bucket(len(plan.photo_units[0].text)),
                continuation_length_bucket=_bucket(max((len(u.text) for u in selected[1:]), default=0)))
            if effect:
                wake_after_commit('effect', str(effect['id']), using=self.using)
            return effect

    def _historical_payload(self, effect, source, metadata):
        invoice = effect['template_version'] == 'receipt-invoice-v2'
        units = (render_product_detail(source, support_url=self.source.support_url()) if invoice
                 else render_legacy(source, support_url=self.source.support_url()))
        expected = len(units) + int(invoice)
        count = self.db.one('''SELECT count(*) AS n FROM public.telegram_outbound_effects
            WHERE effect_group_key=%s AND effect_type=%s''', (effect['effect_group_key'], EFFECT_TYPE))['n']
        if count != expected or ('receipt_chunk_count' in metadata and metadata['receipt_chunk_count'] != expected):
            raise ValueError('Historical receipt plan changed')
        index = effect['sequence'] - 1
        if invoice and index == 0:
            if effect['telegram_method'] != 'sendPhoto':
                raise ValueError('Historical invoice method changed')
            return {'chat_id': effect['telegram_chat_id'], 'photo': self.invoice_renderer(_image_snapshot(source)),
                    'protect_content': True}
        index -= int(invoice)
        if index < 0 or index >= len(units) or effect['telegram_method'] != 'sendMessage':
            raise ValueError('Historical receipt sequence changed')
        unit = normalize_unit(units[index])
        return {'chat_id': effect['telegram_chat_id'], 'text': unit.text, 'entities': list(unit.entities),
                'reply_markup': unit.reply_markup, 'protect_content': True}

    def _continue_legacy_invoice(self, effect):
        if (effect and effect['effect_type'] == EFFECT_TYPE and effect['template_version'] == 'receipt-invoice-v2'
                and effect['sequence'] == 1):
            self._after_sent(effect)


    def deliver(self, effect_id):
        self._outside()
        effect = self.protocol.claim(effect_id)
        if effect is None:
            return 'not_ready'
        token = effect['claim_token']
        if effect['source_type'] != 'App\\Models\\Order' or effect['target_type'] != 'customer':
            self.protocol.finalize_failed(effect_id, token, 'receipt_not_eligible')
            return 'ineligible'
        try:
            if effect['effect_type'] == ACTION_TYPE:
                source, _ = self.source.authorize(effect['source_id'], effect['telegram_chat_id'])
            else:
                source = self.source.load(effect['source_id'], effect['telegram_chat_id'], locale=effect['locale'],
                                          receipt=effect['effect_type'] == EFFECT_TYPE)
        except ReceiptUnavailable:
            self.protocol.finalize_failed(effect_id, token, 'receipt_not_eligible')
            return 'ineligible'
        except Exception:
            self.protocol.finalize_failed(effect_id, token, 'telegram_render_failed')
            return 'render_failed'
        try:
            metadata = _metadata(effect)
            if effect['effect_type'] == ACTION_TYPE:
                if not self.protocol.is_receipt_group_complete(metadata.get('receipt_group_key')) or source['telegram_receipt_sent_at'] is None:
                    raise ValueError('Receipt incomplete')
                unit = render_action_panel(effect['source_id'], effect['locale'], metadata['txt_available'],
                    support_url=self.source.support_url(), privacy=True,
                    icons=self.source.icons(), custom_emoji=self.source.custom_emoji)
                payload = {'chat_id': effect['telegram_chat_id'], 'text': unit.text, 'entities': list(unit.entities),
                           'reply_markup': unit.reply_markup}
            elif effect['effect_type'] == TXT_TYPE:
                document = render_txt(source)
                if document is None:
                    raise ValueError('Receipt unavailable')
                payload = {'chat_id': effect['telegram_chat_id'], 'document': document.contents, 'filename': document.filename}
            else:
                if effect['source_type'] != 'App\\Models\\Order' or effect['target_type'] != 'customer':
                    raise ValueError('Invalid effect')
                if effect['template_version'] in ('receipt-invoice-v2', 'receipt-credentials-v1'):
                    payload = self._historical_payload(effect, source, metadata)
                elif effect['template_version'] not in (PHOTO_TEMPLATE, TEXT_TEMPLATE):
                    raise ValueError('Unsupported historical receipt layout')
                else:
                    copyable = metadata.get('credential_layout_version') == 'copy-v1'
                    compact = metadata.get('receipt_layout_version') == 'v2'
                    document = metadata.get('render_mode') in ('photo_document', 'text_document')
                    plan = render_receipt(source, document_delivery=document, compact=compact,
                                          copyable_credentials=copyable, support_url=self.source.support_url())
                    fallback = effect['template_version'] == TEXT_TEMPLATE
                    units = plan.text_units if fallback else plan.photo_units
                    specs = _unit_specs(plan, fallback)
                    index = effect['sequence'] - 1
                    if (len(units) != metadata.get('receipt_chunk_count') or index < 0 or index >= len(units)
                            or specs[index]['method'] != effect['telegram_method']
                            or ('contains_credentials' in metadata and specs[index]['contains_credentials'] != metadata['contains_credentials'])
                            or ('account_count' in metadata and plan.account_count != metadata['account_count'])
                            or ('product_count' in metadata and plan.product_count != metadata['product_count'])):
                        raise ValueError('Receipt plan changed')
                    unit = units[index]
                    payload = {'chat_id': effect['telegram_chat_id'], 'reply_markup': unit.reply_markup,
                               'protect_content': False if effect['telegram_method'] == 'sendDocument' else
                                                  (not copyable or not unit.contains_credentials)}
                    if effect['telegram_method'] == 'sendPhoto':
                        payload.update(photo=self.invoice_renderer(_image_snapshot(source)), caption=unit.text,
                                       caption_entities=list(unit.entities))
                    elif effect['telegram_method'] == 'sendDocument':
                        payload.update(document=plan.document.contents, filename=plan.document.filename,
                                       caption=unit.text, caption_entities=list(unit.entities))
                    else:
                        payload.update(text=unit.text, entities=list(unit.entities))
        except Exception:
            failed = self.protocol.finalize_failed(effect_id, token, 'receipt_render_changed')
            self._continue_legacy_invoice(failed)
            if failed and effect['effect_type'] == EFFECT_TYPE and effect['template_version'] == PHOTO_TEMPLATE and effect['sequence'] == 1:
                self.materialize(effect['source_id'], fallback_from=effect_id)
            return 'render_failed'
        try:
            result = self.transport.execute(effect['telegram_method'], payload)
        except ReceiptPreSendError:
            failed = self.protocol.finalize_failed(effect_id, token, 'telegram_render_failed')
            self._continue_legacy_invoice(failed)
            if failed and effect['effect_type'] == EFFECT_TYPE and effect['template_version'] == PHOTO_TEMPLATE and effect['sequence'] == 1:
                self.materialize(effect['source_id'], fallback_from=effect_id)
            return 'render_failed'
        except Exception:
            unknown = self.protocol.finalize_unknown(effect_id, token)
            self._continue_legacy_invoice(unknown)
            return 'unknown'
        if result.outcome == 'success' and type(result.message_id) is int and result.message_id > 0:
            sent = self.protocol.finalize_sent(effect_id, token, result.message_id)
            if not sent:
                return 'fenced'
            if effect['effect_type'] == EFFECT_TYPE:
                self._after_sent(sent)
            return 'sent'
        if result.outcome == 'definite':
            failed = self.protocol.finalize_failed(effect_id, token, result.error_code or 'telegram_delivery_failed',
                                                   retry_after_seconds=result.retry_after_seconds)
            self._continue_legacy_invoice(failed)
            if failed and effect['effect_type'] == EFFECT_TYPE and effect['template_version'] == PHOTO_TEMPLATE and effect['sequence'] == 1:
                self.materialize(effect['source_id'], fallback_from=effect_id)
            return 'failed'
        unknown = self.protocol.finalize_unknown(effect_id, token)
        self._continue_legacy_invoice(unknown)
        return 'unknown'

    def _after_sent(self, effect):
        group = effect['effect_group_key']
        if self.protocol.is_receipt_group_complete(group):
            self._complete(effect)
        else:
            next_effect = self.protocol.next_receipt_effect(group, effect['sequence'])
            if next_effect:
                wake_after_commit('effect', str(next_effect['id']), using=self.using)

    def _complete(self, effect):
        order_id, group = str(effect['source_id']), effect['effect_group_key']
        if group not in (receipt_group_key(order_id), receipt_group_key(order_id) + ':text-fallback',
                         f'order:{order_id}:receipt.credentials:v1'):
            return False
        with transaction.atomic(using=self.using):
            self.db.one('SELECT id FROM public.orders WHERE id=%s FOR UPDATE', (order_id,))
            if not self.protocol.is_receipt_group_complete(group):
                return False
            self.db.execute('''UPDATE public.orders SET telegram_receipt_sent_at=%s,updated_at=%s
                WHERE id=%s AND telegram_receipt_sent_at IS NULL''', (effect['sent_at'] or _now(), _now(), order_id))
            first = self.db.one('''SELECT * FROM public.telegram_outbound_effects WHERE effect_type=%s
                AND effect_group_key=%s ORDER BY sequence LIMIT 1''', (EFFECT_TYPE, group))
            metadata = _metadata(first)
            if first['template_version'] not in (PHOTO_TEMPLATE, TEXT_TEMPLATE) or metadata.get('receipt_actions_version') != 'v1':
                return True
            version = 'text-fallback' if group.endswith(':text-fallback') else 'v2'
            key = f'order:{order_id}:telegram_receipt_actions:v1:{version}'
            panel_metadata = {'receipt_layout_version': 'v2', 'delivery_context': 'receipt_actions',
                'txt_available': metadata.get('account_count', 0) > 0, 'product_count': metadata.get('product_count', 0),
                'receipt_group_key': group, 'receipt_actions_version': 'v1'}
            now = _now()
            panel = self.db.one('''INSERT INTO public.telegram_outbound_effects
                (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,telegram_chat_id,
                 queue,telegram_method,locale,template_version,state,attempts,next_attempt_at,outcome_metadata,created_at,updated_at)
                VALUES (%s,%s,%s,1,%s,%s,%s,'customer',%s,%s,'sendMessage',%s,'receipt-actions-v1',
                        'pending',0,%s,%s::json,%s,%s) ON CONFLICT (effect_key) DO NOTHING RETURNING id''',
                (str(uuid4()), key, key, ACTION_TYPE, 'App\\Models\\Order', order_id, effect['telegram_chat_id'],
                 QUEUE, effect['locale'], now, json.dumps(panel_metadata), now, now))
            if panel:
                wake_after_commit('effect', str(panel['id']), using=self.using)
        return True

    def request_download(self, order_id, customer_id, chat_id, request_id):
        """Record an owner-bound explicit TXT request without serializing credentials."""
        self._outside()
        if type(request_id) is not int or request_id < 1:
            raise ValueError('Invalid receipt request')
        nonce = hashlib.sha256(str(request_id).encode('ascii')).hexdigest()[:32]
        key = f'order:{order_id}:telegram_txt_download:{nonce}:v1'
        with transaction.atomic(using=self.using):
            try:
                source, _ = self.source.authorize(order_id, chat_id, lock=True)
            except ReceiptUnavailable:
                return None
            if source['customer_id'] != customer_id:
                return None
            if not self.db.one('''SELECT s.id FROM public.product_stock_items s
                JOIN public.order_items i ON i.id=s.order_item_id
                WHERE i.order_id=%s LIMIT 1''', (order_id,)):
                return None
            existing = self.db.one('SELECT * FROM public.telegram_outbound_effects WHERE effect_key=%s', (key,))
            if existing:
                return existing if (existing['effect_type'] == TXT_TYPE and existing['source_type'] == 'App\\Models\\Order'
                                    and existing['source_id'] == str(order_id) and existing['telegram_chat_id'] == str(chat_id)) else None
            now = _now()
            count = self.db.one('''SELECT COUNT(*) AS n FROM public.telegram_outbound_effects
                WHERE source_type=%s AND source_id=%s AND effect_type=%s AND created_at>%s''',
                ('App\\Models\\Order', str(order_id), TXT_TYPE, now - timedelta(seconds=600)))
            if count['n'] >= 3:
                return None
            effect = self.db.one('''INSERT INTO public.telegram_outbound_effects
                (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
                 telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,
                 next_attempt_at,outcome_metadata,created_at,updated_at)
                VALUES (%s,%s,%s,1,%s,%s,%s,'customer',%s,%s,'sendDocument',%s,'receipt-txt-v1',
                        'pending',0,%s,%s::json,%s,%s) RETURNING *''',
                (str(uuid4()), key, key, TXT_TYPE, 'App\\Models\\Order', str(order_id), str(chat_id), QUEUE,
                 source['locale'], now, json.dumps({'delivery_context': 'receipt_txt_download',
                 'request_nonce': nonce}), now, now))
            wake_after_commit('effect', str(effect['id']), using=self.using)
            return effect

    def handle(self, raw):
        kind, identity = decode_job(raw)
        return self.materialize(identity) if kind == 'order' else self.deliver(identity)

    def recover(self, limit=100):
        self._outside()
        if type(limit) is not int or not 1 <= limit <= 1000:
            raise ValueError('Invalid recovery limit')
        unknown = self.protocol.recover_expired_leases(limit)
        sent = self.protocol.completed_receipts_needing_projection(limit)
        repaired, failures = 0, 0
        for effect in sent:
            try:
                repaired += bool(self._complete(effect))
            except Exception:
                failures += 1
        orders = self.db.rows('''SELECT o.id FROM public.orders o JOIN public.customers c ON c.id=o.customer_id
            JOIN LATERAL (SELECT status,paid_at FROM public.payments WHERE order_id=o.id
                ORDER BY created_at DESC,id DESC LIMIT 1) payment ON payment.status='paid' AND payment.paid_at IS NOT NULL
            WHERE o.payment_status='paid' AND o.order_status IN ('processing','completed')
              AND o.telegram_receipt_sent_at IS NULL AND c.telegram_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM public.manual_fulfillment_reviews r
                  WHERE r.order_id=o.id AND r.reason='late_payment' AND r.state IN ('pending','refund_uncertain'))
              AND NOT EXISTS (SELECT 1 FROM public.supplier_fulfillments f
                  WHERE f.order_id=o.id AND f.state <> 'delivered')
              AND NOT EXISTS (SELECT 1 FROM public.supplier_manual_handoffs h
                  JOIN public.supplier_fulfillments f ON f.id=h.supplier_fulfillment_id WHERE f.order_id=o.id)
              AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e
                WHERE e.source_type=%s AND e.source_id=o.id::text AND e.effect_type=%s)
            ORDER BY o.id LIMIT %s''', ('App\\Models\\Order', EFFECT_TYPE, limit))
        for order in orders:
            try:
                self.materialize(str(order['id']))
            except Exception:
                failures += 1
        failed_photos = self.db.rows('''SELECT e.id,e.source_id FROM public.telegram_outbound_effects e
            WHERE e.effect_type=%s AND e.template_version=%s AND e.sequence=1 AND e.state='failed'
              AND e.last_error_code NOT IN ('receipt_not_eligible')
              AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects f
                  WHERE f.effect_group_key=e.effect_group_key || ':text-fallback')
            ORDER BY e.id LIMIT %s''', (EFFECT_TYPE, PHOTO_TEMPLATE, limit))
        for photo in failed_photos:
            try:
                self.materialize(str(photo['source_id']), fallback_from=str(photo['id']))
            except Exception:
                failures += 1
        effects = self.protocol.ready_effects(limit)
        for effect in effects:
            wake_after_commit('effect', str(effect['id']), using=self.using)
        return {'expired_unknown': unknown, 'projections_repaired': repaired, 'orders_discovered': len(orders),
                'effects_discovered': len(effects), 'source_failures': failures}
