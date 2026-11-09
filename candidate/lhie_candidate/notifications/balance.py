"""IDR top-up notification delivery; ledger history remains read-only."""
from datetime import timedelta
from html import escape
import os
import re
from types import SimpleNamespace
import unicodedata
from urllib.parse import quote, quote_plus
from zoneinfo import ZoneInfo

from django.db import transaction

from ..navigation.presentation import CatalogPresenter
from ..receipt.protocol import _now
from ..receipt.render import _len, _tr
from .html import localized_html
from .policy import COMPENSATION, OWNER_ALERT, TOPUP_CHANNEL_PROOF, NotEligible

RECEIPT = 'balance.top_up_receipt'
PAID = 'admin.balance_top_up_paid_notification'
PAYMENT_PROOF = 'admin.balance_top_up_payment_proof'

def redact_error(text):
    text = re.sub(r'capi_[a-f0-9]{32}(?:\.|%2e)[a-f0-9]{64}', '[redacted-customer-api-key]', text, flags=re.I)
    text = re.sub(r'https?://[^\s<>"\']+/bot[^/\s<>"\']+(?:/[^\s<>"\']*)?',
                  '[redacted-telegram-bot-api-url]', text, flags=re.I)
    text = re.sub(r'(?<![A-Za-z0-9])/bot[^/\s<>"\']+(?:/[^\s<>"\']*)?',
                  '[redacted-telegram-bot-api-path]', text, flags=re.I)
    text = re.sub(r'(?<![A-Za-z0-9_-])(?:wa_confirm(?::|%3A)|wa_)[A-Za-z0-9_-]{43}(?![A-Za-z0-9_-])',
                  '[redacted-secret]', text, flags=re.I)
    for key in ('TELEGRAM_BOT_TOKEN', 'TELEGRAM_WEBHOOK_SECRET'):
        secret = os.environ.get(key, '')
        if len(secret.encode('utf-8')) >= 4:
            for value in (secret, quote(secret, safe=''), quote_plus(secret, safe='').replace('~', '%7E')):
                text = text.replace(value, '[redacted-secret]')
    return text


def trim_width(text, maximum):
    widths = [2 if unicodedata.east_asian_width(char) in ('W', 'F') else 1 for char in text]
    if sum(widths) <= maximum:
        return text
    width = 0
    for index, size in enumerate(widths):
        if width + size > maximum - 3:
            return text[:index] + '...'
        width += size
    return text


