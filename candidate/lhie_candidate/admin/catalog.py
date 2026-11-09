"""Catalog reads and canonical admin mutations on the existing PHP tables."""

import hashlib
import json
import math

from django.db import DatabaseError
from django.http import Http404
from django.utils.text import slugify

from ..checkout.db import Database
from .db import (
    audit,
    available_stock_sql,
    bounded_transaction,
    local_available,
    local_item_count,
    unique_slug_conflict,
)
from .errors import AdminConflict, AdminValidationError
from .pricing import (
    PricingScheduleError,
    normalize_tier_rows,
    parse_base_prices,
    validate_schedule,
)
from .validation import (
    boolean,
    optional_multiline,
    optional_text,
    parse_featured_rank,
    parse_integer,
    raw,
    required_text,
    safe_media_path,
    safe_usage_guide_url,
)


DETAIL_FIELDS = (
    ("name", "Nama produk", "text", 255, True),
    ("description", "Deskripsi produk", "textarea", None, True),
    ("description_ms", "Deskripsi Melayu", "textarea", None, False),
    ("description_en", "Deskripsi English", "textarea", None, False),
    ("product_type", "Tipe produk", "text", 100, False),
    ("access_duration", "Durasi akses", "text", 100, False),
    ("region", "Region atau negara", "text", 120, False),
    ("account_type", "Tipe akun", "text", 100, False),
    ("device_limit", "Batas perangkat atau seat", "text", 100, False),
    ("included_items", "Item yang termasuk", "textarea", 2000, False),
    ("limitations", "Batasan", "textarea", 2000, False),
    ("fulfillment_expectation", "Ekspektasi pengiriman", "textarea", None, False),
    ("warranty_policy", "Replacement & Refund Policy", "textarea", None, False),
    ("warranty_policy_ms", "Kebijakan garansi Melayu", "textarea", None, False),
    ("warranty_policy_en", "Kebijakan garansi English", "textarea", None, False),
    ("support_policy", "Kebijakan support", "textarea", None, False),
    ("delivery_note", "Catatan pengiriman rahasia", "textarea", 2000, False),
    ("usage_guide_title", "Judul panduan", "text", 160, False),
    ("usage_guide_url", "URL panduan", "text", 2048, False),
)

DETAIL_KEYS = tuple(field[0] for field in DETAIL_FIELDS)
PRICE_KEYS = ("price_amount", "usdt_price_amount", "reseller_price_amount", "reseller_usdt_price_amount")
APPEARANCE_KEYS = (
    "icon", "custom_emoji_id", "is_active", "featured_rank",
    "image_path", "telegram_image_path", "telegram_channel_image_path",
)
FAMILY_KEYS = ("product_family_id", "family_sort_order", "family_option_label")

SORTS = {
    "name_asc": "Nama A-Z",
    "name_desc": "Nama Z-A",
    "price_asc": "Harga terendah",
    "price_desc": "Harga tertinggi",
    "newest": "Terbaru",
    "stock_asc": "Stok terendah",
    "stock_desc": "Stok tertinggi",
}

PRODUCT_TEXT_MAXIMUM = 100_000


def _clean_text(errors, data, key, label, maximum, required=False):
    if maximum is None:
        maximum = PRODUCT_TEXT_MAXIMUM
    if key in {"included_items", "limitations"}:
        value = optional_multiline(errors, data, key, label)
        if value is not None and len(value) > maximum:
            errors[key] = [f"{label} maksimal {maximum} karakter."]
        return value
    return (required_text(errors, data, key, label, maximum) if required
            else optional_text(errors, data, key, label, maximum))


def _check_errors(errors):
    if errors:
        raise AdminValidationError(errors)



