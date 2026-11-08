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


TABS = ("stats", "produk")

_ITEMS = (
    NavItem("stats", "Ringkasan", "/admin?tab=stats", "⌂", "utama", "stats"),
    NavItem("produk", "Produk", "/admin?tab=produk", "▦", "utama", "produk"),
    NavItem("categories", "Kategori", "/admin/kategori", "☷", "katalog"),
    NavItem("families", "Product Family", "/admin/family", "◫", "katalog"),
    NavItem("banners", "Banner beranda", "/admin/banner", "▭", "katalog"),
)

_GROUPS = (("utama", "Utama"), ("katalog", "Katalog"))


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