class BalanceNotifications:
    def __init__(self, db, recipients):
        self.db, self.recipients = db, recipients
        self._cursor = 0
        self._proof_cursor = 0
        self._compensation_cursor = 0
        self._alert_cursor = 0
        self._channel_cursor = 0

    def load(self, identity):
        row = self.db.one('''SELECT t.id,t.customer_id,t.chat_id,t.locale,t.amount,t.status,t.merchant_ref,
            t.provider,t.provider_reference,t.provider_total_payment,t.provider_payment_method,t.provider_fee,t.currency,t.paid_at,t.settled_at,
            c.telegram_id,c.locale AS customer_locale,c.username,c.first_name
            FROM public.store_balance_top_ups t JOIN public.customers c ON c.id=t.customer_id WHERE t.id=%s''', (identity,))
        if not row or row['status'] != 'paid' or row['settled_at'] is None:
            raise NotEligible()
        # A status label never grants a credit notice. Prove the immutable
        # posting belongs to this same Customer and exact original source.
        entry = self.db.one('''SELECT e.id FROM public.store_balance_entries e
            JOIN public.customer_balance_accounts a ON a.id=e.customer_balance_account_id
            WHERE a.customer_id=%s AND e.kind='top_up_credit' AND e.source_type='balance_top_up'
            AND e.source_id=%s AND e.amount=%s LIMIT 1''', (row['customer_id'], str(row['id']), row['amount']))
        if not entry:
            raise NotEligible()
        return row

    def channel_proof(self, effect):
        from ..receipt.invoice import render_invoice
        from .proof import mask
        from .proof_publication import TEXT_ICONS, money

        setting = self.db.one('''SELECT sales_proof_enabled,telegram_chat_id,channel_username
            FROM public.store_channel_settings WHERE singleton_key='store_channel' ''')
        current = str((setting or {}).get('telegram_chat_id') or (setting or {}).get('channel_username') or '').strip()
        if (not setting or not setting['sales_proof_enabled'] or not self.recipients.permits(current)
                or effect['target_type'] != 'store_channel' or effect['telegram_method'] != 'sendPhoto'
                or effect['template_version'] != 'balance-top-up-channel-proof-v1'):
            raise NotEligible()
        row = self.load(effect['source_id'])
        method = str(row['provider_payment_method'] or '').strip().upper()
        if (str(row['currency'] or '').strip().upper() != 'IDR' or method not in ('QRIS', 'QR_MANUAL')
                or type(row['amount']) is not int or row['amount'] <= 0
                or type(row['provider_total_payment']) is not int or row['provider_total_payment'] < 0
                or (row['provider_fee'] is not None and (type(row['provider_fee']) is not int or row['provider_fee'] < 0))):
            raise NotEligible()
        paid = row['paid_at'] or row['settled_at']
        if paid.tzinfo is None:
            paid = paid.replace(tzinfo=ZoneInfo('Asia/Jakarta'))
        paid = paid.astimezone(ZoneInfo('Asia/Jakarta'))
        def clean(value, limit):
            value = re.sub(r'[\x00-\x1f\x7f]+', ' ', str(value or '')).strip()
            return value if len(value) <= limit else value[:limit - 1].rstrip() + '…'
        reference = clean(row['merchant_ref'], 96) or 'TOPUP-' + str(row['id'])
        label = 'QRIS' if method == 'QRIS' else 'QR Manual'
        buyer = mask(row['first_name'], row['username'])
        name = str(row['first_name'] or '').strip()
        if not name or name.lower() == 'telegram customer':
            name = str(row['username'] or '').strip()
        icons = self.db.rows('''SELECT key,source_emoji,fallback_emoji FROM public.telegram_ui_icons
            ORDER BY sort_order,label,id''')
        slots = {item['key']: item for item in icons}
        by_source = {item['source_emoji']: str(item['fallback_emoji'] or '').strip()
                     for item in icons if item['source_emoji']}
        pattern = re.compile('|'.join(re.escape(value) for value in sorted(by_source, key=len, reverse=True))) if by_source else None
        def icon(value):
            return pattern.sub(lambda match: by_source[match.group()], value) if pattern else value
        def slot(key):
            return str(slots[key]['fallback_emoji'] or '').strip() if key in slots else TEXT_ICONS[key]
        caption = '\n'.join((icon('🎉 BALANCE TOP-UP CONFIRMED'), '',
            slot('message.users') + ' Buyer: ' + buyer, slot('message.ticket') + ' Top-up ID: ' + reference,
            icon('💳 Paid via: ') + label,
            slot('message.deadline') + ' Completed: ' + paid.strftime('%d %B %Y, %H:%M WIB'),
            slot('message.cash') + ' Amount paid: ' + money(row['provider_total_payment'], 'IDR'),
            '', '━━━━━━━━━━━━━━', icon('✨ Thank you for topping up at Lhie Store.')))
        snapshot = {'invoice_number': reference, 'buyer_name': name, 'chat_id': None, 'paid_at': paid,
                    'payment_method': label, 'currency': 'IDR', 'provider_fee': row['provider_fee'] or 0,
                    'total_paid': row['provider_total_payment'], 'items': [
                        {'product_name': 'Store Balance Top-Up', 'quantity': 1,
                         'unit_price': row['amount'], 'subtotal': row['amount']}]}
        return {'chat_id': effect['telegram_chat_id'], 'photo': render_invoice(snapshot), 'caption': caption,
                'reply_markup': {'inline_keyboard': [[{'text': icon('🛒 Shop Now'), 'style': 'primary',
                    'url': 'https://t.me/LhieStoreTestBot?start=landing'}]]}}

    def owner_alert(self, effect, admin_target):
        if (effect['target_type'] != 'admin' or effect['telegram_method'] != 'sendMessage'
                or not admin_target or effect['telegram_chat_id'] != admin_target):
            raise NotEligible()
        row = self.db.one('''SELECT t.id,t.status,t.amount,t.payment_proof_file_id,t.provider_create_last_error,
            c.id AS customer_id,c.username,c.first_name,c.telegram_id
            FROM public.store_balance_top_ups t LEFT JOIN public.customers c ON c.id=t.customer_id
            WHERE t.id=%s AND NOT EXISTS (SELECT 1 FROM public.store_balance_compensations x
                                          WHERE x.store_balance_top_up_id=t.id)''', (effect['source_id'],))
        if (not row or row['status'] not in ('recovery_pending', 'invalid_provider_result', 'expired')
                or (row['status'] == 'expired' and not str(row['payment_proof_file_id'] or '').strip())
                or type(row['amount']) is not int or row['amount'] < 0):
            raise NotEligible()
        labels = {'recovery_pending': 'Recovery pending (status provider belum dapat dipastikan)',
                  'invalid_provider_result': 'Hasil provider invalid / butuh peninjauan',
                  'expired': 'Kedaluwarsa dengan bukti pembayaran'}
        if row['customer_id'] is None:
            customer = 'Customer tidak ditemukan'
        else:
            customer = str(row['first_name'] or row['username'] or row['telegram_id'] or 'Customer').strip()
            username = str(row['username'] or '').strip()
            if username:
                customer += ' (@' + username.lstrip('@') + ')'
        icons = self.db.rows('''SELECT source_emoji,fallback_emoji,custom_emoji_id FROM public.telegram_ui_icons
            WHERE source_emoji IS NOT NULL ORDER BY sort_order,label,id''')
        by_source = {icon['source_emoji']: icon for icon in icons if icon['source_emoji']}
        pattern = re.compile('|'.join(re.escape(value) for value in sorted(by_source, key=len, reverse=True))) if by_source else None
        lines, entities = [], []
        def add(prefix, value=''):
            offset = _len('\n'.join(lines)) + (1 if lines else 0)
            parts, cursor = [], 0
            for match in pattern.finditer(prefix) if pattern else ():
                parts.append(prefix[cursor:match.start()])
                icon = by_source[match.group()]
                glyph = str(icon['fallback_emoji'] or '').strip()
                identity = str(icon['custom_emoji_id'] or '').strip()
                if identity:
                    entities.append({'type': 'custom_emoji', 'offset': offset + _len(''.join(parts)),
                                     'length': _len(glyph), 'custom_emoji_id': identity})
                parts.append(glyph)
                cursor = match.end()
            parts.append(prefix[cursor:])
            lines.append(''.join(parts) + value)
        add('⚠️ Top-up saldo butuh keputusan owner')
        add('━━━━━━━━━━━━━━')
        add('🧾 Top-up: ', '#' + str(row['id']))
        add('👤 Customer: ', customer)
        add('💰 Nominal: ', 'IDR ' + f"{row['amount']:,}".replace(',', '.'))
        add('📌 Kondisi: ', labels[row['status']])
        detail = str(row['provider_create_last_error'] or '').strip()
        if detail:
            add('🔎 Detail: ', trim_width(redact_error(detail), 240))
        add('Buka dashboard admin pada tab Kompensasi Top-up: periksa provider sebelum memutuskan release saldo.')
        payload = {'chat_id': effect['telegram_chat_id'], 'text': '\n'.join(lines)}
        if entities:
            payload['entities'] = entities
        return payload

    def compensation_source(self, identity, chat):
        row = self.db.one('''SELECT c.id,c.amount,c.resolved_at,c.created_at,t.id AS topup_id,
            t.amount AS topup_amount,t.status,t.currency,t.chat_id,
            u.telegram_id,u.locale AS customer_locale,e.kind,e.amount AS entry_amount,e.source_type,
            e.source_id,a.customer_id AS account_customer_id,c.customer_id AS compensation_customer_id,
            t.customer_id AS topup_customer_id
            FROM public.store_balance_compensations c
            JOIN public.store_balance_top_ups t ON t.id=c.store_balance_top_up_id
            JOIN public.customers u ON u.id=c.customer_id
            JOIN public.store_balance_entries e ON e.id=c.ledger_entry_id
            JOIN public.customer_balance_accounts a ON a.id=e.customer_balance_account_id
            WHERE c.id=%s''', (identity,))
        if (not row or row['status'] != 'compensated' or row['currency'] != 'IDR'
                or row['kind'] != 'owner_compensation_credit' or row['source_type'] != 'owner_compensation'
                or row['source_id'] != 'top_up:' + str(row['topup_id'])
                or type(row['amount']) is not int or row['amount'] < 0
                or row['amount'] != row['topup_amount'] or row['entry_amount'] != row['amount']
                or row['account_customer_id'] != row['compensation_customer_id']
                or row['topup_customer_id'] != row['compensation_customer_id']
                or str(row['telegram_id']) != str(row['chat_id']).strip()
                or chat != str(row['chat_id']).strip()):
            raise NotEligible()
        return row

    def compensation(self, effect):
        if effect['target_type'] != 'customer' or effect['telegram_method'] != 'sendMessage':
            raise NotEligible()
        row = self.compensation_source(effect['source_id'], effect['telegram_chat_id'])
        locale = effect['locale'] or row['customer_locale'] or 'en'
        date_locale = row['customer_locale'] if row['customer_locale'] in ('id', 'ms', 'en') else 'en'
        date = (row['resolved_at'] or row['created_at']).astimezone(ZoneInfo('Asia/Jakarta'))
        date_label = f"{date:%d} {_tr(date_locale, 'date.month.' + str(date.month))} {date:%Y %H:%M} WIB"
        icons = self.db.rows('SELECT key,source_emoji,fallback_emoji,custom_emoji_id FROM public.telegram_ui_icons')
        def tr(key, **values):
            return localized_html(locale, 'balance.compensation.' + key, icons=icons, custom=True, **values)
        text = '\n'.join((tr('title'), tr('credit', amount='Rp ' + f"{row['amount']:,}".replace(',', '.')),
                          tr('explanation'), tr('reference', reference='#' + str(row['topup_id'])),
                          tr('resolved_at', date=date_label), tr('support')))
        presenter = CatalogPresenter(SimpleNamespace(icons=lambda: icons), custom_emoji=True)
        def button(slot, action):
            return presenter._button({'locale': locale}, slot, action, slot=slot)
        markup = {'inline_keyboard': [[button('menu.balance', 'balance'), button('menu.home', 'menu:home')],
                                     [button('menu.support', 'menu:support')]]}
        return {'chat_id': effect['telegram_chat_id'], 'text': text, 'parse_mode': 'HTML', 'reply_markup': markup}

    def payment_proof(self, effect):
        row = self.db.one('''SELECT t.id,t.provider,t.status,t.amount,t.provider_total_payment,t.expires_at,t.provider_expires_at,
            t.payment_proof_file_id,t.payment_proof_submitted_at,t.payment_proof_forwarded_at,
            t.payment_proof_admin_chat_id,c.locale AS customer_locale
            FROM public.store_balance_top_ups t JOIN public.customers c ON c.id=t.customer_id WHERE t.id=%s''',
            (effect['source_id'],))
        if (not row or (row['provider'] or '').strip().lower() != 'manual' or row['status'] != 'awaiting_confirmation'
                or not row['payment_proof_file_id'] or not row['payment_proof_submitted_at']
                or row['payment_proof_forwarded_at'] is not None or effect['target_type'] != 'admin'
                or effect['telegram_method'] != 'sendPhoto'
                or str(row['payment_proof_admin_chat_id']) != effect['telegram_chat_id']):
            raise NotEligible()
        icons = self.db.rows('SELECT source_emoji,fallback_emoji,custom_emoji_id FROM public.telegram_ui_icons')
        def tr(key, **values):
            return localized_html('en', 'balance.topup.payment_proof.' + key, icons=icons, custom=True, **values)
        def idr(value):
            if type(value) is not int or value < 0:
                raise NotEligible()
            return 'Rp ' + f'{value:,}'.replace(',', '.')
        lines = [tr('title'), '━━━━━━━━━━━━━━', tr('credit', amount=idr(row['amount']))]
        date = row['expires_at'] or row['provider_expires_at']
        if date is None:
            raise NotEligible()
        locale = row['customer_locale'] if row['customer_locale'] in ('id', 'ms', 'en') else 'en'
        date_label = f"{date:%d} {_tr(locale, 'date.month.' + str(date.month))} {date:%Y %H:%M} WIB"
        lines.extend((tr('total', amount=idr(row['provider_total_payment'] if row['provider_total_payment'] is not None else row['amount'])),
                      tr('deadline', expires=date_label)))
        lines.extend((tr('reference', reference='#' + str(row['id'])), tr('help')))
        keyboard = {'inline_keyboard': [[
            {'text': _tr('en', 'balance.topup.payment_proof.approve'), 'style': 'primary',
             'callback_data': 'admin:topup-approve:' + str(row['id'])},
            {'text': _tr('en', 'balance.topup.payment_proof.reject'), 'style': 'primary',
             'callback_data': 'admin:topup-reject:' + str(row['id'])}]]}
        return {'chat_id': effect['telegram_chat_id'], 'photo': row['payment_proof_file_id'],
                'caption': '\n'.join(lines), 'parse_mode': 'HTML', 'reply_markup': keyboard}

    def payload(self, effect):
        if effect['effect_type'] == PAYMENT_PROOF:
            return self.payment_proof(effect)
        topup = self.load(effect['source_id'])
        def idr(value, spaced=False):
            if type(value) is not int or value < 0:
                raise NotEligible()
            return ('Rp ' if spaced else 'Rp') + f'{value:,}'.replace(',', '.')
        icons = self.db.rows('SELECT key,source_emoji,fallback_emoji,custom_emoji_id FROM public.telegram_ui_icons')
        if effect['effect_type'] == RECEIPT:
            if (effect['target_type'] != 'customer' or topup['provider_total_payment'] is None
                    or str(topup['telegram_id']) != topup['chat_id'] or effect['telegram_chat_id'] != topup['chat_id']):
                raise NotEligible()
            locale = effect['locale'] or topup['locale'] or topup['customer_locale'] or 'id'
            date = topup['paid_at'] or topup['settled_at']
            date_label = f"{date:%d} {_tr(locale, 'date.month.' + str(date.month))} {date:%Y %H:%M} WIB"
            def tr(key, **values):
                return localized_html(locale, 'balance.topup.receipt.' + key, icons=icons, custom=True, **values)
            text = '\n'.join((tr('title'), tr('credit', amount=idr(topup['amount'])),
                tr('provider_total', amount=idr(topup['provider_total_payment'])),
                tr('reference', reference=topup['provider_reference'] or topup['merchant_ref']),
                tr('paid_at', date=date_label), tr('support')))
            presenter = CatalogPresenter(SimpleNamespace(icons=lambda: icons), custom_emoji=True)
            def button(slot, key, action):
                return presenter._button({'locale': locale}, key, action, slot=slot)
            markup = {'inline_keyboard': [[button('menu.balance', 'menu.balance', 'balance'),
                                          button('menu.home', 'menu.home', 'menu:home')],
                                         [button('menu.support', 'menu.support', 'menu:support')]]}
        else:
            if effect['target_type'] != 'admin':
                raise NotEligible()
            buyer = '@' + topup['username'].strip().lstrip('@') if (topup['username'] or '').strip() else (topup['first_name'] or '').strip() or 'Customer'
            if topup['telegram_id']:
                buyer += ' (' + str(topup['telegram_id']) + ')'
            title = '🔔 Hasil topup balance'
            for icon in icons:
                if icon['source_emoji']:
                    title = title.replace(icon['source_emoji'], icon['fallback_emoji'] or '')
            lines = [title, '━━━━━━━━━━━━━━', 'Invoice: ' + topup['merchant_ref'], 'Pembeli: ' + buyer,
                     'Sumber: Telegram Bot', 'Status pembayaran: Paid', 'Saldo dikreditkan: ' + idr(topup['amount'], True)]
            if topup['provider_total_payment'] is not None:
                lines.append('Total pembayaran: ' + idr(topup['provider_total_payment'], True))
            lines.extend(('Metode: ' + (topup['provider_payment_method'] or topup['provider']).upper(),
                          'Provider: ' + topup['provider'].upper(), '',
                          'Paid at: ' + (topup['paid_at'] or topup['settled_at']).strftime('%d %b %Y, %H:%M'), '',
                          'Topup selesai. Saldo sudah dikreditkan. Tidak perlu proses ulang.'))
            text, markup = escape('\n'.join(lines)), {}
        return {'chat_id': effect['telegram_chat_id'], 'text': text, 'parse_mode': 'HTML', 'reply_markup': markup}

    def recover_channel_proofs(self, record, limit):
        setting = self.db.one('''SELECT sales_proof_enabled,telegram_chat_id,channel_username
            FROM public.store_channel_settings WHERE singleton_key='store_channel' ''')
        chat = str((setting or {}).get('telegram_chat_id') or (setting or {}).get('channel_username') or '').strip()
        if not setting or not setting['sales_proof_enabled'] or not self.recipients.permits(chat):
            return
        model = 'App\\Models\\StoreBalanceTopUp'
        rows = self.db.rows('''SELECT t.id FROM public.store_balance_top_ups t
            WHERE t.id>%s AND t.status='paid' AND t.settled_at >= %s
              AND upper(trim(t.currency))='IDR' AND upper(trim(t.provider_payment_method)) IN ('QRIS','QR_MANUAL')
              AND t.amount>0 AND t.provider_total_payment IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e
                WHERE e.source_type=%s AND e.source_id=t.id::text AND e.effect_type=%s)
            ORDER BY t.id LIMIT %s''', (self._channel_cursor, _now() - timedelta(days=7), model, TOPUP_CHANNEL_PROOF, limit))
        self._channel_cursor = rows[-1]['id'] if rows else 0
        for candidate in rows:
            with transaction.atomic(using=self.db.using):
                self.db.one('SELECT id FROM public.store_balance_top_ups WHERE id=%s FOR UPDATE', (candidate['id'],))
                if self.db.one('''SELECT id FROM public.telegram_outbound_effects
                    WHERE source_type=%s AND source_id=%s AND effect_type=%s LIMIT 1''',
                    (model, str(candidate['id']), TOPUP_CHANNEL_PROOF)):
                    continue
                try:
                    row = self.load(candidate['id'])
                except NotEligible:
                    continue
                if (str(row['currency'] or '').strip().upper() != 'IDR'
                        or str(row['provider_payment_method'] or '').strip().upper() not in ('QRIS', 'QR_MANUAL')
                        or row['amount'] <= 0 or row['provider_total_payment'] is None
                        or row['settled_at'] < _now() - timedelta(days=7)):
                    continue
                record(key=f"balance-top-up-channel-proof:{row['id']}:v1", kind=TOPUP_CHANNEL_PROOF,
                       model='StoreBalanceTopUp', source=row['id'], chat=chat, target='store_channel',
                       locale='en', template='balance-top-up-channel-proof-v1', method='sendPhoto')

    def recover_alerts(self, record, admin_target, limit):
        if not admin_target or not self.recipients.permits(admin_target):
            return
        model = 'App\\Models\\StoreBalanceTopUp'
        rows = self.db.rows('''SELECT t.id FROM public.store_balance_top_ups t
            WHERE t.id>%s AND (t.status IN ('recovery_pending','invalid_provider_result')
                OR (t.status='expired' AND trim(COALESCE(t.payment_proof_file_id,''))<>''))
              AND NOT EXISTS (SELECT 1 FROM public.store_balance_compensations c WHERE c.store_balance_top_up_id=t.id)
              AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e
                WHERE e.source_type=%s AND e.source_id=t.id::text AND e.effect_type=%s)
            ORDER BY t.id LIMIT %s''', (self._alert_cursor, model, OWNER_ALERT, limit))
        self._alert_cursor = rows[-1]['id'] if rows else 0
        for candidate in rows:
            with transaction.atomic(using=self.db.using):
                topup = self.db.one('''SELECT id,status,payment_proof_file_id FROM public.store_balance_top_ups
                    WHERE id=%s FOR UPDATE''', (candidate['id'],))
                if (not topup or topup['status'] not in ('recovery_pending', 'invalid_provider_result', 'expired')
                        or (topup['status'] == 'expired' and not str(topup['payment_proof_file_id'] or '').strip())
                        or self.db.one('SELECT id FROM public.store_balance_compensations WHERE store_balance_top_up_id=%s',
                                       (candidate['id'],))
                        or self.db.one('''SELECT id FROM public.telegram_outbound_effects
                            WHERE source_type=%s AND source_id=%s AND effect_type=%s LIMIT 1''',
                                       (model, str(candidate['id']), OWNER_ALERT))):
                    continue
                record(key=f"balance-top-up-owner-alert:{topup['id']}:v1", kind=OWNER_ALERT,
                       model='StoreBalanceTopUp', source=topup['id'], chat=admin_target, target='admin',
                       locale=None, template='balance-top-up-owner-alert-v1')

    def recover_compensations(self, record, limit):
        model = 'App\\Models\\StoreBalanceCompensation'
        rows = self.db.rows('''SELECT c.id,trim(t.chat_id) AS chat_id FROM public.store_balance_compensations c
            JOIN public.store_balance_top_ups t ON t.id=c.store_balance_top_up_id
            WHERE c.id>%s AND trim(t.chat_id)=ANY(%s) AND NOT EXISTS (
                SELECT 1 FROM public.telegram_outbound_effects e
                WHERE e.source_type=%s AND e.source_id=c.id::text AND e.effect_type=%s)
            ORDER BY c.id LIMIT %s''',
            (self._compensation_cursor, list(self.recipients.telegram), model, COMPENSATION, limit))
        self._compensation_cursor = rows[-1]['id'] if rows else 0
        for candidate in rows:
            with transaction.atomic(using=self.db.using):
                self.db.one("SELECT pg_advisory_xact_lock(hashtextextended(%s,0))",
                            ('p25-owner-compensation:' + str(candidate['id']),))
                if self.db.one('''SELECT id FROM public.telegram_outbound_effects
                    WHERE source_type=%s AND source_id=%s AND effect_type=%s LIMIT 1''',
                    (model, str(candidate['id']), COMPENSATION)):
                    continue
                try:
                    row = self.compensation_source(candidate['id'], candidate['chat_id'])
                except NotEligible:
                    continue
                record(key=f"balance-owner-compensation:{row['id']}:notification:v1",
                       kind=COMPENSATION, model='StoreBalanceCompensation', source=row['id'],
                       chat=candidate['chat_id'], target='customer', locale=row['customer_locale'],
                       template='balance-owner-compensation-v1')

    def recover_proofs(self, record, limit):
        chats = list(self.recipients.telegram)
        rows = self.db.rows('''SELECT t.id FROM public.store_balance_top_ups t
            WHERE t.id>%s AND lower(trim(t.provider))='manual' AND t.status='awaiting_confirmation'
              AND t.payment_proof_file_id IS NOT NULL AND t.payment_proof_submitted_at IS NOT NULL
              AND t.payment_proof_forwarded_at IS NULL AND t.payment_proof_admin_chat_id=ANY(%s)
              AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e
                WHERE e.effect_type=%s AND e.source_type=%s AND e.source_id=t.id::text)
            ORDER BY t.id LIMIT %s''',
            (self._proof_cursor, chats, PAYMENT_PROOF, 'App\\Models\\StoreBalanceTopUp', limit))
        self._proof_cursor = rows[-1]['id'] if rows else 0
        for row in rows:
            with transaction.atomic(using=self.db.using):
                topup = self.db.one('''SELECT id,provider,status,payment_proof_file_id,
                    payment_proof_submitted_at,payment_proof_forwarded_at,payment_proof_admin_chat_id
                    FROM public.store_balance_top_ups WHERE id=%s FOR UPDATE''', (row['id'],))
                if (not topup or (topup['provider'] or '').strip().lower() != 'manual' or topup['status'] != 'awaiting_confirmation'
                        or not topup['payment_proof_file_id'] or not topup['payment_proof_submitted_at']
                        or topup['payment_proof_forwarded_at'] is not None
                        or not self.recipients.permits(topup['payment_proof_admin_chat_id'])
                        or self.db.one('''SELECT id FROM public.telegram_outbound_effects
                            WHERE effect_type=%s AND source_type=%s AND source_id=%s LIMIT 1''',
                            (PAYMENT_PROOF, 'App\\Models\\StoreBalanceTopUp', str(topup['id'])))):
                    continue
                record(key=f"balance-top-up:{topup['id']}:admin-payment-proof:v1", kind=PAYMENT_PROOF,
                       model='StoreBalanceTopUp', source=topup['id'], chat=topup['payment_proof_admin_chat_id'],
                       target='admin', locale=None, template='balance-top-up-payment-proof-v1', method='sendPhoto')

    def recover(self, record, limit):
        rows = self.db.rows('''SELECT id FROM public.store_balance_top_ups WHERE status='paid'
            AND settled_at IS NOT NULL AND id>%s ORDER BY id LIMIT %s''', (self._cursor, limit))
        self._cursor = rows[-1]['id'] if rows else 0
        setting = self.db.one('SELECT admin_telegram_chat_id FROM public.manual_payment_settings WHERE is_active=true ORDER BY id LIMIT 1')
        admin = str((setting or {}).get('admin_telegram_chat_id') or '').strip()
        for row in rows:
            with transaction.atomic(using=self.db.using):
                self.db.one("SELECT pg_advisory_xact_lock(hashtextextended(%s,0))", ('p25-balance-notice:' + str(row['id']),))
                try:
                    topup = self.load(row['id'])
                except NotEligible:
                    continue
                if self.recipients.permits(topup['chat_id']) and str(topup['telegram_id']) == topup['chat_id']:
                    record(key=f"balance-top-up:{topup['id']}:{topup['provider']}-receipt:v1", kind=RECEIPT,
                           model='StoreBalanceTopUp', source=topup['id'], chat=topup['chat_id'], target='customer',
                           locale=topup['locale'] or topup['customer_locale'] or 'id', template='balance-top-up-receipt-v1')
                if self.recipients.permits(admin):
                    record(key=f"balance-top-up:{topup['id']}:admin-paid:v1", kind=PAID,
                           model='StoreBalanceTopUp', source=topup['id'], chat=admin, target='admin', locale='id',
                           template='balance-top-up-admin-paid:v1')
