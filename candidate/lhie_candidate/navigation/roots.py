"""ACK-backed navigation roots in the explicitly provisioned candidate schema.

NavigationRoots accepts a transport.execute(method, payload) returning TelegramResult.
Customers are dicts or objects with id and telegram_id. Public methods return outcome
strings; callers re-read current_for_chat after an established/replaced result. This
service never installs schema, reads configuration, or owns durable business messages.
"""

from dataclasses import dataclass
from datetime import datetime
from html.parser import HTMLParser
from uuid import uuid4

from django.db import connections, transaction

from .types import TelegramResult


@dataclass(frozen=True)
class Root:
    bot_asset_scope: str
    customer_id: int
    chat_id: str
    message_id: int | None
    media_kind: str | None
    screen: str | None
    locale: str | None
    banner_reference: str | None
    banner_version: int | None
    banner_kind: str
    generation: int
    render_token: object | None
    lease_expires_at: datetime | None
    rendered_at: datetime | None


class _CaptionText(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.length = 0

    def handle_data(self, data):
        self.length += len(data)


def fits_caption(surface):
    if surface.options.get('parse_mode') != 'HTML':
        return len(surface.caption) <= 1024
    parsed = _CaptionText()
    parsed.feed(surface.caption)
    parsed.close()
    return parsed.length <= 1024


class NavigationRoots:
    LEASE_SECONDS = 120
    TABLE = 'django_candidate.navigation_roots'
    COLUMNS = ', '.join(Root.__dataclass_fields__)

    def __init__(self, transport, *, using='default', banner_reference=None,
                 banner_version=None, banner_kind='photo', bot_asset_scope='synthetic'):
        if not isinstance(bot_asset_scope, str) or not bot_asset_scope or len(bot_asset_scope) > 191:
            raise ValueError('A nonempty bot asset scope is required')
        if banner_kind not in ('photo', 'animation'):
            raise ValueError('Unsupported banner media kind')
        self.transport = transport
        self.using = using
        self.banner_reference = banner_reference
        self.banner_version = banner_version
        self.banner_kind = banner_kind
        self.bot_asset_scope = bot_asset_scope

    @property
    def connection(self):
        connection = connections[self.using]
        if connection.vendor != 'postgresql':
            raise RuntimeError('Canonical navigation requires PostgreSQL')
        return connection

    def _outside_transaction(self):
        if self.connection.in_atomic_block or not self.connection.get_autocommit():
            raise RuntimeError('Navigation I/O cannot run inside a database transaction')

    def _owner(self, customer, chat_id):
        value = customer.get if isinstance(customer, dict) else lambda name: getattr(customer, name, None)
        customer_id, telegram_id = value('id'), value('telegram_id')
        chat_id = str(chat_id).strip()
        if type(customer_id) is not int or customer_id < 1 or type(telegram_id) is not int:
            return None
        if telegram_id < 1 or str(telegram_id) != chat_id:
            return None
        with self.connection.cursor() as cursor:
            cursor.execute('SELECT id FROM public.customers WHERE id = %s AND telegram_id = %s',
                           [customer_id, telegram_id])
            return (customer_id, chat_id) if cursor.fetchone() else None

    def current_for_chat(self, customer, chat_id):
        owner = self._owner(customer, chat_id)
        if owner is None:
            return None
        with self.connection.cursor() as cursor:
            cursor.execute(f'SELECT {self.COLUMNS} FROM {self.TABLE} '
                           'WHERE bot_asset_scope = %s AND customer_id = %s AND chat_id = %s '
                           'AND message_id IS NOT NULL', [self.bot_asset_scope, *owner])
            row = cursor.fetchone()
        return Root(*row) if row else None

    def match(self, customer, chat_id, message_id):
        if type(message_id) is not int or message_id < 1:
            return None
        root = self.current_for_chat(customer, chat_id)
        return root if root and root.message_id == message_id else None

    def _claim(self, customer, chat_id, root=None, *, establish=False):
        owner = self._owner(customer, chat_id)
        if owner is None:
            return None
        if root is not None and (root.customer_id != owner[0] or root.chat_id != owner[1]
                                 or root.bot_asset_scope != self.bot_asset_scope):
            return None
        token = uuid4()
        with transaction.atomic(using=self.using):
            with self.connection.cursor() as cursor:
                if establish:
                    cursor.execute(f'INSERT INTO {self.TABLE} (bot_asset_scope, customer_id, chat_id) '
                                   'VALUES (%s, %s, %s) ON CONFLICT DO NOTHING',
                                   [self.bot_asset_scope, *owner])
                predicates = ''
                params = [token, self.LEASE_SECONDS, self.bot_asset_scope, *owner]
                if root is not None:
                    predicates = ' AND generation = %s AND message_id = %s'
                    params += [root.generation, root.message_id]
                cursor.execute(f'UPDATE {self.TABLE} SET generation = generation + 1, render_token = %s, '
                               "lease_expires_at = clock_timestamp() + %s * interval '1 second' "
                               'WHERE bot_asset_scope = %s AND customer_id = %s AND chat_id = %s '
                               'AND (render_token IS NULL OR lease_expires_at <= clock_timestamp())'
                               f'{predicates} RETURNING {self.COLUMNS}', params)
                row = cursor.fetchone()
        return Root(*row) if row else None

    def _fence(self, root):
        return ('bot_asset_scope = %s AND customer_id = %s AND chat_id = %s '
                'AND message_id IS NOT DISTINCT FROM %s AND generation = %s AND render_token = %s '
                'AND lease_expires_at > clock_timestamp()',
                [root.bot_asset_scope, root.customer_id, root.chat_id, root.message_id,
                 root.generation, root.render_token])

    def _release(self, root):
        where, params = self._fence(root)
        with self.connection.cursor() as cursor:
            cursor.execute(f'UPDATE {self.TABLE} SET render_token = NULL, lease_expires_at = NULL '
                           f'WHERE {where}', params)
            return cursor.rowcount == 1

    def _live(self, root):
        where, params = self._fence(root)
        with self.connection.cursor() as cursor:
            cursor.execute(f'SELECT 1 FROM {self.TABLE} WHERE {where}', params)
            return cursor.fetchone() is not None

    def _finish(self, root, surface, *, sent_id=None, media_kind=None, reference=None, version=None, establishing=False):
        where, params = self._fence(root)
        projection = 'screen = %s, locale = %s, rendered_at = clock_timestamp(), '
        values = [surface.screen, surface.locale]
        if sent_id is not None:
            projection += ('message_id = %s, media_kind = %s, banner_reference = %s, '
                           'banner_version = %s, banner_kind = %s, ')
            if not establishing:
                projection += 'generation = generation + 1, '
            values += [sent_id, media_kind, reference, version, self.banner_kind]
        with self.connection.cursor() as cursor:
            cursor.execute(f'UPDATE {self.TABLE} SET {projection}'
                           f'render_token = NULL, lease_expires_at = NULL WHERE {where}', values + params)
            return cursor.rowcount == 1

    def _execute(self, method, payload):
        self._outside_transaction()
        try:
            result = self.transport.execute(method, payload)
        except Exception:
            return TelegramResult(message_id=None, outcome='ambiguous')
        if result.outcome not in ('success', 'not_modified', 'definite', 'ambiguous'):
            return TelegramResult(message_id=None, outcome='ambiguous')
        return result

    @staticmethod
    def _options(surface):
        return {key: value for key, value in surface.options.items() if key not in ('photo_url', 'asset_version')}

    def _payload(self, root, surface):
        return {**self._options(surface), 'chat_id': root.chat_id,
                'reply_markup': surface.keyboard}

    def _media(self, root, surface, media_reference=None):
        reference = self.banner_reference
        version = self.banner_version
        kind = self.banner_kind
        if root.banner_reference and root.banner_version == version and root.banner_kind == kind:
            reference = root.banner_reference
        if media_reference is not None:
            reference = media_reference
        if surface.screen == 'product':
            return ('photo', surface.options['photo_url']) if surface.options.get('photo_url') and fits_caption(surface) else ('text', None)
        return (kind, reference) if reference and fits_caption(surface) else ('text', None)

    def _send(self, root, surface, *, media_reference=None, remove_legacy_reply_keyboard=False, establishing=False):
        media_kind, reference = self._media(root, surface, media_reference)
        payload = self._payload(root, surface)
        if remove_legacy_reply_keyboard:
            payload['reply_markup'] = {'remove_keyboard': True}
        if media_kind == 'text':
            payload['text'] = surface.caption
            method = 'sendMessage'
        else:
            payload.update({media_kind: reference, 'caption': surface.caption})
            method = 'sendAnimation' if media_kind == 'animation' else 'sendPhoto'
        result = self._execute(method, payload)
        if result.outcome != 'success' or type(result.message_id) is not int or result.message_id < 1:
            return result.outcome if result.outcome == 'definite' else 'ambiguous'
        if remove_legacy_reply_keyboard:
            if not self._live(root):
                return 'superseded'
            restored = self._execute('editMessageReplyMarkup', {
                'chat_id': root.chat_id, 'message_id': result.message_id, 'reply_markup': surface.keyboard,
            })
            if restored.outcome != 'success':
                return 'definite' if restored.outcome == 'definite' else 'ambiguous'
        banner = reference if media_kind != 'text' and surface.screen != 'product' else (root.banner_reference or self.banner_reference)
        version = self.banner_version if surface.screen != 'product' else root.banner_version
        return 'replaced' if self._finish(root, surface, sent_id=result.message_id,
                                          media_kind=media_kind, reference=banner, version=version,
                                          establishing=establishing) else 'superseded'

    def establish(self, customer, chat_id, surface, *, media_reference=None, remove_legacy_reply_keyboard=False):
        self._outside_transaction()
        root = self._claim(customer, chat_id, establish=True)
        if root is None:
            return 'superseded'
        outcome = self._send(root, surface, media_reference=media_reference,
                             remove_legacy_reply_keyboard=remove_legacy_reply_keyboard, establishing=True)
        if outcome != 'replaced':
            self._release(root)
        return 'established' if outcome == 'replaced' else outcome

    def render(self, customer, root, chat_id, surface, *, refresh=False):
        self._outside_transaction()
        if not isinstance(root, Root):
            return 'superseded'
        claimed = self._claim(customer, chat_id, root)
        if claimed is None:
            return 'superseded'
        product_transition = ((claimed.screen == 'product') != (surface.screen == 'product'))
        media_change = (surface.screen == 'product' and (
            (claimed.media_kind == 'text' and surface.options.get('photo_url') and fits_caption(surface))
            or (claimed.media_kind != 'text' and not surface.options.get('photo_url'))))
        replace = refresh or product_transition or media_change or (
            claimed.media_kind != 'text' and not fits_caption(surface))
        if not replace:
            payload = self._payload(claimed, surface)
            payload.update({'message_id': claimed.message_id,
                            'text' if claimed.media_kind == 'text' else 'caption': surface.caption})
            result = self._execute('editMessageText' if claimed.media_kind == 'text' else 'editMessageCaption', payload)
            if result.outcome == 'success':
                return 'applied' if self._finish(claimed, surface) else 'superseded'
            if result.outcome != 'definite':
                released = self._release(claimed)
                return ('unchanged' if result.outcome == 'not_modified' else 'ambiguous') if released else 'superseded'
        if not self._live(claimed):
            return 'superseded'
        if refresh:
            result = self._execute('deleteMessage', {'chat_id': claimed.chat_id, 'message_id': claimed.message_id})
            if result.outcome != 'success':
                self._release(claimed)
                return 'definite' if result.outcome == 'definite' else 'ambiguous'
            if not self._live(claimed):
                return 'superseded'
        outcome = self._send(claimed, surface)
        if outcome != 'replaced':
            self._release(claimed)
        elif product_transition or media_change:
            self._execute('deleteMessage', {'chat_id': claimed.chat_id, 'message_id': claimed.message_id})
        return outcome
