"""IDR funding notices on the shared outbound ledger, with frozen PHP presentation."""
import base64
from datetime import datetime
from html import escape
import re
import unicodedata
from zoneinfo import ZoneInfo

from django.db import transaction

from ..receipt.protocol import _now
from .bulk_content import icon, icons, translate
from .policy import NotEligible
from .proof import mask
from .snapshot import frozen_payload, latest_sent, metadata, record_snapshot

INSTRUCTIONS = 'balance.top_up_instructions'
RECEIPT = 'balance.top_up_receipt'
OWNER_ALERT = 'admin.balance_top_up_owner_alert'
PAYMENT_PROOF = 'admin.balance_top_up_payment_proof'
PAID = 'admin.balance_top_up_paid_notification'
COMPENSATION = 'balance.owner_compensation_notification'
ADJUSTMENT = 'balance.adjustment_notice'
CHANNEL_PROOF = 'balance.top_up_channel_proof'
MODELS = {kind: 'StoreBalanceTopUp' for kind in (INSTRUCTIONS, RECEIPT, OWNER_ALERT, PAYMENT_PROOF, PAID, CHANNEL_PROOF)}
MODELS.update({COMPENSATION: 'StoreBalanceCompensation', ADJUSTMENT: 'BalanceAdjustmentRequest'})
LIMITS = {kind: 3 for kind in MODELS}
_TOPUP_COLUMNS = '''t.id,t.customer_id,t.chat_id,t.locale,t.provider,t.merchant_ref,t.currency,t.amount,
    t.status,t.provider_reference,t.provider_fee,t.provider_total_payment,t.provider_payment_method,
    t.provider_expires_at,t.expires_at,t.provider_create_last_error,t.paid_at,t.settled_at,
    t.payment_proof_file_id,t.payment_proof_submitted_at,t.payment_proof_admin_chat_id,
    t.payment_proof_forwarded_at,c.telegram_id,c.first_name,c.username,c.locale AS customer_locale,
    EXISTS(SELECT 1 FROM public.store_balance_compensations x WHERE x.store_balance_top_up_id=t.id) AS compensated'''
_STATUS = {'recovery_pending': 'Recovery pending (status provider belum dapat dipastikan)',
           'invalid_provider_result': 'Hasil provider invalid / butuh peninjauan',
           'expired': 'Kedaluwarsa dengan bukti pembayaran'}


def instruction_eligible(row, now=None):
    if (row.get('status') not in ('payable', 'awaiting_confirmation') or not customer_eligible(row)
            or row.get('currency') != 'IDR' or type(row.get('amount')) is not int or row['amount'] <= 0
            or type(row.get('provider_fee')) is not int or row['provider_fee'] < 0
            or type(row.get('provider_total_payment')) is not int or row['provider_total_payment'] < row['amount']):
        return False
    manual = row.get('provider') == 'manual'
    if row.get('status') != ('awaiting_confirmation' if manual else 'payable'):
        return False
    if row.get('provider_payment_method') != ('QR_MANUAL' if manual else 'QRIS'):
        return False
    deadlines = [row.get('expires_at'), row.get('provider_expires_at')]
    if not all(isinstance(value, datetime) for value in deadlines):
        return False
    now = now or _now()
    normalized = [value.astimezone(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)
                  if value.tzinfo else value for value in deadlines]
    return min(normalized) > now


def render_instructions(row, chat, locale, rows=()):
    def tr(key, **values):
        return translate(locale, 'balance.topup.' + key, rows, **values)
    money = _rupiah
    lines = [tr('instruction.title'), '━━━━━━━━━━━━━━', tr('credit', amount=money(row['amount'])),
             tr('fee', amount=money(row['provider_fee'])), tr('total', amount=money(row['provider_total_payment'])),
             tr('deadline', expires=_date(min(row['expires_at'], row['provider_expires_at']), locale)),
             '', tr('awaiting_confirmation' if row['status'] == 'awaiting_confirmation' else 'unpaid'), tr('help')]
    return {'chat_id': str(chat), 'photo': 'attach://qris', 'caption': '\n'.join(lines), 'parse_mode': 'HTML',
            'reply_markup': {'inline_keyboard': [[{'text': translate(locale, 'balance.topup.check', custom=False),
                'callback_data': f"balance:topup:{row['id']}:check", 'style': 'primary'}]]}}


