"""P22 admin shell/catalog/stock proof inside the disposable P01 database."""

import hashlib
import json
import multiprocessing
import re
import uuid

from tests.test_checkout_postgres import admin_connection


def csrf(response):
    match = re.search(rb'name="csrfmiddlewaretoken" value="([^"]+)"', response.content)
    assert match is not None, ("csrf form missing", response.status_code)
    return match.group(1).decode()


def admin_race(spec, barrier, queue):
    try:
        from lhie_candidate.startup import configure_django
        configure_django()
        from django.conf import settings
        from django.db import connections
        from lhie_candidate.admin.errors import AdminValidationError
        from lhie_candidate.admin.stock import StockService
        settings.CANDIDATE_IDENTITY_FINGERPRINT_KEY = spec["identity_key"]
        service = StockService()
        barrier.wait(timeout=15)
        try:
            result = service.submit(
                spec["product_id"],
                {
                    "stock_format": "email_password_access_token",
                    "stock_items": spec["line"],
                    "operation_key": spec["operation_key"],
                },
                spec["actor_id"],
                "127.0.30.99",
            )
            queue.put(("accepted", result["stock_after"]))
        except AdminValidationError as error:
            queue.put(("rejected", error.status))
        finally:
            connections.close_all()
    except Exception as error:
        queue.put(("failed", type(error).__name__))


def compete(specs):
    from django.db import connections
    connections.close_all()
    context = multiprocessing.get_context("spawn")
    barrier = context.Barrier(len(specs) + 1)
    queue = context.Queue()
    children = [context.Process(target=admin_race, args=(spec, barrier, queue)) for spec in specs]
    try:
        for child in children:
            child.start()
        barrier.wait(timeout=15)
        results = [queue.get(timeout=30) for _ in children]
        for child in children:
            child.join(timeout=10)
            assert child.exitcode == 0, "admin race worker failed"
        assert not any(state == "failed" for state, _ in results), results
        return results
    finally:
        for child in children:
            if child.is_alive():
                child.kill()
                child.join()
        queue.close()


