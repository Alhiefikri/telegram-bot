"""Original-currency refunds inside the lifecycle owner's already locked transaction.

P18 owns creation of supplier/warranty entitlements. This writer consumes their
canonical historical rows, never a caller-supplied amount, and atomically records
settlement and retirement of the exact refunded credential. The lifecycle caller
owns operator authorization, audit, supplier resource locks and whole-Order
resource release. No supplier transport is activated here.
"""
from dataclasses import dataclass
from hashlib import sha256
import json
from uuid import uuid4

from lhie_candidate.domain.money import MAX_UNITS, require_integer

from .balance import StoreBalanceLedger
from .db import Database
from .errors import CheckoutError


@dataclass(frozen=True)
class RefundResult:
    newly_applied: bool
    fully_refunded: bool
    entry: dict | None = None
    refund: dict | None = None


def _fingerprint(variant, kind, customer_id, amount, **fields):
    # PHP json_encode preserves insertion order and escapes Unicode and slashes.
    payload = dict(version=1, variant=variant, kind=kind, currency="USDT",
                   customer_id=customer_id, amount_micro=amount, **fields)
    encoded = json.dumps(payload, ensure_ascii=True, separators=(",", ":")).replace("/", "\\/")
    return sha256(encoded.encode()).hexdigest()


