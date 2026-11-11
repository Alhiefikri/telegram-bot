"""The retained admin-authorized synthetic proof, isolated from economic history."""
from datetime import datetime, timedelta
import hashlib
import json
import re
import secrets
from uuid import UUID
from zoneinfo import ZoneInfo

from django.db import transaction

from ..admin.db import audit
from ..checkout.errors import CheckoutError
from ..checkout.pricing import PricingSnapshot
from ..navigation.repository import _PRODUCT_SELECT
from ..receipt.invoice import render_invoice
from ..receipt.protocol import _now
from . import bulk_content
from .policy import NotEligible
from .proof_publication import SalesProofPublication, _metadata, format_caption, shopping_markup

FAKE = 'sales_proof.fake'
FAKE_TEMPLATE = 'fake-sales-proof-post-v1'
MODELS = {FAKE: 'Product'}
LIMITS = {FAKE: 3}
WINDOW_SECONDS = 20
_BUYERS = ('Andi Saputra', 'Budi Santoso', 'Citra Lestari', 'Dimas Pratama', 'Eka Putri',
           'Fajar Ramadhan', 'Gilang Maulana', 'Hana Salsabila', 'Intan Permata', 'Joko Susilo',
           'Kevin Wijaya', 'Larasati Putri', 'Maya Anggraini', 'Nadia Aulia', 'Rizky Kurniawan', 'Siti Rahma')


def _ulid(at):
    value = (int(at.timestamp() * 1000) << 80) | secrets.randbits(80)
    alphabet, encoded = '0123456789ABCDEFGHJKMNPQRSTVWXYZ', ''
    for _ in range(26):
        encoded = alphabet[value & 31] + encoded
        value >>= 5
    return encoded


def synthetic_presentation(product, quote, paid_at, invoice_id):
    """Port the existing synthetic invoice contract; never infer a real buyer."""
    currency = str(product.get('currency') or 'IDR').strip().upper()
    usd = currency == 'USDT'
    unit = quote.usdt_unit_price if usd and quote.usdt_unit_price is not None else quote.unit_price
    subtotal = quote.usdt_subtotal if usd and quote.usdt_subtotal is not None else quote.subtotal
    fee = (subtotal + 99) // 100
    seed = hashlib.sha256(f"sales-proof-buyer-name|{product['id']}|{invoice_id}".encode()).hexdigest()
    buyer = _BUYERS[int(seed[:8], 16) % len(_BUYERS)][:3] + '***'
    return {'invoice_number': invoice_id, 'buyer_name': buyer, 'chat_id': None,
            'paid_at': paid_at, 'payment_method': 'QRIS', 'currency': currency,
            'items': [{'product_name': product['name'] or 'Digital product', 'quantity': quote.quantity,
                       'quantity_price_tier_minimum': quote.tier_minimum, 'unit_price': unit, 'subtotal': subtotal}],
            'provider_fee': fee, 'total_paid': subtotal + fee}


