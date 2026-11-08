"""Admin-only bot capture flows kept outside the customer catalog presenter.

The Store Bot lets a Telegram administrator edit Product copy and catalog/menu/
message icons from one private chat. Every write is a bounded, revision-checked
transaction; pending prompts live in the existing navigation session state and
expire after five minutes. No credential value is ever read or stored here.
"""
from datetime import datetime, timezone
import json
import re

from django.db import transaction

from ..checkout.db import Database
from .types import Message


FIELDS = {'description': 'Product Details',
          'warranty_policy': 'Replacement / Refund Policy'}
LOCALES = {'id': 'Bahasa Indonesia', 'ms': 'Bahasa Melayu', 'en': 'English'}
MAX_DETAIL_UNITS = 3000
CAPTURE_TTL_SECONDS = 300
CONTENT_TTL_SECONDS = 600
_PLAIN_EMOJI = re.compile(
    r'[\U0001F000-\U0001FAFF\U0001F1E6-\U0001F1FF\u2190-\u21FF'
    r'\u2300-\u27BF\u2900-\u2BFF\uFE0F\u200D\u20E3]')

# Admin edit copy is intentionally the same plain Indonesian copy the PHP
# editor used; it is not customer-facing presentation.
CONTENT_MENU = ('Admin — pilih produk untuk mengedit Product Details atau '
                'Replacement / Refund Policy. Setiap bagian punya tiga varian '
                'bahasa (Indonesia, Melayu, English); varian kosong memakai teks '
                'bahasa default.')
CONTENT_FIELD_PROMPT = ('{field} — {name}\nPilih bahasa yang ingin diedit. Varian '
                        'kosong tampil memakai teks bahasa default produk.')
CONTENT_INSTRUCTIONS = ('{label} — {name}\nSalin teks asli berikut ke kolom pesan, '
                        'edit, lalu BALAS pesan instruksi terakhir. Emoji premium harus '
                        'dipilih dari keyboard emoji Telegram. /cancel membatalkan; '
                        '/clear mengosongkan varian ini sehingga tampil memakai teks '
                        'bahasa default. Berlaku 10 menit.')
CONTENT_REPLY_PROMPT = 'Balas pesan ini dengan teks pengganti lengkap untuk {label}.'
CONTENT_UNAVAILABLE = 'Produk tidak tersedia. Buka /admin lagi.'
CONTENT_CHANGED = ('Konten sudah berubah. Buka /admin lagi agar tidak menimpa '
                   'perubahan terbaru.')
CONTENT_TOO_LONG = ('Total Product Details + Refund Policy {locale} maksimal '
                    + str(MAX_DETAIL_UNITS) + ' unit UTF-16 (emoji dapat dihitung dua '
                    'unit) agar muat di Telegram.')
CONTENT_SAVED = ('Tersimpan. Konten langsung digunakan pada Product Detail berikutnya. '
                 'Emoji bergerak memerlukan izin custom emoji bot dari Telegram dan '
                 'animasi aktif pada aplikasi penerima.')
CONTENT_CANCELLED = 'Edit dibatalkan.'
CAPTURE_EXPIRED = 'Sesi pengambilan emoji sudah berakhir. Pilih slot ikon lagi dari menu.'
CAPTURE_CANCELLED = 'Dibatalkan.'


def _now():
    return datetime.now(timezone.utc)


def _db_now():
    return datetime.now(timezone.utc).astimezone()


def utc16_units(text):
    return len(str(text).encode('utf-16-le')) // 2


def column(field, locale):
    if field not in FIELDS or locale not in LOCALES:
        raise ValueError('invalid content field')
    return field if locale == 'id' else f'{field}_{locale}'


