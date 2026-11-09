"""Server-rendered admin pages with PRG mutations and JSON stock helpers."""

import re
import uuid

from django.http import Http404, HttpResponseRedirect, JsonResponse
from django.middleware.csrf import get_token
from django.shortcuts import render
from django.views.decorators.http import require_GET, require_http_methods, require_POST

from ..auth import http as auth_http
from ..auth.types import AuthError
from . import access, navigation
from .catalog import APPEARANCE_KEYS, DETAIL_FIELDS, DETAIL_KEYS, CatalogService
from .errors import AdminValidationError
from .pricing import format_usdt
from .stock import StockService
from .validation import boolean, raw


_FIELD_ERROR = re.compile(r"quantity_price_tiers\.(\d+)\.(minimum_quantity|price_amount|usdt_price_amount)\Z")


def _headers(response):
    response["Cache-Control"] = "no-store, private"
    response["Referrer-Policy"] = "same-origin"
    response["X-Robots-Tag"] = "noindex, nofollow"
    response["Content-Security-Policy"] = (
        "default-src 'self'; img-src 'self' data:; style-src 'self'; "
        "script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
    )
    return response


def _json(payload, status=200):
    return _headers(JsonResponse(payload, status=status))


def _flash_set(request, message, tone="success"):
    request.auth_session.set("admin_flash", {"message": message, "tone": tone})


def _flash_pop(request):
    flash = request.auth_session.data.get("admin_flash")
    if isinstance(flash, dict):
        request.auth_session.discard("admin_flash")
        return flash
    return None


def _page(request, template, title, nav, status=200, **extra):
    admin = access.identity(request)
    if admin["role"] != "owner":
        for group in nav.get("groups", ()):
            group["items"] = [item for item in group["items"]
                              if not item.get("owner_only")]
    response = render(request, template, {
        "title": title,
        "navigation": nav,
        "admin": admin,
        "flash": _flash_pop(request),
        "csrf_token": get_token(request),
        **extra,
    })
    response.status_code = status
    return _headers(response)


def _error_page(request, status=404, message=None, nav=None):
    message = message or ("Halaman tidak ditemukan." if status == 404 else "Permintaan tidak dapat diproses.")
    nav = nav or navigation.navigation()
    return _page(request, "admin/error.html", "Tidak ditemukan" if status == 404 else "Tidak dapat diproses",
                 nav, status=status, message=message)


def _flatten(errors):
    messages = []
    for values in errors.values():
        for value in values:
            if value not in messages:
                messages.append(value)
    return messages


def _safe_errors(error):
    return error.errors or {"_form": [error.message]}


def _select_options(rows, selected, empty_label=None):
    options = []
    if empty_label is not None:
        options.append({"value": "", "label": empty_label,
                        "selected": selected in (None, "", "0")})
    for row in rows:
        options.append({
            "value": str(row["id"]),
            "label": row["name"],
            "selected": str(selected or "") == str(row["id"]),
        })
    return options


def _field(name, label, value="", *, kind="text", maximum=None, required=False,
           help_text="", errors=(), rows=4, options=None, step=None, minimum=None,
           readonly=False):
    return {
        "name": name,
        "label": label,
        "value": "" if value is None else value,
        "kind": kind,
        "maximum": maximum,
        "required": required,
        "help": help_text,
        "errors": list(errors or ()),
        "rows": rows,
        "options": options or [],
        "step": step,
        "minimum": minimum,
        "readonly": readonly,
    }


def _detail_fields(values, errors):
    fields = []
    for key, label, kind, maximum, required in DETAIL_FIELDS:
        if key == "included_items":
            help_text = "Satu item per baris, maksimal 20 baris."
        elif key == "limitations":
            help_text = "Satu batasan per baris, maksimal 20 baris."
        elif key == "delivery_note":
            help_text = "Hanya dikirim ke pembeli setelah pembayaran dan akun tersedia."
        elif key == "usage_guide_url":
            help_text = "URL HTTP/HTTPS atau path lokal yang diawali satu garis miring."
        else:
            help_text = ""
        fields.append(_field(
            key, label, values.get(key, ""), kind=kind, maximum=maximum,
            required=required, help_text=help_text, errors=errors.get(key),
            rows=5 if key.startswith("description") else 3,
        ))
    return fields


