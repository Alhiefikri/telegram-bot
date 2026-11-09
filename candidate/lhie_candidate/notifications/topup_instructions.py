"""Render existing Store Balance instruction obligations without money writes."""
from zoneinfo import ZoneInfo
from datetime import timedelta
from uuid import uuid4

from django.db import transaction

from ..funding.manual import ManualFunding
from ..funding.outbound import record_effect
from ..funding.service import FundingService, _deadline
from ..payments.presentation import QrPresentation
from ..receipt.render import _tr
from .policy import NotEligible, TOPUP_INSTRUCTIONS
from .proof_publication import PublicationUnavailable


class TopUpInstructions:
    def __init__(self, db, *, qris=None, funding=None):
        self.db = db
        self.qris = qris if qris is not None else QrPresentation(
            public_storage='/run/harness/public-assets', using=db.using)
        self.funding = funding if funding is not None else FundingService(using=db.using)
        self._cursor = 0
        self._cleanup_cursor = 0

    def cleanup(self, topup_id, recipients, transport):
        now, token = self.funding.clock(), str(uuid4())
        with transaction.atomic(using=self.db.using):
            target = self.db.one('''UPDATE public.telegram_outbound_effects e
                SET telegram_message_deletion_started_at=%s,telegram_message_deletion_token=%s,updated_at=%s
                WHERE e.id=(SELECT id FROM public.telegram_outbound_effects WHERE source_type=%s
                    AND source_id=%s AND effect_type=%s ORDER BY created_at DESC,id DESC LIMIT 1)
                  AND e.state='sent' AND e.telegram_chat_id=ANY(%s)
                  AND e.telegram_message_id::text ~ '^[1-9][0-9]{0,18}$'
                  AND e.telegram_message_deleted_at IS NULL
                  AND (e.telegram_message_deletion_started_at IS NULL OR e.telegram_message_deletion_started_at<=%s)
                  AND EXISTS (SELECT 1 FROM public.store_balance_top_ups t WHERE t.id=%s AND t.status='paid')
                RETURNING e.id,e.telegram_chat_id,e.telegram_message_id''',
                (now, token, now, 'App\\Models\\StoreBalanceTopUp', str(topup_id), TOPUP_INSTRUCTIONS,
                 list(recipients.telegram), now - timedelta(seconds=600), topup_id))
        if not target:
            return False
        try:
            result = transport.execute('deleteMessage', {'chat_id': target['telegram_chat_id'],
                                                          'message_id': int(target['telegram_message_id'])})
            deleted = result.outcome in ('success', 'not_modified')
        except Exception:
            deleted = False
        now = self.funding.clock()
        if deleted:
            return bool(self.db.execute('''UPDATE public.telegram_outbound_effects SET telegram_message_deleted_at=%s,
                telegram_message_deletion_started_at=NULL,telegram_message_deletion_token=NULL,updated_at=%s
                WHERE id=%s AND telegram_message_deletion_token=%s AND telegram_message_id=%s
                  AND telegram_message_deleted_at IS NULL''',
                (now, now, target['id'], token, target['telegram_message_id'])))
        self.db.execute('''UPDATE public.telegram_outbound_effects SET telegram_message_deletion_started_at=NULL,
            telegram_message_deletion_token=NULL,updated_at=%s WHERE id=%s AND telegram_message_deletion_token=%s
            AND telegram_message_id=%s AND telegram_message_deleted_at IS NULL''',
            (now, target['id'], token, target['telegram_message_id']))
        return False

    def cleanup_paid(self, recipients, limit, transport):
        rows = self.db.rows('''SELECT t.id FROM public.store_balance_top_ups t
            JOIN LATERAL (SELECT state,telegram_chat_id,telegram_message_id,telegram_message_deleted_at
                FROM public.telegram_outbound_effects WHERE source_type=%s AND source_id=t.id::text
                  AND effect_type=%s ORDER BY created_at DESC,id DESC LIMIT 1) e ON true
            WHERE t.id>%s AND t.status='paid' AND e.state='sent' AND e.telegram_message_deleted_at IS NULL
              AND e.telegram_chat_id=ANY(%s) AND e.telegram_message_id::text ~ '^[1-9][0-9]{0,18}$'
            ORDER BY t.id LIMIT %s''',
            ('App\\Models\\StoreBalanceTopUp', TOPUP_INSTRUCTIONS, self._cleanup_cursor,
             list(recipients.telegram), min(limit, 5)))
        self._cleanup_cursor = rows[-1]['id'] if rows else 0
        for row in rows:
            self.cleanup(row['id'], recipients, transport)

    def recover(self, recipients, limit):
        model = 'App\\Models\\StoreBalanceTopUp'
        rows = self.db.rows('''SELECT t.id,t.customer_id FROM public.store_balance_top_ups t
            JOIN public.customers c ON c.id=t.customer_id
            WHERE t.id>%s AND t.status IN ('payable','awaiting_confirmation')
              AND trim(t.chat_id)=ANY(%s) AND c.telegram_id::text=trim(t.chat_id)
              AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e
                  WHERE e.source_type=%s AND e.source_id=t.id::text AND e.effect_type=%s)
            ORDER BY t.id LIMIT %s''',
            (self._cursor, list(recipients.telegram), model, TOPUP_INSTRUCTIONS, limit))
        self._cursor = rows[-1]['id'] if rows else 0
        for candidate in rows:
            with transaction.atomic(using=self.db.using):
                customer = self.db.one('SELECT id,telegram_id,locale FROM public.customers WHERE id=%s FOR UPDATE',
                                       (candidate['customer_id'],))
                topup = self.db.one('''SELECT id,customer_id,chat_id,locale,provider,status,currency,amount,
                    provider_fee,provider_total_payment,provider_payment_method,expires_at,provider_expires_at
                    FROM public.store_balance_top_ups WHERE id=%s FOR UPDATE''', (candidate['id'],))
                if (not customer or not topup or topup['customer_id'] != customer['id']
                        or str(customer['telegram_id'] or '') != str(topup['chat_id'] or '').strip()
                        or not recipients.permits(str(topup['chat_id'] or '').strip())
                        or self.db.one('''SELECT id FROM public.telegram_outbound_effects
                            WHERE source_type=%s AND source_id=%s AND effect_type=%s LIMIT 1''',
                            (model, str(candidate['id']), TOPUP_INSTRUCTIONS))):
                    continue
                now = self.funding.clock()
                payable = (topup['status'] == 'payable'
                    and str(topup['currency'] or '').strip().upper() == 'IDR'
                    and str(topup['provider_payment_method'] or '').strip().upper() == 'QRIS'
                    and topup['amount'] > 0 and topup['provider_fee'] is not None and topup['provider_fee'] >= 0
                    and topup['provider_total_payment'] is not None and topup['provider_total_payment'] >= topup['amount']
                    and topup['expires_at'] is not None and _deadline(topup['expires_at']) > now
                    and topup['provider_expires_at'] is not None and _deadline(topup['provider_expires_at']) > now)
                if topup['status'] != 'awaiting_confirmation' and not payable:
                    continue
                provider = str(topup['provider'] or '').strip().lower()
                if provider not in ('manual', 'klikqris', 'sakurupiah'):
                    continue
                record_effect(self.db, topup['id'], TOPUP_INSTRUCTIONS, provider + '-instructions',
                              'customer', str(topup['chat_id']).strip(), 'sendPhoto',
                              'balance-top-up-' + provider + '-v1', now,
                              locale=topup['locale'] or customer['locale'])

    def payload(self, effect):
        if effect['target_type'] != 'customer' or effect['telegram_method'] != 'sendPhoto':
            raise NotEligible()
        row = self.db.one('''SELECT t.id,t.customer_id,t.chat_id,t.locale,t.provider,t.currency,t.amount,t.status,
            t.expires_at,t.provider_expires_at,t.provider_fee,t.provider_total_payment,t.provider_payment_method,
            t.provider_create_state,t.provider_qris_url,t.provider_qris_payload,c.telegram_id,c.locale AS customer_locale
            FROM public.store_balance_top_ups t JOIN public.customers c ON c.id=t.customer_id WHERE t.id=%s''',
            (effect['source_id'],))
        if (not row or str(row['telegram_id'] or '') != str(row['chat_id'] or '').strip()
                or effect['telegram_chat_id'] != str(row['chat_id'] or '').strip()
                or str(row['currency'] or '').strip().upper() != 'IDR'
                or type(row['amount']) is not int or row['amount'] <= 0
                or type(row['provider_total_payment']) is not int or row['provider_total_payment'] < 0
                or (row['provider_fee'] is not None and (type(row['provider_fee']) is not int or row['provider_fee'] < 0))):
            raise NotEligible()
        provider = str(row['provider'] or '').strip().lower()
        if provider == 'manual':
            view = ManualFunding(self.funding).presentation(row)
        elif provider in ('klikqris', 'sakurupiah'):
            if provider == 'klikqris' and provider not in self.funding.rails:
                raise PublicationUnavailable()
            if provider == 'sakurupiah' and self.funding.encrypter is None:
                from ..compat.crypto import LaravelEncrypter
                from ..runtime import application_key
                self.funding.encrypter = LaravelEncrypter(application_key())
            view = self.funding.presentation(row)
        else:
            raise NotEligible()
        deadline = view.get('expires')
        candidates = [value for value in (deadline, row['expires_at'], row['provider_expires_at']) if value is not None]
        if not candidates:
            raise NotEligible()
        deadline = min(value.replace(tzinfo=None) if value.tzinfo is None else value.astimezone(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None) for value in candidates)
        if not view.get('payable') or deadline <= self.funding.clock():
            raise NotEligible()
        expected = {'manual': 'balance-top-up-manual-v1', 'sakurupiah': 'balance-top-up-sakurupiah-v1',
                    'klikqris': 'balance-top-up-klikqris-v1'}[provider]
        if effect['template_version'] != expected:
            raise NotEligible()
        try:
            if provider == 'manual':
                photo = self.qris.manual()
            elif view.get('qris_payload'):
                photo = self.qris.payload(view['qris_payload'])
            else:
                url = view.get('qris_url')
                if not url:
                    raise PublicationUnavailable()
                photo = self.qris.provider_image(url, provider, environment='sandbox')
                if not photo:
                    raise PublicationUnavailable()
        except (OSError, ValueError):
            raise PublicationUnavailable() from None
        locale = effect['locale'] or row['locale'] or row['customer_locale'] or 'en'
        label = f"{deadline:%d} {_tr(locale, 'date.month.' + str(deadline.month))} {deadline:%Y %H:%M} WIB"
        def amount(value):
            return 'Rp' + f'{value:,}'.replace(',', '.')
        lines = [_tr(locale, 'balance.topup.instruction.title'), '━━━━━━━━━━━━━━',
                 _tr(locale, 'balance.topup.credit', amount=amount(row['amount'])),
                 _tr(locale, 'balance.topup.fee', amount=amount(row['provider_fee'] or 0)),
                 _tr(locale, 'balance.topup.total', amount=amount(row['provider_total_payment'])),
                 _tr(locale, 'balance.topup.deadline', expires=label), '',
                 _tr(locale, 'balance.topup.awaiting_confirmation' if provider == 'manual' else 'balance.topup.unpaid'),
                 _tr(locale, 'balance.topup.help')]
        button = {'text': _tr(locale, 'balance.topup.check'), 'style': 'primary',
                  'callback_data': f"balance:topup:{row['id']}:check"}
        return {'chat_id': effect['telegram_chat_id'], 'photo': photo, 'caption': '\n'.join(lines),
                'reply_markup': {'inline_keyboard': [[button]]}}