def _locale(value):
    return value if value in ('id', 'en', 'ms') else 'en'


def _idr(value):
    if type(value) is not int or value < 0:
        raise NotEligible()
    return 'Rp ' + f'{value:,}'.replace(',', '.')


def _idr_code(value):
    return _idr(value).replace('Rp ', 'IDR ', 1)


def _rupiah(value):
    return _idr(value).replace('Rp ', 'Rp', 1)


def _date(value, locale, *, zone=True, comma=False):
    if isinstance(value, str):
        value = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if not isinstance(value, datetime):
        raise NotEligible()
    value = value.replace(tzinfo=ZoneInfo('Asia/Jakarta')) if value.tzinfo is None else value.astimezone(ZoneInfo('Asia/Jakarta'))
    month = value.strftime('%b') if comma else translate(locale, 'date.month.' + str(value.month), custom=False)
    return f'{value.day:02d} {month} {value.year}' + (', ' if comma else ' ') + value.strftime('%H:%M') + (' WIB' if zone else '')


def _legacy(text, rows):
    by_source = {row['source_emoji']: row for row in rows if row.get('source_emoji')}
    if not by_source:
        return escape(text)
    pattern = re.compile('|'.join(re.escape(s) for s in sorted(by_source, key=len, reverse=True)))
    result, start = [], 0
    for match in pattern.finditer(text):
        result.extend((escape(text[start:match.start()]), icon(by_source[match.group()].get('fallback_emoji'), by_source[match.group()].get('custom_emoji_id'))))
        start = match.end()
    result.append(escape(text[start:]))
    return ''.join(result)


def _without_leading_icon(label):
    stripped = re.sub(r'^[^\w]*\s*', '', label, flags=re.UNICODE)
    return stripped if stripped.strip() else label


def _buttons(locale, rows):
    slots = {row['key']: row for row in rows}
    def button(key, callback):
        label = translate(locale, key, custom=False)
        row = slots.get(key)
        if not row:
            return {'text': label, 'callback_data': callback, 'style': 'primary'}
        glyph = str(row.get('fallback_emoji') or '').strip()
        text = _without_leading_icon(label)
        fallback = (glyph + ' ' + text).strip()
        identifier = str(row.get('custom_emoji_id') or '').strip()
        result = {'text': fallback, 'callback_data': callback, 'style': 'primary'}
        if identifier.isascii() and identifier.isdigit():
            result.update(text=text, icon_custom_emoji_id=identifier,
                          _unicode_fallback=fallback)
        return result
    return {'inline_keyboard': [[button('menu.balance', 'balance'), button('menu.home', 'menu:home')],
                                 [button('menu.support', 'menu:support')]]}


def paid_eligible(row):
    return row.get('status') == 'paid' and row.get('settled_at') is not None


def owner_eligible(row):
    return (not row.get('compensated') and row.get('status') in _STATUS
            and (row['status'] != 'expired' or bool(str(row.get('payment_proof_file_id') or '').strip())))


def proof_eligible(row):
    return (row.get('status') == 'awaiting_confirmation' and row.get('provider') == 'manual'
            and bool(str(row.get('payment_proof_file_id') or '').strip())
            and row.get('payment_proof_submitted_at') is not None
            and row.get('payment_proof_forwarded_at') is None)

def customer_eligible(row):
    return (row.get('telegram_id') is not None
            and str(row.get('telegram_id')) == str(row.get('chat_id'))
            and re.fullmatch(r'[1-9][0-9]{0,18}', str(row.get('chat_id') or '')) is not None)


def channel_eligible(row):
    return (paid_eligible(row) and row.get('provider_total_payment') is not None
            and type(row.get('amount')) is int and row['amount'] > 0
            and str(row.get('currency') or '').strip().upper() == 'IDR'
            and str(row.get('provider_payment_method') or '').strip().upper() in ('QRIS', 'QR_MANUAL'))


