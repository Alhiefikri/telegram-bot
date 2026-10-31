"""Credential-free, read-only projections of the PHP catalog's public tables."""
from functools import cmp_to_key
import json

from django.db import connections


PRODUCT_COLUMNS = (
    "id category_id product_family_id family_sort_order family_option_label name icon "
    "custom_emoji_id description description_ms description_en warranty_policy "
    "warranty_policy_ms warranty_policy_en telegram_content price_amount usdt_price_amount "
    "currency stock reserved_stock telegram_image_path created_at product_type access_duration "
    "region account_type device_limit included_items limitations fulfillment_expectation support_policy"
).split()


def _json(value):
    return json.loads(value) if isinstance(value, str) else (value or {})


class CatalogRepository:
    def __init__(self, using="default"):
        self.using = using

    def _rows(self, sql, params=()):
        with connections[self.using].cursor() as cursor:
            cursor.execute(sql, params)
            columns = [column[0] for column in cursor.description]
            return [dict(zip(columns, row)) for row in cursor.fetchall()]

    def _one(self, sql, params=()):
        rows = self._rows(sql, params)
        return rows[0] if rows else None

    def customer(self, telegram_id):
        return self._one(
            "SELECT id,telegram_id,first_name,username,locale FROM public.customers WHERE telegram_id=%s",
            (telegram_id,),
        )

    def products(self):
        columns = ",".join("p." + name for name in PRODUCT_COLUMNS)
        rows = self._rows(f"""
            SELECT {columns},c.name AS category_name,c.slug AS category_slug,
              CASE WHEN EXISTS (SELECT 1 FROM public.product_stock_items s
                     WHERE s.product_id=p.id AND s.supplier_purchase_id IS NULL)
                THEN LEAST(GREATEST(0,p.stock-p.reserved_stock),
                     (SELECT COUNT(*) FROM public.product_stock_items s WHERE s.product_id=p.id
                      AND s.supplier_purchase_id IS NULL AND s.order_item_id IS NULL AND s.retired_at IS NULL))
                ELSE GREATEST(0,p.stock-p.reserved_stock) END
              + CASE WHEN p.supplier_available_until > CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                     THEN GREATEST(0,p.supplier_available_stock) ELSE 0 END AS available_stock,
              COALESCE((SELECT SUM(i.quantity) FROM public.order_items i JOIN public.orders o ON o.id=i.order_id
                        WHERE i.product_id=p.id AND o.payment_status='paid'
                          AND o.order_status IN ('processing','completed')),0) AS sold_quantity
            FROM public.products p LEFT JOIN public.categories c ON c.id=p.category_id AND c.deleted_at IS NULL
            WHERE p.deleted_at IS NULL AND p.is_active=TRUE
              AND (p.category_id IS NULL OR c.is_active=TRUE)
        """)
        families = {family['id']: family for family in self.families()}
        for product in rows:
            family = families.get(product['product_family_id'])
            product['family'] = family
            if family is None:
                product['product_family_id'] = None
            if not (product.get('icon') or '').strip() and not (product.get('custom_emoji_id') or '').strip():
                product['icon'] = family.get('icon') if family else None
                product['custom_emoji_id'] = family.get('custom_emoji_id') if family else None
            product['telegram_content'] = _json(product.get('telegram_content'))
            product['available_stock'] = int(product['available_stock'])
            product['sold_quantity'] = int(product['sold_quantity'])
        return rows

    def product(self, product_id):
        return next((product for product in self.products() if product['id'] == product_id), None)

    def families(self):
        return self._rows("""SELECT id,name,slug,description,icon,custom_emoji_id,telegram_image_path,sort_order
                             FROM public.product_families WHERE deleted_at IS NULL ORDER BY sort_order,name,id""")

    def family(self, family_id):
        family = next((family for family in self.families() if family['id'] == family_id), None)
        if family is None:
            return None
        members = [product for product in self.products() if product['product_family_id'] == family_id]
        if not members:
            return None
        return dict(family, members=sorted(members, key=self.member_key))

    @staticmethod
    def member_key(product):
        return (product.get('family_sort_order') or 0, product['name'].lower(), product['id'])

    def categories(self):
        return self._rows("""SELECT c.id,c.name,c.slug,c.description FROM public.categories c
            WHERE c.is_active=TRUE AND c.deleted_at IS NULL AND EXISTS (
              SELECT 1 FROM public.products p WHERE p.category_id=c.id AND p.is_active=TRUE AND p.deleted_at IS NULL)
            ORDER BY c.sort_order,c.name,c.id""")

    def category(self, identity):
        field = 'id' if str(identity).isdigit() else 'slug'
        return self._one(f"""SELECT id,name,slug,description FROM public.categories
            WHERE {field}=%s AND is_active=TRUE AND deleted_at IS NULL""", (identity,))

    def browse(self, *, category=None, search=None):
        products = self.products()
        if category is not None:
            category_row = self.category(category) if category != 'uncategorized' else None
            category_id = category_row['id'] if category_row else None
            products = [p for p in products if p['category_id'] == category_id]
        keyword = (search or '').strip().lower()
        def matches(row):
            return any(keyword in str(row.get(field) or '').lower() for field in (
                'name','slug','description','description_en','description_ms','family_option_label',
                'category_name','category_slug','product_type','access_duration','region','account_type',
                'device_limit','included_items','limitations','fulfillment_expectation','warranty_policy',
                'warranty_policy_en','warranty_policy_ms','support_policy'))
        entries = []
        for family in self.families():
            members = [p for p in products if p['product_family_id'] == family['id']]
            if members and (not keyword or matches(family) or any(matches(p) for p in members)):
                members.sort(key=lambda p: ((not matches(p)) if keyword else False, *self.member_key(p)))
                entries.append(dict(family, type='family', members=members,
                                    available_stock=sum(p['available_stock'] for p in members),
                                    sold_quantity=sum(p['sold_quantity'] for p in members)))
        entries.extend(dict(p, type='product') for p in products
                       if p['product_family_id'] is None and (not keyword or matches(p)))
        def compare(left, right):
            if left['type'] == right['type'] == 'family':
                sold = right['sold_quantity'] - left['sold_quantity']
                if sold:
                    return sold
            def key(entry):
                return (entry['available_stock'] <= 0, entry['type'] != 'family',
                        entry.get('sort_order', 2**63-1), entry['name'].lower(), entry['id'])
            return (key(left) > key(right)) - (key(left) < key(right))
        return sorted(entries, key=cmp_to_key(compare))

    def tiers(self, product_id):
        return self._rows("""SELECT minimum_quantity,price_amount,usdt_price_amount
            FROM public.product_quantity_price_tiers WHERE product_id=%s ORDER BY minimum_quantity,id""", (product_id,))

    def icons(self):
        return self._rows("""SELECT key,fallback_emoji,source_emoji,custom_emoji_id
                             FROM public.telegram_ui_icons ORDER BY sort_order,label,id""")

    def maintenance(self):
        return self._one("""SELECT is_active,public_note FROM public.checkout_maintenance_settings
                            WHERE singleton_key='checkout_maintenance'""") or {'is_active': False}

    def asset(self, source_path, bot_scope='synthetic'):
        if not source_path:
            return None
        return self._one("""SELECT file_id,version FROM django_candidate.navigation_assets
                           WHERE bot_scope=%s AND source_path=%s""", (bot_scope,source_path))

    def banner_asset(self, bot_scope='synthetic'):
        setting = self._one("""SELECT telegram_welcome_banner_path FROM public.storefront_settings
                               WHERE singleton_key='storefront'""") or {}
        return self.asset(setting.get('telegram_welcome_banner_path'),bot_scope)

    def spending_available(self):
        row = self._one("""SELECT
            EXISTS (SELECT 1 FROM public.customer_usdt_funding_policies
                    WHERE singleton_key='customer_usdt_funding_policy') AS has_policy,
            EXISTS (SELECT 1 FROM public.customer_usdt_funding_pauses WHERE scope='spending') AS paused,
            EXISTS (SELECT 1 FROM public.balance_restore_gates WHERE id=1 AND state='open') AS restoring""")
        return bool(row['has_policy'] and not row['paused'] and not row['restoring'])

    def home_data(self, customer_id):
        canonical = """WITH canonical AS (SELECT DISTINCT ON (order_id) order_id,amount,currency
            FROM public.payments WHERE status='paid' AND paid_at IS NOT NULL ORDER BY order_id,paid_at,id) """
        metrics = self._one(canonical + """SELECT
            (SELECT COUNT(*) FROM public.customers WHERE telegram_id IS NOT NULL) AS bot_users,
            (SELECT COUNT(*) FROM canonical c JOIN public.orders o ON o.id=c.order_id
             WHERE o.order_status IN ('processing','completed')) AS successful_orders""")
        totals = self._one(canonical + """SELECT
            COALESCE(SUM(CASE WHEN UPPER(COALESCE(NULLIF(TRIM(c.currency),''),'IDR'))='IDR'
                THEN GREATEST(c.amount,0) ELSE 0 END),0) AS idr_total,
            COALESCE(SUM(CASE WHEN UPPER(c.currency)='USDT' THEN GREATEST(c.amount,0) ELSE 0 END),0) AS usdt_total
            FROM canonical c JOIN public.orders o ON o.id=c.order_id
            WHERE o.customer_id=%s AND o.order_status IN ('processing','completed')""", (customer_id,))
        balance = self._one("""SELECT a.balance,(SELECT e.balance_after FROM public.store_balance_entries e
            WHERE e.customer_balance_account_id=a.id ORDER BY e.id DESC LIMIT 1) AS latest_balance_after
            FROM public.customer_balance_accounts a WHERE a.customer_id=%s""", (customer_id,))
        amount = 0 if balance is None else balance['balance']
        if balance and ((balance['latest_balance_after'] is None and amount != 0)
                        or (balance['latest_balance_after'] is not None and balance['latest_balance_after'] != amount)):
            amount = None
        channel = self._one("""SELECT channel_link,channel_username FROM public.store_channel_settings
                               WHERE singleton_key='store_channel'""") or {}
        username = (channel.get('channel_username') or '').strip().lstrip('@')
        url = (channel.get('channel_link') or '').strip() or ('https://t.me/' + username if username else None)
        api = self._one("SELECT enabled FROM public.customer_api_settings WHERE singleton_key='customer_api'")
        banner = self._one("""SELECT telegram_welcome_banner_path FROM public.storefront_settings
                              WHERE singleton_key='storefront'""") or {}
        return dict(metrics, **totals, balance=amount, channel_url=url,
                    api_enabled=bool(api and api['enabled']), banner_path=banner.get('telegram_welcome_banner_path'))
