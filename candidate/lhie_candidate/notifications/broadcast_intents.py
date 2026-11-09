"""Private admin confirmation flow; only the confirmed source owes fan-out."""
from datetime import datetime, timezone
import hashlib
import json
import re
import secrets

from django.db import transaction

from ..checkout.db import Database
from ..navigation.repository import CatalogRepository
from ..receipt.protocol import _now
from .policy import NotEligible

HELP = ('📣 Broadcast Info\n━━━━━━━━━━━━━━\nKetik pesan dengan format:\n\n/broadcast isi pesan\n\n'
        'Setelah itu bot menampilkan preview. Kamu bisa menambahkan tombol produk, lalu konfirmasi sebelum pesan dikirim.')


class BroadcastIntents:
    def __init__(self, repository, transport, recipients, *, clock=None):
        self.repository, self.transport, self.recipients = repository, transport, recipients
        self.db = Database(repository.using)
        self.catalog = CatalogRepository(repository.using)
        self.clock = clock or (lambda: int(datetime.now(timezone.utc).timestamp()))

    def _admin(self, customer, chat):
        return self.recipients.permits(chat) and self.repository.customer_is_admin(customer['id'], customer['telegram_id'], chat)

    def _message(self, chat, text, keyboard=None):
        result = self.transport.execute('sendMessage', {'chat_id': str(chat), 'text': text,
            'reply_markup': {'inline_keyboard': keyboard or []}})
        return 'ambiguous' if result.outcome == 'ambiguous' else 'broadcast'

    def _static(self, text):
        slots = self.db.rows('SELECT source_emoji,fallback_emoji FROM public.telegram_ui_icons WHERE source_emoji IS NOT NULL')
        by_source = {slot['source_emoji']: slot['fallback_emoji'] or '' for slot in slots if slot['source_emoji']}
        if not by_source:
            return text
        return re.sub('|'.join(re.escape(source) for source in sorted(by_source, key=len, reverse=True)),
                      lambda match: by_source[match.group()], text)

    def _product(self, identity):
        product = self.catalog.product(identity)
        return product if product and product['available_stock'] > 0 else None

    def _current(self, customer):
        state = self.repository.session_state(customer['id'])
        draft = state.get('broadcast')
        if (not isinstance(draft, dict) or draft.get('expires', 0) <= self.clock()
                or not re.fullmatch(r'[A-Za-z0-9]{16}', str(draft.get('id', '')))):
            return state, None
        return state, draft

    def handle(self, customer, chat, source, *, callback=False):
        raw = source.get('data', '') if callback else source.get('caption', source.get('text', '')).strip()
        if not ((callback and raw.startswith('broadcast:')) or (not callback and
                (re.match(r'^/broadcast(?:\s|$)', raw) or raw == '📣 Broadcast Info'))):
            return None
        if not self._admin(customer, chat):
            return self._message(chat, 'Akses admin diperlukan.')
        if callback:
            match = re.fullmatch(r'broadcast:(preview|cancel|pick|select|send):([A-Za-z0-9]{16})(?::([1-9][0-9]{0,8}))?', raw)
            if not match:
                return self._message(chat, 'Draft sudah kedaluwarsa.')
            action, identity, argument = match.groups()
            if (action in ('pick', 'select')) != bool(argument):
                return self._message(chat, 'Draft sudah kedaluwarsa.')
            with transaction.atomic(using=self.db.using):
                self.db.one('SELECT customer_id FROM django_candidate.navigation_sessions WHERE customer_id=%s FOR UPDATE', (customer['id'],))
                if not self._admin(customer, chat):
                    raise NotEligible()
                state, draft = self._current(customer)
                if not draft or not secrets.compare_digest(draft['id'], identity):
                    return_value = 'Draft sudah kedaluwarsa.'
                elif action == 'cancel':
                    state.pop('broadcast', None)
                    self.repository.save_session_state(customer['id'], state)
                    return_value = '❌ Draft broadcast dibatalkan.'
                elif action == 'select':
                    product = self._product(int(argument))
                    if product:
                        draft['product_id'] = product['id']
                        self.repository.save_session_state(customer['id'], state)
                        return_value = None
                    else:
                        return_value = 'Produk tidak tersedia.'
                elif action == 'send':
                    product = self._product(draft['product_id']) if draft['product_id'] else None
                    if draft['product_id'] and not product:
                        return_value = 'Produk sudah tidak tersedia. Pilih produk lain.'
                    else:
                        markup = {}
                        if product:
                            label = (product.get('icon') or '🛍️') + ' ' + product['name'][:38]
                            markup = {locale: {'inline_keyboard': [[{'text': label + ' · ' + self._static('🛍️ ') + verb,
                                'style': 'primary', 'callback_data': 'product:' + str(product['id']),
                                **({'icon_custom_emoji_id': product['custom_emoji_id']} if product.get('custom_emoji_id') else {})}]]}
                                for locale, verb in (('id', 'Beli'), ('ms', 'Beli'), ('en', 'Buy'))}
                        digest = hashlib.sha256(('telegram-admin-broadcast:' + identity).encode()).hexdigest()
                        key = '-'.join((digest[:8], digest[8:12], digest[12:16], digest[16:20], digest[20:32]))
                        at = _now()
                        self.db.execute('''INSERT INTO public.telegram_broadcasts
                            (broadcast_key,source,message,photo_file_id,reply_markup,status,created_at,updated_at)
                            VALUES (%s,'admin',%s,%s,%s,'queued',%s,%s) ON CONFLICT (broadcast_key) DO NOTHING''',
                            (key, draft['message'], draft['photo_file_id'], json.dumps(markup) if markup else None, at, at))
                        state.pop('broadcast', None)
                        self.repository.save_session_state(customer['id'], state)
                        return_value = 'Broadcast masuk antrean pengiriman.'
                else:
                    return_value = None
            if return_value:
                return self._message(chat, return_value)
            if action == 'pick':
                return self.picker(chat, draft, int(argument))
            return self.preview(chat, draft)
        if raw == '📣 Broadcast Info':
            return self._message(chat, self._static(HELP))
        text = re.sub(r'^/broadcast(?:\s|$)', '', raw, count=1).strip()
        photo = source.get('photo') or source.get('reply_to_message', {}).get('photo') or []
        if source.get('media_group_id') or (source.get('media_kind') and source['media_kind'] != 'photo'):
            return self._message(chat, 'Broadcast hanya mendukung teks atau satu foto.')
        file_id = photo[-1]['file_id'] if photo else None
        if not text and not file_id:
            return self._message(chat, self._static(HELP))
        if len(text) > (1024 if file_id else 3500):
            return self._message(chat, 'Caption foto broadcast maksimal 1024 karakter.' if file_id else 'Pesan broadcast maksimal 3500 karakter.')
        state = self.repository.session_state(customer['id'])
        draft = {'id': ''.join(secrets.choice('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') for _ in range(16)),
                 'expires': self.clock() + 900, 'message': text, 'photo_file_id': file_id, 'product_id': None}
        state['broadcast'] = draft
        self.repository.save_session_state(customer['id'], state)
        return self.preview(chat, draft)

    def preview(self, chat, draft):
        product = self._product(draft['product_id']) if draft['product_id'] else None
        product_line = 'Tombol produk: ' + ((product.get('icon') or '🛍️') + ' ' + product['name'] if product else 'tidak ada')
        if draft['photo_file_id']:
            result = self.transport.execute('sendPhoto', {'chat_id': str(chat), 'photo': draft['photo_file_id'], 'caption': draft['message']})
            if result.outcome != 'success':
                return 'ambiguous'
            text = self._static('📣 Preview broadcast') + '\n━━━━━━━━━━━━━━\n' + product_line + '\n\nFoto di atas belum dikirim. Pilih tindakan di bawah.'
        else:
            text = self._static('📣 Preview broadcast') + '\n━━━━━━━━━━━━━━\nPesan:\n' + draft['message'] + '\n\n' + product_line + '\n\nPesan belum dikirim. Pilih tindakan di bawah.'
        identity = draft['id']
        return self._message(chat, text, [[{'text': self._static('🔁 Ganti produk' if product else '➕ Tambahkan produk'), 'style': 'primary', 'callback_data': f'broadcast:pick:{identity}:1'}],
            [{'text': self._static('✅ Kirim'), 'style': 'primary', 'callback_data': f'broadcast:send:{identity}'},
             {'text': self._static('❌ Batal'), 'style': 'primary', 'callback_data': f'broadcast:cancel:{identity}'}]])

    def picker(self, chat, draft, page):
        page = min(10000, max(1, page))
        products = self.catalog._enrich_products(self.catalog._product_rows(
            ' ORDER BY p.name,p.id LIMIT 9 OFFSET %s', ((page - 1) * 8,)))
        rows = [[{'text': (p.get('icon') or '🛍️') + ' ' + p['name'][:38] + ' · 🛍️ Rp' + f"{p['price_amount']:,}".replace(',', '.'),
                  'style': 'primary', 'callback_data': f"broadcast:select:{draft['id']}:{p['id']}"}]
                for p in products[:8] if p['available_stock'] > 0]
        nav = []
        if page > 1:
            nav.append({'text': '⬅️ Sebelumnya', 'style': 'primary', 'callback_data': f"broadcast:pick:{draft['id']}:{page-1}"})
        if len(products) == 9:
            nav.append({'text': 'Berikutnya ➡️', 'style': 'primary', 'callback_data': f"broadcast:pick:{draft['id']}:{page+1}"})
        if nav:
            rows.append(nav)
        rows.append([{'text': '⬅️ Kembali ke preview', 'style': 'primary', 'callback_data': f"broadcast:preview:{draft['id']}"}])
        return self._message(chat, 'Pilih produk untuk tombol pembelian broadcast.', rows)