def _price_fields(values, errors):
    return [
        _field("price_amount", "Harga jual IDR", values.get("price_amount", ""),
               kind="number", minimum=0, step="1", required=True,
               help_text="Harga dasar per unit. Harga bertingkat berlaku untuk jumlah lebih besar.",
               errors=errors.get("price_amount")),
        _field("usdt_price_amount", "Harga USDT opsional", values.get("usdt_price_amount", ""),
               kind="number", minimum=0, step="0.001",
               help_text="Maksimal 3 desimal. Kosongkan jika produk tidak dijual dalam USDT.",
               errors=errors.get("usdt_price_amount")),
        _field("reseller_price_amount", "Harga reseller IDR opsional", values.get("reseller_price_amount", ""),
               kind="number", minimum=1, step="1",
               help_text="Harga final per unit untuk pesanan API reseller.",
               errors=errors.get("reseller_price_amount")),
        _field("reseller_usdt_price_amount", "Harga reseller USDT opsional",
               values.get("reseller_usdt_price_amount", ""), kind="number", minimum=0.001,
               step="0.001", help_text="Wajib memiliki harga USDT dasar di atas 0.",
               errors=errors.get("reseller_usdt_price_amount")),
    ]


def _appearance_fields(values, errors, *, include_custom_emoji=False):
    fields = [
        _field("icon", "Ikon katalog", values.get("icon", ""), maximum=32,
               help_text="Emoji/karakter. Kosongkan untuk memakai ikon family.",
               errors=errors.get("icon")),
        _field("is_active", "Status katalog", bool(values.get("is_active")),
               kind="checkbox", help_text="Aktif bukan jaminan stok tersedia.",
               errors=errors.get("is_active")),
        _field("featured_rank", "Urutan unggulan", values.get("featured_rank", ""),
               kind="number", minimum=1, maximum=999, step="1",
               help_text="Kosongkan jika bukan pilihan utama. Nilai 1 sampai 999.",
               errors=errors.get("featured_rank")),
        _field("image_path", "Gambar kartu storefront", values.get("image_path", ""),
               maximum=2048, help_text="Path media tersimpan di database, bukan URL eksternal.",
               errors=errors.get("image_path")),
        _field("telegram_image_path", "Gambar detail bot", values.get("telegram_image_path", ""),
               maximum=2048, help_text="Path media tersimpan untuk detail Telegram.",
               errors=errors.get("telegram_image_path")),
        _field("telegram_channel_image_path", "Poster stok channel",
               values.get("telegram_channel_image_path", ""), maximum=2048,
               help_text="Path media tersimpan untuk pengumuman stok channel.",
               errors=errors.get("telegram_channel_image_path")),
    ]
    if include_custom_emoji:
        fields.insert(1, _field("custom_emoji_id", "ID emoji premium", values.get("custom_emoji_id", ""),
                                maximum=255, help_text="Opsional; dipakai bot bila ikon kustom tersedia.",
                                errors=errors.get("custom_emoji_id")))
    return fields


def _family_fields(values, errors):
    return [
        _field("name", "Nama Product Family", values.get("name", ""), maximum=160,
               required=True, errors=errors.get("name")),
        _field("slug", "Slug", values.get("slug", ""), maximum=160,
               help_text="Kosongkan untuk mempertahankan slug yang ada.",
               errors=errors.get("slug")),
        _field("description", "Deskripsi family", values.get("description", ""),
               kind="textarea", rows=4, errors=errors.get("description")),
        _field("icon", "Ikon family", values.get("icon", ""), maximum=32,
               errors=errors.get("icon")),
        _field("custom_emoji_id", "ID emoji premium", values.get("custom_emoji_id", ""),
               maximum=255, errors=errors.get("custom_emoji_id")),
        _field("sort_order", "Urutan Product Family", values.get("sort_order", 0),
               kind="number", minimum=0, step="1", required=True,
               errors=errors.get("sort_order")),
        _field("image_path", "Gambar family", values.get("image_path", ""), maximum=2048,
               help_text="Path media tersimpan, bukan URL eksternal.",
               errors=errors.get("image_path")),
        _field("telegram_image_path", "Gambar Telegram family",
               values.get("telegram_image_path", ""), maximum=2048,
               errors=errors.get("telegram_image_path")),
    ]


def _category_fields(values, errors, *, edit=False):
    if edit:
        return [
            _field("name", "Nama kategori", values.get("name", ""), maximum=120,
                   required=True, errors=errors.get("name")),
            _field("description", "Deskripsi kategori", values.get("description", ""),
                   kind="textarea", rows=3, errors=errors.get("description")),
            _field("sort_order", "Urutan tampil", values.get("sort_order", 0),
                   kind="number", minimum=0, step="1", required=True,
                   errors=errors.get("sort_order")),
            _field("is_active", "Aktif", bool(values.get("is_active")), kind="checkbox",
                   errors=errors.get("is_active")),
        ]
    return [
        _field("category_name", "Nama kategori", values.get("category_name", ""),
               maximum=120, required=True, errors=errors.get("category_name")),
        _field("category_description", "Deskripsi kategori",
               values.get("category_description", ""), kind="textarea", rows=3,
               errors=errors.get("category_description")),
    ]


