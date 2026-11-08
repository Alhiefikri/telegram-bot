"""Stock intake and local credential management on the canonical inventory tables."""

import hashlib
import hmac
import json
import re
import uuid

from django.db import DatabaseError
from django.http import Http404

from ..checkout.db import Database
from ..compat.crypto import CryptoError, LaravelEncrypter
from ..runtime import application_key
from . import credentials
from .db import audit, available_stock_sql, bounded_transaction
from .errors import AdminConflict, AdminValidationError
from .validation import raw


BULK_FORMATS = {
    "legacy": "legacy",
    "email_password_mfa_secret": "mfa_secret",
    "email_password_mfa": "mfa_secret",
    "email_password_access_token": "access_token",
    "email_password_auth_token": "access_token",
    "email_password_reader_mail_link": "reader_mail_link",
    "email_password_otp_mail_link": "reader_mail_link",
}
MAX_BULK_ITEMS = 500
MAX_STRUCTURED_ITEMS = 20
_ACCOUNT_KEY = re.compile(
    r"accounts\.(\d+)\.(email|password|access_method|access_value)\Z", re.ASCII
)
_UUID = re.compile(r"[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\Z")


def _is_unique(error):
    cause = error.__cause__
    return getattr(cause, "pgcode", None) == "23505" or getattr(error, "pgcode", None) == "23505"


def _uuid(value):
    if not isinstance(value, str) or _UUID.fullmatch(value.strip()) is None:
        return str(uuid.uuid4())
    return value.strip().lower()


