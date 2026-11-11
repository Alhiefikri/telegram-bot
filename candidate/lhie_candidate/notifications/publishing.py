"""Admin confirmation creates one immutable durable broadcast, never a send."""
import json
from uuid import UUID, uuid4

from django.db import transaction

from ..receipt.protocol import _now
from .policy import NotEligible


class BroadcastPublishing:
    def __init__(self, service):
        self.db, self.recipients = service.db, service.recipients

    def product_options(self):
        return self.db.rows(self._eligible_products_sql() + ' ORDER BY p.name,p.id')

    def draft(self, message, photo_file_id='', product_id=''):
        if not isinstance(message, str) or (not message.strip() and not photo_file_id):
            raise ValueError('Isi pesan broadcast terlebih dahulu.')
        if len(message) > 3500:
            raise ValueError('Pesan broadcast maksimal 3500 karakter.')
        if not isinstance(photo_file_id, str) or len(photo_file_id) > 255 or any(ord(c) < 32 for c in photo_file_id):
            raise ValueError('Referensi foto Telegram tidak valid.')
        if photo_file_id and len(message) > 1024:
            raise ValueError('Caption foto maksimal 1024 karakter.')
        product = None
        if product_id not in ('', None):
            if isinstance(product_id, bool) or not str(product_id).isascii() or not str(product_id).isdigit():
                raise ValueError('Produk broadcast tidak tersedia.')
            product = self.eligible_product(int(product_id))
            if not product:
                raise ValueError('Produk broadcast tidak tersedia.')
        reply_markup = self._purchase_markup(product) if product else {}
        return {'key': str(uuid4()), 'message': message, 'photo_file_id': photo_file_id or None,
                'product_id': product['id'] if product else None,
                'product_name': product['name'] if product else None,
                'reply_markup': reply_markup, 'expires_at': int(_now().timestamp()) + 900}

    def publish(self, draft, confirmation):
        if not self.recipients.telegram:
            raise NotEligible()
        if (not isinstance(draft, dict) or confirmation != draft.get('key')
                or type(draft.get('expires_at')) is not int or draft['expires_at'] < int(_now().timestamp())):
            raise ValueError('Konfirmasi broadcast kedaluwarsa. Tinjau pesan lagi.')
        try:
            key = str(UUID(draft['key']))
        except (ValueError, TypeError, KeyError, AttributeError):
            raise ValueError('Konfirmasi broadcast tidak valid.') from None
        # Validate the server-owned snapshot, not new form fields on the confirm POST.
        self.draft(draft.get('message'), draft.get('photo_file_id') or '')
        markup = draft.get('reply_markup') or {}
        if not isinstance(markup, dict):
            raise ValueError('Konfirmasi broadcast tidak valid.')
        with transaction.atomic(using=self.db.using):
            existing = self.db.one(
                'SELECT id FROM public.telegram_broadcasts WHERE broadcast_key=%s FOR UPDATE',
                (key,),
            )
            if existing:
                return existing['id']
            product_id = draft.get('product_id')
            if product_id is not None:
                product = self.eligible_product(product_id, lock=True)
                if not product:
                    raise ValueError('Produk broadcast sudah tidak tersedia. Tinjau pesan lagi.')
                current_markup = self._purchase_markup(product)
                if product['name'] != draft.get('product_name') or current_markup != markup:
                    raise ValueError('Tampilan produk broadcast berubah. Tinjau pesan lagi.')
                markup = current_markup
            elif draft.get('product_name') is not None or markup:
                raise ValueError('Konfirmasi broadcast tidak valid.')

            self.db.execute('''INSERT INTO public.telegram_broadcasts
                (broadcast_key,source,message,photo_file_id,reply_markup,status,created_at,updated_at)
                VALUES (%s,'admin',%s,%s,%s,'queued',%s,%s) ON CONFLICT (broadcast_key) DO NOTHING''',
                (key, draft['message'], draft.get('photo_file_id'),
                 json.dumps(markup, ensure_ascii=False) if markup else None, _now(), _now()))
            return self.db.one('SELECT id FROM public.telegram_broadcasts WHERE broadcast_key=%s', (key,))['id']

    def eligible_product(self, product_id, *, lock=False):
        sql = self._eligible_products_sql() + ' AND p.id=%s'
        if lock:
            sql += ' FOR SHARE OF p'
        return self.db.one(sql, (product_id,))

    @staticmethod
    def _eligible_products_sql():
        return '''SELECT p.id,p.name,p.price_amount,p.icon,p.custom_emoji_id,
            f.icon AS family_icon,f.custom_emoji_id AS family_custom_emoji_id
            FROM public.products p
            LEFT JOIN public.categories c ON c.id=p.category_id AND c.deleted_at IS NULL
            LEFT JOIN public.product_families f ON f.id=p.product_family_id AND f.deleted_at IS NULL
            WHERE p.deleted_at IS NULL AND p.is_active=TRUE
              AND (p.category_id IS NULL OR c.is_active=TRUE)
              AND ((p.stock>p.reserved_stock AND (
                    NOT EXISTS (SELECT 1 FROM public.product_stock_items s
                                WHERE s.product_id=p.id AND s.supplier_purchase_id IS NULL)
                    OR EXISTS (SELECT 1 FROM public.product_stock_items s
                               WHERE s.product_id=p.id AND s.supplier_purchase_id IS NULL
                                 AND s.order_item_id IS NULL AND s.retired_at IS NULL)))
                   OR (p.supplier_available_stock>0 AND p.supplier_available_until>now()))'''


    def _purchase_markup(self, product):
        glyph, identifier = product.get('icon'), product.get('custom_emoji_id')
        if not (glyph or '').strip() and not (identifier or '').strip():
            glyph, identifier = product.get('family_icon'), product.get('family_custom_emoji_id')
        product_glyph = (glyph or '').strip() or '🛍️'
        cart = next((row for row in self.db.rows(
            'SELECT source_emoji,fallback_emoji,custom_emoji_id FROM public.telegram_ui_icons '
            'ORDER BY sort_order,label,id') if row.get('source_emoji') == '🛍️'), None)
        cart_glyph = (cart or {}).get('fallback_emoji') or '🛍️'
        action = {'id': 'Beli', 'en': 'Buy', 'ms': 'Beli'}
        result = {}
        for locale in ('id', 'en', 'ms'):
            product_name = str(product['name'])
            if len(product_name) > 38:
                product_name = product_name[:38].rstrip(' \t\n\r\0\x0b') + '…'
            label = f'{product_glyph} {product_name} · {cart_glyph} {action[locale]}'
            result[locale] = {'inline_keyboard': [[{
                'text': label, 'callback_data': 'product:' + str(product['id']),
            }]]}
        return result