def _custom_entities(text, entities):
    """Validate and return only non-overlapping custom-emoji entities."""
    encoded = str(text).encode('utf-16-le')
    result = []
    for entity in entities or ():
        if not isinstance(entity, dict) or entity.get('type') != 'custom_emoji':
            continue
        offset, length = entity.get('offset'), entity.get('length')
        identity = entity.get('custom_emoji_id')
        if (type(offset) is not int or type(length) is not int or offset < 0 or length < 1
                or (offset + length) * 2 > len(encoded)
                or not isinstance(identity, str) or not re.fullmatch(r'[0-9]{1,64}', identity)):
            raise ValueError('invalid custom emoji entity')
        glyph = encoded[offset * 2:(offset + length) * 2].decode('utf-16-le')
        result.append({'type': 'custom_emoji', 'offset': offset, 'length': length,
                       'custom_emoji_id': identity, 'glyph': glyph})
    result.sort(key=lambda item: item['offset'])
    end = 0
    for entity in result:
        if entity['offset'] < end:
            raise ValueError('overlapping custom emoji entity')
        end = entity['offset'] + entity['length']
    return result


def _metadata_for(rows):
    content = rows.get('telegram_content')
    if isinstance(content, str):
        try:
            content = json.loads(content)
        except ValueError:
            content = None
    return content if isinstance(content, dict) else {}


def _stored_entities(content, field, text):
    entry = content.get(field)
    return (entry.get('entities') or [] if isinstance(entry, dict)
            and entry.get('text') == text else [])