class StockService:
    def __init__(self, db=None, encrypter=None):
        self.db = db or Database()
        self.cipher = encrypter or LaravelEncrypter(application_key())

    # -- structured input gate ------------------------------------------

    def structured_enabled(self):
        digest = credentials.fingerprint_key_digest()
        if not digest:
            return False
        try:
            row = self.db.one(
                """SELECT status,fingerprint_key_digest,scan_upper_bound_id,completed_at,
                          ready_at,conflict_count,unresolved_count
                   FROM public.credential_identity_backfill_readiness
                   WHERE singleton_key='credential_identity_registry'"""
            )
        except DatabaseError:
            return False
        return bool(
            row
            and row["status"] == "ready"
            and isinstance(row["fingerprint_key_digest"], str)
            and hmac.compare_digest(row["fingerprint_key_digest"], digest)
            and row["scan_upper_bound_id"] is not None
            and row["completed_at"] is not None
            and row["ready_at"] is not None
            and int(row["conflict_count"] or 0) == 0
            and int(row["unresolved_count"] or 0) == 0
        )

    # -- parsing --------------------------------------------------------

    def parse_accounts(self, data):
        """Return (items, canonical, duplicate_rows, errors) for indexed fields."""
        rows = {}
        for key, value in (data.items() if hasattr(data, "items") else data):
            match = _ACCOUNT_KEY.fullmatch(key)
            if match is not None:
                rows.setdefault(int(match.group(1)), {})[match.group(2)] = value
        errors, items, canonical, duplicate_rows = {}, [], [], {}
        if not 1 <= len(rows) <= MAX_STRUCTURED_ITEMS:
            errors["accounts"] = ["Input Akun harus berisi 1 sampai 20 akun."]
        seen = {}
        for index in sorted(rows):
            row = rows[index]
            if not any(str(row.get(field, "")).strip() for field in
                       ("email", "password", "access_method", "access_value")):
                continue
            prefix = f"accounts.{index}"
            payload = None
            try:
                payload = credentials.structured_payload(
                    email_value=raw(row, "email", ""),
                    password_value=raw(row, "password", ""),
                    access_method_value=raw(row, "access_method", "none") or "none",
                    access_value=raw(row, "access_value", ""),
                )
            except credentials.CredentialError as error:
                errors[prefix + "." + error.field] = [error.message]
            if payload is None:
                continue
            identities = credentials.identity_values(payload)
            item = {"index": index, "format": "structured_account",
                    "secret": None, "payload": payload, "identities": identities}
            items.append(item)
            canonical.append(payload)
            for kind, value in identities:
                claim = kind + ":" + value
                if claim in seen:
                    duplicate_rows[seen[claim]] = True
                    duplicate_rows[index] = True
                    errors[f"accounts.{seen[claim]}.access_value"] = [
                        "Identitas kredensial duplikat dalam pengajuan."
                    ]
                    errors[prefix + ".access_value"] = [
                        "Identitas kredensial duplikat dalam pengajuan."
                    ]
                else:
                    seen[claim] = index
        if not items and "accounts" not in errors:
            errors["accounts"] = ["Input Akun wajib diisi."]
        return items, canonical, duplicate_rows, errors

    def _registered_duplicates(self, items):
        if not items:
            return {}
        digest = credentials.fingerprint_key_digest()
        if not digest:
            return {}
        duplicates = {}
        for item in items:
            for kind, value in item["identities"]:
                try:
                    fingerprint = credentials.fingerprint(kind, value)
                except credentials.CredentialError:
                    continue
                if self.db.one(
                    """SELECT id FROM public.credential_identities
                       WHERE identity_kind=%s AND fingerprint=%s""",
                    (kind, fingerprint),
                ):
                    duplicates[item["index"]] = True
        return duplicates

    def review_structured(self, product_id, data):
        if not self.structured_enabled():
            raise AdminValidationError(
                {"accounts": ["Input Akun sedang tidak tersedia. Silakan hubungi administrator."]},
                status=409,
            )
        product = self.db.one(
            "SELECT id,name FROM public.products WHERE id=%s AND deleted_at IS NULL",
            (product_id,),
        )
        if product is None:
            raise Http404
        items, _, duplicate_rows, errors = self.parse_accounts(data)
        if errors:
            raise AdminValidationError(errors)
        registered = self._registered_duplicates(items)
        duplicate_rows = {**duplicate_rows, **registered}
        accounts = []
        for number, item in enumerate(items, start=1):
            payload = item["payload"]
            duplicate = item["index"] in duplicate_rows
            accounts.append({
                "index": item["index"],
                "account_number": number,
                "email_masked": credentials.mask_email(payload["email"]),
                "access_method": payload["access_method"],
                "otp_domain": credentials.otp_domain(payload),
                "duplicate_status": "duplicate" if duplicate else "clear",
            })
        return {
            "status": "duplicate" if duplicate_rows else "ready",
            "account_count": len(accounts),
            "duplicate_count": len(duplicate_rows),
            "accounts": accounts,
            "product": {"id": int(product["id"]), "name": product["name"]},
        }

    def normalize(self, data, stock_format="legacy"):
        """Validate one submission and return a credential-free description."""
        normalized_format = BULK_FORMATS.get(stock_format or "legacy")
        if stock_format == "structured_account":
            normalized_format = "structured_account"
        if normalized_format is None:
            raise AdminValidationError({"stock_format": ["Format stok tidak valid."]})
        errors = {}
        if normalized_format == "structured_account":
            mode, label = "structured_account", "akun terstruktur"
            items, canonical, duplicate_rows, errors = self.parse_accounts(data)
            for index in duplicate_rows:
                errors.setdefault(f"accounts.{index}.access_value", [
                    "Identitas kredensial duplikat dalam pengajuan."
                ])
            if not self.structured_enabled():
                errors["accounts"] = [
                    "Input Akun sedang tidak tersedia. Silakan hubungi administrator."
                ]
            if errors:
                raise AdminValidationError(errors)
            return {
                "mode": mode,
                "label": label,
                "items": items,
                "canonical": canonical,
                "structured": True,
                "operation_key": _uuid(raw(data, "operation_key")),
            }
        if normalized_format == "legacy":
            mode, label = "legacy", "manual"
            text = raw(data, "stock_items")
            if not isinstance(text, str):
                errors["stock_items"] = ["Isi stok harus berupa teks."]
                text = ""
            lines = [line.strip() for line in re.split(r"\r\n|\r|\n", text) if line.strip()]
            if len(lines) > MAX_BULK_ITEMS:
                errors["stock_items"] = [f"Isi stok maksimal {MAX_BULK_ITEMS} baris."]
            items = [{"index": index, "format": "legacy", "secret": value,
                      "payload": None, "identities": []}
                     for index, value in enumerate(lines)]
            canonical = [{"secret": item["secret"]} for item in items]
        elif normalized_format == "mfa_secret":
            mode, label = "bulk_mfa_secret", "mfa"
            items, canonical = [], []
            text = raw(data, "stock_items")
            lines = [line.strip() for line in re.split(r"\r\n|\r|\n", text or "") if line.strip()]
            if len(lines) > MAX_BULK_ITEMS:
                errors["stock_items"] = [f"Isi stok maksimal {MAX_BULK_ITEMS} baris."]
            for index, line in enumerate(lines):
                if credentials.credential_parts(line) is None:
                    errors[f"stock_items.{index}"] = ["Format baris harus email|password|MFA secret."]
                    continue
                items.append({"index": index, "format": "legacy", "secret": line,
                              "payload": None, "identities": []})
                canonical.append({"secret": line})
        elif normalized_format == "access_token":
            mode, label = "bulk_access_token", "access token"
            items, canonical = [], []
            text = raw(data, "stock_items")
            lines = [line.strip() for line in re.split(r"\r\n|\r|\n", text or "") if line.strip()]
            if len(lines) > MAX_BULK_ITEMS:
                errors["stock_items"] = [f"Isi stok maksimal {MAX_BULK_ITEMS} baris."]
            for index, line in enumerate(lines):
                parts = line.split("|", 2)
                try:
                    if len(parts) != 3:
                        raise credentials.CredentialError("line", "Format baris harus email|password|akses token.")
                    payload = credentials.structured_payload(
                        email_value=parts[0].strip(),
                        password_value=parts[1],
                        access_method_value="auth_token",
                        access_value=parts[2],
                    )
                except credentials.CredentialError as error:
                    errors[f"stock_items.{index}"] = [error.message]
                    continue
                item = {"index": index, "format": "structured_account", "secret": None,
                        "payload": payload, "identities": credentials.identity_values(payload)}
                items.append(item)
                canonical.append(payload)
        else:
            mode, label = "bulk_reader_mail_link", "reader mail link"
            items, canonical = [], []
            text = raw(data, "stock_items")
            lines = [line.strip() for line in re.split(r"\r\n|\r|\n", text or "") if line.strip()]
            if len(lines) > MAX_BULK_ITEMS:
                errors["stock_items"] = [f"Isi stok maksimal {MAX_BULK_ITEMS} baris."]
            for index, line in enumerate(lines):
                parts = self._reader_parts(line)
                if parts is None:
                    errors[f"stock_items.{index}"] = [
                        "Format baris harus email|password|token|link atau email|password|email|password|token|UUID|link."
                    ]
                    continue
                try:
                    payload = credentials.structured_payload(
                        email_value=parts["email"],
                        password_value=parts["password"],
                        access_method_value="auth_token",
                        access_value=parts["access_token"],
                        reader_mail_link=parts["reader_mail_link"],
                    )
                except credentials.CredentialError as error:
                    errors[f"stock_items.{index}"] = [error.message]
                    continue
                item = {"index": index, "format": "structured_account", "secret": None,
                        "payload": payload, "identities": credentials.identity_values(payload)}
                items.append(item)
                canonical.append(payload)

        if not items and "stock_items" not in errors:
            errors["stock_items"] = ["Isi minimal satu baris stok."]
        seen = {}
        for item in items:
            for kind, value in item["identities"]:
                claim = kind + ":" + value
                if claim in seen:
                    errors[f"stock_items.{seen[claim]}.access_value"] = [
                        "Identitas kredensial duplikat dalam pengajuan."
                    ]
                    errors[f"stock_items.{item['index']}.access_value"] = [
                        "Identitas kredensial duplikat dalam pengajuan."
                    ]
                else:
                    seen[claim] = item["index"]
        structured = mode in {"bulk_access_token", "bulk_reader_mail_link"}
        if structured and not self.structured_enabled():
            errors["stock_items"] = [
                "Format access token belum tersedia karena pemeriksaan kesiapan identitas belum selesai."
            ]
        if errors:
            raise AdminValidationError(errors)
        return {
            "mode": mode,
            "label": label,
            "items": items,
            "canonical": canonical,
            "structured": structured,
            "operation_key": _uuid(raw(data, "operation_key")),
        }

    @staticmethod
    def _reader_parts(line):
        parts = line.split("|")
        uuid_like = lambda value: _UUID.fullmatch((value or "").strip()) is not None
        if len(parts) == 4:
            email, password, token, link = parts
        elif (len(parts) == 7 and parts[0].strip() == parts[2].strip()
              and parts[1] == parts[3] and uuid_like(parts[5])):
            email, password, token, link = parts[0], parts[1], parts[4], parts[6]
        elif len(parts) == 6 and uuid_like(parts[3]):
            try:
                credentials.email(parts[4].strip())
            except credentials.CredentialError:
                return None
            email, password, token, link = parts[0], parts[1], parts[2], parts[5]
        elif (len(parts) == 8 and parts[0].strip() == parts[2].strip()
              and parts[1] == parts[3] and uuid_like(parts[5])):
            try:
                credentials.email(parts[6].strip())
            except credentials.CredentialError:
                return None
            email, password, token, link = parts[0], parts[1], parts[4], parts[7]
        else:
            return None
        email, password, token, link = email.strip(), password, token, link.strip()
        try:
            credentials.email(email)
        except credentials.CredentialError:
            return None
        if (not password.strip() or not token.strip() or not link
                or "\r" in email + password + token + link
                or "\n" in email + password + token + link):
            return None
        return {"email": email, "password": password,
                "access_token": token, "reader_mail_link": link}

    # -- intake ---------------------------------------------------------

    def submit(self, product_id, data, actor_id, ip=None):
        submission = self.normalize(data, raw(data, "stock_format", "legacy"))
        return self.submit_normalized(product_id, submission, actor_id, ip)

    def submit_normalized(self, product_id, submission, actor_id, ip=None):
        try:
            return bounded_transaction(
                lambda: self._submit_in_transaction(product_id, submission, actor_id, ip)
            )
        except DatabaseError as error:
            if _is_unique(error):
                raise AdminValidationError(
                    {"stock_items": ["Identitas kredensial sudah pernah digunakan."]},
                    status=409,
                ) from None
            raise

    def _submit_in_transaction(self, product_id, submission, actor_id, ip):
        self.db.require_transaction()
        if submission["structured"] and not self.structured_enabled():
            raise AdminValidationError(
                {"stock_items": ["Format access token belum tersedia karena pemeriksaan kesiapan identitas belum selesai."]},
                status=409,
            )
        self.db.one(
            "SELECT pg_advisory_xact_lock(hashtextextended(%s,0)) AS locked",
            ("product-stock-intake:" + submission["operation_key"],),
        )
        existing = self.db.one(
            """SELECT * FROM public.product_stock_intake_operations
               WHERE operation_key=%s FOR UPDATE""",
            (submission["operation_key"],),
        )
        payload_hash = self._payload_hash(submission)
        if existing is not None:
            if (int(existing["product_id"]) != int(product_id)
                    or existing["payload_hash"] != payload_hash
                    or existing["mode"] != submission["mode"]
                    or existing["inventory_movement_id"] is None):
                raise AdminConflict("Kunci operasi sudah digunakan untuk pengajuan stok lain.")
            return {
                "product_id": int(existing["product_id"]),
                "movement_id": int(existing["inventory_movement_id"]),
                "item_count": int(existing["item_count"]),
                "stock_before": int(existing["stock_before"]),
                "stock_after": int(existing["stock_after"]),
                "available_before": int(existing["available_before"]),
                "available_after": int(existing["available_after"]),
                "idempotent_replay": True,
            }
        product = self.db.one(
            f"""SELECT id,name,stock,reserved_stock,{available_stock_sql('p')} AS available
                FROM public.products p WHERE p.id=%s AND p.deleted_at IS NULL FOR UPDATE""",
            (product_id,),
        )
        if product is None:
            raise Http404
        count = len(submission["items"])
        stock_before = int(product["stock"])
        stock_after = stock_before + count
        reserved = int(product["reserved_stock"])
        if stock_after < reserved:
            raise AdminValidationError(
                {"stock_items": ["Hasil stok harus non-negatif dan tidak di bawah stok yang dipesan."]}
            )
        operation = self.db.one(
            """INSERT INTO public.product_stock_intake_operations
               (operation_key,product_id,actor_id,payload_hash,mode,item_count,
                stock_before,stock_after,available_before,available_after,ip_address,
                created_at,updated_at)
               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,0,%s,
                 CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                 CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')
               RETURNING id""",
            (submission["operation_key"], product_id, actor_id, payload_hash,
             submission["mode"], count, stock_before, stock_after,
             int(product["available"]), ip),
        )
        for item in submission["items"]:
            if item["format"] == "structured_account":
                payload = json.dumps(item["payload"], ensure_ascii=False,
                                     separators=(",", ":"))
                stock = self.db.one(
                    """INSERT INTO public.product_stock_items
                       (product_id,credential_format,credential_payload,secret,created_at,updated_at)
                       VALUES (%s,'structured_account',%s,NULL,
                         CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                         CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta') RETURNING id""",
                    (product_id, self.cipher.encrypt_string(payload)),
                )
            else:
                stock = self.db.one(
                    """INSERT INTO public.product_stock_items
                       (product_id,credential_format,credential_payload,secret,created_at,updated_at)
                       VALUES (%s,'legacy',NULL,%s,
                         CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                         CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta') RETURNING id""",
                    (product_id, self.cipher.encrypt_string(item["secret"])),
                )
            self._claim_identities(
                product_id, stock["id"], item, allow_current=False,
                error_key="accounts" if submission["mode"] == "structured_account" else "stock_items",
            )
        self.db.execute(
            """UPDATE public.products SET stock=%s,
               updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s""",
            (stock_after, product_id),
        )
        available_after = self.db.one(
            f"""SELECT {available_stock_sql('p')} AS available
                FROM public.products p WHERE p.id=%s""", (product_id,))["available"]
        movement = self.db.one(
            """INSERT INTO public.inventory_movements
               (product_id,order_id,type,quantity,stock_before,available_before,
                available_after,became_unavailable,notes,created_at)
               VALUES (%s,NULL,'adjustment',%s,%s,%s,%s,FALSE,%s,
                 CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta') RETURNING id""",
            (product_id, count, stock_before, int(product["available"]),
             int(available_after), self._movement_notes(submission["mode"], count)),
        )
        self.db.execute(
            """UPDATE public.product_stock_intake_operations
               SET inventory_movement_id=%s,available_after=%s,
                   updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
               WHERE id=%s""",
            (movement["id"], int(available_after), operation["id"]),
        )
        audit(self.db, actor_id, "product.stock_adjusted", "App\\Models\\Product",
              product_id,
              {"stock": stock_before, "reserved_stock": reserved},
              {"stock": stock_after, "reserved_stock": reserved, "delta": count,
               "item_count": count,
               "credential_format": "structured_account" if submission["structured"] else "legacy"},
              ip)
        return {
            "product_id": int(product_id),
            "movement_id": int(movement["id"]),
            "item_count": count,
            "stock_before": stock_before,
            "stock_after": stock_after,
            "available_before": int(product["available"]),
            "available_after": int(available_after),
            "idempotent_replay": False,
        }

    @staticmethod
    def _movement_notes(mode, count):
        if mode == "bulk_mfa_secret":
            return f"Tambah stok bulk email/password/MFA secret dashboard ({count} item)."
        if mode == "bulk_access_token":
            return f"Tambah stok bulk email/password/akses token dashboard ({count} item)."
        if mode == "bulk_reader_mail_link":
            return f"Tambah stok bulk email/password/link reader mail dashboard ({count} item)."
        if mode == "structured_account":
            return f"Tambah stok akun terstruktur dashboard ({count} item)."
        return f"Tambah stok manual dashboard ({count} item)."

    def _payload_hash(self, submission):
        payload = {"mode": submission["mode"], "items": submission["canonical"]}
        encoded = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
        return hmac.new(application_key().encode("utf-8"), encoded.encode("utf-8"),
                        hashlib.sha256).hexdigest()

    def _claim_identities(self, product_id, stock_item_id, item, *, allow_current,
                          error_key="stock_items"):
        if not item["identities"]:
            return
        if not credentials.fingerprint_key_digest():
            return
        active = {}
        if allow_current:
            current = self.db.one(
                """SELECT credential_payload FROM public.product_stock_items
                   WHERE id=%s FOR UPDATE""", (stock_item_id,))
            if current and current["credential_payload"]:
                try:
                    payload = credentials.decode_structured_payload(
                        self.cipher.decrypt_json(current["credential_payload"]))
                except (CryptoError, credentials.CredentialError):
                    payload = None
                if payload:
                    for kind, value in credentials.identity_values(payload):
                        active[kind + ":" + credentials.fingerprint(kind, value)] = True
        for kind, value in item["identities"]:
            fingerprint = credentials.fingerprint(kind, value)
            existing = self.db.one(
                """SELECT product_stock_item_id FROM public.credential_identities
                   WHERE identity_kind=%s AND fingerprint=%s FOR UPDATE""",
                (kind, fingerprint),
            )
            if existing is not None:
                if (allow_current and int(existing["product_stock_item_id"] or 0) == int(stock_item_id)
                        and kind + ":" + fingerprint in active):
                    continue
                raise AdminValidationError(
                    {error_key: ["Identitas kredensial sudah pernah digunakan."]},
                    status=409,
                )
            self.db.execute(
                """INSERT INTO public.credential_identities
                   (identity_kind,fingerprint,product_id,product_stock_item_id,
                    first_seen_at,created_at,updated_at)
                   VALUES (%s,%s,%s,%s,
                     CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                     CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta',
                     CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')""",
                (kind, fingerprint, product_id, stock_item_id),
            )

    # -- edit/delete -----------------------------------------------------

    def item_for_edit(self, product_id, item_id):
        row = self.db.one(
            """SELECT s.* FROM public.product_stock_items s
               WHERE s.id=%s AND s.product_id=%s AND s.supplier_purchase_id IS NULL
                 AND s.supplier_unit_index IS NULL AND s.order_item_id IS NULL
                 AND s.retired_at IS NULL""",
            (item_id, product_id),
        )
        if row is None:
            raise Http404
        try:
            if (row["credential_format"] or "legacy") == "legacy":
                if row["secret"] is None:
                    raise Http404
                return {"id": row["id"], "format": "legacy",
                        "secret": self.cipher.decrypt_string(row["secret"])}
            payload = credentials.decode_structured_payload(
                self.cipher.decrypt_json(row["credential_payload"]))
            return {"id": row["id"], "format": "structured_account", **payload}
        except (CryptoError, credentials.CredentialError, ValueError):
            raise Http404 from None

    def update_item(self, product_id, item_id, data, actor_id, ip=None):
        def operation():
            product = self.db.one(
                "SELECT id FROM public.products WHERE id=%s AND deleted_at IS NULL FOR UPDATE",
                (product_id,),
            )
            if product is None:
                raise Http404
            row = self.db.one(
                """SELECT * FROM public.product_stock_items
                   WHERE id=%s AND product_id=%s AND supplier_purchase_id IS NULL
                     AND supplier_unit_index IS NULL AND order_item_id IS NULL
                     AND retired_at IS NULL FOR UPDATE""",
                (item_id, product_id),
            )
            if row is None:
                raise Http404
            format_value = row["credential_format"] or "legacy"
            requested = raw(data, "credential_format", format_value)
            if requested != format_value:
                raise AdminValidationError(
                    {"credential_format": ["Format stok tidak cocok dengan editor akun."]}
                )
            if format_value == "legacy":
                secret = raw(data, "secret")
                if not isinstance(secret, str) or secret.strip() == "":
                    raise AdminValidationError({"secret": ["Isi akun stok tidak boleh kosong."]})
                if len(secret) > 5000:
                    raise AdminValidationError({"secret": ["Isi akun stok maksimal 5000 karakter."]})
                self.db.execute(
                    """UPDATE public.product_stock_items SET secret=%s,
                       updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s""",
                    (self.cipher.encrypt_string(secret.strip()), item_id))
                audit(self.db, actor_id, "product_stock_item.updated",
                      "App\\Models\\ProductStockItem", item_id,
                      {"product_id": product_id, "stock_item_id": item_id,
                       "secret_present": True},
                      {"product_id": product_id, "stock_item_id": item_id,
                       "secret_changed": True}, ip)
                return
            try:
                existing = credentials.decode_structured_payload(
                    self.cipher.decrypt_json(row["credential_payload"]))
            except (CryptoError, credentials.CredentialError):
                existing = {}
            payload = credentials.structured_payload(
                email_value=raw(data, "email"),
                password_value=raw(data, "password"),
                access_method_value=raw(data, "access_method", "none") or "none",
                access_value=raw(data, "access_value", ""),
                tutorial_override=existing.get("tutorial_override"),
                reader_mail_link=existing.get("reader_mail_link"),
            )
            item = {"identities": credentials.identity_values(payload)}
            self._claim_identities(product_id, item_id, item, allow_current=True,
                                   error_key="access_value")
            encoded = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
            self.db.execute(
                """UPDATE public.product_stock_items
                   SET credential_payload=%s,secret=NULL,
                       updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta'
                   WHERE id=%s""",
                (self.cipher.encrypt_string(encoded), item_id),
            )
            audit(self.db, actor_id, "product_stock_item.updated",
                  "App\\Models\\ProductStockItem", item_id,
                  {"product_id": product_id, "stock_item_id": item_id,
                   "credential_format": "structured_account", "secret_present": True},
                  {"product_id": product_id, "stock_item_id": item_id,
                   "credential_format": "structured_account", "secret_changed": True,
                   "changed_fields": ["email", "password", "access_method", "access_value"],
                   "identity_kinds": ["auth_token"]}, ip)

        return bounded_transaction(operation)

    def delete_items(self, product_id, item_ids, actor_id, ip=None, *, single=False):
        ids = sorted({int(value) for value in item_ids if int(value) > 0})
        if not ids or len(ids) > 100:
            raise AdminValidationError({"stock_item_ids": ["Pilih 1 sampai 100 stok yang berbeda."]})
        if len(ids) != len(item_ids):
            raise AdminValidationError({"stock_item_ids": ["Pilih 1 sampai 100 stok yang berbeda."]})
        try:
            return bounded_transaction(
                lambda: self._delete_in_transaction(product_id, ids, actor_id, ip, single)
            )
        except DatabaseError as error:
            if _is_unique(error):
                raise AdminValidationError(
                    {"stock_item_ids": ["Pilihan stok berubah. Muat ulang dan pilih kembali."]}
                ) from None
            raise

    def _delete_in_transaction(self, product_id, ids, actor_id, ip, single):
        product = self.db.one(
            f"""SELECT id,stock,reserved_stock,{available_stock_sql('p')} AS available
                FROM public.products p WHERE p.id=%s AND p.deleted_at IS NULL FOR UPDATE""",
            (product_id,),
        )
        if product is None:
            raise Http404
        rows = self.db.rows(
            """SELECT id FROM public.product_stock_items
               WHERE id=ANY(%s) AND product_id=%s AND supplier_purchase_id IS NULL
                 AND supplier_unit_index IS NULL AND order_item_id IS NULL
                 AND retired_at IS NULL ORDER BY id FOR UPDATE""",
            (ids, product_id),
        )
        if len(rows) != len(ids):
            if single:
                raise Http404
            raise AdminValidationError(
                {"stock_item_ids": ["Pilihan stok berubah atau tidak tersedia. Muat ulang dan pilih kembali. Tidak ada stok dihapus."]}
            )
        count = len(ids)
        stock_before = int(product["stock"])
        if count > stock_before:
            raise AdminValidationError(
                {"stock_item_ids": ["Proyeksi stok tidak konsisten. Muat ulang sebelum menghapus stok."]}
            )
        new_stock = stock_before - count
        if new_stock < int(product["reserved_stock"]):
            raise AdminValidationError(
                {"stock_item_ids": ["Stok ini tidak bisa dihapus karena masih ada pesanan yang sedang dipesan."]}
            )
        self.db.execute("DELETE FROM public.product_stock_items WHERE id=ANY(%s)", (ids,))
        self.db.execute(
            """UPDATE public.products SET stock=%s,
               updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s""",
            (new_stock, product_id),
        )
        available_after = self.db.one(
            f"""SELECT {available_stock_sql('p')} AS available
                FROM public.products p WHERE p.id=%s""", (product_id,))["available"]
        became_unavailable = int(product["available"]) > 0 and int(available_after) == 0
        self.db.execute(
            """INSERT INTO public.inventory_movements
               (product_id,order_id,type,quantity,stock_before,became_unavailable,
                available_before,available_after,notes,created_at)
               VALUES (%s,NULL,'adjustment',%s,%s,%s,%s,%s,%s,
                 CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')""",
            (product_id, -count, stock_before, became_unavailable,
             int(product["available"]), int(available_after),
             f"Hapus stok manual dashboard ({count} item)."),
        )
        audit(self.db, actor_id,
              "product_stock_item.deleted" if single else "product_stock_items.bulk_deleted",
              "App\\Models\\Product", product_id,
              {"stock": stock_before, "reserved_stock": int(product["reserved_stock"]),
               "available_stock": int(product["available"]),
               **({"stock_item_id": ids[0]} if single else {"stock_item_ids": ids})},
              {"stock": new_stock, "reserved_stock": int(product["reserved_stock"]),
               "available_stock": int(available_after), "delta": -count,
               **({"stock_item_id": ids[0]} if single else {"stock_item_ids": ids})},
              ip)
        return {"deleted_count": count, "product_id": int(product_id),
                "stock": new_stock, "available": int(available_after)}
