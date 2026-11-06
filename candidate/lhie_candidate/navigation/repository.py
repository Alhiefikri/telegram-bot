"""Credential-free, read-only projections of the PHP catalog's public tables."""
from datetime import datetime, timezone
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

FAMILY_COLUMNS = (
    "id name slug description icon custom_emoji_id telegram_image_path sort_order"
).split()

CATEGORY_COLUMNS = "id name slug description".split()

ORDER_HISTORY_PAGE_SIZE = 10
BALANCE_ACTIVITY_PAGE_SIZE = 10
USDT_ACTIVITY_ROWS = 25

# One projection shared by the catalog scan and targeted detail lookups. Detail,
# quantity and Back renders must not re-scan every Product with its correlated
# stock/order subqueries; P11 measured that full scan as the dominant cost.
# Family columns ride the same row: a separate Family read per Product render was
# a second round trip that never carried authority or changed presentation.
_PRODUCT_SELECT = f"""
    SELECT {", ".join("p." + name for name in PRODUCT_COLUMNS)},
      c.name AS category_name,c.slug AS category_slug,
      {", ".join("f." + name + " AS family_" + name for name in FAMILY_COLUMNS)},
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
    FROM public.products p
    LEFT JOIN public.categories c ON c.id=p.category_id AND c.deleted_at IS NULL
    LEFT JOIN public.product_families f ON f.id=p.product_family_id AND f.deleted_at IS NULL
    WHERE p.deleted_at IS NULL AND p.is_active=TRUE
      AND (p.category_id IS NULL OR c.is_active=TRUE)
"""

