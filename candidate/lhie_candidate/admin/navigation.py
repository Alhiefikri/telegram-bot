"""Permission-filtered server navigation for the shared admin shell."""

from dataclasses import dataclass


@dataclass(frozen=True)
class NavItem:
    id: str
    label: str
    url: str
    glyph: str
    group: str
    tab: str | None = None
    owner_only: bool = False


TABS = ("stats", "pesanan", "produk", "saldo", "supplier", "laporan", "pengaturan")

_ITEMS = (
    NavItem("stats", "Ringkasan", "/admin?tab=stats", "⌂", "utama", "stats"),
    NavItem("pesanan", "Pesanan", "/admin?tab=pesanan", "▤", "utama", "pesanan"),
    NavItem("produk", "Produk", "/admin?tab=produk", "▦", "utama", "produk"),
    NavItem("laporan", "Laporan", "/admin/laporan", "◔", "utama", "laporan"),
    NavItem("adjustments", "Saldo & adjustment", "/admin/store-balance/adjustments", "◫", "operasi", owner_only=True),
    NavItem("usdt", "USDT review", "/admin/store-balance/usdt-review", "◇", "operasi", owner_only=True),
    NavItem("topups", "Top-up IDR", "/admin/store-balance/reports", "↧", "operasi"),
    NavItem("suppliers", "Supplier", "/admin/suppliers", "⇄", "operasi"),
    NavItem("reviews", "Manual fulfillment", "/admin/manual-fulfillment-reviews", "☑", "operasi"),
    NavItem("delivery", "Manual delivery", "/admin/telegram-outbound-effects", "✈", "operasi"),
    NavItem("audit", "Audit", "/admin/audit", "⟳", "operasi"),
    NavItem("settings", "Pengaturan", "/admin?tab=pengaturan", "⚙", "toko", "pengaturan"),
    NavItem("categories", "Kategori", "/admin/kategori", "☷", "katalog"),
    NavItem("families", "Product Family", "/admin/family", "◫", "katalog"),
    NavItem("banners", "Banner beranda", "/admin/banner", "▭", "katalog"),
)

_GROUPS = (("utama", "Utama"), ("operasi", "Operasi"), ("toko", "Toko"),
           ("katalog", "Katalog"))


def normalize_tab(value):
    value = value if isinstance(value, str) else "stats"
    return value if value in TABS else "stats"


def navigation(requested_tab=None, active=None):
    tab = normalize_tab(requested_tab)
    active = active or tab
    groups = []
    for group_id, label in _GROUPS:
        items = []
        for item in _ITEMS:
            if item.group != group_id:
                continue
            items.append({
                "id": item.id,
                "label": item.label,
                "url": item.url,
                "glyph": item.glyph,
                "active": item.id == active,
                "aria_current": "page" if item.id == active else None,
                "owner_only": item.owner_only,
            })
        groups.append({"id": group_id, "label": label, "items": items})
    label = next((item.label for item in _ITEMS if item.id == active), "Ringkasan")
    return {
        "active": active,
        "active_tab": tab,
        "label": label,
        "groups": groups,
        "tabs": [
            {"id": item.id, "label": item.label, "url": item.url,
             "active": item.id == tab, "glyph": item.glyph}
            for item in _ITEMS if item.tab is not None
        ],
    }


def can_view(role):
    return role in ("owner", "admin")