def _submitted_tiers(data):
    rows = {}
    for key, value in data.items():
        match = _FIELD_ERROR.fullmatch(key) or re.fullmatch(
            r"quantity_price_tiers\.(\d+)\.(minimum_quantity|price_amount|usdt_price_amount)",
            key,
        )
        if match is not None:
            rows.setdefault(int(match.group(1)), {})[match.group(2)] = value
    if not rows:
        rows[0] = {}
    return [{"index": index, **rows[index]} for index in sorted(rows)]


def _tier_rows(data, errors):
    rows = data
    result = []
    for number, row in enumerate(rows, start=1):
        index = row.get("index", number - 1)
        prefix = f"quantity_price_tiers.{index}"
        base = {
            "index": index,
            "number": number,
        }
        fields = [
            _field(f"{prefix}.minimum_quantity", "Mulai jumlah",
                   row.get("minimum_quantity", ""), kind="number", minimum=2, maximum=20,
                   step="1", required=True, errors=errors.get(prefix + ".minimum_quantity")),
            _field(f"{prefix}.price_amount", "Harga IDR / unit",
                   row.get("price_amount", ""), kind="number", minimum=1, step="1",
                   required=True, errors=errors.get(prefix + ".price_amount")),
            _field(f"{prefix}.usdt_price_amount", "Harga USDT / unit",
                   row.get("usdt_price_amount", ""), kind="number", minimum=0.001,
                   step="0.001", errors=errors.get(prefix + ".usdt_price_amount")),
        ]
        result.append(base | {"fields": fields})
    return result


def _create_context(request, values=None, errors=None):
    service = CatalogService()
    values, errors = values or {}, errors or {}
    if "is_active" not in values:
        values = dict(values)
        values["is_active"] = True
    options = service.options()
    appearance = [field for field in _appearance_fields(
        values, errors, include_custom_emoji=True)
        if field["name"] in ("icon", "custom_emoji_id")]
    return {
        "detail_fields": _detail_fields(values, errors),
        "price_fields": _price_fields(values, errors),
        "appearance_fields": appearance,
        "category_field": _field(
            "category_id", "Kategori", values.get("category_id", ""), kind="select",
            options=_select_options(options["categories"], values.get("category_id"), "Tanpa kategori"),
            errors=errors.get("category_id")),
        "family_field": _field(
            "product_family_id", "Product Family", values.get("product_family_id", ""),
            kind="select",
            options=_select_options(options["families"], values.get("product_family_id"), "Tanpa family"),
            errors=errors.get("product_family_id")),
        "family_sort_order": values.get("family_sort_order", 0),
        "family_option_label": values.get("family_option_label", ""),
        "errors": errors,
        "error_messages": _flatten(errors),
        "values": values,
    }


