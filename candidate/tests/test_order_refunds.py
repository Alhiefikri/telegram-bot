"""P12 behavioral proof; invoked by the admitted disposable PostgreSQL smoke."""
from uuid import uuid4


def exercise_order_refunds(fixtures):
    from django.db import transaction
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.checkout.errors import CheckoutError
    from lhie_candidate.checkout.refunds import OrderRefunds, _fingerprint
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from tests.test_checkout_postgres import admin_connection

    db = Database()
    admin = admin_connection()
    crypto = LaravelEncrypter("c" * 32)
    refunds = OrderRefunds(encrypter=crypto)
    customer = fixtures["python"]["customer_id"]
    foreign = fixtures["foreign"]["customer_id"]
    product = fixtures["python"]["product_id"]
    now = "CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'"

    def reject(action, expected):
        try:
            action()
        except CheckoutError as error:
            assert error.code == expected, (error.code, expected)
        else:
            raise AssertionError("Expected refund rejection: " + expected)

    def apply(case, **kwargs):
        with transaction.atomic():
            db.one("SELECT id FROM public.customers WHERE id=%s FOR SHARE", (customer,))
            payment = db.one("SELECT id,order_id,provider,method,amount,currency,status,paid_at FROM public.payments WHERE id=%s", (case["payment"],))
            account = refunds.lock_account(customer, payment)
            order = db.one("SELECT id,customer_id,total_amount,currency,order_status,payment_status FROM public.orders WHERE id=%s FOR UPDATE", (case["order"],))
            payments = db.rows("SELECT id,order_id,provider,method,amount,currency,status,paid_at FROM public.payments WHERE order_id=%s ORDER BY id FOR UPDATE", (case["order"],))
            from lhie_candidate.checkout.fulfillment import FulfillmentResources
            FulfillmentResources(encrypter=crypto).lock(case["order"])
            return refunds.apply_locked(order, payments[0], account=account, **kwargs)

    def seed(provider="store_balance", *, entitlement=True, amount=50, debit=True,
             external_usdt=False, external_rail="BINANCE_PAY_ID", payment_id=None):
        # Independent historical snapshots; current catalog prices are not refund authority.
        order_id, refund_id = str(uuid4()), str(uuid4())
        payment_id = payment_id or str(uuid4())
        currency = "USDT" if provider == "usdt_balance" else "IDR"
        method = {"store_balance": "STORE_BALANCE", "usdt_balance": "USDT_BALANCE"}.get(provider, "QR_MANUAL")
        payment_currency = "USDT" if external_usdt else currency
        if external_usdt:
            method = external_rail
        with admin.transaction():
            historical_product = admin.execute(f"""INSERT INTO public.products
                (name,description,price_amount,stock,reserved_stock,is_active,created_at,updated_at)
                VALUES (%s,'Synthetic historical product',50,0,0,true,{now},{now}) RETURNING id""",
                ("Synthetic historical refund " + order_id,)).fetchone()[0]
            admin.execute(f"""INSERT INTO public.orders
                (id,customer_id,invoice_number,order_status,payment_status,total_amount,currency,created_at,updated_at)
                VALUES (%s,%s,%s,'processing','paid',101,%s,{now},{now})""", (order_id, customer, "P12-" + order_id, currency))
            item_id = admin.execute(f"""INSERT INTO public.order_items
                (order_id,product_id,product_name,quantity,local_quantity,supplier_quantity,
                 unit_price,subtotal,usdt_subtotal,created_at,updated_at)
                VALUES (%s,%s,'Synthetic refund allocation',2,1,1,50,101,101,{now},{now}) RETURNING id""", (order_id, historical_product)).fetchone()[0]
            admin.execute(f"""INSERT INTO public.product_stock_items
                (product_id,order_item_id,secret,created_at,updated_at)
                VALUES (%s,%s,%s,{now},{now})""",
                (historical_product, item_id, crypto.encrypt_string("Synthetic retained healthy credential")))
            admin.execute(f"""INSERT INTO public.payments
                (id,order_id,provider,merchant_ref,method,amount,currency,status,paid_at,created_at,updated_at)
                VALUES (%s,%s,%s,%s,%s,101,%s,'paid',{now},{now},{now})""",
                (payment_id, order_id, provider, "P12-" + payment_id, method, payment_currency))
            for kind, quantity in (("reservation", 2), ("sale", -2)):
                admin.execute(f"""INSERT INTO public.inventory_movements
                    (product_id,order_id,type,quantity,notes,created_at) VALUES (%s,%s,%s,%s,'Synthetic historical sale',{now})""",
                    (historical_product, order_id, kind, quantity))
            if provider == "store_balance" and debit:
                account_id, balance = admin.execute("SELECT id,balance FROM public.customer_balance_accounts WHERE customer_id=%s FOR UPDATE", (customer,)).fetchone()
                assert balance >= 101, "Synthetic P07 balance must cover refund fixture debit"
                admin.execute(f"""INSERT INTO public.store_balance_entries
                    (customer_balance_account_id,kind,amount,balance_after,source_type,source_id,idempotency_key,created_at)
                    VALUES (%s,'purchase_debit',101,%s,'order',%s,%s,{now})""",
                    (account_id, balance - 101, order_id, f"order:{order_id}:debit"))
                admin.execute("UPDATE public.customer_balance_accounts SET balance=%s WHERE id=%s", (balance - 101, account_id))
            elif provider == "usdt_balance" and debit:
                admin.execute(f"""INSERT INTO public.customer_usdt_balance_accounts
                    (customer_id,created_at,updated_at) VALUES (%s,{now},{now}) ON CONFLICT (customer_id) DO NOTHING""", (customer,))
                account_id, balance, version = admin.execute("SELECT id,balance_micro,version FROM public.customer_usdt_balance_accounts WHERE customer_id=%s FOR UPDATE", (customer,)).fetchone()
                for kind, delta, source_type, source_id, fingerprint, debit, credit in (
                    ("funding_credit", 101, "funding_intent", str(uuid4()), "f" * 64, "usdt_settlement_asset", "customer_usdt_liability"),
                    ("purchase_debit", -101, "order", order_id, _fingerprint("purchase", "purchase_debit", customer, 101, order_id=order_id, payment_id=payment_id), "customer_usdt_liability", "usdt_sale"),
                ):
                    version += 1
                    balance += delta
                    entry_id = admin.execute(f"""INSERT INTO public.customer_usdt_balance_entries
                        (customer_usdt_balance_account_id,account_sequence,kind,amount_micro,balance_after_micro,
                         source_type,source_id,idempotency_key,request_fingerprint,created_at)
                        VALUES (%s,%s,%s,101,%s,%s,%s,%s,%s,{now}) RETURNING id""",
                        (account_id, version, kind, balance, source_type, source_id, "p12-" + source_id, fingerprint)).fetchone()[0]
                    admin.execute(f"""INSERT INTO public.customer_usdt_balance_accounting_events
                        (customer_usdt_balance_entry_id,currency,amount_micro,debit_account,credit_account,source_fingerprint,created_at)
                        VALUES (%s,'USDT',101,%s,%s,%s,{now})""", (entry_id, debit, credit, fingerprint))
                admin.execute("UPDATE public.customer_usdt_balance_accounts SET balance_micro=%s,version=%s,last_entry_id=%s WHERE id=%s", (balance, version, entry_id, account_id))
            fulfillment = None
            if entitlement:
                fulfillment = admin.execute(f"""INSERT INTO public.supplier_fulfillments
                    (order_id,order_item_id,quantity,state,maximum_cost_idr,sources_snapshot,created_at,updated_at)
                    VALUES (%s,%s,1,'refund_pending',1,%s,{now},{now}) RETURNING id""", (order_id, item_id, crypto.encrypt_json([]))).fetchone()[0]
                admin.execute(f"""INSERT INTO public.order_refunds
                    (id,order_id,payment_id,customer_id,supplier_fulfillment_id,missing_quantity,amount,currency,state,created_at,updated_at)
                    VALUES (%s,%s,%s,%s,%s,1,%s,%s,'requested',{now},{now})""",
                    (refund_id, order_id, payment_id, customer, fulfillment, amount, payment_currency))
        return dict(order=order_id, payment=payment_id, refund=refund_id, item=item_id,
                    fulfillment=fulfillment, product=historical_product)

    def warranty_case():
        case = seed(entitlement=False)
        with admin.transaction():
            admin.execute(f"""INSERT INTO public.supplier_currency_rates(currency,exchange_rate_idr,created_at,updated_at)
                VALUES ('IDR',1,{now},{now}) ON CONFLICT (currency) DO NOTHING""")
            supplier = admin.execute(f"""INSERT INTO public.suppliers
                (name,provider,api_key,currency,exchange_rate_idr,created_at,updated_at)
                VALUES ('P12 synthetic','canboso',%s,'IDR',
                    (SELECT exchange_rate_idr FROM public.supplier_currency_rates WHERE currency='IDR'),{now},{now}) RETURNING id""",
                (crypto.encrypt_string("synthetic-not-a-real-key"),)).fetchone()[0]
            catalog = admin.execute(f"""INSERT INTO public.supplier_catalog_items
                (supplier_id,external_id,name,created_at,updated_at)
                VALUES (%s,%s,'P12 synthetic',{now},{now}) RETURNING id""",
                (supplier, str(uuid4()))).fetchone()[0]
            fulfillment = admin.execute(f"""INSERT INTO public.supplier_fulfillments
                (order_id,order_item_id,quantity,state,maximum_cost_idr,sources_snapshot,created_at,updated_at)
                VALUES (%s,%s,1,'delivered',1,%s,{now},{now}) RETURNING id""",
                (case["order"], case["item"], crypto.encrypt_json([]))).fetchone()[0]
            purchase = admin.execute(f"""INSERT INTO public.supplier_purchases
                (supplier_fulfillment_id,supplier_id,supplier_catalog_item_id,reference,
                 quantity,observed_quantity,expected_cost_micros,actual_cost_micros,
                 exchange_rate_idr,state,committed_at,created_at,updated_at)
                VALUES (%s,%s,%s,%s,1,1,1,1,1,'settled',{now},{now},{now}) RETURNING id""",
                (fulfillment, supplier, catalog, str(uuid4()))).fetchone()[0]
            stock = admin.execute(f"""INSERT INTO public.product_stock_items
                (product_id,order_item_id,secret,supplier_purchase_id,supplier_unit_index,created_at,updated_at)
                VALUES (%s,%s,%s,%s,1,{now},{now}) RETURNING id""",
                (case["product"], case["item"], crypto.encrypt_string("synthetic-exposed-credential"), purchase)).fetchone()[0]
            unit = admin.execute(f"""INSERT INTO public.supplier_account_units
                (public_id,supplier_id,supplier_purchase_id,product_stock_item_id,order_id,
                 order_item_id,external_id,provider_state,refund_deadline,cost_idr,
                 retail_amount,retail_currency,unit_number,created_at,updated_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,'active',{now}+INTERVAL '1 day',1,50,'IDR',2,{now},{now})
                RETURNING id""",
                (str(uuid4()), supplier, purchase, stock, case["order"], case["item"], str(uuid4()))).fetchone()[0]
            claim = admin.execute(f"""INSERT INTO public.warranty_claims
                (public_id,supplier_account_unit_id,customer_id,order_id,proof_path,proof_digest,
                 note,state,refund_deadline,verified_at,settlement_choice,settlement_state,created_at,updated_at)
                VALUES (%s,%s,%s,%s,'synthetic-proof',%s,'Synthetic fixture','approved',
                    {now}+INTERVAL '1 day',{now},'refund','refund_pending',{now},{now}) RETURNING id""",
                (str(uuid4()), unit, customer, case["order"], "a" * 64)).fetchone()[0]
            admin.execute(f"""INSERT INTO public.order_refunds
                (id,order_id,payment_id,customer_id,supplier_account_unit_id,warranty_claim_id,
                 amount,currency,state,created_at,updated_at)
                VALUES (%s,%s,%s,%s,%s,%s,50,'IDR','requested',{now},{now})""",
                (case["refund"], case["order"], case["payment"], customer, unit, claim))
        return dict(case, stock=stock, unit=unit, claim=claim, supplier=supplier, catalog=catalog, purchase=purchase)

    def prove_supplier_release(case):
        from lhie_candidate.checkout.lifecycle import OrderLifecycle
        order_id, payment_id = str(uuid4()), str(uuid4())
        with admin.transaction():
            admin.execute(f"""INSERT INTO public.orders
                (id,customer_id,invoice_number,order_status,payment_status,total_amount,currency,expires_at,created_at,updated_at)
                VALUES (%s,%s,%s,'pending_payment','pending',50,'IDR',{now}+INTERVAL '15 minutes',{now},{now})""",
                (order_id, customer, "P12-hold-" + order_id))
            item = admin.execute(f"""INSERT INTO public.order_items
                (order_id,product_id,product_name,quantity,local_quantity,supplier_quantity,unit_price,subtotal,created_at,updated_at)
                VALUES (%s,%s,'Synthetic retained supplier hold',1,0,1,50,50,{now},{now}) RETURNING id""",
                (order_id, case["product"])).fetchone()[0]
            admin.execute(f"""INSERT INTO public.payments
                (id,order_id,provider,merchant_ref,method,amount,currency,status,expired_at,created_at,updated_at)
                VALUES (%s,%s,'manual',%s,'QR_MANUAL',50,'IDR','pending',{now}+INTERVAL '15 minutes',{now},{now})""",
                (payment_id, order_id, "P12-hold-" + payment_id))
            admin.execute(f"""INSERT INTO public.inventory_movements(product_id,order_id,type,quantity,notes,created_at)
                VALUES (%s,%s,'reservation',1,'Synthetic external reservation',{now})""", (case["product"], order_id))
            snapshot = crypto.encrypt_json([{"supplier_id": case["supplier"], "catalog_item_id": case["catalog"]}])
            admin.execute(f"""INSERT INTO public.supplier_fulfillments
                (order_id,order_item_id,quantity,state,maximum_cost_idr,sources_snapshot,
                 reserved_supplier_id,reserved_catalog_item_id,reserved_cost_micros,reserved_quantity,created_at,updated_at)
                VALUES (%s,%s,1,'reserved',50,%s,%s,%s,7,1,{now},{now})""",
                (order_id, item, snapshot, case["supplier"], case["catalog"]))
            admin.execute("UPDATE public.suppliers SET reserved_balance_micros=reserved_balance_micros+7 WHERE id=%s", (case["supplier"],))
            admin.execute("UPDATE public.supplier_catalog_items SET reserved_quantity=reserved_quantity+1 WHERE id=%s", (case["catalog"],))
        service = OrderLifecycle(encrypter=crypto)
        before = admin.execute("SELECT reserved_balance_micros,version FROM public.suppliers WHERE id=%s", (case["supplier"],)).fetchone()
        admin.execute("UPDATE public.supplier_fulfillments SET current_purchase_id=%s WHERE order_id=%s", (case["purchase"], order_id))
        reject(lambda: service.apply_pending_cancellation(order_id, customer_id=customer), "supplier_purchase_identity_invalid")
        assert admin.execute("SELECT reserved_balance_micros,version FROM public.suppliers WHERE id=%s", (case["supplier"],)).fetchone() == before
        assert admin.execute("SELECT state FROM public.supplier_purchases WHERE id=%s", (case["purchase"],)).fetchone() == ("settled",)
        admin.execute("UPDATE public.supplier_fulfillments SET current_purchase_id=NULL WHERE order_id=%s", (order_id,))
        assert service.apply_pending_cancellation(order_id, customer_id=customer).newly_applied
        assert not service.apply_pending_cancellation(order_id, customer_id=customer).newly_applied
        assert admin.execute("SELECT reserved_balance_micros,version FROM public.suppliers WHERE id=%s", (case["supplier"],)).fetchone() == (before[0] - 7, before[1] + 1)
        assert admin.execute("SELECT reserved_quantity FROM public.supplier_catalog_items WHERE id=%s", (case["catalog"],)).fetchone() == (0,)

    def prove_claimed_rails():
        from lhie_candidate.checkout.lifecycle import OrderLifecycle
        service = OrderLifecycle(encrypter=crypto)
        from lhie_candidate.payments.claimed_order import order_receipt_claimed
        def claimed(case):
            with transaction.atomic():
                order, payment, _, _ = service.lock_payment(case["payment"])
                return order_receipt_claimed(db, order, payment)
        for rail, vector in fixtures["p12_receipts"].items():
            case = seed("manual", external_usdt=True, payment_id=vector["target_id"],
                        external_rail="USDT_BEP20" if rail == "bsc" else "BINANCE_PAY_ID")
            assert not claimed(case)
            if rail == "bsc":
                address, token = vector["beneficiary"], "0x" + "c" * 40
                claim = admin.execute(f"""INSERT INTO public.usdt_receiver_address_claims
                    (chain_id,address_normalized,channel,claimed_at) VALUES (56,%s,'checkout',{now}) RETURNING id""", (address,)).fetchone()[0]
                receiver = admin.execute(f"""INSERT INTO public.bsc_payment_receivers
                    (label,address,address_normalized,chain_id,token_contract,token_decimals,address_claim_id,created_at,updated_at)
                    VALUES ('Synthetic P12',%s,%s,56,%s,18,%s,{now},{now}) RETURNING id""", (address, address, token, claim)).fetchone()[0]
                admin.execute(f"""INSERT INTO public.payment_bsc_details
                    (payment_id,receiver_id,receiver_address,chain_id,token_contract,token_decimals,
                     base_amount_micro,payable_amount_micro,expected_token_units,created_at,updated_at)
                    VALUES (%s,%s,%s,56,%s,18,101,101,%s,{now},{now})""",
                    (case["payment"], receiver, address, token, vector["amount_units"]))
            columns = list(vector)
            receipt_id = admin.execute("INSERT INTO public.external_money_receipts (" + ",".join(columns)
                + ",status,claimed_at,created_at,updated_at) VALUES (" + ",".join(["%s"] * len(columns))
                + f",'claimed',{now},{now},{now}) RETURNING id", tuple(vector.values())).fetchone()[0]
            if rail == "bsc":
                assert not claimed(case)
                admin.execute(f"""INSERT INTO public.external_money_receipt_parent_claims
                    (chain_id,tx_hash,external_money_receipt_id,status,target_type,target_id,created_at,updated_at)
                    VALUES (56,%s,%s,'claimed','order_payment',%s,{now},{now})""",
                    (vector["tx_hash"], receipt_id, case["payment"]))
            assert claimed(case)
            assert not service.apply_payment_paid(case["payment"]).newly_applied

    def economics(case, usdt=False):
        table = "customer_usdt_balance_entries" if usdt else "store_balance_entries"
        amount = "amount_micro" if usdt else "amount"
        return admin.execute(f"""SELECT COUNT(*),COALESCE(SUM({amount}),0)
            FROM public.{table} WHERE kind='refund_credit'
            AND ((source_type='order' AND source_id=%s) OR refund_order_id=%s)""", (case["order"], case["order"])).fetchone()

    try:
        assert db.one("SELECT current_database() AS db,current_user AS role") == {"db": "disposable", "role": "candidate_runtime"}
        fixed_order = "69400000-0000-4000-8000-000000000001"
        fixed_payment = "69400000-0000-4000-8000-000000000002"
        assert _fingerprint("purchase", "purchase_debit", customer, 101,
            order_id=fixed_order, payment_id=fixed_payment) == fixtures["p12_fingerprints"]["purchase"]
        assert _fingerprint("verified_refund", "refund_credit", customer, 51,
            refund_id=fixed_order, order_id=fixed_order, original_entry_id=42,
            payment_id=fixed_payment, full_order=True) == fixtures["p12_fingerprints"]["refund"]
        prove_claimed_rails()
        # A registered missing unit receives floor(101/2)=50, not an arbitrary caller amount.
        idr = seed()
        reject(lambda: apply(idr), "refund_registered_entitlement_unresolved")
        first = apply(idr, refund_id=idr["refund"])
        assert first.newly_applied and not first.fully_refunded and first.entry["amount"] == 50
        assert admin.execute("SELECT state,refunded_quantity FROM public.supplier_fulfillments WHERE id=%s", (idr["fulfillment"],)).fetchone() == ("delivered", 1)
        replay = apply(idr, refund_id=idr["refund"])
        assert not replay.newly_applied and replay.entry["id"] == first.entry["id"]
        remainder = apply(idr)
        assert remainder.newly_applied and remainder.fully_refunded and remainder.entry["amount"] == 51
        assert not apply(idr).newly_applied and economics(idr) == (2, 101)
        full = seed(entitlement=False)
        assert apply(full).entry["amount"] == 101
        assert not apply(full).newly_applied and economics(full) == (1, 101)
        admin.execute("UPDATE public.orders SET order_status='cancelled',payment_status='refunded' WHERE id=%s", (full["order"],))
        admin.execute("UPDATE public.payments SET status='refunded' WHERE id=%s", (full["payment"],))
        assert not apply(full).newly_applied and economics(full) == (1, 101)
        absent = seed(entitlement=False, debit=False)
        reject(lambda: apply(absent), "refund_original_debit_missing")
        assert economics(absent) == (0, 0)

        invalid = seed(amount=51)
        reject(lambda: apply(invalid, refund_id=invalid["refund"]), "refund_entitlement_invalid")
        assert economics(invalid) == (0, 0)
        cap = seed(amount=102)
        reject(lambda: apply(cap, refund_id=cap["refund"]), "refund_exceeds_original")
        assert economics(cap) == (0, 0)
        foreign_case = seed()
        admin.execute("UPDATE public.order_refunds SET customer_id=%s WHERE id=%s", (foreign, foreign_case["refund"]))
        reject(lambda: apply(foreign_case, refund_id=foreign_case["refund"]), "refund_identity_conflict")
        assert economics(foreign_case) == (0, 0)
        # A caller cannot substitute a different account owner even with a valid entitlement.
        with transaction.atomic():
            payment = db.one("SELECT id,order_id,provider,method,amount,currency,status,paid_at FROM public.payments WHERE id=%s", (invalid["payment"],))
            db.one("SELECT id FROM public.customers WHERE id=%s FOR SHARE", (foreign,))
            other_account = refunds.lock_account(foreign, payment)
            order = db.one("SELECT id,customer_id,total_amount,currency,order_status,payment_status FROM public.orders WHERE id=%s FOR UPDATE", (invalid["order"],))
            db.rows("SELECT id FROM public.payments WHERE order_id=%s ORDER BY id FOR UPDATE", (invalid["order"],))
            reject(lambda: refunds.apply_locked(order, payment, account=other_account, refund_id=invalid["refund"]), "refund_original_debit_identity_invalid")

        # Verified warranty refund retires the exact exposed credential without unassigning it.
        warranty = warranty_case()
        prove_supplier_release(warranty)
        admin.execute("UPDATE public.supplier_account_units SET confirmation_state='unknown' WHERE id=%s", (warranty["unit"],))
        reject(lambda: apply(warranty, refund_id=warranty["refund"]), "refund_supplier_evidence_unresolved")
        assert economics(warranty) == (0, 0)
        admin.execute("UPDATE public.supplier_account_units SET confirmation_state=NULL WHERE id=%s", (warranty["unit"],))
        warranty_result = apply(warranty, refund_id=warranty["refund"])
        assert warranty_result.entry["amount"] == 50
        assert admin.execute("SELECT settlement_state FROM public.warranty_claims WHERE id=%s", (warranty["claim"],)).fetchone() == ("refunded",)
        retired = admin.execute("SELECT order_item_id,retired_at IS NOT NULL,retired_reason FROM public.product_stock_items WHERE id=%s", (warranty["stock"],)).fetchone()
        assert retired == (warranty["item"], True, "warranty_refund")
        assert not apply(warranty, refund_id=warranty["refund"]).newly_applied
        from lhie_candidate.receipt.source import ReceiptSource
        telegram_id = admin.execute("SELECT telegram_id FROM public.customers WHERE id=%s", (customer,)).fetchone()[0]
        warranty_live = ReceiptSource(crypto).load(warranty["order"], telegram_id)
        assert warranty_live["allocated_count"] == 1
        assert "synthetic-exposed-credential" not in str(warranty_live["groups"])
        assert apply(warranty).entry["amount"] == 51 and economics(warranty) == (2, 101)

        # Integer micro-USDT credits use only the USDT ledger and its accounting event.
        usdt = seed("usdt_balance")
        idr_before = admin.execute("SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s", (customer,)).fetchone()
        partial = apply(usdt, refund_id=usdt["refund"])
        assert partial.entry["amount_micro"] == 50 and not partial.fully_refunded
        final = apply(usdt)
        assert final.entry["amount_micro"] == 51 and final.fully_refunded
        assert not apply(usdt).newly_applied and economics(usdt, True) == (2, 101)
        admin.execute("UPDATE public.customer_usdt_balance_accounts SET frozen_reason='synthetic-replay-proof' WHERE customer_id=%s", (customer,))
        assert not apply(usdt).newly_applied and economics(usdt, True) == (2, 101)
        admin.execute("UPDATE public.customer_usdt_balance_accounts SET frozen_reason=NULL WHERE customer_id=%s", (customer,))
        assert admin.execute("SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s", (customer,)).fetchone() == idr_before
        event = admin.execute("""SELECT currency,amount_micro,debit_account,credit_account,source_fingerprint
            FROM public.customer_usdt_balance_accounting_events WHERE customer_usdt_balance_entry_id=%s""", (final.entry["id"],)).fetchone()
        assert event == ("USDT", 51, "usdt_sale_refund", "customer_usdt_liability", final.entry["request_fingerprint"])
        assert final.entry["request_fingerprint"] == _fingerprint("verified_refund", "refund_credit", customer, 51,
            refund_id=usdt["order"], order_id=usdt["order"], original_entry_id=final.entry["original_entry_id"], payment_id=usdt["payment"], full_order=True)
        from lhie_candidate.receipt.service import ReceiptService
        from lhie_candidate.receipt.source import ReceiptSource
        from lhie_candidate.receipt.transport import ReceiptResult
        class SyntheticTelegram:
            def __init__(self):
                self.calls = []
                self.ambiguous = False
            def execute(self, method, payload):
                self.calls.append((method, payload))
                if self.ambiguous:
                    raise TimeoutError('Synthetic ACK loss')
                return ReceiptResult(42, 'success')
        transport = SyntheticTelegram()
        receipts = ReceiptService(ReceiptSource(crypto), transport, None)
        first_effect = db.one("SELECT id,state FROM public.telegram_outbound_effects WHERE effect_key=%s", (f"usdt-refund:{partial.entry['id']}:receipt:v1",))
        final_effect = db.one("SELECT id,state FROM public.telegram_outbound_effects WHERE effect_key=%s", (f"usdt-refund:{final.entry['id']}:receipt:v1",))
        admin.execute(f"""INSERT INTO public.customer_usdt_funding_pauses(scope,reason,paused_at,created_at,updated_at)
            VALUES ('delivery','Synthetic delivery pause',{now},{now},{now})""")
        assert receipts.deliver(first_effect["id"]) == "not_ready"
        assert db.one("SELECT state,attempts FROM public.telegram_outbound_effects WHERE id=%s", (first_effect["id"],)) == {"state": "pending", "attempts": 0}
        admin.execute("DELETE FROM public.customer_usdt_funding_pauses WHERE scope='delivery'")
        assert receipts.deliver(first_effect["id"]) == "sent"
        assert receipts.deliver(first_effect["id"]) == "not_ready"
        assert "0.00005" in transport.calls[0][1]["text"]
        transport.ambiguous = True
        assert receipts.deliver(final_effect["id"]) == "unknown"
        assert not apply(usdt).newly_applied
        assert receipts.deliver(final_effect["id"]) == "not_ready"
        assert len(transport.calls) == 2 and economics(usdt, True) == (2, 101)

        # External uncertainty reserves the registered entitlement without credit or full status.
        actor = admin.execute("SELECT id FROM public.users ORDER BY id LIMIT 1").fetchone()
        if actor is None:
            actor = admin.execute(f"""INSERT INTO public.users(name,email,password,role,created_at,updated_at)
                VALUES ('P12 synthetic',%s,'unusable-synthetic-password','owner',{now},{now}) RETURNING id""",
                (f"p12-{uuid4()}@example.invalid",)).fetchone()
        from lhie_candidate.checkout.lifecycle import OrderLifecycle
        public = seed()
        public_result = OrderLifecycle(encrypter=crypto).apply_payment_refunded(
            public["payment"], actor_user_id=actor[0], refund_id=public["refund"])
        assert not public_result.fully_refunded
        assert admin.execute("SELECT order_status FROM public.orders WHERE id=%s", (public["order"],)).fetchone() == ("completed",)
        telegram_id = admin.execute("SELECT telegram_id FROM public.customers WHERE id=%s", (customer,)).fetchone()[0]
        retained = ReceiptSource(crypto).load(public["order"], telegram_id)
        assert retained["allocated_count"] == 1
        assert "Synthetic retained healthy credential" in str(retained["groups"])
        external = seed("manual")
        uncertain = apply(external, refund_id=external["refund"], reference="p12-partial-transfer", result="uncertain", actor_user_id=actor[0])
        assert not uncertain.fully_refunded and uncertain.refund["state"] == "uncertain"
        cipher = uncertain.refund["external_reference"]
        assert cipher != "p12-partial-transfer" and crypto.decrypt_string(cipher) == "p12-partial-transfer"
        reject(lambda: apply(external, reference="different-remainder", actor_user_id=actor[0]), "refund_registered_entitlement_unresolved")
        reject(lambda: apply(external, refund_id=external["refund"], reference="different-transfer", actor_user_id=actor[0]), "refund_external_evidence_conflict")
        failed = apply(external, refund_id=external["refund"], reference="p12-partial-transfer", result="failure", actor_user_id=actor[0])
        assert not failed.newly_applied and failed.refund["state"] == "uncertain"
        confirmed = apply(external, refund_id=external["refund"], reference="p12-partial-transfer", actor_user_id=actor[0])
        assert confirmed.refund["external_reference"] == cipher and not confirmed.fully_refunded
        assert not apply(external, refund_id=external["refund"], reference="p12-partial-transfer", actor_user_id=actor[0]).newly_applied
        pending_remainder = apply(external, reference="p12-remainder-transfer", result="uncertain", actor_user_id=actor[0])
        assert pending_remainder.refund["amount"] == 51 and not pending_remainder.fully_refunded
        assert not apply(external, reference="p12-remainder-transfer", result="uncertain", actor_user_id=actor[0]).newly_applied
        complete = apply(external, reference="p12-remainder-transfer", actor_user_id=actor[0])
        assert complete.fully_refunded and complete.refund["amount"] == 51
        assert not apply(external).newly_applied and economics(external) == (0, 0)
        assert admin.execute("SELECT SUM(amount),COUNT(*) FROM public.order_refunds WHERE payment_id=%s AND state='confirmed'", (external["payment"],)).fetchone() == (101, 2)
        assert admin.execute("SELECT payment_status,order_status FROM public.orders WHERE id=%s", (external["order"],)).fetchone() == ("paid", "processing")
        claimed = seed("manual", entitlement=False)
        reject(lambda: apply(claimed, reference="p12-remainder-transfer", actor_user_id=actor[0]), "refund_external_evidence_claimed")
        unknown_full = apply(claimed, reference="p12-whole-transfer", result="uncertain", actor_user_id=actor[0])
        assert unknown_full.refund["amount"] == 101 and not unknown_full.fully_refunded
        reject(lambda: apply(claimed), "refund_external_evidence_invalid")
        full_confirmed = apply(claimed, reference="p12-whole-transfer", actor_user_id=actor[0])
        assert full_confirmed.fully_refunded and full_confirmed.refund["id"] == unknown_full.refund["id"]
        assert not apply(claimed).newly_applied and economics(claimed) == (0, 0)
        historical = seed("manual", entitlement=False)
        admin.execute("UPDATE public.orders SET order_status='cancelled',payment_status='refunded' WHERE id=%s", (historical["order"],))
        admin.execute("UPDATE public.payments SET status='refunded' WHERE id=%s", (historical["payment"],))
        historical_result = apply(historical)
        assert not historical_result.newly_applied and historical_result.fully_refunded
        assert admin.execute("SELECT COUNT(*) FROM public.order_refunds WHERE payment_id=%s", (historical["payment"],)).fetchone() == (0,)
        mixed = seed("manual", external_usdt=True)
        original_idr = admin.execute("SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s", (customer,)).fetchone()
        partial_usdt = apply(mixed, refund_id=mixed["refund"], reference="p12-external-usdt-partial", actor_user_id=actor[0])
        assert partial_usdt.refund["currency"] == "USDT" and partial_usdt.refund["amount"] == 50
        remainder_usdt = apply(mixed, reference="p12-external-usdt-remainder", actor_user_id=actor[0])
        assert remainder_usdt.fully_refunded and remainder_usdt.refund["amount"] == 51
        assert admin.execute("SELECT currency FROM public.orders WHERE id=%s", (mixed["order"],)).fetchone() == ("IDR",)
        assert admin.execute("SELECT balance FROM public.customer_balance_accounts WHERE customer_id=%s", (customer,)).fetchone() == original_idr
        return {"idr_partial_remainder": "50+51=101_once", "original_usdt": "micro_usdt_only_with_accounting",
                "foreign_customer": "rejected", "entitlement_and_cap": "enforced",
                "exposed_warranty_credential": "retired_without_unassignment",
                "external_unknown": "preserved_until_same_evidence_confirmation",
                "original_external_usdt": "IDR Order / USDT Payment retained without FX",
                "historical_rail_claims": "PHP Binance/BSC receipt and fingerprint vectors accepted",
                "supplier_release": "public lifecycle releases one historical hold exactly once",
                "retained_access": "healthy credentials remain readable after partial settlement",
                "refund_delivery": "one sent effect; lost ACK remains unknown without replay"}
    finally:
        admin.close()
