"""IDR receipt and paid Owner notice; ledger history remains read-only."""
from html import escape

from django.db import transaction

from ..receipt.protocol import _now
from ..receipt.render import _tr
from .html import localized_html
from .policy import NotEligible

RECEIPT = 'balance.top_up_receipt'
PAID = 'admin.balance_top_up_paid_notification'
TYPES = (RECEIPT, PAID)


class BalanceNotifications:
    def __init__(self, db, recipients):
        self.db, self.recipients = db, recipients
        self._cursor = 0

    def load(self, identity):
        row = self.db.one('''SELECT t.id,t.customer_id,t.chat_id,t.locale,t.amount,t.status,t.merchant_ref,
            t.provider,t.provider_reference,t.provider_total_payment,t.provider_payment_method,t.paid_at,t.settled_at,
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

    def payload(self, effect):
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
            def button(slot, key, action):
                label = _tr(locale, key)
                icon = next((item for item in icons if item['key'] == slot), None)
                value = {'text': label, 'style': 'primary', 'callback_data': action}
                if icon and icon['custom_emoji_id']:
                    value['icon_custom_emoji_id'] = icon['custom_emoji_id']
                return value
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