def _workspace_context(request, product_id, *, errors=None, submitted_section=None,
                       submitted=None):
    service = CatalogService()
    workspace = service.workspace(product_id)
    product = workspace["product"]
    errors = errors or {}
    submitted = submitted or {}
    detail_values = {key: product.get(key) for key in DETAIL_KEYS}
    detail_values["category_id"] = product.get("category_id")
    price_values = {
        "price_amount": product["price_amount"],
        "usdt_price_amount": format_usdt(product.get("usdt_price_amount")),
        "reseller_price_amount": product.get("reseller_price_amount") or "",
        "reseller_usdt_price_amount": format_usdt(product.get("reseller_usdt_price_amount")),
    }
    appearance_values = {key: product.get(key) for key in APPEARANCE_KEYS}
    family_values = {
        "product_family_id": product.get("product_family_id"),
        "family_sort_order": int(product.get("family_sort_order") or 0),
        "family_option_label": product.get("family_option_label") or "",
    }
    if submitted_section == "family":
        for key in family_values:
            if key in submitted:
                family_values[key] = submitted.get(key)
    tier_rows = [
        {"index": index, "minimum_quantity": int(tier["minimum_quantity"]),
         "price_amount": int(tier["price_amount"]),
         "usdt_price_amount": format_usdt(tier.get("usdt_price_amount"))}
        for index, tier in enumerate(workspace["tiers"])
    ]
    if submitted_section == "detail":
        for key in detail_values:
            if key in submitted:
                detail_values[key] = submitted.get(key)
    elif submitted_section == "price":
        for key in price_values:
            if key in submitted:
                price_values[key] = submitted.get(key)
        tier_rows = _submitted_tiers(submitted)
    elif submitted_section == "appearance":
        for key in appearance_values:
            if key == "is_active":
                appearance_values[key] = boolean(submitted, key)
            elif key in submitted:
                appearance_values[key] = submitted.get(key)
    if not tier_rows:
        tier_rows = [{"index": 0, "minimum_quantity": "", "price_amount": "",
                      "usdt_price_amount": ""}]
    options = workspace["options"]
    open_section = request.GET.get("section") or submitted_section or "detail"
    if open_section not in ("detail", "price", "appearance", "family", "stock"):
        open_section = "detail"
    return {
        "product": product,
        "detail_fields": _detail_fields(detail_values, errors if submitted_section == "detail" else {}),
        "price_fields": _price_fields(price_values, errors if submitted_section == "price" else {}),
        "appearance_fields": _appearance_fields(
            appearance_values, errors if submitted_section == "appearance" else {},
            include_custom_emoji=True),
        "family_fields": [
            _field("product_family_id", "Family", family_values["product_family_id"],
                   kind="select", options=_select_options(
                       options["families"], family_values["product_family_id"], "Tanpa family"),
                   errors=errors.get("product_family_id")),
            _field("family_sort_order", "Urutan dalam family",
                   family_values["family_sort_order"], kind="number", minimum=0, step="1",
                   errors=errors.get("family_sort_order")),
            _field("family_option_label", "Label pilihan di family",
                   family_values["family_option_label"], maximum=255,
                   errors=errors.get("family_option_label")),
        ],
        "category_options": _select_options(
            options["categories"], detail_values.get("category_id"), "Tanpa kategori"),
        "category_field": _field(
            "category_id", "Kategori", detail_values.get("category_id", ""), kind="select",
            options=_select_options(
                options["categories"], detail_values.get("category_id"), "Tanpa kategori"),
            errors=errors.get("category_id") if submitted_section == "detail" else []),
        "tier_rows": _tier_rows(tier_rows, errors),
        "tiers_global_errors": errors.get("quantity_price_tiers", []),
        "versions": workspace["versions"],
        "stock_summary": workspace["stock_summary"],
        "available_stock": workspace["available_stock"],
        "local_available": workspace["local_available"],
        "available_item_count": workspace["available_item_count"],
        "stock_items": workspace["stock_items"],
        "stock_items_truncated": workspace["stock_items_truncated"],
        "stock_errors": _flatten(errors),
        "stock_operation_key": str(uuid.uuid4()),
        "structured_enabled": StockService().structured_enabled(),
        "open_section": open_section,
        "error_messages": _flatten(errors),
        "errors": errors,
    }


def _workspace_page(request, product_id, *, errors=None, submitted_section=None,
                    submitted=None, status=200):
    try:
        context = _workspace_context(
            request, product_id, errors=errors, submitted_section=submitted_section,
            submitted=submitted)
    except Http404:
        return _error_page(request, 404)
    nav = navigation.navigation("produk", "produk")
    return _page(request, "admin/workspace.html",
                 "Kelola " + context["product"]["name"], nav, status=status, **context)


# -- authentication -----------------------------------------------------

@require_http_methods(["GET", "POST"])
def login(request):
    if not access.wants_html(request):
        return auth_http.admin_login(request)
    if request.method == "GET":
        try:
            access._service().current(request)
        except AuthError:
            pass
        else:
            return _headers(HttpResponseRedirect("/admin"))
        response = _headers(render(request, "admin/login.html", {
            "title": "Masuk Admin",
            "next": access.safe_next(request.GET.get("next")),
            "error": None,
            "values": {"email": ""},
            "csrf_token": get_token(request),
        }))
        return response
    email = request.POST.get("email", "")
    password = request.POST.get("password", "")
    error = None
    status = 422
    if (not isinstance(email, str) or len(email) > 320 or not email.strip()
            or not isinstance(password, str) or not password or len(password) > 4096):
        error = "Email atau password admin tidak cocok."
    else:
        try:
            access.login(request, email, password)
        except AuthError as failure:
            status = failure.status if failure.status in (429, 503) else 422
            error = ("Terlalu banyak percobaan login. Coba lagi nanti."
                     if failure.status == 429 else
                     "Login admin sementara tidak tersedia." if failure.status == 503 else
                     "Email atau password admin tidak cocok.")
    if error:
        response = render(request, "admin/login.html", {
            "title": "Masuk Admin",
            "next": access.safe_next(request.POST.get("next")),
            "error": error,
            "values": {"email": email if isinstance(email, str) else ""},
            "csrf_token": get_token(request),
        })
        response.status_code = status
        return _headers(response)
    return _headers(HttpResponseRedirect(access.safe_next(request.POST.get("next"))))