def compensation_eligible(row):
    return (row.get('status') == 'compensated' and customer_eligible(row)
            and row.get('compensation_customer_id') == row.get('customer_id')
            and row.get('entry_customer_id') == row.get('customer_id')
            and row.get('compensation_amount') == row.get('amount') == row.get('entry_amount')
            and row.get('entry_kind') == 'owner_compensation_credit'
            and row.get('entry_source_type') == 'owner_compensation'
            and str(row.get('entry_source_id')) == str(row.get('compensation_id')))


def _redact_detail(text):
    text = re.sub(r'capi_[a-f0-9]{32}(?:\.|%2e)[a-f0-9]{64}', '[redacted-customer-api-key]', text, flags=re.I)
    text = re.sub(r'https?://[^\s<>"\']+/bot[^/\s<>"\']+(?:/[^\s<>"\']*)?', '[redacted-telegram-bot-api-url]', text, flags=re.I)
    text = re.sub(r'(?<![A-Za-z0-9])/bot[^/\s<>"\']+(?:/[^\s<>"\']*)?', '[redacted-telegram-bot-api-path]', text, flags=re.I)
    text = re.sub(r'(?<![A-Za-z0-9_-])(?:wa_confirm(?::|%3A)|wa_)[A-Za-z0-9_-]{43}(?![A-Za-z0-9_-])', '[redacted-secret]', text, flags=re.I)
    text = re.sub(r'\b\d{5,}:[A-Za-z0-9_-]{20,}\b', '[redacted-secret]', text)
    if sum(2 if unicodedata.east_asian_width(c) in ('W', 'F') else 1 for c in text) <= 240:
        return text
    result, width = [], 0
    for char in text:
        width += 2 if unicodedata.east_asian_width(char) in ('W', 'F') else 1
        if width > 237:
            break
        result.append(char)
    return ''.join(result) + '...'


def render_receipt(row, chat, locale, rows=(), *, compensation=False):
    prefix = 'balance.compensation.' if compensation else 'balance.topup.receipt.'
    def tr(key, **values):
        return translate(locale, prefix + key, rows, **{k: escape(str(v)) for k, v in values.items()})
    if compensation:
        lines = [tr('title'), tr('credit', amount=_idr(row['compensation_amount'])), tr('explanation'),
                 tr('reference', reference='#' + str(row['id'])),
                 tr('resolved_at', date=_date(row['resolved_at'] or row['compensation_created_at'], row['customer_locale'])), tr('support')]
    else:
        lines = [tr('title'), tr('credit', amount=_idr(row['amount'])),
                 tr('provider_total', amount=_idr(row['provider_total_payment'])),
                 tr('reference', reference=str(row['provider_reference'] or row['merchant_ref']).strip()),
                 tr('paid_at', date=_date(row['paid_at'] or row['settled_at'], row['customer_locale'])), tr('support')]
    return {'chat_id': str(chat), 'text': '\n'.join(lines), 'parse_mode': 'HTML', 'reply_markup': _buttons(locale, rows)}


