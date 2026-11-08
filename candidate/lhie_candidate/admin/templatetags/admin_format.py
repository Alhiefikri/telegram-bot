"""Small presentation-only formatting filters for the admin templates."""

from django import template

from ..pricing import format_usdt


register = template.Library()


@register.filter
def idr(value):
    try:
        amount = int(value or 0)
    except (TypeError, ValueError):
        return ""
    return "Rp" + format(amount, ",").replace(",", ".")


@register.filter
def usdt(value):
    return format_usdt(value) if value not in (None, "") else ""