@require_POST
def logout(request):
    if not access.wants_html(request):
        return auth_http.admin_logout(request)
    access.logout(request)
    return _headers(HttpResponseRedirect("/admin/login"))


# -- dashboard ----------------------------------------------------------

@require_http_methods(["GET", "HEAD"])
def dashboard(request):
    if not access.wants_html(request):
        return auth_http.admin_current(request)
    return _dashboard(request)


@access.guard
def _dashboard(request):
    tab = navigation.normalize_tab(request.GET.get("tab"))
    if tab == "laporan":
        from . import operations_views
        return operations_views.reports(request)
    if tab == "pengaturan":
        from . import operations_views
        return operations_views.settings_tab(request)
    if tab == "supplier":
        from . import operations_views
        return operations_views.suppliers(request)
    if tab == "saldo":
        from . import operations_views
        return operations_views.balance_adjustments(request)
    if tab == "pesanan":
        from .orders import OrderReads
        data = OrderReads().list_orders(
            request.GET, min(10_000, max(1, int(request.GET.get("page", 1)
                                                  if str(request.GET.get("page", "1")).isdigit()
                                                  else 1))))
        return _page(request, "admin/orders.html", "Pesanan",
                     navigation.navigation("pesanan", "pesanan"), **data)
    nav = navigation.navigation(tab, tab)
    service = CatalogService()
    context = {"stats": service.dashboard_stats(), "tab": tab}
    if tab == "stats":
        from .reporting import Reporting
        context["overview"] = Reporting().overview(request.GET)
    if tab == "produk":
        try:
            page = min(10_000, max(1, int(request.GET.get("products_page", 1))))
        except (TypeError, ValueError):
            page = 1
        context["catalog"] = service.product_groups(
            search=request.GET.get("product_search", ""),
            sort=request.GET.get("product_sort", "name_asc"),
            page=page,
        )
    return _page(request, "admin/dashboard.html", nav["label"], nav, **context)


# -- products -----------------------------------------------------------

@require_http_methods(["GET", "POST"])
@access.guard
def product_create(request):
    if request.method == "GET":
        return _page(request, "admin/product_form.html", "Tambah produk",
                     navigation.navigation("produk", "produk"), **_create_context(request))
    try:
        product_id = CatalogService().create_product(
            request.POST, request.admin_user["id"], request.META.get("REMOTE_ADDR"))
    except AdminValidationError as error:
        context = _create_context(request, request.POST, error.errors)
        return _page(request, "admin/product_form.html", "Tambah produk",
                     navigation.navigation("produk", "produk"), status=error.status,
                     **context)
    _flash_set(request, "Produk berhasil dibuat. Lengkapi stok dan tampilan di workspace.")
    return _headers(HttpResponseRedirect(f"/admin/produk/{product_id}"))


@require_http_methods(["GET", "HEAD"])
@access.guard
def product_workspace(request, product_id):
    return _workspace_page(request, product_id)


@require_POST
@access.guard
def product_update(request, product_id):
    section = raw(request.POST, "workspace_section", "")
    service = CatalogService()
    from django.conf import settings
    if settings.RUNTIME.notifications:
        from ..notifications.price import PriceNotifications
        from ..notifications.policy import TestRecipients
        service.price_events = PriceNotifications(service.db, TestRecipients(frozenset({'1'})))
    try:
        service.update_product(
            product_id, request.POST, request.admin_user["id"],
            request.META.get("REMOTE_ADDR"), section=section)
    except AdminValidationError as error:
        return _workspace_page(
            request, product_id, errors=_safe_errors(error),
            submitted_section=section if section in ("detail", "price", "appearance") else None,
            submitted=request.POST, status=error.status)
    _flash_set(request, "Perubahan produk disimpan.")
    anchor = {"detail": "detail", "price": "harga", "appearance": "tampilan"}.get(section)
    return _headers(HttpResponseRedirect(f"/admin/produk/{product_id}" + (f"#{anchor}" if anchor else "")))


@require_POST
@access.guard
def product_delete(request, product_id):
    CatalogService().delete_product(
        product_id, request.admin_user["id"], request.META.get("REMOTE_ADDR"))
    _flash_set(request, "Produk diarsipkan dari katalog.")
    return _headers(HttpResponseRedirect("/admin?tab=produk"))


@require_POST
@access.guard
def product_visibility(request, product_id):
    CatalogService().update_visibility(
        product_id, boolean(request.POST, "is_active"), request.admin_user["id"],
        request.META.get("REMOTE_ADDR"))
    _flash_set(request, "Visibilitas produk disimpan.")
    return _headers(HttpResponseRedirect(f"/admin/produk/{product_id}#tampilan"))