_UNSET = object()


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

    def order_history(self, customer_id, *, direction=None, created_token=None, order_id=None):
        """PHP-equivalent successful Orders page using one bounded keyset read.

        ``created_token`` is the stored wall-clock timestamp (YYYYMMDDHHMMSS),
        never a timezone-converted value: legacy Orders are timestamp without
        time zone written in the application timezone. The composite keyset
        keeps equal-second Orders reachable in both directions.
        """
        if direction not in (None, 'older', 'newer'):
            raise ValueError('invalid order history direction')
        if (direction is None) != (created_token is None and order_id is None):
            raise ValueError('invalid order history cursor')
        if direction is not None and (
                not created_token or len(created_token) != 14 or not created_token.isdigit()
                or not order_id):
            raise ValueError('invalid order history cursor')
        where = ("customer_id=%s AND payment_status='paid' "
                 "AND order_status IN ('processing','completed')")
        params = [customer_id]
        if direction is not None:
            operator = '<' if direction == 'older' else '>'
            timestamp = (f'{created_token[0:4]}-{created_token[4:6]}-{created_token[6:8]} '
                         f'{created_token[8:10]}:{created_token[10:12]}:{created_token[12:14]}')
            where += f' AND (created_at, id) {operator} (%s::timestamp, %s::uuid)'
            params += [timestamp, order_id]
        order = 'DESC' if direction != 'newer' else 'ASC'
        return self._rows(
            "SELECT id,invoice_number,order_status,payment_status,"
            "to_char(created_at,'YYYYMMDDHH24MISS') AS created_token,"
            "telegram_receipt_sent_at IS NOT NULL AS receipt_sent "
            "FROM public.orders WHERE " + where +
            f" ORDER BY created_at {order},id {order} LIMIT {ORDER_HISTORY_PAGE_SIZE + 1}",
            params,
        )

    def balance_snapshot(self, customer_id):
        """PHP `StoreBalanceLedger::snapshot()` without any write authority.

        The projection and its newest entry are read in one statement so a
        concurrent credit cannot look like a real ledger/projection drift.
        """
        row = self._one(
            "SELECT a.balance,(SELECT e.balance_after FROM public.store_balance_entries e "
            "WHERE e.customer_balance_account_id=a.id ORDER BY e.id DESC LIMIT 1) "
            "AS latest_balance_after FROM public.customer_balance_accounts a "
            "WHERE a.customer_id=%s",
            (customer_id,),
        )
        if row is None:
            return {'available': True, 'balance': 0}
        amount = int(row['balance'])
        if row['latest_balance_after'] is None:
            return {'available': amount == 0, 'balance': amount if amount == 0 else 0}
        available = int(row['latest_balance_after']) == amount
        return {'available': available, 'balance': amount if available else 0}

    def usdt_balance_snapshot(self, customer_id):
        """PHP `CustomerUsdtBalanceLedger::snapshot()` cursor-aware health check."""
        row = self._one(
            "SELECT a.balance_micro,a.version,a.frozen_reason,"
            "(SELECT e.balance_after_micro FROM public.customer_usdt_balance_entries e "
            "WHERE e.customer_usdt_balance_account_id=a.id ORDER BY e.id DESC LIMIT 1) "
            "AS latest_balance_after,"
            "(SELECT e.account_sequence FROM public.customer_usdt_balance_entries e "
            "WHERE e.customer_usdt_balance_account_id=a.id ORDER BY e.id DESC LIMIT 1) "
            "AS latest_account_sequence FROM public.customer_usdt_balance_accounts a "
            "WHERE a.customer_id=%s",
            (customer_id,),
        )
        if row is None:
            return {'available': True, 'balance_micro': 0, 'frozen': False}
        amount, version = int(row['balance_micro']), int(row['version'])
        if row['latest_balance_after'] is None:
            available = version == 0 and amount == 0
        else:
            available = (int(row['latest_balance_after']) == amount
                         and int(row['latest_account_sequence']) == version)
        return {'available': available,
                'balance_micro': amount if available else 0,
                'frozen': row['frozen_reason'] is not None}

    def balance_account_id(self, customer_id):
        row = self._one("""SELECT id FROM public.customer_balance_accounts
                            WHERE customer_id=%s""", (customer_id,))
        return None if row is None else int(row['id'])

    @staticmethod
    def _activity_timestamp(occurred_micros):
        if type(occurred_micros) is not int or occurred_micros < 1:
            raise ValueError('invalid balance activity cursor')
        seconds, micros = divmod(occurred_micros, 1_000_000)
        return (datetime.fromtimestamp(seconds, tz=timezone.utc)
                .strftime('%Y-%m-%d %H:%M:%S') + f'.{micros:06d}')

    @staticmethod
    def _occurred_micros(occurred_at):
        """Wall-clock ordering key; the column has no time zone."""
        occurred = datetime.fromisoformat(occurred_at).replace(tzinfo=timezone.utc)
        delta = occurred - datetime(1970, 1, 1, tzinfo=timezone.utc)
        return (delta.days * 86400 + delta.seconds) * 1_000_000 + delta.microseconds

    def balance_activity(self, account_id, *, direction=None, occurred_micros=None,
                         entry_id=None):
        """One bounded PHP-equivalent IDR ledger page.

        ``(created_at, id)`` keeps same-second rows reachable in both
        directions. The page reads at most ten rows plus one lookahead and
        never scans another Customer's account.
        """
        if direction not in (None, 'older', 'newer'):
            raise ValueError('invalid balance activity direction')
        if (direction is None) != (occurred_micros is None and entry_id is None):
            raise ValueError('invalid balance activity cursor')
        if direction is not None and (occurred_micros is None or not entry_id):
            raise ValueError('invalid balance activity cursor')
        where, params = 'customer_balance_account_id=%s', [account_id]
        if direction is not None:
            operator = '<' if direction == 'older' else '>'
            where += f' AND (created_at,id) {operator} (%s::timestamp,%s)'
            params += [self._activity_timestamp(occurred_micros), entry_id]
        order = 'ASC' if direction == 'newer' else 'DESC'
        rows = self._rows(
            "SELECT id,kind,amount,source_type,source_id,refund_order_id,"
            "to_char(created_at,'YYYY-MM-DD\"T\"HH24:MI:SS.US') AS occurred_at "
            "FROM public.store_balance_entries WHERE " + where +
            f" ORDER BY created_at {order},id {order} LIMIT {BALANCE_ACTIVITY_PAGE_SIZE + 1}",
            params,
        )
        for row in rows:
            row['occurred_micros'] = self._occurred_micros(row['occurred_at'])
        return rows

    def balance_entry_exists(self, account_id, entry_id):
        return self._one("""SELECT 1 AS present FROM public.store_balance_entries
                            WHERE customer_balance_account_id=%s AND id=%s""",
                         (account_id, entry_id)) is not None

    def usdt_activity(self, customer_id, limit=USDT_ACTIVITY_ROWS):
        """Newest USDT ledger rows for the wallet overview; never funding targets."""
        if type(limit) is not int or not 1 <= limit <= USDT_ACTIVITY_ROWS:
            raise ValueError('invalid USDT activity limit')
        rows = self._rows(
            "SELECT e.id,e.kind,e.amount_micro,e.source_type,e.source_id,e.refund_order_id,"
            "to_char(e.created_at,'YYYY-MM-DD\"T\"HH24:MI:SS.US') AS occurred_at "
            "FROM public.customer_usdt_balance_entries e "
            "JOIN public.customer_usdt_balance_accounts a "
            "ON a.id=e.customer_usdt_balance_account_id "
            "WHERE a.customer_id=%s ORDER BY e.created_at DESC,e.id DESC LIMIT %s",
            (customer_id, limit),
        )
        for row in rows:
            row['occurred_micros'] = self._occurred_micros(row['occurred_at'])
        return rows

    def usdt_history(self, customer_id, *, direction=None, cursor=None):
        if direction not in (None, 'older', 'newer') or (direction is None) != (cursor is None):
            raise ValueError('invalid USDT history cursor')
        allowed = self._one("SELECT has_column_privilege(current_user,'public.customer_usdt_top_ups','id','SELECT') AS allowed")
        if not allowed['allowed']:
            return self._usdt_entry_history(customer_id, direction=direction, cursor=cursor)
        params = [customer_id, customer_id]
        predicate = ''
        if cursor is not None:
            stamp, rank, item_id = cursor
            timestamp = self._activity_timestamp(stamp)
            exists = self._one(
                "SELECT 1 AS present FROM ("
                "SELECT t.created_at,1 AS rank,t.intent_id AS item_id FROM public.customer_usdt_top_ups t WHERE t.customer_id=%s "
                "UNION ALL SELECT e.created_at,2,e.id FROM public.customer_usdt_balance_entries e "
                "JOIN public.customer_usdt_balance_accounts a ON a.id=e.customer_usdt_balance_account_id WHERE a.customer_id=%s"
                ") x WHERE x.created_at=%s::timestamp AND x.rank=%s AND x.item_id=%s",
                (customer_id, customer_id, timestamp, rank, item_id))
            if exists is None:
                return None
            operator = '<' if direction == 'older' else '>'
            predicate = f' WHERE (created_at,rank,item_id) {operator} (%s::timestamp,%s,%s)'
            params += [timestamp, rank, item_id]
        order = 'ASC' if direction == 'newer' else 'DESC'
        rows = self._rows(
            "SELECT *,to_char(created_at,'YYYY-MM-DD\"T\"HH24:MI:SS.US') AS occurred_at FROM ("
            "SELECT t.created_at,1 AS rank,t.intent_id AS item_id,t.id::text AS target_id,"
            "t.amount_micro,'top_up' AS kind,t.reference AS reference "
            "FROM public.customer_usdt_top_ups t WHERE t.customer_id=%s "
            "AND (NOT EXISTS (SELECT 1 FROM public.customer_usdt_top_up_resolutions r WHERE r.top_up_id=t.id) "
            "OR EXISTS (SELECT 1 FROM public.customer_usdt_top_up_resolutions r "
            "JOIN public.customer_usdt_top_up_settlement_work w ON w.top_up_id=r.top_up_id "
            "WHERE r.top_up_id=t.id AND r.kind IN ('expired_unpaid','cancelled_by_customer') AND w.state='manual_review')) "
            "UNION ALL SELECT e.created_at,2,e.id,NULL::text,e.amount_micro,e.kind,"
            "COALESCE(e.source_id::text,'') FROM public.customer_usdt_balance_entries e "
            "JOIN public.customer_usdt_balance_accounts a ON a.id=e.customer_usdt_balance_account_id WHERE a.customer_id=%s"
            ") x" + predicate + f" ORDER BY created_at {order},rank {order},item_id {order} LIMIT {USDT_ACTIVITY_ROWS + 1}",
            params)
        for row in rows:
            row['occurred_micros'] = self._occurred_micros(row['occurred_at'])
        return rows

    def _usdt_entry_history(self, customer_id, *, direction, cursor):
        params, predicate = [customer_id], ''
        if cursor is not None:
            stamp, rank, item_id = cursor
            if rank != 2:
                return None
            timestamp = self._activity_timestamp(stamp)
            if not self._one('''SELECT e.id FROM public.customer_usdt_balance_entries e
                JOIN public.customer_usdt_balance_accounts a ON a.id=e.customer_usdt_balance_account_id
                WHERE a.customer_id=%s AND e.created_at=%s::timestamp AND e.id=%s''',
                (customer_id, timestamp, item_id)):
                return None
            operator = '<' if direction == 'older' else '>'
            predicate = f' AND (e.created_at,e.id) {operator} (%s::timestamp,%s)'
            params += [timestamp, item_id]
        order = 'ASC' if direction == 'newer' else 'DESC'
        rows = self._rows('''SELECT to_char(e.created_at,'YYYY-MM-DD"T"HH24:MI:SS.US') AS occurred_at,
            2 AS rank,e.id AS item_id,NULL::text AS target_id,e.amount_micro,e.kind,
            COALESCE(e.source_id::text,'') AS reference FROM public.customer_usdt_balance_entries e
            JOIN public.customer_usdt_balance_accounts a ON a.id=e.customer_usdt_balance_account_id
            WHERE a.customer_id=%s''' + predicate + f' ORDER BY e.created_at {order},e.id {order} LIMIT {USDT_ACTIVITY_ROWS + 1}', params)
        for row in rows:
            row['occurred_micros'] = self._occurred_micros(row['occurred_at'])
        return rows

    def orders_for_balance_entries(self, customer_id, order_ids):
        if not order_ids:
            return {}
        rows = self._rows("""SELECT id,invoice_number FROM public.orders
                             WHERE customer_id=%s AND id = ANY(%s::uuid[])""",
                          (customer_id, [str(value) for value in order_ids]))
        return {str(row['id']): row['invoice_number'] for row in rows}

    def _product_rows(self, suffix="", params=()):
        return self._rows(_PRODUCT_SELECT + suffix, params)

    @staticmethod
    def _enrich_products(rows):
        for product in rows:
            family = {name: product.pop('family_' + name) for name in FAMILY_COLUMNS}
            if family['id'] is None:
                family = None
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

    def products(self):
        return self._enrich_products(self._product_rows())

    def product(self, product_id):
        rows = self._product_rows(" AND p.id=%s", (product_id,))
        return self._enrich_products(rows)[0] if rows else None

    def families(self):
        return self._rows("""SELECT id,name,slug,description,icon,custom_emoji_id,telegram_image_path,sort_order
                             FROM public.product_families WHERE deleted_at IS NULL ORDER BY sort_order,name,id""")

    def family(self, family_id):
        rows = self._enrich_products(
            self._product_rows(" AND p.product_family_id=%s", (family_id,)),
        )
        if not rows or rows[0]['family'] is None:
            return None
        return dict(rows[0]['family'], members=sorted(rows, key=self.member_key))

    @staticmethod
    def member_key(product):
        return (product.get('family_sort_order') or 0, product['name'].lower(), product['id'])

    def category_options(self):
        """Categories and the uncategorized flag in one read."""
        rows = self._rows("""SELECT c.id,c.name,c.slug,c.description,flags.has_uncategorized
            FROM (SELECT EXISTS (
              SELECT 1 FROM public.products p WHERE p.category_id IS NULL
                AND p.is_active=TRUE AND p.deleted_at IS NULL) AS has_uncategorized) flags
            LEFT JOIN public.categories c ON c.is_active=TRUE AND c.deleted_at IS NULL AND EXISTS (
              SELECT 1 FROM public.products p WHERE p.category_id=c.id AND p.is_active=TRUE AND p.deleted_at IS NULL)
            ORDER BY c.sort_order,c.name,c.id""")
        has_uncategorized = bool(rows[0]['has_uncategorized']) if rows else False
        categories = [
            {name: row[name] for name in CATEGORY_COLUMNS}
            for row in rows if row['id'] is not None
        ]
        return {'categories': categories, 'has_uncategorized': has_uncategorized}

    def categories(self):
        return self.category_options()['categories']

    def category(self, identity):
        field = 'id' if str(identity).isdigit() else 'slug'
        return self._one(f"""SELECT id,name,slug,description FROM public.categories
            WHERE {field}=%s AND is_active=TRUE AND deleted_at IS NULL""", (identity,))

    def browse(self, *, category=None, search=None, category_row=_UNSET):
        products = self.products()
        if category is not None:
            if category != 'uncategorized':
                if category_row is _UNSET:
                    category_row = self.category(category)
                category_id = category_row['id'] if category_row else None
            else:
                category_id = None
            products = [p for p in products if p['category_id'] == category_id]
        keyword = (search or '').strip().lower()
        def matches(row):
            return any(keyword in str(row.get(field) or '').lower() for field in (
                'name','slug','description','description_en','description_ms','family_option_label',
                'category_name','category_slug','product_type','access_duration','region','account_type',
                'device_limit','included_items','limitations','fulfillment_expectation','warranty_policy',
                'warranty_policy_en','warranty_policy_ms','support_policy'))
        families = {}
        for product in products:
            family = product.get('family')
            if family is not None:
                families.setdefault(family['id'], family)
        entries = []
        for family in families.values():
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

    def detail_flags(self):
        """Maintenance and Store-Balance availability in one round trip."""
        row = self._one("""SELECT
            COALESCE((SELECT is_active FROM public.checkout_maintenance_settings
                      WHERE singleton_key='checkout_maintenance'), FALSE) AS maintenance_active,
            (SELECT public_note FROM public.checkout_maintenance_settings
             WHERE singleton_key='checkout_maintenance') AS maintenance_note,
            (EXISTS (SELECT 1 FROM public.customer_usdt_funding_policies
                     WHERE singleton_key='customer_usdt_funding_policy')
             AND NOT EXISTS (SELECT 1 FROM public.customer_usdt_funding_pauses WHERE scope='spending')
             AND NOT EXISTS (SELECT 1 FROM public.balance_restore_gates
                             WHERE id=1 AND state='open')) AS spending_available""") or {}
        return {
            'maintenance': {'is_active': bool(row.get('maintenance_active')),
                            'public_note': row.get('maintenance_note')},
            'spending_available': bool(row.get('spending_available')),
        }

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

    def home_data(self, customer_id, bot_scope='synthetic'):
        """Home metrics, wallet, channel and banner/asset in one statement.

        These reads are independent and were previously seven round trips on
        every Home render. PHP serves Home from warm projections; the candidate
        keeps the reads live but does not pay one connection round trip each.
        """
        row = self._one("""
            WITH canonical AS (
              SELECT DISTINCT ON (order_id) order_id,amount,currency
              FROM public.payments
              WHERE status='paid' AND paid_at IS NOT NULL
              ORDER BY order_id,paid_at,id
            )
            SELECT
              (SELECT COUNT(*) FROM public.customers WHERE telegram_id IS NOT NULL) AS bot_users,
              (SELECT COUNT(*) FROM canonical c JOIN public.orders o ON o.id=c.order_id
               WHERE o.order_status IN ('processing','completed')) AS successful_orders,
              (SELECT COALESCE(SUM(CASE WHEN UPPER(COALESCE(NULLIF(TRIM(c.currency),''),'IDR'))='IDR'
                  THEN GREATEST(c.amount,0) ELSE 0 END),0)
               FROM canonical c JOIN public.orders o ON o.id=c.order_id
               WHERE o.customer_id=%s AND o.order_status IN ('processing','completed')) AS idr_total,
              (SELECT COALESCE(SUM(CASE WHEN UPPER(c.currency)='USDT'
                  THEN GREATEST(c.amount,0) ELSE 0 END),0)
               FROM canonical c JOIN public.orders o ON o.id=c.order_id
               WHERE o.customer_id=%s AND o.order_status IN ('processing','completed')) AS usdt_total,
              (SELECT a.balance FROM public.customer_balance_accounts a
               WHERE a.customer_id=%s) AS balance,
              (SELECT e.balance_after FROM public.store_balance_entries e
               JOIN public.customer_balance_accounts a ON a.id=e.customer_balance_account_id
               WHERE a.customer_id=%s ORDER BY e.id DESC LIMIT 1) AS latest_balance_after,
              (SELECT channel_link FROM public.store_channel_settings
               WHERE singleton_key='store_channel') AS channel_link,
              (SELECT channel_username FROM public.store_channel_settings
               WHERE singleton_key='store_channel') AS channel_username,
              (SELECT enabled FROM public.customer_api_settings
               WHERE singleton_key='customer_api') AS api_enabled,
              (SELECT telegram_welcome_banner_path FROM public.storefront_settings
               WHERE singleton_key='storefront') AS banner_path,
              (SELECT is_active FROM public.checkout_maintenance_settings
               WHERE singleton_key='checkout_maintenance') AS maintenance_active,
              (SELECT public_note FROM public.checkout_maintenance_settings
               WHERE singleton_key='checkout_maintenance') AS maintenance_note,
              (SELECT file_id FROM django_candidate.navigation_assets
               WHERE bot_scope=%s AND source_path=(
                 SELECT telegram_welcome_banner_path FROM public.storefront_settings
                 WHERE singleton_key='storefront')) AS banner_file_id,
              (SELECT version FROM django_candidate.navigation_assets
               WHERE bot_scope=%s AND source_path=(
                 SELECT telegram_welcome_banner_path FROM public.storefront_settings
                 WHERE singleton_key='storefront')) AS banner_version
        """, (customer_id, customer_id, customer_id, customer_id, bot_scope, bot_scope))
        amount = 0 if row['balance'] is None else row['balance']
        if row['balance'] is not None and (
                (row['latest_balance_after'] is None and amount != 0)
                or (row['latest_balance_after'] is not None and row['latest_balance_after'] != amount)):
            amount = None
        username = (row['channel_username'] or '').strip().lstrip('@')
        url = (row['channel_link'] or '').strip() or ('https://t.me/' + username if username else None)
        asset = None
        if row['banner_file_id'] is not None:
            asset = {'file_id': row['banner_file_id'], 'version': row['banner_version']}
        return {
            'bot_users': row['bot_users'],
            'successful_orders': row['successful_orders'],
            'idr_total': row['idr_total'],
            'usdt_total': row['usdt_total'],
            'balance': amount,
            'channel_url': url,
            'api_enabled': bool(row['api_enabled']),
            'banner_path': row['banner_path'],
            'banner_asset': asset,
            'maintenance': {'is_active': bool(row['maintenance_active']),
                            'public_note': row['maintenance_note']},
        }