def render_owner(row, chat, rows=(), *, paid=False):
    if paid:
        username = str(row.get('username') or '').strip()
        buyer = '@' + username.lstrip('@') if username else str(row.get('first_name') or '').strip() or 'Customer'
        if row.get('telegram_id'):
            buyer += ' (' + str(row['telegram_id']) + ')'
        lines = [_legacy('🔔 Hasil topup balance', rows), '━━━━━━━━━━━━━━',
                 'Invoice: ' + escape(row['merchant_ref']), 'Pembeli: ' + escape(buyer), 'Sumber: Telegram Bot',
                 'Status pembayaran: Paid', 'Saldo dikreditkan: ' + _rupiah(row['amount'])]
        if row.get('provider_total_payment') is not None:
            lines.append('Total pembayaran: ' + _rupiah(row['provider_total_payment']))
        lines.extend(('Metode: ' + escape(str(row.get('provider_payment_method') or row['provider']).upper()),
                      'Provider: ' + escape(row['provider'].upper()), '',
                      'Paid at: ' + _date(row['paid_at'] or row['settled_at'], 'en', zone=False, comma=True), '',
                      'Topup selesai. Saldo sudah dikreditkan. Tidak perlu proses ulang.'))
    else:
        name = str(row.get('first_name') or row.get('username') or row.get('telegram_id') or 'Customer').strip()
        username = str(row.get('username') or '').strip()
        buyer = name + (' (@' + username.lstrip('@') + ')' if username else '')
        if row.get('customer_id') is None:
            buyer = 'Customer tidak ditemukan'
        lines = [_legacy('⚠️ Top-up saldo butuh keputusan owner', rows), '━━━━━━━━━━━━━━',
                 _legacy('🧾 Top-up: ', rows) + '#' + str(row['id']),
                 _legacy('👤 Customer: ', rows) + escape(buyer),
                 _legacy('💰 Nominal: ', rows) + _idr_code(row['amount']),
                 _legacy('📌 Kondisi: ', rows) + escape(_STATUS.get(row['status'], row['status']))]
        detail = str(row.get('provider_create_last_error') or '').strip()
        if detail:
            lines.append(_legacy('🔎 Detail: ', rows) + escape(_redact_detail(detail)))
        lines.append('Buka dashboard admin pada tab Kompensasi Top-up: periksa provider sebelum memutuskan release saldo.')
    return {'chat_id': str(chat), 'text': '\n'.join(lines), 'parse_mode': 'HTML'}


def render_proof(row, chat, rows=()):
    def tr(key, **values):
        return translate('en', 'balance.topup.payment_proof.' + key, rows,
                         **{k: escape(str(v)) for k, v in values.items()})
    lines = [tr('title'), '━━━━━━━━━━━━━━', tr('credit', amount=_idr(row['amount']))]
    deadlines = [v for v in (row.get('expires_at'), row.get('provider_expires_at')) if v is not None]
    if row.get('provider_total_payment') is not None and deadlines:
        lines.extend((tr('total', amount=_idr(row['provider_total_payment'])),
                      tr('deadline', expires=_date(min(deadlines), row['customer_locale']))))
    lines.extend((tr('reference', reference='#' + str(row['id'])), tr('help')))
    return {'chat_id': str(chat), 'photo': row['payment_proof_file_id'], 'caption': '\n'.join(lines),
            'reply_markup': {'inline_keyboard': [[
                {'text': translate('en', 'balance.topup.payment_proof.approve', custom=False),
                 'callback_data': 'admin:topup-approve:' + str(row['id']), 'style': 'primary'},
                {'text': translate('en', 'balance.topup.payment_proof.reject', custom=False),
                 'callback_data': 'admin:topup-reject:' + str(row['id']), 'style': 'primary'}]]}}


def public_topup(row):
    clean = lambda value, limit: re.sub(r'[\x00-\x1f\x7f]+', ' ', str(value or '')).strip()[:limit]
    buyer_id = str(row.get('telegram_id') or '').strip()
    buyer_id = buyer_id[:3] + '*****' + buyer_id[-2:] if re.fullmatch(r'\d{6,}', buyer_id) else 'Buyer ID unavailable'
    method = str(row.get('provider_payment_method') or '').strip().upper()
    paid = row.get('paid_at') or row['settled_at']
    if isinstance(paid, str):
        paid = datetime.fromisoformat(paid)
    paid = paid.replace(tzinfo=ZoneInfo('Asia/Jakarta')) if paid.tzinfo is None else paid.astimezone(ZoneInfo('Asia/Jakarta'))
    return {'invoice_number': clean(row['merchant_ref'], 96) or 'TOPUP-' + str(row['id']),
            'buyer_name': mask(row.get('first_name'), row.get('username')), 'chat_id': buyer_id,
            'paid_at': paid, 'payment_method': 'QR Manual' if method == 'QR_MANUAL' else method or 'Unknown',
            'currency': 'IDR', 'items': [{'product_name': 'Store Balance Top-Up', 'quantity': 1,
                'quantity_price_tier_minimum': None, 'unit_price': row['amount'], 'subtotal': row['amount']}],
            'provider_fee': row.get('provider_fee') or 0, 'total_paid': row['provider_total_payment']}