class AdminCapture:
    def __init__(self, presenter, repository, using='default'):
        self.presenter = presenter
        self.repository = repository
        self.db = Database(using)

    # -- sessions -----------------------------------------------------

    def is_admin(self, customer, chat_id):
        return self.repository.customer_is_admin(
            customer['id'], customer['telegram_id'], chat_id)

    def state(self, customer_id):
        return self.repository.session_state(customer_id)

    def save_state(self, customer_id, state):
        self.repository.save_session_state(customer_id, state)

    def clear_pending(self, customer_id):
        state = self.repository.session_state(customer_id)
        state.pop('admin_capture', None)
        state.pop('content_edit', None)
        self.repository.save_session_state(customer_id, state)

    # -- content editor ----------------------------------------------

    def content_menu(self, customer, page=0):
        page = max(0, min(int(page), 100000))
        rows = self.db.rows(
            "SELECT id,name FROM public.products WHERE deleted_at IS NULL "
            "ORDER BY name,id OFFSET %s LIMIT 11", (page * 10,))
        buttons = [[self.presenter.raw_button(row['name'], 'content:product:%s' % row['id'])]
                   for row in rows[:10]]
        navigation = []
        if page > 0:
            navigation.append(self.presenter.raw_button('‹ Sebelumnya', 'content:page:%s' % (page - 1)))
        if len(rows) > 10:
            navigation.append(self.presenter.raw_button('Berikutnya ›', 'content:page:%s' % (page + 1)))
        if navigation:
            buttons.append(navigation)
        return self.presenter.screen(customer, CONTENT_MENU, buttons, screen='admin')

    def content_product(self, customer, product_id):
        product = self.db.one(
            "SELECT id,name FROM public.products WHERE id=%s AND deleted_at IS NULL",
            (product_id,))
        if product is None:
            return self.presenter.screen(customer, CONTENT_UNAVAILABLE, [],
                                         screen='admin')
        rows = [[self.presenter.raw_button(label, f'content:edit:{product["id"]}:{field}')]
                for field, label in FIELDS.items()]
        rows.append([self.presenter.raw_button('Kembali', 'content:page:0')])
        return self.presenter.screen(customer, product['name'] + ' — pilih bagian:',
                                     rows, screen='admin')

    def content_locales(self, customer, product_id, field):
        product = self._content_row(product_id)
        if product is None or field not in FIELDS:
            return self.presenter.screen(customer, CONTENT_UNAVAILABLE, [],
                                         screen='admin')
        rows = []
        for locale, label in LOCALES.items():
            value = str(product.get(column(field, locale)) or '')
            rows.append([self.presenter.raw_button(
                label + ' — ' + ('terisi' if value.strip() else 'kosong'),
                f'content:locale:{product_id}:{field}:{locale}')])
        rows.append([self.presenter.raw_button('Kembali', f'content:product:{product_id}')])
        return self.presenter.screen(
            customer, CONTENT_FIELD_PROMPT.format(field=FIELDS[field], name=product['name']),
            rows, screen='admin')

    def start_content(self, customer, product_id, field, locale, chat_id):
        product = self._content_row(product_id)
        if (product is None or field not in FIELDS or locale not in LOCALES):
            return [Message(CONTENT_UNAVAILABLE)], None
        column_name = column(field, locale)
        text = str(product.get(column_name) or '')
        metadata = _metadata_for(product)
        entities = _stored_entities(metadata, column_name, text)
        label = f'{FIELDS[field]} ({LOCALES[locale]})'
        header = CONTENT_INSTRUCTIONS.format(label=label, name=product['name'])
        messages = [Message(header)]
        for index in (range(0, len(text), 3906) if text else ()):
            chunk = text[index:index + 3906]
            chunk_entities = tuple(entities) if (index == 0 and len(text) <= 4096
                                                 and entities) else ()
            messages.append(Message(chunk, chunk_entities))
        messages.append(Message(
            CONTENT_REPLY_PROMPT.format(label=label),
            keyboard={'force_reply': True, 'selective': True}))
        state = {'product_id': product_id, 'field': field, 'column': column_name,
                 'locale': locale, 'text': text, 'metadata': metadata.get(column_name),
                 'chat_id': str(chat_id), 'prompt_id': None,
                 'expires_at': int(_now().timestamp()) + CONTENT_TTL_SECONDS}
        return messages, {'content_edit': state}

    def _content_row(self, product_id):
        return self.db.one(
            """SELECT id,name,description,description_ms,description_en,
                      warranty_policy,warranty_policy_ms,warranty_policy_en,telegram_content,
                      product_family_id
               FROM public.products WHERE id=%s AND deleted_at IS NULL""",
            (product_id,))

    def capture_content(self, customer, message, pending):
        """Consume a reply to the edit prompt; return (messages, next_state)."""
        if str(message.get('chat', {}).get('id')) != pending.get('chat_id'):
            return [Message('Belum disimpan. Balas pesan instruksi edit, atau /cancel.')], None
        reply = message.get('reply_to_message') or {}
        if reply.get('message_id') != pending.get('prompt_id'):
            return [Message('Belum disimpan. Balas pesan instruksi edit, atau /cancel.')], None
        text = message.get('text')
        if text is None:
            return [Message('Kirim pesan teks, bukan foto/stiker.')], None
        if text.strip().lower() in ('/cancel', '/batal'):
            return [Message(CONTENT_CANCELLED)], {}
        if text.startswith('/') and text.strip().lower() != '/clear':
            return [], {}
        text = '' if text.strip().lower() == '/clear' else text
        if text != '' and not text.strip():
            return [Message('Kirim pesan teks, bukan foto/stiker.')], None
        try:
            entities = _custom_entities(text, message.get('entities', []))
        except ValueError:
            return [Message('Entity emoji tidak valid. Kirim ulang teks dari Telegram.')], None
        cleaned = [{key: value for key, value in entity.items() if key != 'glyph'}
                   for entity in entities]
        product = self._content_row(pending['product_id'])
        if product is None:
            return [Message(CONTENT_UNAVAILABLE)], {}
        other_field = 'warranty_policy' if pending['field'] == 'description' else 'description'
        other = str(product.get(column(other_field, pending['locale'])) or '')
        if utc16_units(text) + utc16_units(other) > MAX_DETAIL_UNITS:
            return [Message(CONTENT_TOO_LONG.format(locale=LOCALES[pending['locale']]))], None
        try:
            with transaction.atomic():
                locked = self.db.one(
                    "SELECT id,telegram_content FROM public.products WHERE id=%s FOR UPDATE",
                    (pending['product_id'],))
                if locked is None:
                    return [Message(CONTENT_UNAVAILABLE)], {}
                current = self._content_row(pending['product_id'])
                if (str(current.get(pending['column']) or '') != pending.get('text')
                        or _metadata_for(current).get(pending['column']) != pending.get('metadata')):
                    return [Message(CONTENT_CHANGED)], {}
                metadata = _metadata_for(current)
                if text == '':
                    metadata.pop(pending['column'], None)
                else:
                    metadata[pending['column']] = {'text': text, 'entities': cleaned}
                self.db.execute(
                    "UPDATE public.products SET " + pending['column'] + "=%s,"
                    "telegram_content=%s::jsonb,updated_at=%s WHERE id=%s",
                    (text, json.dumps(metadata, ensure_ascii=False) if metadata else None,
                     _db_now(), pending['product_id']))
                self._audit(customer, 'product.telegram_content_updated', 'App\\Models\\Product',
                            pending['product_id'], {pending['column']: pending.get('text')},
                            {pending['column']: text, 'telegram_actor_id': str(customer['telegram_id'])})
        except ValueError:
            return [Message(CONTENT_CHANGED)], {}
        except Exception:
            return [Message(CONTENT_CHANGED)], None
        messages = [Message(CONTENT_SAVED)]
        if text:
            messages.append(Message(text, tuple(cleaned)))
        return messages, {}

    # -- icon capture -------------------------------------------------

    def icon_products(self, customer):
        families = self.db.rows(
            "SELECT id,name,icon,custom_emoji_id FROM public.product_families "
            "WHERE deleted_at IS NULL ORDER BY sort_order,name,id")
        standalone = self.db.rows(
            "SELECT id,name,icon,custom_emoji_id FROM public.products "
            "WHERE deleted_at IS NULL AND product_family_id IS NULL ORDER BY name,id")
        rows = []
        for family in families:
            rows.append([self._icon_button(customer, family['name'],
                                           family.get('icon'), family.get('custom_emoji_id'),
                                           bool((family.get('custom_emoji_id') or '').strip()),
                                           'familyicon:pick:%s' % family['id'])])
        for product in standalone:
            rows.append([self._icon_button(customer, product['name'],
                                           product.get('icon'), product.get('custom_emoji_id'),
                                           bool((product.get('custom_emoji_id') or '').strip()),
                                           'producticon:pick:%s' % product['id'])])
        rows.append([self.presenter.raw_button(
            self.presenter.t(customer, 'catalog.ui_icon.menu_label'), 'menuicon:list')])
        rows.append([self.presenter.raw_button(
            self.presenter.t(customer, 'catalog.message_icon.list_body').split('\n')[0],
            'messageicon:list')])
        rows.append([self.presenter.legacy_button(customer, 'menu.home', 'menu:home')])
        count = len(families) + len(standalone)
        body = (self.presenter.t(customer, 'catalog.product_icon.empty') if count == 0
                else self.presenter.t(customer, 'catalog.product_icon.list_body', count=count))
        return self.presenter.screen(customer, body, rows, screen='admin')

    def icon_family(self, customer, family_id):
        family = self.db.one(
            "SELECT id,name,icon,custom_emoji_id FROM public.product_families "
            "WHERE id=%s AND deleted_at IS NULL", (family_id,))
        if family is None:
            return self.presenter.screen(customer, self.presenter.t(
                customer, 'catalog.product_icon.expired'), [], screen='admin')
        products = self.db.rows(
            "SELECT id,name,icon,custom_emoji_id FROM public.products "
            "WHERE product_family_id=%s AND deleted_at IS NULL ORDER BY family_sort_order,name,id",
            (family_id,))
        rows = []
        for product in products:
            glyph = product.get('icon') or family.get('icon')
            identity = product.get('custom_emoji_id') or family.get('custom_emoji_id')
            own = bool((product.get('icon') or '').strip()
                       or (product.get('custom_emoji_id') or '').strip())
            rows.append([self._icon_button(customer, product['name'], glyph, identity,
                                           own, 'producticon:pick:%s' % product['id'])])
        rows.append([self.presenter.raw_button(
            self.presenter.t(customer, 'catalog.product_icon.button_family'),
            'familyicon:pick:%s' % family_id)])
        rows.append([self.presenter.raw_button(
            self.presenter.t(customer, 'catalog.product_icon.button_back'),
            'producticon:list')])
        return self.presenter.screen(
            customer, self.presenter.t(customer, 'catalog.product_icon.variants_body',
                                       label=family['name'], count=len(products)),
            rows, screen='admin')

    def icon_menu(self, customer):
        return self._icon_list(customer, 'menu.%', 'catalog.ui_icon.list_body',
                               'menuicon:pick:%s', 'catalog.ui_icon.product_label',
                               'producticon:list', 'catalog.ui_icon.message_label',
                               'messageicon:list', 'catalog.ui_icon')

    def icon_message(self, customer):
        return self._icon_list(customer, 'message.%', 'catalog.message_icon.list_body',
                               'messageicon:pick:%s', 'catalog.ui_icon.menu_label',
                               'menuicon:list', 'catalog.ui_icon.product_label',
                               'producticon:list', 'catalog.message_icon')

    def _icon_list(self, customer, prefix, body_key, callback, other_key, other_callback,
                   second_key, second_callback, back_group):
        icons = self.db.rows(
            "SELECT id,key,label,fallback_emoji,custom_emoji_id FROM public.telegram_ui_icons "
            "WHERE key LIKE %s ORDER BY sort_order,label,id", (prefix,))
        rows = [[self._icon_button(customer, icon['label'], icon.get('fallback_emoji'),
                                   icon.get('custom_emoji_id'),
                                   bool((icon.get('custom_emoji_id') or '').strip()),
                                   callback % icon['id'])] for icon in icons]
        rows.append([self.presenter.raw_button(
            self.presenter.t(customer, other_key), other_callback)])
        rows.append([self.presenter.raw_button(
            self.presenter.t(customer, second_key), second_callback)])
        rows.append([self.presenter.legacy_button(customer, 'menu.home', 'menu:home')])
        return self.presenter.screen(customer, self.presenter.t(customer, body_key),
                                     rows, screen='admin')

    def _icon_button(self, customer, label, glyph, identity, captured, callback):
        glyph = (glyph or '').strip()
        identity = (identity or '').strip()
        preview = identity if identity else glyph
        text = self.presenter.t(
            customer, 'catalog.product_icon.status_ready' if captured
            else 'catalog.product_icon.status_empty',
            label=(label if identity else (preview + ' ' + label).strip()))
        button = {'text': text, 'callback_data': callback, 'style': 'primary'}
        if identity and self.presenter.custom_emoji:
            button['icon_custom_emoji_id'] = identity
            button['_unicode_fallback'] = (preview + ' ' + label).strip()
        return button

    def start_icon(self, customer, kind, target_id, message_id):
        target = self._icon_target(kind, target_id)
        if target is None:
            return None
        label = target['label']
        if kind in ('menu', 'message'):
            prompt_key = ('catalog.ui_icon.prompt' if kind == 'menu'
                          else 'catalog.message_icon.prompt')
        else:
            prompt_key = 'catalog.product_icon.prompt'
        back = target['back_callback']
        back_key = target['back_key']
        rows = [[self.presenter.raw_button(self.presenter.t(customer, back_key), back)]]
        surface = self.presenter.screen(
            customer, self.presenter.t(customer, prompt_key, label=label), rows,
            screen='admin')
        state = {'kind': kind, 'target_id': target_id, 'message_id': message_id,
                 'old_icon': target.get('icon'), 'old_custom': target.get('custom'),
                 'old_fallback': target.get('fallback'), 'family': target.get('family'),
                 'label': label, 'expires_at': int(_now().timestamp()) + CAPTURE_TTL_SECONDS}
        return surface, {'admin_capture': state}

    def _icon_target(self, kind, target_id):
        if kind == 'product':
            row = self.db.one(
                "SELECT id,name,icon,custom_emoji_id,product_family_id FROM public.products "
                "WHERE id=%s AND deleted_at IS NULL", (target_id,))
            if row is None:
                return None
            back = ('producticon:list' if row['product_family_id'] is None
                    else 'producticon:family:%s' % row['product_family_id'])
            return {'label': row['name'], 'icon': row.get('icon'),
                    'custom': row.get('custom_emoji_id'), 'back_callback': back,
                    'back_key': 'catalog.product_icon.button_back',
                    'family': row['product_family_id']}
        if kind == 'family':
            row = self.db.one(
                "SELECT id,name,icon,custom_emoji_id FROM public.product_families "
                "WHERE id=%s AND deleted_at IS NULL", (target_id,))
            if row is None:
                return None
            return {'label': row['name'], 'icon': row.get('icon'),
                    'custom': row.get('custom_emoji_id'),
                    'back_callback': 'producticon:list',
                    'back_key': 'catalog.product_icon.button_back'}
        if kind in ('menu', 'message'):
            prefix = 'menu.%' if kind == 'menu' else 'message.%'
            row = self.db.one(
                "SELECT id,label,fallback_emoji,custom_emoji_id FROM public.telegram_ui_icons "
                "WHERE id=%s AND key LIKE %s", (target_id, prefix))
            if row is None:
                return None
            return {'label': row['label'], 'fallback': row.get('fallback_emoji'),
                    'custom': row.get('custom_emoji_id'),
                    'back_callback': ('menuicon:list' if kind == 'menu'
                                      else 'messageicon:list'),
                    'back_key': ('catalog.ui_icon.button_back' if kind == 'menu'
                                 else 'catalog.message_icon.button_back')}
        return None

    def capture_icon(self, customer, message, pending):
        if int(pending.get('expires_at') or 0) < int(_now().timestamp()):
            return self.presenter.screen(customer, self.presenter.t(
                customer, 'catalog.product_icon.expired'), [], screen='admin'), {}
        text = str(message.get('text') or message.get('caption') or '').strip()
        if text.lower() in ('/batal', '/cancel'):
            return self.presenter.screen(customer, self.presenter.t(
                customer, 'catalog.product_icon.cancelled'), [], screen='admin'), {}
        try:
            entities = _custom_entities(text, message.get('entities', []))
        except ValueError:
            return self.presenter.screen(customer, self.presenter.t(
                customer, 'catalog.ui_icon.not_an_emoji'), [], screen='admin'), None
        if len(entities) > 1:
            return self.presenter.screen(customer, self.presenter.t(
                customer, 'catalog.product_icon.too_many'), [], screen='admin'), None
        if entities:
            identity = entities[0]['custom_emoji_id']
            glyph = entities[0]['glyph']
        else:
            glyph = self._plain_emoji(text)
            identity = None
        if not glyph:
            key = ('catalog.ui_icon.not_an_emoji' if pending['kind'] in ('menu', 'message')
                   else 'catalog.product_icon.not_an_emoji')
            return self.presenter.screen(customer, self.presenter.t(customer, key), [],
                                         screen='admin'), None
        try:
            with transaction.atomic():
                changed = self._save_icon(pending, identity, glyph)
                if not changed:
                    return self.presenter.screen(customer, self.presenter.t(
                        customer, 'catalog.product_icon.expired'), [], screen='admin'), {}
                self._audit(customer, 'telegram_icon.updated', self._subject(pending),
                            pending['target_id'],
                            {'custom_emoji_id': pending.get('old_custom'),
                             'icon': pending.get('old_icon'),
                             'fallback_emoji': pending.get('old_fallback')},
                            {'custom_emoji_id': identity, 'icon': glyph,
                             'telegram_actor_id': str(customer['telegram_id'])})
        except Exception:
            return self.presenter.screen(customer, self.presenter.t(
                customer, 'catalog.product_icon.expired'), [], screen='admin'), {}
        kind = pending['kind']
        if identity:
            saved = ('catalog.ui_icon.saved' if kind == 'menu'
                     else 'catalog.message_icon.saved' if kind == 'message'
                     else 'catalog.product_icon.saved')
        else:
            saved = ('catalog.ui_icon.saved_glyph' if kind == 'menu'
                     else 'catalog.message_icon.saved_glyph' if kind == 'message'
                     else 'catalog.product_icon.saved_glyph')
        rows = []
        if identity:
            clear_label = ('catalog.product_icon.button_restore_family'
                           if kind == 'product' and pending.get('family') is not None
                           else 'catalog.product_icon.button_clear')
            rows.append([self.presenter.legacy_button(
                customer, clear_label,
                '%s:clear:%s' % (self._prefix(kind), pending['target_id']))])
        back = self._back_callback(kind, pending)
        rows.append([self.presenter.raw_button(
            self.presenter.t(customer, 'catalog.product_icon.button_back'), back)])
        return self.presenter.screen(
            customer, self.presenter.t(customer, saved, label=pending['label']),
            rows, screen='admin'), {}

    def _save_icon(self, pending, identity, glyph):
        kind = pending['kind']
        if kind == 'product':
            if identity:
                return self.db.execute(
                    "UPDATE public.products SET icon=%s,custom_emoji_id=%s,updated_at=%s "
                    "WHERE id=%s AND icon IS NOT DISTINCT FROM %s "
                    "AND custom_emoji_id IS NOT DISTINCT FROM %s",
                    (glyph, identity, _db_now(), pending['target_id'],
                     pending.get('old_icon'), pending.get('old_custom'))) == 1
            return self.db.execute(
                "UPDATE public.products SET icon=%s,updated_at=%s WHERE id=%s "
                "AND icon IS NOT DISTINCT FROM %s AND custom_emoji_id IS NOT DISTINCT FROM %s",
                (glyph, _db_now(), pending['target_id'], pending.get('old_icon'),
                 pending.get('old_custom'))) == 1
        if kind == 'family':
            if identity:
                return self.db.execute(
                    "UPDATE public.product_families SET icon=%s,custom_emoji_id=%s,updated_at=%s "
                    "WHERE id=%s AND icon IS NOT DISTINCT FROM %s "
                    "AND custom_emoji_id IS NOT DISTINCT FROM %s",
                    (glyph, identity, _db_now(), pending['target_id'],
                     pending.get('old_icon'), pending.get('old_custom'))) == 1
            return self.db.execute(
                "UPDATE public.product_families SET icon=%s,updated_at=%s WHERE id=%s "
                "AND icon IS NOT DISTINCT FROM %s AND custom_emoji_id IS NOT DISTINCT FROM %s",
                (glyph, _db_now(), pending['target_id'], pending.get('old_icon'),
                 pending.get('old_custom'))) == 1
        if kind in ('menu', 'message'):
            prefix = 'menu.%' if kind == 'menu' else 'message.%'
            if identity:
                return self.db.execute(
                    "UPDATE public.telegram_ui_icons SET fallback_emoji=%s,custom_emoji_id=%s,"
                    "updated_at=%s WHERE id=%s AND key LIKE %s AND custom_emoji_id IS NOT DISTINCT FROM %s",
                    (glyph, identity, _db_now(), pending['target_id'], prefix,
                     pending.get('old_custom'))) == 1
            return self.db.execute(
                "UPDATE public.telegram_ui_icons SET fallback_emoji=%s,custom_emoji_id=NULL,"
                "updated_at=%s WHERE id=%s AND key LIKE %s AND custom_emoji_id IS NOT DISTINCT FROM %s",
                (glyph, _db_now(), pending['target_id'], prefix,
                 pending.get('old_custom'))) == 1
        return False

    def clear_icon(self, customer, kind, target_id):
        target = self._icon_target(kind, target_id)
        if target is None:
            return self.presenter.screen(customer, self.presenter.t(
                customer, 'catalog.product_icon.expired'), [], screen='admin')
        try:
            with transaction.atomic():
                if kind == 'product':
                    if target.get('family') is None:
                        self.db.execute(
                            "UPDATE public.products SET custom_emoji_id=NULL,updated_at=%s "
                            "WHERE id=%s", (_db_now(), target_id))
                    else:
                        self.db.execute(
                            "UPDATE public.products SET icon=NULL,custom_emoji_id=NULL,"
                            "updated_at=%s WHERE id=%s", (_db_now(), target_id))
                elif kind == 'family':
                    self.db.execute(
                        "UPDATE public.products SET custom_emoji_id=NULL "
                        "WHERE product_family_id=%s", (target_id,))
                else:
                    prefix = 'menu.%' if kind == 'menu' else 'message.%'
                    self.db.execute(
                        "UPDATE public.telegram_ui_icons SET custom_emoji_id=NULL,updated_at=%s "
                        "WHERE id=%s AND key LIKE %s", (_db_now(), target_id, prefix))
                self._audit(customer, 'telegram_icon.cleared', self._subject(kind), target_id,
                            {'custom_emoji_id': target.get('custom'),
                             'icon': target.get('icon')},
                            {'custom_emoji_id': None,
                             'telegram_actor_id': str(customer['telegram_id'])})
        except Exception:
            return self.presenter.screen(customer, self.presenter.t(
                customer, 'catalog.product_icon.expired'), [], screen='admin')
        key = ('catalog.ui_icon.cleared' if kind == 'menu'
               else 'catalog.message_icon.cleared' if kind == 'message'
               else 'catalog.product_icon.cleared')
        rows = [[self.presenter.raw_button(
            self.presenter.t(customer, 'catalog.product_icon.button_back'),
            self._back_callback(kind, {'family': target.get('family')}))]]
        return self.presenter.screen(customer, self.presenter.t(customer, key,
                                                                 label=target['label']),
                                     rows, screen='admin')

    def _back_callback(self, kind, pending):
        if kind == 'product':
            return ('producticon:list' if pending.get('family') is None
                    else 'producticon:family:%s' % pending['family'])
        if kind == 'family':
            return 'producticon:list'
        return 'menuicon:list' if kind == 'menu' else 'messageicon:list'

    @staticmethod
    def _prefix(kind):
        return {'product': 'producticon', 'family': 'familyicon',
                'menu': 'menuicon', 'message': 'messageicon'}[kind]

    @staticmethod
    def _subject(kind):
        return {'product': 'App\\Models\\Product',
                'family': 'App\\Models\\ProductFamily',
                'menu': 'App\\Models\\TelegramUiIcon',
                'message': 'App\\Models\\TelegramUiIcon'}[kind]

    def _plain_emoji(self, text):
        if not text or len(text) > 32 or re.search(r'[A-Za-z\u00C0-\u024F]', text):
            return None
        return text if _PLAIN_EMOJI.search(text) else None

    def _audit(self, customer, action, subject_type, subject_id, old, new):
        self.audit(customer, action, subject_type, subject_id, old, new)

    def audit(self, customer, action, subject_type, subject_id, old, new):
        self.db.execute(
            """INSERT INTO public.audit_logs
               (user_id,action,subject_type,subject_id,old_values,new_values,ip_address,created_at)
               VALUES (NULL,%s,%s,%s,%s::jsonb,%s::jsonb,NULL,%s)""",
            (action, subject_type, str(subject_id),
             json.dumps(old, ensure_ascii=False) if old is not None else None,
             json.dumps(new, ensure_ascii=False) if new is not None else None,
             _db_now()))