class FakeProofPublication(SalesProofPublication):
    def __init__(self, service, *, bot_username=None):
        super().__init__(service, bot_username=bot_username)
        self._deletion_cursor = None

    def _prepare(self, product, quantity, key, chat, username, *, movement_ids=()):
        self.db.require_transaction()
        retained = self.db.one('SELECT * FROM public.telegram_outbound_effects WHERE effect_key=%s FOR UPDATE', (key,))
        if retained:
            return retained
        tiers = self.db.rows('''SELECT minimum_quantity,price_amount,usdt_price_amount
            FROM public.product_quantity_price_tiers WHERE product_id=%s ORDER BY minimum_quantity''', (product['id'],))
        quote = PricingSnapshot.build([{'product_id': product['id'], 'quantity': quantity}],
            {product['id']: product}, {product['id']: tiers}).quotes[0]
        paid_at = datetime.now(ZoneInfo('Asia/Jakarta')).replace(microsecond=0)
        snapshot = synthetic_presentation(product, quote, paid_at, 'INV-' + paid_at.strftime('%Y%m%d') + '-' + _ulid(paid_at))
        number = self._number()
        icons = bulk_content.icons(self.db)
        rendered = {'snapshot': {**snapshot, 'paid_at': snapshot['paid_at'].isoformat()},
                    'number': number, 'caption': format_caption(snapshot, number, self._stock(product['id']), icons),
                    'reply_markup': shopping_markup(icons, bulk_content.product_url(product['id'], username)),
                    'synthetic': True, 'deleted_movement_ids': list(movement_ids)}
        effect = self.service.record(key=key, kind=FAKE, model='Product', source=product['id'],
            chat=chat, target='store_channel', locale='en', template=FAKE_TEMPLATE, method='sendPhoto')
        self.db.execute('''UPDATE public.telegram_outbound_effects SET outcome_metadata=
            jsonb_build_object('delivery_context','fake_sales_proof','proof_publication',%s::jsonb)::json
            WHERE id=%s''', (json.dumps(rendered, ensure_ascii=False), effect['id']))
        return self.db.one('SELECT * FROM public.telegram_outbound_effects WHERE id=%s', (effect['id'],))

    def manual(self, product_id, actor_id, request_id, ip=None):
        if not self.service.recipients.telegram:
            raise CheckoutError('sales_proof_not_ready')
        try:
            request_id = str(UUID(str(request_id)))
            product_id = int(product_id)
            if product_id < 1:
                raise ValueError()
            username = self._username()
        except (ValueError, TypeError, NotEligible):
            raise CheckoutError('fake_sales_proof_invalid') from None
        with transaction.atomic(using=self.db.using):
            actor = self.db.one('SELECT role FROM public.users WHERE id=%s FOR SHARE', (actor_id,))
            if not actor or actor['role'] not in ('owner','admin'):
                raise CheckoutError('operator_forbidden')
            try:
                chat = self._channel()
            except NotEligible:
                raise CheckoutError('sales_proof_not_ready') from None
            # Product lock and ledger key serialize repeated browser submissions.
            product = self.db.one(_PRODUCT_SELECT + ' AND p.id=%s FOR UPDATE OF p', (product_id,))
            if not product or int(product['available_stock']) < 1:
                raise CheckoutError('fake_sales_proof_invalid')
            key = f'fake-sales-proof:manual:{actor_id}:{request_id}:v1'
            existing = self.db.one('SELECT * FROM public.telegram_outbound_effects WHERE effect_key=%s', (key,))
            if existing:
                if existing['source_id'] != str(product_id):
                    raise CheckoutError('fake_sales_proof_invalid')
                return existing
            effect = self._prepare(product, 1, key, chat, username)
            audit(self.db, actor_id, 'fake_sales_proof.requested', 'App\\Models\\Product', product_id,
                  None, {'effect_id': str(effect['id']), 'synthetic': True}, ip)
            return effect

    def materialize(self, limit):
        if not self.service.recipients.telegram:
            return 0
        try:
            chat, username = self._channel(), self._username()
        except NotEligible:
            return 0
        # The committed negative adjustment is the durable handoff; Redis loss
        # cannot erase it. Never select sale movements or create a Sale here.
        rows = self.db.rows('''SELECT m.id,m.product_id,m.created_at FROM public.inventory_movements m
            WHERE m.order_id IS NULL AND m.type='adjustment' AND m.quantity<0
              AND m.notes LIKE 'Hapus stok manual dashboard (%% item).'
              AND m.created_at<=%s AND (%s::bigint IS NULL OR m.id>%s)
              AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e WHERE e.effect_type=%s
                AND e.outcome_metadata::jsonb->'proof_publication'->'deleted_movement_ids' @> jsonb_build_array(m.id))
            ORDER BY m.id LIMIT %s''', (_now() - timedelta(seconds=WINDOW_SECONDS), self._deletion_cursor, self._deletion_cursor, FAKE, limit))
        self._deletion_cursor = rows[-1]['id'] if rows else None
        prepared = 0
        for row in rows:
            with transaction.atomic(using=self.db.using):
                product = self.db.one('''SELECT id,name,currency,price_amount,usdt_price_amount
                    FROM public.products WHERE id=%s FOR UPDATE''', (row['product_id'],))
                if not product:
                    continue
                movements = self.db.rows('''SELECT m.id,m.quantity FROM public.inventory_movements m
                    WHERE m.product_id=%s AND m.order_id IS NULL AND m.type='adjustment' AND m.quantity<0
                      AND m.notes LIKE 'Hapus stok manual dashboard (%% item).'
                      AND m.created_at>=%s AND m.created_at<%s
                      AND NOT EXISTS (SELECT 1 FROM public.telegram_outbound_effects e WHERE e.effect_type=%s
                        AND e.outcome_metadata::jsonb->'proof_publication'->'deleted_movement_ids' @> jsonb_build_array(m.id))
                    ORDER BY m.id''', (row['product_id'], row['created_at'], row['created_at'] + timedelta(seconds=WINDOW_SECONDS), FAKE))
                if not movements:
                    continue
                ids = [m['id'] for m in movements]
                self._prepare(product, -sum(m['quantity'] for m in movements),
                    f"fake-sales-proof:deleted-stock:{row['product_id']}:{ids[0]}:v1", chat, username, movement_ids=ids)
                prepared += 1
        return prepared

    def payload(self, effect):
        if (effect['effect_type'] != FAKE or effect['source_type'] != 'App\\Models\\Product'
                or effect['target_type'] != 'store_channel' or effect['telegram_method'] != 'sendPhoto'
                or effect['template_version'] != FAKE_TEMPLATE or effect['telegram_chat_id'] != self._channel()):
            raise NotEligible()
        frozen = _metadata(effect).get('proof_publication')
        if not isinstance(frozen, dict) or frozen.get('synthetic') is not True:
            raise NotEligible()
        snapshot = {**frozen['snapshot'], 'paid_at': datetime.fromisoformat(frozen['snapshot']['paid_at'])}
        return {'chat_id': effect['telegram_chat_id'], 'photo': render_invoice(snapshot),
                'caption': frozen['caption'], 'reply_markup': frozen['reply_markup'], 'parse_mode': 'HTML'}

    def project(self, effect):
        return 0
