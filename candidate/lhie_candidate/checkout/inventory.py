"""Local credential inventory on the canonical PHP tables; callers own atomicity."""

from lhie_candidate.domain.money import require_integer

from .db import Database
from .errors import CheckoutError


_STOCK_COLUMNS = """
    id,product_id,order_item_id,supplier_purchase_id,supplier_unit_index,
    retired_at,retired_reason,credential_format,
    ((credential_format='legacy' AND secret IS NOT NULL AND credential_payload IS NULL)
      OR (credential_format='structured_account' AND secret IS NULL
          AND credential_payload IS NOT NULL)) AS credential_valid
"""


class InventoryLedger:
    def __init__(self, using="default"):
        self.db = Database(using)

    def lock_products(self, items: list[dict], *, allow_supplier=False) -> dict[int, dict]:
        self.db.require_transaction()
        quantities = {}
        for item in items:
            product_id = require_integer(item["product_id"], minimum=1)
            quantity = require_integer(item["quantity"], minimum=1)
            if product_id in quantities:
                raise CheckoutError("inventory_invariant")
            quantities[product_id] = quantity
        if not quantities:
            raise CheckoutError("inventory_invariant")
        products = self._products(sorted(quantities))
        for product_id, quantity in quantities.items():
            product = products[product_id]
            if not product["public_visible"]:
                raise CheckoutError("unavailable_product")
            count = self.db.one("""
                SELECT COUNT(*) AS count FROM public.product_stock_items
                WHERE product_id=%s AND supplier_purchase_id IS NULL
                  AND supplier_unit_index IS NULL AND order_item_id IS NULL AND retired_at IS NULL
            """, (product_id,))["count"]
            available = min(product["stock"] - product["reserved_stock"], count)
            external = 0
            if allow_supplier and product["supplier_available_until"] is not None:
                external = self.db.one("""SELECT CASE WHEN %s > CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                    THEN %s ELSE 0 END AS available""",
                    (product["supplier_available_until"], product["supplier_available_stock"]))["available"]
            if available + external < quantity:
                raise CheckoutError("insufficient_stock")
            product["available_stock"] = available + external
            product["local_available_stock"] = available
        return products

    def allocate(self, order_item_id: int, product_id: int, quantity: int) -> None:
        self.db.require_transaction()
        require_integer(order_item_id, minimum=1)
        require_integer(product_id, minimum=1)
        require_integer(quantity, minimum=1)
        source = self.db.one("SELECT order_id FROM public.order_items WHERE id=%s", (order_item_id,))
        if source is None:
            raise CheckoutError("inventory_invariant")
        order, items, products, proofs, allocations = self._state(source["order_id"], local_only=False)
        self._assert_unpaid(order)
        item = next((item for item in items if item["id"] == order_item_id), None)
        if item is None or item["product_id"] != product_id or item["local_quantity"] != quantity:
            raise CheckoutError("inventory_invariant")
        if (product_id, "sale") in proofs or (product_id, "release") in proofs:
            raise CheckoutError("invalid_order_transition")
        existing = allocations[order_item_id]
        if existing:
            self._assert_local_allocated(item, existing)
            return
        if (product_id, "reservation") in proofs:
            raise CheckoutError("inventory_invariant")
        product = products[product_id]
        if not product["public_visible"]:
            raise CheckoutError("unavailable_product")
        if product["stock"] - product["reserved_stock"] < quantity:
            raise CheckoutError("insufficient_stock")
        credentials = self.db.rows(f"""
            SELECT {_STOCK_COLUMNS} FROM public.product_stock_items
            WHERE product_id=%s AND supplier_purchase_id IS NULL
              AND supplier_unit_index IS NULL AND order_item_id IS NULL AND retired_at IS NULL
            ORDER BY id LIMIT %s FOR UPDATE
        """, (product_id, quantity))
        if len(credentials) != quantity:
            raise CheckoutError("insufficient_stock")
        self._assert_credentials(credentials, product_id)
        ids = [credential["id"] for credential in credentials]
        changed = self.db.execute("""
            UPDATE public.product_stock_items SET order_item_id=%s,
              updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
            WHERE id=ANY(%s) AND product_id=%s AND order_item_id IS NULL
              AND supplier_purchase_id IS NULL AND supplier_unit_index IS NULL AND retired_at IS NULL
        """, (order_item_id, ids, product_id))
        if changed != quantity:
            raise CheckoutError("inventory_invariant")

    def reserve(self, order_id: str, product_id: int, quantity: int) -> None:
        self.db.require_transaction()
        require_integer(product_id, minimum=1)
        require_integer(quantity, minimum=1)
        order, items, products, proofs, allocations = self._state(order_id, local_only=False)
        self._assert_unpaid(order)
        item = next((item for item in items if item["product_id"] == product_id), None)
        if item is None or item["quantity"] != quantity:
            raise CheckoutError("inventory_invariant")
        if (product_id, "sale") in proofs or (product_id, "release") in proofs:
            raise CheckoutError("invalid_order_transition")
        self._assert_local_allocated(item, allocations[item["id"]])
        if (product_id, "reservation") in proofs:
            return
        product = products[product_id]
        if not product["public_visible"]:
            raise CheckoutError("unavailable_product")
        local = item["quantity"] if item["local_quantity"] is None else item["local_quantity"]
        if product["stock"] - product["reserved_stock"] < local:
            raise CheckoutError("insufficient_stock")
        self._change_product(product_id, 0, local)
        self._movement(order_id, product_id, "reservation", quantity, "Order reservation")

    def record_sales(self, order_id: str) -> None:
        self.db.require_transaction()
        order, items, products, proofs, allocations = self._state(order_id)
        if order["order_status"] not in ("pending_payment", "processing", "completed"):
            raise CheckoutError("invalid_order_transition")
        sold = self._transition_proofs(items, proofs, "sale")
        if any(kind == "release" for _, kind in proofs):
            raise CheckoutError("invalid_order_transition")
        for item in items:
            self._require_reservation(item, proofs)
            self._assert_allocated(item, allocations[item["id"]], allow_retired=sold)
        if sold:
            return
        for item in items:
            product = products[item["product_id"]]
            quantity = item["quantity"]
            if product["reserved_stock"] < quantity or product["stock"] < quantity:
                raise CheckoutError("inventory_invariant")
            self._change_product(product["id"], -quantity, -quantity)
            available = self.db.one("""
                SELECT COUNT(*) AS count FROM public.product_stock_items
                WHERE product_id=%s AND supplier_purchase_id IS NULL
                  AND order_item_id IS NULL AND retired_at IS NULL
            """, (product["id"],))["count"]
            became_unavailable = (product["stock"] == quantity
                                  or (product["reserved_stock"] == quantity and available == 0))
            self._movement(order_id, product["id"], "sale", -quantity, "Order sale",
                           stock_before=product["stock"], became_unavailable=became_unavailable)

    def release(self, order_id: str) -> None:
        self.db.require_transaction()
        order, items, products, proofs, allocations = self._state(order_id)
        self._assert_unpaid(order, released=True)
        if any(kind == "sale" for _, kind in proofs):
            raise CheckoutError("invalid_order_transition")
        released = self._transition_proofs(items, proofs, "release")
        for item in items:
            self._require_reservation(item, proofs)
            credentials = allocations[item["id"]]
            if released:
                if credentials:
                    raise CheckoutError("inventory_invariant")
            else:
                self._assert_allocated(item, credentials)
        if released:
            return
        for item in items:
            product_id, quantity = item["product_id"], item["quantity"]
            if products[product_id]["reserved_stock"] < quantity:
                raise CheckoutError("inventory_invariant")
            self._change_product(product_id, 0, -quantity)
            self._movement(order_id, product_id, "release", -quantity, "Order reservation release")
            changed = self.db.execute("""
                UPDATE public.product_stock_items SET order_item_id=NULL,
                  updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                WHERE order_item_id=%s AND product_id=%s AND supplier_purchase_id IS NULL
                  AND supplier_unit_index IS NULL AND retired_at IS NULL
            """, (item["id"], product_id))
            if changed != quantity:
                raise CheckoutError("inventory_invariant")

    def retire(self, order_item_id: int) -> None:
        self.db.require_transaction()
        require_integer(order_item_id, minimum=1)
        source = self.db.one("SELECT order_id FROM public.order_items WHERE id=%s", (order_item_id,))
        if source is None:
            raise CheckoutError("inventory_invariant")
        _, items, _, proofs, allocations = self._state(source["order_id"])
        item = next((item for item in items if item["id"] == order_item_id), None)
        if item is None:
            raise CheckoutError("inventory_invariant")
        self._require_reservation(item, proofs)
        if (item["product_id"], "sale") not in proofs or (item["product_id"], "release") in proofs:
            raise CheckoutError("invalid_order_transition")
        credentials = allocations[order_item_id]
        self._assert_allocated(item, credentials, allow_retired=True)
        expected = sum(credential["retired_at"] is None for credential in credentials)
        # Sale proof already consumed both projections; retirement must not consume them twice.
        changed = self.db.execute("""
            UPDATE public.product_stock_items SET retired_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
              retired_reason='refunded_after_delivery',updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
            WHERE order_item_id=%s AND product_id=%s AND supplier_purchase_id IS NULL
              AND supplier_unit_index IS NULL AND retired_at IS NULL
        """, (order_item_id, item["product_id"]))
        if changed != expected:
            raise CheckoutError("inventory_invariant")

    def has_sales(self, order_id):
        self.db.require_transaction()
        _, items, _, proofs, _ = self._state(order_id, local_only=False)
        return self._transition_proofs(items, proofs, "sale")

    def lifecycle_sales(self, order_id):
        """Consume reservations even when a historical Order needs credential review."""
        self.db.require_transaction()
        _, items, products, proofs, _ = self._state(order_id, local_only=False)
        if self._transition_proofs(items, proofs, "sale"):
            return
        if any(kind == "release" for _, kind in proofs):
            raise CheckoutError("invalid_order_transition")
        for item in items:
            self._require_reservation(item, proofs)
            local = item["quantity"] if item["local_quantity"] is None else item["local_quantity"]
            product = products[item["product_id"]]
            if product["stock"] < local or product["reserved_stock"] < local:
                raise CheckoutError("inventory_invariant")
            self._change_product(product["id"], -local, -local)
            self._movement(order_id, product["id"], "sale", -item["quantity"], "Order sale",
                           stock_before=product["stock"],
                           became_unavailable=product["stock"] == local)

    def lifecycle_release(self, order_id):
        self.db.require_transaction()
        _, items, products, proofs, allocations = self._state(order_id, local_only=False)
        if any(kind == "sale" for _, kind in proofs):
            raise CheckoutError("invalid_order_transition")
        if self._transition_proofs(items, proofs, "release"):
            return
        for item in items:
            self._require_reservation(item, proofs)
            local = item["quantity"] if item["local_quantity"] is None else item["local_quantity"]
            product = products[item["product_id"]]
            if product["reserved_stock"] < local:
                raise CheckoutError("inventory_invariant")
            # Retired/exposed units keep their allocation, including in historical data.
            self.db.execute("""UPDATE public.product_stock_items SET order_item_id=NULL,
                updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                WHERE order_item_id=%s AND supplier_purchase_id IS NULL AND retired_at IS NULL""",
                (item["id"],))
            self._change_product(product["id"], 0, -local)
            self._movement(order_id, product["id"], "release", -item["quantity"],
                           "Order reservation release")

    def fulfill_review(self, order_id):
        """Owner-authorized allocation; a released reservation is never decremented twice."""
        self.db.require_transaction()
        _, items, products, proofs, allocations = self._state(order_id, local_only=False)
        if any(item["supplier_quantity"] for item in items):
            raise CheckoutError("supplier_fulfillment_requires_resolution")
        sold = self._transition_proofs(items, proofs, "sale")
        released = self._transition_proofs(items, proofs, "release")
        if not sold and not released:
            raise CheckoutError("inventory_invariant")
        for item in items:
            self._require_reservation(item, proofs)
            product = products[item["product_id"]]
            current = allocations[item["id"]]
            self._assert_credentials(current, product["id"])
            if any(row["retired_at"] is not None for row in current) or len(current) > item["quantity"]:
                raise CheckoutError("inventory_invariant")
            missing = item["quantity"] - len(current)
            if released and not sold:
                if not product["public_visible"] or product["stock"] - product["reserved_stock"] < item["quantity"]:
                    raise CheckoutError("insufficient_stock")
            if missing:
                available = self.db.rows(f"""SELECT {_STOCK_COLUMNS} FROM public.product_stock_items
                    WHERE product_id=%s AND order_item_id IS NULL AND retired_at IS NULL
                      AND supplier_purchase_id IS NULL AND supplier_unit_index IS NULL
                    ORDER BY id LIMIT %s FOR UPDATE""", (product["id"], missing))
                if len(available) != missing:
                    raise CheckoutError("insufficient_stock")
                self._assert_credentials(available, product["id"])
                self.db.execute("""UPDATE public.product_stock_items SET order_item_id=%s,
                    updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=ANY(%s)""",
                    (item["id"], [row["id"] for row in available]))
            if not sold:
                self._change_product(product["id"], -item["quantity"], 0)
                self._movement(order_id, product["id"], "sale", -item["quantity"],
                               "Late payment manual fulfillment", stock_before=product["stock"],
                               became_unavailable=product["stock"] == item["quantity"])

    def retire_order(self, order_id):
        self.db.require_transaction()
        _, items, _, proofs, _ = self._state(order_id, local_only=False)
        if not self._transition_proofs(items, proofs, "sale"):
            raise CheckoutError("inventory_invariant")
        self.db.execute("""UPDATE public.product_stock_items SET
            retired_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
            retired_reason='refunded_after_delivery',updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
            WHERE order_item_id=ANY(%s) AND retired_at IS NULL""", ([item["id"] for item in items],))

    def _products(self, product_ids: list[int]) -> dict[int, dict]:
        rows = self.db.rows("""
            SELECT p.*,(p.is_active AND p.deleted_at IS NULL AND
              (p.category_id IS NULL OR EXISTS (SELECT 1 FROM public.categories c
               WHERE c.id=p.category_id AND c.is_active AND c.deleted_at IS NULL))) AS public_visible
            FROM public.products p WHERE p.id=ANY(%s) ORDER BY p.id FOR NO KEY UPDATE OF p
        """, (product_ids,))
        products = {product["id"]: product for product in rows}
        if set(products) != set(product_ids):
            raise CheckoutError("unavailable_product")
        for product in rows:
            stock = require_integer(product["stock"])
            reserved = require_integer(product["reserved_stock"])
            if reserved > stock:
                raise CheckoutError("inventory_invariant")
        return products

    def _state(self, order_id, *, local_only=True):
        order = self.db.one("""
            SELECT id,order_status,payment_status FROM public.orders WHERE id=%s FOR UPDATE
        """, (order_id,))
        if order is None:
            raise CheckoutError("invalid_order_transition")
        items = self.db.rows("""
            SELECT id,order_id,product_id,quantity,local_quantity,supplier_quantity
            FROM public.order_items WHERE order_id=%s ORDER BY product_id,id
        """, (order_id,))
        product_ids = []
        for item in items:
            require_integer(item["product_id"], minimum=1)
            quantity = require_integer(item["quantity"], minimum=1)
            local = quantity if item["local_quantity"] is None else item["local_quantity"]
            supplier = require_integer(item["supplier_quantity"])
            if (type(local) is not int or local < 0 or local + supplier != quantity
                    or (local_only and (local != quantity or supplier != 0))):
                raise CheckoutError("inventory_invariant")
            product_ids.append(item["product_id"])
        if not items or len(set(product_ids)) != len(items):
            raise CheckoutError("inventory_invariant")
        products = self._products(product_ids)
        movements = self.db.rows("""
            SELECT id,product_id,type,quantity FROM public.inventory_movements
            WHERE order_id=%s ORDER BY product_id,type,id
        """, (order_id,))
        quantities = {item["product_id"]: item["quantity"] for item in items}
        proofs = {}
        for movement in movements:
            product_id, kind = movement["product_id"], movement["type"]
            if product_id not in quantities or kind not in ("reservation", "sale", "release"):
                raise CheckoutError("inventory_invariant")
            key = (product_id, kind)
            expected = quantities[product_id] * (1 if kind == "reservation" else -1)
            if key in proofs or movement["quantity"] != expected:
                raise CheckoutError("inventory_invariant")
            proofs[key] = movement
        allocations = {item["id"]: [] for item in items}
        credentials = self.db.rows(f"""
            SELECT {_STOCK_COLUMNS} FROM public.product_stock_items
            WHERE order_item_id=ANY(%s) ORDER BY product_id,id FOR UPDATE
        """, (list(allocations),))
        for credential in credentials:
            allocations[credential["order_item_id"]].append(credential)
        return order, items, products, proofs, allocations

    def _assert_unpaid(self, order, *, released=False):
        statuses = ("pending_payment", "cancelled", "expired") if released else ("pending_payment",)
        if order["order_status"] not in statuses or order["payment_status"] not in ("pending", "failed", "expired"):
            raise CheckoutError("invalid_order_transition")
        payments = self.db.rows("""
            SELECT status,paid_at FROM public.payments WHERE order_id=%s ORDER BY id FOR UPDATE
        """, (order["id"],))
        if any(payment["status"] not in ("pending", "failed", "expired")
               or payment["paid_at"] is not None for payment in payments):
            raise CheckoutError("invalid_order_transition")

    def _assert_credentials(self, credentials, product_id):
        for credential in credentials:
            if (credential["product_id"] != product_id or credential["supplier_purchase_id"] is not None
                    or credential["supplier_unit_index"] is not None or not credential["credential_valid"]):
                raise CheckoutError("inventory_invariant")
        identities = self.db.rows("""
            SELECT id,product_id,product_stock_item_id,identity_kind FROM public.credential_identities
            WHERE product_stock_item_id=ANY(%s) ORDER BY id
        """, ([credential["id"] for credential in credentials],))
        emails = set()
        for identity in identities:
            if identity["product_id"] != product_id or identity["identity_kind"] not in ("email", "auth_token"):
                raise CheckoutError("inventory_invariant")
            if identity["identity_kind"] == "email":
                emails.add(identity["product_stock_item_id"])
        if any(credential["credential_format"] == "structured_account" and credential["id"] not in emails
               for credential in credentials):
            raise CheckoutError("inventory_invariant")

    def _assert_local_allocated(self, item, credentials):
        local = item["quantity"] if item["local_quantity"] is None else item["local_quantity"]
        if len(credentials) != local:
            raise CheckoutError("inventory_invariant")
        self._assert_credentials(credentials, item["product_id"])
        if any(row["retired_at"] is not None for row in credentials):
            raise CheckoutError("invalid_order_transition")

    def _assert_allocated(self, item, credentials, *, allow_retired=False):
        if len(credentials) != item["quantity"]:
            raise CheckoutError("inventory_invariant")
        self._assert_credentials(credentials, item["product_id"])
        if not allow_retired and any(credential["retired_at"] is not None for credential in credentials):
            raise CheckoutError("invalid_order_transition")
        if any(credential["retired_at"] is not None and not credential["retired_reason"] for credential in credentials):
            raise CheckoutError("inventory_invariant")

    @staticmethod
    def _require_reservation(item, proofs):
        if (item["product_id"], "reservation") not in proofs:
            raise CheckoutError("inventory_invariant")

    @staticmethod
    def _transition_proofs(items, proofs, kind):
        count = sum((item["product_id"], kind) in proofs for item in items)
        if count not in (0, len(items)):
            raise CheckoutError("inventory_invariant")
        return count == len(items)

    def _change_product(self, product_id, stock_delta, reserved_delta):
        changed = self.db.execute("""
            UPDATE public.products SET stock=stock+%s,reserved_stock=reserved_stock+%s,
              updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
            WHERE id=%s AND stock+%s>=0 AND reserved_stock+%s>=0
              AND reserved_stock+%s<=stock+%s
        """, (stock_delta, reserved_delta, product_id, stock_delta, reserved_delta, reserved_delta, stock_delta))
        if changed != 1:
            raise CheckoutError("inventory_invariant")

    def _movement(self, order_id, product_id, kind, quantity, notes, *, stock_before=None, became_unavailable=None):
        changed = self.db.execute("""
            INSERT INTO public.inventory_movements
              (product_id,order_id,type,quantity,stock_before,became_unavailable,notes,created_at)
            VALUES (%s,%s,%s,%s,%s,%s,%s,CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')
        """, (product_id, order_id, kind, quantity, stock_before, became_unavailable, notes))
        if changed != 1:
            raise CheckoutError("inventory_invariant")