class CatalogService:
    def __init__(self, db=None, *, price_events=None):
        self.db = db or Database()
        self.price_events = price_events

    # -- read models -----------------------------------------------------

    def dashboard_stats(self):
        row = self.db.one(
            f"""SELECT
              (SELECT COUNT(*) FROM public.products WHERE deleted_at IS NULL) AS product_count,
              (SELECT COUNT(*) FROM public.products WHERE deleted_at IS NULL AND is_active=TRUE) AS active_product_count,
              (SELECT COUNT(*) FROM public.categories WHERE deleted_at IS NULL) AS category_count,
              (SELECT COUNT(*) FROM public.product_families WHERE deleted_at IS NULL) AS family_count,
              (SELECT COUNT(*) FROM public.product_stock_items s
                 JOIN public.products p ON p.id=s.product_id AND p.deleted_at IS NULL
                 WHERE s.supplier_purchase_id IS NULL AND s.supplier_unit_index IS NULL
                   AND s.order_item_id IS NULL AND s.retired_at IS NULL) AS available_item_count,
              (SELECT COUNT(*) FROM public.product_stock_items s
                 JOIN public.products p ON p.id=s.product_id AND p.deleted_at IS NULL
                 WHERE s.supplier_purchase_id IS NULL AND s.supplier_unit_index IS NULL
                   AND s.retired_at IS NOT NULL) AS retired_item_count,
              (SELECT COUNT(*) FROM public.products p
                 WHERE p.deleted_at IS NULL AND p.is_active=TRUE
                   AND {available_stock_sql('p')} <= 0) AS sold_out_product_count"""
        )
        return {key: int(value or 0) for key, value in row.items()}

    def product_groups(self, *, search="", sort="name_asc", page=1, per_page=20):
        search = " ".join(str(search or "").split())[:255]
        sort = sort if sort in SORTS else "name_asc"
        page = max(1, int(page or 1))
        where, params = "p.deleted_at IS NULL", []
        if search:
            where += """ AND (
              p.name ILIKE %s OR p.description ILIKE %s OR c.name ILIKE %s
              OR f.name ILIKE %s OR f.slug ILIKE %s)"""
            needle = "%" + search.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_") + "%"
            params.extend([needle] * 5)
        order = {
            "name_asc": "LOWER(name) ASC, group_key ASC",
            "name_desc": "LOWER(name) DESC, group_key ASC",
            "price_asc": "min_price ASC, LOWER(name) ASC",
            "price_desc": "max_price DESC, LOWER(name) ASC",
            "newest": "created DESC NULLS LAST, group_key ASC",
            "stock_asc": "available_sum ASC, LOWER(name) ASC",
            "stock_desc": "available_sum DESC, LOWER(name) ASC",
        }[sort]
        rows = self.db.rows(
            f"""WITH filtered AS (
                  SELECT p.id,p.name,p.price_amount,p.created_at,
                    CASE WHEN f.id IS NOT NULL THEN 'f:'||p.product_family_id
                         ELSE 'p:'||p.id END AS group_key,
                    CASE WHEN f.id IS NOT NULL THEN f.name ELSE p.name END AS group_name,
                    {available_stock_sql('p')} AS available
                  FROM public.products p
                  LEFT JOIN public.categories c ON c.id=p.category_id AND c.deleted_at IS NULL
                  LEFT JOIN public.product_families f
                    ON f.id=p.product_family_id AND f.deleted_at IS NULL
                  WHERE {where}
                ), grouped AS (
                  SELECT group_key,MIN(group_name) AS name,COUNT(*) AS member_count,
                    MIN(price_amount) AS min_price,MAX(price_amount) AS max_price,
                    SUM(available) AS available_sum,
                    COUNT(*) FILTER (WHERE available <= 0) AS sold_out_count,
                    MAX(created_at) AS created
                  FROM filtered GROUP BY group_key
                )
                SELECT *,COUNT(*) OVER() AS total FROM grouped
                ORDER BY {order} LIMIT %s OFFSET %s""",
            (*params, per_page, (page - 1) * per_page),
        )
        total = int(rows[0]["total"]) if rows else 0
        if not rows and page > 1:
            total = int(self.db.one(
                f"""WITH filtered AS (
                      SELECT CASE WHEN f.id IS NOT NULL THEN 'f:'||p.product_family_id
                                  ELSE 'p:'||p.id END AS group_key
                      FROM public.products p
                      LEFT JOIN public.categories c ON c.id=p.category_id AND c.deleted_at IS NULL
                      LEFT JOIN public.product_families f
                        ON f.id=p.product_family_id AND f.deleted_at IS NULL
                      WHERE {where})
                    SELECT COUNT(DISTINCT group_key) AS total FROM filtered""",
                tuple(params),
            )["total"] or 0)
        pages = max(1, math.ceil(total / per_page))
        if page > pages:
            return self.product_groups(search=search, sort=sort, page=pages, per_page=per_page)
        keys = [row["group_key"] for row in rows]
        families = {}
        family_ids = [int(key.split(":", 1)[1]) for key in keys if key.startswith("f:")]
        if family_ids:
            families = {int(item["id"]): item for item in self.db.rows(
                """SELECT id,name,slug,description,icon,sort_order,image_path
                   FROM public.product_families
                   WHERE id=ANY(%s) AND deleted_at IS NULL""", (family_ids,))}
        members = {}
        if keys:
            for row in self.db.rows(
                f"""WITH ranked AS (
                      SELECT p.id,p.name,p.icon,p.price_amount,p.usdt_price_amount,
                        p.reseller_price_amount,p.is_active,p.stock,p.reserved_stock,
                        p.image_path,p.product_family_id,p.family_sort_order,
                        p.family_option_label,p.category_id,c.name AS category_name,
                        CASE WHEN f.id IS NOT NULL THEN 'f:'||p.product_family_id
                             ELSE 'p:'||p.id END AS group_key,
                        {available_stock_sql('p')} AS available,
                        ROW_NUMBER() OVER (
                          PARTITION BY CASE WHEN f.id IS NOT NULL THEN 'f:'||p.product_family_id
                                            ELSE 'p:'||p.id END
                          ORDER BY p.family_sort_order,p.name,p.id) AS rank
                      FROM public.products p
                      LEFT JOIN public.categories c ON c.id=p.category_id AND c.deleted_at IS NULL
                      LEFT JOIN public.product_families f
                        ON f.id=p.product_family_id AND f.deleted_at IS NULL
                      WHERE p.deleted_at IS NULL
                        AND (CASE WHEN f.id IS NOT NULL THEN 'f:'||p.product_family_id
                                  ELSE 'p:'||p.id END) = ANY(%s))
                    SELECT * FROM ranked WHERE rank <= 50 ORDER BY group_key,rank""",
                (keys,),
            ):
                members.setdefault(row["group_key"], []).append(row)
        groups, page_groups = [], []
        for row in rows:
            key = row["group_key"]
            family_id = int(key.split(":", 1)[1]) if key.startswith("f:") else None
            family = families.get(family_id) if family_id else None
            items = members.get(key, [])
            name = family["name"] if family else (items[0]["name"] if items else row["name"])
            category = items[0]["category_name"] if items else None
            group = {
                "key": key,
                "family_id": family_id,
                "family": family,
                "name": name,
                "category": category or "Tanpa kategori",
                "member_count": int(row["member_count"]),
                "shown_members": len(items),
                "truncated": int(row["member_count"]) > len(items),
                "minimum_price": int(row["min_price"]),
                "maximum_price": int(row["max_price"]),
                "available_sum": int(row["available_sum"] or 0),
                "sold_out_count": int(row["sold_out_count"] or 0),
                "members": items,
                "is_single": family_id is None,
            }
            groups.append(group)
        offset = (page - 1) * per_page
        page_groups = groups
        previous_url = self._page_url(page - 1, search, sort) if page > 1 else None
        next_url = self._page_url(page + 1, search, sort) if page < pages else None
        return {
            "groups": page_groups,
            "search": search,
            "sort": sort,
            "sorts": SORTS,
            "pagination": {"current": page, "pages": pages, "total": total,
                           "previous": previous_url, "next": next_url},
        }

    @staticmethod
    def _page_url(page, search="", sort="name_asc"):
        from urllib.parse import urlencode
        values = {"tab": "produk", "products_page": page, "product_sort": sort}
        if search:
            values["product_search"] = search
        return "/admin?" + urlencode(values)

    def product(self, product_id, *, include_deleted=False):
        suffix = "" if include_deleted else " AND p.deleted_at IS NULL"
        row = self.db.one(
            f"""SELECT p.*,c.name AS category_name,c.slug AS category_slug,
                       f.name AS family_name
                FROM public.products p
                LEFT JOIN public.categories c ON c.id=p.category_id AND c.deleted_at IS NULL
                LEFT JOIN public.product_families f
                  ON f.id=p.product_family_id AND f.deleted_at IS NULL
                WHERE p.id=%s{suffix}""",
            (product_id,),
        )
        if row is None:
            raise Http404
        return row

    def tiers(self, product_id):
        return self.db.rows(
            """SELECT minimum_quantity,price_amount,usdt_price_amount
               FROM public.product_quantity_price_tiers
               WHERE product_id=%s ORDER BY minimum_quantity,id""",
            (product_id,),
        )

    def options(self):
        return {
            "categories": self.db.rows(
                "SELECT id,name FROM public.categories WHERE deleted_at IS NULL ORDER BY name,id"
            ),
            "families": self.db.rows(
                """SELECT id,name,slug FROM public.product_families
                   WHERE deleted_at IS NULL ORDER BY name,id"""
            ),
        }

    def stock_summary(self, product_id):
        row = self.db.one(
            """SELECT COUNT(*) FILTER (WHERE supplier_purchase_id IS NULL
                   AND supplier_unit_index IS NULL) AS total,
                 COUNT(*) FILTER (WHERE supplier_purchase_id IS NULL
                   AND supplier_unit_index IS NULL AND order_item_id IS NULL
                   AND retired_at IS NULL) AS available,
                 COUNT(*) FILTER (WHERE supplier_purchase_id IS NULL
                   AND supplier_unit_index IS NULL AND order_item_id IS NOT NULL
                   AND retired_at IS NULL) AS allocated,
                 COUNT(*) FILTER (WHERE supplier_purchase_id IS NULL
                   AND supplier_unit_index IS NULL AND retired_at IS NOT NULL) AS retired
               FROM public.product_stock_items WHERE product_id=%s""",
            (product_id,),
        )
        return {key: int(value or 0) for key, value in row.items()}

    def stock_items(self, product_id, *, limit=50):
        rows = self.db.rows(
            """SELECT s.id,s.order_item_id,s.supplier_purchase_id,s.retired_at,
                      s.credential_format,
                      (s.credential_format='legacy' AND s.secret IS NOT NULL
                        AND s.credential_payload IS NULL)
                      OR (s.credential_format='structured_account' AND s.secret IS NULL
                          AND s.credential_payload IS NOT NULL) AS credential_valid,
                      o.invoice_number,o.order_status,o.payment_status
               FROM public.product_stock_items s
               LEFT JOIN public.order_items i ON i.id=s.order_item_id
               LEFT JOIN public.orders o ON o.id=i.order_id
               WHERE s.product_id=%s AND s.supplier_purchase_id IS NULL
                 AND s.supplier_unit_index IS NULL
               ORDER BY CASE
                 WHEN s.order_item_id IS NULL THEN 0
                 WHEN o.payment_status='paid' THEN 3
                 WHEN o.order_status='pending_payment' THEN 1
                 ELSE 2 END, s.id DESC
               LIMIT %s""",
            (product_id, limit + 1),
        )
        truncated = len(rows) > limit
        result = []
        for row in rows[:limit]:
            format_value = row["credential_format"] or "legacy"
            if not row["credential_valid"]:
                status, label = "repair", "Perlu diperbaiki"
            elif row["retired_at"] is not None:
                status, label = "retired", "Retired"
            elif row["order_item_id"] is None:
                status, label = "available", "Tersedia"
            elif row["payment_status"] == "paid":
                status, label = "paid", "Terjual"
            elif row["order_status"] == "pending_payment":
                status, label = "reserved", "Dipesan"
            elif row["order_status"] in ("expired", "cancelled"):
                status, label = "repair", "Perlu rilis"
            else:
                status, label = "allocated", "Terpakai"
            row.update({"format": format_value, "status": status,
                        "status_label": label, "editable": status == "available"})
            result.append(row)
        return result, truncated

    def versions(self, product):
        tiers = [
            {"minimum_quantity": int(item["minimum_quantity"]),
             "price_amount": int(item["price_amount"]),
             "usdt_price_amount": None if item["usdt_price_amount"] is None
             else int(item["usdt_price_amount"])}
            for item in self.tiers(product["id"])
        ]
        return {
            "detail": self._version({**{key: product.get(key) for key in DETAIL_KEYS},
                                     "category_id": product.get("category_id")}),
            "price": self._version({**{key: product.get(key) for key in PRICE_KEYS},
                                    "quantity_price_tiers": tiers}),
            "appearance": self._version({key: product.get(key) for key in APPEARANCE_KEYS}),
            "family": self.membership_version(product),
        }, tiers

    @staticmethod
    def _version(values):
        encoded = json.dumps(values, ensure_ascii=False, sort_keys=True,
                             separators=(",", ":"), default=str)
        return hashlib.sha256(encoded.encode("utf-8")).hexdigest()

    def membership_version(self, product):
        return self._version({
            "product_family_id": product.get("product_family_id"),
            "family_sort_order": int(product.get("family_sort_order") or 0),
            "family_option_label": product.get("family_option_label"),
        })

    def workspace(self, product_id):
        product = self.product(product_id)
        versions, tiers = self.versions(product)
        stock_items, truncated = self.stock_items(product_id)
        summary = self.stock_summary(product_id)
        counts = local_item_count(self.db, product_id)
        return {
            "product": product,
            "tiers": tiers,
            "versions": versions,
            "options": self.options(),
            "stock_summary": summary,
            "local_available": local_available(self.db, product),
            "available_item_count": counts["available"],
            "stock_items": stock_items,
            "stock_items_truncated": truncated,
            "available_stock": int(product["stock"]) - int(product["reserved_stock"]),
        }

    # -- categories ------------------------------------------------------

    def create_category(self, data, actor_id, ip=None):
        errors = {}
        name = required_text(errors, data, "category_name", "Nama kategori", 120)
        description = optional_text(errors, data, "category_description", "Deskripsi kategori", 500)
        _check_errors(errors)

        def operation():
            slug = self._unique_slug("categories", slugify(name) or "kategori")
            row = self.db.one(
                """INSERT INTO public.categories
                   (name,slug,description,banner_image_path,sort_order,is_active,created_at,updated_at)
                   VALUES (%s,%s,%s,NULL,0,TRUE,
                     CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                     CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')
                   RETURNING id,name,slug""",
                (name, slug, description),
            )
            audit(self.db, actor_id, "category.created", "App\\Models\\Category", row["id"],
                  None, {"name": row["name"], "slug": row["slug"], "description": description,
                         "sort_order": 0, "is_active": True}, ip)
            return int(row["id"])

        for attempt in range(5):
            try:
                return bounded_transaction(operation)
            except DatabaseError as error:
                if unique_slug_conflict(error) and attempt < 4:
                    continue
                raise

    def update_category(self, category_id, data, actor_id, ip=None):
        errors = {}
        name = required_text(errors, data, "name", "Nama kategori", 120)
        description = optional_text(errors, data, "description", "Deskripsi kategori", 500)
        sort_order = parse_integer(errors, data, "sort_order", "Urutan tampil",
                                   minimum=0, maximum=999999, required=True)
        is_active = boolean(data, "is_active")
        _check_errors(errors)

        def operation():
            row = self.db.one(
                """SELECT * FROM public.categories WHERE id=%s AND deleted_at IS NULL
                   FOR UPDATE""", (category_id,))
            if row is None:
                raise Http404
            old, new = {}, {}
            for key, value in (("name", name), ("description", description),
                               ("sort_order", sort_order), ("is_active", is_active)):
                if row.get(key) != value:
                    old[key], new[key] = row.get(key), value
            if new:
                self.db.execute(
                    """UPDATE public.categories SET name=%s,description=%s,sort_order=%s,
                       is_active=%s,updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                       WHERE id=%s""",
                    (name, description, sort_order, is_active, category_id),
                )
                audit(self.db, actor_id, "category.updated", "App\\Models\\Category",
                      category_id, old, new, ip)

        return bounded_transaction(operation)

    def delete_category(self, category_id, actor_id, ip=None):
        def operation():
            family_ids = [int(row["product_family_id"]) for row in self.db.rows(
                """SELECT DISTINCT product_family_id FROM public.products
                   WHERE category_id=%s AND product_family_id IS NOT NULL
                   ORDER BY product_family_id""", (category_id,))]
            if family_ids:
                self.db.rows(
                    "SELECT id FROM public.product_families WHERE id=ANY(%s) ORDER BY id FOR UPDATE",
                    (family_ids,))
            product_ids = [int(row["id"]) for row in self.db.rows(
                "SELECT id FROM public.products WHERE category_id=%s ORDER BY id FOR UPDATE",
                (category_id,))]
            category = self.db.one(
                "SELECT * FROM public.categories WHERE id=%s AND deleted_at IS NULL FOR UPDATE",
                (category_id,))
            if category is None:
                raise Http404
            product_count = self.db.execute(
                """UPDATE public.products SET category_id=NULL,
                   updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                   WHERE category_id=%s""", (category_id,))
            self.db.execute(
                """UPDATE public.categories SET deleted_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                   updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s""",
                (category_id,))
            audit(self.db, actor_id, "category.deleted", "App\\Models\\Category",
                  category_id,
                  {"id": category_id, "name": category["name"], "slug": category["slug"],
                   "description": category["description"], "sort_order": category["sort_order"],
                   "is_active": category["is_active"], "affected_product_count": product_count},
                  None, ip)
            return product_count

        return bounded_transaction(operation)

    # -- families --------------------------------------------------------

    def create_family(self, data, actor_id, ip=None):
        errors = {}
        name = required_text(errors, data, "name", "Nama Product Family", 160)
        description = optional_text(errors, data, "description", "Deskripsi family", PRODUCT_TEXT_MAXIMUM)
        icon = optional_text(errors, data, "icon", "Ikon family", 32)
        custom_emoji_id = optional_text(errors, data, "custom_emoji_id", "ID emoji family", 255)
        sort_order = parse_integer(errors, data, "sort_order", "Urutan Product Family",
                                   minimum=0, maximum=999999, required=True)
        image_path = self._media_path(errors, data, "image_path", "Gambar family")
        telegram_image_path = self._media_path(errors, data, "telegram_image_path", "Gambar Telegram family")
        _check_errors(errors)

        def operation():
            slug = self._unique_slug("product_families", slugify(name) or "product-family",
                                     include_redirects=True)
            row = self.db.one(
                """INSERT INTO public.product_families
                   (name,slug,description,icon,custom_emoji_id,image_path,telegram_image_path,
                    sort_order,created_at,updated_at)
                   VALUES (%s,%s,%s,%s,%s,%s,%s,%s,
                     CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                     CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')
                   RETURNING id,name,slug""",
                (name, slug, description, icon, custom_emoji_id, image_path,
                 telegram_image_path, sort_order),
            )
            audit(self.db, actor_id, "product_family.created", "App\\Models\\ProductFamily",
                  row["id"], None,
                  {"name": row["name"], "slug": row["slug"], "description": description,
                   "icon": icon, "sort_order": sort_order}, ip)
            return int(row["id"])

        for attempt in range(5):
            try:
                return bounded_transaction(operation)
            except DatabaseError as error:
                if unique_slug_conflict(error) and attempt < 4:
                    continue
                raise

    def update_family(self, family_id, data, actor_id, ip=None):
        errors = {}
        name = required_text(errors, data, "name", "Nama Product Family", 160)
        description = optional_text(errors, data, "description", "Deskripsi family", PRODUCT_TEXT_MAXIMUM)
        icon = optional_text(errors, data, "icon", "Ikon family", 32)
        custom_emoji_id = optional_text(errors, data, "custom_emoji_id", "ID emoji family", 255)
        sort_order = parse_integer(errors, data, "sort_order", "Urutan Product Family",
                                   minimum=0, maximum=999999, required=True)
        slug_value = optional_text(errors, data, "slug", "Slug family", 160)
        image_path = self._media_path(errors, data, "image_path", "Gambar family")
        telegram_image_path = self._media_path(errors, data, "telegram_image_path", "Gambar Telegram family")
        _check_errors(errors)

        def operation():
            family = self.db.one(
                """SELECT * FROM public.product_families WHERE id=%s AND deleted_at IS NULL
                   FOR UPDATE""", (family_id,))
            if family is None:
                raise Http404
            new_slug = slugify(slug_value) if slug_value else family["slug"]
            if not new_slug:
                raise AdminValidationError({"slug": ["Slug harus berisi huruf atau angka."]})
            if new_slug != family["slug"]:
                if self.db.one(
                    """SELECT id FROM public.product_families
                       WHERE slug=%s AND id<>%s FOR UPDATE""", (new_slug, family_id)):
                    raise AdminValidationError({"slug": ["Slug sudah digunakan family lain."]})
                alias = self.db.one(
                    "SELECT product_family_id FROM public.product_family_slug_redirects "
                    "WHERE old_slug=%s FOR UPDATE", (new_slug,))
                if alias is not None and int(alias["product_family_id"]) != family_id:
                    raise AdminValidationError({"slug": ["Slug masih menjadi alamat lama family lain."]})
                old_alias = self.db.one(
                    "SELECT product_family_id FROM public.product_family_slug_redirects "
                    "WHERE old_slug=%s FOR UPDATE", (family["slug"],))
                if old_alias is not None and int(old_alias["product_family_id"]) != family_id:
                    raise AdminValidationError({"slug": ["Slug saat ini masih menjadi alamat lama family lain."]})
                if alias is not None:
                    self.db.execute(
                        "DELETE FROM public.product_family_slug_redirects WHERE old_slug=%s",
                        (new_slug,))
                self.db.execute(
                    """INSERT INTO public.product_family_slug_redirects
                       (old_slug,product_family_id,created_at,updated_at)
                       VALUES (%s,%s,CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                         CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')
                       ON CONFLICT (old_slug) DO UPDATE SET product_family_id=EXCLUDED.product_family_id,
                         updated_at=EXCLUDED.updated_at""",
                    (family["slug"], family_id))
                audit(self.db, actor_id, "product_family.slug_changed",
                      "App\\Models\\ProductFamily", family_id,
                      {"slug": family["slug"]},
                      {"slug": new_slug, "old_slug_alias": family["slug"]}, ip)
            old, new = {}, {}
            for key, value in (("name", name), ("description", description),
                               ("icon", icon), ("custom_emoji_id", custom_emoji_id),
                               ("sort_order", sort_order), ("slug", new_slug),
                               ("image_path", image_path),
                               ("telegram_image_path", telegram_image_path)):
                if family.get(key) != value:
                    old[key], new[key] = family.get(key), value
            if new:
                self.db.execute(
                    """UPDATE public.product_families SET name=%s,slug=%s,description=%s,
                       icon=%s,custom_emoji_id=%s,sort_order=%s,image_path=%s,
                       telegram_image_path=%s,
                       updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                       WHERE id=%s""",
                    (name, new_slug, description, icon, custom_emoji_id, sort_order,
                     image_path, telegram_image_path, family_id))
                audit(self.db, actor_id, "product_family.updated",
                      "App\\Models\\ProductFamily", family_id, old, new, ip)

        return bounded_transaction(operation)

    def delete_family(self, family_id, actor_id, ip=None):
        def operation():
            family = self.db.one(
                """SELECT * FROM public.product_families WHERE id=%s AND deleted_at IS NULL
                   FOR UPDATE""", (family_id,))
            if family is None:
                raise Http404
            product_ids = [int(row["id"]) for row in self.db.rows(
                "SELECT id FROM public.products WHERE product_family_id=%s ORDER BY id FOR UPDATE",
                (family_id,))]
            product_count = self.db.execute(
                """UPDATE public.products SET product_family_id=NULL,family_sort_order=0,
                   family_option_label=NULL,
                   updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                   WHERE product_family_id=%s""", (family_id,))
            self.db.execute(
                """UPDATE public.product_families
                   SET deleted_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                   updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s""",
                (family_id,))
            audit(self.db, actor_id, "product_family.deleted", "App\\Models\\ProductFamily",
                  family_id,
                  {"id": family_id, "name": family["name"], "slug": family["slug"],
                   "description": family["description"], "sort_order": family["sort_order"],
                   "affected_product_count": product_count},
                  None, ip)
            return product_count

        return bounded_transaction(operation)

    # -- products --------------------------------------------------------

    def create_product(self, data, actor_id, ip=None):
        errors = {}
        detail = self._detail_values(data, errors)
        idr, usdt, reseller_idr, reseller_usdt = parse_base_prices(data, errors)
        icon = optional_text(errors, data, "icon", "Ikon katalog", 32)
        custom_emoji_id = optional_text(errors, data, "custom_emoji_id", "ID emoji katalog", 255)
        featured_rank = parse_featured_rank(errors, data)
        family_id = self._optional_id(errors, data, "product_family_id", "Product Family")
        family_sort = parse_integer(errors, data, "family_sort_order", "Urutan family",
                                    minimum=0, maximum=999999) or 0
        family_label = optional_text(errors, data, "family_option_label", "Label pilihan family", 255)
        category_id = self._optional_id(errors, data, "category_id", "Kategori")
        _check_errors(errors)
        if family_id is None:
            family_sort, family_label = 0, None

        def operation():
            self._lock_family_and_category(family_id, category_id, None)
            if category_id is not None and self.db.one(
                "SELECT id FROM public.categories WHERE id=%s AND deleted_at IS NULL",
                (category_id,),
            ) is None:
                raise AdminValidationError({"category_id": ["Kategori tidak valid."]})
            self._assert_family_compatible(family_id, category_id, None)
            row = self.db.one(
                """INSERT INTO public.products
                   (category_id,product_family_id,family_sort_order,family_option_label,
                    name,icon,custom_emoji_id,description,description_ms,description_en,
                    product_type,access_duration,region,account_type,device_limit,
                    included_items,limitations,fulfillment_expectation,warranty_policy,
                    warranty_policy_ms,warranty_policy_en,support_policy,delivery_note,
                    usage_guide_title,usage_guide_url,price_amount,usdt_price_amount,
                    reseller_price_amount,reseller_usdt_price_amount,currency,stock,
                    reserved_stock,is_active,featured_rank,created_at,updated_at)
                   VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,
                     %s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'IDR',0,0,TRUE,%s,
                     CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                     CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')
                   RETURNING id""",
                (
                    category_id, family_id, family_sort, family_label,
                    detail["name"], icon, custom_emoji_id,
                    detail["description"], detail["description_ms"], detail["description_en"],
                    detail["product_type"], detail["access_duration"], detail["region"],
                    detail["account_type"], detail["device_limit"], detail["included_items"],
                    detail["limitations"], detail["fulfillment_expectation"],
                    detail["warranty_policy"], detail["warranty_policy_ms"],
                    detail["warranty_policy_en"], detail["support_policy"],
                    detail["delivery_note"], detail["usage_guide_title"],
                    detail["usage_guide_url"], idr, usdt, reseller_idr, reseller_usdt,
                    featured_rank,
                ),
            )
            audit(self.db, actor_id, "product.created", "App\\Models\\Product", row["id"],
                  None, self._audit_product(detail, idr, usdt, reseller_idr, reseller_usdt,
                                            category_id, family_id, icon, featured_rank), ip)
            return int(row["id"])

        return bounded_transaction(operation)

    def update_product(self, product_id, data, actor_id, ip=None, *, section):
        if section not in {"detail", "price", "appearance"}:
            raise AdminValidationError({"section": ["Bagian produk tidak valid."]})
        errors = {}
        if section == "detail":
            detail = self._detail_values(data, errors)
            category_id = self._optional_id(errors, data, "category_id", "Kategori")
            _check_errors(errors)
            values = detail | {"category_id": category_id}
        elif section == "price":
            idr, usdt, reseller_idr, reseller_usdt = parse_base_prices(data, errors)
            tiers = normalize_tier_rows(data, errors)
            if not errors:
                try:
                    validate_schedule(idr, usdt, tiers)
                except PricingScheduleError as error:
                    errors["quantity_price_tiers"] = [error.message]
            _check_errors(errors)
            values = {"price_amount": idr, "usdt_price_amount": usdt,
                      "reseller_price_amount": reseller_idr,
                      "reseller_usdt_price_amount": reseller_usdt}
        else:
            icon = optional_text(errors, data, "icon", "Ikon katalog", 32)
            custom_emoji_id = optional_text(errors, data, "custom_emoji_id", "ID emoji katalog", 255)
            featured_rank = parse_featured_rank(errors, data)
            image_path = self._media_path(errors, data, "image_path", "Gambar produk")
            telegram_image_path = self._media_path(errors, data, "telegram_image_path", "Gambar Telegram")
            telegram_channel_image_path = self._media_path(
                errors, data, "telegram_channel_image_path", "Poster channel")
            is_active = boolean(data, "is_active")
            _check_errors(errors)
            values = {"icon": icon, "custom_emoji_id": custom_emoji_id,
                      "is_active": is_active, "featured_rank": featured_rank,
                      "image_path": image_path,
                      "telegram_image_path": telegram_image_path,
                      "telegram_channel_image_path": telegram_channel_image_path}
        expected_version = raw(data, "workspace_version", "")
        if not isinstance(expected_version, str) or len(expected_version) != 64:
            raise AdminValidationError({"workspace_version": ["Versi bagian tidak valid. Muat ulang halaman."]})

        def operation():
            current = self.product(product_id)
            if section == "detail":
                self._lock_family_and_category(
                    current.get("product_family_id"), current.get("category_id"),
                    product_id, target_category_id=values["category_id"])
                if values["category_id"] is not None and self.db.one(
                    "SELECT id FROM public.categories WHERE id=%s AND deleted_at IS NULL",
                    (values["category_id"],),
                ) is None:
                    raise AdminValidationError({"category_id": ["Kategori tidak valid."]})
                if current.get("product_family_id"):
                    self._assert_family_compatible(
                        current["product_family_id"], values["category_id"], product_id)
            else:
                self.db.rows(
                    "SELECT id FROM public.products WHERE id=%s ORDER BY id FOR UPDATE",
                    (product_id,))
            current = self.db.one(
                "SELECT * FROM public.products WHERE id=%s AND deleted_at IS NULL FOR UPDATE",
                (product_id,))
            if current is None:
                raise Http404
            current_tiers = self.tiers(product_id)
            version_values = {
                "detail": {key: current.get(key) for key in DETAIL_KEYS} | {
                    "category_id": current.get("category_id")},
                "price": {**{key: current.get(key) for key in PRICE_KEYS},
                          "quantity_price_tiers": [
                              {"minimum_quantity": int(item["minimum_quantity"]),
                               "price_amount": int(item["price_amount"]),
                               "usdt_price_amount": None if item["usdt_price_amount"] is None
                               else int(item["usdt_price_amount"])}
                              for item in current_tiers]},
                "appearance": {key: current.get(key) for key in APPEARANCE_KEYS},
            }[section]
            actual = self._version(version_values)
            if actual != expected_version:
                raise AdminConflict()
            old, new = {}, {}
            for key, value in values.items():
                if current.get(key) != value:
                    old[key], new[key] = current.get(key), value
            if new:
                assignments = ",".join(f"{key}=%s" for key in new)
                params = list(new.values()) + [product_id]
                self.db.execute(
                    f"""UPDATE public.products SET {assignments},
                        updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                        WHERE id=%s""", params)
            if section == "price":
                if self.price_events is not None:
                    self.price_events.record_change(current, current | values)
                old["quantity_price_tiers"] = [
                    {"minimum_quantity": int(item["minimum_quantity"]),
                     "price_amount": int(item["price_amount"]),
                     "usdt_price_amount": None if item["usdt_price_amount"] is None
                     else int(item["usdt_price_amount"])} for item in current_tiers]
                new["quantity_price_tiers"] = tiers
                self.db.execute(
                    "DELETE FROM public.product_quantity_price_tiers WHERE product_id=%s",
                    (product_id,))
                for tier in tiers:
                    self.db.execute(
                        """INSERT INTO public.product_quantity_price_tiers
                           (product_id,minimum_quantity,price_amount,usdt_price_amount,
                            created_at,updated_at)
                           VALUES (%s,%s,%s,%s,
                             CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                             CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')""",
                        (product_id, tier["minimum_quantity"], tier["price_amount"],
                         tier["usdt_price_amount"]))
            if old or new:
                audit(self.db, actor_id, "product.updated", "App\\Models\\Product",
                      product_id, old, new, ip)

        return bounded_transaction(operation)

    def delete_product(self, product_id, actor_id, ip=None):
        def operation():
            row = self.db.one(
                "SELECT * FROM public.products WHERE id=%s AND deleted_at IS NULL FOR UPDATE",
                (product_id,))
            if row is None:
                raise Http404
            self.db.execute(
                """UPDATE public.products
                   SET deleted_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                       updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                   WHERE id=%s""", (product_id,))
            audit(self.db, actor_id, "product.deleted", "App\\Models\\Product",
                  product_id, {"id": product_id, "name": row["name"]}, None, ip)

        return bounded_transaction(operation)

    def update_visibility(self, product_id, is_active, actor_id, ip=None):
        def operation():
            row = self.db.one(
                "SELECT id,is_active FROM public.products WHERE id=%s AND deleted_at IS NULL FOR UPDATE",
                (product_id,))
            if row is None:
                raise Http404
            active = bool(is_active)
            if bool(row["is_active"]) != active:
                self.db.execute(
                    """UPDATE public.products SET is_active=%s,
                       updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s""",
                    (active, product_id))
                audit(self.db, actor_id, "product.visibility_updated", "App\\Models\\Product",
                      product_id, {"is_active": bool(row["is_active"])},
                      {"is_active": active}, ip)

        return bounded_transaction(operation)

    def update_membership(self, product_id, data, actor_id, ip=None):
        errors = {}
        family_id = self._optional_id(errors, data, "product_family_id", "Product Family")
        sort_order = parse_integer(errors, data, "family_sort_order", "Urutan family",
                                   minimum=0, maximum=999999) or 0
        option_label = optional_text(errors, data, "family_option_label", "Label pilihan family", 255)
        expected_version = raw(data, "workspace_version", "")
        if not isinstance(expected_version, str) or len(expected_version) != 64:
            errors["workspace_version"] = ["Versi grouping tidak valid. Muat ulang halaman."]
        _check_errors(errors)

        def operation():
            current = self.product(product_id)
            self._lock_family_and_category(
                current.get("product_family_id"), current.get("category_id"),
                product_id, target_family_id=family_id)
            locked = self.db.one(
                "SELECT * FROM public.products WHERE id=%s AND deleted_at IS NULL FOR UPDATE",
                (product_id,))
            if locked is None:
                raise Http404
            if self.membership_version(locked) != expected_version:
                raise AdminConflict()
            target_family = family_id
            if target_family is not None:
                self._assert_family_compatible(
                    target_family, locked["category_id"], product_id,
                    error_field="product_family_id")
            old = {"product_family_id": locked["product_family_id"],
                   "family_sort_order": int(locked["family_sort_order"] or 0),
                   "family_option_label": locked["family_option_label"]}
            new = {"product_family_id": target_family,
                   "family_sort_order": sort_order if target_family else 0,
                   "family_option_label": option_label if target_family else None}
            if old != new:
                self.db.execute(
                    """UPDATE public.products SET product_family_id=%s,family_sort_order=%s,
                       family_option_label=%s,
                       updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                       WHERE id=%s""",
                    (new["product_family_id"], new["family_sort_order"],
                     new["family_option_label"], product_id))
                if old["product_family_id"] == new["product_family_id"]:
                    action = "product.family_membership_updated"
                elif old["product_family_id"] is None:
                    action = "product.family_assigned"
                elif new["product_family_id"] is None:
                    action = "product.family_unassigned"
                else:
                    action = "product.family_moved"
                audit(self.db, actor_id, action, "App\\Models\\Product",
                      product_id, old, new, ip)

        return bounded_transaction(operation)

    # -- storefront banners ---------------------------------------------

    def banners(self):
        row = self.db.one(
            """SELECT homepage_banners,homepage_banners_version
               FROM public.storefront_settings WHERE singleton_key='storefront'"""
        )
        raw_banners = row["homepage_banners"] if row else []
        if isinstance(raw_banners, str):
            try:
                raw_banners = json.loads(raw_banners)
            except ValueError:
                raw_banners = []
        if not isinstance(raw_banners, list):
            raw_banners = []
        slots = []
        for index in range(5):
            slot = raw_banners[index] if index < len(raw_banners) else None
            slots.append({
                "index": index,
                "path": slot.get("path") if isinstance(slot, dict) else None,
                "title": slot.get("title") if isinstance(slot, dict) else None,
                "link": slot.get("link") if isinstance(slot, dict) else None,
            })
        return {"slots": slots, "version": int(row["homepage_banners_version"] or 0) if row else 0}

    def update_banners(self, data, actor_id, ip=None):
        errors = {}
        version = parse_integer(errors, data, "version", "Versi banner", minimum=0, required=True)
        slots = []
        for index in range(5):
            prefix = f"banners.{index}."
            remove = boolean(data, prefix + "remove")
            path = self._media_path(errors, data, prefix + "path", f"Banner {index + 1}")
            title = optional_text(errors, data, prefix + "title", f"Judul banner {index + 1}", 160)
            link = optional_text(errors, data, prefix + "link", f"Tautan banner {index + 1}", 2048)
            if link is not None and (not link.startswith("/") or link.startswith("//")
                                     or "\\" in link
                                     or any(ord(char) <= 32 for char in link)):
                errors[prefix + "link"] = ["Gunakan tautan halaman toko seperti /shop atau /pilihan/chatgpt."]
            slots.append({"remove": remove, "path": path,
                          "title": title or f"Banner {index + 1}", "link": link})
        _check_errors(errors)

        def operation():
            row = self.db.one(
                """SELECT id,homepage_banners,homepage_banners_version
                   FROM public.storefront_settings WHERE singleton_key='storefront'
                   FOR UPDATE""")
            if row is None:
                raise Http404
            if int(row["homepage_banners_version"] or 0) != version:
                raise AdminConflict("Banner sudah diubah. Muat ulang halaman sebelum menyimpan.")
            previous = row["homepage_banners"]
            if isinstance(previous, str):
                try:
                    previous = json.loads(previous)
                except ValueError:
                    previous = []
            previous = previous if isinstance(previous, list) else []
            next_slots = []
            for index, slot in enumerate(slots):
                old_path = previous[index].get("path") if (
                    index < len(previous) and isinstance(previous[index], dict)) else None
                path = None if slot["remove"] else (slot["path"] or old_path)
                next_slots.append({
                    "path": path,
                    "title": slot["title"],
                    "link": slot["link"],
                } if path else None)
            self.db.execute(
                """UPDATE public.storefront_settings
                   SET homepage_banners=%s::jsonb,homepage_banners_version=%s,
                       updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                   WHERE id=%s""",
                (json.dumps(next_slots, ensure_ascii=False, separators=(",", ":")),
                 version + 1, row["id"]))
            audit(self.db, actor_id, "storefront.banners.updated",
                  "App\\Models\\StorefrontSetting", row["id"],
                  {"homepage_banners": previous},
                  {"homepage_banners": next_slots}, ip)

        return bounded_transaction(operation)

    # -- helpers ---------------------------------------------------------

    def _detail_values(self, data, errors):
        values = {}
        for key, label, kind, maximum, required in DETAIL_FIELDS:
            values[key] = _clean_text(errors, data, key, label, maximum, required)
        title = values.get("usage_guide_title")
        url = values.get("usage_guide_url")
        if title and not url:
            errors["usage_guide_url"] = ["URL panduan wajib diisi jika judul panduan diisi."]
        if url and not title:
            errors["usage_guide_title"] = ["Judul panduan wajib diisi jika URL panduan diisi."]
        if url and not safe_usage_guide_url(url):
            errors["usage_guide_url"] = [
                "URL panduan harus berupa URL HTTP/HTTPS atau path lokal yang diawali satu garis miring."
            ]
        return values

    def _media_path(self, errors, data, key, label):
        value = optional_text(errors, data, key, label, 2048)
        if value is not None and not safe_media_path(value):
            errors[key] = [f"{label} harus berupa path media lokal tanpa protokol atau .."]
            return None
        return value

    def _optional_id(self, errors, data, key, label):
        value = raw(data, key)
        if value is None or value == "":
            return None
        parsed = parse_integer(errors, data, key, label, minimum=1)
        return parsed

    def _unique_slug(self, table, base, *, include_redirects=False):
        slug, suffix = base, 2
        while True:
            exists = self.db.one(f"SELECT id FROM public.{table} WHERE slug=%s", (slug,))
            redirected = None
            if include_redirects:
                redirected = self.db.one(
                    "SELECT id FROM public.product_family_slug_redirects WHERE old_slug=%s",
                    (slug,))
            if not exists and not redirected:
                return slug
            slug = f"{base}-{suffix}"
            suffix += 1

    def _lock_family_and_category(self, family_id, category_id, product_id,
                                  *, target_family_id=None, target_category_id=None):
        family_ids = sorted({item for item in (family_id, target_family_id) if item})
        if family_ids:
            self.db.rows(
                "SELECT id FROM public.product_families WHERE id=ANY(%s) ORDER BY id FOR UPDATE",
                (family_ids,))
            self.db.rows(
                """SELECT id FROM public.products
                   WHERE product_family_id=ANY(%s) ORDER BY id FOR UPDATE""",
                (family_ids,))
        if product_id is not None:
            self.db.rows(
                "SELECT id FROM public.products WHERE id=%s ORDER BY id FOR UPDATE",
                (product_id,))
        category_ids = sorted({item for item in (category_id, target_category_id) if item})
        if category_ids:
            self.db.rows(
                "SELECT id FROM public.categories WHERE id=ANY(%s) ORDER BY id FOR UPDATE",
                (category_ids,))

    def _assert_family_compatible(self, family_id, category_id, product_id,
                                  *, error_field="category_id"):
        if not family_id:
            return
        family = self.db.one(
            "SELECT id,name FROM public.product_families WHERE id=%s AND deleted_at IS NULL",
            (family_id,))
        if family is None:
            raise AdminValidationError({"product_family_id": ["Product Family tidak valid."]})
        rows = self.db.rows(
            """SELECT id,category_id FROM public.products
               WHERE product_family_id=%s AND id IS DISTINCT FROM %s""",
            (family_id, product_id),
        )
        sentinel = object()
        expected = sentinel
        for row in rows:
            value = None if row["category_id"] is None else int(row["category_id"])
            if expected is sentinel:
                expected = value
            elif value != expected:
                raise AdminValidationError({
                    error_field: [
                        f'Product Family "{family["name"]}" hanya dapat berisi Product dengan kategori yang sama. '
                        "Pilih kategori anggota yang sudah ada atau keluarkan Product dari family terlebih dahulu."
                    ]
                })
        if expected is not sentinel and expected != category_id:
            raise AdminValidationError({
                error_field: [
                    f'Product Family "{family["name"]}" hanya dapat berisi Product dengan kategori yang sama. '
                    "Pilih kategori anggota yang sudah ada atau keluarkan Product dari family terlebih dahulu."
                ]
            })
        if category_id is not None:
            category = self.db.one(
                "SELECT id FROM public.categories WHERE id=%s AND deleted_at IS NULL",
                (category_id,))
            if category is None:
                raise AdminValidationError({"category_id": ["Kategori tidak valid."]})

    @staticmethod
    def _audit_product(detail, idr, usdt, reseller_idr, reseller_usdt,
                       category_id, family_id, icon, featured_rank):
        return detail | {
            "category_id": category_id, "product_family_id": family_id,
            "price_amount": idr, "usdt_price_amount": usdt,
            "reseller_price_amount": reseller_idr,
            "reseller_usdt_price_amount": reseller_usdt,
            "icon": icon, "featured_rank": featured_rank, "is_active": True,
        }