@require_POST
@access.guard
def product_membership(request, product_id):
    try:
        CatalogService().update_membership(
            product_id, request.POST, request.admin_user["id"],
            request.META.get("REMOTE_ADDR"))
    except AdminValidationError as error:
        return _workspace_page(
            request, product_id, errors=_safe_errors(error),
            submitted_section="family", submitted=request.POST, status=error.status)
    _flash_set(request, "Grouping produk disimpan.")
    return _headers(HttpResponseRedirect(f"/admin/produk/{product_id}#family"))


# -- stock --------------------------------------------------------------

def _stock_result(request, product_id, submission, service):
    result = service.submit_normalized(
        product_id, submission, request.admin_user["id"], request.META.get("REMOTE_ADDR"))
    return result


def _stock_error(request, product_id, error, structured=False):
    if "application/json" in request.headers.get("Accept", ""):
        return _json({"status": "validation_error", "message": error.message,
                      "errors": error.errors}, status=error.status)
    return _workspace_page(request, product_id, errors=_safe_errors(error),
                           submitted_section="stock", status=error.status)


@require_POST
@access.guard
def stock_intake(request, product_id, structured=False):
    service = StockService()
    try:
        submission = service.normalize(
            request.POST, "structured_account" if structured
            else raw(request.POST, "stock_format", "legacy"))
        result = _stock_result(request, product_id, submission, service)
    except AdminValidationError as error:
        return _stock_error(request, product_id, error, structured)
    _flash_set(request, f"Stok bertambah {result['item_count']} item.")
    if "application/json" in request.headers.get("Accept", ""):
        return _json({"status": "ok", "message": f"Stok bertambah {result['item_count']} item.",
                      "redirect_url": f"/admin/produk/{product_id}#stok",
                      "result": result}, status=200 if result["idempotent_replay"] else 201)
    return _headers(HttpResponseRedirect(f"/admin/produk/{product_id}#stok"))


@require_POST
@access.guard
def stock_accounts_review(request, product_id):
    try:
        review = StockService().review_structured(product_id, request.POST)
    except Http404:
        return _json({"status": "not_found"}, status=404)
    except AdminValidationError as error:
        return _json({"status": "validation_error", "message": error.message,
                      "errors": error.errors}, status=error.status)
    return _json({"status": "ok", "review": review, **review})


@require_GET
@access.guard
def stock_items_json(request, product_id):
    service = CatalogService()
    try:
        product = service.product(product_id)
    except Http404:
        return _json({"status": "not_found"}, status=404)
    items, truncated = service.stock_items(product_id)
    return _json({
        "product": {"id": int(product["id"]), "name": product["name"],
                    "stock": int(product["stock"]),
                    "reserved_stock": int(product["reserved_stock"])},
        "summary": service.stock_summary(product_id),
        "items": [{
            "id": int(item["id"]), "status": item["status"],
            "status_label": item["status_label"], "format": item["format"],
            "invoice": item["invoice_number"],
            "editable": item["editable"],
            "edit_url": f"/admin/produk/{product_id}/stok/{item['id']}"
            if item["editable"] else None,
        } for item in items],
        "truncated": truncated,
    })


@require_http_methods(["GET", "POST"])
@access.guard
def stock_item_edit(request, product_id, item_id):
    service = StockService()
    if request.method == "GET":
        try:
            item = service.item_for_edit(product_id, item_id)
        except Http404:
            return _error_page(request, 404)
        product = CatalogService().product(product_id)
        return _page(request, "admin/stock_item.html", "Edit stok", 
                     navigation.navigation("produk", "produk"),
                     product=product, item=item, errors={}, error_messages=[])
    try:
        service.update_item(product_id, item_id, request.POST,
                            request.admin_user["id"], request.META.get("REMOTE_ADDR"))
    except AdminValidationError as error:
        try:
            item = service.item_for_edit(product_id, item_id)
        except Http404:
            return _error_page(request, 404)
        item.update({
            "email": request.POST.get("email", ""),
            "password": "",
            "access_method": request.POST.get("access_method", "none"),
            "access_value": request.POST.get("access_value", ""),
            "secret": "" if item["format"] == "legacy" else item.get("secret"),
        })
        product = CatalogService().product(product_id)
        return _page(request, "admin/stock_item.html", "Edit stok",
                     navigation.navigation("produk", "produk"),
                     product=product, item=item, errors=error.errors,
                     error_messages=_flatten(error.errors), status=error.status)
    _flash_set(request, "Stok diperbarui.")
    return _headers(HttpResponseRedirect(f"/admin/produk/{product_id}#stok"))