class OrderRefunds:
    def __init__(self, using="default", *, encrypter=None):
        self.db = Database(using)
        self.balance = StoreBalanceLedger(using)
        self.encrypter = encrypter

    def lock_account(self, customer_id, payment):
        """Call after Customer FOR SHARE and before Order/Payment resource locks."""
        self.db.require_transaction()
        customer_id = require_integer(customer_id, minimum=1)
        if payment["provider"] == "store_balance":
            account = self.balance.lock_for_purchase(customer_id)
        elif payment["provider"] == "usdt_balance":
            account = self.db.one("""SELECT id,customer_id,currency,balance_micro,version,
                last_entry_id,frozen_reason FROM public.customer_usdt_balance_accounts
                WHERE customer_id=%s FOR UPDATE""", (customer_id,))
            if account is None:
                raise CheckoutError("refund_original_debit_missing")
        else:
            return None
        return dict(account, customer_id=customer_id, provider=payment["provider"])

    def apply_locked(self, order, payment, *, account=None, refund_id=None,
                     reference=None, result="success", actor_user_id=None):
        """Post a registered partial or exact original-debit/external remainder.

        Order and all its Payments (ordered by ID) must already be locked.
        This also resolves the registered origin and retires its exact credential.
        External evidence is authoritative only when result=success.
        """
        self.db.require_transaction()
        amount = require_integer(payment["amount"], minimum=1)
        external_usdt = (order["currency"] == "IDR" and payment["currency"] == "USDT"
                         and payment["provider"] == "manual"
                         and payment["method"] in ("BINANCE_PAY_ID", "USDT_BEP20"))
        if (str(payment["order_id"]) != str(order["id"])
                or (payment["currency"] != order["currency"] and not external_usdt)
                or payment["currency"] not in ("IDR", "USDT")
                or payment["paid_at"] is None):
            raise CheckoutError("refund_payment_identity_invalid")
        paid = (payment["status"] == "paid" and order["payment_status"] == "paid"
                and (order["order_status"] in ("processing", "completed")
                     or (payment["provider"] not in ("store_balance", "usdt_balance")
                         and order["order_status"] in ("cancelled", "expired"))))
        replay = (payment["status"] == "refunded" and order["payment_status"] == "refunded"
                  and order["order_status"] in ("cancelled", "completed"))
        if not (paid or replay):
            raise CheckoutError("refund_payment_state_invalid")
        self._assert_historical_safety(order["id"])
        refunds = self.db.rows("""SELECT * FROM public.order_refunds
            WHERE payment_id=%s ORDER BY id FOR UPDATE""", (payment["id"],))
        for refund in refunds:
            if (str(refund["order_id"]) != str(order["id"])
                    or refund["customer_id"] != order["customer_id"]
                    or refund["currency"] != payment["currency"]
                    or refund["state"] not in ("requested", "uncertain", "confirmed")):
                raise CheckoutError("refund_identity_conflict")
        if sum(require_integer(r["amount"], minimum=1) for r in refunds) > amount:
            raise CheckoutError("refund_exceeds_original")
        refund = None
        if refund_id is not None:
            refund = next((r for r in refunds if str(r["id"]) == str(refund_id)), None)
            if refund is None:
                raise CheckoutError("refund_entitlement_missing")
        if payment["provider"] in ("store_balance", "usdt_balance"):
            if reference is not None or result != "success":
                raise CheckoutError("refund_internal_external_evidence")
            outcome = self._internal(order, payment, account, refunds, refund, replay)
        else:
            if account is not None:
                raise CheckoutError("refund_identity_conflict")
            outcome = self._external(order, payment, refunds, refund, reference, result,
                                     actor_user_id, replay)
        if outcome.newly_applied and outcome.refund is not None:
            self._settle_origin(outcome.refund)
        if payment["provider"] == "usdt_balance" and outcome.entry is not None:
            from .refund_receipt import record_refund_receipt
            record_refund_receipt(self.db, outcome.entry, using=self.db.using)
        return outcome

    def _assert_historical_safety(self, order_id):
        # These are read guards, not P18 transport/replacement activation.
        if (self.db.one("""SELECT id FROM public.supplier_account_units WHERE order_id=%s
                AND confirmation_state IN ('requested','sending','unknown') LIMIT 1""", (order_id,))
                or self.db.one("""SELECT id FROM public.warranty_claims WHERE order_id=%s
                AND settlement_state IN ('replacement_pending','replacement_sending',
                    'replacement_unknown','replacement_review') LIMIT 1""", (order_id,))
                or self.db.one("""SELECT p.id FROM public.supplier_purchases p
                JOIN public.supplier_fulfillments f ON f.id=p.supplier_fulfillment_id
                WHERE f.order_id=%s AND p.state IN
                    ('sending','awaiting_delivery','ambiguous','review') LIMIT 1""", (order_id,))):
            raise CheckoutError("refund_supplier_evidence_unresolved")

    def _internal(self, order, payment, account, refunds, refund, replay):
        usdt = payment["provider"] == "usdt_balance"
        currency, method = ("USDT", "USDT_BALANCE") if usdt else ("IDR", "STORE_BALANCE")
        expected_total = order["total_amount"]
        if (account is None or account.get("customer_id") != order["customer_id"]
                or account.get("provider") != payment["provider"]
                or payment["method"] != method or payment["currency"] != currency
                or (not usdt and payment["amount"] != expected_total)):
            raise CheckoutError("refund_original_debit_identity_invalid")
        table = "customer_usdt_balance_entries" if usdt else "store_balance_entries"
        account_column = "customer_usdt_balance_account_id" if usdt else "customer_balance_account_id"
        amount_column = "amount_micro" if usdt else "amount"
        original = self.db.one(f"""SELECT * FROM public.{table}
            WHERE source_type='order' AND source_id=%s AND kind='purchase_debit'""", (str(order["id"]),))
        if (original is None or original[account_column] != account["id"]
                or original[amount_column] != payment["amount"]):
            raise CheckoutError("refund_original_debit_missing")
        if usdt and original["request_fingerprint"] != _fingerprint(
                "purchase", "purchase_debit", order["customer_id"], payment["amount"],
                order_id=str(order["id"]), payment_id=str(payment["id"])):
            raise CheckoutError("refund_original_debit_identity_invalid")
        original_filter = " OR original_entry_id=%s" if usdt else ""
        parameters = (str(order["id"]), order["id"]) + ((original["id"],) if usdt else ())
        entries = self.db.rows(f"""SELECT * FROM public.{table} WHERE kind='refund_credit'
            AND ((source_type='order' AND source_id=%s) OR refund_order_id=%s{original_filter})
            ORDER BY id""", parameters)
        by_source = {}
        for entry in entries:
            if (entry[account_column] != account["id"]
                    or (usdt and entry["original_entry_id"] != original["id"])
                    or entry["source_type"] not in ("order", "order_refund")
                    or (entry["source_type"] == "order" and entry["source_id"] != str(order["id"]))
                    or (entry["source_type"] == "order_refund"
                        and str(entry["refund_order_id"]) != str(order["id"]))
                    or (not usdt and entry["source_type"] == "order"
                        and entry["refund_order_id"] is not None)):
                raise CheckoutError("refund_identity_conflict")
            if usdt:
                expected = _fingerprint("verified_refund", "refund_credit",
                    order["customer_id"], entry["amount_micro"],
                    refund_id=entry["source_id"], order_id=str(order["id"]),
                    original_entry_id=original["id"], payment_id=str(payment["id"]),
                    full_order=entry["source_type"] == "order")
                event = self.db.one("""SELECT currency,amount_micro,debit_account,credit_account,
                    source_fingerprint FROM public.customer_usdt_balance_accounting_events
                    WHERE customer_usdt_balance_entry_id=%s""", (entry["id"],))
                if (entry["request_fingerprint"] != expected
                        or str(entry["refund_order_id"]) != str(order["id"])
                        or event is None or event["currency"] != "USDT"
                        or event["amount_micro"] != entry["amount_micro"]
                        or event["debit_account"] != "usdt_sale_refund"
                        or event["credit_account"] != "customer_usdt_liability"
                        or event["source_fingerprint"] != expected):
                    raise CheckoutError("refund_identity_conflict")
            by_source[(entry["source_type"], entry["source_id"])] = entry
        total = sum(require_integer(e[amount_column], minimum=1) for e in entries)
        if total > payment["amount"]:
            raise CheckoutError("refund_exceeds_original")
        if ("order", str(order["id"])) in by_source and total != payment["amount"]:
            raise CheckoutError("refund_identity_conflict")
        for registered in refunds:
            if registered["external_reference_hash"] is not None or registered["external_reference"] is not None:
                raise CheckoutError("refund_internal_external_evidence")
            entry = by_source.get(("order_refund", str(registered["id"])))
            if entry is not None and (entry[amount_column] != registered["amount"]
                                      or registered["state"] != "confirmed"):
                raise CheckoutError("refund_identity_conflict")
            if entry is None and registered["state"] == "confirmed":
                raise CheckoutError("refund_identity_conflict")
            if refund is None and entry is None:
                raise CheckoutError("refund_registered_entitlement_unresolved")
        registered_ids = {str(r["id"]) for r in refunds}
        if any(e["source_type"] == "order_refund" and e["source_id"] not in registered_ids for e in entries):
            raise CheckoutError("refund_identity_conflict")
        source_type = "order" if refund is None else "order_refund"
        source_id = str(order["id"] if refund is None else refund["id"])
        existing = by_source.get((source_type, source_id))
        if existing is not None:
            return RefundResult(False, total == payment["amount"], existing, refund)
        if refund is None and total == payment["amount"]:
            return RefundResult(False, True, entries[-1] if entries else None)
        if replay:
            raise CheckoutError("refund_replay_missing_credit")
        if refund is not None:
            if (refund["state"] != "requested" or ("order", str(order["id"])) in by_source):
                raise CheckoutError("refund_entitlement_state_invalid")
            self._assert_entitlement(order, payment, refund)
            credit = require_integer(refund["amount"], minimum=1)
        else:
            credit = payment["amount"] - total
        if credit > payment["amount"] - total:
            raise CheckoutError("refund_exceeds_original")
        key = (f"order:{order['id']}:usdt-refund" if usdt else f"order:{order['id']}:refund") if refund is None else f"order-refund:{source_id}"
        if usdt:
            entry = self._post_usdt(account, order, payment, original, credit,
                                    source_type, source_id, key)
        else:
            account = self.balance.lock_for_purchase(order["customer_id"])
            # Canonical posting alone changes the IDR projection.
            claimed = self.balance._find_source_entry(account, "refund_credit", credit,
                source_type, source_id, key, refund_order_id=None if refund is None else order["id"])
            if claimed is not None:
                raise CheckoutError("refund_identity_conflict")
            entry = self.balance._post(account, "refund_credit", credit, source_id, key,
                source_type=source_type, refund_order_id=None if refund is None else order["id"])
        if refund is not None:
            refund = self.db.one("""UPDATE public.order_refunds SET state='confirmed',
                confirmed_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s RETURNING *""", (refund["id"],))
        return RefundResult(True, total + credit == payment["amount"], entry, refund)

    def _post_usdt(self, account, order, payment, original, amount, source_type, source_id, key):
        # Reload the locked account: another posting in this transaction may have advanced it.
        current = self.db.one("""SELECT id,balance_micro,version,last_entry_id,frozen_reason,currency
            FROM public.customer_usdt_balance_accounts WHERE id=%s FOR UPDATE""", (account["id"],))
        if current["frozen_reason"] is not None:
            raise CheckoutError("usdt_balance_frozen")
        latest = self.db.one("""SELECT id,account_sequence,balance_after_micro FROM
            public.customer_usdt_balance_entries WHERE customer_usdt_balance_account_id=%s
            ORDER BY account_sequence DESC LIMIT 1""", (account["id"],))
        if (current["currency"] != "USDT" or latest is None
                or latest["id"] != current["last_entry_id"]
                or latest["account_sequence"] != current["version"]
                or latest["balance_after_micro"] != current["balance_micro"]):
            raise CheckoutError("usdt_balance_projection_mismatch")
        balance = require_integer(current["balance_micro"])
        sequence = require_integer(current["version"]) + 1
        if amount > MAX_UNITS - balance or sequence > MAX_UNITS:
            raise CheckoutError("balance_overflow")
        fingerprint = _fingerprint("verified_refund", "refund_credit", order["customer_id"], amount,
            refund_id=source_id, order_id=str(order["id"]), original_entry_id=original["id"],
            payment_id=str(payment["id"]), full_order=source_type == "order")
        claims = self.db.rows("""SELECT id FROM public.customer_usdt_balance_entries
            WHERE (customer_usdt_balance_account_id=%s AND idempotency_key=%s)
               OR (source_type=%s AND source_id=%s AND kind='refund_credit')""",
            (account["id"], key, source_type, source_id))
        if claims:
            raise CheckoutError("refund_identity_conflict")
        entry = self.db.one("""INSERT INTO public.customer_usdt_balance_entries
            (customer_usdt_balance_account_id,account_sequence,kind,amount_micro,
             balance_after_micro,source_type,source_id,idempotency_key,request_fingerprint,
             original_entry_id,refund_order_id,created_at)
            VALUES (%s,%s,'refund_credit',%s,%s,%s,%s,%s,%s,%s,%s,
                CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta') RETURNING *""",
            (account["id"], sequence, amount, balance + amount, source_type, source_id,
             key, fingerprint, original["id"], order["id"]))
        self.db.execute("""INSERT INTO public.customer_usdt_balance_accounting_events
            (customer_usdt_balance_entry_id,currency,amount_micro,debit_account,
             credit_account,source_fingerprint,created_at)
            VALUES (%s,'USDT',%s,'usdt_sale_refund','customer_usdt_liability',%s,
                CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')""", (entry["id"], amount, fingerprint))
        self.db.execute("""UPDATE public.customer_usdt_balance_accounts SET balance_micro=%s,
            version=%s,last_entry_id=%s,updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
            WHERE id=%s""", (balance + amount, sequence, entry["id"], account["id"]))
        return entry

    def _settle_origin(self, refund):
        if refund["warranty_claim_id"] is not None:
            self.db.execute("""UPDATE public.warranty_claims SET settlement_state=%s,
                updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s""",
                ("refunded" if refund["state"] == "confirmed" else "refund_uncertain",
                 refund["warranty_claim_id"]))
            if refund["state"] == "confirmed":
                self.db.execute("""UPDATE public.product_stock_items SET
                    retired_at=COALESCE(retired_at,CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'),
                    retired_reason='warranty_refund',
                    updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                    WHERE id=(SELECT product_stock_item_id FROM public.supplier_account_units WHERE id=%s)""",
                    (refund["supplier_account_unit_id"],))
        elif refund["supplier_fulfillment_id"] is not None and refund["state"] == "confirmed":
            self.db.execute("""UPDATE public.supplier_fulfillments SET state='delivered',
                refunded_quantity=%s,next_attempt_at=NULL,last_error='missing_units_refunded',
                updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s""",
                (refund["missing_quantity"], refund["supplier_fulfillment_id"]))

    def _assert_entitlement(self, order, payment, refund):
        claim_id, fulfillment_id = refund["warranty_claim_id"], refund["supplier_fulfillment_id"]
        if claim_id is not None and fulfillment_id is None and refund["missing_quantity"] is None:
            unit = self.db.one("""SELECT id,order_id,order_item_id,product_stock_item_id,
                retail_amount,retail_currency,unit_number FROM public.supplier_account_units
                WHERE id=%s FOR UPDATE""", (refund["supplier_account_unit_id"],))
            claim = self.db.one("""SELECT id,order_id,customer_id,supplier_account_unit_id,state,
                verified_at,settlement_choice,settlement_state FROM public.warranty_claims
                WHERE id=%s FOR UPDATE""", (claim_id,))
            if claim is None or unit is None:
                raise CheckoutError("refund_entitlement_invalid")
            item = self.db.one("""SELECT id,order_id,quantity,subtotal,usdt_subtotal
                FROM public.order_items WHERE id=%s FOR UPDATE""", (unit["order_item_id"],))
            stock = self.db.one("""SELECT id,order_item_id FROM public.product_stock_items
                WHERE id=%s FOR UPDATE""", (unit["product_stock_item_id"],))
            valid = (str(claim["order_id"]) == str(order["id"])
                and claim["customer_id"] == order["customer_id"]
                and claim["supplier_account_unit_id"] == unit["id"]
                and claim["state"] == "approved" and claim["verified_at"] is not None
                and claim["settlement_choice"] == "refund"
                and claim["settlement_state"] in ("refund_pending", "refund_uncertain")
                and str(unit["order_id"]) == str(order["id"])
                and unit["retail_currency"] == payment["currency"]
                and unit["retail_amount"] == refund["amount"] and item is not None
                and str(item["order_id"]) == str(order["id"])
                and stock is not None and stock["order_item_id"] == item["id"]
                and 1 <= unit["unit_number"] <= item["quantity"])
            if valid:
                subtotal = item["usdt_subtotal"] if payment["currency"] == "USDT" else item["subtotal"]
                valid = subtotal is not None and self._unit(subtotal, item["quantity"], unit["unit_number"]) == refund["amount"]
            if valid:
                return
        elif (fulfillment_id is not None and claim_id is None
              and refund["supplier_account_unit_id"] is None):
            fulfillment = self.db.one("""SELECT id,order_id,order_item_id,state,quantity,
                reserved_quantity,refunded_quantity FROM public.supplier_fulfillments WHERE id=%s FOR UPDATE""", (fulfillment_id,))
            if fulfillment is None:
                raise CheckoutError("refund_entitlement_invalid")
            item = self.db.one("""SELECT id,order_id,quantity,local_quantity,subtotal,usdt_subtotal
                FROM public.order_items WHERE id=%s FOR UPDATE""", (fulfillment["order_item_id"],))
            acquired = self.db.one("""SELECT
                (SELECT COUNT(*) FROM public.product_stock_items s JOIN public.supplier_purchases p
                 ON p.id=s.supplier_purchase_id WHERE s.order_item_id=%s
                 AND p.supplier_fulfillment_id=%s AND p.warranty_claim_id IS NULL)
                + (SELECT COALESCE(SUM(quantity),0) FROM public.supplier_manual_handoffs
                   WHERE supplier_fulfillment_id=%s) AS quantity,
                (SELECT COALESCE(SUM(observed_quantity),0) FROM public.supplier_purchases
                 WHERE supplier_fulfillment_id=%s AND warranty_claim_id IS NULL
                   AND committed_at IS NOT NULL) AS proven""",
                (fulfillment["order_item_id"], fulfillment_id, fulfillment_id, fulfillment_id))
            count = int(acquired["quantity"])
            valid = (item is not None and str(fulfillment["order_id"]) == str(order["id"])
                and str(item["order_id"]) == str(order["id"])
                and fulfillment["state"] == "refund_pending" and fulfillment["reserved_quantity"] == 0
                and fulfillment["refunded_quantity"] == 0
                and count == acquired["proven"] and count < fulfillment["quantity"]
                and refund["missing_quantity"] == fulfillment["quantity"] - count)
            if valid:
                subtotal = item["usdt_subtotal"] if payment["currency"] == "USDT" else item["subtotal"]
                local = item["quantity"] if item["local_quantity"] is None else item["local_quantity"]
                if subtotal is not None:
                    subtotal = require_integer(subtotal)
                    quantity = require_integer(item["quantity"], minimum=1)
                    prefix = local + count
                    base, remainder = divmod(subtotal, quantity)
                    valid = (0 <= prefix < quantity
                             and subtotal - base * prefix - min(remainder, prefix) == refund["amount"])
                else:
                    valid = False
            if valid:
                return
        raise CheckoutError("refund_entitlement_invalid")

    @staticmethod
    def _unit(total, quantity, number):
        total = require_integer(total)
        quantity = require_integer(quantity, minimum=1)
        number = require_integer(number, minimum=1)
        if number > quantity:
            raise CheckoutError("refund_entitlement_invalid")
        base, remainder = divmod(total, quantity)
        return base + (number <= remainder)

    def _external(self, order, payment, refunds, refund, reference, result, actor_id, replay):
        if replay and not refunds and refund is None:
            # Historical full external refunds predate the per-refund evidence ledger.
            # Preserve their terminal authority without inventing a transfer or credit.
            return RefundResult(False, True)
        if (reference is None and refund is None and result == "success" and refunds
                and all(r["state"] == "confirmed" and r["external_reference_hash"] is not None
                        and r["external_reference"] is not None and r["confirmed_at"] is not None
                        for r in refunds)
                and sum(r["amount"] for r in refunds) == payment["amount"]):
            return RefundResult(False, True)
        if result not in ("success", "failure", "uncertain") or type(reference) is not str:
            raise CheckoutError("refund_external_evidence_invalid")
        reference = reference.strip(" \t\n\r\0\v")
        if (not reference or len(reference.encode()) > 200
                or any(ord(c) < 32 or ord(c) == 127 for c in reference)):
            raise CheckoutError("refund_external_evidence_invalid")
        actor_id = require_integer(actor_id, minimum=1)
        identity = sha256(f"{payment['provider']}|{payment['currency']}|{reference}".encode()).hexdigest()
        claimed = self.db.one("SELECT id FROM public.order_refunds WHERE external_reference_hash=%s", (identity,))
        if claimed is not None and (refund is None or str(claimed["id"]) != str(refund["id"])):
            # A full replay resolves the same canonical remainder row.
            matching = next((r for r in refunds if str(r["id"]) == str(claimed["id"])
                             and r["warranty_claim_id"] is None and r["supplier_fulfillment_id"] is None), None)
            if refund is not None or matching is None:
                raise CheckoutError("refund_external_evidence_claimed")
            refund = matching
        total = sum(r["amount"] for r in refunds if r["state"] == "confirmed")
        if any(r["state"] == "confirmed" and (r["external_reference_hash"] is None
               or r["external_reference"] is None or r["confirmed_at"] is None) for r in refunds):
            raise CheckoutError("refund_external_confirmation_missing")
        if refund is not None and refund["state"] == "confirmed":
            if refund["external_reference_hash"] != identity or result != "success":
                raise CheckoutError("refund_external_evidence_conflict")
            return RefundResult(False, total == payment["amount"], refund=refund)
        if replay:
            raise CheckoutError("refund_external_confirmation_missing")
        if refund is None:
            if any(r["state"] != "confirmed" for r in refunds):
                raise CheckoutError("refund_registered_entitlement_unresolved")
            remaining = payment["amount"] - total
            if remaining == 0:
                return RefundResult(False, True)
            if any(r["warranty_claim_id"] is None and r["supplier_fulfillment_id"] is None for r in refunds):
                raise CheckoutError("refund_identity_conflict")
            if result == "failure":
                return RefundResult(False, False)
            if self.encrypter is None:
                raise CheckoutError("refund_encrypter_unavailable")
            refund = self.db.one("""INSERT INTO public.order_refunds
                (id,order_id,payment_id,customer_id,amount,currency,state,external_reference,
                 external_reference_hash,actor_user_id,confirmed_at,created_at,updated_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,
                    CASE WHEN %s THEN CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' ELSE NULL END,
                    CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                    CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta') RETURNING *""",
                (str(uuid4()), order["id"], payment["id"], order["customer_id"], remaining,
                 payment["currency"], "confirmed" if result == "success" else "uncertain",
                 self.encrypter.encrypt_string(reference), identity, actor_id, result == "success"))
            return RefundResult(True, result == "success", refund=refund)
        if refund["state"] not in ("requested", "uncertain"):
            raise CheckoutError("refund_entitlement_state_invalid")
        if refund["state"] == "uncertain" and refund["external_reference_hash"] != identity:
            raise CheckoutError("refund_external_evidence_conflict")
        if refund["warranty_claim_id"] is not None or refund["supplier_fulfillment_id"] is not None:
            self._assert_entitlement(order, payment, refund)
        elif refund["amount"] != payment["amount"] - total:
            raise CheckoutError("refund_exceeds_original")
        if result == "failure":
            return RefundResult(False, False, refund=refund)
        if (result == "uncertain" and refund["state"] == "uncertain"
                and refund["external_reference_hash"] == identity):
            return RefundResult(False, False, refund=refund)
        if self.encrypter is None:
            raise CheckoutError("refund_encrypter_unavailable")
        refund = self.db.one("""UPDATE public.order_refunds SET state=%s,external_reference=%s,
            external_reference_hash=%s,actor_user_id=%s,
            confirmed_at=CASE WHEN %s THEN CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' ELSE NULL END,
            updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s RETURNING *""",
            ("confirmed" if result == "success" else "uncertain",
             refund["external_reference"] or self.encrypter.encrypt_string(reference),
             identity, actor_id, result == "success", refund["id"]))
        return RefundResult(True, result == "success" and total + refund["amount"] == payment["amount"], refund=refund)