class BalanceNotices:
    def __init__(self, service):
        self.service, self.db, self.recipients = service, service.db, service.recipients

    def _topup(self, source, *, lock=False):
        return self.db.one('SELECT ' + _TOPUP_COLUMNS + ''' FROM public.store_balance_top_ups t
            LEFT JOIN public.customers c ON c.id=t.customer_id WHERE t.id=%s''' + (' FOR UPDATE OF t' if lock else ''), (source,))

    def _compensation(self, source, *, lock=False):
        return self.db.one('SELECT ' + _TOPUP_COLUMNS + ''',x.id AS compensation_id,
            x.customer_id AS compensation_customer_id,x.amount AS compensation_amount,x.resolved_at,
            x.created_at AS compensation_created_at,e.amount AS entry_amount,e.kind AS entry_kind,
            e.source_type AS entry_source_type,e.source_id AS entry_source_id,a.customer_id AS entry_customer_id
            FROM public.store_balance_compensations x
            JOIN public.store_balance_top_ups t ON t.id=x.store_balance_top_up_id
            LEFT JOIN public.customers c ON c.id=t.customer_id
            JOIN public.store_balance_entries e ON e.id=x.ledger_entry_id
            JOIN public.customer_balance_accounts a ON a.id=e.customer_balance_account_id
            WHERE x.id=%s''' + (' FOR UPDATE OF x,t' if lock else ''), (source,))

    def _channel(self, *, admitted=True):
        row = self.db.one('''SELECT telegram_chat_id,channel_username,sales_proof_enabled,last_test_success
            FROM public.store_channel_settings ORDER BY id LIMIT 1''')
        if not row or not row['sales_proof_enabled'] or row['last_test_success'] is not True:
            return None
        chat = str(row['telegram_chat_id'] or row['channel_username'] or '').strip()
        return chat if chat and (not admitted or self.recipients.permits(chat)) else None

    def _render(self, kind, row, chat, locale):
        ui = icons(self.db)
        if kind in (RECEIPT, COMPENSATION):
            return render_receipt(row, chat, locale, ui, compensation=kind == COMPENSATION)
        if kind in (OWNER_ALERT, PAID):
            return render_owner(row, chat, ui, paid=kind == PAID)
        if kind == PAYMENT_PROOF:
            return render_proof(row, chat, ui)
        if kind == CHANNEL_PROOF:
            from .proof_publication import format_topup, shopping_markup
            source = public_topup(row)
            return {'chat_id': str(chat), 'photo': 'attach://invoice', 'caption': format_topup(source, ui),
                    'parse_mode': 'HTML', 'reply_markup': shopping_markup(ui, self._shopping_url),
                    '_balance_invoice': {**source, 'paid_at': source['paid_at'].isoformat()}}
        raise NotEligible()

    def _eligible(self, kind, row):
        if not row:
            return False
        if kind == INSTRUCTIONS:
            return instruction_eligible(row)
        if kind == COMPENSATION:
            return compensation_eligible(row)
        if kind == RECEIPT:
            return paid_eligible(row) and customer_eligible(row) and row.get('provider_total_payment') is not None
        if kind == PAID:
            return paid_eligible(row)
        if kind == OWNER_ALERT:
            return owner_eligible(row)
        if kind == PAYMENT_PROOF:
            return proof_eligible(row)
        if kind == CHANNEL_PROOF:
            return channel_eligible(row)
        return False

    def _record(self, kind, row, *, existing=None, admin=None, channel=None):
        source = row['compensation_id'] if kind == COMPENSATION else row['id']
        locale = _locale(row.get('locale') or row.get('customer_locale'))
        if kind in (RECEIPT, COMPENSATION):
            chat, target = str(row['chat_id']), 'customer'
        elif kind == CHANNEL_PROOF:
            chat, target, locale = channel, 'store_channel', 'en'
        else:
            chat, target, locale = (row.get('payment_proof_admin_chat_id') or admin) if kind == PAYMENT_PROOF else admin, 'admin', 'id'
        if existing:
            chat, target, locale = existing['telegram_chat_id'], existing['target_type'], existing.get('locale') or locale
        if not self._eligible(kind, row) or not chat or not self.recipients.permits(chat):
            return None
        if kind == CHANNEL_PROOF and not channel:
            return None
        keys = {RECEIPT: f"balance-top-up:{source}:{row['provider']}-receipt:v1",
                PAYMENT_PROOF: f'balance-top-up:{source}:admin-payment-proof:v1',
                OWNER_ALERT: f'balance-top-up-owner-alert:{source}:v1',
                PAID: f'balance-top-up:{source}:admin-paid:v1',
                COMPENSATION: f'balance-owner-compensation:{source}:notification:v1',
                CHANNEL_PROOF: f'balance-top-up:{source}:channel-proof:v1'}
        legacy = {'sent_at': row.get('payment_proof_forwarded_at')} if kind == PAYMENT_PROOF else None
        return record_snapshot(self.service, key=existing['effect_key'] if existing else keys[kind], kind=kind,
            model=MODELS[kind], source=source, chat=str(chat), target=target, locale=locale,
            template=existing['template_version'] if existing else 'balance-notification-v1',
            payload=self._render(kind, row, str(chat), locale), legacy=legacy)

    def _instruction_media(self, row):
        presentation = getattr(self.service, 'qr_presentation', None)
        if presentation is None:
            return None
        if row['provider'] == 'manual':
            return presentation.manual()
        funding = getattr(self.service, 'funding', None)
        if funding is None:
            return None
        source = funding.top_up_by_id(row['id'])
        view = funding.presentation(source)
        if not view.get('payable') or not view.get('qris_url'):
            return None
        rail = funding.rails.get(row['provider'])
        adapter = getattr(rail[0], 'adapter', None) if rail else None
        config = getattr(adapter, 'config', None)
        return presentation.provider_image(view['qris_url'], row['provider'],
            environment=getattr(config, 'environment', 'sandbox'),
            allowed_hosts=getattr(config, 'allowed_hosts', ()))

    def _materialize_instructions(self, limit):
        chats = list(self.recipients.telegram)
        sources = self.db.rows('''SELECT t.id FROM public.store_balance_top_ups t
            WHERE t.chat_id=ANY(%s) AND t.status IN ('payable','awaiting_confirmation')
              AND t.expires_at>%s AND t.provider_expires_at>%s
              AND NOT EXISTS(SELECT 1 FROM public.telegram_outbound_effects e
                WHERE e.effect_type=%s AND e.source_type='App\\Models\\StoreBalanceTopUp'
                  AND e.source_id=t.id::text AND (e.state NOT IN ('pending','failed')
                    OR COALESCE(e.outcome_metadata,'{}'::json)::jsonb ? 'notification_payload'))
            ORDER BY t.id LIMIT %s''', (chats, _now(), _now(), INSTRUCTIONS, limit))
        count = 0
        for source in sources:
            row = self._topup(source['id'])
            if not row or not instruction_eligible(row):
                continue
            try:
                photo = self._instruction_media(row)
            except (ValueError, OSError):
                continue
            if type(photo) is not bytes or not 0 < len(photo) <= 5 * 1024 * 1024:
                continue
            # Files/provider images are read outside locks. Recheck the complete
            # presentation source after acquiring its economic source lock.
            with transaction.atomic(using=self.db.using):
                locked = self._topup(source['id'], lock=True)
                fields = ('provider','chat_id','currency','amount','status','provider_fee',
                          'provider_total_payment','provider_expires_at','expires_at','provider_payment_method')
                if not locked or not instruction_eligible(locked) or any(locked[key] != row[key] for key in fields):
                    continue
                existing = self.db.one('''SELECT * FROM public.telegram_outbound_effects
                    WHERE effect_type=%s AND source_type='App\\Models\\StoreBalanceTopUp' AND source_id=%s
                    ORDER BY created_at,id LIMIT 1''', (INSTRUCTIONS, str(row['id'])))
                locale = _locale((existing or {}).get('locale') or row['locale'] or row['customer_locale'])
                chat = existing['telegram_chat_id'] if existing else row['chat_id']
                if str(chat) != str(row['chat_id']) or not self.recipients.permits(chat):
                    continue
                payload = render_instructions(locked, chat, locale, icons(self.db))
                payload['_balance_qris'] = base64.b64encode(photo).decode('ascii')
                record_snapshot(self.service,
                    key=existing['effect_key'] if existing else f"balance-top-up:{row['id']}:{row['provider']}-instructions:v1",
                    kind=INSTRUCTIONS, model=MODELS[INSTRUCTIONS], source=row['id'], chat=chat,
                    target='customer', locale=locale,
                    template=existing['template_version'] if existing else 'balance-top-up-instructions-v1',
                    payload=payload)
                count += 1
        return count

    def materialize(self, limit):
        if not self.recipients.telegram:
            return 0
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError('Invalid balance notification batch')
        selected = set(getattr(self.service.protocol, 'effect_types', MODELS)) & MODELS.keys()
        if not selected:
            return 0
        setting = self.db.one('''SELECT admin_telegram_chat_id FROM public.manual_payment_settings
            WHERE is_active=true ORDER BY id LIMIT 1''')
        admin = str(setting['admin_telegram_chat_id'] or '').strip() if setting else None
        channel = self._channel() if CHANNEL_PROOF in selected else None
        if channel:
            from ..auth.bot_identity import TelegramBotIdentity
            from .proof_publication import shopping_url
            username = getattr(self.service, 'bot_username', None)
            try:
                self._shopping_url = shopping_url(username if username is not None else TelegramBotIdentity().username())
            except NotEligible:
                channel = None
                selected -= {CHANNEL_PROOF}
        count = self._materialize_instructions(limit) if INSTRUCTIONS in selected else 0
        selected -= {INSTRUCTIONS}
        # Funding owners already record obligations atomically. Freeze those rows
        # before the shared protocol can claim them, without changing their target.
        effects = self.db.rows('''SELECT * FROM public.telegram_outbound_effects
            WHERE effect_type=ANY(%s) AND telegram_chat_id=ANY(%s)
              AND state IN ('pending','failed')
              AND NOT (COALESCE(outcome_metadata,'{}'::json)::jsonb ? 'notification_payload')
            ORDER BY created_at,id LIMIT %s''', (list(selected - {ADJUSTMENT}), list(self.recipients.telegram), limit))
        for effect in effects:
            with transaction.atomic(using=self.db.using):
                kind = effect['effect_type']
                row = self._compensation(effect['source_id'], lock=True) if kind == COMPENSATION else self._topup(effect['source_id'], lock=True)
                if row and self._record(kind, row, existing=effect, admin=admin, channel=channel):
                    count += 1
        for kind in selected - {ADJUSTMENT}:
            if kind not in (RECEIPT, COMPENSATION, CHANNEL_PROOF) and not self.recipients.permits(admin):
                # A retained proof target may differ from today's configured admin.
                if kind != PAYMENT_PROOF:
                    continue
            if kind == CHANNEL_PROOF and not channel:
                continue
            table = 'store_balance_compensations' if kind == COMPENSATION else 'store_balance_top_ups'
            filters = {COMPENSATION: 'EXISTS(SELECT 1 FROM public.store_balance_top_ups t WHERE t.id=s.store_balance_top_up_id AND t.chat_id=ANY(%s))', RECEIPT: "s.status='paid' AND s.settled_at IS NOT NULL AND s.provider_total_payment IS NOT NULL AND s.chat_id=ANY(%s)",
                       PAID: "s.status='paid' AND s.settled_at IS NOT NULL", OWNER_ALERT: "(s.status IN ('recovery_pending','invalid_provider_result') OR (s.status='expired' AND COALESCE(s.payment_proof_file_id,'')<>'')) AND NOT EXISTS(SELECT 1 FROM public.store_balance_compensations x WHERE x.store_balance_top_up_id=s.id)",
                       PAYMENT_PROOF: "s.provider='manual' AND s.status='awaiting_confirmation' AND s.payment_proof_submitted_at IS NOT NULL AND COALESCE(s.payment_proof_file_id,'')<>'' AND s.payment_proof_admin_chat_id=ANY(%s) AND s.payment_proof_forwarded_at IS NULL",
                       CHANNEL_PROOF: "s.status='paid' AND s.settled_at IS NOT NULL AND s.provider_total_payment IS NOT NULL AND s.currency='IDR' AND s.provider_payment_method IN ('QRIS','QR_MANUAL')"}
            params = [list(self.recipients.telegram)] if kind in (RECEIPT, PAYMENT_PROOF, COMPENSATION) else []
            sources = self.db.rows(f'''SELECT s.id FROM public.{table} s WHERE {filters[kind]}
                AND NOT EXISTS(SELECT 1 FROM public.telegram_outbound_effects e WHERE e.effect_type=%s
                    AND e.source_type=%s AND e.source_id=s.id::text)
                ORDER BY s.id LIMIT %s''', (*params, kind, 'App\\Models\\' + MODELS[kind], limit))
            for source in sources:
                with transaction.atomic(using=self.db.using):
                    row = self._compensation(source['id'], lock=True) if kind == COMPENSATION else self._topup(source['id'], lock=True)
                    if row and self._record(kind, row, admin=admin, channel=channel):
                        count += 1
        return count

    def payload(self, effect):
        kind = effect['effect_type']
        if (kind not in MODELS or effect['source_type'] != 'App\\Models\\' + MODELS[kind]
                or not self.recipients.permits(effect['telegram_chat_id'])):
            raise NotEligible()
        if kind == ADJUSTMENT:
            value = metadata(effect)
            message = value.get('message')
            if (value.get('delivery_context') != 'balance_adjustment' or not isinstance(message, str)
                    or not message or len(message) > 4096 or effect['telegram_method'] != 'sendMessage'
                    or effect['target_type'] not in ('admin', 'owner', 'customer')):
                raise NotEligible()
            return {'chat_id': effect['telegram_chat_id'], 'text': message, 'parse_mode': None}
        row = self._compensation(effect['source_id']) if kind == COMPENSATION else self._topup(effect['source_id'])
        if not self._eligible(kind, row):
            raise NotEligible()
        target = 'customer' if kind in (INSTRUCTIONS, RECEIPT, COMPENSATION) else 'store_channel' if kind == CHANNEL_PROOF else 'admin'
        if effect['target_type'] != target:
            raise NotEligible()
        if target == 'customer' and str(row['chat_id']) != str(effect['telegram_chat_id']):
            raise NotEligible()
        if kind == PAYMENT_PROOF and str(row['payment_proof_admin_chat_id']) != str(effect['telegram_chat_id']):
            raise NotEligible()
        result = frozen_payload(effect)
        if kind == INSTRUCTIONS:
            try:
                photo = base64.b64decode(result.pop('_balance_qris'), validate=True)
            except (KeyError, ValueError, TypeError):
                raise NotEligible() from None
            if not 0 < len(photo) <= 5 * 1024 * 1024:
                raise NotEligible()
            result['photo'] = photo
        if kind == CHANNEL_PROOF:
            if not self._channel(admitted=False):
                raise NotEligible()
            from ..receipt.invoice import render_invoice
            source = result.pop('_balance_invoice', None)
            if not isinstance(source, dict):
                raise NotEligible()
            source['paid_at'] = datetime.fromisoformat(source['paid_at'])
            result['photo'] = render_invoice(source)
        return result

    def project(self, effect):
        if (effect.get('effect_type') != PAYMENT_PROOF
                or effect.get('source_type') != 'App\\Models\\StoreBalanceTopUp'
                or not self.recipients.permits(effect.get('telegram_chat_id'))
                or not latest_sent(self.db, effect)):
            return 0
        return self.db.execute('''UPDATE public.store_balance_top_ups SET payment_proof_forwarded_at=%s,
            updated_at=%s WHERE id=%s AND payment_proof_admin_chat_id=%s
            AND payment_proof_forwarded_at IS DISTINCT FROM %s''',
            (effect['sent_at'], _now(), effect['source_id'], effect['telegram_chat_id'], effect['sent_at']))