@require_POST
@access.guard
def stock_item_delete(request, product_id, item_id):
    try:
        result = StockService().delete_items(
            product_id, [int(item_id)], request.admin_user["id"],
            request.META.get("REMOTE_ADDR"), single=True)
    except AdminValidationError as error:
        if "application/json" in request.headers.get("Accept", ""):
            return _json({"status": "validation_error", "message": error.message,
                          "errors": error.errors}, status=error.status)
        return _workspace_page(request, product_id, errors=_safe_errors(error),
                               submitted_section="stock", status=error.status)
    _flash_set(request, "Satu stok dihapus.")
    if "application/json" in request.headers.get("Accept", ""):
        return _json({"status": "ok", "deleted_count": result["deleted_count"]})
    return _headers(HttpResponseRedirect(f"/admin/produk/{product_id}#stok"))


@require_POST
@access.guard
def stock_bulk_delete(request, product_id):
    values = request.POST.getlist("stock_item_ids")
    if any(not str(value).isdigit() for value in values):
        error = AdminValidationError({"stock_item_ids": ["Pilihan stok tidak valid."]})
        return _stock_error(request, product_id, error)
    try:
        result = StockService().delete_items(
            product_id, [int(value) for value in values], request.admin_user["id"],
            request.META.get("REMOTE_ADDR"))
    except AdminValidationError as error:
        return _stock_error(request, product_id, error)
    _flash_set(request, f"{result['deleted_count']} stok dihapus.")
    if "application/json" in request.headers.get("Accept", ""):
        return _json({"status": "ok", "deleted_count": result["deleted_count"]})
    return _headers(HttpResponseRedirect(f"/admin/produk/{product_id}#stok"))


# -- categories ---------------------------------------------------------

def _category_rows():
    return CatalogService().db.rows(
        """SELECT c.id,c.name,c.slug,c.description,c.sort_order,c.is_active,
                  COUNT(p.id) AS product_count
           FROM public.categories c
           LEFT JOIN public.products p ON p.category_id=c.id AND p.deleted_at IS NULL
           WHERE c.deleted_at IS NULL
           GROUP BY c.id ORDER BY c.sort_order,c.name,c.id"""
    )


@require_GET
@access.guard
def categories(request):
    return _page(request, "admin/categories.html", "Kategori",
                 navigation.navigation("produk", "categories"),
                 categories=_category_rows())


@require_http_methods(["GET", "POST"])
@access.guard
def category_create(request):
    if request.method == "GET":
        return _page(request, "admin/category_form.html", "Tambah kategori",
                     navigation.navigation("produk", "categories"),
                     fields=_category_fields({}, {}), error_messages=[])
    try:
        CatalogService().create_category(
            request.POST, request.admin_user["id"], request.META.get("REMOTE_ADDR"))
    except AdminValidationError as error:
        return _page(request, "admin/category_form.html", "Tambah kategori",
                     navigation.navigation("produk", "categories"),
                     fields=_category_fields(request.POST, error.errors),
                     error_messages=_flatten(error.errors), status=error.status)
    _flash_set(request, "Kategori dibuat.")
    return _headers(HttpResponseRedirect("/admin/kategori"))


@require_http_methods(["GET", "POST"])
@access.guard
def category_edit(request, category_id):
    service = CatalogService()
    category = service.db.one(
        "SELECT * FROM public.categories WHERE id=%s AND deleted_at IS NULL",
        (category_id,))
    if category is None:
        return _error_page(request, 404)
    if request.method == "GET":
        return _page(request, "admin/category_form.html", "Edit kategori",
                     navigation.navigation("produk", "categories"),
                     fields=_category_fields(category, {}, edit=True), error_messages=[],
                     category=category)
    try:
        service.update_category(category_id, request.POST, request.admin_user["id"],
                                request.META.get("REMOTE_ADDR"))
    except AdminValidationError as error:
        return _page(request, "admin/category_form.html", "Edit kategori",
                     navigation.navigation("produk", "categories"),
                     fields=_category_fields(request.POST, error.errors, edit=True),
                     error_messages=_flatten(error.errors), category=category,
                     status=error.status)
    _flash_set(request, "Kategori diperbarui.")
    return _headers(HttpResponseRedirect("/admin/kategori"))


@require_POST
@access.guard
def category_delete(request, category_id):
    try:
        count = CatalogService().delete_category(
            category_id, request.admin_user["id"], request.META.get("REMOTE_ADDR"))
    except Http404:
        return _error_page(request, 404)
    _flash_set(request, f"Kategori dihapus. {count} produk menjadi tanpa kategori.")
    return _headers(HttpResponseRedirect("/admin/kategori"))