def exercise_admin(fixtures):
    from django.db import connections
    from django.test import Client, override_settings
    from lhie_candidate.admin import credentials
    from lhie_candidate.admin.catalog import CatalogService
    from lhie_candidate.admin.stock import StockService
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.readiness import verify_dependencies
    from lhie_candidate.runtime import application_key

    admin = admin_connection()
    verify_dependencies()
    identity_key = "p22-synthetic-identity-key"
    identity_digest = hashlib.sha256(identity_key.encode()).hexdigest()
    owner_id = int(fixtures["auth"]["admin"]["id"])
    password = fixtures["auth"]["password"]
    cipher = LaravelEncrypter(application_key())

    def browser(ip="127.0.30.10"):
        return Client(enforce_csrf_checks=True, HTTP_HOST="localhost",
                      HTTP_ORIGIN="https://localhost", HTTP_ACCEPT="text/html",
                      REMOTE_ADDR=ip, raise_request_exception=False)

    def form_csrf(client, path):
        response = client.get(path, secure=True)
        assert response.status_code == 200, ("form", path, response.status_code)
        return csrf(response)

    with override_settings(CANDIDATE_IDENTITY_FINGERPRINT_KEY=identity_key):
        admin.execute("""INSERT INTO public.credential_identity_backfill_readiness
            (singleton_key,status,fingerprint_key_digest,scan_upper_bound_id,last_processed_id,
             scanned_count,registered_count,already_registered_count,skipped_unrecognized_count,
             conflict_count,unresolved_count,started_at,completed_at,ready_at,created_at,updated_at)
            VALUES ('credential_identity_registry','ready',%s,0,0,0,0,0,0,0,0,now(),now(),now(),now(),now())
            ON CONFLICT (singleton_key) DO UPDATE SET status='ready',
              fingerprint_key_digest=EXCLUDED.fingerprint_key_digest,scan_upper_bound_id=0,
              completed_at=now(),ready_at=now(),conflict_count=0,unresolved_count=0""",
            (identity_digest,))

        # Unauthenticated HTML goes to the login page; JSON remains P19-compatible.
        guest = browser("127.0.30.11")
        denied = guest.get("/admin", secure=True)
        assert denied.status_code == 302 and denied["Location"].startswith("/admin/login?next=/admin")
        assert guest.get("/admin/produk/1", secure=True).status_code == 302
        login_form = guest.get("/admin/login", secure=True)
        assert login_form.status_code == 200 and b'name="csrfmiddlewaretoken"' in login_form.content
        token = csrf(login_form)
        assert guest.post("/admin/login", {"email": fixtures["auth"]["admin"]["email"],
                                           "password": password}, secure=True,
                          HTTP_ACCEPT="text/html").status_code == 403, "login CSRF"
        assert guest.post("/admin/login", {"csrfmiddlewaretoken": token,
                                           "email": fixtures["auth"]["admin"]["email"],
                                           "password": password}, secure=True,
                          HTTP_ACCEPT="text/html").status_code == 302, "owner login"

        owner = browser()
        login_page = owner.get("/admin/login", secure=True)
        token = csrf(login_page)
        response = owner.post("/admin/login", {"csrfmiddlewaretoken": token,
                                               "email": fixtures["auth"]["admin"]["email"],
                                               "password": password}, secure=True,
                              HTTP_ACCEPT="text/html")
        assert response.status_code == 302 and response["Location"] == "/admin"
        dashboard = owner.get("/admin", secure=True)
        assert dashboard.status_code == 200
        assert b"data-admin-drawer-open" in dashboard.content
        assert b"admin-nojs-nav" in dashboard.content
        assert b"id=\"admin-drawer\"" in dashboard.content
        assert b"Ringkasan katalog" in dashboard.content
        products_tab = owner.get("/admin?tab=produk", secure=True)
        assert products_tab.status_code == 200 and b"Produk dan stok" in products_tab.content
        for path in ("/admin/kategori", "/admin/family", "/admin/banner", "/admin/produk/baru"):
            page = owner.get(path, secure=True)
            assert page.status_code == 200, (path, page.status_code, page.content[:800])
        filtered = owner.get("/admin?tab=produk&product_search=Synthetic%20P22%20Alpha&product_sort=price_desc",
                             secure=True)
        assert filtered.status_code == 200 and b"Synthetic P22 Alpha" in filtered.content

        # Direct role denial even when a forged/stale admin marker exists.
        admin.execute("UPDATE public.users SET role='support' WHERE id=%s", (owner_id,))
        try:
            assert owner.get("/admin", secure=True).status_code == 302
        finally:
            admin.execute("UPDATE public.users SET role='owner' WHERE id=%s", (owner_id,))
        # The rejected request discarded the marker; log in again for mutations.
        token = csrf(owner.get("/admin/login", secure=True))
        assert owner.post("/admin/login", {"csrfmiddlewaretoken": token,
                                           "email": fixtures["auth"]["admin"]["email"],
                                           "password": password}, secure=True,
                          HTTP_ACCEPT="text/html").status_code == 302

        # Finance/Support can never enter, even with a valid password.
        import bcrypt
        finance_hash = bcrypt.hashpw(b"p22-finance", bcrypt.gensalt(rounds=4)).decode()
        admin.execute("""INSERT INTO public.users(name,email,password,role,created_at,updated_at)
            VALUES ('P22 Finance','p22-finance@example.invalid',%s,'finance',now(),now())""",
            (finance_hash,))
        support_hash = bcrypt.hashpw(b"p22-support", bcrypt.gensalt(rounds=4)).decode()
        admin.execute("""INSERT INTO public.users(name,email,password,role,created_at,updated_at)
            VALUES ('P22 Support','p22-support@example.invalid',%s,'support',now(),now())""",
            (support_hash,))
        for email, secret in (("p22-finance@example.invalid", "p22-finance"),
                              ("p22-support@example.invalid", "p22-support")):
            client = browser("127.0.30.12")
            token = csrf(client.get("/admin/login", secure=True))
            rejected = client.post("/admin/login", {"csrfmiddlewaretoken": token,
                                                    "email": email, "password": secret},
                                   secure=True, HTTP_ACCEPT="text/html")
            assert rejected.status_code == 422
            assert client.get("/admin", secure=True).status_code == 302

        # Categories and families are created through the real HTML forms.
        token = csrf(owner.get("/admin/kategori/baru", secure=True))
        response = owner.post("/admin/kategori/baru", {
            "csrfmiddlewaretoken": token, "category_name": "Synthetic P22 Streaming",
            "category_description": "Kategori sintetis P22",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert response.status_code == 302
        category_a = admin.execute("SELECT id,slug FROM public.categories WHERE name='Synthetic P22 Streaming'").fetchone()
        assert category_a is not None
        token = csrf(owner.get("/admin/kategori/baru", secure=True))
        owner.post("/admin/kategori/baru", {"csrfmiddlewaretoken": token,
                                           "category_name": "Synthetic P22 Streaming"}, secure=True,
                   HTTP_ACCEPT="text/html")
        slugs = [row[0] for row in admin.execute(
            "SELECT slug FROM public.categories WHERE name='Synthetic P22 Streaming' ORDER BY id").fetchall()]
        assert len(slugs) == 2 and len(set(slugs)) == 2
        token = csrf(owner.get("/admin/kategori/baru", secure=True))
        owner.post("/admin/kategori/baru", {"csrfmiddlewaretoken": token,
                                           "category_name": "Synthetic P22 Other"}, secure=True,
                   HTTP_ACCEPT="text/html")
        category_b = admin.execute("SELECT id FROM public.categories WHERE name='Synthetic P22 Other'").fetchone()[0]
        category_edit = owner.get(f"/admin/kategori/{category_a[0]}", secure=True)
        assert category_edit.status_code == 200 and b"category_name" not in category_edit.content
        token = csrf(category_edit)
        assert owner.post(f"/admin/kategori/{category_a[0]}", {
            "csrfmiddlewaretoken": token, "name": "Synthetic P22 Streaming Updated",
            "description": "Kategori diperbarui", "sort_order": "4", "is_active": "1",
        }, secure=True, HTTP_ACCEPT="text/html").status_code == 302
        assert admin.execute("SELECT name,sort_order,is_active FROM public.categories WHERE id=%s",
                             (category_a[0],)).fetchone() == ("Synthetic P22 Streaming Updated", 4, True)
        token = csrf(owner.get("/admin/family/baru", secure=True))
        owner.post("/admin/family/baru", {"csrfmiddlewaretoken": token,
                                         "name": "Synthetic P22 Family", "sort_order": "3",
                                         "description": "Family sintetis"}, secure=True,
                   HTTP_ACCEPT="text/html")
        family = admin.execute("SELECT id,slug FROM public.product_families WHERE name='Synthetic P22 Family'").fetchone()
        assert family is not None
        family_edit = owner.get(f"/admin/family/{family[0]}", secure=True)
        token = csrf(family_edit)
        assert owner.post(f"/admin/family/{family[0]}", {
            "csrfmiddlewaretoken": token, "name": "Synthetic P22 Family Updated",
            "slug": "synthetic-p22-family-updated", "description": "Family diperbarui",
            "icon": "🎬", "sort_order": "4",
        }, secure=True, HTTP_ACCEPT="text/html").status_code == 302
        assert admin.execute("SELECT name,slug,sort_order FROM public.product_families WHERE id=%s",
                             (family[0],)).fetchone() == ("Synthetic P22 Family Updated",
                                                          "synthetic-p22-family-updated", 4)
        assert admin.execute("""SELECT product_family_id FROM public.product_family_slug_redirects
            WHERE old_slug=%s""", (family[1],)).fetchone() == (family[0],)

        def product_form(name, category, family_id=None, price="15000", usdt="1.5"):
            return {
                "name": name, "description": "Deskripsi sintetis P22",
                "description_ms": "Deskripsi MS", "description_en": "English description",
                "product_type": "Streaming", "access_duration": "1 bulan",
                "region": "Indonesia", "account_type": "Sharing", "device_limit": "2 device",
                "included_items": "Akun\nPanduan", "limitations": "Satu perangkat",
                "fulfillment_expectation": "Manual", "warranty_policy": "Garansi 7 hari",
                "warranty_policy_ms": "Waranti 7 hari", "warranty_policy_en": "7 day warranty",
                "support_policy": "Support 7 hari", "delivery_note": "Catatan rahasia sintetis",
                "usage_guide_title": "Panduan", "usage_guide_url": "/panduan",
                "price_amount": price, "usdt_price_amount": usdt,
                "reseller_price_amount": "14000", "reseller_usdt_price_amount": "1.4",
                "category_id": str(category),
                "product_family_id": "" if family_id is None else str(family_id),
                "family_sort_order": "0", "family_option_label": "Alpha",
                "icon": "🎬",
            }

        token = csrf(owner.get("/admin/produk/baru", secure=True))
        created = owner.post("/admin/produk/baru", {
            "csrfmiddlewaretoken": token, **product_form("Synthetic P22 Alpha", category_a[0], family[0]),
        }, secure=True, HTTP_ACCEPT="text/html")
        assert created.status_code == 302, ("create product", created.status_code)
        product = admin.execute("""SELECT id,stock,reserved_stock,is_active,currency,price_amount,
            usdt_price_amount,reseller_price_amount,product_family_id,category_id
            FROM public.products WHERE name='Synthetic P22 Alpha'""").fetchone()
        assert product is not None and product[1:4] == (0, 0, True) and product[4] == "IDR"
        product_id = int(product[0])
        assert admin.execute("SELECT action FROM public.audit_logs WHERE subject_id=%s AND action='product.created'",
                             (str(product_id),)).fetchone() == ("product.created",)

        token = csrf(owner.get("/admin/produk/baru", secure=True))
        mismatch = owner.post("/admin/produk/baru", {
            "csrfmiddlewaretoken": token, **product_form("Synthetic P22 Wrong Family", category_b, family[0]),
        }, secure=True, HTTP_ACCEPT="text/html")
        assert mismatch.status_code == 422 and b"kategori yang sama" in mismatch.content
        assert admin.execute("SELECT id FROM public.products WHERE name='Synthetic P22 Wrong Family'").fetchone() is None

        token = csrf(owner.get("/admin/produk/baru", secure=True))
        overflow = owner.post("/admin/produk/baru", {
            "csrfmiddlewaretoken": token,
            **{**product_form("Synthetic P22 Overflow", category_b), "price_amount": str((1 << 63) - 1)},
        }, secure=True, HTTP_ACCEPT="text/html")
        assert overflow.status_code == 422 and b"price_amount" in overflow.content
        assert admin.execute("SELECT id FROM public.products WHERE name='Synthetic P22 Overflow'").fetchone() is None

        # Price/tier/adapter validation and stale-version fencing.
        service = CatalogService()
        row = service.product(product_id)
        versions, _ = service.versions(row)
        workspace_page = owner.get(f"/admin/produk/{product_id}", secure=True)
        assert workspace_page.status_code == 200
        token = csrf(workspace_page)
        saved = owner.post(f"/admin/produk/{product_id}/ubah", {
            "csrfmiddlewaretoken": token, "workspace_section": "price",
            "workspace_version": versions["price"], "quantity_price_tiers_present": "1",
            "price_amount": "20000", "usdt_price_amount": "2.5",
            "reseller_price_amount": "19000", "reseller_usdt_price_amount": "2.4",
            "quantity_price_tiers.0.minimum_quantity": "2",
            "quantity_price_tiers.0.price_amount": "19000",
            "quantity_price_tiers.0.usdt_price_amount": "2.4",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert saved.status_code == 302, ("price save", saved.status_code)
        assert admin.execute("""SELECT price_amount,usdt_price_amount,reseller_price_amount,
            reseller_usdt_price_amount FROM public.products WHERE id=%s""", (product_id,)).fetchone() == (
                20000, 2_500_000, 19000, 2_400_000)
        assert admin.execute("""SELECT minimum_quantity,price_amount,usdt_price_amount
            FROM public.product_quantity_price_tiers WHERE product_id=%s""", (product_id,)).fetchall() == [
                (2, 19000, 2_400_000)]
        stale = owner.post(f"/admin/produk/{product_id}/ubah", {
            "csrfmiddlewaretoken": token, "workspace_section": "price",
            "workspace_version": versions["price"], "quantity_price_tiers_present": "1",
            "price_amount": "21000", "usdt_price_amount": "", "reseller_price_amount": "",
            "reseller_usdt_price_amount": "",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert stale.status_code == 409
        assert admin.execute("SELECT price_amount FROM public.products WHERE id=%s", (product_id,)).fetchone() == (20000,)
        versions, _ = service.versions(service.product(product_id))
        invalid_tier = owner.post(f"/admin/produk/{product_id}/ubah", {
            "csrfmiddlewaretoken": token, "workspace_section": "price",
            "workspace_version": versions["price"], "quantity_price_tiers_present": "1",
            "price_amount": "20000", "usdt_price_amount": "2.5",
            "quantity_price_tiers.0.minimum_quantity": "2",
            "quantity_price_tiers.0.price_amount": "25000",
            "quantity_price_tiers.0.usdt_price_amount": "2.6",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert invalid_tier.status_code == 422
        assert admin.execute("SELECT price_amount FROM public.products WHERE id=%s", (product_id,)).fetchone() == (20000,)

        # Legacy stock intake, replay, idempotency conflict and movement/audit evidence.
        operation = str(uuid.uuid4())
        secret_one = "P22-LEGACY-SECRET-ONE"
        secret_two = "P22-LEGACY-SECRET-TWO"
        token = csrf(owner.get(f"/admin/produk/{product_id}", secure=True))
        intake = owner.post(f"/admin/produk/{product_id}/stok", {
            "csrfmiddlewaretoken": token, "operation_key": operation, "stock_format": "legacy",
            "stock_items": secret_one + "\n" + secret_two,
        }, secure=True, HTTP_ACCEPT="text/html")
        assert intake.status_code == 302
        assert admin.execute("SELECT stock,reserved_stock FROM public.products WHERE id=%s",
                             (product_id,)).fetchone() == (2, 0)
        encrypted = admin.execute("""SELECT secret,credential_format,credential_payload
            FROM public.product_stock_items WHERE product_id=%s ORDER BY id""", (product_id,)).fetchall()
        assert len(encrypted) == 2
        assert [cipher.decrypt_string(item[0]) for item in encrypted] == [secret_one, secret_two]
        assert all(item[1] == "legacy" and item[2] is None for item in encrypted)
        assert admin.execute("SELECT type,quantity FROM public.inventory_movements WHERE product_id=%s",
                             (product_id,)).fetchone() == ("adjustment", 2)
        assert admin.execute("""SELECT item_count,stock_before,stock_after,inventory_movement_id IS NOT NULL
            FROM public.product_stock_intake_operations WHERE operation_key=%s""", (operation,)).fetchone() == (2, 0, 2, True)
        replay = owner.post(f"/admin/produk/{product_id}/stok", {
            "csrfmiddlewaretoken": token, "operation_key": operation, "stock_format": "legacy",
            "stock_items": secret_one + "\n" + secret_two,
        }, secure=True, HTTP_ACCEPT="text/html")
        assert replay.status_code == 302
        assert admin.execute("SELECT stock FROM public.products WHERE id=%s", (product_id,)).fetchone() == (2,)
        conflict = owner.post(f"/admin/produk/{product_id}/stok", {
            "csrfmiddlewaretoken": token, "operation_key": operation, "stock_format": "legacy",
            "stock_items": "P22-DIFFERENT-SECRET",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert conflict.status_code == 409
        assert admin.execute("SELECT stock FROM public.products WHERE id=%s", (product_id,)).fetchone() == (2,)
        workspace = owner.get(f"/admin/produk/{product_id}", secure=True)
        assert workspace.status_code == 200
        assert secret_one.encode() not in workspace.content, "workspace exposed a legacy secret"
        assert b"stock-intake-dialog" in workspace.content and b"stock-account-dialog" in workspace.content
        assert b"tier-row-template" in workspace.content
        assert b"style=" not in workspace.content

        # Bulk MFA validates shape and remains legacy storage.
        token = csrf(owner.get(f"/admin/produk/{product_id}", secure=True))
        mfa = owner.post(f"/admin/produk/{product_id}/stok", {
            "csrfmiddlewaretoken": token, "operation_key": str(uuid.uuid4()),
            "stock_format": "email_password_mfa_secret",
            "stock_items": "mfa@example.invalid|synthetic|123456",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert mfa.status_code == 302
        bad_mfa = owner.post(f"/admin/produk/{product_id}/stok", {
            "csrfmiddlewaretoken": token, "operation_key": str(uuid.uuid4()),
            "stock_format": "email_password_mfa_secret", "stock_items": "not-a-triplet",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert bad_mfa.status_code == 422
        assert admin.execute("SELECT stock FROM public.products WHERE id=%s", (product_id,)).fetchone() == (3,)

        # Structured access-token intake claims only Auth Token identities.
        bulk_token = "P22-AUTH-TOKEN-BULK"
        token = csrf(owner.get(f"/admin/produk/{product_id}", secure=True))
        bulk = owner.post(f"/admin/produk/{product_id}/stok", {
            "csrfmiddlewaretoken": token, "operation_key": str(uuid.uuid4()),
            "stock_format": "email_password_access_token",
            "stock_items": "bulk@example.invalid|synthetic|" + bulk_token,
        }, secure=True, HTTP_ACCEPT="text/html")
        assert bulk.status_code == 302, (bulk.status_code, bulk.content[:1200])
        structured = admin.execute("""SELECT credential_payload,secret FROM public.product_stock_items
            WHERE product_id=%s ORDER BY id DESC LIMIT 1""", (product_id,)).fetchone()
        assert structured[1] is None
        payload = cipher.decrypt_json(structured[0])
        assert payload["access_method"] == "auth_token" and payload["access_value"] == bulk_token
        fingerprint = credentials.fingerprint("auth_token", bulk_token)
        assert admin.execute("""SELECT product_stock_item_id FROM public.credential_identities
            WHERE identity_kind='auth_token' AND fingerprint=%s""", (fingerprint,)).fetchone() is not None
        duplicate = owner.post(f"/admin/produk/{product_id}/stok", {
            "csrfmiddlewaretoken": token, "operation_key": str(uuid.uuid4()),
            "stock_format": "email_password_access_token",
            "stock_items": "other@example.invalid|synthetic|" + bulk_token,
        }, secure=True, HTTP_ACCEPT="text/html")
        assert duplicate.status_code == 409
        assert admin.execute("SELECT stock FROM public.products WHERE id=%s", (product_id,)).fetchone() == (4,)

        # Structured account rows: one Auth Token and one OTP Mail Link.
        otp_token = "P22-AUTH-TOKEN-OTP"
        token = csrf(owner.get(f"/admin/produk/{product_id}", secure=True))
        structured_intake = owner.post(f"/admin/produk/{product_id}/stok/akun", {
            "csrfmiddlewaretoken": token, "operation_key": str(uuid.uuid4()),
            "accounts.0.email": "otp@example.invalid",
            "accounts.0.password": "synthetic",
            "accounts.0.access_method": "otp_mail_link",
            "accounts.0.access_value": "https://mail.example.invalid/inbox?token=synthetic",
            "accounts.1.email": "token@example.invalid",
            "accounts.1.password": "synthetic",
            "accounts.1.access_method": "auth_token",
            "accounts.1.access_value": otp_token,
        }, secure=True, HTTP_ACCEPT="text/html")
        assert structured_intake.status_code == 302, structured_intake.status_code
        rows = admin.execute("""SELECT credential_payload FROM public.product_stock_items
            WHERE product_id=%s ORDER BY id DESC LIMIT 2""", (product_id,)).fetchall()
        decoded = sorted((cipher.decrypt_json(row[0]) for row in rows), key=lambda item: item["email"])
        assert decoded[0]["access_method"] == "otp_mail_link"
        assert decoded[1]["access_value"] == otp_token
        token = csrf(owner.get(f"/admin/produk/{product_id}", secure=True))
        review = owner.post(f"/admin/produk/{product_id}/stok/akun/review", {
            "csrfmiddlewaretoken": token, "operation_key": str(uuid.uuid4()),
            "accounts.0.email": "token@example.invalid",
            "accounts.0.password": "synthetic",
            "accounts.0.access_method": "auth_token",
            "accounts.0.access_value": otp_token,
        }, secure=True, HTTP_ACCEPT="application/json")
        review_body = review.json()
        assert review.status_code == 200 and review_body["review"]["duplicate_count"] == 1
        assert b"otp@example.invalid" not in review.content
        assert b"synthetic" not in review.content

        # A credential that was exposed can never be deleted back into service.
        last_id = int(admin.execute("""SELECT id FROM public.product_stock_items
            WHERE product_id=%s ORDER BY id DESC LIMIT 1""", (product_id,)).fetchone()[0])
        admin.execute("UPDATE public.product_stock_items SET retired_at=now() WHERE id=%s", (last_id,))
        token = csrf(owner.get(f"/admin/produk/{product_id}", secure=True))
        assert owner.post(f"/admin/produk/{product_id}/stok/{last_id}/hapus", {
            "csrfmiddlewaretoken": token}, secure=True, HTTP_ACCEPT="text/html").status_code == 404
        assert admin.execute("SELECT retired_at IS NOT NULL FROM public.product_stock_items WHERE id=%s",
                             (last_id,)).fetchone() == (True,)

        # Reserving the projection blocks deletion; removing real available rows decrements once.
        stock_before = admin.execute("SELECT stock FROM public.products WHERE id=%s", (product_id,)).fetchone()[0]
        admin.execute("UPDATE public.products SET reserved_stock=stock WHERE id=%s", (product_id,))
        try:
            token = csrf(owner.get(f"/admin/produk/{product_id}", secure=True))
            blocked = owner.post(f"/admin/produk/{product_id}/stok/hapus-bulk", {
                "csrfmiddlewaretoken": token,
                "stock_item_ids": [str((admin.execute("""SELECT id FROM public.product_stock_items
                    WHERE product_id=%s AND order_item_id IS NULL AND retired_at IS NULL ORDER BY id LIMIT 1""",
                    (product_id,)).fetchone()[0]))],
            }, secure=True, HTTP_ACCEPT="text/html")
            assert blocked.status_code == 422
        finally:
            admin.execute("UPDATE public.products SET reserved_stock=0 WHERE id=%s", (product_id,))
        available_ids = [int(row[0]) for row in admin.execute("""SELECT id FROM public.product_stock_items
            WHERE product_id=%s AND order_item_id IS NULL AND retired_at IS NULL ORDER BY id""",
            (product_id,)).fetchall()]
        token = csrf(owner.get(f"/admin/produk/{product_id}", secure=True))
        delete_ids = available_ids[-2:]
        bulk_delete = owner.post(f"/admin/produk/{product_id}/stok/hapus-bulk", {
            "csrfmiddlewaretoken": token, "stock_item_ids": [str(value) for value in delete_ids],
        }, secure=True, HTTP_ACCEPT="text/html")
        assert bulk_delete.status_code == 302
        assert admin.execute("SELECT stock FROM public.products WHERE id=%s", (product_id,)).fetchone() == (
            stock_before - 2,)
        assert admin.execute("SELECT COUNT(*) FROM public.product_stock_items WHERE id=ANY(%s)",
                             (delete_ids,)).fetchone() == (0,)

        # Legacy edit preserves encryption and audits no secret value.
        legacy_id, encrypted_secret = admin.execute("""SELECT id,secret FROM public.product_stock_items
            WHERE product_id=%s AND credential_format='legacy' ORDER BY id LIMIT 1""",
            (product_id,)).fetchone()
        replacement = "P22-LEGACY-SECRET-REPLACED"
        token = csrf(owner.get(f"/admin/produk/{product_id}/stok/{legacy_id}", secure=True))
        edited = owner.post(f"/admin/produk/{product_id}/stok/{legacy_id}", {
            "csrfmiddlewaretoken": token, "credential_format": "legacy", "secret": replacement,
        }, secure=True, HTTP_ACCEPT="text/html")
        assert edited.status_code == 302
        changed = admin.execute("SELECT secret FROM public.product_stock_items WHERE id=%s", (legacy_id,)).fetchone()[0]
        assert changed != encrypted_secret and cipher.decrypt_string(changed) == replacement
        audit_row = admin.execute("""SELECT old_values,new_values FROM public.audit_logs
            WHERE action='product_stock_item.updated' AND subject_id=%s ORDER BY id DESC LIMIT 1""",
            (str(legacy_id),)).fetchone()
        assert replacement not in json.dumps(audit_row)

        row = service.product(product_id)
        versions, _ = service.versions(row)
        token = csrf(owner.get(f"/admin/produk/{product_id}", secure=True))
        appearance_saved = owner.post(f"/admin/produk/{product_id}/ubah", {
            "csrfmiddlewaretoken": token, "workspace_section": "appearance",
            "workspace_version": versions["appearance"], "icon": "🎬",
            "custom_emoji_id": "p22-synthetic-emoji", "is_active": "1",
            "featured_rank": "2", "image_path": "products/synthetic-p22.webp",
            "telegram_image_path": "products/synthetic-p22-telegram.webp",
            "telegram_channel_image_path": "products/synthetic-p22-channel.webp",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert appearance_saved.status_code == 302, ("appearance save", appearance_saved.status_code)
        assert admin.execute("""SELECT icon,custom_emoji_id,featured_rank,image_path,
            telegram_image_path,telegram_channel_image_path FROM public.products WHERE id=%s""",
            (product_id,)).fetchone() == (
                "🎬", "p22-synthetic-emoji", 2, "products/synthetic-p22.webp",
                "products/synthetic-p22-telegram.webp", "products/synthetic-p22-channel.webp")
        token = csrf(owner.get(f"/admin/produk/{product_id}", secure=True))
        paused = owner.post(f"/admin/produk/{product_id}/visibilitas", {
            "csrfmiddlewaretoken": token, "is_active": "0",
        }, secure=True, HTTP_ACCEPT="text/html")
        assert paused.status_code == 302
        assert admin.execute("SELECT is_active FROM public.products WHERE id=%s",
                             (product_id,)).fetchone() == (False,)
        token = csrf(owner.get(f"/admin/produk/{product_id}", secure=True))
        assert owner.post(f"/admin/produk/{product_id}/visibilitas", {
            "csrfmiddlewaretoken": token, "is_active": "1",
        }, secure=True, HTTP_ACCEPT="text/html").status_code == 302
        assert admin.execute("SELECT is_active FROM public.products WHERE id=%s",
                             (product_id,)).fetchone() == (True,)

        # Product price edits do not rewrite an existing Order snapshot.
        php_product = fixtures["php"]["product_id"]
        order_item_before = admin.execute("""SELECT unit_price,subtotal,quantity_price_tier_minimum
            FROM public.order_items WHERE product_id=%s ORDER BY id LIMIT 1""", (php_product,)).fetchone()
        php_row = service.product(php_product)
        php_versions, _ = service.versions(php_row)
        service.update_product(php_product, {
            "workspace_section": "price", "workspace_version": php_versions["price"],
            "quantity_price_tiers_present": "1",
            "price_amount": str(int(php_row["price_amount"]) + 1000),
            "usdt_price_amount": "", "reseller_price_amount": "",
            "reseller_usdt_price_amount": "",
        }, owner_id, "127.0.30.10", section="price")
        assert admin.execute("""SELECT unit_price,subtotal,quantity_price_tier_minimum
            FROM public.order_items WHERE product_id=%s ORDER BY id LIMIT 1""",
            (php_product,)).fetchone() == order_item_before

        # Banner version, DB JSON and audit; no upload or external URL.
        banners = owner.get("/admin/banner", secure=True)
        version = int(re.search(rb'name="version" value="(\d+)"', banners.content).group(1))
        token = csrf(banners)
        banner_values = {"csrfmiddlewaretoken": token, "version": str(version)}
        for index in range(5):
            banner_values.update({
                f"banners.{index}.path": "synthetic-p22.webp" if index == 0 else "",
                f"banners.{index}.title": "Synthetic P22 banner" if index == 0 else "",
                f"banners.{index}.link": "/shop" if index == 0 else "",
                f"banners.{index}.remove": "0",
            })
        saved_banners = owner.post("/admin/banner", banner_values, secure=True, HTTP_ACCEPT="text/html")
        assert saved_banners.status_code == 302
        stored = admin.execute("""SELECT homepage_banners,homepage_banners_version FROM public.storefront_settings
            WHERE singleton_key='storefront'""").fetchone()
        stored_json = stored[0] if isinstance(stored[0], list) else json.loads(stored[0])
        assert stored_json[0]["path"] == "synthetic-p22.webp" and stored_json[1] is None
        assert int(stored[1]) == version + 1
        stale_banner = owner.post("/admin/banner", banner_values, secure=True, HTTP_ACCEPT="text/html")
        assert stale_banner.status_code == 409
        bad_banner = {**banner_values, "version": str(version + 1),
                      "banners.0.link": "//evil.example"}
        assert owner.post("/admin/banner", bad_banner, secure=True,
                          HTTP_ACCEPT="text/html").status_code == 422

        # JSON stock read never includes secret or payload values.
        stock_json = owner.get(f"/admin/produk/{product_id}/stok-items", secure=True,
                               HTTP_ACCEPT="application/json")
        assert stock_json.status_code == 200
        assert b"P22-LEGACY-SECRET" not in stock_json.content
        assert b"credential_payload" not in stock_json.content

        # Same Auth Token race: exactly one winner, one durable stock row.
        race_product = admin.execute("""INSERT INTO public.products
            (name,description,price_amount,currency,stock,reserved_stock,is_active,created_at,updated_at)
            VALUES ('Synthetic P22 Race','Race',1000,'IDR',0,0,TRUE,now(),now()) RETURNING id""").fetchone()[0]
        race_token = "P22-RACE-TOKEN"
        race_line = "race@example.invalid|synthetic|" + race_token
        outcomes = compete([{
            "product_id": int(race_product), "line": race_line,
            "operation_key": str(uuid.uuid4()), "actor_id": owner_id,
            "identity_key": identity_key,
        } for _ in range(2)])
        assert sorted(state for state, _ in outcomes) == ["accepted", "rejected"], outcomes
        assert admin.execute("SELECT stock FROM public.products WHERE id=%s", (race_product,)).fetchone() == (1,)
        race_fingerprint = credentials.fingerprint("auth_token", race_token)
        assert admin.execute("""SELECT COUNT(*) FROM public.credential_identities
            WHERE identity_kind='auth_token' AND fingerprint=%s""", (race_fingerprint,)).fetchone() == (1,)

        # Family/category deletion unlinks products without deleting them.
        token = csrf(owner.get("/admin/family", secure=True))
        assert owner.post(f"/admin/family/{family[0]}/hapus", {"csrfmiddlewaretoken": token},
                          secure=True, HTTP_ACCEPT="text/html").status_code == 302
        assert admin.execute("SELECT product_family_id FROM public.products WHERE id=%s",
                             (product_id,)).fetchone() == (None,)
        assert admin.execute("SELECT deleted_at IS NOT NULL FROM public.product_families WHERE id=%s",
                             (family[0],)).fetchone() == (True,)
        token = csrf(owner.get("/admin/kategori", secure=True))
        assert owner.post(f"/admin/kategori/{category_a[0]}/hapus", {"csrfmiddlewaretoken": token},
                          secure=True, HTTP_ACCEPT="text/html").status_code == 302
        assert admin.execute("SELECT category_id FROM public.products WHERE id=%s",
                             (product_id,)).fetchone() == (None,)
        assert admin.execute("SELECT deleted_at IS NOT NULL FROM public.categories WHERE id=%s",
                             (category_a[0],)).fetchone() == (True,)

        token = csrf(owner.get(f"/admin/produk/{product_id}", secure=True))
        assert owner.post(f"/admin/produk/{product_id}/hapus", {"csrfmiddlewaretoken": token},
                          secure=True, HTTP_ACCEPT="text/html").status_code == 302
        assert admin.execute("SELECT deleted_at IS NOT NULL FROM public.products WHERE id=%s",
                             (product_id,)).fetchone() == (True,)
        assert admin.execute("SELECT COUNT(*) FROM public.product_stock_items WHERE product_id=%s",
                             (product_id,)).fetchone()[0] > 0, "archive deleted stock history"

        # Existing P19 JSON admin contract still resolves when no HTML is requested.
        json_client = Client(HTTP_HOST="localhost", HTTP_ORIGIN="https://localhost",
                             REMOTE_ADDR="127.0.30.13", raise_request_exception=False)
        json_client.cookies = owner.cookies
        current = json_client.get("/admin")
        assert current.status_code == 200 and current["Content-Type"].startswith("application/json")
        assert current.json()["admin"]["id"] == owner_id
        landing = json_client.get("/akun")
        json_token = (landing.json()["csrf_token"]
                      if landing["Content-Type"].startswith("application/json")
                      else csrf(landing))
        assert json_client.post("/admin/logout", HTTP_X_CSRFTOKEN=json_token).status_code == 200
        assert json_client.get("/admin").status_code == 401

        return {
            "shell": "permission-filtered sidebar/topbar/direct tabs, drawer, no-JS fallback and P19 JSON compatibility",
            "catalog": "category/family/product CRUD, family-category coherence, price/tier bounds, immutable Order snapshots",
            "stock": "legacy/MFA/structured intake, replay and conflict, identity duplicate race, edit/delete/bulk/retired/reserved fences",
            "presentation": "DB-backed banners with version fencing; no upload, no presentation ENV",
            "errors": "CSRF, role denial and safe field messages without credential reflection",
        }
    admin.close()
    connections.close_all()