# -- families -----------------------------------------------------------

def _family_rows():
    return CatalogService().db.rows(
        """SELECT f.id,f.name,f.slug,f.description,f.icon,f.sort_order,
                  f.image_path,f.telegram_image_path,COUNT(p.id) AS product_count,
                  MIN(c.name) AS category_name
           FROM public.product_families f
           LEFT JOIN public.products p ON p.product_family_id=f.id AND p.deleted_at IS NULL
           LEFT JOIN public.categories c ON c.id=p.category_id AND c.deleted_at IS NULL
           WHERE f.deleted_at IS NULL
           GROUP BY f.id ORDER BY f.sort_order,f.name,f.id"""
    )


@require_GET
@access.guard
def families(request):
    return _page(request, "admin/families.html", "Product Family",
                 navigation.navigation("produk", "families"),
                 families=_family_rows(),
                 fields=_family_fields({}, {}))


@require_http_methods(["GET", "POST"])
@access.guard
def family_create(request):
    def create_fields(values, errors):
        return [field for field in _family_fields(values, errors)
                if field["name"] != "slug"]
    if request.method == "GET":
        return _page(request, "admin/family_form.html", "Tambah Product Family",
                     navigation.navigation("produk", "families"),
                     fields=create_fields({}, {}), error_messages=[])
    try:
        CatalogService().create_family(
            request.POST, request.admin_user["id"], request.META.get("REMOTE_ADDR"))
    except AdminValidationError as error:
        return _page(request, "admin/family_form.html", "Tambah Product Family",
                     navigation.navigation("produk", "families"),
                     fields=create_fields(request.POST, error.errors),
                     error_messages=_flatten(error.errors), status=error.status)
    _flash_set(request, "Product Family dibuat.")
    return _headers(HttpResponseRedirect("/admin/family"))


@require_http_methods(["GET", "POST"])
@access.guard
def family_edit(request, family_id):
    service = CatalogService()
    family = service.db.one(
        "SELECT * FROM public.product_families WHERE id=%s AND deleted_at IS NULL",
        (family_id,))
    if family is None:
        return _error_page(request, 404)
    if request.method == "GET":
        return _page(request, "admin/family_form.html", "Edit Product Family",
                     navigation.navigation("produk", "families"),
                     fields=_family_fields(family, {}), error_messages=[], family=family)
    try:
        service.update_family(family_id, request.POST, request.admin_user["id"],
                              request.META.get("REMOTE_ADDR"))
    except AdminValidationError as error:
        return _page(request, "admin/family_form.html", "Edit Product Family",
                     navigation.navigation("produk", "families"),
                     fields=_family_fields(request.POST, error.errors),
                     error_messages=_flatten(error.errors), family=family,
                     status=error.status)
    _flash_set(request, "Product Family diperbarui.")
    return _headers(HttpResponseRedirect("/admin/family"))


@require_POST
@access.guard
def family_delete(request, family_id):
    try:
        count = CatalogService().delete_family(
            family_id, request.admin_user["id"], request.META.get("REMOTE_ADDR"))
    except Http404:
        return _error_page(request, 404)
    _flash_set(request, f"Product Family dihapus. {count} produk dilepas dari family.")
    return _headers(HttpResponseRedirect("/admin/family"))


# -- banners ------------------------------------------------------------

@require_http_methods(["GET", "POST"])
@access.guard
def banners(request):
    service = CatalogService()
    if request.method == "GET":
        return _page(request, "admin/banners.html", "Banner beranda",
                     navigation.navigation("produk", "banners"),
                     errors={}, error_messages=[], **service.banners())
    try:
        service.update_banners(request.POST, request.admin_user["id"],
                               request.META.get("REMOTE_ADDR"))
    except AdminValidationError as error:
        context = service.banners()
        for index in range(5):
            prefix = f"banners.{index}."
            if prefix + "path" in request.POST:
                context["slots"][index]["path"] = request.POST.get(prefix + "path")
            if prefix + "title" in request.POST:
                context["slots"][index]["title"] = request.POST.get(prefix + "title")
            if prefix + "link" in request.POST:
                context["slots"][index]["link"] = request.POST.get(prefix + "link")
        return _page(request, "admin/banners.html", "Banner beranda",
                     navigation.navigation("produk", "banners"),
                     slots=context["slots"], version=context["version"],
                     errors=_safe_errors(error), error_messages=_flatten(_safe_errors(error)),
                     status=error.status)
    _flash_set(request, "Banner beranda diperbarui.")
    return _headers(HttpResponseRedirect("/admin/banner"))
